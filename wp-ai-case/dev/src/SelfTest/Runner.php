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
	 * : unit / integration / all
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
		if ( in_array( $group, array( 'unit', 'all' ), true ) ) {
			( new UnitTests( $t ) )->run();
		}
		if ( in_array( $group, array( 'integration', 'all' ), true ) ) {
			$user_id = get_current_user_id();
			if ( $user_id < 1 ) {
				\WP_CLI::error( 'integration には --user=<管理者> が必要です' );
			}
			( new IntegrationTests( $t, $user_id ) )->run();
		}

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
