<?php
/**
 * 「施工事例をAIで作成」画面（顧客向け）。
 *
 * 画面は assets/js/create.js が組み立てる。通信は REST（wp-ai-case/v1、ログインCookie＋nonce）。
 * 一般顧客には、エラーコードと安全な文面だけを表示する（設定方法・キー・サーバー情報は出さない）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Admin;

use WpAiCase\Diagnostics\PreflightService;

defined( 'ABSPATH' ) || exit;

final class CreatePage {

	public const HOOK = 'toplevel_page_wp-ai-case';

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( self::HOOK !== $hook ) {
			return;
		}
		wp_enqueue_media();
		$ver = defined( 'WP_AI_CASE_VERSION' ) ? WP_AI_CASE_VERSION : '0';
		$dir = plugin_dir_path( WP_AI_CASE_FILE );
		// 更新後に古いファイルがブラウザに残らないよう、版に更新日時を足す。
		$css_ver = $ver . '.' . (int) @filemtime( $dir . 'assets/css/create.css' );
		$js_ver  = $ver . '.' . (int) @filemtime( $dir . 'assets/js/create.js' );
		wp_enqueue_style( 'wp-ai-case-create', plugins_url( 'assets/css/create.css', WP_AI_CASE_FILE ), array(), $css_ver );
		wp_enqueue_script( 'wp-ai-case-create', plugins_url( 'assets/js/create.js', WP_AI_CASE_FILE ), array(), $js_ver, true );
		wp_add_inline_script(
			'wp-ai-case-create',
			'window.WPAIC = ' . wp_json_encode(
				array(
					'root'           => esc_url_raw( rest_url( 'wp-ai-case/v1/' ) ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'isAdmin'        => current_user_can( 'manage_options' ),
					'diagnosticsUrl' => current_user_can( 'manage_options' ) ? Menu::diagnostics_url() : '',
					'insecure'       => ! is_ssl(),
				)
			) . ';',
			'before'
		);
	}

	public static function render(): void {
		$type = get_post_type_object( 'works' );
		if ( ! $type || ! current_user_can( $type->cap->edit_posts ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'wp-ai-case' ), 403 );
		}
		$pre = PreflightService::check( get_current_user_id() );
		echo '<div class="wrap wpaic">';
		echo '<h1>' . esc_html( '施工事例をAIで作成' ) . '</h1>';
		if ( str_starts_with( \WpAiCase\AI\ProviderFactory::make()->id(), 'mock' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( '開発用の模擬AIで動作しています。作られる文章は【模擬】の固定文で、写真や文章は外部へ送信されません。' ) . '</p></div>';
		}
		if ( ! $pre['ok'] ) {
			printf(
				'<div class="notice notice-error inline"><p><strong>%s</strong>（%s）</p></div>',
				esc_html( (string) $pre['error']['message'] ),
				esc_html( (string) $pre['error']['error_code'] )
			);
			if ( current_user_can( 'manage_options' ) ) {
				$mapping = 'AI-010' === (string) $pre['error']['error_code'];
				printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $mapping ? Menu::mapping_url() : Menu::diagnostics_url() ), esc_html( $mapping ? 'マッピングを開く（管理者）' : '接続・診断を開く（管理者）' ) );
			}
		}
		echo '<div id="wpaic-app" data-available="' . ( $pre['ok'] ? '1' : '0' ) . '"><p>' . esc_html( '読み込み中…' ) . '</p></div>';
		echo '<noscript><p>' . esc_html( 'この画面を使うには JavaScript を有効にしてください。' ) . '</p></noscript>';
		echo '</div>';
	}
}
