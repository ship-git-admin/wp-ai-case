<?php
/**
 * WP-CLI：記事の作り方（写真の並べ方・書き方）の設定。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Cli;

use WpAiCase\Display\ArticleSettings;

defined( 'ABSPATH' ) || exit;

/**
 * 記事の作り方の設定を表示・書き出し・読み込み・初期化する。
 */
final class ArticleCommand {

	/**
	 * 今の設定を表示する。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function show( array $args, array $assoc ): void {
		\WP_CLI::line( (string) wp_json_encode( ArticleSettings::get(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
	}

	/**
	 * 設定を書き出す（別の案件のサイトで import する JSON）。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function export( array $args, array $assoc ): void {
		\WP_CLI::line( ArticleSettings::export() );
	}

	/**
	 * 書き出した設定を読み込む（今の設定は置き換わる）。
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : export で書き出した JSON のファイル。- で標準入力。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function import( array $args, array $assoc ): void {
		$json = '-' === $args[0] ? (string) stream_get_contents( STDIN ) : (string) file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		try {
			ArticleSettings::import( $json );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::success( '設定を読み込みました' );
	}

	/**
	 * 初期値に戻す。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function reset( array $args, array $assoc ): void {
		ArticleSettings::reset();
		\WP_CLI::success( '初期値に戻しました' );
	}
}
