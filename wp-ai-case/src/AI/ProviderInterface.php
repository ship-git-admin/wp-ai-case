<?php
/**
 * AI プロバイダーの境界。プラグインの他の部分はこのインターフェースだけを使う。
 *
 * @package WpAiCase
 */

namespace WpAiCase\AI;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {

	public function id(): string;

	/**
	 * 認証とモデルの到達性だけを確かめる最小の呼び出し。ユーザーの写真・文章・ACF定義・サーバー情報は送らない。
	 */
	public function probe( string $model ): ProviderResult;
}
