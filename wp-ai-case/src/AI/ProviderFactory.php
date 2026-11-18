<?php
/**
 * 使うプロバイダーを選ぶ。
 *
 * - 開発用の Mock（dev/ がありオプションで有効なときだけ）
 * - Claude API との接続は、実装方式（公式SDKの同梱か WordPress の HTTP API か）の確定後に追加する。
 *   それまでは「未実装」を返し、外部へは一切通信しない。
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
		return new PendingProvider();
	}
}
