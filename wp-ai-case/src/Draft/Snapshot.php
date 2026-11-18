<?php
/**
 * 保存前スナップショットと復元。
 *
 * ACF の値は postmeta に分散して入るため、DB トランザクションには頼らない。
 * 書き込み前に投稿のメタ（このプラグイン自身の管理用メタ・編集ロックを除く）を丸ごと控え、
 * 失敗時はその状態へ戻す。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Draft;

defined( 'ABSPATH' ) || exit;

final class Snapshot {

	private const META_KEY = '_wp_ai_case_snapshot';

	/**
	 * @return array{post_id:int,post_title:string,post_modified_gmt:string,meta:array<string,array<int,mixed>>,taken_at:string}
	 */
	public static function capture( int $post_id ): array {
		wp_cache_delete( $post_id, 'post_meta' );
		$meta = array();
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( self::excluded( (string) $key ) ) {
				continue;
			}
			$meta[ $key ] = array_map( 'maybe_unserialize', (array) $values );
		}
		ksort( $meta );
		$post = get_post( $post_id );
		return array(
			'post_id'           => $post_id,
			'post_title'        => $post ? (string) $post->post_title : '',
			'post_modified_gmt' => $post ? (string) $post->post_modified_gmt : '',
			'meta'              => $meta,
			'taken_at'          => gmdate( 'c' ),
		);
	}

	/**
	 * 復元用に最新1件を投稿メタへ保存する（手動復旧用）。
	 *
	 * @param array<string,mixed> $snapshot
	 */
	public static function persist( array $snapshot ): void {
		$json = wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return;
		}
		update_post_meta( (int) $snapshot['post_id'], self::META_KEY, wp_slash( base64_encode( gzcompress( $json, 6 ) ) ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function load_persisted( int $post_id ): ?array {
		$stored = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		$raw = base64_decode( $stored, true );
		$raw = false === $raw ? false : gzuncompress( $raw );
		if ( false === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * スナップショットの状態へ戻す。戻した後のメタが一致したかを返す。
	 *
	 * @param array<string,mixed> $snapshot
	 */
	public static function restore( array $snapshot ): bool {
		$post_id = (int) $snapshot['post_id'];
		wp_cache_delete( $post_id, 'post_meta' );

		$current = array();
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( ! self::excluded( (string) $key ) ) {
				$current[ $key ] = true;
			}
		}
		// 書き込み中に増えたキーを消す。
		foreach ( array_keys( $current ) as $key ) {
			if ( ! array_key_exists( $key, $snapshot['meta'] ) ) {
				delete_post_meta( $post_id, $key );
			}
		}
		// 元の値へ戻す。
		foreach ( $snapshot['meta'] as $key => $values ) {
			delete_post_meta( $post_id, $key );
			foreach ( (array) $values as $value ) {
				add_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}
		$post = get_post( $post_id );
		if ( $post && $post->post_title !== $snapshot['post_title'] ) {
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => wp_slash( (string) $snapshot['post_title'] ),
				)
			);
		}
		self::flush( $post_id );

		$after = self::capture( $post_id );
		return $after['meta'] == $snapshot['meta']; // phpcs:ignore Universal.Operators.StrictComparisons -- 値の型（int/string）の揺れを許容して比較する。
	}

	public static function flush( int $post_id ): void {
		clean_post_cache( $post_id );
		wp_cache_delete( $post_id, 'post_meta' );
		if ( function_exists( 'acf_get_store' ) ) {
			$store = acf_get_store( 'values' );
			if ( $store && method_exists( $store, 'reset' ) ) {
				$store->reset();
			}
		}
	}

	private static function excluded( string $key ): bool {
		return str_starts_with( $key, '_wp_ai_case_' ) || '_edit_lock' === $key || '_edit_last' === $key;
	}
}
