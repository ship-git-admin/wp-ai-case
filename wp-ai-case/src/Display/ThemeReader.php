<?php
/**
 * 施工事例の表示に関わるテーマのファイルを選んで読む（読み取り専用。書き換えない）。
 *
 * 選び方（2026-11-19 依頼者と決めた）：
 *   1) 名前（パス）に works を含む PHP
 *   2) 1) から get_template_part で呼ばれる部品のうち、施工事例の ACF の項目名（EntryData_Staff など）を使うもの（3段まで）
 *   3) 名前に works を含む SCSS（コンパイル済みの css/ は除く）
 *   どれも、使っていない施工事例のフレーム（GRA/GRB の別の方）・old_・node_modules・vendor・.history・.git は除く。
 *   1ファイル 60KB、合計 240KB まで（超えた分は送らず、一覧に「省略」と出す）。
 * 送る前に、秘密らしい文字列（APIキー・トークン・秘密鍵・パスワードの代入）を機械的に調べる。見つかれば送らない（値は表示しない）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Display;

use WpAiCase\Mapping\ShipWorksTemplateV11;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class ThemeReader {

	public const MAX_FILE  = 61440;
	public const MAX_TOTAL = 245760;
	private const SKIP_DIRS = array( 'node_modules', 'vendor', '.history', '.git', '.github', 'css' );

	/** 秘密らしい文字列（名前 => 正規表現）。値は記録しない。 */
	private const SECRET_PATTERNS = array(
		'Google API キー'    => '/AIza[0-9A-Za-z_\-]{35}/',
		'OpenAI/Anthropic キー' => '/\bsk-(?:ant-)?[A-Za-z0-9_\-]{20,}/',
		'GitHub トークン'    => '/\b(?:ghp|gho|ghu|ghs)_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}/',
		'Slack トークン'     => '/\bxox[abposr]-[A-Za-z0-9\-]{10,}/',
		'AWS キー'           => '/\bAKIA[0-9A-Z]{16}\b/',
		'秘密鍵'             => '/-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----/',
		'パスワード等の代入' => '/(?:password|passwd|secret|api[_-]?key|access[_-]?token|client[_-]?secret)[\'"]?\s*(?:=>|=|:)\s*[\'"][^\'"\s$]{6,}[\'"]/i',
	);

	/**
	 * 送る候補（中身は読まない）。
	 *
	 * @return array{frame:string,files:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>}
	 */
	public static function collect(): array {
		$frame  = self::frame();
		$others = self::other_frames( $frame );
		$files  = array();
		foreach ( self::dirs() as $label => $dir ) {
			foreach ( self::walk( $dir ) as $abs ) {
				$rel = ltrim( str_replace( '\\', '/', substr( $abs, strlen( $dir ) ) ), '/' );
				if ( self::excluded( $rel, $others ) ) {
					continue;
				}
				$files[ $label . ':' . $rel ] = $abs;
			}
		}

		$picked = array();
		foreach ( $files as $key => $abs ) {
			$ext = strtolower( (string) pathinfo( $abs, PATHINFO_EXTENSION ) );
			if ( str_contains( strtolower( $key ), 'works' ) && in_array( $ext, array( 'php', 'scss' ), true ) ) {
				$picked[ $key ] = 'php' === $ext ? 'works' : 'style';
			}
		}
		// 部品（get_template_part）を3段までたどる。施工事例の項目名を使うものだけ。
		$names = self::field_names();
		$queue = array_keys( array_filter( $picked, static fn( $r ) => 'works' === $r ) );
		for ( $depth = 0; $depth < 3 && $queue; $depth++ ) {
			$next = array();
			foreach ( $queue as $key ) {
				foreach ( self::parts( (string) file_get_contents( $files[ $key ] ) ) as $part ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					foreach ( array_keys( $files ) as $cand ) {
						if ( isset( $picked[ $cand ] ) || ! str_ends_with( $cand, ':' . $part ) ) {
							continue;
						}
						$code = (string) file_get_contents( $files[ $cand ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
						foreach ( $names as $name ) {
							if ( false !== stripos( $code, $name ) ) {
								$picked[ $cand ] = 'part';
								$next[]          = $cand;
								break;
							}
						}
					}
				}
			}
			$queue = $next;
		}

		ksort( $picked );
		$out     = array();
		$skipped = array();
		$total   = 0;
		foreach ( $picked as $key => $reason ) {
			$bytes = (int) filesize( $files[ $key ] );
			$row   = array(
				'path'   => $key,
				'reason' => $reason,
				'bytes'  => $bytes,
				'sha256' => (string) hash_file( 'sha256', $files[ $key ] ),
				'abs'    => $files[ $key ],
			);
			$send = min( $bytes, self::MAX_FILE );
			if ( $total + $send > self::MAX_TOTAL ) {
				$skipped[] = $row;
				continue;
			}
			$row['truncated'] = $bytes > self::MAX_FILE;
			$total           += $send;
			$out[]            = $row;
		}
		return array(
			'frame'   => $frame,
			'files'   => $out,
			'skipped' => $skipped,
		);
	}

	/**
	 * 送るファイルの中身（秘密らしい文字列があれば止める）。
	 *
	 * @param array<int,array<string,mixed>> $files collect()['files']
	 * @return array<int,array{path:string,content:string}>
	 * @throws CaseError INVALID_VALUE（reason: secret。context.found に「ファイル:行（種類）」）
	 */
	public static function read( array $files ): array {
		$out   = array();
		$found = array();
		foreach ( $files as $f ) {
			$code = (string) file_get_contents( (string) $f['abs'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( strlen( $code ) > self::MAX_FILE ) {
				$code = mb_strcut( $code, 0, self::MAX_FILE ) . "\n/* （ここから先は大きいため省略） */\n";
			}
			foreach ( self::scan( $code ) as $hit ) {
				$found[] = $f['path'] . ':' . $hit;
			}
			$out[] = array(
				'path'    => (string) $f['path'],
				'content' => $code,
			);
		}
		if ( $found ) {
			throw new CaseError( CaseError::INVALID_VALUE, 'テーマのファイルに秘密らしい文字列があるため、AIへ送信しませんでした', array( 'reason' => 'secret', 'found' => array_slice( $found, 0, 20 ) ) );
		}
		return $out;
	}

	/**
	 * 秘密らしい文字列の場所（「行（種類）」。値は返さない）。
	 *
	 * @return string[]
	 */
	public static function scan( string $code ): array {
		$hits = array();
		foreach ( preg_split( '/\R/', $code ) ?: array() as $i => $line ) {
			foreach ( self::SECRET_PATTERNS as $kind => $re ) {
				if ( preg_match( $re, $line ) ) {
					$hits[] = ( $i + 1 ) . '行目（' . $kind . '）';
				}
			}
		}
		return $hits;
	}

	/**
	 * 選んだファイルの内容の指紋（ファイルが変われば表示メモを作り直す）。
	 *
	 * @param array{frame:string,files:array<int,array<string,mixed>>}|null $set
	 */
	public static function fingerprint( ?array $set = null ): string {
		$set   = $set ?? self::collect();
		$parts = array( 'frame:' . $set['frame'] );
		foreach ( $set['files'] as $f ) {
			$parts[] = $f['path'] . ':' . $f['sha256'];
		}
		/**
		 * 試験用：テーマの変更を模擬する。
		 */
		return (string) apply_filters( 'wp_ai_case_display_fingerprint', hash( 'sha256', implode( "\n", $parts ) ) );
	}

	/** いま使っている施工事例のフレーム（ship-works-frame-switch がなければ ''）。 */
	public static function frame(): string {
		return function_exists( 'ship_works_frame_get' ) ? sanitize_key( (string) ship_works_frame_get() ) : '';
	}

	/**
	 * @return string[]
	 */
	private static function other_frames( string $frame ): array {
		$all = function_exists( 'ship_works_frame_choices' ) ? array_map( 'sanitize_key', array_keys( (array) ship_works_frame_choices() ) ) : array( 'gra', 'grb' );
		return array_values( array_filter( $all, static fn( $f ) => '' !== $f && $f !== $frame ) );
	}

	/**
	 * @return array<string,string> ラベル => ディレクトリ
	 */
	private static function dirs(): array {
		$out = array( 'theme' => get_template_directory() );
		if ( get_stylesheet_directory() !== get_template_directory() ) {
			$out['child'] = get_stylesheet_directory();
		}
		return $out;
	}

	/**
	 * @return string[]
	 */
	private static function walk( string $dir ): array {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				static fn( $f ) => ! ( $f->isDir() && in_array( $f->getFilename(), self::SKIP_DIRS, true ) )
			)
		);
		foreach ( $it as $file ) {
			if ( $file->isFile() && in_array( strtolower( $file->getExtension() ), array( 'php', 'scss' ), true ) ) {
				$out[] = $file->getPathname();
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * @param string[] $others
	 */
	private static function excluded( string $rel, array $others ): bool {
		if ( preg_match( '#(^|/)old_#i', $rel ) ) {
			return true;
		}
		foreach ( $others as $f ) {
			if ( preg_match( '#(^|[/_.\-])' . preg_quote( $f, '#' ) . '([/_.\-]|$)#i', $rel ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * get_template_part の呼び出し（文字列で書かれたもの）から、部品のファイル名の候補。
	 *
	 * @return string[]
	 */
	private static function parts( string $code ): array {
		$out = array();
		if ( preg_match_all( '#get_template_part\(\s*([\'"])([^\'"]+)\1\s*(?:,\s*([\'"])([^\'"]*)\3)?#', $code, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$slug = trim( $x[2], '/' );
				if ( isset( $x[4] ) && '' !== $x[4] ) {
					$out[] = $slug . '-' . $x[4] . '.php';
				}
				$out[] = $slug . '.php';
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * 施工事例の ACF の項目名のうち、ほかの用途と紛れにくいもの（_ を含む名前）。
	 *
	 * @return string[]
	 */
	public static function field_names(): array {
		$out  = array();
		$walk = static function ( array $nodes ) use ( &$walk, &$out ): void {
			foreach ( $nodes as $name => $node ) {
				if ( str_contains( (string) $name, '_' ) ) {
					$out[] = (string) $name;
				}
				$walk( (array) ( $node['children'] ?? array() ) );
				foreach ( (array) ( $node['layouts'] ?? array() ) as $layout => $children ) {
					$walk( (array) $children );
				}
			}
		};
		$walk( ShipWorksTemplateV11::logical_schema() );
		return array_values( array_unique( $out ) );
	}
}
