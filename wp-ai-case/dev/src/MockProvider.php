<?php
/**
 * 開発用の模擬プロバイダー。外部へは通信せず、指定した結果（成功・401・429 など）を返す。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev;

use WpAiCase\AI\ProviderInterface;
use WpAiCase\AI\ProviderResult;

defined( 'ABSPATH' ) || exit;

final class MockProvider implements ProviderInterface {

	public const OPTION    = 'wp_ai_case_dev_mock_provider';
	public const SCENARIOS = array( 'ok', '401', '402', '403', '404', '413', '429', '500', '529', 'network', 'timeout' );

	public static int $calls = 0;

	public function __construct( private string $scenario ) {
	}

	public static function register(): void {
		add_filter(
			'wp_ai_case_provider',
			static function ( $provider ) {
				$scenario = (string) get_option( self::OPTION, '' );
				return in_array( $scenario, self::SCENARIOS, true ) ? new self( $scenario ) : $provider;
			}
		);
	}

	public function id(): string {
		return 'mock:' . $this->scenario;
	}

	public function probe( string $model ): ProviderResult {
		++self::$calls;
		return match ( $this->scenario ) {
			'ok'      => ProviderResult::success( 200, array( 'display_name' => 'mock ' . $model ), 'req_mock' ),
			'401'     => ProviderResult::http_error( 401, 'authentication_error', 'req_mock' ),
			'402'     => ProviderResult::http_error( 402, 'billing_error', 'req_mock' ),
			'403'     => ProviderResult::http_error( 403, 'permission_error', 'req_mock' ),
			'404'     => ProviderResult::http_error( 404, 'not_found_error', 'req_mock' ),
			'413'     => ProviderResult::http_error( 413, 'request_too_large', 'req_mock' ),
			'429'     => ProviderResult::http_error( 429, 'rate_limit_error', 'req_mock' ),
			'500'     => ProviderResult::http_error( 500, 'api_error', 'req_mock' ),
			'529'     => ProviderResult::http_error( 529, 'overloaded_error', 'req_mock' ),
			'network' => ProviderResult::network( false ),
			'timeout' => ProviderResult::network( true ),
			default   => ProviderResult::unavailable(),
		};
	}
}
