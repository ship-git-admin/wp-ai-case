<?php
/**
 * 顧客向け画面の通信（admin-ajax.php）。
 *
 * 共通テンプレートのサイトでは、管理者以外の REST API が Code Snippets で止められている
 * （oEmbed・Contact Form 7 以外は rest_disabled）。そのため顧客向けの操作は REST に頼らず、
 * ログインCookie＋nonce＋権限で守った admin-ajax を使う。未ログイン（nopriv）には登録しない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Rest;

use WpAiCase\Diagnostics\PreflightService;
use WpAiCase\Plugin;

defined( 'ABSPATH' ) || exit;

final class AjaxController {

	public const NONCE = 'wp_ai_case_customer';

	public static function register(): void {
		add_action( 'wp_ajax_wp_ai_case_preflight', array( self::class, 'ajax_preflight' ) );
	}

	public static function ajax_preflight(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! self::can_use( get_current_user_id() ) ) {
			wp_send_json( array( 'available' => false, 'error' => PreflightService::check( 0 )['error'] ), 403 );
		}
		wp_send_json( self::preflight( get_current_user_id() ) );
	}

	/**
	 * @return array{available:bool,error:array<string,mixed>|null}
	 */
	public static function preflight( int $user_id ): array {
		$r = PreflightService::check( $user_id );
		return array(
			'available' => $r['ok'],
			'error'     => $r['error'],
		);
	}

	public static function can_use( int $user_id ): bool {
		$type = get_post_type_object( Plugin::POST_TYPE );
		return $user_id > 0 && $type && user_can( $user_id, $type->cap->edit_posts );
	}
}
