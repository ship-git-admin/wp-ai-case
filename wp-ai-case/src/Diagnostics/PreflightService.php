<?php
/**
 * 生成を始める前のローカル確認（外部通信 0 回、ジョブ作成 0、枠の予約 0）。
 *
 * 失敗したら、一般顧客向けの安全な文面（error_response, visibility=customer）を返す。
 * 入力内容・画像IDは呼び出し側（画面）で保持したままにする。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Diagnostics;

use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Mapping\MappingService;
use WpAiCase\Plugin;

defined( 'ABSPATH' ) || exit;

final class PreflightService {

	/**
	 * @param bool $record 失敗をサポート記録に残すか（ページ表示時は残さず、生成ボタンを押したときに残す）
	 * @return array{ok:bool,code:string,error:array<string,mixed>|null}
	 */
	public static function check( int $user_id, bool $record = false ): array {
		$code = self::first_failure( $user_id );
		if ( null === $code ) {
			return array( 'ok' => true, 'code' => '', 'error' => null );
		}
		$error = $record
			? SafeErrorPresenter::present( $code, 'customer', 'preflight_failed', array( 'reason' => 'preflight' ) )
			: array(
				'success'         => false,
				'error_code'      => $code,
				'visibility'      => 'customer',
				'message'         => ErrorCatalog::message( $code, 'customer' ),
				'retryable'       => ErrorCatalog::retryable( $code ),
				'support_id'      => wp_generate_uuid4(),
				'recovery_action' => ErrorCatalog::recovery_action( $code, 'customer' ),
			);
		return array( 'ok' => false, 'code' => $code, 'error' => $error );
	}

	private static function first_failure( int $user_id ): ?string {
		$type = get_post_type_object( Plugin::POST_TYPE );
		if ( $user_id < 1 || ! $type || ! user_can( $user_id, $type->cap->edit_posts ) ) {
			return 'AI-015';
		}
		if ( 'configured' !== Settings::api_key_status() ) {
			return 'AI-001';
		}
		if ( '' === Settings::model() ) {
			return 'AI-004';
		}
		$last = ProviderProbe::last();
		if ( $last && 'failed' === $last['outcome'] && in_array( $last['code'], array( 'AI-002', 'AI-003', 'AI-004' ), true ) ) {
			// 失敗が分かっている設定のまま送らない（管理者が直して接続テストをやり直すまで）。
			return (string) $last['code'];
		}
		$inspector = new SchemaInspector();
		$state     = ( new MappingService( $inspector ) )->inspect( $inspector->context( Plugin::POST_TYPE, 0, array() ) )['state'];
		if ( 'ready' !== $state ) {
			return 'AI-010';
		}
		return null;
	}
}
