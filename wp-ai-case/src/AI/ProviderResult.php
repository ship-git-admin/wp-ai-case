<?php
/**
 * プロバイダー呼び出しの結果（生の応答本文・ヘッダーは持たない）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\AI;

defined( 'ABSPATH' ) || exit;

final class ProviderResult {

	/**
	 * @param array<string,mixed> $data 成功時の安全な値だけ（例：モデルの表示名）
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly ?int $http_status = null,
		public readonly ?string $error_type = null,
		public readonly bool $network_error = false,
		public readonly bool $timed_out = false,
		public readonly bool $not_available = false,
		public readonly ?string $request_id = null,
		public readonly array $data = array()
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function success( int $status, array $data = array(), ?string $request_id = null ): self {
		return new self( true, $status, null, false, false, false, $request_id, $data );
	}

	public static function http_error( int $status, ?string $error_type, ?string $request_id = null ): self {
		return new self( false, $status, $error_type, false, false, false, $request_id );
	}

	public static function network( bool $timed_out ): self {
		return new self( false, null, null, true, $timed_out );
	}

	/**
	 * このサイトではまだ呼び出せない（実装方式の確定待ちなど）。外部通信はしていない。
	 */
	public static function unavailable(): self {
		return new self( false, null, null, false, false, true );
	}
}
