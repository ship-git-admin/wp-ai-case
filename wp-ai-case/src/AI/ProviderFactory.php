<?php
/**
 * 使うプロバイダーを選ぶ。
 *
 * - 通常は Claude API（WordPress の HTTP API で直接呼ぶ。2026-11-18 依頼者の決定）
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
		return new AnthropicProvider();
	}
}
