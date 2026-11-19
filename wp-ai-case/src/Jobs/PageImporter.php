<?php
/**
 * 既存の施工事例ページ（貼り付けた HTML または文章）の読み取りと、ページの画像のメディアへの取り込み。
 *
 * - parse()：スクリプト等を除き、改行を残した文章にする。画像の位置には「[写真n]」の印を入れる（AI が近くの見出しから工程を判断できるように）。
 *   同じ画像（縮小版・WebP・別ドメインの同じファイルを含む）は同じ番号にする。最大100枚。
 * - このサイトのメディアにある画像は、アップロード先のパスで見つける（外部通信なし）。
 * - それ以外の画像は sideload() で1枚ずつ取り込む（画面から1枚ずつ呼ぶ。1回の通信を短くするため）。
 *   http/https の公開アドレスだけ（wp_http_validate_url・wp_safe_remote_get）、20MB まで、JPEG/PNG/WebP、200×150 以上。
 *   縮小版の URL（-300x200）なら元の大きさを先に試す。同じ URL から取り込んだ画像があれば、それを使う（二重に登録しない）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Jobs;

use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class PageImporter {

	public const MAX_PHOTOS  = 100;
	public const MAX_TEXT    = 20000;
	public const MAX_BYTES   = 20971520;
	public const META_SOURCE = '_wp_ai_case_source_url';

	/**
	 * @return array{text:string,photos:array<int,array<string,mixed>>}
	 */
	public static function parse( string $html, int $user ): array {
		$h = (string) preg_replace( '#<(script|style|noscript|template|svg|iframe)\b[^>]*>.*?</\1\s*>#is', ' ', $html );
		$h = (string) preg_replace( '#<!--.*?-->#s', ' ', $h );
		$base = self::base_url( $html );

		// 画像を包むリンクが画像そのもの（ポップアップ表示用）なら、それを元の大きさの候補にする。
		$h = (string) preg_replace_callback(
			'#<a\b[^>]*?\bhref\s*=\s*(["\'])([^"\']+?\.(?:jpe?g|png|webp)(?:\?[^"\']*)?)\1[^>]*>(\s*<img\b)#i',
			static fn( $m ) => str_replace( $m[3], $m[3] . ' data-wpaic-full="' . esc_attr( $m[2] ) . '"', $m[0] ),
			$h
		);

		$photos = array();
		$keys   = array();
		$h      = (string) preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( $m ) use ( &$photos, &$keys, $base ) {
				$tag = $m[0];
				$url = self::absolute( self::candidate( $tag ), $base );
				if ( '' === $url ) {
					return ' ';
				}
				$w = (int) self::attr( $tag, 'width' );
				$hgt = (int) self::attr( $tag, 'height' );
				if ( ( $w && $w < 120 ) || ( $hgt && $hgt < 90 ) ) {
					return ' '; // アイコン・ボタン
				}
				$key = self::file_key( $url );
				if ( ! isset( $keys[ $key ] ) ) {
					if ( count( $photos ) >= self::MAX_PHOTOS ) {
						return ' ';
					}
					$photos[]     = array(
						'no'  => count( $photos ) + 1,
						'url' => $url,
						'alt' => ResultAssembler::line( html_entity_decode( self::attr( $tag, 'alt' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 60 ),
					);
					$keys[ $key ] = count( $photos );
				}
				$no  = $keys[ $key ];
				$alt = $photos[ $no - 1 ]['alt'];
				return "\n[写真{$no}]" . ( '' !== $alt ? '（' . $alt . '）' : '' ) . "\n";
			},
			$h
		);

		$h = (string) preg_replace( '#<br\s*/?>#i', "\n", $h );
		$h = (string) preg_replace( '#</(p|div|li|tr|h[1-6]|dt|dd|section|article|table|ul|ol|figure|figcaption|blockquote|header|footer|main|aside)\s*>#i', "\n", $h );
		$h = (string) preg_replace( '#<(td|th|dt|dd)\b[^>]*>#i', ' ', $h );
		$t = wp_strip_all_tags( $h, false );
		$t = html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$lines = array();
		foreach ( preg_split( '/\R/u', $t ) ?: array() as $line ) {
			$line = trim( (string) preg_replace( '/[\h\x{3000}]+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		foreach ( $photos as $i => $p ) {
			$id                            = self::local_attachment( $p['url'], $user );
			$photos[ $i ]['attachment_id'] = $id;
			$photos[ $i ]['thumb']         = $id ? (string) wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
		}
		return array(
			'text'   => mb_substr( implode( "\n", $lines ), 0, self::MAX_TEXT ),
			'photos' => $photos,
		);
	}

	/**
	 * このサイトのメディアにある画像（アップロード先のパスで照合。縮小版・WebP・-scaled・別ドメインでも同じファイル）。自分が編集できる画像だけ。
	 */
	public static function local_attachment( string $url, int $user ): int {
		global $wpdb;
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$pos  = strpos( $path, '/uploads/' );
		$id   = 0;
		if ( false !== $pos ) {
			$file = rawurldecode( substr( $path, $pos + 9 ) );
			$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $file ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $id ) {
				$base = self::strip_variant( $file );
				$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND ( meta_value LIKE %s OR meta_value LIKE %s ) ORDER BY post_id LIMIT 1", $wpdb->esc_like( $base ) . '.%', $wpdb->esc_like( $base ) . '-scaled.%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		if ( ! $id ) {
			// 以前に同じ URL から取り込んだ画像。
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id DESC LIMIT 1", self::META_SOURCE, esc_url_raw( $url ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( ! $id || 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) || ! user_can( $user, 'edit_post', $id ) ) {
			return 0;
		}
		return $id;
	}

	/**
	 * ページの画像を1枚、メディアに取り込む。
	 *
	 * @return array{attachment_id:int,thumb:string,reused:bool}
	 * @throws CaseError INVALID_VALUE（reason: url / download / not_image / too_small / too_large / save）
	 */
	public static function sideload( string $url, int $user, string $title = '' ): array {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		if ( '' === $url || strlen( $url ) > 2000 || ! wp_http_validate_url( $url ) ) {
			throw new CaseError( CaseError::INVALID_VALUE, '取り込めない画像のアドレスです', array( 'reason' => 'url' ) );
		}
		$have = self::local_attachment( $url, $user );
		if ( $have ) {
			return array( 'attachment_id' => $have, 'thumb' => (string) wp_get_attachment_image_url( $have, 'thumbnail' ), 'reused' => true );
		}
		$tries = array_values( array_unique( array_filter( array( self::original_size( $url ), $url ) ) ) );
		$body  = null;
		$code  = 0;
		foreach ( $tries as $try ) {
			$r = wp_safe_remote_get(
				$try,
				array(
					'timeout'             => 30,
					'redirection'         => 3,
					'limit_response_size' => self::MAX_BYTES + 1,
					'user-agent'          => 'WP AI Case importer',
				)
			);
			if ( is_wp_error( $r ) ) {
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			if ( 200 === $code && '' !== (string) wp_remote_retrieve_body( $r ) ) {
				$body = (string) wp_remote_retrieve_body( $r );
				break;
			}
		}
		if ( null === $body ) {
			throw new CaseError( CaseError::INVALID_VALUE, 401 === $code || 403 === $code ? '画像を取得できませんでした（認証が必要なページの画像です）' : '画像を取得できませんでした', array( 'reason' => 'download', 'status' => $code ) );
		}
		if ( strlen( $body ) > self::MAX_BYTES ) {
			throw new CaseError( CaseError::INVALID_VALUE, '画像が大きすぎます（20MBまで）', array( 'reason' => 'too_large' ) );
		}
		$info = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$type = is_array( $info ) ? (int) $info[2] : 0;
		if ( ! in_array( $type, array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) ) {
			throw new CaseError( CaseError::INVALID_VALUE, '写真（JPEG・PNG・WebP）ではありません', array( 'reason' => 'not_image' ) );
		}
		if ( (int) $info[0] < 200 || (int) $info[1] < 150 ) {
			throw new CaseError( CaseError::INVALID_VALUE, '小さすぎる画像です（アイコンなど）', array( 'reason' => 'too_small' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ext  = (string) image_type_to_extension( $type, false );
		$ext  = 'jpeg' === $ext ? 'jpg' : $ext;
		$name = sanitize_file_name( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME ) );
		$name = '' !== $name ? $name : 'imported-photo';
		$tmp  = wp_tempnam( $name );
		file_put_contents( $tmp, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$id = media_handle_sideload(
			array(
				'name'     => mb_substr( $name, 0, 80 ) . '.' . $ext,
				'tmp_name' => $tmp,
			),
			0,
			'' !== $title ? mb_substr( $title, 0, 100 ) : null
		);
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		if ( is_wp_error( $id ) ) {
			throw new CaseError( CaseError::INVALID_VALUE, '画像をメディアに登録できませんでした', array( 'reason' => 'save' ) );
		}
		update_post_meta( (int) $id, self::META_SOURCE, $url );
		return array( 'attachment_id' => (int) $id, 'thumb' => (string) wp_get_attachment_image_url( (int) $id, 'thumbnail' ), 'reused' => false );
	}

	/** 縮小版（-300x200）の URL なら、元の大きさの URL。違えば ''。 */
	public static function original_size( string $url ): string {
		$fixed = (string) preg_replace( '#-\d+x\d+(\.(?:jpe?g|png|webp))(\?.*)?$#i', '$1', $url );
		return $fixed !== $url ? $fixed : '';
	}

	/**
	 * img の候補：ポップアップのリンク → srcset の一番大きいもの → 遅延読み込みの属性 → src。
	 */
	private static function candidate( string $tag ): string {
		$full = self::attr( $tag, 'data-wpaic-full' );
		if ( '' !== $full ) {
			return $full;
		}
		foreach ( array( 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $a ) {
			$set = self::attr( $tag, $a );
			if ( '' === $set ) {
				continue;
			}
			$best = '';
			$max  = -1;
			foreach ( explode( ',', $set ) as $item ) {
				$parts = preg_split( '/\s+/', trim( $item ) ) ?: array();
				$size  = (int) ( $parts[1] ?? 0 );
				if ( '' !== ( $parts[0] ?? '' ) && $size > $max ) {
					$max  = $size;
					$best = $parts[0];
				}
			}
			if ( '' !== $best ) {
				return $best;
			}
		}
		foreach ( array( 'data-src', 'data-lazy-src', 'data-original', 'src' ) as $a ) {
			$v = self::attr( $tag, $a );
			if ( '' !== $v && ! str_starts_with( $v, 'data:' ) ) {
				return $v;
			}
		}
		return '';
	}

	private static function attr( string $tag, string $name ): string {
		if ( preg_match( '#\s' . preg_quote( $name, '#' ) . '\s*=\s*(["\'])(.*?)\1#is', $tag, $m ) ) {
			return trim( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		return '';
	}

	/** ページのアドレス（base・canonical・og:url）。相対パスの画像を絶対にするため。 */
	private static function base_url( string $html ): string {
		foreach ( array( '#<base\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1#i', '#<link\b[^>]*rel\s*=\s*(["\'])canonical\1[^>]*href\s*=\s*(["\'])(.*?)\2#i', '#<meta\b[^>]*property\s*=\s*(["\'])og:url\1[^>]*content\s*=\s*(["\'])(.*?)\2#i' ) as $re ) {
			if ( preg_match( $re, $html, $m ) ) {
				$u = end( $m );
				if ( preg_match( '#^https?://#i', (string) $u ) ) {
					return (string) $u;
				}
			}
		}
		return '';
	}

	private static function absolute( string $url, string $base ): string {
		$url = trim( $url );
		if ( '' === $url || str_starts_with( $url, 'data:' ) || preg_match( '#\.svg(\?|$)#i', $url ) ) {
			return '';
		}
		if ( str_starts_with( $url, '//' ) ) {
			return 'https:' . $url;
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		if ( '' === $base ) {
			// 相対パスでも、このサイトのメディアなら照合できるように、パスのまま残す。
			return str_starts_with( $url, '/' ) ? $url : '';
		}
		$p = wp_parse_url( $base );
		if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return '';
		}
		$origin = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		if ( str_starts_with( $url, '/' ) ) {
			return $origin . $url;
		}
		$dir = isset( $p['path'] ) ? preg_replace( '#/[^/]*$#', '/', $p['path'] ) : '/';
		return $origin . $dir . $url;
	}

	/** 同じ画像の見分け（縮小版・WebP・-scaled・ドメイン違いを同じとみなす）。 */
	private static function file_key( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$pos  = strpos( $path, '/uploads/' );
		return strtolower( self::strip_variant( false !== $pos ? substr( $path, $pos + 9 ) : $url ) );
	}

	private static function strip_variant( string $file ): string {
		$base = (string) preg_replace( '/\.webp$/i', '', $file );
		$base = (string) preg_replace( '/\.(jpe?g|png|gif|webp|avif)$/i', '', $base );
		return (string) preg_replace( '/-(\d+x\d+|scaled)$/i', '', $base );
	}
}
