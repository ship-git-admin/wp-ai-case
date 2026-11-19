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

	/**
	 * 写真の解析と記事の下書き（成功時 data: content=出力JSON、usage=トークン数）。
	 *
	 * @param array<string,mixed> $payload model, facts, photo_set_description, images（attachment_id/position/caption/phase_hint/data/media_type）,
	 *                                     mode（full / analyze_only / compose_text_only）, prior_analysis
	 */
	public function compose( array $payload ): ProviderResult;

	/**
	 * 指示に従った修正操作の提案（成功時 data: content=['operations'=>...]、usage）。
	 *
	 * @param array<string,mixed> $payload model, document（画像データなし）, allowed_sections, instruction
	 */
	public function revise( array $payload ): ProviderResult;

	/**
	 * 既存ページの文章から、一次情報を項目ごとに取り出す（成功時 data: content=Prompts::extract_schema の形、usage）。
	 *
	 * @param array<string,mixed> $payload model, text（タグを除いたページの文章）
	 */
	public function extract( array $payload ): ProviderResult;
}
