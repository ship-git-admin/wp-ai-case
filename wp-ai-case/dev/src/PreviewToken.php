<?php
/**
 * 表示確認用の期限付きプレビュー（開発専用）。
 *
 * WordPress にログインしなくても、合成データの下書き（_wp_ai_case_poc=1）だけを
 * 期限付きトークンで表示できるようにする。投稿の状態は変更しない（表示時にメモリ上だけ扱いを変える）。
 * トークンはハッシュだけを保存し、期限切れ・別の投稿・本物の下書きには効かない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev;

defined( 'ABSPATH' ) || exit;

final class PreviewToken {

	public const PARAM = 'wpaic_preview';
	private const META = '_wp_ai_case_preview_token';

	public static function register(): void {
		add_filter( 'posts_results', array( self::class, 'filter_posts' ), 10, 2 );
	}

	public static function issue( int $post_id, int $ttl ): string {
		$token = bin2hex( random_bytes( 24 ) );
		update_post_meta(
			$post_id,
			self::META,
			array(
				'hash' => hash( 'sha256', $token ),
				'exp'  => time() + max( 60, min( $ttl, 7200 ) ),
			)
		);
		return $token;
	}

	public static function revoke( int $post_id ): void {
		delete_post_meta( $post_id, self::META );
	}

	/**
	 * @param \WP_Post[] $posts
	 * @return \WP_Post[]
	 */
	public static function filter_posts( array $posts, \WP_Query $query ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 読み取り専用の表示判定。
		$token = isset( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::PARAM ] ) ) : '';
		if ( '' === $token || is_admin() || ! $query->is_main_query() || 1 !== count( $posts ) ) {
			return $posts;
		}
		$post = $posts[0];
		if ( 'works' !== $post->post_type || 'draft' !== $post->post_status ) {
			return $posts;
		}
		if ( '1' !== (string) get_post_meta( $post->ID, '_wp_ai_case_poc', true ) ) {
			return $posts;
		}
		$record = get_post_meta( $post->ID, self::META, true );
		if ( ! is_array( $record ) || (int) ( $record['exp'] ?? 0 ) < time() || ! hash_equals( (string) ( $record['hash'] ?? '' ), hash( 'sha256', $token ) ) ) {
			return $posts;
		}
		$posts[0]->post_status = 'publish'; // このリクエストのメモリ上だけ。DBは draft のまま。
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'redirect_canonical', '__return_false' );
		nocache_headers();
		return $posts;
	}
}
