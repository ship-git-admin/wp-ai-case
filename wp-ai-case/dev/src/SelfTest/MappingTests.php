<?php
/**
 * C1-B：マッピングの結び付け・分類・変換と、状態遷移（DBに版とイベントを書くが、最後に必ず消す）。
 *
 * ACF の実定義は変えない。共通テンプレートの実定義から作った複製（AcfVariants::group）を
 * 差し替え可能な inspector に渡して、構造変更を模擬する。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev\SelfTest;

use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Dev\AcfVariants;
use WpAiCase\Mapping\BindingResolver;
use WpAiCase\Mapping\MappingService;
use WpAiCase\Mapping\MappingTranslator;
use WpAiCase\Mapping\SampleDocument;
use WpAiCase\Mapping\ShipWorksTemplateV11;
use WpAiCase\Plugin;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class MappingTests {

	private const SELFTEST_GROUP = 'group_wpaicsyn_selftest';

	/** @var array<string,array<int,array<string,mixed>>> */
	private array $variants = array();

	public function __construct( private Tester $t, private int $admin ) {
	}

	public function run(): void {
		// 施工事例に適用される他のグループ（SEO・監修者など）はそのまま使い、共通テンプレートだけを複製に差し替える。
		$inspector = new SchemaInspector();
		$others    = array_values(
			array_filter(
				$inspector->groups( $inspector->context( Plugin::POST_TYPE, 0, array() ) ),
				static fn( $g ) => '施工事例_ver1.1' !== $g['title']
			)
		);
		foreach ( AcfVariants::VARIANTS as $v ) {
			$group = AcfVariants::group( $v );
			if ( ! $group ) {
				$this->t->test( 'M-00', '前提：共通テンプレートの実定義', fn() => throw new SkipTest( '施工事例_ver1.1 が見つかりません' ) );
				return;
			}
			$group['key']         = self::SELFTEST_GROUP;
			$this->variants[ $v ] = array_merge( $others, array( $group ) );
		}
		$this->unit();
		$this->lifecycle();
	}

	/**
	 * 結び付け・分類・変換（DBに書かない）。
	 */
	private function unit(): void {
		$t        = $this->t;
		$resolver = new BindingResolver();
		$service  = new MappingService();
		$logical  = ShipWorksTemplateV11::logical_schema();
		$prev     = function ( string $v ) use ( $resolver, $service, $logical ): array {
			$res = $resolver->resolve( $logical, $this->variants[ $v ] );
			return array(
				'document'     => array( 'bindings' => $res['bindings'] ),
				'bound_sha256' => $service->bound_hash( $res['bindings'], $this->variants[ $v ] ),
			);
		};

		$t->test( 'MU-01', 'C1-ACF01 共通テンプレートの構造に、論理構造がすべて結び付く（問題なし）', function () use ( $resolver, $logical ) {
			$res = $resolver->resolve( $logical, $this->variants['A'] );
			$this->t->same( array(), $res['problems'], '問題' );
			$this->t->true( count( $res['bindings'] ) > 100, '結び付けの数: ' . count( $res['bindings'] ) );
			$this->t->same( array( 'identity', 'layout.identity' ), array_values( array_unique( array_column( $res['bindings'], 'transform_id' ) ) ), '変換は不要' );
		} );
		$t->test( 'MU-02', 'C1-ACF05 根本変更（グループ→リピーター、セレクト→ラジオ）を「対応可の根本変更」と分類し、登録済みの変換で結び付ける', function () use ( $resolver, $service, $logical, $prev ) {
			$a   = $prev( 'A' );
			$res = $resolver->resolve( $logical, $this->variants['B'], $service->by_slot( $a ) );
			$this->t->same( array(), $res['problems'], '問題' );
			$this->t->same( 'root_supported', $service->classify( $a, $res, $this->variants['B'] ), '分類' );
			$by = array_column( $res['bindings'], 'transform_id', 'semantic_slot' );
			$this->t->same( 'container.group_to_single_row', $by['Format/@EntryData_Label_Detail/EntryData_Group_Detail'] ?? null, 'グループ→リピーター' );
			$this->t->same( 'choice.identity', $by['FV_Layout'] ?? null, 'セレクト→ラジオ' );
		} );
		$t->test( 'MU-03', 'C1-ACF06 未対応の型への変更は「未対応」として理由つきで止める', function () use ( $resolver, $service, $logical, $prev ) {
			$a   = $prev( 'A' );
			$res = $resolver->resolve( $logical, $this->variants['C'], $service->by_slot( $a ) );
			$this->t->same( 'unsupported', $service->classify( $a, $res, $this->variants['C'] ), '分類' );
			$this->t->same( 'Outline/@Outline_Label_Area/Outline_Area', $res['problems'][0]['slot'] ?? null, '問題の場所' );
			$this->t->same( 'unsupported_type', $res['problems'][0]['kind'] ?? null, '問題の種類' );
		} );
		$t->test( 'MU-04', 'C1-ACF03 同じ構造でキーだけ違う（サイト差分）を検出し、前の版のキーを使わない', function () use ( $resolver, $service, $logical, $prev ) {
			$a   = $prev( 'A' );
			$res = $resolver->resolve( $logical, $this->variants['D'], $service->by_slot( $a ) );
			$this->t->same( 'key_only', $service->classify( $a, $res, $this->variants['D'] ), '分類' );
			// 複製した共通テンプレート部分（キーが架空のもの）だけを比べる。SEO・監修者のグループは変えていない。
			$synthetic = static fn( array $bs ) => array_values( array_filter( array_column( $bs, 'field_key' ), static fn( $k ) => str_starts_with( $k, 'field_wpaicsyn' ) ) );
			$old       = $synthetic( $a['document']['bindings'] );
			$new       = $synthetic( $res['bindings'] );
			$this->t->true( count( $new ) > 100, '複製部分の結び付け' );
			$this->t->same( array(), array_values( array_intersect( $old, $new ) ), '前の版のキーが残っている' );
		} );
		$t->test( 'MU-05', 'マッピングが使わない部分だけの変更は「互換」に分類する（自動で続けてよい範囲）', function () use ( $resolver, $service, $logical, $prev ) {
			$a   = $prev( 'A' );
			$res = $resolver->resolve( $logical, $this->variants['E'], $service->by_slot( $a ) );
			$this->t->same( 'compatible', $service->classify( $a, $res, $this->variants['E'] ), '分類' );
		} );
		$t->test( 'MU-06', '根本変更後の結び付けで、架空データが新しい構造に保存できる形になる（グループが1行のリピーターに）', function () use ( $resolver, $service, $logical ) {
			$res  = $resolver->resolve( $logical, $this->variants['B'] );
			$this->t->same( array(), $service->local_check( $res['bindings'], $this->variants['B'] ), '構造検証' );
			$built = ( new ShipWorksTemplateV11() )->build( SampleDocument::get(), array( 'hero' => 'before_after' ) );
			$tree  = ( new MappingTranslator() )->translate( $built['fields'], $logical, $res['bindings'] );
			$detail = $tree['Format'][0]['EntryData_Group_Detail'] ?? null;
			$this->t->true( is_array( $detail ) && array_is_list( $detail ) && 1 === count( $detail ), 'リピーター1行になっていない' );
			$this->t->same( 'layout03', $tree['FV_Layout'], 'FV' );
		} );
		$t->test( 'MU-07', '別の階層への移動・レイアウトの削除・必須フィールドの追加は推測で埋めず、問題に挙げる', function () use ( $resolver, $logical ) {
			$g = $this->variants['A'];
			$c = count( $g ) - 1; // 複製グループは最後
			// EntryData_CatchTxt をグループの中へ移動
			foreach ( $g[ $c ]['fields'] as $i => $f ) {
				if ( 'EntryData_CatchTxt' === $f['name'] ) {
					unset( $g[ $c ]['fields'][ $i ] );
					$g[ $c ]['fields'][] = array( 'key' => 'field_wpaicsyn_moved', 'name' => 'Concept', 'type' => 'group', 'sub_fields' => array( $f ) );
				}
				if ( 'Outline' === $f['name'] ) {
					foreach ( $f['layouts'] as $lk => $l ) {
						if ( 'Outline_Label_Cost' === $l['name'] ) {
							unset( $g[ $c ]['fields'][ $i ]['layouts'][ $lk ] );
						}
					}
				}
				if ( 'EntryData_Staff' === $f['name'] ) {
					$g[ $c ]['fields'][ $i ]['sub_fields'][] = array( 'key' => 'field_wpaicsyn_req', 'name' => 'Staff_Role', 'type' => 'text', 'required' => 1 );
				}
			}
			$g[ $c ]['fields'] = array_values( $g[ $c ]['fields'] );
			$kinds          = array_column( $resolver->resolve( $logical, $g )['problems'], 'kind', 'slot' );
			$this->t->same( 'missing', $kinds['EntryData_CatchTxt'] ?? null, '移動' );
			$this->t->same( 'missing', $kinds['Outline/@Outline_Label_Cost'] ?? null, 'レイアウト削除' );
			$this->t->same( 'new_required', $kinds['EntryData_Staff/Staff_Role'] ?? null, '必須追加' );
		} );
	}

	/**
	 * 状態遷移：初回→検証→承認→構造変更で停止→再構築→承認→再開、互換、未対応、巻き戻し。
	 */
	private function lifecycle(): void {
		$t         = $this->t;
		$inspector = new FakeInspector();
		$service   = new MappingService( $inspector );
		$context   = $inspector->context( Plugin::POST_TYPE, 0, array() );
		$set       = function ( string $v ) use ( $inspector ): void {
			$inspector->fake_groups = $this->variants[ $v ];
		};
		$set( 'A' );
		$ctx_hash = $inspector->context_hash( $context, $inspector->fake_groups );
		$service->repository()->delete_context( $ctx_hash );
		$state = array();

		try {
			$t->test( 'ML-01', '承認済みのマッピングがない文脈では保存を止める（AI-010）', function () use ( $service, $context ) {
				$this->t->same( 'no_mapping', $service->inspect( $context )['state'], '状態' );
				$e = $this->t->throws_case( CaseError::MAPPING_NOT_READY, fn() => $service->gate( $context ) );
				$this->t->same( 'AI-010', $e->ai_code(), 'コード' );
			} );
			$t->test( 'ML-02', '管理者以外は提案・承認できない', function () use ( $service, $context ) {
				$this->t->throws_case( CaseError::FORBIDDEN, fn() => $service->propose( $context, 0 ) );
			} );
			$t->test( 'ML-03', '初回提案→構造検証（validated_local）。ステージング確認前は承認できない', function () use ( $service, $context, &$state ) {
				$r = $service->propose( $context, $this->admin );
				$this->t->same( 'validated_local', $r['row']['status'], '状態' );
				$this->t->same( 'initial', $r['classification'], '分類' );
				$this->t->throws_case( CaseError::INVALID_TRANSITION, fn() => $service->approve( $r['row'], $context, $this->admin, '試験' ) );
				$state['v1'] = $r['row'];
			} );
			$t->test( 'ML-04', 'ステージングの保存確認→実表示の確認→管理者承認で approved_active になり、保存を許可する', function () use ( $service, $context, &$state ) {
				$row = $service->record_staging( $state['v1'], $context, $this->admin, 'selftest' );
				$this->t->same( 'pending_staging_validation', $row['status'], '保存確認後' );
				$row = $service->confirm_display( $row, $context, $this->admin, 'selftest（自動。見た目の確認は別途）' );
				$this->t->same( 'validated_staging', $row['status'], '表示確認後' );
				$this->t->throws_case( CaseError::INVALID_TRANSITION, fn() => $service->approve( $row, $context, $this->admin, '' ) );
				$row = $service->approve( $row, $context, $this->admin, 'selftest の承認' );
				$this->t->same( 'approved_active', $row['status'], '承認後' );
				$this->t->same( $row['mapping_version'], $service->gate( $context )['mapping_version'], 'gate' );
				$state['v1'] = $row;
			} );
			$t->test( 'ML-05', 'C1-ACF02 定義が同じなら、そのまま再利用（提案は不要と拒否）', function () use ( $service, $context ) {
				$this->t->same( 'ready', $service->inspect( $context )['state'], '状態' );
				$this->t->throws_case( CaseError::INVALID_TRANSITION, fn() => $service->propose( $context, $this->admin ) );
			} );
			$t->test( 'ML-06', '互換の変更（マッピングが使わない部分だけ）は、構造検証を通して自動で新しい版に引き継ぐ', function () use ( $service, $context, $set, &$state ) {
				$set( 'E' );
				$s = $service->inspect( $context, $this->admin );
				$this->t->same( 'ready', $s['state'], '状態' );
				$this->t->same( 'compatible', $s['classification'], '分類' );
				$this->t->true( $s['mapping']['mapping_version'] > $state['v1']['mapping_version'], '新しい版' );
				$this->t->same( 'retired', $service->repository()->get( $state['v1']['mapping_id'], $state['v1']['mapping_version'] )['status'], '旧版' );
				$state['v2'] = $s['row'];
			} );
			$t->test( 'ML-07', 'C1-ACF05 根本変更を検知→その文脈の保存を停止（blocked_drift、イベント記録）', function () use ( $service, $context, $set, &$state ) {
				$set( 'B' );
				$s = $service->inspect( $context, $this->admin );
				$this->t->same( 'blocked_drift', $s['state'], '状態' );
				$this->t->same( 'root_supported', $s['classification'], '分類' );
				$this->t->throws_case( CaseError::MAPPING_NOT_READY, fn() => $service->gate( $context ) );
				$events = $service->repository()->events( $state['v2']['mapping_id'] );
				$last   = end( $events );
				$this->t->same( 'schema_drift', $last['event_type'], 'イベント' );
				$this->t->true( $last['schema_before'] !== $last['schema_after'], 'hash の前後' );
			} );
			$t->test( 'ML-08', 'C1-ACF05 全体を再構築→検証→承認すると、新しい版で保存を再開し、古い版は退役', function () use ( $service, $context, &$state ) {
				$r = $service->propose( $context, $this->admin );
				$this->t->same( 'validated_local', $r['row']['status'], '状態' );
				$this->t->same( 'root_supported', $r['classification'], '分類' );
				$by = array_column( $r['row']['document']['bindings'], 'transform_id', 'semantic_slot' );
				$this->t->same( 'container.group_to_single_row', $by['Format/@EntryData_Label_Detail/EntryData_Group_Detail'] ?? null, '変換' );
				$row = $service->record_staging( $r['row'], $context, $this->admin, 'selftest' );
				$row = $service->confirm_display( $row, $context, $this->admin, 'selftest' );
				$row = $service->approve( $row, $context, $this->admin, 'selftest：根本変更の再承認' );
				$this->t->same( $row['mapping_version'], $service->gate( $context )['mapping_version'], 'gate' );
				$this->t->same( 'retired', $service->repository()->get( $state['v2']['mapping_id'], $state['v2']['mapping_version'] )['status'], '旧版' );
				$state['v3'] = $row;
			} );
			$t->test( 'ML-09', 'C1-ACF06 未対応の変更は blocked_unsupported と理由を記録し、保存は止めたまま', function () use ( $service, $context, $set ) {
				$set( 'C' );
				$this->t->same( 'blocked_drift', $service->inspect( $context, $this->admin )['state'], '検知' );
				$r = $service->propose( $context, $this->admin );
				$this->t->same( 'blocked_unsupported', $r['row']['status'], '状態' );
				$this->t->same( 'unsupported_type', $r['problems'][0]['kind'] ?? null, '理由' );
				$this->t->true( ! empty( $r['row']['document']['problems'] ), '理由を文書に記録' );
				$this->t->throws_case( CaseError::MAPPING_NOT_READY, fn() => $service->gate( $context ) );
			} );
			$t->test( 'ML-10', 'C1-ACF10 定義が戻ったら、以前に承認された版へ巻き戻せる（承認は改めて必要）', function () use ( $service, $context, $set, &$state ) {
				$set( 'B' );
				$this->t->same( 'blocked_unsupported', $service->inspect( $context )['state'], '巻き戻し前' );
				$row = $service->rollback( $state['v3'], $context, $this->admin, 'selftest：構造を元に戻した' );
				$this->t->same( 'validated_staging', $row['status'], '巻き戻し後' );
				$this->t->throws_case( CaseError::MAPPING_NOT_READY, fn() => $service->gate( $context ) );
				$row = $service->approve( $row, $context, $this->admin, 'selftest：巻き戻しの承認' );
				$this->t->same( 'ready', $service->inspect( $context )['state'], '再開' );
				$events = $service->repository()->events( $row['mapping_id'] );
				$actors = array_unique( array_filter( array_column( $events, 'actor_user_id' ) ) );
				$this->t->same( array( $this->admin ), array_values( $actors ), '操作者の記録' );
				$this->t->true( count( $events ) >= 15, 'イベント数: ' . count( $events ) );
			} );
			$t->test( 'ML-11', 'C1-ACF08 定義が同じでもテーマが変わったら、保存は可能・表示は再確認が必要と区別する', function () use ( $service, $context ) {
				$this->t->same( 'verified', $service->inspect( $context, 0, true )['display'], '変更前' );
				$fake = static fn( $h ) => $h . '-changed';
				add_filter( 'wp_ai_case_theme_fingerprint', $fake );
				try {
					$s = $service->inspect( $context, 0, true );
				} finally {
					remove_filter( 'wp_ai_case_theme_fingerprint', $fake );
				}
				$this->t->same( 'ready', $s['state'], '保存' );
				$this->t->same( 'recheck_required', $s['display'], '表示' );
			} );
			$t->test( 'ML-12', '同じ版を同時に動かしても、片方だけが成功する（compare-and-swap）', function () use ( $service, $context ) {
				$row = $service->gate( $context );
				$doc = $row['document'];
				$service->repository()->compare_and_set( $row, 'retired', $doc );
				$this->t->throws_case( CaseError::STALE_BASE, fn() => $service->repository()->compare_and_set( $row, 'retired', $doc ) );
			} );
			$t->test( 'ML-13', '記録した文書が契約（acf_mapping / mapping_transition）の必須項目を持つ', function () use ( $service, &$state ) {
				$row = $service->repository()->get( $state['v3']['mapping_id'], $state['v3']['mapping_version'] );
				foreach ( array( 'mapping_id', 'mapping_version', 'site_scope_id', 'post_type', 'context_selector', 'context_hash', 'schema_sha256', 'mapping_status', 'bindings', 'validation', 'approval' ) as $key ) {
					$this->t->true( array_key_exists( $key, $row['document'] ), "acf_mapping.{$key}" );
				}
				$event = $service->repository()->events( $row['mapping_id'] )[0];
				foreach ( array( 'event_id', 'mapping_id', 'from_status', 'to_status', 'event_type', 'schema_before', 'schema_after', 'context_hash', 'affected_post_ids', 'reason', 'actor_user_id', 'evidence_refs', 'occurred_at' ) as $key ) {
					$this->t->true( array_key_exists( $key, $event ), "mapping_transition.{$key}" );
				}
			} );
		} finally {
			$deleted = $service->repository()->delete_context( $ctx_hash );
			$t->test( 'ML-99', '試験で作ったマッピングの版とイベントを削除した', function () use ( $service, $ctx_hash, $deleted ) {
				$this->t->true( $deleted > 0, '削除件数 0' );
				$this->t->same( array(), $service->repository()->by_context( $ctx_hash ), '残っている' );
			} );
		}
	}
}
