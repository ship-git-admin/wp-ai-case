<?php
/**
 * REST（wp-ai-case/v1）。WordPress のログインCookie＋nonce と権限で守る。
 *
 * - /ping           … 疎通確認（ログインユーザー、内容なし）
 * - /preflight      … ログインユーザー：利用できるか（顧客向けの安全な文面だけ）
 * - /diagnostics    … 管理者：ローカル診断（diagnostic_result）
 * - /support/{id}   … 管理者：サポートIDの記録
 *
 * 共通テンプレートのサイトでは、Code Snippet「RESTAPIセキュリティ設定」が管理者以外の REST を止めている。
 * そこへ `wp-ai-case/` の例外を追加して使う（ログイン・nonce・権限の確認はこのクラスの permission_callback で行う）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Rest;

use WpAiCase\Diagnostics\DiagnosticsService;
use WpAiCase\Diagnostics\PreflightService;
use WpAiCase\Diagnostics\SupportLog;

defined( 'ABSPATH' ) || exit;

final class RestController {

	public const NS = 'wp-ai-case/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => array( 'ok' => true ),
				'permission_callback' => static fn() => is_user_logged_in(),
			)
		);
		register_rest_route(
			self::NS,
			'/preflight',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'preflight' ),
				'permission_callback' => static fn() => is_user_logged_in(),
			)
		);
		register_rest_route(
			self::NS,
			'/diagnostics',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => DiagnosticsService::run(),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);
		register_rest_route(
			self::NS,
			'/support/(?P<id>[0-9a-f\-]{36})',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'support' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function preflight(): array {
		$r = PreflightService::check( get_current_user_id() );
		return array(
			'available' => $r['ok'],
			'error'     => $r['error'],
		);
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function support( \WP_REST_Request $request ) {
		$row = SupportLog::get( (string) $request['id'] );
		return $row ?? new \WP_Error( 'not_found', '記録が見つかりません', array( 'status' => 404 ) );
	}
}
