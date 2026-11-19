<?php
/**
 * REST：生成ジョブ（wp-ai-case/v1/jobs）。施工事例を編集できるログインユーザーだけ。他人のジョブは見えない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Rest;

use WpAiCase\Jobs\JobError;
use WpAiCase\Jobs\JobService;
use WpAiCase\Jobs\PatchApplier;
use WpAiCase\Plugin;
use WpAiCase\Diagnostics\PreflightService;

defined( 'ABSPATH' ) || exit;

final class JobController {

	private const UUID = '(?P<id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		$perm = array( self::class, 'can_use' );
		register_rest_route( RestController::NS, '/form-options', array( 'methods' => 'GET', 'callback' => array( self::class, 'form_options' ), 'permission_callback' => $perm ) );
		register_rest_route( RestController::NS, '/jobs', array( 'methods' => 'POST', 'callback' => array( self::class, 'create' ), 'permission_callback' => $perm ) );
		register_rest_route( RestController::NS, '/drafts', array( 'methods' => 'GET', 'callback' => array( self::class, 'drafts' ), 'permission_callback' => $perm ) );
		register_rest_route( RestController::NS, '/import', array( 'methods' => 'POST', 'callback' => array( self::class, 'import' ), 'permission_callback' => $perm ) );
		register_rest_route( RestController::NS, '/jobs/' . self::UUID, array( 'methods' => 'GET', 'callback' => array( self::class, 'show' ), 'permission_callback' => $perm ) );
		foreach ( array( 'tick', 'cancel', 'retry', 'revise', 'undo', 'redo', 'save', 'meta' ) as $action ) {
			register_rest_route( RestController::NS, '/jobs/' . self::UUID . '/' . $action, array( 'methods' => 'POST', 'callback' => array( self::class, $action ), 'permission_callback' => $perm ) );
		}
	}

	public static function can_use(): bool {
		$type = get_post_type_object( Plugin::POST_TYPE );
		return is_user_logged_in() && $type && current_user_can( $type->cap->edit_posts );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function form_options(): array {
		$list = static function ( string $type ): array {
			$out = array();
			foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
				// 画面は textContent で表示するので、HTML実体参照（&#8230; など）を文字に戻し、タグを除く。
				$out[] = array( 'id' => (int) $p->ID, 'title' => wp_strip_all_tags( html_entity_decode( get_the_title( $p ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			}
			// 同じ名前が複数あると画面で見分けられないので、重複する名前にだけ ID を添える。
			$count = array_count_values( array_column( $out, 'title' ) );
			foreach ( $out as $i => $o ) {
				if ( $count[ $o['title'] ] > 1 ) {
					$out[ $i ]['title'] = $o['title'] . '（ID: ' . $o['id'] . '）';
				}
			}
			return $out;
		};
		$pre = PreflightService::check( get_current_user_id() );
		return array(
			'available'  => $pre['ok'],
			'error'      => $pre['error'],
			'voices'     => $list( 'voice' ),
			'staff'      => $list( 'staff' ),
			'categories' => JobService::categories(),
			'sections'   => PatchApplier::SECTIONS,
			'quota'      => array_intersect_key( ( new \WpAiCase\Quota\QuotaService() )->summary(), array_flip( array( 'limit', 'remaining', 'resets_on' ) ) ),
		);
	}

	/**
	 * 修正できる下書きの一覧（この機能で保存した、自分が編集できる下書き）。
	 */
	public static function drafts(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'drafts' => JobService::list_drafts( get_current_user_id() ) ) );
	}

	/**
	 * 既存ページの貼り付けから入力欄の値を取り出す（下書きは作らない）。
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import( \WP_REST_Request $r ) {
		try {
			return new \WP_REST_Response( ( new JobService() )->import( (array) $r->get_json_params(), get_current_user_id() ) );
		} catch ( JobError $e ) {
			return new \WP_Error( 'wpaic_error', $e->response['message'] ?? '', array( 'status' => $e->status, 'error' => $e->response ) + $e->extra );
		}
	}

	public static function meta( \WP_REST_Request $r ) {
		$p    = (array) $r->get_json_params();
		$base = (int) ( $p['base_index'] ?? -1 );
		return self::run( static fn( JobService $s, int $u ) => $s->set_meta( (string) $r['id'], $u, $base, array_intersect_key( $p, array_flip( array( 'terms', 'thumbnail_id' ) ) ) ) );
	}

	public static function create( \WP_REST_Request $r ) {
		return self::run( static fn( JobService $s, int $u ) => $s->create( (array) $r->get_json_params(), $u ), 201 );
	}

	public static function show( \WP_REST_Request $r ) {
		return self::run( static fn( JobService $s, int $u ) => $s->get_owned( (string) $r['id'], $u ) );
	}

	public static function tick( \WP_REST_Request $r ) {
		return self::run( static fn( JobService $s, int $u ) => $s->tick( (string) $r['id'], $u ) );
	}

	public static function cancel( \WP_REST_Request $r ) {
		return self::run( static fn( JobService $s, int $u ) => $s->cancel( (string) $r['id'], $u ) );
	}

	public static function retry( \WP_REST_Request $r ) {
		return self::run( static fn( JobService $s, int $u ) => $s->retry( (string) $r['id'], $u ) );
	}

	public static function revise( \WP_REST_Request $r ) {
		$p       = (array) $r->get_json_params();
		$allowed = array_values( array_map( 'strval', (array) ( $p['allowed_sections'] ?? array() ) ) );
		$base    = (int) ( $p['base_index'] ?? -1 );
		if ( isset( $p['instruction'] ) ) {
			return self::run( static fn( JobService $s, int $u ) => $s->revise_ai( (string) $r['id'], $u, $base, $allowed, (string) $p['instruction'] ) );
		}
		return self::run( static fn( JobService $s, int $u ) => $s->revise_local( (string) $r['id'], $u, $base, $allowed, array_values( (array) ( $p['operations'] ?? array() ) ) ) );
	}

	public static function undo( \WP_REST_Request $r ) {
		$base = (int) ( ( (array) $r->get_json_params() )['base_index'] ?? -1 );
		return self::run( static fn( JobService $s, int $u ) => $s->move( (string) $r['id'], $u, $base, -1 ) );
	}

	public static function redo( \WP_REST_Request $r ) {
		$base = (int) ( ( (array) $r->get_json_params() )['base_index'] ?? -1 );
		return self::run( static fn( JobService $s, int $u ) => $s->move( (string) $r['id'], $u, $base, 1 ) );
	}

	public static function save( \WP_REST_Request $r ) {
		$base = (int) ( ( (array) $r->get_json_params() )['base_index'] ?? -1 );
		return self::run( static fn( JobService $s, int $u ) => $s->save( (string) $r['id'], $u, $base ) );
	}

	/**
	 * @param callable(JobService,int):array<string,mixed> $fn
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function run( callable $fn, int $ok_status = 200 ) {
		$user = get_current_user_id();
		try {
			$job = $fn( new JobService(), $user );
		} catch ( JobError $e ) {
			return new \WP_Error( 'wpaic_error', $e->response['message'] ?? '', array( 'status' => $e->status, 'error' => $e->response ) + $e->extra );
		}
		return new \WP_REST_Response( JobService::view( $job, $user ), $ok_status );
	}
}
