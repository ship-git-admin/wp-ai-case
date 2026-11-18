<?php
/**
 * ログ・診断に残す文字列から、秘密やサーバー情報を取り除く。
 *
 * 文面は ErrorCatalog の固定文を使うのが原則。ここは「念のための二重の防御」で、
 * 記録する補足情報（フィールド名・経路など）に紛れ込んだものを消す。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Diagnostics;

defined( 'ABSPATH' ) || exit;

final class Redactor {

	/** 記録してよい補足情報のキー。 */
	private const ALLOWED_KEYS = array( 'reason', 'code', 'state', 'classification', 'path', 'slot', 'field', 'fields', 'type', 'post_id', 'mapping_id', 'mapping_version', 'job_id', 'written', 'restored', 'http_status_class', 'provider_error_type', 'step', 'attempt', 'missing', 'extra', 'errors', 'kind', 'count', 'min', 'max', 'operator', 'layout' );

	public static function scrub( string $text ): string {
		$patterns = array(
			'/sk-ant-[A-Za-z0-9_\-]+/'                         => '[secret]',
			'/\b(?:Bearer|Basic)\s+[A-Za-z0-9+\/=._\-]+/i'     => '[credential]',
			'/x-api-key\s*[:=]\s*\S+/i'                        => 'x-api-key: [secret]',
			'/https?:\/\/[^\s"\'<>]+/i'                         => '[url]',
			'/\b(?:\d{1,3}\.){3}\d{1,3}\b/'                    => '[ip]',
			'#(?:/home|/var|/usr|/srv|/opt|/tmp|[A-Za-z]:\\\\)[^\s"\'<>]*#' => '[path]',
			'/\bPHP\s*\d+\.\d+(?:\.\d+)?/i'                    => 'PHP [version]',
		);
		$text = (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
		return mb_substr( $text, 0, 500 );
	}

	/**
	 * 補足情報を、許可したキーと安全な値だけにする。
	 *
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public static function context( array $context, int $depth = 0 ): array {
		$out = array();
		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = $depth < 3 ? self::context_list( $value, $depth + 1 ) : '[...]';
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$out[ $key ] = $value;
			} else {
				$out[ $key ] = self::scrub( (string) $value );
			}
		}
		return $out;
	}

	/**
	 * @param array<int|string,mixed> $value
	 * @return array<int|string,mixed>
	 */
	private static function context_list( array $value, int $depth ): array {
		if ( ! array_is_list( $value ) ) {
			return self::context( $value, $depth );
		}
		$out = array();
		foreach ( array_slice( $value, 0, 20 ) as $item ) {
			if ( is_array( $item ) ) {
				$out[] = $depth < 3 ? self::context_list( $item, $depth + 1 ) : '[...]';
			} elseif ( is_scalar( $item ) || null === $item ) {
				$out[] = is_string( $item ) ? self::scrub( $item ) : $item;
			}
		}
		return $out;
	}
}
