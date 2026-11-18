<?php
/**
 * 施工事例（works）の下書きだけに ACF 値を保存する。
 *
 * 手順：
 *   1) CaseDocument を検証 → テンプレートの論理名で値ツリーを作る
 *   2) この文脈の承認済みマッピング（MappingService::gate）で、現在のACFの名前・形へ変換
 *   3) 現在のACF定義と照合して変換（ここで1つでも問題があれば何も書かない）
 *   4) 投稿の状態（works / draft / 権限 / 編集ロック / 基準版）・ACF定義・マッピングの版を、書き込み直前にもう一度確認
 *   5) スナップショット → 書き込み → キャッシュを消して読み戻し → 一致を確認
 *   6) 途中失敗・不一致ならスナップショットへ戻す
 *
 * このクラスは公開・予約・非公開・削除を行わない。post_status を変更しない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Draft;

use WpAiCase\Acf\FieldAdapterRegistry;
use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Domain\CaseDocumentValidator;
use WpAiCase\Mapping\MappingService;
use WpAiCase\Mapping\MappingTranslator;
use WpAiCase\Mapping\ShipWorksTemplateV11;
use WpAiCase\Plugin;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class DraftWriter {

	public const AUDIT_META = '_wp_ai_case_last_write';
	public const POC_MARK   = '_wp_ai_case_poc';

	private SchemaInspector $inspector;
	private FieldAdapterRegistry $registry;
	private ShipWorksTemplateV11 $template;
	private MappingService $mappings;

	public function __construct( ?SchemaInspector $inspector = null, ?FieldAdapterRegistry $registry = null, ?ShipWorksTemplateV11 $template = null, ?MappingService $mappings = null ) {
		$this->inspector = $inspector ?? new SchemaInspector();
		$this->registry  = $registry ?? new FieldAdapterRegistry();
		$this->template  = $template ?? new ShipWorksTemplateV11();
		$this->mappings  = $mappings ?? new MappingService( $this->inspector );
	}

	/**
	 * CaseDocument から新しい下書きを作って保存する。
	 *
	 * @param array<string,mixed> $doc
	 * @param array<string,mixed> $options hero（FV強制）, terms（taxonomy => term_id[]）, extra_meta（印など）,
	 *                                     mapping（検証中の候補で合成下書きに保存するときだけ）
	 * @return array<string,mixed>
	 */
	public function create_from_document( array $doc, int $user_id, array $options = array() ): array {
		( new CaseDocumentValidator() )->validate( $doc );

		$type_obj = get_post_type_object( Plugin::POST_TYPE );
		if ( ! $type_obj || ! user_can( $user_id, $type_obj->cap->edit_posts ) ) {
			throw new CaseError( CaseError::POST_STATE, '施工事例の下書きを作る権限がありません' );
		}

		// 投稿を作る前に、新規投稿の条件（予定のターム）で照合を済ませる（失敗したら何も作らない）。
		$terms   = array_map( static fn( $ids ) => array_map( 'intval', (array) $ids ), (array) ( $options['terms'] ?? array() ) );
		$context = $this->inspector->context( Plugin::POST_TYPE, 0, $terms );
		$mapping = $this->select_mapping( $context, $options );
		$fields  = $this->translate( $doc, $mapping, $options, $built );
		$groups  = $this->inspector->groups( $context );
		$this->registry->prepare_top_level( SchemaInspector::top_level_index( $groups ), $fields, array() );
		$this->check_image_coverage( $built['image_ids'] );

		$post_id = wp_insert_post(
			array(
				'post_type'   => Plugin::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => wp_slash( $built['post']['post_title'] ),
				'post_author' => $user_id,
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			throw new CaseError( CaseError::WRITE_FAILED, '下書きを作成できませんでした' );
		}
		foreach ( (array) ( $options['extra_meta'] ?? array() ) as $key => $value ) {
			update_post_meta( $post_id, (string) $key, $value );
		}
		foreach ( $terms as $taxonomy => $ids ) {
			if ( $ids ) {
				wp_set_object_terms( $post_id, $ids, (string) $taxonomy, false );
			}
		}
		Snapshot::flush( $post_id );
		$post = get_post( $post_id );

		$result = $this->write_fields(
			$post_id,
			$fields,
			$user_id,
			(string) $post->post_modified_gmt,
			$mapping,
			$options + array( 'expected_images' => $built['image_ids'] )
		);
		return $result + array(
			'created' => true,
			'hero'    => $built['hero'],
		);
	}

	/**
	 * この機能で保存した既存の下書きを、修正後の CaseDocument に合わせて更新する。
	 *
	 * - 前回の保存から変わった部分（論理名）だけを書く。変わっていない部分・この機能が書かない部分は触らない。
	 * - 書く部分を、保存後に人が編集画面で変えていたら、何も書かずに止める（人の編集を上書きしない）。
	 * - 書く前に、修正後の文書全体を現在のACF定義で検証し、写真の集合も確かめる。
	 *
	 * @param array<string,mixed> $doc     修正後の CaseDocument
	 * @param array<string,mixed> $state   DraftState::load()['state']（ジョブ開始時に読んだもの）
	 * @param array<string,mixed> $mapping この保存を許可する承認済みの版
	 * @return array<string,mixed> changed_slots（書いた論理名）を含む
	 */
	public function revise_document( int $post_id, array $doc, int $user_id, string $base_modified_gmt, array $mapping, array $state ): array {
		( new CaseDocumentValidator() )->validate( $doc );
		$this->guard_post( $post_id, $user_id, $base_modified_gmt );

		$built   = $this->template->build( $doc );
		$prints  = DraftState::built_prints( $built );
		$changed = array();
		foreach ( $prints as $slot => $print ) {
			if ( ( $state['built'][ $slot ] ?? null ) !== $print ) {
				$changed[] = $slot;
			}
		}
		if ( ! $changed ) {
			return array(
				'post_id'       => $post_id,
				'changed_slots' => array(),
				'readback'      => 'not_needed',
			);
		}

		$context = $this->inspector->context( Plugin::POST_TYPE, $post_id );
		$groups  = $this->inspector->groups( $context );
		$edited  = DraftState::edited_slots( $post_id, $state, $mapping, $groups, $changed );
		if ( $edited ) {
			throw new CaseError(
				CaseError::EDITED_BY_HAND,
				'修正する部分が、保存後に編集画面で変更されています',
				array(
					'post_id' => $post_id,
					'fields'  => DraftState::labels( $edited ),
				)
			);
		}

		// 修正後の文書全体が現在の定義で保存できる形か、写真の集合が合っているかを先に確かめる。
		$index = SchemaInspector::top_level_index( $groups );
		$scope = array();
		foreach ( $index as $field ) {
			$scope[ $field['key'] ] = get_field( $field['key'], $post_id, false );
		}
		$translator = new MappingTranslator();
		$bindings   = (array) $mapping['document']['bindings'];
		$this->registry->prepare_top_level( $index, $translator->translate( $built['fields'], ShipWorksTemplateV11::logical_schema(), $bindings ), $scope );
		$this->check_image_coverage( $built['image_ids'] );

		// 変わった部分だけの値ツリー。値がなくなった部分は、空にできる型だけ空で書く（入力を消した操作）。
		$logical = ShipWorksTemplateV11::logical_schema();
		$subset  = array();
		foreach ( $changed as $slot ) {
			if ( DraftState::TITLE_SLOT === $slot ) {
				continue;
			}
			if ( array_key_exists( $slot, $built['fields'] ) ) {
				$subset[ $slot ] = $built['fields'][ $slot ];
				continue;
			}
			$empty = match ( (string) ( $logical[ $slot ]['type'] ?? '' ) ) {
				'text', 'textarea', 'wysiwyg' => '',
				'flexible_content', 'repeater', 'relationship' => array(),
				default => null, // グループ（トップの写真の各型など）は条件分岐で隠れるので触らない
			};
			if ( null !== $empty ) {
				$subset[ $slot ] = $empty;
			}
		}
		$fields  = $subset ? $translator->translate( $subset, $logical, $bindings ) : array();
		$options = in_array( DraftState::TITLE_SLOT, $changed, true ) ? array( 'post_title' => (string) $built['post']['post_title'] ) : array();
		$result  = $this->write_fields( $post_id, $fields, $user_id, $base_modified_gmt, $mapping, $options );
		return $result + array( 'changed_slots' => $changed );
	}

	/**
	 * CaseDocument を、マッピングを通して現在のACFの名前の値ツリーにする（保存はしない）。
	 *
	 * @param array<string,mixed>      $doc
	 * @param array<string,mixed>      $mapping
	 * @param array<string,mixed>      $options
	 * @param array<string,mixed>|null $built   テンプレートの出力（参照で返す）
	 * @return array<string,mixed>
	 */
	public function translate( array $doc, array $mapping, array $options = array(), ?array &$built = null ): array {
		$built = $this->template->build( $doc, $options );
		return ( new MappingTranslator() )->translate( $built['fields'], ShipWorksTemplateV11::logical_schema(), (array) $mapping['document']['bindings'] );
	}

	/**
	 * 既存の下書きへ、指定したフィールドだけを書く（指定のないフィールドは保持）。
	 *
	 * @param array<string,mixed> $fields  現在のACFの name => 値
	 * @param array<string,mixed> $mapping この保存を許可するマッピングの版（開始時に取得したもの）
	 * @param array<string,mixed> $options expected_images, post_title（タイトルも変えるとき）
	 * @return array<string,mixed>
	 */
	public function write_fields( int $post_id, array $fields, int $user_id, string $base_modified_gmt, array $mapping, array $options = array() ): array {
		$this->guard_post( $post_id, $user_id, $base_modified_gmt );
		$this->guard_poc_only( $post_id, $mapping );

		$context = $this->inspector->context( Plugin::POST_TYPE, $post_id );
		$groups  = $this->inspector->groups( $context );
		$hash    = $this->inspector->schema_hash( $groups );
		if ( ! hash_equals( (string) $mapping['schema_sha256'], $hash ) ) {
			throw new CaseError( CaseError::SCHEMA_CHANGED, '開始時と保存直前でACFの適用定義が違います', array( 'post_id' => $post_id ) );
		}
		if ( ! hash_equals( (string) $mapping['context_hash'], $this->inspector->context_hash( $context, $groups ) ) ) {
			throw new CaseError( CaseError::MAPPING_NOT_READY, 'この下書きの適用条件（タクソノミー等）がマッピングと違います', array( 'post_id' => $post_id ) );
		}

		$index = SchemaInspector::top_level_index( $groups );
		$scope = array();
		foreach ( $index as $field ) {
			$scope[ $field['key'] ] = get_field( $field['key'], $post_id, false );
		}
		$prepared = $this->registry->prepare_top_level( $index, $fields, $scope );
		if ( isset( $options['expected_images'] ) ) {
			$this->check_image_coverage( $options['expected_images'] );
		}

		$snapshot = Snapshot::capture( $post_id );
		Snapshot::persist( $snapshot );

		// 書き込み直前の再確認（TOCTOU 対策）：投稿の状態・ACF定義・マッピングの版。
		$this->guard_post( $post_id, $user_id, $base_modified_gmt );
		$this->mappings->assert_still_current( $mapping, $this->inspector->groups( $this->inspector->context( Plugin::POST_TYPE, $post_id ) ) );

		$written = 0;
		try {
			// タイトルは先に変える（保存時の他のフックが走った後に、ACFの値を書くため）。
			if ( isset( $options['post_title'] ) ) {
				$updated = wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => wp_slash( (string) $options['post_title'] ),
					),
					true
				);
				if ( is_wp_error( $updated ) || ! $updated ) {
					throw new CaseError( CaseError::WRITE_FAILED, 'タイトルを保存できませんでした' );
				}
			}
			foreach ( $prepared as $key => $item ) {
				/**
				 * 試験用：N件書いた時点で失敗させる（0 なら無効）。
				 */
				$fail_after = (int) apply_filters( 'wp_ai_case_test_inject_write_failure', 0 );
				if ( $fail_after > 0 && $written >= $fail_after ) {
					throw new CaseError( CaseError::WRITE_FAILED, '試験用に書き込みを途中で失敗させました' );
				}
				update_field( $key, $item['raw'], $post_id );
				++$written;
			}
			Snapshot::flush( $post_id );
			$mismatches = $this->readback( $post_id, $prepared );
			// 権限によってはタイトルの記号が実体参照で保存されるので、文字に戻して比べる。
			if ( isset( $options['post_title'] ) && html_entity_decode( (string) get_post( $post_id )->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== html_entity_decode( (string) $options['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) {
				$mismatches[] = 'post_title';
			}
			if ( $mismatches ) {
				throw new CaseError( CaseError::READBACK_MISMATCH, '保存後の読み戻しが一致しません', array( 'fields' => $mismatches ) );
			}
		} catch ( \Throwable $e ) {
			$restored = Snapshot::restore( $snapshot );
			$error    = $e instanceof CaseError ? $e : new CaseError( CaseError::WRITE_FAILED, '保存中にエラーが発生しました' );
			throw new CaseError(
				$error->reason,
				$error->getMessage(),
				$error->context + array(
					'post_id'  => $post_id,
					'written'  => $written,
					'restored' => $restored,
				)
			);
		}

		$post  = get_post( $post_id );
		$audit = array(
			'at'                => gmdate( 'c' ),
			'user_id'           => $user_id,
			'template'          => ShipWorksTemplateV11::TEMPLATE_ID . '@' . ShipWorksTemplateV11::TEMPLATE_VERSION,
			'mapping_id'        => (string) $mapping['mapping_id'],
			'mapping_version'   => (int) $mapping['mapping_version'],
			'mapping_status'    => (string) $mapping['status'],
			'schema_sha256'     => $hash,
			'context_sha256'    => (string) $mapping['context_hash'],
			'fields'            => array_map( static fn( $i ) => (string) $i['field']['name'], array_values( $prepared ) ),
			'readback'          => 'ok',
			'post_modified_gmt' => (string) $post->post_modified_gmt,
		);
		update_post_meta( $post_id, self::AUDIT_META, wp_slash( $audit ) );

		return array(
			'post_id'         => $post_id,
			'post_status'     => (string) $post->post_status,
			'schema_sha256'   => $hash,
			'context_sha256'  => $audit['context_sha256'],
			'mapping_id'      => $audit['mapping_id'],
			'mapping_version' => $audit['mapping_version'],
			'groups'          => array_map( static fn( $g ) => (string) $g['title'], $groups ),
			'fields_written'  => $audit['fields'],
			'readback'        => 'ok',
			'attachments'     => $this->registry->referenced_attachments(),
			'related_posts'   => $this->registry->referenced_posts(),
		);
	}

	/**
	 * 承認済みのマッピングを選ぶ。検証中の候補を使えるのは、合成データの下書き（印付き）だけ。
	 *
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 * @param array<string,mixed>                                                $options
	 * @return array<string,mixed>
	 */
	private function select_mapping( array $context, array $options ): array {
		if ( empty( $options['mapping'] ) ) {
			return $this->mappings->gate( $context );
		}
		$mapping = $options['mapping'];
		if ( 'approved_active' !== $mapping['status'] && '1' !== (string) ( $options['extra_meta'][ self::POC_MARK ] ?? '' ) ) {
			throw new CaseError( CaseError::MAPPING_NOT_READY, '未承認のマッピングで保存できるのは、検証用の合成下書きだけです' );
		}
		$cur = $this->mappings->current( $context );
		if ( $cur['context_hash'] !== $mapping['context_hash'] || $cur['schema_sha256'] !== $mapping['schema_sha256'] ) {
			throw new CaseError( CaseError::MAPPING_NOT_READY, 'マッピングの版が、この投稿の現在の定義と一致しません' );
		}
		return $mapping;
	}

	/**
	 * 未承認の版での書き込みは、合成データの下書きだけに限る。
	 *
	 * @param array<string,mixed> $mapping
	 */
	private function guard_poc_only( int $post_id, array $mapping ): void {
		if ( 'approved_active' !== $mapping['status'] && '1' !== (string) get_post_meta( $post_id, self::POC_MARK, true ) ) {
			throw new CaseError( CaseError::MAPPING_NOT_READY, '未承認のマッピングで保存できるのは、検証用の合成下書きだけです', array( 'post_id' => $post_id ) );
		}
	}

	/**
	 * 対象が「施工事例の下書き」で、権限があり、他人が編集中でなく、基準版から変わっていないこと。
	 */
	public function guard_post( int $post_id, int $user_id, string $base_modified_gmt ): void {
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( ! $post || Plugin::POST_TYPE !== $post->post_type ) {
			throw new CaseError( CaseError::POST_STATE, '施工事例の投稿ではありません', array( 'post_id' => $post_id ) );
		}
		if ( 'draft' !== $post->post_status ) {
			throw new CaseError( CaseError::POST_STATE, '下書き以外の投稿には保存できません', array( 'post_id' => $post_id, 'post_status' => $post->post_status ) );
		}
		if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
			throw new CaseError( CaseError::POST_STATE, 'この下書きを編集する権限がありません', array( 'post_id' => $post_id ) );
		}
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$lock = get_post_meta( $post_id, '_edit_lock', true );
		if ( $lock ) {
			[ $time, $locker ] = array_pad( explode( ':', (string) $lock ), 2, 0 );
			$window            = (int) apply_filters( 'wp_check_post_lock_window', 150 );
			if ( (int) $locker && (int) $locker !== $user_id && ( time() - (int) $time ) < $window ) {
				throw new CaseError( CaseError::POST_STATE, '他のユーザーが編集中です', array( 'post_id' => $post_id ) );
			}
		}
		if ( ! hash_equals( $base_modified_gmt, (string) $post->post_modified_gmt ) ) {
			throw new CaseError( CaseError::STALE_BASE, '下書きが開始時から更新されています', array( 'post_id' => $post_id ) );
		}
	}

	/**
	 * @param array<string,array{field:array<string,mixed>,raw:mixed}> $prepared
	 * @return string[] 一致しなかったフィールド名
	 */
	private function readback( int $post_id, array $prepared ): array {
		$bad = array();
		foreach ( $prepared as $key => $item ) {
			$expected = $this->registry->normalize( $item['field'], $item['raw'] );
			$actual   = $this->registry->normalize( $item['field'], get_field( $key, $post_id, false ) );
			if ( SchemaInspector::stable_json( $expected ) !== SchemaInspector::stable_json( $actual ) ) {
				$bad[] = (string) $item['field']['name'];
			}
		}
		return $bad;
	}

	/**
	 * 記事に含める写真が全部使われ、それ以外の画像を使っていないこと。
	 *
	 * @param int[] $expected
	 */
	private function check_image_coverage( array $expected ): void {
		$used    = $this->registry->referenced_attachments();
		$missing = array_values( array_diff( $expected, $used ) );
		$extra   = array_values( array_diff( $used, $expected ) );
		if ( $missing || $extra ) {
			throw new CaseError(
				CaseError::INVALID_VALUE,
				'写真の集合が一致しません',
				array(
					'missing' => $missing,
					'extra'   => $extra,
				)
			);
		}
	}
}
