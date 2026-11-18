<?php
/**
 * 施工事例（works）の下書きだけに ACF 値を保存する。
 *
 * 手順：
 *   1) CaseDocument を検証 → 既知マッピングで値ツリーを作る
 *   2) 現在のACF定義と照合して変換（ここで1つでも問題があれば何も書かない）
 *   3) 投稿の状態（works / draft / 権限 / 編集ロック / 基準版）とスキーマを、書き込み直前にもう一度確認
 *   4) スナップショット → 書き込み → キャッシュを消して読み戻し → 一致を確認
 *   5) 途中失敗・不一致ならスナップショットへ戻す
 *
 * このクラスは公開・予約・非公開・削除を行わない。post_status を変更しない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Draft;

use WpAiCase\Acf\FieldAdapterRegistry;
use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Domain\CaseDocumentValidator;
use WpAiCase\Mapping\ShipWorksTemplateV11;
use WpAiCase\Plugin;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class DraftWriter {

	public const AUDIT_META = '_wp_ai_case_last_write';

	private SchemaInspector $inspector;
	private FieldAdapterRegistry $registry;
	private ShipWorksTemplateV11 $template;

	public function __construct( ?SchemaInspector $inspector = null, ?FieldAdapterRegistry $registry = null, ?ShipWorksTemplateV11 $template = null ) {
		$this->inspector = $inspector ?? new SchemaInspector();
		$this->registry  = $registry ?? new FieldAdapterRegistry();
		$this->template  = $template ?? new ShipWorksTemplateV11();
	}

	/**
	 * CaseDocument から新しい下書きを作って保存する。
	 *
	 * @param array<string,mixed> $doc
	 * @param array<string,mixed> $options hero（FV強制）, extra_meta（印など）, groups_override（試験用の合成定義）
	 * @return array<string,mixed>
	 */
	public function create_from_document( array $doc, int $user_id, array $options = array() ): array {
		( new CaseDocumentValidator() )->validate( $doc );
		$built = $this->template->build( $doc, $options );

		$type_obj = get_post_type_object( Plugin::POST_TYPE );
		if ( ! $type_obj || ! user_can( $user_id, $type_obj->cap->edit_posts ) ) {
			throw new CaseError( CaseError::POST_STATE, '施工事例の下書きを作る権限がありません' );
		}

		// 投稿を作る前に、新規投稿の条件で照合を済ませる（失敗したら何も作らない）。
		$context  = $this->inspector->context( Plugin::POST_TYPE, 0, array() );
		$groups   = $options['groups_override'] ?? $this->inspector->groups( $context );
		$pre_hash = $this->inspector->schema_hash( $groups );
		$this->registry->prepare_top_level( SchemaInspector::top_level_index( $groups ), $built['fields'], array() );
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
		Snapshot::flush( $post_id );
		$post = get_post( $post_id );

		$result = $this->write_fields(
			$post_id,
			$built['fields'],
			$user_id,
			(string) $post->post_modified_gmt,
			$pre_hash,
			$options + array( 'expected_images' => $built['image_ids'] )
		);
		return $result + array(
			'created' => true,
			'hero'    => $built['hero'],
		);
	}

	/**
	 * 既存の下書きへ、指定したフィールドだけを書く（指定のないフィールドは保持）。
	 *
	 * @param array<string,mixed> $fields  name => 値
	 * @param array<string,mixed> $options groups_override, expected_images
	 * @return array<string,mixed>
	 */
	public function write_fields( int $post_id, array $fields, int $user_id, string $base_modified_gmt, ?string $expected_schema_hash = null, array $options = array() ): array {
		$this->guard_post( $post_id, $user_id, $base_modified_gmt );

		$context = $this->inspector->context( Plugin::POST_TYPE, $post_id );
		$groups  = $options['groups_override'] ?? $this->inspector->groups( $context );
		$hash    = $this->inspector->schema_hash( $groups );
		if ( null !== $expected_schema_hash && ! hash_equals( $expected_schema_hash, $hash ) ) {
			throw new CaseError( CaseError::SCHEMA_CHANGED, '開始時と保存直前でACFの適用定義が違います', array( 'post_id' => $post_id ) );
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

		// 書き込み直前の再確認（TOCTOU 対策）。
		$this->guard_post( $post_id, $user_id, $base_modified_gmt );

		$written = 0;
		try {
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
			'mapping'           => 'builtin-c1a',
			'schema_sha256'     => $hash,
			'context_sha256'    => $this->inspector->context_hash( $context ),
			'fields'            => array_map( static fn( $i ) => (string) $i['field']['name'], array_values( $prepared ) ),
			'readback'          => 'ok',
			'post_modified_gmt' => (string) $post->post_modified_gmt,
		);
		update_post_meta( $post_id, self::AUDIT_META, wp_slash( $audit ) );

		return array(
			'post_id'             => $post_id,
			'post_status'         => (string) $post->post_status,
			'schema_sha256'       => $hash,
			'context_sha256'      => $audit['context_sha256'],
			'groups'              => array_map( static fn( $g ) => (string) $g['title'], $groups ),
			'fields_written'      => $audit['fields'],
			'readback'            => 'ok',
			'attachments'         => $this->registry->referenced_attachments(),
			'related_posts'       => $this->registry->referenced_posts(),
		);
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
