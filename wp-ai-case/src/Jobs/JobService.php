<?php
/**
 * 施工事例の生成ジョブ（作成 → 写真の解析・下書き → 修正 → 下書き保存）。
 *
 * - WP-Cron には頼らず、画面からの「進める」（tick）で1回に1つの外部呼び出しだけを行う。
 * - 処理の担当（lease）を取ってから進めるので、複数のタブで同時に進んでも二重に呼ばない。
 * - 外部へ送る直前に「送信中」を記録する。送信中のまま処理が途切れたら、結果が分からないので自動では
 *   再送せず「要確認（needs_recovery）」にする（二重課金・二重作成を防ぐ）。再送は利用者の明示操作だけ。
 * - 取消し・再試行で世代（job_generation）を上げ、古い呼び出しの結果は捨てる。
 * - 下書き保存は、開始時に記録したマッピングの版が今も有効で、ACF定義が同じときだけ。
 *
 * 既存の下書きの修正（mode=revise_draft）は、この機能で保存した下書きだけが対象。開いた時点の文書から始め、
 * 保存では前回から変わった部分だけを書く（人が編集画面で変えた部分に重なる修正は保存しない）。
 *
 * 新規作成枠：作成の開始時に予約し、新しい下書きの保存成功で確定、取消し・期限切れで戻す（QuotaService）。
 * 費用：外部呼び出しの前に推定費用で上限を確かめ（超えるなら呼ばない・AI-014）、呼んだ後に使用量を記録する（CostGuard）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Jobs;

use WpAiCase\Acf\SchemaInspector;
use WpAiCase\AI\ProviderFactory;
use WpAiCase\AI\ProviderInterface;
use WpAiCase\Diagnostics\ErrorClassifier;
use WpAiCase\Diagnostics\PreflightService;
use WpAiCase\Diagnostics\SafeErrorPresenter;
use WpAiCase\Diagnostics\Settings;
use WpAiCase\Domain\CaseDocumentValidator;
use WpAiCase\Draft\DraftState;
use WpAiCase\Draft\DraftWriter;
use WpAiCase\Mapping\MappingService;
use WpAiCase\Plugin;
use WpAiCase\Quota\CostGuard;
use WpAiCase\Quota\QuotaService;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class JobService {

	public const LEASE       = 200;
	public const CHUNK       = 20;
	public const HISTORY_MAX = 30;
	public const META_JOB    = '_wp_ai_case_job';

	private JobRepository $repo;
	private ?ProviderInterface $provider;
	private MappingService $mappings;
	private QuotaService $quota;
	private CostGuard $cost;

	public function __construct( ?JobRepository $repo = null, ?ProviderInterface $provider = null, ?MappingService $mappings = null ) {
		$this->repo     = $repo ?? new JobRepository();
		$this->provider = $provider;
		$this->mappings = $mappings ?? new MappingService();
		$this->quota    = new QuotaService();
		$this->cost     = new CostGuard();
	}

	public function repository(): JobRepository {
		return $this->repo;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public function create( array $raw, int $user ): array {
		if ( 'revise_draft' === ( $raw['mode'] ?? 'create' ) ) {
			return $this->open_draft( $raw, $user );
		}
		$pre = PreflightService::check( $user, true );
		if ( ! $pre['ok'] ) {
			throw new JobError( $pre['error'], 'AI-015' === $pre['code'] ? 403 : 503 );
		}
		try {
			$input = CaseInputValidator::validate( $raw, $user );
		} catch ( CaseError $e ) {
			throw JobError::from_case( $e, 'input_invalid', 400 );
		}
		$existing = $this->repo->by_idempotency( $input['idempotency_key'] );
		if ( $existing ) {
			if ( (int) $existing['user_id'] !== $user ) {
				throw JobError::code( 'AI-012', 'idempotency_conflict', 409 );
			}
			return $existing;
		}
		$inspector = new SchemaInspector();
		try {
			$mapping = $this->mappings->gate( $inspector->context( Plugin::POST_TYPE, 0, array() ) );
		} catch ( CaseError $e ) {
			throw JobError::from_case( $e, 'mapping_not_ready', 503 );
		}
		// 新規作成枠の予約（ここまでの確認で止まった場合は枠を使わない）。
		try {
			$this->quota->reserve( $input['idempotency_key'], $user );
		} catch ( CaseError $e ) {
			throw JobError::from_case( $e, 'quota_' . $e->reason, CaseError::QUOTA_EXCEEDED === $e->reason ? 429 : 503 );
		}
		try {
			$job = $this->insert_create_job( $input, $user, $mapping );
		} catch ( \Throwable $e ) {
			$this->quota->release( $input['idempotency_key'] );
			throw $e;
		}
		$this->quota->attach_job( $input['idempotency_key'], (string) $job['job_id'] );
		return $job;
	}

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $mapping
	 * @return array<string,mixed>
	 */
	private function insert_create_job( array $input, int $user, array $mapping ): array {
		return $this->repo->insert(
			$input['idempotency_key'],
			$user,
			'create',
			array(
				'input'     => $input,
				'snapshot'  => array(
					'mapping_id'      => (string) $mapping['mapping_id'],
					'mapping_version' => (int) $mapping['mapping_version'],
					'schema_sha256'   => (string) $mapping['schema_sha256'],
					'context_hash'    => (string) $mapping['context_hash'],
				),
				'step'      => 0,
				'analysis'  => array(),
				'versions'  => array(),
				'index'     => -1,
				'provider'  => array( 'state' => 'none' ),
				'questions' => array(),
				'usage'     => array( 'input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0 ),
				'error'     => null,
			)
		);
	}

	/**
	 * 既存の下書きを修正のために開く（外部への通信なし）。開いた時点の文書が最初の版になる。
	 *
	 * @param array<string,mixed> $raw case_input（mode=revise_draft, post_id, base_version）
	 * @return array<string,mixed>
	 */
	public function open_draft( array $raw, int $user ): array {
		$key     = (string) ( $raw['idempotency_key'] ?? '' );
		$post_id = (int) ( $raw['post_id'] ?? 0 );
		$base    = (int) ( $raw['base_version'] ?? 0 );
		if ( ! wp_is_uuid( $key, 4 ) || 'works' !== ( $raw['post_type'] ?? 'works' ) || $post_id < 1 || $base < 1 ) {
			throw JobError::code( 'AI-017', 'revise_input_invalid', 400 );
		}
		if ( true !== ( $raw['consent_external_ai'] ?? null ) ) {
			throw new JobError( SafeErrorPresenter::present( 'AI-017', 'customer', 'revise_consent_missing' ), 400, array( 'fields' => array( 'consent_external_ai' ) ) );
		}
		$existing = $this->repo->by_idempotency( $key );
		if ( $existing ) {
			if ( (int) $existing['user_id'] !== $user ) {
				throw JobError::code( 'AI-012', 'idempotency_conflict', 409 );
			}
			return $existing;
		}
		$post = get_post( $post_id );
		if ( ! $post || Plugin::POST_TYPE !== $post->post_type || 'draft' !== $post->post_status || ! user_can( $user, 'edit_post', $post_id ) ) {
			throw JobError::code( 'AI-012', 'revise_not_draft', 409 );
		}
		$saved = DraftState::load( $post_id );
		if ( ! $saved ) {
			throw JobError::code( 'AI-012', 'revise_not_ai_draft', 409 );
		}
		if ( DraftState::version( $post_id ) !== $base ) {
			throw JobError::code( 'AI-012', 'revise_stale_version', 409 );
		}
		try {
			( new CaseDocumentValidator() )->validate( $saved['doc'] );
		} catch ( CaseError $e ) {
			throw JobError::code( 'AI-016', 'revise_document_invalid', 409 );
		}
		$images  = array();
		$missing = array();
		foreach ( $saved['doc']['images'] as $img ) {
			$id = (int) $img['attachment_id'];
			if ( get_post_type( $id ) !== 'attachment' || ! wp_attachment_is_image( $id ) ) {
				$missing[] = '写真（ID: ' . $id . '）がメディアから削除されています';
				continue;
			}
			$images[] = array(
				'attachment_id'     => $id,
				'original_position' => (int) $img['original_position'],
				'caption'           => (string) ( $img['caption'] ?? '' ),
				'user_phase_hint'   => (string) $img['phase'],
			);
		}
		if ( $missing ) {
			throw new JobError( SafeErrorPresenter::present( 'AI-017', 'customer', 'revise_image_missing', array( 'post_id' => $post_id ) ), 409, array( 'fields' => $missing ) );
		}
		$inspector = new SchemaInspector();
		$context   = $inspector->context( Plugin::POST_TYPE, $post_id );
		try {
			$mapping = $this->mappings->gate( $context );
		} catch ( CaseError $e ) {
			throw JobError::from_case( $e, 'mapping_not_ready', 503 );
		}
		$edited = DraftState::edited_slots( $post_id, $saved['state'], $mapping, $inspector->groups( $context ) );
		return $this->repo->insert(
			$key,
			$user,
			'revise_draft',
			array(
				'input'     => array(
					'mode'                => 'revise_draft',
					'post_id'             => $post_id,
					'base_version'        => $base,
					'images'              => $images,
					'consent_external_ai' => true,
				),
				'snapshot'  => array(
					'mapping_id'        => (string) $mapping['mapping_id'],
					'mapping_version'   => (int) $mapping['mapping_version'],
					'schema_sha256'     => (string) $mapping['schema_sha256'],
					'context_hash'      => (string) $mapping['context_hash'],
					'post_modified_gmt' => (string) $post->post_modified_gmt,
					'doc_version'       => $base,
				),
				'target'    => array(
					'title'         => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'edited'        => DraftState::labels( $edited ),
					'was_published' => '0000-00-00 00:00:00' !== (string) $post->post_date_gmt,
				),
				'step'      => 0,
				'analysis'  => array(),
				'versions'  => array( $saved['doc'] ),
				'index'     => 0,
				'provider'  => array( 'state' => 'none' ),
				'questions' => array(),
				'usage'     => array( 'input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0 ),
				'error'     => null,
			),
			'proposal_ready',
			$post_id
		);
	}

	/**
	 * 修正できる下書き（この機能で保存した、自分が編集できる施工事例の下書き）。新しい順に最大50件。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_drafts( int $user ): array {
		$ids = get_posts(
			array(
				'post_type'      => Plugin::POST_TYPE,
				'post_status'    => 'draft',
				'posts_per_page' => 50,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_key'       => DraftState::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$post = get_post( $id );
			if ( ! $post || ! user_can( $user, 'edit_post', $id ) ) {
				continue;
			}
			$saved = DraftState::load( $id );
			if ( ! $saved ) {
				continue;
			}
			$out[] = array(
				'post_id'        => $id,
				'title'          => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'modified'       => (string) get_post_modified_time( 'Y-m-d H:i', false, $post ),
				'version'        => DraftState::version( $id ),
				'edited_in_editor' => (string) $post->post_modified_gmt !== (string) ( $saved['state']['post_modified_gmt'] ?? '' ),
				'was_published'  => '0000-00-00 00:00:00' !== (string) $post->post_date_gmt,
				'edit_url'       => (string) get_edit_post_link( $id, 'raw' ),
			);
		}
		return $out;
	}

	/**
	 * 自分のジョブだけ（管理者は全員分）。期限切れはここで「期限切れ」にする。
	 *
	 * @return array<string,mixed>
	 */
	public function get_owned( string $job_id, int $user ): array {
		$job = $this->repo->get( $job_id );
		if ( ! $job || ( (int) $job['user_id'] !== $user && ! user_can( $user, 'manage_options' ) ) ) {
			throw JobError::code( 'AI-018', 'job_not_found', 404 );
		}
		if ( ! in_array( $job['status'], array( 'committed', 'cancelled', 'expired', 'saving' ), true ) && strtotime( $job['expires_at'] . ' UTC' ) < time() ) {
			try {
				$job = $this->repo->compare_and_set( $job, array( 'status' => 'expired', 'job_generation' => $job['job_generation'] + 1, 'lease_until' => null ) );
				$this->quota->release( (string) $job['idempotency_key'] );
			} catch ( CaseError $e ) {
				$job = $this->repo->get( $job_id );
			}
		}
		return $job;
	}

	/**
	 * 1ステップ進める（外部呼び出しは最大1回）。
	 *
	 * @return array<string,mixed>
	 */
	public function tick( string $job_id, int $user ): array {
		$job = $this->get_owned( $job_id, $user );
		if ( ! in_array( $job['status'], array( 'queued', 'analyzing' ), true ) ) {
			return $job;
		}
		if ( ! $this->repo->acquire_lease( $job, self::LEASE ) ) {
			return $this->repo->get( $job_id );
		}
		$job  = $this->repo->get( $job_id );
		$data = $job['data'];

		if ( 'sending' === ( $data['provider']['state'] ?? '' ) ) {
			// 前回の送信の結果が分からない（処理が途中で止まった）。自動では再送しない。
			$data['provider']['state'] = 'unknown';
			$data['error']             = SafeErrorPresenter::present( 'AI-011', 'customer', 'provider_result_unknown', array( 'job_id' => $job_id, 'step' => (int) $data['step'] ) );
			return $this->repo->compare_and_set( $job, array( 'status' => 'needs_recovery', 'data' => $data, 'lease_until' => null ) );
		}

		$plan = self::plan( count( $data['input']['images'] ) );
		$kind = $plan[ (int) $data['step'] ] ?? 'full';
		try {
			$payload = $this->payload( $kind, $data );
		} catch ( CaseError $e ) {
			$data['error'] = SafeErrorPresenter::present( 'AI-008', 'customer', 'image_prepare_failed', array( 'reason' => $e->reason ) + $e->context );
			return $this->repo->compare_and_set( $job, array( 'status' => 'failed', 'data' => $data, 'lease_until' => null ) );
		}

		// 費用の上限（推定）。超えるなら送らずに止める（上限を見直した後、同じ画面から再試行できる）。
		$estimate = CostGuard::estimate( (string) $payload['model'], count( $payload['images'] ), self::text_bytes( $payload ) );
		try {
			$this->cost->check( 'full' === $kind ? 'create' : ( 'compose' === $kind ? 'compose' : 'analyze' ), $estimate['usd'] );
		} catch ( CaseError $e ) {
			$data['error'] = SafeErrorPresenter::from_case_error( $e, 'customer', 'cost_guard' );
			return $this->repo->compare_and_set( $job, array( 'status' => 'failed', 'data' => $data, 'lease_until' => null ) );
		}

		$data['provider'] = array( 'state' => 'sending', 'attempt' => wp_generate_uuid4(), 'started_at' => time(), 'step' => (int) $data['step'] );
		$data['error']    = null;
		$job              = $this->repo->compare_and_set( $job, array( 'status' => 'analyzing', 'data' => $data ) );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::LEASE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$result = $this->provider()->compose( $payload );
		$this->cost->record( 'full' === $kind ? 'create' : ( 'compose' === $kind ? 'compose' : 'analyze' ), (string) $payload['model'], $job_id, count( $payload['images'] ), (array) ( $result->data['usage'] ?? array() ), $result->ok ? 'ok' : (string) ( $result->error_type ?? 'error' ) );

		// 呼び出し中に取消し・再試行があれば、この結果は捨てる。
		$fresh = $this->repo->get( $job_id );
		if ( ! $fresh || (int) $fresh['job_generation'] !== (int) $job['job_generation'] || 'analyzing' !== $fresh['status'] ) {
			return $fresh ?? $job;
		}
		$data                       = $fresh['data'];
		$data['provider']['state']  = 'done';
		$data['usage']['calls']    += 1;
		$data['usage']['input_tokens']  += (int) ( $result->data['usage']['input_tokens'] ?? 0 );
		$data['usage']['output_tokens'] += (int) ( $result->data['usage']['output_tokens'] ?? 0 );

		if ( ! $result->ok ) {
			$class         = ErrorClassifier::from_provider( $result ) ?? array( 'code' => 'AI-018', 'http_class' => 'unknown' );
			$data['error'] = SafeErrorPresenter::present( $class['code'], 'customer', 'provider_error', array( 'job_id' => $job_id, 'provider_error_type' => (string) $result->error_type ), $class['http_class'] );
			return $this->repo->compare_and_set( $fresh, array( 'status' => 'failed', 'data' => $data, 'lease_until' => null ) );
		}

		$content = (array) ( $result->data['content'] ?? array() );
		try {
			if ( 'full' === $kind || 'compose' === $kind ) {
				$analysis = 'full' === $kind ? (array) ( $content['images'] ?? array() ) : $data['analysis'];
				$doc      = ResultAssembler::build( $data['input'], $analysis, (array) ( $content['article'] ?? array() ), $job_id );
				$data['versions']  = array( $doc );
				$data['index']     = 0;
				$data['questions'] = array_slice( array_values( array_filter( array_map( static fn( $q ) => ResultAssembler::line( (string) $q, 200 ), (array) ( $content['questions'] ?? array() ) ) ) ), 0, 10 );
				return $this->repo->compare_and_set( $fresh, array( 'status' => 'proposal_ready', 'data' => $data, 'lease_until' => null ) );
			}
			// 写真が多いときの分割解析：この束の写真だけが返っていること。
			$chunk_ids = array_map( static fn( $i ) => (int) $i['attachment_id'], $payload['images'] );
			$got       = array();
			foreach ( (array) ( $content['images'] ?? array() ) as $a ) {
				$id = (int) ( $a['attachment_id'] ?? 0 );
				if ( ! in_array( $id, $chunk_ids, true ) || isset( $got[ $id ] ) ) {
					throw new CaseError( CaseError::INVALID_DOCUMENT, 'AIの結果の写真が依頼と一致しません', array( 'reason' => 'image_set_mismatch' ) );
				}
				$got[ $id ] = $a;
			}
			if ( count( $got ) !== count( $chunk_ids ) ) {
				throw new CaseError( CaseError::INVALID_DOCUMENT, 'AIの結果で一部の写真が解析されていません', array( 'reason' => 'image_set_mismatch' ) );
			}
			$data['analysis'] = array_merge( $data['analysis'], array_values( $got ) );
			$data['step']     = (int) $data['step'] + 1;
			return $this->repo->compare_and_set( $fresh, array( 'status' => 'analyzing', 'data' => $data, 'lease_until' => null ) );
		} catch ( CaseError $e ) {
			$data['error'] = SafeErrorPresenter::present( 'AI-009', 'customer', 'result_invalid', array( 'job_id' => $job_id, 'reason' => $e->reason ) + $e->context );
			return $this->repo->compare_and_set( $fresh, array( 'status' => 'failed', 'data' => $data, 'lease_until' => null ) );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function cancel( string $job_id, int $user ): array {
		$job = $this->get_owned( $job_id, $user );
		if ( ! in_array( $job['status'], array( 'queued', 'analyzing', 'proposal_ready', 'failed', 'needs_recovery' ), true ) ) {
			throw JobError::code( 'AI-012', 'cancel_not_allowed', 409 );
		}
		$job = $this->repo->compare_and_set( $job, array( 'status' => 'cancelled', 'job_generation' => $job['job_generation'] + 1, 'lease_until' => null ) );
		$this->quota->release( (string) $job['idempotency_key'] );
		return $job;
	}

	/**
	 * 利用者の明示操作による再試行（設定を直した後など）。入力・写真はそのまま使う。
	 *
	 * @return array<string,mixed>
	 */
	public function retry( string $job_id, int $user ): array {
		$job = $this->get_owned( $job_id, $user );
		if ( ! in_array( $job['status'], array( 'failed', 'needs_recovery' ), true ) ) {
			throw JobError::code( 'AI-012', 'retry_not_allowed', 409 );
		}
		$pre = PreflightService::check( $user, true );
		if ( ! $pre['ok'] ) {
			throw new JobError( $pre['error'], 503 );
		}
		$data             = $job['data'];
		$data['provider'] = array( 'state' => 'none' );
		$data['error']    = null;
		return $this->repo->compare_and_set( $job, array( 'status' => 'queued', 'job_generation' => $job['job_generation'] + 1, 'data' => $data, 'lease_until' => null ) );
	}

	/**
	 * 画面での修正（AIを使わない）。
	 *
	 * @param string[]                       $allowed
	 * @param array<int,array<string,mixed>> $ops
	 * @return array<string,mixed>
	 */
	public function revise_local( string $job_id, int $user, int $base_index, array $allowed, array $ops ): array {
		$job = $this->editable( $job_id, $user, $base_index );
		try {
			$r = PatchApplier::apply( $job['data']['versions'][ $base_index ], $allowed, $ops, false );
		} catch ( CaseError $e ) {
			throw JobError::from_case( $e, 'revise_rejected', 400 );
		}
		return $this->push_version( $job, $r['doc'], $r['changed'], 'manual' );
	}

	/**
	 * AI による修正（選んだ部分だけ）。
	 *
	 * @param string[] $allowed
	 * @return array<string,mixed>
	 */
	public function revise_ai( string $job_id, int $user, int $base_index, array $allowed, string $instruction ): array {
		$job         = $this->editable( $job_id, $user, $base_index );
		$instruction = ResultAssembler::text( $instruction, 1000 );
		if ( '' === $instruction ) {
			throw JobError::code( 'AI-017', 'instruction_missing', 400 );
		}
		$pre = PreflightService::check( $user, true );
		if ( ! $pre['ok'] ) {
			throw new JobError( $pre['error'], 503 );
		}
		$doc     = $job['data']['versions'][ $base_index ];
		$payload = array(
			'model'            => Settings::model(),
			'document'         => $doc,
			'allowed_sections' => $allowed,
			'instruction'      => $instruction,
		);
		try {
			$this->cost->check( 'revise', CostGuard::estimate( Settings::model(), 0, strlen( (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) ) )['usd'] );
		} catch ( CaseError $e ) {
			throw JobError::from_case( $e, 'cost_guard', 429 );
		}
		$result = $this->provider()->revise( $payload );
		$this->cost->record( 'revise', Settings::model(), $job_id, 0, (array) ( $result->data['usage'] ?? array() ), $result->ok ? 'ok' : (string) ( $result->error_type ?? 'error' ) );
		$fresh = $this->repo->get( $job_id );
		if ( ! $fresh || (int) $fresh['job_generation'] !== (int) $job['job_generation'] || 'proposal_ready' !== $fresh['status'] || (int) $fresh['data']['index'] !== $base_index ) {
			throw JobError::code( 'AI-012', 'revise_stale', 409 );
		}
		if ( ! $result->ok ) {
			$class = ErrorClassifier::from_provider( $result ) ?? array( 'code' => 'AI-018', 'http_class' => 'unknown' );
			throw new JobError( SafeErrorPresenter::present( $class['code'], 'customer', 'revise_provider_error', array( 'job_id' => $job_id, 'provider_error_type' => (string) $result->error_type ), $class['http_class'] ), 502 );
		}
		try {
			$r = PatchApplier::apply( $doc, $allowed, (array) ( $result->data['content']['operations'] ?? array() ), true );
		} catch ( CaseError $e ) {
			throw new JobError( SafeErrorPresenter::present( 'AI-009', 'customer', 'revise_output_rejected', array( 'job_id' => $job_id, 'reason' => $e->reason ) ), 422 );
		}
		$fresh['data']['usage']['calls']         += 1;
		$fresh['data']['usage']['input_tokens']  += (int) ( $result->data['usage']['input_tokens'] ?? 0 );
		$fresh['data']['usage']['output_tokens'] += (int) ( $result->data['usage']['output_tokens'] ?? 0 );
		return $this->push_version( $fresh, $r['doc'], $r['changed'], 'ai' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function move( string $job_id, int $user, int $base_index, int $delta ): array {
		$job    = $this->editable( $job_id, $user, $base_index );
		$target = $base_index + $delta;
		if ( $target < 0 || $target >= count( $job['data']['versions'] ) ) {
			throw JobError::code( 'AI-012', 'history_out_of_range', 409 );
		}
		$data          = $job['data'];
		$data['index'] = $target;
		return $this->repo->compare_and_set( $job, array( 'data' => $data ) );
	}

	/**
	 * 今の案を施工事例の下書きとして保存する。
	 *
	 * @return array<string,mixed>
	 */
	public function save( string $job_id, int $user, int $base_index ): array {
		$job     = $this->editable( $job_id, $user, $base_index );
		$job     = $this->repo->compare_and_set( $job, array( 'status' => 'saving' ) );
		$data    = $job['data'];
		$doc     = $data['versions'][ $base_index ];
		$mapping = $this->mappings->repository()->get( (string) $data['snapshot']['mapping_id'], (int) $data['snapshot']['mapping_version'] );
		$revise  = 'revise_draft' === $job['mode'];
		try {
			if ( ! $mapping || 'approved_active' !== $mapping['status'] ) {
				throw new CaseError( CaseError::MAPPING_NOT_READY, '生成を始めた後にマッピングが変わりました' );
			}
			if ( $revise ) {
				$result = $this->save_revision( $data, $doc, $user, $mapping );
			} else {
				$result = ( new DraftWriter() )->create_from_document(
					$doc,
					$user,
					array(
						'mapping'    => $mapping,
						'extra_meta' => array( self::META_JOB => $job_id ),
					)
				);
				$inspector = new SchemaInspector();
				DraftState::record( (int) $result['post_id'], $doc, $mapping, $inspector->groups( $inspector->context( Plugin::POST_TYPE, (int) $result['post_id'] ) ), 1, $user );
			}
		} catch ( CaseError $e ) {
			$data['error'] = SafeErrorPresenter::from_case_error( $e, 'customer', 'save_failed' );
			$this->repo->compare_and_set( $job, array( 'status' => 'proposal_ready', 'data' => $data, 'post_id' => $revise ? (int) $data['input']['post_id'] : ( isset( $e->context['post_id'] ) ? (int) $e->context['post_id'] : null ) ) );
			$fields = CaseError::EDITED_BY_HAND === $e->reason ? array( 'fields' => array_values( array_map( 'strval', (array) ( $e->context['fields'] ?? array() ) ) ) ) : array();
			throw new JobError( $data['error'], in_array( $e->reason, array( CaseError::MAPPING_NOT_READY, CaseError::SCHEMA_CHANGED ), true ) ? 503 : 409, $fields );
		}
		$data['error']         = null;
		$data['saved_changes'] = $revise ? DraftState::labels( (array) ( $result['changed_slots'] ?? array() ) ) : null;
		if ( ! $revise ) {
			$this->quota->commit( (string) $job['idempotency_key'], (int) $result['post_id'] );
		}
		return $this->repo->compare_and_set( $job, array( 'status' => 'committed', 'post_id' => (int) $result['post_id'], 'data' => $data ) );
	}

	/**
	 * 既存の下書きへの保存。版を先に1つ進めて（同時に2つの画面から保存されても後の方は止まる）、
	 * 変わった部分だけを書き、保存後の状態を記録する。
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $doc
	 * @param array<string,mixed> $mapping
	 * @return array<string,mixed>
	 */
	private function save_revision( array $data, array $doc, int $user, array $mapping ): array {
		$post_id = (int) $data['input']['post_id'];
		$from    = (int) $data['snapshot']['doc_version'];
		$saved   = DraftState::load( $post_id );
		if ( ! $saved || ! DraftState::claim( $post_id, $from ) ) {
			throw new CaseError( CaseError::STALE_BASE, '別の画面での修正が先に保存されました', array( 'post_id' => $post_id ) );
		}
		try {
			$result = ( new DraftWriter() )->revise_document( $post_id, $doc, $user, (string) $data['snapshot']['post_modified_gmt'], $mapping, $saved['state'] );
		} catch ( \Throwable $e ) {
			DraftState::release( $post_id, $from );
			throw $e;
		}
		if ( ! $result['changed_slots'] ) {
			DraftState::release( $post_id, $from );
			return $result;
		}
		$inspector = new SchemaInspector();
		DraftState::record( $post_id, $doc, $mapping, $inspector->groups( $inspector->context( Plugin::POST_TYPE, $post_id ) ), $from + 1, $user );
		return $result;
	}

	/**
	 * 画面に返す形（顧客向けに安全な項目だけ）。
	 *
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public static function view( array $job, int $user ): array {
		$data  = $job['data'];
		$index = (int) ( $data['index'] ?? -1 );
		$doc   = $index >= 0 ? ( $data['versions'][ $index ] ?? null ) : null;
		$imgs  = array();
		foreach ( (array) ( $data['input']['images'] ?? array() ) as $img ) {
			$id          = (int) $img['attachment_id'];
			$imgs[ $id ] = array(
				'thumb'  => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
				'medium' => (string) wp_get_attachment_image_url( $id, 'medium' ),
			);
		}
		$plan = self::plan( count( (array) ( $data['input']['images'] ?? array() ) ) );
		$post = $job['post_id'] ? (int) $job['post_id'] : 0;
		return array(
			'job_id'          => $job['job_id'],
			'idempotency_key' => $job['idempotency_key'],
			'status'          => $job['status'],
			'generation'      => (int) $job['job_generation'],
			'progress'        => array( 'step' => min( (int) ( $data['step'] ?? 0 ), count( $plan ) ), 'total' => count( $plan ) ),
			'error'           => $data['error'] ?? null,
			'questions'       => $data['questions'] ?? array(),
			'version_index'   => $index,
			'version_count'   => count( (array) ( $data['versions'] ?? array() ) ),
			'proposal'        => $doc,
			'images'          => $imgs,
			'input'           => $data['input'] ?? null,
			'post_id'         => $post ?: null,
			'mode'            => (string) $job['mode'],
			'target'          => $data['target'] ?? null,
			'saved_changes'   => $data['saved_changes'] ?? null,
			'edit_url'        => $post && user_can( $user, 'edit_post', $post ) ? (string) get_edit_post_link( $post, 'raw' ) : null,
			'preview_url'     => $post ? (string) get_preview_post_link( $post ) : null,
			'expires_at'      => $job['expires_at'],
		);
	}

	/**
	 * @return string[] 1ステップ＝外部呼び出し1回
	 */
	public static function plan( int $images ): array {
		if ( $images <= self::CHUNK ) {
			return array( 'full' );
		}
		$steps = array();
		for ( $i = 0; $i < (int) ceil( $images / self::CHUNK ); $i++ ) {
			$steps[] = 'chunk:' . $i;
		}
		$steps[] = 'compose';
		return $steps;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function payload( string $kind, array $data ): array {
		$images = $data['input']['images'];
		$mode   = 'full';
		if ( str_starts_with( $kind, 'chunk:' ) ) {
			$images = array_slice( $images, (int) substr( $kind, 6 ) * self::CHUNK, self::CHUNK );
			$mode   = 'analyze_only';
		} elseif ( 'compose' === $kind ) {
			$mode = 'compose_text_only';
		}
		$with_data = 'compose_text_only' !== $mode && 'anthropic' === $this->provider()->id();
		$list      = array();
		foreach ( $images as $img ) {
			$item = array(
				'attachment_id' => (int) $img['attachment_id'],
				'position'      => (int) $img['original_position'],
				'caption'       => (string) $img['caption'],
				'phase_hint'    => (string) $img['user_phase_hint'],
			);
			if ( $with_data ) {
				$prepared           = ImagePreparer::prepare( (int) $img['attachment_id'] );
				$item['data']       = $prepared['data'];
				$item['media_type'] = $prepared['media_type'];
			}
			$list[] = $item;
		}
		return array(
			'model'                 => Settings::model(),
			'mode'                  => $mode,
			'facts'                 => $data['input']['primary_facts'],
			'photo_set_description' => $data['input']['photo_set_description'],
			'images'                => $list,
			'prior_analysis'        => 'compose_text_only' === $mode ? $data['analysis'] : null,
		);
	}

	/**
	 * 修正・保存できる状態か（提案があり、画面が見ている版が最新か）。
	 *
	 * @return array<string,mixed>
	 */
	private function editable( string $job_id, int $user, int $base_index ): array {
		$job = $this->get_owned( $job_id, $user );
		if ( 'proposal_ready' !== $job['status'] ) {
			throw JobError::code( 'AI-012', 'not_editable', 409 );
		}
		if ( (int) $job['data']['index'] !== $base_index || ! isset( $job['data']['versions'][ $base_index ] ) ) {
			throw JobError::code( 'AI-012', 'stale_version', 409 );
		}
		return $job;
	}

	/**
	 * @param array<string,mixed> $job
	 * @param array<string,mixed> $doc
	 * @param string[]            $changed
	 * @return array<string,mixed>
	 */
	private function push_version( array $job, array $doc, array $changed, string $by ): array {
		$data             = $job['data'];
		$versions         = array_slice( $data['versions'], 0, (int) $data['index'] + 1 );
		$versions[]       = $doc;
		$versions         = array_slice( $versions, -self::HISTORY_MAX );
		$data['versions'] = $versions;
		$data['index']    = count( $versions ) - 1;
		$data['last_change'] = array( 'by' => $by, 'changed' => $changed );
		$data['error']    = null;
		return $this->repo->compare_and_set( $job, array( 'data' => $data ) );
	}

	/**
	 * 見積もり用：画像データを除いた依頼の文章の大きさ（バイト）。
	 *
	 * @param array<string,mixed> $payload
	 */
	private static function text_bytes( array $payload ): int {
		$copy = $payload;
		foreach ( (array) ( $copy['images'] ?? array() ) as $i => $img ) {
			unset( $copy['images'][ $i ]['data'] );
		}
		return strlen( (string) wp_json_encode( $copy, JSON_UNESCAPED_UNICODE ) );
	}

	private function provider(): ProviderInterface {
		$this->provider ??= ProviderFactory::make();
		return $this->provider;
	}
}
