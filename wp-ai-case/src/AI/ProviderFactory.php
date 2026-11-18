<?php
/**
 * 使うプロバイダーを選ぶ。
 *
 * - wp-config.php の WP_AI_CASE_PROVIDER で、Claude（既定）と OpenAI を切り替える（どちらも WordPress の HTTP API で直接呼ぶ）
 * - 開発用の Mock（dev/ がありオプションで有効なときだけ）
 *
 * @package WpAiCase
 */

namespace WpAiCase\AI;

defined( 'ABSPATH' ) || exit;

final class ProviderFactory {

	public static function make(): ProviderInterface {
		/**
		 * 開発用：プロバイダーを差し替える（dev/ の Mock が使う）。
		 */
		$provider = apply_filters( 'wp_ai_case_provider', null );
		if ( $provider instanceof ProviderInterface ) {
			return $provider;
		}
		return self::for_provider( \WpAiCase\Diagnostics\Settings::provider() );
	}

	public static function for_provider( string $id ): ProviderInterface {
		return 'openai' === $id ? new OpenAIProvider() : new AnthropicProvider();
	}
}
