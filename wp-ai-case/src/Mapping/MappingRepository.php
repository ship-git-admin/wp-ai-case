<?php
/**
 * マッピング（版ごとに不変の bindings）と状態遷移イベントの保存。
 *
 * 状態の更新は compare-and-swap（元の状態が一致したときだけ更新）で行い、
 * 同時に2つの操作が同じ版を動かしても片方だけが成功するようにする。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Mapping;

use WpAiCase\Support\CaseError;
use WpAiCase\Support\Installer;

defined( 'ABSPATH' ) || exit;

final class MappingRepository {

	/**
	 * @param array<string,mixed> $document acf_mapping 契約の文書
	 * @param array<string,mixed> $snapshot 正規化したACF定義（サイト内だけに保存）
	 * @return array<string,mixed> 保存した行
	 */
	public function insert( array $document, string $bound_sha256, array $snapshot, int $user_id ): array {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Installer::mappings_table(),
			array(
				'mapping_id'       => $document['mapping_id'],
				'mapping_version'  => (int) $document['mapping_version'],
				'context_hash'     => $document['context_hash'],
				'schema_sha256'    => $document['schema_sha256'],
				'bound_sha256'     => $bound_sha256,
				'status'           => $document['mapping_status'],
				'template_id'      => $document['template_ref']['template_id'] ?? '',
				'template_version' => $document['template_ref']['template_version'] ?? '',
				'document'         => (string) wp_json_encode( $document, JSON_UNESCAPED_UNICODE ),
				'schema_snapshot'  => base64_encode( (string) gzcompress( (string) wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE ), 6 ) ),
				'created_by'       => $user_id,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( ! $ok ) {
			throw new CaseError( CaseError::WRITE_FAILED, 'マッピングを保存できませんでした' );
		}
		return $this->get( (string) $document['mapping_id'], (int) $document['mapping_version'] );
	}

	/**
	 * 状態と文書を、元の状態が一致するときだけ更新する。
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $document
	 */
	public function compare_and_set( array $row, string $to_status, array $document ): array {
		global $wpdb;
		$table   = Installer::mappings_table();
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, document = %s, updated_at = %s WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$to_status,
				(string) wp_json_encode( $document, JSON_UNESCAPED_UNICODE ),
				current_time( 'mysql', true ),
				(int) $row['id'],
				(string) $row['status']
			)
		);
		if ( 1 !== (int) $updated ) {
			throw new CaseError( CaseError::STALE_BASE, 'マッピングの状態が他の操作で変わりました', array( 'mapping_id' => $row['mapping_id'], 'mapping_version' => (int) $row['mapping_version'] ) );
		}
		return $this->get( (string) $row['mapping_id'], (int) $row['mapping_version'] );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( string $mapping_id, int $version ): ?array {
		global $wpdb;
		$table = Installer::mappings_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE mapping_id = %s AND mapping_version = %d", $mapping_id, $version ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function by_context( string $context_hash, ?string $status = null ): array {
		global $wpdb;
		$table = Installer::mappings_table();
		if ( null === $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE context_hash = %s ORDER BY mapping_version DESC, id DESC", $context_hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE context_hash = %s AND status = %s ORDER BY mapping_version DESC, id DESC", $context_hash, $status ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * 同じテンプレートで最後に承認されていた版（別の文脈を含む）。初回提案のヒントに使う。
	 *
	 * @return array<string,mixed>|null
	 */
	public function latest_approved_for_template( string $template_id ): ?array {
		global $wpdb;
		$table = Installer::mappings_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE template_id = %s AND status IN ('approved_active','blocked_drift','retired') AND document LIKE %s ORDER BY updated_at DESC, id DESC LIMIT 1", $template_id, '%"approval"%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;
		$table = Installer::mappings_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY context_hash, mapping_version DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	public function next_version( string $mapping_id ): int {
		global $wpdb;
		$table = Installer::mappings_table();
		return 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(mapping_version) FROM {$table} WHERE mapping_id = %s", $mapping_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param array<string,mixed> $event mapping_transition 契約の文書
	 */
	public function add_event( array $event, int $version ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Installer::events_table(),
			array(
				'event_id'        => $event['event_id'],
				'mapping_id'      => $event['mapping_id'],
				'mapping_version' => $version,
				'event_type'      => $event['event_type'],
				'from_status'     => $event['from_status'],
				'to_status'       => $event['to_status'],
				'document'        => (string) wp_json_encode( $event, JSON_UNESCAPED_UNICODE ),
				'occurred_at'     => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function events( string $mapping_id ): array {
		global $wpdb;
		$table = Installer::events_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT mapping_version, document FROM {$table} WHERE mapping_id = %s ORDER BY id ASC", $mapping_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$doc                    = json_decode( (string) $row['document'], true );
			$doc['mapping_version'] = (int) $row['mapping_version'];
			$out[]                  = $doc;
		}
		return $out;
	}

	/**
	 * 試験用：文脈ごとに行とイベントを削除する（開発ツールからのみ使う）。
	 */
	public function delete_context( string $context_hash ): int {
		global $wpdb;
		$table  = Installer::mappings_table();
		$events = Installer::events_table();
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT mapping_id FROM {$table} WHERE context_hash = %s", $context_hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $ids as $id ) {
			$wpdb->delete( $events, array( 'mapping_id' => $id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return (int) $wpdb->delete( $table, array( 'context_hash' => $context_hash ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		$row['id']              = (int) $row['id'];
		$row['mapping_version'] = (int) $row['mapping_version'];
		$row['created_by']      = (int) $row['created_by'];
		$row['document']        = json_decode( (string) $row['document'], true );
		unset( $row['schema_snapshot'] );
		return $row;
	}
}
