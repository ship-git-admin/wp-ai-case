<?php
/**
 * 管理者への通知（APIキー未設定）。サイトの他の機能は止めない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Admin;

use WpAiCase\Diagnostics\Settings;

defined( 'ABSPATH' ) || exit;

final class Notices {

	private const SCREENS = array( 'dashboard', 'plugins', 'toplevel_page_wp-ai-case' );

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) || 'configured' === Settings::api_key_status() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( 'WP AI Case（AI-001）：' . Settings::info()['api'] . ' のキーが未設定のため、AIによる施工事例の作成は使えません。サイトの他の機能には影響しません。' ),
			esc_url( Menu::diagnostics_url() ),
			esc_html( '接続・診断を開く' )
		);
	}
}
