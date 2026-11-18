<?php
/**
 * 実際の WordPress / ACF に書き込む試験。作った試験用の投稿は最後に必ず削除する。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev\SelfTest;

use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Dev\Poc;
use WpAiCase\Draft\DraftWriter;
use WpAiCase\Draft\Snapshot;
use WpAiCase\Mapping\MappingService;
use WpAiCase\Plugin;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class IntegrationTests {

	private const TEST_MARK = '_wp_ai_case_selftest';

	/** @var int[] */
	private array $created = array();

	public function __construct( private Tester $t, private int $user_id ) {
	}

	public function run(): void {
		try {
			$this->tests();
		} finally {
			$this->cleanup();
		}
	}

	private function tests(): void {
		$t = $this->t;

		$doc = null;
		try {
			$doc = Poc::load_fixture( 'poc_full', Poc::default_relations() );
		} catch ( CaseError $e ) {
			$t->test( 'I-00', '前提：合成画像・関連付け先', fn() => throw new SkipTest( $e->getMessage() ) );
			return;
		}

		$writer  = new DraftWriter();
		$main    = 0;
		$service = new MappingService();
		$context = ( new SchemaInspector() )->context( Plugin::POST_TYPE, 0, array() );
		try {
			$mapping = $service->gate( $context );
		} catch ( CaseError $e ) {
			$t->test( 'I-00', '前提：この文脈の承認済みマッピング', fn() => throw new SkipTest( $e->getMessage() ) );
			return;
		}

		$t->test( 'I-01', 'C1-A01/02 合成CaseDocument→下書き作成→保存→読み戻し一致（FV=ビフォーアフター）', function () use ( $writer, $mapping, $doc, &$main ) {
			$r    = $writer->create_from_document( $doc, $this->user_id, $this->opts( array( 'hero' => 'before_after' ) ) );
			$main = (int) $r['post_id'];
			$this->created[] = $main;
			$this->t->same( 'ok', $r['readback'], '読み戻し' );
			$this->t->same( 'draft', get_post_status( $main ), 'post_status' );
			$this->t->true( in_array( '施工事例_ver1.1', $r['groups'], true ), '適用グループ' );
			$this->t->same( 8, count( $r['attachments'] ), '写真8枚すべてを使う' );
		} );

		$t->test( 'I-02', 'テーマが読む形式で値が取れる（FV・概要・Format・関連付け）', function () use ( &$main ) {
			if ( ! $main ) {
				throw new SkipTest( 'I-01 が失敗' );
			}
			Snapshot::flush( $main );
			$fv = get_field( 'FV_Layout', $main );
			$this->t->same( 'layout03', is_array( $fv ) ? ( $fv['value'] ?? null ) : null, 'FV_Layout' );
			$this->t->true( '' !== (string) get_post_meta( $main, 'Outline_0_Outline_Area', true ), '概要の1行目（テーマは postmeta を直接読む）' );
			$format = get_field( 'Format', $main );
			$this->t->same( array( 'EntryData_Label_Detail', 'Feature_Label', 'BeforeAfter_Label' ), array_column( (array) $format, 'acf_fc_layout' ), 'Format の並び' );
			$detail = $format[0]['EntryData_Group_Detail'] ?? array();
			$this->t->same( 2, count( $detail['EntryData_SectionMae'][1]['EntryData_PictureMae'] ?? array() ), '施工前の写真2枚' );
			$this->t->true( is_array( $detail['EntryData_SectionMae'][1]['EntryData_PictureMae'][0]['EntryData_PictureMae_Image'] ?? null ), '画像は配列（sizes付き）で返る' );
			$voice = get_field( 'EntryData_RelatedData', $main );
			$this->t->true( is_array( $voice ) && $voice[0] instanceof \WP_Post && 'voice' === $voice[0]->post_type, 'お客様の声' );
			$staff = get_field( 'EntryData_Staff', $main );
			$this->t->true( is_array( $staff ) && 1 === count( $staff ) && ( $staff[0]['EntryData_Staff_Name'][0] ?? null ) instanceof \WP_Post, '担当スタッフ' );
			$sup = get_field( 'entrydata_supervision', $main );
			$this->t->true( is_array( $sup ) && 'staff' === $sup[0]->post_type, '監修者' );
		} );

		$t->test( 'I-03', 'FV=シングル固定・ギャラリーでも保存・読み戻し一致', function () use ( $writer, $mapping, $doc ) {
			foreach ( array( 'single' => 'layout01', 'gallery' => 'layout02' ) as $hero => $layout ) {
				$r               = $writer->create_from_document( $doc, $this->user_id, $this->opts( array( 'hero' => $hero ) ) );
				$this->created[] = (int) $r['post_id'];
				$this->t->same( 'ok', $r['readback'], $hero );
				Snapshot::flush( (int) $r['post_id'] );
				$this->t->same( $layout, get_field( 'FV_Layout', (int) $r['post_id'] )['value'] ?? null, $hero );
			}
		} );

		$t->test( 'I-04', 'C1-A02 指定しないフィールドは保持される（一部だけ更新）', function () use ( $writer, $mapping, &$main ) {
			if ( ! $main ) {
				throw new SkipTest( 'I-01 が失敗' );
			}
			$before = Snapshot::capture( $main );
			$r      = $writer->write_fields( $main, array( 'EntryData_CatchTxt' => '【合成テスト】一部更新', 'Request' => '<p>【合成テスト】一部更新</p>' ), $this->user_id, (string) get_post( $main )->post_modified_gmt, $mapping );
			$this->t->same( array( 'EntryData_CatchTxt', 'Request' ), $r['fields_written'], '書いたフィールド' );
			$after   = Snapshot::capture( $main );
			$changed = array();
			foreach ( array_unique( array_merge( array_keys( $before['meta'] ), array_keys( $after['meta'] ) ) ) as $key ) {
				if ( ( $before['meta'][ $key ] ?? null ) != ( $after['meta'][ $key ] ?? null ) ) { // phpcs:ignore Universal.Operators.StrictComparisons
					$changed[] = $key;
				}
			}
			sort( $changed );
			$this->t->same( array( 'EntryData_CatchTxt', 'Request' ), $changed, '変わったメタ' );
		} );

		$t->test( 'I-05', 'C1-ACF09 生成の途中でACF定義が変わったら（未対応の型が現れた等）、何も書かずに止まる', function () use ( $writer, $mapping, &$main ) {
			if ( ! $main ) {
				throw new SkipTest( 'I-01 が失敗' );
			}
			$change = static function ( $field ) {
				$field['type'] = 'google_map';
				return $field;
			};
			$reset  = static function (): void {
				foreach ( array( 'fields', 'local-fields', 'values' ) as $store ) {
					$s = acf_get_store( $store );
					if ( $s ) {
						$s->reset();
					}
				}
			};
			$before = Snapshot::capture( $main );
			add_filter( 'acf/load_field/name=EntryData_CatchTxt', $change );
			$reset();
			try {
				$this->t->throws_case( CaseError::SCHEMA_CHANGED, fn() => $writer->write_fields( $main, array( 'EntryData_CatchTxt' => 'x' ), $this->user_id, (string) get_post( $main )->post_modified_gmt, $mapping ) );
			} finally {
				remove_filter( 'acf/load_field/name=EntryData_CatchTxt', $change );
				$reset();
			}
			$this->t->true( $before['meta'] == Snapshot::capture( $main )['meta'], 'メタが変わった' ); // phpcs:ignore Universal.Operators.StrictComparisons
		} );

		$t->test( 'I-06', '条件分岐で非表示の枝（FV=シングルなのにギャラリー）を拒否し、何も書かない', function () use ( $writer, $mapping, &$main ) {
			if ( ! $main ) {
				throw new SkipTest( 'I-01 が失敗' );
			}
			$images = Poc::images();
			$before = Snapshot::capture( $main );
			$this->t->throws_case(
				CaseError::HIDDEN_BY_CONDITION,
				fn() => $writer->write_fields(
					$main,
					array(
						'FV_Layout'         => 'layout01',
						'FV_Layout_Gallery' => array( array( 'FV_Layout_Gallery_img' => $images['after1'] ) ),
					),
					$this->user_id,
					(string) get_post( $main )->post_modified_gmt,
					$mapping
				)
			);
			$this->t->true( $before['meta'] == Snapshot::capture( $main )['meta'], 'メタが変わった' ); // phpcs:ignore Universal.Operators.StrictComparisons
		} );

		$t->test( 'I-07', '下書き以外（非公開・公開済み）には保存しない', function () use ( $writer, $mapping ) {
			$private = wp_insert_post( array( 'post_type' => Plugin::POST_TYPE, 'post_status' => 'private', 'post_title' => '【合成テスト】非公開', 'post_author' => $this->user_id ), true );
			$this->t->true( ! is_wp_error( $private ), '試験用の非公開投稿を作れない' );
			$this->created[] = (int) $private;
			update_post_meta( $private, Poc::MARK, '1' );
			update_post_meta( $private, self::TEST_MARK, '1' );
			$this->t->throws_case( CaseError::POST_STATE, fn() => $writer->write_fields( (int) $private, array( 'EntryData_CatchTxt' => 'x' ), $this->user_id, (string) get_post( $private )->post_modified_gmt, $mapping ) );
			$published = get_posts( array( 'post_type' => Plugin::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ) );
			if ( $published ) {
				// 公開済みの実記事には書き込み処理を呼ばず、事前チェックだけを確かめる。
				$this->t->throws_case( CaseError::POST_STATE, fn() => $writer->guard_post( (int) $published[0], $this->user_id, (string) get_post( $published[0] )->post_modified_gmt ) );
			}
		} );

		$t->test( 'I-08', '基準版より後に更新された下書き・他人の編集中は保存しない', function () use ( $writer, $mapping, &$main ) {
			if ( ! $main ) {
				throw new SkipTest( 'I-01 が失敗' );
			}
			$this->t->throws_case( CaseError::STALE_BASE, fn() => $writer->write_fields( $main, array( 'EntryData_CatchTxt' => 'x' ), $this->user_id, '2000-01-01 00:00:00', $mapping ) );
			$others = get_users( array( 'role' => 'administrator', 'exclude' => array( $this->user_id ), 'number' => 1, 'fields' => 'ID' ) );
			if ( $others ) {
				update_post_meta( $main, '_edit_lock', time() . ':' . (int) $others[0] );
				try {
					$this->t->throws_case( CaseError::POST_STATE, fn() => $writer->write_fields( $main, array( 'EntryData_CatchTxt' => 'x' ), $this->user_id, (string) get_post( $main )->post_modified_gmt, $mapping ) );
				} finally {
					delete_post_meta( $main, '_edit_lock' );
				}
			}
		} );

		$t->test( 'I-09', '開始時と保存直前で schema hash が違えば保存しない', function () use ( $writer, $mapping, &$main ) {
			if ( ! $main ) {
				throw new SkipTest( 'I-01 が失敗' );
			}
			$this->t->throws_case( CaseError::SCHEMA_CHANGED, fn() => $writer->write_fields( $main, array( 'EntryData_CatchTxt' => 'x' ), $this->user_id, (string) get_post( $main )->post_modified_gmt, array( 'schema_sha256' => str_repeat( '0', 64 ) ) + $mapping ) );
		} );

		$t->test( 'I-10', '保存の途中で失敗したらスナップショットへ戻す', function () use ( $writer, $mapping, $doc, &$main ) {
			if ( ! $main ) {
				throw new SkipTest( 'I-01 が失敗' );
			}
			$fields = $writer->translate( $doc, $mapping, array( 'hero' => 'gallery' ) );
			$before = Snapshot::capture( $main );
			$fail   = static fn() => 3;
			add_filter( 'wp_ai_case_test_inject_write_failure', $fail );
			try {
				$e = $this->t->throws_case( CaseError::WRITE_FAILED, fn() => $writer->write_fields( $main, $fields, $this->user_id, (string) get_post( $main )->post_modified_gmt, $mapping ) );
			} finally {
				remove_filter( 'wp_ai_case_test_inject_write_failure', $fail );
			}
			$this->t->same( true, $e->context['restored'] ?? null, '復元結果' );
			$this->t->same( 3, $e->context['written'] ?? null, '書いた件数' );
			$this->t->true( $before['meta'] == Snapshot::capture( $main )['meta'], '復元後のメタが元と違う' ); // phpcs:ignore Universal.Operators.StrictComparisons
			$this->t->true( null !== Snapshot::load_persisted( $main ), '手動復旧用のスナップショットが保存されていない' );
		} );

		$t->test( 'I-11', '紐付けなし（お客様の声・担当スタッフ・監修者が空）でも下書きを作り、関連付けは空のまま', function () use ( $writer, $mapping, $doc ) {
			$none              = $doc;
			$none['relations'] = array( 'customer_voice_post_ids' => array(), 'staff' => array(), 'supervisor_post_ids' => array() );
			$r                 = $writer->create_from_document( $none, $this->user_id, $this->opts( array( 'hero' => 'single' ) ) );
			$id                = (int) $r['post_id'];
			$this->created[]   = $id;
			$this->t->same( 'ok', $r['readback'], '読み戻し' );
			$this->t->same( array(), $r['related_posts'], '関連先' );
			foreach ( array( 'EntryData_RelatedData', 'EntryData_Staff', 'entrydata_supervision' ) as $name ) {
				$this->t->true( ! in_array( $name, $r['fields_written'], true ), "{$name} を書いた" );
				$this->t->true( empty( get_field( $name, $id, false ) ), "{$name} に値がある" );
			}
		} );
		$t->test( 'I-12', 'お客様の声だけ紐付けなし（担当スタッフ・監修者はあり）でも保存できる', function () use ( $writer, $mapping, $doc ) {
			$part                                         = $doc;
			$part['relations']['customer_voice_post_ids'] = array();
			$r                                            = $writer->create_from_document( $part, $this->user_id, $this->opts( array( 'hero' => 'single' ) ) );
			$this->created[]                              = (int) $r['post_id'];
			$this->t->same( 'ok', $r['readback'], '読み戻し' );
			$this->t->true( in_array( 'EntryData_Staff', $r['fields_written'], true ) && in_array( 'entrydata_supervision', $r['fields_written'], true ), 'スタッフ・監修者を書いていない' );
			$this->t->true( ! in_array( 'EntryData_RelatedData', $r['fields_written'], true ), 'お客様の声を書いた' );
		} );
		$t->test( 'I-13', '未承認のマッピングでは、合成データ以外の下書きに保存しない', function () use ( $writer, $mapping ) {
			$id = wp_insert_post( array( 'post_type' => Plugin::POST_TYPE, 'post_status' => 'draft', 'post_title' => '【合成テスト】印なし', 'post_author' => $this->user_id ), true );
			$this->t->true( ! is_wp_error( $id ), '試験用の下書きを作れない' );
			$this->created[] = (int) $id;
			update_post_meta( $id, self::TEST_MARK, '1' );
			$candidate = array( 'status' => 'validated_local' ) + $mapping;
			$this->t->throws_case( CaseError::MAPPING_NOT_READY, fn() => $writer->write_fields( (int) $id, array( 'EntryData_CatchTxt' => 'x' ), $this->user_id, (string) get_post( $id )->post_modified_gmt, $candidate ) );
		} );

		$t->test( 'I-14', 'C1-ACF04/07 投稿の適用条件（タクソノミー）が変わったら、その投稿には元のマッピングで保存しない', function () use ( $writer, $mapping ) {
			$term = get_term_by( 'slug', 'old_layout', 'sys_tag' );
			if ( ! $term ) {
				throw new SkipTest( 'old_layout タームがありません' );
			}
			$id = wp_insert_post( array( 'post_type' => Plugin::POST_TYPE, 'post_status' => 'draft', 'post_title' => '【合成テスト】条件変更', 'post_author' => $this->user_id ), true );
			$this->created[] = (int) $id;
			update_post_meta( $id, Poc::MARK, '1' );
			update_post_meta( $id, self::TEST_MARK, '1' );
			wp_set_object_terms( (int) $id, array( (int) $term->term_id ), 'sys_tag' );
			$this->t->throws_case( CaseError::SCHEMA_CHANGED, fn() => $writer->write_fields( (int) $id, array( 'EntryData_CatchTxt' => 'x' ), $this->user_id, (string) get_post( $id )->post_modified_gmt, $mapping ) );
			$service = new MappingService();
			$ctx     = ( new SchemaInspector() )->context( Plugin::POST_TYPE, (int) $id );
			$this->t->same( 'no_mapping', $service->inspect( $ctx )['state'], '旧レイアウトの文脈' );
			$this->t->same( 'ready', $service->inspect( ( new SchemaInspector() )->context( Plugin::POST_TYPE, 0, array() ) )['state'], '通常の文脈は止まらない' );
		} );
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function opts( array $extra = array() ): array {
		return $extra + array(
			'extra_meta' => array(
				Poc::MARK       => '1',
				self::TEST_MARK => '1',
			),
		);
	}

	private function cleanup(): void {
		foreach ( array_unique( $this->created ) as $id ) {
			if ( '1' === (string) get_post_meta( $id, self::TEST_MARK, true ) && in_array( get_post_status( $id ), array( 'draft', 'private' ), true ) ) {
				wp_delete_post( $id, true );
			}
		}
		$this->t->test( 'I-99', '試験用の投稿を削除した', function () {
			foreach ( $this->created as $id ) {
				$this->t->true( null === get_post( $id ), "#{$id} が残っている" );
			}
		} );
	}
}
