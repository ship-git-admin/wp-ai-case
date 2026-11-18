<?php
/**
 * 「施工事例をAIで作成」画面（C1-C 前半：利用可否の表示のみ。入力フォームは次の段階）。
 *
 * 一般顧客には、エラーコードと「管理者へ問い合わせ」だけを表示する。設定方法・キー・サーバー情報は表示しない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Admin;

use WpAiCase\Diagnostics\PreflightService;

defined( 'ABSPATH' ) || exit;

final class CreatePage {

	public static function render(): void {
		$type = get_post_type_object( 'works' );
		if ( ! $type || ! current_user_can( $type->cap->edit_posts ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'wp-ai-case' ), 403 );
		}
		$pre = PreflightService::check( get_current_user_id() );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( '施工事例をAIで作成' ) . '</h1>';
		if ( ! $pre['ok'] ) {
			printf(
				'<div class="notice notice-error inline"><p><strong>%s</strong>（%s）</p></div>',
				esc_html( (string) $pre['error']['message'] ),
				esc_html( (string) $pre['error']['error_code'] )
			);
			if ( current_user_can( 'manage_options' ) ) {
				printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( Menu::diagnostics_url() ), esc_html( '接続・診断を開く（管理者）' ) );
			}
		} else {
			echo '<div class="notice notice-success inline"><p>' . esc_html( 'AI機能を利用できる状態です。' ) . '</p></div>';
		}
		echo '<p>' . esc_html( '入力フォーム（施工概要・ご要望・施工内容・写真・外部送信への同意）は準備中です。' ) . '</p>';
		printf( '<p><button type="button" class="button button-primary" disabled>%s</button></p>', esc_html( 'AIで下書きを作成' ) );
		echo '</div>';
	}
}
