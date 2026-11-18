<?php
/**
 * 画面・REST に返すエラー表現（契約 error_response）を作り、サポート記録を残す。
 *
 * - 文面は ErrorCatalog の固定文だけ。visibility=customer では技術情報を一切含めない。
 * - support_id は推測困難な uuid。詳細は管理者だけが SupportLog から参照できる。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Diagnostics;

use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class SafeErrorPresenter {

	/**
	 * @param array<string,mixed> $details 安全化して記録する補足情報（表示はしない）
	 * @return array<string,mixed> error_response 契約の文書
	 */
	public static function present( string $code, string $visibility, string $event, array $details = array(), string $http_class = 'none' ): array {
		if ( ! ErrorCatalog::exists( $code ) ) {
			$code = 'AI-018';
		}
		$visibility = 'admin' === $visibility ? 'admin' : 'customer';
		$support_id = wp_generate_uuid4();
		SupportLog::add( $support_id, $code, $event, $details + array( 'http_status_class' => $http_class ), get_current_user_id() );

		$out = array(
			'success'         => false,
			'error_code'      => $code,
			'visibility'      => $visibility,
			'message'         => ErrorCatalog::message( $code, $visibility ),
			'retryable'       => ErrorCatalog::retryable( $code ),
			'support_id'      => $support_id,
			'recovery_action' => ErrorCatalog::recovery_action( $code, $visibility ),
		);
		if ( 'admin' === $visibility ) {
			$out['provider_http_status_class'] = in_array( $http_class, array( 'none', '4xx', '5xx', 'network', 'unknown' ), true ) ? $http_class : 'unknown';
		}
		return $out;
	}

	/**
	 * CaseError から作る（コードは例外の分類から決める）。
	 *
	 * @return array<string,mixed>
	 */
	public static function from_case_error( CaseError $e, string $visibility, string $event ): array {
		return self::present( $e->ai_code(), $visibility, $event, array( 'reason' => $e->reason ) + $e->context );
	}
}
