<?php
/**
 * C1-D：新規作成枠（月5件）・費用の上限・後片付け。
 *
 * 実サイトの今月の枠・使用量を使わないよう、試験用の月（2099年）に差し替えて行い、最後に記録を消す。
 * プロバイダーは Mock（外部通信なし）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev\SelfTest;

use WpAiCase\Dev\MockProvider;
use WpAiCase\Dev\Poc;
use WpAiCase\Diagnostics\Settings;
use WpAiCase\Jobs\JobError;
use WpAiCase\Jobs\JobService;
use WpAiCase\Quota\CostGuard;
use WpAiCase\Quota\QuotaService;
use WpAiCase\Support\CaseError;
use WpAiCase\Support\Installer;
use WpAiCase\Support\Maintenance;

defined( 'ABSPATH' ) || exit;

final class QuotaTests {

	/** @var string[] */
	private array $jobs = array();

	public function __construct( private Tester $t, private int $user ) {
	}

	public function run(): void {
		$images = Poc::images();
		if ( count( $images ) < 5 ) {
			$this->t->test( 'Q-00', '前提：合成画像', fn() => throw new SkipTest( '合成画像が未登録です（wp ai-case poc seed-images）' ) );
			return;
		}
		$saved_period          = QuotaService::$period_override;
		$saved_limit           = QuotaService::$limit_override;
		Settings::$test_status = 'configured';
		wp_set_current_user( $this->user );
		try {
			$this->tests( $images );
		} finally {
			QuotaService::$period_override = $saved_period;
			QuotaService::$limit_override  = $saved_limit;
			Settings::$test_status         = null;
			$this->cleanup();
		}
	}

	private function service( string $scenario = 'ok' ): JobService {
		return new JobService( null, new MockProvider( $scenario ) );
	}

	/**
	 * @param array<string,int> $images
	 */
	private function input( array $images ): array {
		$ids = array_values( array_intersect_key( $images, array_flip( array( 'before1', 'during1', 'after1' ) ) ) );
		return array(
			'client_case_id'        => 'selftest-quota',
			'idempotency_key'       => wp_generate_uuid4(),
			'mode'                  => 'create',
			'post_type'             => 'works',
			'primary_facts'         => array( 'overview' => '試験', 'client_request' => '試験', 'work_done' => '試験' ),
			'photo_set_description' => '試験',
			'images'                => array_map( static fn( $id, $i ) => array( 'attachment_id' => $id, 'original_position' => $i, 'caption' => '', 'user_phase_hint' => 'unknown' ), $ids, array_keys( $ids ) ),
			'consent_external_ai'   => true,
			'relations'             => array( 'customer_voice_post_ids' => array(), 'staff' => array(), 'supervisor_post_ids' => array() ),
		);
	}

	private function create( JobService $s, array $in ): array {
		$job          = $s->create( $in, $this->user );
		$this->jobs[] = $job['job_id'];
		return $job;
	}

	private function run_to_proposal( JobService $s, array $job ): array {
		for ( $i = 0; $i < 6 && in_array( $job['status'], array( 'queued', 'analyzing' ), true ); $i++ ) {
			$job = $s->tick( $job['job_id'], $this->user );
		}
		return $job;
	}

	private function err( callable $fn ): \Throwable {
		try {
			$fn();
		} catch ( JobError | CaseError $e ) {
			return $e;
		}
		throw new \RuntimeException( '拒否されませんでした' );
	}

	/**
	 * @param array<string,int> $images
	 */
	private function tests( array $images ): void {
		$t = $this->t;
		$q = new QuotaService();

		$t->test( 'Q-01', 'C1-QUO01 月5件：5件まで予約・確定、6件目は AI-013（ジョブを作らず外部0）', function () use ( $q, $images ) {
			QuotaService::$period_override = '2099-01';
			QuotaService::$limit_override  = 5;
			$keys = array();
			for ( $i = 0; $i < 5; $i++ ) {
				$keys[] = wp_generate_uuid4();
				$q->reserve( end( $keys ), $this->user );
			}
			$q->commit( $keys[0], 1 );
			$q->commit( $keys[1], 1 );
			$this->t->same( array( 2, 3, 0 ), array( $q->summary()['committed'], $q->summary()['reserved'], $q->summary()['remaining'] ), '使用' );
			$e = $this->err( fn() => $q->reserve( wp_generate_uuid4(), $this->user ) );
			$this->t->same( 'AI-013', $e->ai_code(), '6件目' );
			MockProvider::$calls = 0;
			$in = $this->input( $images );
			$je = $this->err( fn() => $this->service()->create( $in, $this->user ) );
			$this->t->same( array( 'AI-013', 429 ), array( $je->response['error_code'], $je->status ), '作成の依頼' );
			$this->t->same( null, ( new JobService() )->repository()->by_idempotency( $in['idempotency_key'] ), 'ジョブを作らない' );
			$this->t->same( 0, MockProvider::$calls, '外部呼び出し' );
			$this->t->same( '2099-02-01', $q->summary()['resets_on'], '翌月の開始日' );
		} );

		$t->test( 'Q-02', '同じ作成意図（idempotency_key）の再送は同じ予約。確定は1回だけ、確定済みは戻さない', function () use ( $q ) {
			QuotaService::$period_override = '2099-02';
			QuotaService::$limit_override  = 5;
			$key = wp_generate_uuid4();
			$a   = $q->reserve( $key, $this->user );
			$b   = $q->reserve( $key, $this->user );
			$this->t->same( $a['id'], $b['id'], '同じ予約' );
			$this->t->same( 1, $q->summary()['reserved'], '予約は1件' );
			$this->t->true( $q->commit( $key, 10 ), '確定' );
			$this->t->true( ! $q->commit( $key, 11 ), '再確定しない' );
			$this->t->true( ! $q->release( $key ), '確定済みは戻さない' );
			$this->t->same( array( 1, 0 ), array( $q->summary()['committed'], $q->summary()['reserved'] ), '数' );
		} );

		$t->test( 'Q-03', '取消し・期限切れ・作成途中の放置で予約を戻す。生存中のジョブの予約は戻さない', function () use ( $q, $images ) {
			global $wpdb;
			QuotaService::$period_override = '2099-03';
			QuotaService::$limit_override  = 5;
			$s    = $this->service();
			$a    = $this->create( $s, $this->input( $images ) );
			$b    = $this->create( $s, $this->input( $images ) );
			$live = $this->create( $s, $this->input( $images ) );
			$this->t->same( 3, $q->summary()['reserved'], '3件予約' );
			$s->cancel( $a['job_id'], $this->user );
			$this->t->same( 'released', $q->get( $a['idempotency_key'] )['status'], '取消しで戻す' );
			$wpdb->update( Installer::jobs_table(), array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'job_id' => $b['job_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$orphan = wp_generate_uuid4();
			$q->reserve( $orphan, $this->user );
			$wpdb->update( Installer::quota_table(), array( 'reserved_at' => '2000-01-01 00:00:00' ), array( 'idempotency_key' => $orphan ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$fresh = wp_generate_uuid4();
			$q->reserve( $fresh, $this->user ); // ジョブ作成の直前（作成途中）。予約の中でも回収が走る
			$q->reclaim();
			$this->t->same( 'released', $q->get( $b['idempotency_key'] )['status'], '期限切れで戻す' );
			$this->t->same( 'released', $q->get( $orphan )['status'], '作成途中のまま放置' );
			$this->t->same( 'reserved', $q->get( $fresh )['status'], '作成途中（直後）は戻さない' );
			$this->t->same( 'reserved', $q->get( $live['idempotency_key'] )['status'], '生存中のジョブは戻さない' );
			$q->release( $fresh );
		} );

		$t->test( 'Q-04', '月をまたいで保存しても、予約した月に数える', function () use ( $q ) {
			QuotaService::$period_override = '2099-04';
			QuotaService::$limit_override  = 5;
			$key = wp_generate_uuid4();
			$q->reserve( $key, $this->user );
			QuotaService::$period_override = '2099-05';
			$q->commit( $key, 12 );
			$this->t->same( '2099-04', $q->get( $key )['period'], '予約した月' );
			$this->t->same( 1, $q->summary( '2099-04' )['committed'], '4月に数える' );
			$this->t->same( 0, $q->summary( '2099-05' )['committed'], '5月には数えない' );
		} );

		$t->test( 'Q-05', 'C1-QUO02/DIAG12 失敗→再試行・修正・既存の下書きの修正は枠を余分に使わない。保存成功で1件確定', function () use ( $q, $images ) {
			QuotaService::$period_override = '2099-06';
			QuotaService::$limit_override  = 5;
			$s   = $this->service( '429' );
			$job = $this->run_to_proposal( $s, $this->create( $s, $this->input( $images ) ) );
			$this->t->same( 'failed', $job['status'], '失敗' );
			$this->t->same( 1, $q->summary()['reserved'], '失敗しても予約は1件のまま' );
			$s   = $this->service();
			$job = $this->run_to_proposal( $s, $s->retry( $job['job_id'], $this->user ) );
			$this->t->same( array( 'proposal_ready', 1 ), array( $job['status'], $q->summary()['reserved'] ), '再試行でも1件' );
			$job = $s->revise_ai( $job['job_id'], $this->user, 0, array( 'title' ), '試験' );
			$job = $s->save( $job['job_id'], $this->user, 1 );
			$this->t->same( array( 1, 0 ), array( $q->summary()['committed'], $q->summary()['reserved'] ), '保存で確定' );
			$rev = $s->create( array( 'client_case_id' => 'x', 'idempotency_key' => wp_generate_uuid4(), 'mode' => 'revise_draft', 'post_type' => 'works', 'post_id' => (int) $job['post_id'], 'base_version' => 1, 'consent_external_ai' => true ), $this->user );
			$this->jobs[] = $rev['job_id'];
			$rev = $s->revise_local( $rev['job_id'], $this->user, 0, array( 'seo' ), array( array( 'op' => 'seo_set', 'section' => 'seo', 'field' => 'keywords', 'value' => '試験' ) ) );
			$s->save( $rev['job_id'], $this->user, 1 );
			$this->t->same( array( 1, 0 ), array( $q->summary()['committed'], $q->summary()['reserved'] ), '既存の下書きの修正は数えない' );
		} );

		$t->test( 'Q-06', '同時の予約は DB のロックで1つずつ処理する（ロック中は待って AI-011、超えない）', function () use ( $q ) {
			QuotaService::$period_override = '2099-07';
			QuotaService::$limit_override  = 5;
			$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$got   = (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $q->lock_name() ) );
			$this->t->same( '1', $got, '別の接続がロックを持つ' );
			$start = microtime( true );
			try {
				$e = $this->err( fn() => $q->reserve( wp_generate_uuid4(), $this->user ) );
				$this->t->same( 'AI-011', $e->ai_code(), 'ロック中は予約しない' );
				$this->t->true( microtime( true ) - $start >= 4, '待ってから止める' );
			} finally {
				$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $q->lock_name() ) );
				$other->close();
			}
			$this->t->same( 0, $q->summary()['reserved'], '予約は増えていない' );
			$q->reserve( wp_generate_uuid4(), $this->user );
			$this->t->same( 1, $q->summary()['reserved'], 'ロックが外れたら予約できる' );
		} );

		$t->test( 'C-01', 'C1-QUO02 費用：推定費用が上限を超えるなら外部へ送らない（AI-014）。上限を戻せば同じジョブを再試行できる。使用量を記録', function () use ( $images ) {
			global $wpdb;
			QuotaService::$period_override = '2099-08';
			QuotaService::$limit_override  = 5;
			MockProvider::$calls = 0;
			$s     = $this->service();
			$tiny  = static fn() => 0.000001;
			add_filter( 'wp_ai_case_monthly_usd_limit', $tiny );
			try {
				$job = $s->tick( $this->create( $s, $this->input( $images ) )['job_id'], $this->user );
			} finally {
				remove_filter( 'wp_ai_case_monthly_usd_limit', $tiny );
			}
			$this->t->same( array( 'failed', 'AI-014', 0 ), array( $job['status'], $job['data']['error']['error_code'] ?? null, MockProvider::$calls ), '月の上限' );
			add_filter( 'wp_ai_case_request_usd_limit', $tiny );
			try {
				$job = $s->tick( $s->retry( $job['job_id'], $this->user )['job_id'], $this->user );
			} finally {
				remove_filter( 'wp_ai_case_request_usd_limit', $tiny );
			}
			$this->t->same( array( 'failed', 'AI-014', 0 ), array( $job['status'], $job['data']['error']['error_code'] ?? null, MockProvider::$calls ), '1回の上限' );
			$job = $this->run_to_proposal( $s, $s->retry( $job['job_id'], $this->user ) );
			$this->t->same( array( 'proposal_ready', 1 ), array( $job['status'], MockProvider::$calls ), '上限を戻して再試行' );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Installer::usage_table() . ' WHERE job_id = %s', $job['job_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$this->t->true( $row && 'create' === $row['kind'] && (int) $row['input_tokens'] > 0 && (float) $row['usd_estimate'] > 0 && '' !== $row['price_version'] && 3 === (int) $row['images'], '使用量の記録' );
			$one = static fn() => 1;
			add_filter( 'wp_ai_case_daily_revise_limit', $one );
			try {
				$job = $s->revise_ai( $job['job_id'], $this->user, 0, array( 'title' ), '試験' );
				$e   = $this->err( fn() => $s->revise_ai( $job['job_id'], $this->user, 1, array( 'title' ), '試験' ) );
			} finally {
				remove_filter( 'wp_ai_case_daily_revise_limit', $one );
			}
			$this->t->same( array( 'AI-014', 429, 2 ), array( $e->response['error_code'], $e->status, MockProvider::$calls ), '1日のAI修正の上限' );
			$s->cancel( $job['job_id'], $this->user );
		} );

		$t->test( 'C-02', '料金表：プロンプト10万トークン以下/超で料金が変わる。キャッシュの読み書き', function () {
			$m = Settings::model();
			$this->t->same( 0.01, CostGuard::usd( $m, 100000, 0 ), '10万以下の入力' );
			$this->t->same( 0.1, CostGuard::usd( $m, 200000, 0 ), '10万超の入力（0.5ドル/100万）' );
			$this->t->same( 0.0005, CostGuard::usd( $m, 0, 1000 ), '出力' );
			$this->t->same( 0.000113, CostGuard::usd( $m, 0, 0, 10000, 100 ), 'キャッシュ（読み0.1倍・書き1.25倍。10000×0.1×0.1＋100×0.1×1.25）' );
			$e = CostGuard::estimate( $m, 20, 4000 );
			$this->t->true( $e['input_tokens'] > 20 * 2000 && $e['usd'] < Settings::request_usd_limit(), '写真20枚の見積もりは1回の上限内（' . $e['usd'] . '）' );
		} );

		$t->test( 'M-01', '後片付け：期限切れのジョブと予約を戻し、保存中で止まったジョブを提案に戻す（AI-016）。実行を記録', function () use ( $q, $images ) {
			global $wpdb;
			QuotaService::$period_override = '2099-09';
			QuotaService::$limit_override  = 5;
			$s     = $this->service();
			$old   = $this->create( $s, $this->input( $images ) );
			$stuck = $this->run_to_proposal( $s, $this->create( $s, $this->input( $images ) ) );
			$wpdb->update( Installer::jobs_table(), array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'job_id' => $old['job_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( Installer::jobs_table(), array( 'status' => 'saving', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ), array( 'job_id' => $stuck['job_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$r = Maintenance::run( 'selftest' );
			$this->t->true( $r['expired_jobs'] >= 1 && $r['recovered_save'] >= 1, '実行結果' );
			$this->t->same( 'expired', $s->repository()->get( $old['job_id'] )['status'], '期限切れ' );
			$this->t->same( 'released', $q->get( $old['idempotency_key'] )['status'], '予約を戻す' );
			$j = $s->repository()->get( $stuck['job_id'] );
			$this->t->same( array( 'proposal_ready', 'AI-016' ), array( $j['status'], $j['data']['error']['error_code'] ?? null ), '保存の中断から復帰' );
			$this->t->same( 'reserved', $q->get( $stuck['idempotency_key'] )['status'], '保存し直せるよう予約は残す' );
			$this->t->same( 'selftest', get_option( Maintenance::LAST_RUN )['via'] ?? null, '実行の記録' );
			$s->cancel( $stuck['job_id'], $this->user );
		} );
	}

	private function cleanup(): void {
		global $wpdb;
		$s = new JobService();
		foreach ( array_unique( $this->jobs ) as $id ) {
			$job = $s->repository()->get( $id );
			if ( $job && $job['post_id'] && 'create' === $job['mode'] && get_post_meta( (int) $job['post_id'], JobService::META_JOB, true ) === $id && 'draft' === get_post_status( (int) $job['post_id'] ) ) {
				wp_delete_post( (int) $job['post_id'], true );
			}
			$s->repository()->delete( $id );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM " . Installer::quota_table() . " WHERE period LIKE '2099-%'" );
		$wpdb->query( "DELETE FROM " . Installer::usage_table() . " WHERE period LIKE '2099-%'" );
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Installer::quota_table() . " WHERE period LIKE '2099-%'" );
		// phpcs:enable
		$this->t->test( 'Q-99', '試験の予約・使用量・ジョブ・下書きを削除した', function () use ( $s, $left ) {
			$this->t->same( 0, $left, '試験の予約' );
			foreach ( $this->jobs as $id ) {
				$this->t->same( null, $s->repository()->get( $id ), "ジョブ {$id}" );
			}
		} );
	}
}
