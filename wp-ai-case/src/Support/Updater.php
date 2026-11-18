<?php
/**
 * 更新の確認（GitHub の公開リリース → WordPress 標準の「更新」画面から1クリック）。
 *
 * - 同梱の Plugin Update Checker（v5.7、MIT）で、GitHub のリリースを12時間ごとに確認する。
 * - 使う ZIP は、リリースに添付した配布用 ZIP だけ（REQUIRE_RELEASE_ASSETS）。GitHub が自動で作る
 *   ソース一式の ZIP（開発用のファイルを含む）は使わない。添付がないリリースは更新として扱わない。
 * - 公開リポジトリから取得するので認証トークンは使わない（サイトに秘密を置かない）。
 * - 更新の確認で送るのは、GitHub API への通常の取得だけ（サイトの情報・キーは送らない）。
 * - 止めるとき：wp-config.php で WP_AI_CASE_DISABLE_UPDATES を true。取得元を変えるとき：WP_AI_CASE_UPDATE_REPO。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Support;

defined( 'ABSPATH' ) || exit;

final class Updater {

	public const DEFAULT_REPO = 'https://github.com/ship-git-admin/wp-ai-case/';
	public const ASSET_REGEX  = '/^wp-ai-case-[0-9]+\.[0-9]+\.[0-9]+\.zip$/';

	/** @var object|null 生成した Plugin Update Checker（試験・診断用） */
	public static ?object $checker = null;

	public static function register(): void {
		if ( defined( 'WP_AI_CASE_DISABLE_UPDATES' ) && WP_AI_CASE_DISABLE_UPDATES ) {
			return;
		}
		$loader = WP_AI_CASE_DIR . '/vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $loader ) ) {
			return;
		}
		$repo = self::repository();
		if ( '' === $repo ) {
			return;
		}
		require_once $loader;
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( $repo, WP_AI_CASE_FILE, 'wp-ai-case' );
		$checker->getVcsApi()->enableReleaseAssets( self::ASSET_REGEX, \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS );
		self::$checker = $checker;
	}

	/**
	 * 取得元（GitHub のリポジトリの URL だけを許す）。不正なら空文字（更新の確認をしない）。
	 */
	public static function repository(): string {
		$repo = defined( 'WP_AI_CASE_UPDATE_REPO' ) && is_string( WP_AI_CASE_UPDATE_REPO ) ? WP_AI_CASE_UPDATE_REPO : self::DEFAULT_REPO;
		$repo = (string) apply_filters( 'wp_ai_case_update_repository', $repo );
		return preg_match( '#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/?$#', $repo ) ? trailingslashit( $repo ) : '';
	}
}
