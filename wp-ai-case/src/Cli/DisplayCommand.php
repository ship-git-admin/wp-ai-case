<?php
/**
 * WP-CLI：テーマでの表示（表示メモ）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Cli;

use WpAiCase\Display\DisplayNotes;
use WpAiCase\Display\ThemeReader;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

/**
 * 施工事例の表示に使うテーマのファイルから作る「表示メモ」の確認・作成・承認。
 */
final class DisplayCommand {

	/**
	 * 表示メモの状態（none / draft / approved / stale）と、いま記事の作成に使っているメモの出どころ。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function status( array $args, array $assoc ): void {
		$s = DisplayNotes::stored();
		\WP_CLI::line(
			(string) wp_json_encode(
				array(
					'status'      => DisplayNotes::status(),
					'used_source' => DisplayNotes::for_prompt()['source'],
					'created_at'  => $s['created_at'] ?? null,
					'approved_at' => $s['approved_at'] ?? null,
					'theme'       => $s['theme'] ?? null,
					'usd'         => $s['usd'] ?? null,
				),
				JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * 送るテーマのファイルの一覧（中身は表示しない。秘密らしい文字列の検査の結果つき）。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function files( array $args, array $assoc ): void {
		$set  = ThemeReader::collect();
		$rows = array();
		foreach ( $set['files'] as $f ) {
			$code   = (string) file_get_contents( (string) $f['abs'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$rows[] = array(
				'path'    => $f['path'],
				'reason'  => $f['reason'],
				'kb'      => number_format( (int) $f['bytes'] / 1024, 1 ),
				'secrets' => implode( ' ', ThemeReader::scan( $code ) ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'path', 'reason', 'kb', 'secrets' ) );
		\WP_CLI::line( 'frame=' . $set['frame'] . ' skipped=' . implode( ',', array_column( $set['skipped'], 'path' ) ) );
	}

	/**
	 * テーマのファイルを AI に送って表示メモを作る（承認待ち）。外部へ送るので --yes が必要。
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : 送信に同意する。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function build( array $args, array $assoc ): void {
		if ( empty( $assoc['yes'] ) ) {
			\WP_CLI::error( 'テーマのファイルを AI へ送ります。files で一覧を確かめてから --yes を付けて実行してください' );
		}
		try {
			$r = DisplayNotes::build( get_current_user_id() );
		} catch ( CaseError $e ) {
			\WP_CLI::error( $e->ai_code() . '：' . $e->getMessage() . ( ! empty( $e->context['found'] ) ? '：' . implode( '、', (array) $e->context['found'] ) : '' ) );
		}
		\WP_CLI::success( '表示メモを作りました（承認待ち）。推定費用 $' . number_format( (float) $r['cost']['usd'], 4 ) . '・入力 ' . (int) $r['cost']['input_tokens'] . ' / 出力 ' . (int) $r['cost']['output_tokens'] . ' トークン' );
	}

	/**
	 * 表示メモの内容を表示する。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function show( array $args, array $assoc ): void {
		$s = DisplayNotes::stored();
		if ( ! $s ) {
			\WP_CLI::error( '表示メモがありません' );
		}
		\WP_CLI::line( (string) wp_json_encode( $s['notes'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
	}

	/**
	 * 表示メモを承認して、記事の作成・修正に使う。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function approve( array $args, array $assoc ): void {
		try {
			DisplayNotes::approve( get_current_user_id() );
		} catch ( CaseError $e ) {
			\WP_CLI::error( $e->ai_code() . '：' . $e->getMessage() );
		}
		\WP_CLI::success( '表示メモを承認しました' );
	}

	/**
	 * 表示メモを削除する（標準のメモに戻る）。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function discard( array $args, array $assoc ): void {
		try {
			DisplayNotes::discard( get_current_user_id() );
		} catch ( CaseError $e ) {
			\WP_CLI::error( $e->ai_code() . '：' . $e->getMessage() );
		}
		\WP_CLI::success( '表示メモを削除しました' );
	}
}
