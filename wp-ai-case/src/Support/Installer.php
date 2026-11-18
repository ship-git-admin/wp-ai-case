<?php
/**
 * DB テーブルの作成・更新と、サイト識別子の発行。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Support;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public const DB_VERSION        = '1';
	private const DB_VERSION_OPTION = 'wp_ai_case_db_version';
	private const SITE_SCOPE_OPTION = 'wp_ai_case_site_scope_id';

	public static function mappings_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpaic_mappings';
	}

	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpaic_mapping_events';
	}

	/**
	 * 必要ならテーブルを作成・更新する（何度呼んでもよい）。
	 */
	public static function maybe_install(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset  = $wpdb->get_charset_collate();
		$mappings = self::mappings_table();
		$events   = self::events_table();

		dbDelta(
			"CREATE TABLE {$mappings} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  mapping_id char(36) NOT NULL,
  mapping_version int(10) unsigned NOT NULL,
  context_hash char(64) NOT NULL,
  schema_sha256 char(64) NOT NULL,
  bound_sha256 char(64) NOT NULL DEFAULT '',
  status varchar(32) NOT NULL,
  template_id varchar(64) NOT NULL DEFAULT '',
  template_version varchar(32) NOT NULL DEFAULT '',
  document longtext NOT NULL,
  schema_snapshot longtext NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY mapping_version (mapping_id,mapping_version),
  KEY context_status (context_hash,status)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id char(36) NOT NULL,
  mapping_id char(36) NOT NULL,
  mapping_version int(10) unsigned NOT NULL,
  event_type varchar(40) NOT NULL,
  from_status varchar(32) DEFAULT NULL,
  to_status varchar(32) NOT NULL,
  document longtext NOT NULL,
  occurred_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY mapping (mapping_id,mapping_version)
) {$charset};"
		);
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		self::site_scope_id();
	}

	/**
	 * サイト内のランダムな識別子（外部へ送らない）。WP_AI_CASE_SITE_IDENTIFIER があればそれを使う。
	 */
	public static function site_scope_id(): string {
		if ( defined( 'WP_AI_CASE_SITE_IDENTIFIER' ) && is_string( WP_AI_CASE_SITE_IDENTIFIER ) && '' !== WP_AI_CASE_SITE_IDENTIFIER ) {
			return WP_AI_CASE_SITE_IDENTIFIER;
		}
		$id = get_option( self::SITE_SCOPE_OPTION );
		if ( ! is_string( $id ) || '' === $id ) {
			$id = 'site-' . wp_generate_uuid4();
			update_option( self::SITE_SCOPE_OPTION, $id, false );
		}
		return $id;
	}
}
