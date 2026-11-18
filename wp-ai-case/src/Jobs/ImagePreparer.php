<?php
/**
 * 外部AIへ送る「解析用コピー」を作る。元の画像（メディアライブラリ）は変更しない。
 *
 * - 長辺 1568px 以下に縮小し、JPEG で書き出す。
 * - EXIF（撮影位置 GPS・機種・日時など）を含めない。書き出し後に確認し、残っていれば GD で再エンコードし、
 *   それでも残るなら送らずに止める（AI-008）。
 * - 一時ファイルは同じ処理の中で必ず削除する。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Jobs;

use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class ImagePreparer {

	public const MAX_EDGE = 1568;
	public const QUALITY  = 82;
	public const MAX_BYTES = 4 * 1024 * 1024;

	/**
	 * @return array{data:string,media_type:string,bytes:int}
	 */
	public static function prepare( int $attachment_id ): array {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_readable( $path ) ) {
			throw new CaseError( CaseError::INVALID_VALUE, '画像を読み込めません', array( 'post_id' => $attachment_id, 'reason' => 'image_unreadable' ) );
		}
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			throw new CaseError( CaseError::INVALID_VALUE, '画像を処理できません', array( 'post_id' => $attachment_id, 'reason' => 'image_editor' ) );
		}
		$size = $editor->get_size();
		if ( ( $size['width'] ?? 0 ) > self::MAX_EDGE || ( $size['height'] ?? 0 ) > self::MAX_EDGE ) {
			$editor->resize( self::MAX_EDGE, self::MAX_EDGE, false );
		}
		$editor->set_quality( self::QUALITY );

		$base = wp_tempnam( 'wpaic-img' );
		$tmp  = $base . '.jpg';
		try {
			$saved = $editor->save( $tmp, 'image/jpeg' );
			if ( is_wp_error( $saved ) || ! is_readable( $tmp ) ) {
				throw new CaseError( CaseError::INVALID_VALUE, '画像を書き出せません', array( 'post_id' => $attachment_id, 'reason' => 'image_save' ) );
			}
			if ( self::has_metadata( $tmp ) ) {
				self::reencode_with_gd( $tmp );
			}
			if ( self::has_metadata( $tmp ) ) {
				throw new CaseError( CaseError::INVALID_VALUE, '画像の位置情報などを取り除けないため送信しません', array( 'post_id' => $attachment_id, 'reason' => 'metadata_remains' ) );
			}
			$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( strlen( $bytes ) > self::MAX_BYTES ) {
				throw new CaseError( CaseError::INVALID_VALUE, '画像が大きすぎます', array( 'post_id' => $attachment_id, 'reason' => 'image_too_large' ) );
			}
			return array(
				'data'       => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'media_type' => 'image/jpeg',
				'bytes'      => strlen( $bytes ),
			);
		} finally {
			foreach ( array( $tmp, $base ) as $f ) {
				if ( is_string( $f ) && file_exists( $f ) ) {
					wp_delete_file( $f );
				}
			}
		}
	}

	/**
	 * EXIF（APP1 "Exif"）や XMP が残っているか。
	 */
	public static function has_metadata( string $file ): bool {
		$head = (string) file_get_contents( $file, false, null, 0, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( str_contains( $head, "Exif\x00\x00" ) || str_contains( $head, 'http://ns.adobe.com/xap/1.0/' ) ) {
			return true;
		}
		if ( function_exists( 'exif_read_data' ) ) {
			$exif = @exif_read_data( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $exif ) && ( isset( $exif['GPSLatitude'] ) || isset( $exif['Make'] ) || isset( $exif['DateTimeOriginal'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function reencode_with_gd( string $file ): void {
		if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
			return;
		}
		$img = @imagecreatefromjpeg( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $img ) {
			return;
		}
		imagejpeg( $img, $file, self::QUALITY );
		imagedestroy( $img );
	}
}
