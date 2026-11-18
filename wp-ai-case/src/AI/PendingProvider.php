<?php
/**
 * Claude API 接続の実装待ちを表すプロバイダー。外部通信をしない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\AI;

defined( 'ABSPATH' ) || exit;

final class PendingProvider implements ProviderInterface {

	public function id(): string {
		return 'pending';
	}

	public function probe( string $model ): ProviderResult {
		return ProviderResult::unavailable();
	}
}
