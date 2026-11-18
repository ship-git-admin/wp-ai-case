<?php
/**
 * サポートID（推測困難な uuid）ごとのエラー記録。管理者だけが参照できる。
 *
 * 記録するのはコード・分類・安全化した補足情報・操作者・日時だけ。
 * APIキー、Authorization、外部APIの生応答、ホスト名、パス、画像、個人名は記録しない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Diagnostics;

use WpAiCase\Support\Installer;

defined( 'ABSPATH' ) || exit;

final class SupportLog {

	/**
	 * @param array<string,mixed> $details
	 */
	public static function add( string $support_id, string $code, string $event, array $details, int $user_id ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Installer::support_log_table(),
			array(
				'support_id' => $support_id,
				'code'       => $code,
				'event'      => substr( $event, 0, 64 ),
				'user_id'    => $user_id,
				'details'    => (string) wp_json_encode( Redactor::context( $details ), JSON_UNESCAPED_UNICODE ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get( string $support_id ): ?array {
		global $wpdb;
		if ( ! wp_is_uuid( $support_id, 4 ) ) {
			return null;
		}
		$table = Installer::support_log_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT support_id, code, event, user_id, details, created_at FROM {$table} WHERE support_id = %s", $support_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}
		$row['details'] = json_decode( (string) $row['details'], true );
		$row['user_id'] = (int) $row['user_id'];
		return $row;
	}

	/**
	 * 保持期間を過ぎた記録を消す（既定90日）。
	 */
	public static function purge( int $days = 90 ): int {
		global $wpdb;
		$table = Installer::support_log_table();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
