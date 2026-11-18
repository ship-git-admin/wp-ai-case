<?php
/**
 * DBに書かない試験。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev\SelfTest;

use WpAiCase\Acf\FieldAdapterRegistry;
use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Domain\CaseDocumentValidator;
use WpAiCase\Mapping\ShipWorksTemplateV11;
use WpAiCase\Plugin;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class UnitTests {

	public function __construct( private Tester $t ) {
	}

	/**
	 * 合成の CaseDocument（画像IDは架空の番号）。
	 *
	 * @return array<string,mixed>
	 */
	public static function synthetic_doc(): array {
		$labels = array( 'before1', 'before2', 'during1', 'during2', 'after1', 'after2', 'after3', 'detail1' );
		$json   = (string) file_get_contents( WP_AI_CASE_DIR . '/dev/fixtures/poc_full.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json   = (string) preg_replace_callback(
			'/"@img:([a-z0-9_]+)"/',
			static fn( $m ) => (string) ( 101 + (int) array_search( $m[1], $labels, true ) ),
			$json
		);
		$json   = str_replace( array( '"@voice"', '"@staff"', '"@supervisor"' ), array( '501', '601', '601' ), $json );
		return json_decode( $json, true );
	}

	/**
	 * 合成のフィールド定義。
	 *
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	public static function f( string $name, string $type, array $extra = array() ): array {
		return array(
			'key'  => 'field_syn_' . strtolower( $name ),
			'name' => $name,
			'type' => $type,
		) + $extra;
	}

	public function run(): void {
		$t = $this->t;

		// ---- CaseDocument の検証 ----
		$t->test( 'U-DOC01', '合成CaseDocumentが検証を通る', function () {
			( new CaseDocumentValidator() )->validate( self::synthetic_doc() );
		} );
		$t->test( 'U-DOC02', '画像IDの重複を拒否', function () {
			$doc                              = self::synthetic_doc();
			$doc['images'][1]['attachment_id'] = $doc['images'][0]['attachment_id'];
			$this->t->throws_case( CaseError::INVALID_DOCUMENT, fn() => ( new CaseDocumentValidator() )->validate( $doc ) );
		} );
		$t->test( 'U-DOC03', '関連付けがないと入力不足（AI-017）', function () {
			$doc = self::synthetic_doc();
			unset( $doc['relations'] );
			$e = $this->t->throws_case( CaseError::MISSING_INPUT, fn() => ( new CaseDocumentValidator() )->validate( $doc ) );
			$this->t->same( 'AI-017', $e->ai_code(), 'コード' );
		} );
		$t->test( 'U-DOC04', '担当スタッフ0件・監修者0件・お客様の声0件を拒否', function () {
			foreach ( array( 'staff', 'supervisor_post_ids', 'customer_voice_post_ids' ) as $key ) {
				$doc                     = self::synthetic_doc();
				$doc['relations'][ $key ] = array();
				$this->t->throws_case( CaseError::MISSING_INPUT, fn() => ( new CaseDocumentValidator() )->validate( $doc ) );
			}
		} );
		$t->test( 'U-DOC05', '費用・工期を画像から推測した値は拒否', function () {
			$doc                  = self::synthetic_doc();
			$doc['facts']['cost'] = array( 'value' => '120万円', 'status' => 'inferred_from_image', 'source' => 'photo', 'evidence_image_ids' => array( 101 ) );
			$this->t->throws_case( CaseError::INVALID_DOCUMENT, fn() => ( new CaseDocumentValidator() )->validate( $doc ) );
		} );
		$t->test( 'U-DOC06', 'セクションが含めない画像・存在しない画像を参照したら拒否', function () {
			$doc                         = self::synthetic_doc();
			$doc['images'][0]['included'] = false;
			$this->t->throws_case( CaseError::INVALID_DOCUMENT, fn() => ( new CaseDocumentValidator() )->validate( $doc ) );
			$doc                                    = self::synthetic_doc();
			$doc['sections'][1]['evidence_image_ids'] = array( 9999 );
			$this->t->throws_case( CaseError::INVALID_DOCUMENT, fn() => ( new CaseDocumentValidator() )->validate( $doc ) );
		} );
		$t->test( 'U-DOC07', '定義外の項目（自由なACFキー等）を拒否', function () {
			$doc              = self::synthetic_doc();
			$doc['acf_field'] = array( 'field_x' => 'y' );
			$this->t->throws_case( CaseError::INVALID_DOCUMENT, fn() => ( new CaseDocumentValidator() )->validate( $doc ) );
			$doc                        = self::synthetic_doc();
			$doc['post_type']           = 'page';
			$this->t->throws_case( CaseError::INVALID_DOCUMENT, fn() => ( new CaseDocumentValidator() )->validate( $doc ) );
		} );

		// ---- 保存アダプタ（合成のACF定義） ----
		$r = new FieldAdapterRegistry();
		$t->test( 'U-ADP01', 'text/textarea はタグを除去、wysiwyg は危険なタグを除去', function () use ( $r ) {
			$idx = array(
				'a' => self::f( 'a', 'text' ),
				'b' => self::f( 'b', 'textarea' ),
				'c' => self::f( 'c', 'wysiwyg' ),
			);
			$out = $r->prepare_top_level( $idx, array( 'a' => '<b>太字</b>見出し', 'b' => "1行目<script>x</script>\n2行目", 'c' => '<p>本文</p><script>alert(1)</script>' ), array() );
			$this->t->same( '太字見出し', $out['field_syn_a']['raw'], 'text' );
			$this->t->same( "1行目\n2行目", $out['field_syn_b']['raw'], 'textarea' );
			$this->t->true( ! str_contains( (string) $out['field_syn_c']['raw'], '<script' ), 'wysiwyg' );
		} );
		$t->test( 'U-ADP02', '選択肢にない値を拒否', function () use ( $r ) {
			$idx = array( 's' => self::f( 's', 'select', array( 'choices' => array( 'layout01' => 'A' ) ) ) );
			$this->t->throws_case( CaseError::INVALID_VALUE, fn() => $r->prepare_top_level( $idx, array( 's' => 'layout09' ), array() ) );
		} );
		$t->test( 'U-ADP03', '現在の定義にないフィールド名を拒否', function () use ( $r ) {
			$idx = array( 'a' => self::f( 'a', 'text' ) );
			$this->t->throws_case( CaseError::MAPPING_MISMATCH, fn() => $r->prepare_top_level( $idx, array( 'zzz' => 'x' ), array() ) );
		} );
		$t->test( 'U-ADP04', 'C1-A04 未対応の型は安全に停止（対応済みと扱わない）', function () use ( $r ) {
			$this->t->same( 'unsupported', FieldAdapterRegistry::support_status( 'google_map' ), '判定' );
			$idx = array( 'm' => self::f( 'm', 'google_map' ) );
			$e   = $this->t->throws_case( CaseError::UNSUPPORTED_FIELD_TYPE, fn() => $r->prepare_top_level( $idx, array( 'm' => array( 'lat' => 1 ) ), array() ) );
			$this->t->same( 'AI-010', $e->ai_code(), 'コード' );
		} );
		$t->test( 'U-ADP05', '未対応の型を含む行は作らない', function () use ( $r ) {
			$idx = array(
				'rep' => self::f( 'rep', 'repeater', array( 'sub_fields' => array( self::f( 'title', 'text' ), self::f( 'color', 'color_picker' ) ) ) ),
			);
			$this->t->throws_case( CaseError::UNSUPPORTED_FIELD_TYPE, fn() => $r->prepare_top_level( $idx, array( 'rep' => array( array( 'title' => 'x' ) ) ), array() ) );
		} );
		$t->test( 'U-ADP06', '条件分岐で非表示のフィールドへの書き込みを拒否', function () use ( $r ) {
			$mode = self::f( 'mode', 'radio', array( 'choices' => array( '01' => 'A', '02' => 'B' ) ) );
			$text = self::f( 'text', 'textarea', array( 'conditional_logic' => array( array( array( 'field' => 'field_syn_mode', 'operator' => '==', 'value' => '01' ) ) ) ) );
			$idx  = array( 'mode' => $mode, 'text' => $text );
			$this->t->throws_case( CaseError::HIDDEN_BY_CONDITION, fn() => $r->prepare_top_level( $idx, array( 'mode' => '02', 'text' => '本文' ), array() ) );
			$ok = $r->prepare_top_level( $idx, array( 'mode' => '01', 'text' => '本文' ), array() );
			$this->t->same( '本文', $ok['field_syn_text']['raw'], '表示されるときは書ける' );
		} );
		$t->test( 'U-ADP07', 'フレキシブル：存在しないレイアウト・レイアウト上限超過を拒否', function () use ( $r ) {
			$fc  = self::f(
				'fc',
				'flexible_content',
				array(
					'layouts' => array(
						'layout_syn_a' => array( 'key' => 'layout_syn_a', 'name' => 'a', 'min' => '', 'max' => 1, 'sub_fields' => array( self::f( 'x', 'text' ) ) ),
					),
				)
			);
			$idx = array( 'fc' => $fc );
			$this->t->throws_case( CaseError::MAPPING_MISMATCH, fn() => $r->prepare_top_level( $idx, array( 'fc' => array( array( 'acf_fc_layout' => 'b' ) ) ), array() ) );
			$this->t->throws_case( CaseError::INVALID_VALUE, fn() => $r->prepare_top_level( $idx, array( 'fc' => array( array( 'acf_fc_layout' => 'a', 'x' => '1' ), array( 'acf_fc_layout' => 'a', 'x' => '2' ) ) ), array() ) );
			$ok = $r->prepare_top_level( $idx, array( 'fc' => array( array( 'acf_fc_layout' => 'a' ) ) ), array() );
			$this->t->same( array( array( 'acf_fc_layout' => 'a', 'field_syn_x' => '' ) ), $ok['field_syn_fc']['raw'], '指定のない子は既定値で埋める' );
		} );
		$t->test( 'U-ADP08', 'リピーター：件数の下限・上限', function () use ( $r ) {
			$idx = array( 'rep' => self::f( 'rep', 'repeater', array( 'min' => 1, 'max' => 2, 'sub_fields' => array( self::f( 'x', 'text' ) ) ) ) );
			$this->t->throws_case( CaseError::INVALID_VALUE, fn() => $r->prepare_top_level( $idx, array( 'rep' => array() ), array() ) );
			$this->t->throws_case( CaseError::INVALID_VALUE, fn() => $r->prepare_top_level( $idx, array( 'rep' => array( array(), array(), array() ) ), array() ) );
		} );
		$t->test( 'U-ADP09', '画像：添付ファイル以外のIDを拒否', function () use ( $r ) {
			$works = get_posts( array( 'post_type' => Plugin::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ) );
			if ( ! $works ) {
				throw new SkipTest( '比較に使う投稿がありません' );
			}
			$idx = array( 'img' => self::f( 'img', 'image' ) );
			$this->t->throws_case( CaseError::INVALID_VALUE, fn() => $r->prepare_top_level( $idx, array( 'img' => (int) $works[0] ), array() ) );
			$this->t->throws_case( CaseError::INVALID_VALUE, fn() => $r->prepare_top_level( $idx, array( 'img' => '12' ), array() ) );
		} );
		$t->test( 'U-ADP10', '関連付け：投稿タイプ違い・存在しない投稿・上限超過を拒否', function () use ( $r ) {
			$works = get_posts( array( 'post_type' => Plugin::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ) );
			if ( ! $works ) {
				throw new SkipTest( '比較に使う投稿がありません' );
			}
			$idx = array( 'rel' => self::f( 'rel', 'relationship', array( 'post_type' => array( 'staff' ), 'max' => 1 ) ) );
			$this->t->throws_case( CaseError::INVALID_VALUE, fn() => $r->prepare_top_level( $idx, array( 'rel' => array( (int) $works[0] ) ), array() ) );
			$this->t->throws_case( CaseError::INVALID_VALUE, fn() => $r->prepare_top_level( $idx, array( 'rel' => array( 999999999 ) ), array() ) );
			$staff = get_posts( array( 'post_type' => 'staff', 'post_status' => 'publish', 'posts_per_page' => 2, 'fields' => 'ids' ) );
			if ( count( $staff ) >= 2 ) {
				$this->t->throws_case( CaseError::INVALID_VALUE, fn() => $r->prepare_top_level( $idx, array( 'rel' => array_map( 'intval', $staff ) ), array() ) );
			}
		} );
		$t->test( 'U-ADP11', 'URL：http/https 以外を拒否', function () use ( $r ) {
			$idx = array( 'u' => self::f( 'u', 'url' ) );
			$this->t->throws_case( CaseError::INVALID_VALUE, fn() => $r->prepare_top_level( $idx, array( 'u' => 'javascript:alert(1)' ), array() ) );
			$ok = $r->prepare_top_level( $idx, array( 'u' => 'https://www.youtube.com/watch?v=abc' ), array() );
			$this->t->same( 'https://www.youtube.com/watch?v=abc', $ok['field_syn_u']['raw'], 'https は通る' );
		} );
		$t->test( 'U-ADP12', '未対応の条件分岐演算子で値を入れようとしたら停止', function () use ( $r ) {
			$a   = self::f( 'a', 'text' );
			$b   = self::f( 'b', 'text', array( 'conditional_logic' => array( array( array( 'field' => 'field_syn_a', 'operator' => '==pattern', 'value' => 'x' ) ) ) ) );
			$idx = array( 'a' => $a, 'b' => $b );
			$this->t->throws_case( CaseError::UNSUPPORTED_FIELD_TYPE, fn() => $r->prepare_top_level( $idx, array( 'a' => 'x', 'b' => 'y' ), array() ) );
		} );

		// ---- 実際のACF定義（読み取りのみ） ----
		$inspector = new SchemaInspector();
		$t->test( 'U-SCH01', 'schema hash が安定している（2回計算して一致）', function () use ( $inspector ) {
			$ctx = $inspector->context( Plugin::POST_TYPE, 0, array() );
			$this->t->same( $inspector->schema_hash( $inspector->groups( $ctx ) ), $inspector->schema_hash( $inspector->groups( $ctx ) ), 'hash' );
		} );
		$t->test( 'U-SCH02', '型の変更で hash が変わり、ラベル変更では変わらない', function () use ( $inspector ) {
			$groups = $inspector->groups( $inspector->context( Plugin::POST_TYPE, 0, array() ) );
			$base   = $inspector->schema_hash( $groups );
			$label  = $groups;
			$label[0]['fields'][0]['label'] = '変更したラベル';
			$this->t->same( $base, $inspector->schema_hash( $label ), 'ラベル' );
			$type                           = $groups;
			$type[0]['fields'][0]['type'] = 'text' === $type[0]['fields'][0]['type'] ? 'textarea' : 'text';
			$this->t->true( $base !== $inspector->schema_hash( $type ), '型を変えたら hash が変わる' );
		} );
		$t->test( 'U-SCH03', '新規の施工事例に「施工事例_ver1.1」が適用され、旧レイアウト用は適用されない', function () use ( $inspector ) {
			$titles = array_map( static fn( $g ) => $g['title'], $inspector->groups( $inspector->context( Plugin::POST_TYPE, 0, array() ) ) );
			$this->t->true( in_array( '施工事例_ver1.1', $titles, true ), 'ver1.1 がない: ' . implode( ',', $titles ) );
			$this->t->true( ! in_array( '施工事例（移設）', $titles, true ), '移設グループが適用されている' );
		} );
		$t->test( 'U-SCH04', 'sys_tag:old_layout の条件では適用グループが入れ替わる（C1-ACF04 の下準備）', function () use ( $inspector ) {
			$term = get_term_by( 'slug', 'old_layout', 'sys_tag' );
			if ( ! $term ) {
				throw new SkipTest( 'old_layout タームがありません' );
			}
			$ctx    = $inspector->context( Plugin::POST_TYPE, 0, array( 'sys_tag' => array( (int) $term->term_id ) ) );
			$titles = array_map( static fn( $g ) => $g['title'], $inspector->groups( $ctx ) );
			$this->t->true( in_array( '施工事例（移設）', $titles, true ), '移設グループがない' );
			$this->t->true( ! in_array( '施工事例_ver1.1', $titles, true ), 'ver1.1 が適用されたまま' );
			$base = $inspector->context( Plugin::POST_TYPE, 0, array() );
			$this->t->true( $inspector->context_hash( $ctx ) !== $inspector->context_hash( $base ), 'context hash が変わらない' );
		} );
		$t->test( 'U-SCH05', '新規の施工事例に適用される定義に、未対応の型がない（対応状況の一覧）', function () use ( $inspector ) {
			$unsupported = array();
			$walk        = static function ( array $fields ) use ( &$walk, &$unsupported ): void {
				foreach ( $fields as $field ) {
					if ( FieldAdapterRegistry::support_status( (string) $field['type'] ) === 'unsupported' ) {
						$unsupported[] = $field['name'] . ':' . $field['type'];
					}
					$walk( $field['sub_fields'] ?? array() );
					foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
						$walk( $layout['sub_fields'] ?? array() );
					}
				}
			};
			foreach ( $inspector->groups( $inspector->context( Plugin::POST_TYPE, 0, array() ) ) as $group ) {
				$walk( $group['fields'] );
			}
			$this->t->same( array(), $unsupported, '未対応の型' );
		} );

		// ---- 既知マッピング ----
		$tpl = new ShipWorksTemplateV11();
		$t->test( 'U-MAP01', 'FV 3パターンで対応する枝だけを作る', function () use ( $tpl ) {
			$doc = self::synthetic_doc();
			$s   = $tpl->build( $doc, array( 'hero' => 'single' ) )['fields'];
			$g   = $tpl->build( $doc, array( 'hero' => 'gallery' ) )['fields'];
			$b   = $tpl->build( $doc, array( 'hero' => 'before_after' ) )['fields'];
			$this->t->same( array( 'layout01', 'layout02', 'layout03' ), array( $s['FV_Layout'], $g['FV_Layout'], $b['FV_Layout'] ), 'FV_Layout' );
			$this->t->true( isset( $s['EntryData_CatchImg'] ) && ! isset( $s['FV_Layout_Gallery'] ) && ! isset( $s['FV_Layout_BeforeAfter'] ), 'single' );
			$this->t->true( isset( $g['FV_Layout_Gallery'] ) && 8 === count( $g['FV_Layout_Gallery'] ), 'gallery は全写真' );
			$this->t->same( 101, $b['FV_Layout_BeforeAfter']['FV_Layout_BeforeAfter_Image_Before'], 'before' );
		} );
		$t->test( 'U-MAP02', '入力されていない事実（費用不明・推測の間取り）は概要に出さない', function () use ( $tpl ) {
			$layouts = array_column( $tpl->build( self::synthetic_doc() )['fields']['Outline'], 'acf_fc_layout' );
			$this->t->true( ! in_array( 'Outline_Label_Cost', $layouts, true ), '費用が出ている' );
			$this->t->true( ! in_array( 'Outline_Label_Floor-plan', $layouts, true ), '推測の間取りが出ている' );
			$this->t->true( in_array( 'Outline_Label_Period', $layouts, true ), '工期（入力済み）がない' );
		} );
		$t->test( 'U-MAP03', '関連付け（お客様の声・担当スタッフ・監修者）を必ず出す', function () use ( $tpl ) {
			$f = $tpl->build( self::synthetic_doc() )['fields'];
			$this->t->same( array( 501 ), $f['EntryData_RelatedData'], 'お客様の声' );
			$this->t->same( array( 601 ), $f['EntryData_Staff'][0]['EntryData_Staff_Name'], '担当スタッフ' );
			$this->t->same( array( 601 ), $f['entrydata_supervision'], '監修者' );
		} );
	}
}
