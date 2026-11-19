<?php
/**
 * 記事の書き方 1.2（2026-11-19 の依頼）：手本の事例に合わせた並び・ご要望とご提案の文章・担当スタッフのコメント・
 * 記事の方針・施工事例カテゴリとアイキャッチ・既存ページからの取り込み・テンプレートの更新でのマッピングの引き継ぎ。
 *
 * プロバイダーは Mock（外部通信なし）。下書きを作る試験は印を付け、最後に削除する。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev\SelfTest;

use WpAiCase\Acf\SchemaInspector;
use WpAiCase\AI\Prompts;
use WpAiCase\Dev\MockProvider;
use WpAiCase\Dev\Poc;
use WpAiCase\Diagnostics\Settings;
use WpAiCase\Draft\DraftState;
use WpAiCase\Jobs\JobError;
use WpAiCase\Jobs\JobService;
use WpAiCase\Jobs\PatchApplier;
use WpAiCase\Jobs\ResultAssembler;
use WpAiCase\Mapping\MappingService;
use WpAiCase\Mapping\SampleDocument;
use WpAiCase\Mapping\ShipWorksTemplateV11;
use WpAiCase\Plugin;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class ArticleTests {

	/** @var string[] */
	private array $jobs = array();

	public function __construct( private Tester $t, private int $user ) {
	}

	public function run(): void {
		Settings::$test_status = 'configured';
		wp_set_current_user( $this->user );
		try {
			$this->template();
			$this->captions();
			$this->assemble();
			$this->patches();
			$this->mapping();
			$images = Poc::images();
			if ( count( $images ) < 5 ) {
				$this->t->test( 'A-20', '前提：合成画像', fn() => throw new SkipTest( '合成画像が未登録です（wp ai-case poc seed-images）' ) );
				return;
			}
			$this->import( $images );
			$this->sideload();
			$this->meta( $images );
		} finally {
			Settings::$test_status = null;
			$this->cleanup();
		}
	}

	private function service( string $scenario = 'ok' ): JobService {
		return new JobService( null, new MockProvider( $scenario ) );
	}

	private function err( callable $fn ): JobError {
		try {
			$fn();
		} catch ( JobError $e ) {
			return $e;
		}
		throw new \RuntimeException( 'JobError になりませんでした' );
	}

	private function template(): void {
		$t = $this->t;
		$t->test( 'A-01', 'テンプレート 1.2：概要の順（地域→建物→築年数→施工箇所→施工内容）・施主様名＝地域・お客様の声・ご要望とご提案は文章の節から', function () {
			$built = ( new ShipWorksTemplateV11() )->build( SampleDocument::get() );
			$f     = $built['fields'];
			$this->t->same(
				array( 'Outline_Label_Area', 'Outline_Label_Building-classification', 'Outline_Label_Age', 'Outline_Label_Parts', 'Outline_Label_Content', 'Outline_Label_Period', 'Outline_Label_Cost', 'Outline_Label_Floor-plan', 'Outline_Label_Site' ),
				array_column( $f['Outline'], 'acf_fc_layout' ),
				'概要の並び'
			);
			$this->t->same( 'sample work type', $f['Outline'][4]['Outline_Content'], '施工内容は短い一覧（概要の文章ではない）' );
			$this->t->same( 'sample area', $f['EntryData_CustomerName'], '施主様名＝地域' );
			$this->t->true( str_contains( $f['EntryData_CustomerVoice'], 'sample customer voice' ), 'お客様からの声（入力そのまま）' );
			$this->t->true( str_contains( $f['Request'], 'sample body request' ) && ! str_contains( $f['Request'], 'sample request' ), 'ご要望は文章の節' );
			$this->t->true( str_contains( $f['Proposal'], 'sample body proposal' ), 'ご提案は文章の節' );
			$this->t->same( '1.2', ShipWorksTemplateV11::TEMPLATE_VERSION, 'テンプレートの版' );
		} );

		$t->test( 'A-02', 'テンプレート 1.2：施工前は 写真→見出し＋文章、施工中は工程ごとに 見出し→写真→文章、施工後は 1枚目を大きく→見出し＋文章→残り。説明文は空', function () {
			$built  = ( new ShipWorksTemplateV11() )->build( SampleDocument::get() );
			$detail = $built['fields']['Format'][0]['EntryData_Group_Detail'];
			$shape  = static fn( array $rows ) => array_map(
				static function ( $r ) {
					if ( str_ends_with( $r['acf_fc_layout'], '_Unit' ) ) {
						$p   = str_replace( array( 'EntryData_Section', '_Unit' ), '', $r['acf_fc_layout'] );
						$ids = array_column( $r[ "EntryData_Picture{$p}" ], "EntryData_Picture{$p}_Image" );
						return 'unit:' . $r[ "EntryData_Picture{$p}_layout" ] . ':' . implode( ',', $ids );
					}
					return 'sub:' . $r['EntryData_Detail_Layout'];
				},
				$rows
			);
			$this->t->same( array( 'unit:type-b:1,2', 'sub:03' ), $shape( $detail['EntryData_SectionMae'] ), '施工前（小見出しなし・文章だけ）' );
			$this->t->same( array( 'sub:02', 'unit:type-b:3', 'sub:03', 'sub:02', 'sub:03' ), $shape( $detail['EntryData_SectionChu'] ), '施工中（工程2つ）' );
			$this->t->same( array( 'unit:type-b:4,5', 'sub:03' ), $shape( $detail['EntryData_SectionGo'] ), '施工後（写真は PC 2列・1列は使わない → 小見出しなしの文章）' );
			$this->t->same( 'sample heading during', $detail['EntryData_SectionChu'][0]['EntryData_Detail_Catch'], '工程の見出し' );
			$this->t->same( 'sample body during', $detail['EntryData_SectionChu'][2]['EntryData_Detail_Text'], '工程の文章' );
			$this->t->true( ! isset( $detail['EntryData_SectionChu'][0]['EntryData_Detail_Text'] ) && ! isset( $detail['EntryData_SectionChu'][2]['EntryData_Detail_Catch'] ), '見出しだけ・文章だけの行' );
			$this->t->same( array( '' ), array_unique( array_column( $detail['EntryData_SectionMae'][0]['EntryData_PictureMae'], 'EntryData_PictureMae_Txt' ) ), '写真の下の説明文は空' );
			$this->t->same( array( '' ), array_unique( array_column( $detail['EntryData_SectionMae'][0]['EntryData_PictureMae'], 'EntryData_PictureMae_Title' ) ), '写真の見出しも空（手本と同じ）' );
			$feature = array();
			foreach ( $built['fields']['Format'] as $row ) {
				if ( 'Feature_Label' === $row['acf_fc_layout'] ) {
					$feature = $row['Feature'];
				}
			}
			$this->t->same( array(), array_values( array_filter( $feature, static fn( $r ) => str_contains( (string) ( $r['Feature_SubtitleText_Subtitle'] ?? '' ), 'request' ) || str_contains( (string) ( $r['Feature_SubtitleText_Subtitle'] ?? '' ), 'proposal' ) ) ), 'ご要望・ご提案は特徴の欄に出さない' );
		} );

		$t->test( 'A-14', '写真の縦横で表示の設定を決める：縦長は「縦長用リサイズ」、4:3 から外れる横長は「トリミング解除」。トップの写真・施工後の大きい1枚は1枚目のまま（縦長なら縦長用リサイズ）', function () {
			$doc  = SampleDocument::get();
			$dims = array( 1 => array( 1478, 1108 ), 2 => array( 768, 1024 ), 3 => array( 2000, 819 ), 4 => array( 1108, 1477 ), 5 => array( 1477, 1108 ), 6 => array( 1000, 1000 ) );
			$b    = ( new ShipWorksTemplateV11() )->build( $doc, array( 'dims' => $dims, 'hero' => 'before_after' ) );
			$d    = $b['fields']['Format'][0]['EntryData_Group_Detail'];
			$mae  = $d['EntryData_SectionMae'][0];
			$this->t->same( array( false, true ), array_column( $mae['EntryData_PictureMae'], 'EntryData_PictureMae_Trim' ), '施工前：縦長の写真だけ縦長用リサイズ' );
			$this->t->same( false, $mae['EntryData_PictureMae_Trim02'], '施工前：4:3 と縦長だけならトリミング解除しない' );
			$chu = array_values( array_filter( $d['EntryData_SectionChu'], static fn( $r ) => 'EntryData_SectionChu_Unit' === $r['acf_fc_layout'] ) )[0];
			$this->t->same( true, $chu['EntryData_PictureChu_Trim02'], '施工中：横に長い写真があればトリミング解除' );
			$go = $d['EntryData_SectionGo'];
			$this->t->same( array( 'type-b', array( 4, 5 ), array( true, false ) ), array( $go[0]['EntryData_PictureGo_layout'], array_column( $go[0]['EntryData_PictureGo'], 'EntryData_PictureGo_Image' ), array_column( $go[0]['EntryData_PictureGo'], 'EntryData_PictureGo_Trim' ) ), '施工後は2列のまとまり1つ・縦長だけ縦長用リサイズ' );
			$fv = $b['fields']['FV_Layout_BeforeAfter'];
			$this->t->same( array( 1, 4, false, true ), array( $fv['FV_Layout_BeforeAfter_Image_Before'], $fv['FV_Layout_BeforeAfter_Image_After'], $fv['FV_Layout_BeforeAfter_BeforeTrim'], $fv['FV_Layout_BeforeAfter_AfterTrim'] ), 'トップの写真：After が縦長なら縦長用リサイズ' );
			$only_portrait = array( 1 => array( 768, 1024 ), 2 => array( 768, 1024 ), 4 => array( 768, 1024 ), 5 => array( 768, 1024 ) );
			$fv2 = ( new ShipWorksTemplateV11() )->build( $doc, array( 'dims' => $only_portrait, 'hero' => 'before_after' ) )['fields']['FV_Layout_BeforeAfter'];
			$this->t->same( array( true, true ), array( $fv2['FV_Layout_BeforeAfter_BeforeTrim'], $fv2['FV_Layout_BeforeAfter_AfterTrim'] ), '縦長しかなければ縦長用リサイズ' );
			$g = ( new ShipWorksTemplateV11() )->build( $doc, array( 'dims' => $dims, 'hero' => 'gallery' ) )['fields']['FV_Layout_Gallery'];
			$this->t->same( array( false, true, false, true, false, false ), array_column( $g, 'FV_Layout_Gallery_Trim' ), 'ギャラリーは縦長だけ縦長用リサイズ' );
		} );

		$t->test( 'A-03', '施工中の工程：写真は1回だけ使い、どの工程にも入らない写真は最後に見出しなしでまとめる', function () {
			$p     = static fn( int $id ) => array( 'attachment_id' => $id );
			$steps = ShipWorksTemplateV11::steps(
				array(
					array( 'node_id' => 'a', 'image_ids' => array( 1, 2 ) ),
					array( 'node_id' => 'b', 'image_ids' => array( 2, 3 ) ),
				),
				array( $p( 1 ), $p( 2 ), $p( 3 ), $p( 4 ) )
			);
			$this->t->same(
				array( array( 'a', array( 1, 2 ) ), array( 'b', array( 3 ) ), array( null, array( 4 ) ) ),
				array_map( static fn( $s ) => array( $s[0]['node_id'] ?? null, array_column( $s[1], 'attachment_id' ) ), $steps ),
				'工程ごとの写真'
			);
		} );
	}

	private function captions(): void {
		$this->t->test( 'A-12', 'キャプション：工程の名前（テーマが見出しとして出す）を除き、「写真」「様子」だけなら空にする。AI による修正も同じ。利用者の入力はそのまま', function () {
			$cases = array( '施工前' => '', '施工前の表示画像' => '', '施工後の様子' => '', '施工後の外壁' => '外壁', '外壁の色あせ（施工前）' => '外壁の色あせ', '【施工中】高圧洗浄' => '高圧洗浄', 'ビフォー' => '', '高圧洗浄' => '高圧洗浄', 'before' => '', '高圧洗浄で汚れを落としました。' => '', '落ち着いた色に仕上がりました' => '' );
			$got   = array();
			foreach ( array_keys( $cases ) as $c ) {
				$got[ $c ] = ResultAssembler::caption( $c );
			}
			$this->t->same( $cases, $got, '変換' );
			$doc = SampleDocument::get();
			$r   = PatchApplier::apply( $doc, array( 'photos' ), array( array( 'op' => 'image_caption_set', 'section' => 'photos', 'attachment_id' => 1, 'caption' => '施工前の様子' ) ), true );
			$this->t->same( '', $r['doc']['images'][0]['caption'], 'AI による修正' );
			$r = PatchApplier::apply( $doc, array( 'photos' ), array( array( 'op' => 'image_caption_set', 'section' => 'photos', 'attachment_id' => 1, 'caption' => '施工前' ) ), false );
			$this->t->same( '施工前', $r['doc']['images'][0]['caption'], '利用者の入力はそのまま' );
		} );

		$this->t->test( 'A-13', '記事の文章：写真についての注意・確認のメモの文だけを除く（ふつうの文は残す）', function () {
			$in  = '外壁と雨樋を塗装しました。仕上がりの色は、掲載用写真での確認が必要です。点検で劣化を確認し、補修しました。画像からは色合いを判断できません。';
			$this->t->same( '外壁と雨樋を塗装しました。点検で劣化を確認し、補修しました。', ResultAssembler::prose( $in, 800 ), '除く文' );
			$this->t->same( '下地を補修しました。', ResultAssembler::prose( '下地を補修しました。', 800 ), 'ふつうの文' );
		} );
	}

	private function assemble(): void {
		$this->t->test( 'A-04', '結果の組み立て：ご要望・ご提案の文章の節、施工内容の一覧（入力があれば入力）、担当コメントは空欄だけAIの案、お客様の声は入力のまま', function () {
			$input    = array(
				'primary_facts' => array( 'overview' => '概要（試験）', 'client_request' => '要望（試験）', 'work_done' => '工事（試験）', 'customer_voice' => "とても満足です。\n（試験）" ),
				'images'        => array(
					array( 'attachment_id' => 101, 'original_position' => 0, 'caption' => '', 'user_phase_hint' => 'before' ),
					array( 'attachment_id' => 102, 'original_position' => 1, 'caption' => '', 'user_phase_hint' => 'after' ),
				),
				'relations'     => array(
					'customer_voice_post_ids' => array(),
					'staff'                   => array( array( 'post_id' => 7, 'comment' => '' ), array( 'post_id' => 8, 'comment' => '入力したコメント' ) ),
					'supervisor_post_ids'     => array(),
				),
			);
			$analysis = array(
				array( 'attachment_id' => 101, 'phase' => 'before', 'description' => 'd', 'caption' => 'とても長いキャプションがここに入りますとても長いキャプションがここに入ります', 'related_image_ids' => array(), 'uncertain' => false ),
				array( 'attachment_id' => 102, 'phase' => 'after', 'description' => 'd', 'caption' => '仕上がり', 'related_image_ids' => array(), 'uncertain' => false ),
			);
			$article  = array(
				'title'          => '【試験市】外壁塗装｜試験',
				'catch'          => 'c',
				'summary'        => 's',
				'sections'       => array( array( 'kind' => 'before', 'heading' => 'h', 'body' => 'b', 'image_ids' => array( 101 ) ) ),
				'seo'            => array( 'title' => '', 'description' => '', 'h1' => '', 'keywords' => '' ),
				'work_type'      => '外壁塗装・付帯部塗装',
				'request_text'   => 'ご要望の文章（試験）',
				'proposal_text'  => 'ご提案の文章（試験）',
				'staff_comments' => array( array( 'staff_no' => 1, 'comment' => 'AIの案（1）' ), array( 'staff_no' => 2, 'comment' => 'AIの案（2）' ) ),
				'category_ids'   => array( 5, 6, 99 ),
			);
			$doc = ResultAssembler::build( $input, $analysis, $article, wp_generate_uuid4() );
			$by  = array_column( $doc['sections'], null, 'node_id' );
			$this->t->same( array( 'custom', 'ご要望の文章（試験）' ), array( $by['request']['kind'], $by['request']['body'] ), 'ご要望の節' );
			$this->t->same( 'ご提案の文章（試験）', $by['proposal']['body'], 'ご提案の節' );
			$this->t->same( '要望（試験）', $doc['facts']['client_request']['value'], '入力の事実はそのまま' );
			$this->t->same( array( '外壁塗装・付帯部塗装', 'editor_review' ), array( $doc['facts']['work_type']['value'], $doc['facts']['work_type']['source'] ), '施工内容の一覧はAIの案（確認が必要）' );
			$this->t->same( array( 'AIの案（1）', '入力したコメント' ), array_column( $doc['relations']['staff'], 'comment' ), '担当コメント：空欄だけAIの案' );
			$this->t->same( array( "とても満足です。\n（試験）", 'user_input' ), array( $doc['facts']['customer_voice']['value'], $doc['facts']['customer_voice']['source'] ), 'お客様の声は入力のまま' );
			$this->t->true( mb_strlen( $doc['images'][0]['caption'] ) <= 40, 'AIのキャプションは短く切る' );
			$this->t->same( array( 5, 6 ), ResultAssembler::categories( $article, array( 5, 6, 7 ) ), 'カテゴリは候補に出したものだけ' );

			$input['primary_facts']['work_type'] = '入力した施工内容';
			$doc2                                = ResultAssembler::build( $input, $analysis, $article, wp_generate_uuid4() );
			$this->t->same( array( '入力した施工内容', 'user_input' ), array( $doc2['facts']['work_type']['value'], $doc2['facts']['work_type']['source'] ), '施工内容の入力があれば入力' );
		} );
	}

	private function patches(): void {
		$this->t->test( 'A-05', '修正：ご要望・ご提案は文章の節を直す（事実は残す）。担当コメントは staff を選んだときだけ。文章の節は本文の操作では直せない', function () {
			$doc = SampleDocument::get();
			$r   = PatchApplier::apply( $doc, array( 'client_request' ), array( array( 'op' => 'text_set', 'section' => 'client_request', 'value' => '直したご要望' ) ), false );
			$by  = array_column( $r['doc']['sections'], null, 'node_id' );
			$this->t->same( array( '直したご要望', 'sample request' ), array( $by['request']['body'], $r['doc']['facts']['client_request']['value'] ), 'ご要望の節' );
			$r = PatchApplier::apply( $doc, array( 'staff' ), array( array( 'op' => 'staff_comment_set', 'section' => 'staff', 'post_id' => 12, 'comment' => 'AIのコメント' ) ), true );
			$this->t->same( 'AIのコメント', $r['doc']['relations']['staff'][0]['comment'], 'AIによる担当コメントの修正' );
			$this->t->throws_case( CaseError::INVALID_VALUE, fn() => PatchApplier::apply( $doc, array( 'staff' ), array( array( 'op' => 'staff_comment_set', 'section' => 'staff', 'post_id' => 999, 'comment' => 'x' ) ), true ) );
			$this->t->throws_case( CaseError::INVALID_VALUE, fn() => PatchApplier::apply( $doc, array( 'body' ), array( array( 'op' => 'staff_comment_set', 'section' => 'staff', 'post_id' => 12, 'comment' => 'x' ) ), true ) );
			$this->t->throws_case( CaseError::INVALID_VALUE, fn() => PatchApplier::apply( $doc, array( 'body' ), array( array( 'op' => 'body_section_set', 'section' => 'body', 'node_id' => 'request', 'heading' => '', 'body' => 'x' ) ), true ) );
			$this->t->throws_case( CaseError::INVALID_VALUE, fn() => PatchApplier::apply( $doc, array( 'facts' ), array( array( 'op' => 'fact_set', 'section' => 'facts', 'fact_key' => 'customer_voice', 'value' => 'x', 'source' => 'user_input' ) ), true ) );
			$schema = Prompts::revise_schema( $doc, array( 'body', 'staff' ) );
			$json   = (string) wp_json_encode( $schema );
			$this->t->true( str_contains( $json, 'staff_comment_set' ) && str_contains( $json, '"enum":[12]' ), '出力の形：担当コメントは下書きにある担当者だけ' );
			$nodes = array();
			foreach ( $schema['properties']['operations']['items']['anyOf'] as $variant ) {
				if ( isset( $variant['properties']['node_id']['enum'] ) ) {
					$nodes = $variant['properties']['node_id']['enum'];
				}
			}
			$this->t->same( array(), array_values( array_intersect( $nodes, array( 'intro', 'request', 'proposal' ) ) ), '出力の形：本文の node_id に導入・ご要望・ご提案を含めない' );
		} );
	}

	private function mapping(): void {
		$this->t->test( 'A-06', 'テンプレートの更新：結び付けを「足しただけ」のときだけ引き継ぐ（既存の結び付けが変わる・問題がある・増えないなら引き継がない）', function () {
			$b    = static fn( string $slot, string $key ) => array( 'semantic_slot' => $slot, 'field_key' => $key, 'field_type' => 'text', 'path' => array( $slot ), 'transform_id' => 'identity', 'layout_name' => '', 'layout_key' => '' );
			$prev = array( 'document' => array( 'bindings' => array( $b( 'A', 'k1' ) ) ) );
			$s    = new MappingService();
			$this->t->true( $s->additive( $prev, array( 'bindings' => array( $b( 'A', 'k1' ), $b( 'B', 'k2' ) ), 'problems' => array() ) ), '足しただけ' );
			$this->t->true( ! $s->additive( $prev, array( 'bindings' => array( $b( 'A', 'k9' ), $b( 'B', 'k2' ) ), 'problems' => array() ) ), '既存の結び付けが変わった' );
			$this->t->true( ! $s->additive( $prev, array( 'bindings' => array( $b( 'B', 'k2' ) ), 'problems' => array() ) ), '既存の結び付けがなくなった' );
			$this->t->true( ! $s->additive( $prev, array( 'bindings' => array( $b( 'A', 'k1' ), $b( 'B', 'k2' ) ), 'problems' => array( array( 'kind' => 'missing' ) ) ) ), '問題がある' );
			$this->t->true( ! $s->additive( $prev, array( 'bindings' => array( $b( 'A', 'k1' ) ), 'problems' => array() ) ), '増えていない' );
		} );

		$this->t->test( 'A-07', 'このサイトの承認済みの版は書き方 1.2 で、新しい項目（施主様名・お客様の声・築年数・施工箇所）の結び付けがある', function () {
			$inspector = new SchemaInspector();
			$s         = new MappingService();
			try {
				$row = $s->gate( $inspector->context( Plugin::POST_TYPE, 0, array() ) );
			} catch ( CaseError $e ) {
				throw new SkipTest( '承認済みのマッピングがありません' );
			}
			$this->t->same( ShipWorksTemplateV11::TEMPLATE_VERSION, MappingService::template_version( $row ), 'テンプレートの版' );
			$slots = array_keys( $s->by_slot( $row ) );
			foreach ( array( 'EntryData_CustomerName', 'EntryData_CustomerVoice', 'Outline/@Outline_Label_Age', 'Outline/@Outline_Label_Parts' ) as $slot ) {
				$this->t->true( in_array( $slot, $slots, true ), "結び付け {$slot}" );
			}
		} );
	}

	/**
	 * @param array<string,int> $images
	 */
	private function import( array $images ): void {
		$this->t->test( 'A-08', '既存ページの取り込み：タグ・スクリプトを除いた文章（写真の位置に印）だけを送り、項目と写真（工程・説明・使うか）を取り出す。このサイトのメディアの画像は別ドメイン・縮小版でも見つけ、ないものはアドレスを返す。同意がなければ送らない', function () use ( $images ) {
			$file = (string) get_post_meta( $images['before1'], '_wp_attached_file', true );
			$ext  = (string) pathinfo( $file, PATHINFO_EXTENSION );
			$thumb = substr( $file, 0, -strlen( $ext ) - 1 ) . '-300x200.' . $ext;
			$html = '<div class="works"><h2>工事の概要</h2><script>alert("x")</script><p>住所&nbsp;試験市</p>'
				. '<img src="https://old.example.com/wp-content/uploads/' . esc_attr( $thumb ) . '" alt="施工前">'
				. '<img src="https://old.example.com/logo.png" alt="会社のロゴ">'
				. '<h3>施工後</h3><img src="' . esc_url( wp_get_attachment_url( $images['after1'] ) ) . '" alt="施工後">'
				. '<img src="' . esc_url( wp_get_attachment_url( $images['after1'] ) ) . '">'
				. '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" width="1" height="1">'
				. '<img src="https://cdn.example.net/photo.jpg" alt="足場の設置">'
				. '<a href="https://cdn.example.net/full.jpg"><img src="https://cdn.example.net/full-300x200.jpg"></a>'
				. '<img src="/icon.png" width="24" height="24"><p>VOICE お客様の声 とても満足です</p></div>';
			MockProvider::$calls    = 0;
			MockProvider::$payloads = array();
			$s = $this->service();
			$e = $this->err( fn() => $s->import( array( 'html' => $html ), $this->user ) );
			$this->t->same( array( 'AI-017', 0 ), array( $e->response['error_code'], MockProvider::$calls ), '同意なしは送らない' );
			$e = $this->err( fn() => $s->import( array( 'html' => '<p> </p>', 'consent_external_ai' => true ), $this->user ) );
			$this->t->same( array( 'AI-017', 0 ), array( $e->response['error_code'], MockProvider::$calls ), '文章がなければ送らない' );
			$r = $s->import( array( 'html' => $html, 'consent_external_ai' => true ), $this->user );
			$this->t->same( 1, MockProvider::$calls, '外部呼び出しは1回' );
			$sent = (string) MockProvider::$payloads[0]['text'];
			$this->t->true( ! str_contains( $sent, 'alert' ) && ! str_contains( $sent, '<' ) && str_contains( $sent, '住所 試験市' ) && str_contains( $sent, "工事の概要\n" ), '送る文章（タグ・スクリプトなし、改行は残す）' );
			$this->t->same( array( 'model', 'text' ), array_keys( MockProvider::$payloads[0] ), '送る項目' );
			$this->t->true( str_contains( $sent, '[写真1]（施工前）' ) && str_contains( $sent, "施工後\n[写真3]（施工後）" ), '写真の位置の印（見出しの近く）' );
			$this->t->same( 2, substr_count( $sent, '[写真3]' ), '同じ写真が2回出たら同じ番号を2回' );
			$this->t->true( ! str_contains( $sent, '[写真6]' ), 'データURI・小さいアイコンは写真にしない' );
			$this->t->same(
				array( array( $images['before1'], '' ), array( $images['after1'], '' ), array( 0, 'https://cdn.example.net/photo.jpg' ), array( 0, 'https://cdn.example.net/full.jpg' ) ),
				array_map( static fn( $p ) => array( $p['attachment_id'], $p['url'] ), $r['photos'] ),
				'写真（重複なし・工程の順（施工前→施工後→不明）・同じ工程はページの順。メディアにあるものは ID、ないものはアドレス。ポップアップのリンク先を元の大きさとして使う）'
			);
			$this->t->same( array( 1, 'after', '' ), array( $r['photos_skipped'], $r['photos'][1]['phase'], $r['photos'][1]['caption'] ), 'ロゴは除く・工程・工程名だけの説明は空' );
			$this->t->true( str_starts_with( $r['facts']['overview'] ?? '', '【模擬】' ) && '模擬市' === ( $r['facts']['area'] ?? '' ), '取り出した項目' );
			$this->t->true( ! isset( $r['facts']['cost'] ), '空の項目は返さない' );

			$r = $this->service( 'evil' )->import( array( 'html' => $html, 'consent_external_ai' => true ), $this->user );
			$this->t->same( '模擬市', $r['facts']['area'], 'タグを除く' );
			$this->t->same( 2000, mb_strlen( $r['facts']['customer_voice'] ), '長さを制限する' );
			$this->t->same( "とても満足です。\n家族も喜んでいます。", \WpAiCase\Jobs\PageImporter::clean_voice( "VOICE\n【施工後】\nとても満足です。\n【お客様の声】家族も喜んでいます。" ), 'お客様の声の小見出しを除く' );
		} );
	}

	/**
	 * ページの画像のメディアへの取り込み（HTTP は試験用の応答に差し替え、外へは通信しない）。
	 */
	private function sideload(): void {
		$this->t->test( 'A-11', 'ページの画像の取り込み：縮小版なら元の大きさを先に取得・取り込み元を記録・同じアドレスは再利用・小さい画像／画像でない／認証が必要／内部アドレスは拒否・アップロード権限が必要', function () {
			$jpeg = static function ( int $w, int $h ): string {
				$im = imagecreatetruecolor( $w, $h );
				imagefill( $im, 0, 0, imagecolorallocate( $im, 120, 160, 200 ) );
				ob_start();
				imagejpeg( $im, null, 80 );
				return (string) ob_get_clean();
			};
			$asked = array();
			$fake  = static function ( $pre, $args, $url ) use ( &$asked, $jpeg ) {
				$asked[] = $url;
				$map     = array(
					'https://example.com/wp-content/uploads/2020/01/wpaic-selftest-photo.jpg' => array( 200, $jpeg( 640, 480 ) ),
					'https://example.com/tiny.jpg'      => array( 200, $jpeg( 100, 80 ) ),
					'https://example.com/not-image.jpg' => array( 200, 'hello' ),
				);
				[ $code, $body ] = $map[ $url ] ?? array( str_contains( $url, 'auth' ) ? 401 : 404, '' );
				return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
			};
			add_filter( 'pre_http_request', $fake, 10, 3 );
			$created = array();
			try {
				$s   = new JobService();
				$url = 'https://example.com/wp-content/uploads/2020/01/wpaic-selftest-photo-300x200.jpg';
				$r   = $s->import_media( array( 'url' => $url, 'title' => '足場の設置' ), $this->user );
				$created[] = $r['attachment_id'];
				$this->t->same( 'https://example.com/wp-content/uploads/2020/01/wpaic-selftest-photo.jpg', $asked[0] ?? '', '元の大きさを先に取得' );
				$this->t->true( wp_attachment_is_image( $r['attachment_id'] ) && false === $r['reused'], 'メディアに登録' );
				$this->t->same( array( $url, '足場の設置' ), array( get_post_meta( $r['attachment_id'], \WpAiCase\Jobs\PageImporter::META_SOURCE, true ), get_the_title( $r['attachment_id'] ) ), '取り込み元・タイトル' );
				$n     = count( $asked );
				$again = $s->import_media( array( 'url' => $url ), $this->user );
				$this->t->same( array( $r['attachment_id'], true, $n ), array( $again['attachment_id'], $again['reused'], count( $asked ) ), '同じアドレスは再利用（取得しない）' );
				foreach ( array( 'https://example.com/tiny.jpg' => '小さすぎる', 'https://example.com/not-image.jpg' => '写真', 'https://example.com/auth.jpg' => '認証', 'http://127.0.0.1/x.jpg' => 'アドレス', 'file:///etc/passwd' => 'アドレス' ) as $bad => $word ) {
					$e = $this->err( fn() => $s->import_media( array( 'url' => $bad ), $this->user ) );
					$this->t->true( 'AI-017' === $e->response['error_code'] && str_contains( (string) ( $e->extra['fields'][0] ?? '' ), $word ), "拒否 {$bad}" );
				}
				$deny = static function ( $allcaps ) {
					$allcaps['upload_files'] = false;
					return $allcaps;
				};
				add_filter( 'user_has_cap', $deny );
				try {
					$this->t->same( 'AI-015', $this->err( fn() => $s->import_media( array( 'url' => $url ), $this->user ) )->response['error_code'], 'アップロード権限なし' );
				} finally {
					remove_filter( 'user_has_cap', $deny );
				}
			} finally {
				remove_filter( 'pre_http_request', $fake, 10 );
				foreach ( $created as $id ) {
					wp_delete_attachment( (int) $id, true );
				}
			}
			$this->t->same( array(), array_values( array_filter( $created, static fn( $id ) => null !== get_post( (int) $id ) ) ), '試験の画像を削除した' );
		} );
	}

	/**
	 * @param array<string,int> $images
	 */
	private function meta( array $images ): void {
		$state = array();
		$this->t->test( 'A-09', '作成：記事の方針と候補のカテゴリをAIへ渡し、空欄の担当コメントだけ頼む。カテゴリはAIの選択（候補だけ）、アイキャッチは施工後の1枚目', function () use ( $images, &$state ) {
			$cats = JobService::categories();
			if ( ! $cats ) {
				throw new SkipTest( '施工事例カテゴリがありません' );
			}
			$rel = Poc::default_relations();
			$ids = array( $images['before1'], $images['during1'], $images['after1'], $images['after2'] );
			$in  = array(
				'client_case_id'        => 'selftest',
				'idempotency_key'       => wp_generate_uuid4(),
				'mode'                  => 'create',
				'post_type'             => 'works',
				'primary_facts'         => array( 'overview' => '外壁塗装（試験）', 'client_request' => '色あせ（試験）', 'work_done' => '3回塗り（試験）', 'area' => '試験市' ),
				'photo_set_description' => '',
				'article_policy'        => 'やわらかい文体で（試験）',
				'images'                => array_map( static fn( $id, $i ) => array( 'attachment_id' => $id, 'original_position' => $i, 'caption' => '', 'user_phase_hint' => 'unknown' ), $ids, array_keys( $ids ) ),
				'consent_external_ai'   => true,
				'relations'             => array( 'customer_voice_post_ids' => array(), 'staff' => array( array( 'post_id' => $rel['staff'], 'comment' => '' ) ), 'supervisor_post_ids' => array() ),
			);
			MockProvider::$payloads = array();
			$s            = $this->service();
			$job          = $s->create( $in, $this->user );
			$this->jobs[] = $job['job_id'];
			$job          = $s->tick( $job['job_id'], $this->user );
			$this->t->same( 'proposal_ready', $job['status'], '解析後' );
			$p = MockProvider::$payloads[0];
			$this->t->same( array( 'やわらかい文体で（試験）', array( 1 ), count( $cats ) ), array( $p['article_policy'], $p['staff_comment_needed'], count( $p['categories'] ) ), '方針・コメントを頼む担当者（番号）・候補' );
			$doc = $job['data']['versions'][0];
			$this->t->same( '【模擬】担当スタッフのコメントです。', $doc['relations']['staff'][0]['comment'], '空欄の担当コメントはAIの案' );
			$this->t->same( array( array( $cats[0]['id'] ), 'ai', JobService::default_thumbnail( $doc ) ), array( $job['data']['meta']['terms'], $job['data']['meta']['terms_source'], $job['data']['meta']['thumbnail_id'] ), 'カテゴリ・アイキャッチの初期値' );
			$after = array_values( array_filter( $doc['images'], static fn( $i ) => 'after' === $i['phase'] ) );
			$this->t->same( $after[0]['attachment_id'], $job['data']['meta']['thumbnail_id'], 'アイキャッチは施工後の1枚目' );
			$state = array( 'job' => $job, 'cats' => $cats, 'ids' => $ids );
		} );

		$this->t->test( 'A-10', 'カテゴリ・アイキャッチの変更（存在しないターム・記事に使わない画像は拒否）→ 保存で下書きに設定。記録のない項目は空なら手での編集とみなさない', function () use ( &$state ) {
			if ( ! $state ) {
				throw new SkipTest( 'A-09 が失敗' );
			}
			$s    = $this->service();
			$job  = $state['job'];
			$cats = $state['cats'];
			$e    = $this->err( fn() => $s->set_meta( $job['job_id'], $this->user, 0, array( 'terms' => array( 999999999 ) ) ) );
			$this->t->same( 'AI-017', $e->response['error_code'], '存在しないターム' );
			$e = $this->err( fn() => $s->set_meta( $job['job_id'], $this->user, 0, array( 'thumbnail_id' => 999999999 ) ) );
			$this->t->same( 'AI-017', $e->response['error_code'], '記事に使わない画像' );
			$want = array_slice( array_column( $cats, 'id' ), 0, 2 );
			$job  = $s->set_meta( $job['job_id'], $this->user, 0, array( 'terms' => $want, 'thumbnail_id' => $state['ids'][0] ) );
			$this->t->same( array( $want, 'user', $state['ids'][0] ), array( $job['data']['meta']['terms'], $job['data']['meta']['terms_source'], $job['data']['meta']['thumbnail_id'] ), '変更を記録' );
			$this->t->same( 1, count( $job['data']['versions'] ), '版は増やさない' );
			$job = $s->save( $job['job_id'], $this->user, 0 );
			$pid = (int) $job['post_id'];
			update_post_meta( $pid, Poc::MARK, '1' );
			$got = wp_get_post_terms( $pid, Plugin::category_taxonomy(), array( 'fields' => 'ids' ) );
			sort( $got );
			sort( $want );
			$this->t->same( array( 'draft', $want, $state['ids'][0] ), array( get_post_status( $pid ), array_map( 'intval', $got ), (int) get_post_thumbnail_id( $pid ) ), '下書きのカテゴリ・アイキャッチ' );

			// テンプレートの更新で増えた項目（記録のない項目）は、今も空なら「手で編集」とみなさない。
			$saved = DraftState::load( $pid );
			$inspector = new SchemaInspector();
			$context   = $inspector->context( Plugin::POST_TYPE, $pid );
			$mapping   = ( new MappingService() )->gate( $context );
			$st        = $saved['state'];
			unset( $st['db']['EntryData_CustomerVoice'] );
			$this->t->same( array(), DraftState::edited_slots( $pid, $st, $mapping, $inspector->groups( $context ), array( 'EntryData_CustomerVoice' ) ), '空なら編集していない' );
			$voice_key = (string) ( new MappingService() )->by_slot( $mapping )['EntryData_CustomerVoice']['field_key'];
			update_field( $voice_key, '<p>手で入れた声</p>', $pid );
			$this->t->same( array( 'EntryData_CustomerVoice' ), DraftState::edited_slots( $pid, $st, $mapping, $inspector->groups( $context ), array( 'EntryData_CustomerVoice' ) ), '値があれば編集した' );
			$state['post'] = $pid;
		} );

		$this->t->test( 'A-15', 'プレビューしながら直す：修正中の下書きに変更をすぐ反映（修正は続けられる・版が進む）。編集画面で保存された後は反映しない（AI-012）', function () use ( &$state ) {
			if ( empty( $state['post'] ) ) {
				throw new SkipTest( 'A-10 が失敗' );
			}
			$pid = (int) $state['post'];
			$s   = $this->service();
			$job = $s->create( array( 'idempotency_key' => wp_generate_uuid4(), 'mode' => 'revise_draft', 'post_type' => 'works', 'post_id' => $pid, 'base_version' => DraftState::version( $pid ), 'consent_external_ai' => true ), $this->user );
			$this->jobs[] = $job['job_id'];
			$v0  = DraftState::version( $pid );
			$job = $s->revise_local( $job['job_id'], $this->user, 0, array( 'title' ), array( array( 'op' => 'text_set', 'section' => 'title', 'value' => '反映の試験（1）' ) ) );
			$job = $s->sync( $job['job_id'], $this->user, 1 );
			clean_post_cache( $pid );
			$this->t->same( array( 'proposal_ready', '反映の試験（1）', $v0 + 1 ), array( $job['status'], get_post( $pid )->post_title, DraftState::version( $pid ) ), '1回目の反映' );
			$this->t->true( in_array( 'タイトル', (array) $job['data']['last_sync']['changed'], true ), '反映した部分' );
			$job = $s->revise_local( $job['job_id'], $this->user, 1, array( 'seo' ), array( array( 'op' => 'seo_set', 'section' => 'seo', 'field' => 'h1', 'value' => '反映の試験 H1' ) ) );
			$job = $s->sync( $job['job_id'], $this->user, 2 );
			$this->t->same( array( 'proposal_ready', $v0 + 2 ), array( $job['status'], DraftState::version( $pid ) ), '2回目の反映（続けて直せる）' );
			wp_update_post( array( 'ID' => $pid, 'post_title' => '編集画面で変えた' ) );
			$job = $s->revise_local( $job['job_id'], $this->user, 2, array( 'title' ), array( array( 'op' => 'text_set', 'section' => 'title', 'value' => '反映の試験（2）' ) ) );
			$e   = $this->err( fn() => $s->sync( $job['job_id'], $this->user, 3 ) );
			clean_post_cache( $pid );
			$this->t->same( array( 'AI-012', '編集画面で変えた' ), array( $e->response['error_code'], get_post( $pid )->post_title ), '編集画面で保存された後は反映しない（人の編集を残す）' );
			$this->t->same( 'proposal_ready', $s->repository()->get( $job['job_id'] )['status'], '修正の画面は続けられる' );
			$s->cancel( $job['job_id'], $this->user );
		} );

		$this->t->test( 'A-16', '書き方の手本：選んだ施工事例の記事の文章と文字数を作成の依頼に入れる（お客様の声などは入れない）。外せば入れない。施工事例以外は選べない', function () use ( &$state ) {
			if ( empty( $state['post'] ) ) {
				throw new SkipTest( 'A-10 が失敗' );
			}
			$saved = get_option( \WpAiCase\Display\StyleExample::OPTION, null );
			try {
				\WpAiCase\Display\StyleExample::set( (int) $state['post'] );
				$ex = \WpAiCase\Display\StyleExample::for_prompt();
				$this->t->true( is_array( $ex ) && '' !== $ex['summary'] && isset( $ex['lengths']['proposal'] ), '手本の文章' );
				$this->t->same( array(), array_values( array_diff( array_keys( (array) $ex ), array( 'catch', 'summary', 'request', 'proposal', 'before', 'during', 'after', 'lengths' ) ) ), '入れる欄（お客様の声・担当コメントは入れない）' );
				$this->t->true( str_contains( Prompts::compose_text( array( 'mode' => 'full', 'images' => array(), 'style_example' => $ex ) ), '"style_example"' ), '依頼の本文' );
				$page = (int) ( get_posts( array( 'post_type' => 'page', 'posts_per_page' => 1, 'fields' => 'ids' ) )[0] ?? 0 );
				if ( $page ) {
					$threw = false;
					try {
						\WpAiCase\Display\StyleExample::set( $page );
					} catch ( \InvalidArgumentException $e ) {
						$threw = true;
					}
					$this->t->true( $threw, '施工事例以外は選べない' );
				}
				\WpAiCase\Display\StyleExample::set( 0 );
				$this->t->same( null, \WpAiCase\Display\StyleExample::for_prompt(), '外せば入れない' );
			} finally {
				null === $saved ? delete_option( \WpAiCase\Display\StyleExample::OPTION ) : update_option( \WpAiCase\Display\StyleExample::OPTION, $saved, false );
				\WpAiCase\Display\StyleExample::flush();
			}
		} );
	}

	private function cleanup(): void {
		$s = new JobService();
		foreach ( array_unique( $this->jobs ) as $id ) {
			$job = $s->repository()->get( $id );
			if ( $job && $job['post_id'] && get_post_meta( (int) $job['post_id'], JobService::META_JOB, true ) === $id && 'draft' === get_post_status( (int) $job['post_id'] ) ) {
				wp_delete_post( (int) $job['post_id'], true );
			}
			$s->repository()->delete( $id );
		}
		$this->t->test( 'A-99', '試験で作ったジョブ・下書きを削除した', function () use ( $s ) {
			foreach ( $this->jobs as $id ) {
				$this->t->same( null, $s->repository()->get( $id ), "ジョブ {$id} が残っている" );
			}
		} );
	}
}
