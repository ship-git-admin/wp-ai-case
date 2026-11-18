<?php
/**
 * 合成データ（フィクスチャ）の読み込みと解決（開発専用）。
 *
 * フィクスチャの "@img:<label>" は合成画像の attachment ID に、
 * "@voice" / "@staff" / "@supervisor" は関連付け先の投稿IDに置き換える。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev;

use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class Poc {

	public const MARK          = '_wp_ai_case_poc';
	public const IMAGES_OPTION = 'wp_ai_case_poc_images';

	/**
	 * @return array<string,int> label => attachment ID
	 */
	public static function images(): array {
		$map = get_option( self::IMAGES_OPTION, array() );
		return is_array( $map ) ? array_map( 'intval', $map ) : array();
	}

	/**
	 * @param array<string,int> $relations voice / staff / supervisor => post ID
	 * @return array<string,mixed>
	 */
	public static function load_fixture( string $name, array $relations ): array {
		if ( ! preg_match( '/^[a-z0-9_]+$/', $name ) ) {
			throw new CaseError( CaseError::INVALID_DOCUMENT, 'フィクスチャ名が不正です' );
		}
		$path = WP_AI_CASE_DIR . "/dev/fixtures/{$name}.json";
		if ( ! is_readable( $path ) ) {
			throw new CaseError( CaseError::INVALID_DOCUMENT, 'フィクスチャがありません', array( 'fixture' => $name ) );
		}
		$doc = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $doc ) ) {
			throw new CaseError( CaseError::INVALID_DOCUMENT, 'フィクスチャのJSONが不正です', array( 'fixture' => $name ) );
		}
		$images = self::images();
		$doc    = self::resolve( $doc, $images, $relations );
		$doc['document_id']       = wp_generate_uuid4();
		$doc['source_request_id'] = wp_generate_uuid4();
		return $doc;
	}

	/**
	 * 関連付け先の既定値（公開中の最初の投稿）。
	 *
	 * @return array<string,int>
	 */
	public static function default_relations(): array {
		$first = static function ( string $type ): int {
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
				)
			);
			return $ids ? (int) $ids[0] : 0;
		};
		$staff = $first( 'staff' );
		return array(
			'voice'      => $first( 'voice' ),
			'staff'      => $staff,
			'supervisor' => $staff,
		);
	}

	/**
	 * @return int[]
	 */
	public static function marked_posts( string $post_type ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => self::MARK, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	/**
	 * @param array<string,int> $images
	 * @param array<string,int> $relations
	 */
	private static function resolve( mixed $value, array $images, array $relations ): mixed {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::resolve( $v, $images, $relations );
			}
			return $value;
		}
		if ( is_string( $value ) && str_starts_with( $value, '@img:' ) ) {
			$label = substr( $value, 5 );
			if ( empty( $images[ $label ] ) ) {
				throw new CaseError( CaseError::MISSING_INPUT, '合成画像が未登録です（wp ai-case poc seed-images を先に実行）', array( 'label' => $label ) );
			}
			return $images[ $label ];
		}
		if ( is_string( $value ) && in_array( $value, array( '@voice', '@staff', '@supervisor' ), true ) ) {
			$id = (int) ( $relations[ substr( $value, 1 ) ] ?? 0 );
			if ( $id < 1 ) {
				throw new CaseError( CaseError::MISSING_INPUT, '関連付け先の投稿が見つかりません', array( 'relation' => $value ) );
			}
			return $id;
		}
		return $value;
	}
}
