<?php
/**
 * マッピングのライフサイクル（C1-B）。
 *
 *   初回/変更検知 → 提案（ルールで結び付け）→ ローカル構造検証 → ステージングで保存・読み戻し
 *   → 実表示の確認 → 管理者承認 → 承認済み（approved_active）で生成・保存を再開
 *
 * - 承認済みの版の schema hash と、現在の定義の hash が一致するときだけ保存を許す（gate）。
 * - 一致しなければ、その文脈の保存だけを止める（blocked_drift）。他の文脈・公開中の表示は止めない。
 * - 「互換」とみなして自動で続けるのは、マッピングが使うフィールドが一切変わっていない場合だけ。
 * - 状態の移り変わりはすべてイベントとして記録する（mapping_transition 契約）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Mapping;

use WpAiCase\Acf\FieldAdapterRegistry;
use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Plugin;
use WpAiCase\Support\CaseError;
use WpAiCase\Support\Installer;

defined( 'ABSPATH' ) || exit;

final class MappingService {

	/** 許可する状態遷移（'' は新しい版の作成）。 */
	public const TRANSITIONS = array(
		''                           => array( 'proposed', 'proposed_rebuild', 'blocked_unsupported', 'approved_active' ),
		'proposed'                   => array( 'validated_local', 'blocked_unsupported', 'retired' ),
		'proposed_rebuild'           => array( 'validated_local', 'blocked_unsupported', 'retired' ),
		'validated_local'            => array( 'pending_staging_validation', 'retired' ),
		'pending_staging_validation' => array( 'validated_staging', 'retired' ),
		'validated_staging'          => array( 'approved_active', 'retired' ),
		'approved_active'            => array( 'blocked_drift', 'retired' ),
		'blocked_drift'              => array( 'retired' ),
		'blocked_unsupported'        => array( 'retired' ),
		'retired'                    => array(),
	);

	private const OPEN = array( 'proposed', 'proposed_rebuild', 'validated_local', 'pending_staging_validation', 'validated_staging', 'blocked_unsupported' );

	private SchemaInspector $inspector;
	private MappingRepository $repo;
	private ShipWorksTemplateV11 $template;
	private BindingResolver $resolver;

	public function __construct( ?SchemaInspector $inspector = null, ?MappingRepository $repo = null, ?ShipWorksTemplateV11 $template = null ) {
		$this->inspector = $inspector ?? new SchemaInspector();
		$this->repo      = $repo ?? new MappingRepository();
		$this->template  = $template ?? new ShipWorksTemplateV11();
		$this->resolver  = new BindingResolver();
	}

	public function repository(): MappingRepository {
		return $this->repo;
	}

	/**
	 * 現在の定義（適用グループ・hash）。
	 *
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 * @return array<string,mixed>
	 */
	public function current( array $context ): array {
		$groups = $this->inspector->groups( $context );
		return array(
			'context'       => $context,
			'groups'        => $groups,
			'schema_sha256' => $this->inspector->schema_hash( $groups ),
			'context_hash'  => $this->inspector->context_hash( $context, $groups ),
		);
	}

	/**
	 * 文脈の状態を調べる。承認済みの版と定義がずれていれば、ここで止める（または互換なら自動で続ける）。
	 *
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 * @return array<string,mixed> state: ready / blocked_drift / no_mapping / pending / blocked_unsupported
	 */
	public function inspect( array $context, int $actor = 0, bool $with_display = false ): array {
		$cur    = $this->current( $context );
		$active = $this->first( $this->repo->by_context( $cur['context_hash'], 'approved_active' ) );

		if ( $active && $active['schema_sha256'] === $cur['schema_sha256'] ) {
			return $this->summary( 'ready', $active, $cur, null, array(), $with_display );
		}

		if ( $active ) {
			$res   = $this->resolver->resolve( ShipWorksTemplateV11::logical_schema(), $cur['groups'], $this->by_slot( $active ) );
			$class = $this->classify( $active, $res, $cur['groups'] );
			if ( 'compatible' === $class && ! $this->local_check( $res['bindings'], $cur['groups'] ) ) {
				$doc                                       = $active['document'];
				$doc['validation']['tested_schema_sha256'] = $cur['schema_sha256'];
				$doc['validation']['tested_context_hash']  = $cur['context_hash'];
				$doc['validation']['evidence_ref']         = 'compatible_update_from_v' . $active['mapping_version'];
				$doc['notes']                              = 'マッピングが使うフィールドは変わっていない（使っていない部分だけの変更）ため、承認済みの結び付けを引き継いだ。表示の再確認を推奨。';
				$new = $this->create_version( $active['mapping_id'], $cur, $res['bindings'], 'approved_active', $actor, 'compatible_update', 'マッピングが使わない部分だけの変更を検出（互換）', $doc, $active['schema_sha256'] );
				$this->transition( $active, 'retired', 'retire', "互換の更新 v{$new['mapping_version']} に置き換え", $actor, $cur );
				return $this->summary( 'ready', $new, $cur, 'compatible', array(), $with_display );
			}
			$affected = $this->affected_drafts( $cur['context_hash'] );
			$reason   = '承認済みの版と現在のACF定義が一致しません（分類: ' . $class . '）';
			$blocked  = $this->transition( $active, 'blocked_drift', 'schema_drift', $reason, $actor, $cur, array(), array(), $affected );
			return $this->summary( 'blocked_drift', $blocked, $cur, $class, $res['problems'], $with_display );
		}

		$latest = $this->first( $this->repo->by_context( $cur['context_hash'] ) );
		if ( ! $latest ) {
			return $this->summary( 'no_mapping', null, $cur, null, array(), $with_display );
		}
		$state = match ( $latest['status'] ) {
			'blocked_unsupported' => 'blocked_unsupported',
			'blocked_drift', 'retired' => 'blocked_drift',
			default => 'pending',
		};
		return $this->summary( $state, $latest, $cur, null, (array) ( $latest['document']['problems'] ?? array() ), $with_display );
	}

	/**
	 * 保存してよいか。承認済みで、定義が一致する版を返す。そうでなければ AI-010 で止める。
	 *
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 * @return array<string,mixed> mapping 行
	 */
	public function gate( array $context ): array {
		$s = $this->inspect( $context );
		if ( 'ready' !== $s['state'] ) {
			$messages = array(
				'no_mapping'          => 'この施工事例の構造に対する承認済みのマッピングがありません。管理者の設定が必要です',
				'blocked_drift'       => 'ACFの構造変更を検出しました。管理者がマッピングを確認中です',
				'blocked_unsupported' => 'ACFの構造に未対応の部分があります。追加開発または追加入力が必要です',
				'pending'             => 'マッピングの検証・承認待ちです',
			);
			throw new CaseError(
				CaseError::MAPPING_NOT_READY,
				$messages[ $s['state'] ] ?? 'マッピングが利用できません',
				array(
					'state'          => $s['state'],
					'classification' => $s['classification'],
					'mapping_id'     => $s['mapping']['mapping_id'] ?? null,
					'mapping_version' => $s['mapping']['mapping_version'] ?? null,
				)
			);
		}
		return $s['row'];
	}

	/**
	 * 保存直前の再確認：開始時の版が、今も同じ状態・同じ定義のままか。
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $groups 現在の定義
	 */
	public function assert_still_current( array $row, array $groups ): void {
		$fresh = $this->repo->get( (string) $row['mapping_id'], (int) $row['mapping_version'] );
		if ( ! $fresh || $fresh['status'] !== $row['status'] ) {
			throw new CaseError( CaseError::MAPPING_NOT_READY, '生成中にマッピングの状態が変わりました', array( 'mapping_id' => $row['mapping_id'], 'mapping_version' => (int) $row['mapping_version'] ) );
		}
		if ( ! hash_equals( (string) $row['schema_sha256'], $this->inspector->schema_hash( $groups ) ) ) {
			throw new CaseError( CaseError::SCHEMA_CHANGED, '開始時と保存直前でACFの適用定義が違います' );
		}
	}

	/**
	 * 現在の定義に合わせて、マッピングの新しい版を提案する（初回・作り直し）。ローカル構造検証まで行う。
	 *
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 * @return array<string,mixed>
	 */
	public function propose( array $context, int $actor ): array {
		$this->require_admin( $actor );
		$s = $this->inspect( $context, $actor );
		if ( 'ready' === $s['state'] ) {
			throw new CaseError( CaseError::INVALID_TRANSITION, '承認済みのマッピングが現在の定義に一致しています（作り直しは不要）' );
		}
		$cur     = $this->current( $context );
		$lineage = $this->repo->by_context( $cur['context_hash'] );

		// まだ承認されていない候補は置き換える。
		foreach ( $lineage as $row ) {
			if ( in_array( $row['status'], self::OPEN, true ) ) {
				$this->transition( $row, 'retired', 'retire', '新しい提案に置き換え', $actor, $cur );
			}
		}

		$hint = null;
		foreach ( $lineage as $row ) {
			if ( ! empty( $row['document']['approval'] ) ) {
				$hint = $row;
				break;
			}
		}
		$hint     ??= $this->repo->latest_approved_for_template( ShipWorksTemplateV11::TEMPLATE_ID );
		$mapping_id = $lineage ? (string) $lineage[0]['mapping_id'] : wp_generate_uuid4();
		$rebuild    = (bool) array_filter( $lineage, static fn( $r ) => ! empty( $r['document']['approval'] ) );
		$res        = $this->resolver->resolve( ShipWorksTemplateV11::logical_schema(), $cur['groups'], $hint ? $this->by_slot( $hint ) : array() );
		$class      = ( $hint && $hint['context_hash'] === $cur['context_hash'] ) ? $this->classify( $hint, $res, $cur['groups'] ) : 'initial';

		if ( $res['problems'] ) {
			$row = $this->create_version( $mapping_id, $cur, $res['bindings'], 'blocked_unsupported', $actor, 'unsupported', $this->problem_text( $res['problems'] ), array( 'problems' => $res['problems'] ) );
			return array( 'state' => 'blocked_unsupported', 'row' => $row, 'classification' => $class, 'problems' => $res['problems'] );
		}

		$row    = $this->create_version( $mapping_id, $cur, $res['bindings'], $rebuild ? 'proposed_rebuild' : 'proposed', $actor, $rebuild ? 'rebuild_proposed' : 'initial_proposal', $rebuild ? "現在の定義に合わせて全体を再構築（分類: {$class}）" : '初回のマッピング提案（ルールによる結び付け）' );
		$errors = $this->local_check( $res['bindings'], $cur['groups'] );
		if ( $errors ) {
			$row = $this->transition( $row, 'blocked_unsupported', 'unsupported', '構造検証に失敗: ' . implode( ' / ', array_column( $errors, 'message' ) ), $actor, $cur, array( 'problems' => $errors ) );
			return array( 'state' => 'blocked_unsupported', 'row' => $row, 'classification' => $class, 'problems' => $errors );
		}
		$doc                                = $row['document'];
		$doc['validation']['fixture_passed'] = true;
		$row = $this->transition( $row, 'validated_local', 'local_validation', '架空データで FV 3パターンの構造検証に合格', $actor, $cur, array( 'validation' => $doc['validation'] ) );
		return array( 'state' => 'validated_local', 'row' => $row, 'classification' => $class, 'problems' => array() );
	}

	/**
	 * ステージングでの保存・読み戻し（と自動のDOM確認）に合格したことを記録する。
	 *
	 * @param array<string,mixed> $row
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 */
	public function record_staging( array $row, array $context, int $actor, string $evidence ): array {
		$this->require_admin( $actor );
		$cur = $this->require_same_definition( $row, $context );
		$doc = $row['document'];
		$doc['validation'] = array_merge(
			$doc['validation'],
			array(
				'staging_write_read_passed' => true,
				'tested_schema_sha256'      => $cur['schema_sha256'],
				'tested_context_hash'       => $cur['context_hash'],
				'tested_theme_fingerprint'  => SchemaInspector::theme_fingerprint(),
				'evidence_ref'              => $evidence,
				'verified_at'               => gmdate( 'Y-m-d\TH:i:s\Z' ),
			)
		);
		return $this->transition( $row, 'pending_staging_validation', 'staging_validation_requested', 'ステージングで保存・読み戻しに合格。実表示の確認待ち', $actor, $cur, array( 'validation' => $doc['validation'] ), array( $evidence ) );
	}

	/**
	 * 実表示（テーマでの見た目）を管理者が確認したことを記録する。
	 *
	 * @param array<string,mixed> $row
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 */
	public function confirm_display( array $row, array $context, int $actor, string $evidence ): array {
		$this->require_admin( $actor );
		$cur = $this->require_same_definition( $row, $context );
		if ( ( $row['document']['validation']['tested_theme_fingerprint'] ?? '' ) !== SchemaInspector::theme_fingerprint() ) {
			throw new CaseError( CaseError::INVALID_TRANSITION, '保存・読み戻しの検証後にテーマが変わりました。検証をやり直してください' );
		}
		$doc                                         = $row['document'];
		$doc['validation']['staging_display_passed'] = true;
		$doc['validation']['evidence_ref']           = trim( $doc['validation']['evidence_ref'] . ' ; ' . $evidence, ' ;' );
		return $this->transition( $row, 'validated_staging', 'staging_validation_passed', 'ステージングで実表示を確認', $actor, $cur, array( 'validation' => $doc['validation'] ), array( $evidence ) );
	}

	/**
	 * 承認済みの版で、テーマが変わった後に実表示を確認し直したことを記録する（状態は承認済みのまま）。
	 * ACF の定義が同じでも表示テンプレートは別に変わりうるので、表示の確認状態だけを更新する。
	 *
	 * @param array<string,mixed> $row
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 */
	public function reconfirm_display( array $row, array $context, int $actor, string $evidence ): array {
		$this->require_admin( $actor );
		if ( 'approved_active' !== $row['status'] ) {
			throw new CaseError( CaseError::INVALID_TRANSITION, '表示の再確認は、承認済みの版だけで行えます' );
		}
		$cur                                           = $this->require_same_definition( $row, $context );
		$doc                                           = $row['document'];
		$doc['validation']['staging_display_passed']   = true;
		$doc['validation']['tested_theme_fingerprint'] = SchemaInspector::theme_fingerprint();
		$doc['validation']['verified_at']              = gmdate( 'Y-m-d\TH:i:s\Z' );
		$doc['validation']['evidence_ref']             = trim( (string) $doc['validation']['evidence_ref'] . ' ; display_recheck: ' . $evidence, ' ;' );
		$updated = $this->repo->compare_and_set( $row, 'approved_active', $doc );
		$this->event( $updated, 'approved_active', 'approved_active', 'staging_validation_passed', 'テーマ変更後に実表示を再確認', $actor, (string) $row['schema_sha256'], $cur['schema_sha256'], $cur['context_hash'], array( $evidence ), array() );
		return $updated;
	}

	/**
	 * 管理者の承認。承認後はこの版で生成・保存を再開する。同じ文脈の古い版は退役させる。
	 *
	 * @param array<string,mixed> $row
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 */
	public function approve( array $row, array $context, int $actor, string $reason ): array {
		$this->require_admin( $actor );
		$cur = $this->require_same_definition( $row, $context );
		$v   = $row['document']['validation'] ?? array();
		if ( empty( $v['fixture_passed'] ) || empty( $v['staging_write_read_passed'] ) || empty( $v['staging_display_passed'] ) ) {
			throw new CaseError( CaseError::INVALID_TRANSITION, '構造検証・ステージング保存・実表示の確認がそろっていません' );
		}
		if ( '' === trim( $reason ) ) {
			throw new CaseError( CaseError::INVALID_TRANSITION, '承認の理由を入力してください' );
		}
		$approval = array(
			'approved_by_user_id' => $actor,
			'approved_at'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'approval_reason'     => $reason,
		);
		foreach ( $this->repo->by_context( $cur['context_hash'] ) as $other ) {
			if ( (int) $other['mapping_version'] !== (int) $row['mapping_version'] && 'retired' !== $other['status'] && in_array( 'retired', self::TRANSITIONS[ $other['status'] ], true ) ) {
				$this->transition( $other, 'retired', 'retire', "v{$row['mapping_version']} の承認により退役", $actor, $cur );
			}
		}
		return $this->transition( $row, 'approved_active', 'approval', $reason, $actor, $cur, array( 'approval' => $approval ) );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public function retire( array $row, int $actor, string $reason ): array {
		$this->require_admin( $actor );
		return $this->transition( $row, 'retired', 'retire', $reason, $actor, null );
	}

	/**
	 * 以前に承認された版へ戻す。定義が当時と同じ場合だけ、その結び付けを新しい版として検証済みにし、承認待ちにする。
	 *
	 * @param array<string,mixed> $old
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 */
	public function rollback( array $old, array $context, int $actor, string $reason ): array {
		$this->require_admin( $actor );
		if ( empty( $old['document']['approval'] ) ) {
			throw new CaseError( CaseError::INVALID_TRANSITION, '承認されたことのない版には戻せません' );
		}
		$cur = $this->require_same_definition( $old, $context );
		if ( $this->first( $this->repo->by_context( $cur['context_hash'], 'approved_active' ) ) ) {
			throw new CaseError( CaseError::INVALID_TRANSITION, '承認済みの版が有効です。先に退役させてください' );
		}
		foreach ( $this->repo->by_context( $cur['context_hash'] ) as $row ) {
			if ( in_array( $row['status'], self::OPEN, true ) ) {
				$this->transition( $row, 'retired', 'retire', "v{$old['mapping_version']} への巻き戻しのため", $actor, $cur );
			}
		}
		$from = "v{$old['mapping_version']}";
		$row  = $this->create_version( (string) $old['mapping_id'], $cur, $old['document']['bindings'], 'proposed_rebuild', $actor, 'rebuild_proposed', "{$from} への巻き戻し: {$reason}" );
		$doc  = $row['document'];
		$doc['validation']                 = $old['document']['validation'];
		$doc['validation']['evidence_ref'] = "rollback_of_{$from}: " . (string) $old['document']['validation']['evidence_ref'];
		$row = $this->transition( $row, 'validated_local', 'local_validation', "{$from} の検証結果を引き継ぎ（定義が同一）", $actor, $cur, array( 'validation' => $doc['validation'] ) );
		$row = $this->transition( $row, 'pending_staging_validation', 'staging_validation_requested', "{$from} の検証結果を引き継ぎ", $actor, $cur );
		return $this->transition( $row, 'validated_staging', 'staging_validation_passed', "{$from} の検証結果を引き継ぎ。承認待ち", $actor, $cur );
	}

	/**
	 * 架空データで、マッピングを通した値が現在の定義に保存できる形かを確かめる（DBには書かない）。
	 *
	 * @param array<int,array<string,mixed>> $bindings
	 * @param array<int,array<string,mixed>> $groups
	 * @return array<int,array<string,string>> 問題の一覧（空なら合格）
	 */
	public function local_check( array $bindings, array $groups ): array {
		$errors     = array();
		$translator = new MappingTranslator();
		$registry   = new FieldAdapterRegistry( false );
		foreach ( array( 'single', 'gallery', 'before_after' ) as $hero ) {
			try {
				$built = $this->template->build( SampleDocument::get(), array( 'hero' => $hero ) );
				$tree  = $translator->translate( $built['fields'], ShipWorksTemplateV11::logical_schema(), $bindings );
				$registry->prepare_top_level( SchemaInspector::top_level_index( $groups ), $tree, array() );
			} catch ( CaseError $e ) {
				$errors[] = array(
					'slot'    => (string) ( $e->context['slot'] ?? $e->context['path'] ?? '' ),
					'kind'    => $e->reason,
					'message' => "FV={$hero}: " . $e->getMessage(),
				);
			}
		}
		return $errors;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,array<string,mixed>>
	 */
	public function by_slot( array $row ): array {
		$out = array();
		foreach ( (array) ( $row['document']['bindings'] ?? array() ) as $binding ) {
			$out[ (string) $binding['semantic_slot'] ] = $binding;
		}
		return $out;
	}

	/**
	 * 前の承認版と比べた変更の分類。
	 *
	 * @param array<string,mixed>                                                            $prev
	 * @param array{bindings:array<int,array<string,mixed>>,problems:array<int,array<string,string>>} $res
	 * @param array<int,array<string,mixed>>                                                 $groups
	 */
	public function classify( array $prev, array $res, array $groups ): string {
		if ( $res['problems'] ) {
			$kinds = array_unique( array_column( $res['problems'], 'kind' ) );
			return array( 'ambiguous' ) === array_values( $kinds ) ? 'uncertain' : 'unsupported';
		}
		$old = $this->by_slot( $prev );
		$new = array();
		foreach ( $res['bindings'] as $binding ) {
			$new[ (string) $binding['semantic_slot'] ] = $binding;
		}
		if ( array_diff_key( $old, $new ) || array_diff_key( $new, $old ) ) {
			return 'root_supported';
		}
		$identical = true;
		$key_only  = true;
		foreach ( $new as $slot => $b ) {
			$p      = $old[ $slot ];
			$struct = $p['field_type'] === $b['field_type'] && $p['path'] === $b['path'] && $p['transform_id'] === $b['transform_id'] && ( $p['layout_name'] ?? '' ) === ( $b['layout_name'] ?? '' );
			$keys   = $p['field_key'] === $b['field_key'] && ( $p['layout_key'] ?? '' ) === ( $b['layout_key'] ?? '' );
			if ( ! $struct ) {
				$identical = false;
				$key_only  = false;
			} elseif ( ! $keys ) {
				$identical = false;
			}
		}
		if ( $identical ) {
			return $this->bound_hash( $res['bindings'], $groups ) === (string) $prev['bound_sha256'] ? 'compatible' : 'root_supported';
		}
		return $key_only ? 'key_only' : 'root_supported';
	}

	/**
	 * マッピングが使うノード（フィールド・レイアウト）の定義だけの hash。
	 *
	 * @param array<int,array<string,mixed>> $bindings
	 * @param array<int,array<string,mixed>> $groups
	 */
	public function bound_hash( array $bindings, array $groups ): string {
		$index = array();
		$walk  = static function ( array $fields ) use ( &$walk, &$index ): void {
			foreach ( $fields as $field ) {
				$index[ (string) $field['key'] ] = $field;
				$walk( $field['sub_fields'] ?? array() );
				foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
					$walk( $layout['sub_fields'] ?? array() );
				}
			}
		};
		foreach ( $groups as $group ) {
			$walk( $group['fields'] ?? array() );
		}
		$parts = array();
		foreach ( $bindings as $b ) {
			$field = $index[ $b['field_key'] ] ?? array();
			if ( 'layout' === $b['field_type'] ) {
				$layout = array();
				foreach ( (array) ( $field['layouts'] ?? array() ) as $l ) {
					if ( (string) $l['name'] === (string) $b['layout_name'] ) {
						$layout = array( 'key' => $l['key'], 'name' => $l['name'], 'min' => $l['min'] ?? '', 'max' => $l['max'] ?? '' );
					}
				}
				$parts[ $b['semantic_slot'] ] = $layout;
			} else {
				$parts[ $b['semantic_slot'] ] = SchemaInspector::canonical_attrs( $field );
			}
		}
		ksort( $parts );
		return hash( 'sha256', SchemaInspector::stable_json( $parts ) );
	}

	/**
	 * 文脈が同じ下書き（構造変更で影響を受けるもの）。最大200件。
	 *
	 * @return int[]
	 */
	public function affected_drafts( string $context_hash ): array {
		$ids = get_posts(
			array(
				'post_type'      => Plugin::POST_TYPE,
				'post_status'    => 'draft',
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$ctx = $this->inspector->context( Plugin::POST_TYPE, (int) $id );
			if ( $this->inspector->context_hash( $ctx, $this->inspector->groups( $ctx ) ) === $context_hash ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed>            $cur
	 * @param array<int,array<string,mixed>> $bindings
	 * @param array<string,mixed>            $extra 文書に追加する項目（validation / approval / notes / problems など）
	 */
	private function create_version( string $mapping_id, array $cur, array $bindings, string $status, int $actor, string $event_type, string $reason, array $extra = array(), ?string $schema_before = null ): array {
		$document = array(
			'mapping_id'       => $mapping_id,
			'mapping_version'  => $this->repo->next_version( $mapping_id ),
			'site_scope_id'    => Installer::site_scope_id(),
			'post_type'        => Plugin::POST_TYPE,
			'context_selector' => $this->inspector->context_selector( $cur['context'], $cur['context_hash'] ),
			'context_hash'     => $cur['context_hash'],
			'schema_sha256'    => $cur['schema_sha256'],
			'template_ref'     => array(
				'template_id'      => ShipWorksTemplateV11::TEMPLATE_ID,
				'template_version' => ShipWorksTemplateV11::TEMPLATE_VERSION,
			),
			'mapping_status'   => $status,
			'bindings'         => array_values( $bindings ),
			'validation'       => array(
				'fixture_passed'            => false,
				'staging_write_read_passed' => false,
				'staging_display_passed'    => false,
				'tested_schema_sha256'      => $cur['schema_sha256'],
				'tested_context_hash'       => $cur['context_hash'],
				'evidence_ref'              => 'none',
				'verified_at'               => gmdate( 'Y-m-d\TH:i:s\Z' ),
			),
		);
		foreach ( $extra as $key => $value ) {
			if ( in_array( $key, array( 'validation', 'approval', 'notes', 'problems' ), true ) ) {
				$document[ $key ] = $value;
			}
		}
		$row = $this->repo->insert( $document, $this->bound_hash( $bindings, $cur['groups'] ), $this->inspector->canonical( $cur['groups'] ), $actor );
		$this->event( $row, null, $status, $event_type, $reason, $actor, $schema_before, $cur['schema_sha256'], $cur['context_hash'], array(), array() );
		return $row;
	}

	/**
	 * @param array<string,mixed>      $row
	 * @param array<string,mixed>|null $cur
	 * @param array<string,mixed>      $changes 文書へ反映する変更
	 * @param string[]                 $evidence
	 * @param int[]                    $affected
	 */
	private function transition( array $row, string $to, string $event_type, string $reason, int $actor, ?array $cur, array $changes = array(), array $evidence = array(), array $affected = array() ): array {
		$from = (string) $row['status'];
		if ( ! in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true ) ) {
			throw new CaseError( CaseError::INVALID_TRANSITION, "マッピングの状態を {$from} から {$to} へは変えられません", array( 'mapping_id' => $row['mapping_id'], 'mapping_version' => (int) $row['mapping_version'] ) );
		}
		$doc = $row['document'];
		foreach ( $changes as $key => $value ) {
			$doc[ $key ] = $value;
		}
		$doc['mapping_status'] = $to;
		$updated               = $this->repo->compare_and_set( $row, $to, $doc );
		$this->event( $updated, $from, $to, $event_type, $reason, $actor, (string) $row['schema_sha256'], $cur['schema_sha256'] ?? (string) $row['schema_sha256'], $cur['context_hash'] ?? (string) $row['context_hash'], $evidence, $affected );
		return $updated;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param string[]            $evidence
	 * @param int[]               $affected
	 */
	private function event( array $row, ?string $from, string $to, string $type, string $reason, int $actor, ?string $before, string $after, string $context_hash, array $evidence, array $affected ): void {
		$this->repo->add_event(
			array(
				'event_id'          => wp_generate_uuid4(),
				'mapping_id'        => (string) $row['mapping_id'],
				'from_status'       => $from,
				'to_status'         => $to,
				'event_type'        => $type,
				'schema_before'     => $before,
				'schema_after'      => $after,
				'context_hash'      => $context_hash,
				'affected_post_ids' => array_values( array_unique( $affected ) ),
				'reason'            => $reason,
				'actor_user_id'     => $actor > 0 ? $actor : null,
				'evidence_refs'     => array_values( $evidence ),
				'occurred_at'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
			),
			(int) $row['mapping_version']
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 * @return array<string,mixed>
	 */
	private function require_same_definition( array $row, array $context ): array {
		$cur = $this->current( $context );
		if ( $cur['context_hash'] !== $row['context_hash'] || $cur['schema_sha256'] !== $row['schema_sha256'] ) {
			throw new CaseError( CaseError::SCHEMA_CHANGED, 'この版を作ったときと現在のACF定義が違います。提案からやり直してください', array( 'mapping_id' => $row['mapping_id'], 'mapping_version' => (int) $row['mapping_version'] ) );
		}
		return $cur;
	}

	private function require_admin( int $actor ): void {
		if ( $actor < 1 || ! user_can( $actor, 'manage_options' ) ) {
			throw new CaseError( CaseError::FORBIDDEN, 'マッピングの操作は管理者だけが行えます' );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,mixed>|null
	 */
	private function first( array $rows ): ?array {
		return $rows[0] ?? null;
	}

	/**
	 * @param array<int,array<string,string>> $problems
	 */
	private function problem_text( array $problems ): string {
		$lines = array();
		foreach ( array_slice( $problems, 0, 10 ) as $p ) {
			$lines[] = "{$p['slot']}: {$p['message']}";
		}
		return '未対応の変更: ' . implode( ' / ', $lines ) . ( count( $problems ) > 10 ? ' ほか' : '' );
	}

	/**
	 * @param array<string,mixed>|null       $row
	 * @param array<string,mixed>            $cur
	 * @param array<int,array<string,mixed>> $problems
	 * @return array<string,mixed>
	 */
	private function summary( string $state, ?array $row, array $cur, ?string $class, array $problems, bool $with_display ): array {
		$display = null;
		if ( $with_display && $row ) {
			$tested  = (string) ( $row['document']['validation']['tested_theme_fingerprint'] ?? '' );
			$display = empty( $row['document']['validation']['staging_display_passed'] ) ? 'unverified' : ( SchemaInspector::theme_fingerprint() === $tested ? 'verified' : 'recheck_required' );
		}
		return array(
			'state'          => $state,
			'schema_sha256'  => $cur['schema_sha256'],
			'context_hash'   => $cur['context_hash'],
			'groups'         => array_map( static fn( $g ) => (string) $g['title'], $cur['groups'] ),
			'mapping'        => $row ? array(
				'mapping_id'      => $row['mapping_id'],
				'mapping_version' => (int) $row['mapping_version'],
				'status'          => $row['status'],
			) : null,
			'row'            => $row,
			'classification' => $class,
			'problems'       => $problems,
			'display'        => $display,
		);
	}
}
