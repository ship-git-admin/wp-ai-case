<?php
/**
 * 開発専用ツールの読み込み（このディレクトリは配布ZIPに含めない）。
 *
 * - WP-CLI: `wp ai-case poc ...`（合成データの保存実証）, `wp ai-case selftest`
 * - 表示確認用の期限付きプレビュー（印の付いた合成データの下書きだけ）
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev;

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'ai-case poc', PocCommand::class );
	\WP_CLI::add_command( 'ai-case selftest', SelfTest\Runner::class );
}

PreviewToken::register();
