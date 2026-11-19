<?php
/**
 * WP-CLI: 自己テスト（開発専用）。実際の WordPress / ACF 上で動かす。
 *
 * - unit … DBに書かない試験（合成のACF定義・CaseDocument検証・既知マッピング）
 * - integration … 試験用の下書きを作って保存・読み戻し・拒否を確かめ、最後に必ず削除する
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev\SelfTest;

defined( 'ABSPATH' ) || exit;

/**
 * 自己テストを実行する。
 */
final class Runner {

	/**
	 * 自己テストを実行する。
	 *
	 * ## OPTIONS
	 *
	 * [--group=<group>]
	 * : unit / mapping / diag / jobs / quota / integration / all
	 * ---
	 * default: all
	 * ---
	 *
	 * [--format=<format>]
	 * : table か json
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function __invoke( array $args, array $assoc ): void {
		$group = (string) ( $assoc['group'] ?? 'all' );
		$t     = new Tester();
		// 試験で作る予約・使用量は試験用の月に入れ、実サイトの今月の枠・費用には数えない（最後に消す）。
		\WpAiCase\Quota\QuotaService::$period_override = '2099-12';
		\WpAiCase\Quota\QuotaService::$limit_override  = 1000;
		// サイトの設定（WP_AI_CASE_PROVIDER）に関係なく、試験は Claude を基準にする（OpenAI の試験は個別に切り替える）。
		$pin = static fn() => 'anthropic';
		add_filter( 'wp_ai_case_provider_id', $pin, 1 );
		try {
			$this->groups( $group, $t );
		} finally {
			remove_filter( 'wp_ai_case_provider_id', $pin, 1 );
			global $wpdb;
			$wpdb->query( 'DELETE FROM ' . \WpAiCase\Support\Installer::quota_table() . " WHERE period = '2099-12'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'DELETE FROM ' . \WpAiCase\Support\Installer::usage_table() . " WHERE period = '2099-12'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			\WpAiCase\Quota\QuotaService::$period_override = null;
			\WpAiCase\Quota\QuotaService::$limit_override  = null;
		}
		$this->report( $t, $assoc );
	}

	private function groups( string $group, Tester $t ): void {
		if ( in_array( $group, array( 'unit', 'all' ), true ) ) {
			( new UnitTests( $t ) )->run();
		}
		$user_id = get_current_user_id();
		if ( in_array( $group, array( 'mapping', 'diag', 'jobs', 'display', 'quota', 'integration', 'all' ), true ) && $user_id < 1 ) {
			\WP_CLI::error( 'mapping / diag / jobs / integration には --user=<管理者> が必要です' );
		}
		if ( in_array( $group, array( 'diag', 'all' ), true ) ) {
			( new DiagnosticsTests( $t, $user_id ) )->run();
		}
		if ( in_array( $group, array( 'mapping', 'all' ), true ) ) {
			( new MappingTests( $t, $user_id ) )->run();
		}
		if ( in_array( $group, array( 'jobs', 'all' ), true ) ) {
			( new JobTests( $t, $user_id ) )->run();
			( new ArticleTests( $t, $user_id ) )->run();
		}
		if ( in_array( $group, array( 'jobs', 'display', 'all' ), true ) ) {
			( new DisplayTests( $t, $user_id ) )->run();
		}
		if ( in_array( $group, array( 'quota', 'all' ), true ) ) {
			( new QuotaTests( $t, $user_id ) )->run();
		}
		if ( in_array( $group, array( 'integration', 'all' ), true ) ) {
			( new IntegrationTests( $t, $user_id ) )->run();
		}
	}

	/**
	 * @param array<string,string> $assoc
	 */
	private function report( Tester $t, array $assoc ): void {
		$results = $t->results();
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $results, array( 'id', 'result', 'name', 'detail' ) );
		}
		$fail = count( array_filter( $results, static fn( $r ) => 'PASS' !== $r['result'] && 'SKIP' !== $r['result'] ) );
		$pass = count( array_filter( $results, static fn( $r ) => 'PASS' === $r['result'] ) );
		if ( $fail ) {
			\WP_CLI::error( "FAIL {$fail} / PASS {$pass}" );
		}
		\WP_CLI::success( "PASS {$pass} / 全 " . count( $results ) . ' 件' );
	}
}
