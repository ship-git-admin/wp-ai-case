<?php
/**
 * プロバイダーの結果を AI-xxx に分類する。HTTP ステータスだけで決めつけず、エラー種別も見る。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Diagnostics;

use WpAiCase\AI\ProviderResult;

defined( 'ABSPATH' ) || exit;

final class ErrorClassifier {

	/**
	 * @return array{code:string,http_class:string}|null 成功なら null
	 */
	public static function from_provider( ProviderResult $r ): ?array {
		if ( $r->ok ) {
			return null;
		}
		if ( $r->not_available ) {
			return array( 'code' => 'AI-018', 'http_class' => 'none' );
		}
		if ( $r->network_error ) {
			return array( 'code' => 'AI-007', 'http_class' => 'network' );
		}
		$status = (int) $r->http_status;
		$type   = (string) $r->error_type;
		$class  = $status >= 500 ? '5xx' : ( $status >= 400 ? '4xx' : 'unknown' );

		$code = match ( true ) {
			401 === $status || 'authentication_error' === $type => 'AI-002',
			402 === $status || 'billing_error' === $type, 403 === $status || 'permission_error' === $type => 'AI-003',
			404 === $status || 'not_found_error' === $type => 'AI-004',
			413 === $status || 'request_too_large' === $type => 'AI-008',
			429 === $status || 'rate_limit_error' === $type => 'AI-005',
			529 === $status || 'overloaded_error' === $type, $status >= 500 || 'api_error' === $type => 'AI-006',
			default => 'AI-018',
		};
		return array( 'code' => $code, 'http_class' => $class );
	}
}
