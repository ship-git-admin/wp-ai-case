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
 * 新規作成枠（月5件）・費用の上限は C1-D で追加する。
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
use WpAiCase\Draft\DraftWriter;
use WpAiCase\Mapping\MappingService;
use WpAiCase\Plugin;
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

	public function __construct( ?JobRepository $repo = null, ?ProviderInterface $provider = null, ?MappingService $mappings = null ) {
		$this->repo     = $repo ?? new JobRepository();
		$this->provider = $provider;
		$this->mappings = $mappings ?? new MappingService();
	}

	public function repository(): JobRepository {
		return $this->repo;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public function create( array $raw, int $user ): array {
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
	 * 自分のジョブだけ（管理者は全員分）。期限切れはここで「期限切れ」にする。
	 *
	 * @return array<string,mixed>
	 */
	public function get_owned( string $job_id, int $user ): array {
		$job = $this->repo->get( $job_id );
		if ( ! $job || ( (int) $job['user_id'] !== $user && ! user_can( $user, 'manage_options' ) ) ) {
			throw JobError::code( 'AI-018', 'job_not_found', 404 );
		}
		if ( ! in_array( $job['status'], array( 'committed', 'cancelled', 'expired' ), true ) && strtotime( $job['expires_at'] . ' UTC' ) < time() ) {
			try {
				$job = $this->repo->compare_and_set( $job, array( 'status' => 'expired', 'job_generation' => $job['job_generation'] + 1, 'lease_until' => null ) );
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

		$data['provider'] = array( 'state' => 'sending', 'attempt' => wp_generate_uuid4(), 'started_at' => time(), 'step' => (int) $data['step'] );
		$data['error']    = null;
		$job              = $this->repo->compare_and_set( $job, array( 'status' => 'analyzing', 'data' => $data ) );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::LEASE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$result = $this->provider()->compose( $payload );

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
		return $this->repo->compare_and_set( $job, array( 'status' => 'cancelled', 'job_generation' => $job['job_generation'] + 1, 'lease_until' => null ) );
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
		$doc    = $job['data']['versions'][ $base_index ];
		$result = $this->provider()->revise(
			array(
				'model'            => Settings::model(),
				'document'         => $doc,
				'allowed_sections' => $allowed,
				'instruction'      => $instruction,
			)
		);
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
		$mapping = $this->mappings->repository()->get( (string) $data['snapshot']['mapping_id'], (int) $data['snapshot']['mapping_version'] );
		try {
			if ( ! $mapping || 'approved_active' !== $mapping['status'] ) {
				throw new CaseError( CaseError::MAPPING_NOT_READY, '生成を始めた後にマッピングが変わりました' );
			}
			$result = ( new DraftWriter() )->create_from_document(
				$data['versions'][ $base_index ],
				$user,
				array(
					'mapping'    => $mapping,
					'extra_meta' => array( self::META_JOB => $job_id ),
				)
			);
		} catch ( CaseError $e ) {
			$data['error'] = SafeErrorPresenter::from_case_error( $e, 'customer', 'save_failed' );
			$this->repo->compare_and_set( $job, array( 'status' => 'proposal_ready', 'data' => $data, 'post_id' => isset( $e->context['post_id'] ) ? (int) $e->context['post_id'] : null ) );
			throw new JobError( $data['error'], in_array( $e->reason, array( CaseError::MAPPING_NOT_READY, CaseError::SCHEMA_CHANGED ), true ) ? 503 : 409 );
		}
		$data['error'] = null;
		return $this->repo->compare_and_set( $job, array( 'status' => 'committed', 'post_id' => (int) $result['post_id'], 'data' => $data ) );
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

	private function provider(): ProviderInterface {
		$this->provider ??= ProviderFactory::make();
		return $this->provider;
	}
}
