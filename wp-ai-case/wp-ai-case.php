<?php
/**
 * Plugin Name:       WP AI Case
 * Description:       施工事例（works）の下書きを、ACF の現在の定義に合わせて安全に作成・保存するためのプラグイン（開発中：C0/C1-A）。
 * Version:           0.1.0-dev
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Ship Inc.
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ai-case
 *
 * @package WpAiCase
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_AI_CASE_VERSION', '0.1.0-dev' );
define( 'WP_AI_CASE_FILE', __FILE__ );
define( 'WP_AI_CASE_DIR', __DIR__ );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	// 古いPHPではクラスを読み込まない（サイト全体を壊さない）。
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>WP AI Case: PHP 8.1 以上が必要です。プラグインは動作を停止しています。</p></div>';
			}
		}
	);
	return;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WpAiCase\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$base     = WP_AI_CASE_DIR . '/src/';
		// 開発専用コード（dev/）は配布ZIPに含めない。存在するときだけ読み込める。
		if ( strncmp( $relative, 'Dev\\', 4 ) === 0 ) {
			$relative = substr( $relative, 4 );
			$base     = WP_AI_CASE_DIR . '/dev/src/';
		}
		$path = $base . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action( 'plugins_loaded', array( \WpAiCase\Plugin::class, 'boot' ), 20 );
