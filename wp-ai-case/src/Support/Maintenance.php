<?php
/**
 * 定期の後片付け（ジョブの期限切れ・枠の回収・保存の中断からの復帰・古い記録の削除）。
 *
 * 実行のきっかけ（どれか1つでも動けばよい）：
 *   - WP-Cron（毎時）。Basic認証などで WP-Cron の自己呼び出しが止まる環境では動かない
 *   - サーバーの cron から WP-CLI：wp ai-case maintenance（推奨。Basic認証の影響を受けない）
 *   - 管理者が管理画面を開いたとき（前回から6時間以上たっていれば）
 *
 * 外部（AI）への通信はしない。ジョブを進める（外部へ送る）のは利用者の画面からの操作だけ。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Support;

use WpAiCase\Diagnostics\SafeErrorPresenter;
use WpAiCase\Diagnostics\SupportLog;
use WpAiCase\Quota\QuotaService;

defined( 'ABSPATH' ) || exit;

final class Maintenance {

	public const HOOK     = 'wp_ai_case_maintenance';
	public const LAST_RUN = 'wp_ai_case_maintenance_last';

	/** 管理画面を開いたときに動かす間隔（秒）。 */
	private const ADMIN_INTERVAL = 6 * HOUR_IN_SECONDS;
	/** 保存中のまま止まったとみなす時間（秒）。 */
	private const SAVING_STUCK = HOUR_IN_SECONDS;

	private const JOB_RETENTION_DAYS     = 30;
	private const USAGE_RETENTION_DAYS   = 400;
	private const SUPPORT_RETENTION_DAYS = 90;

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run_cron' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( 'admin_init', array( self::class, 'maybe_run_admin' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run_cron(): void {
		self::run( 'cron' );
	}

	public static function maybe_run_admin(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$last = get_option( self::LAST_RUN );
		if ( is_array( $last ) && (int) ( $last['at'] ?? 0 ) > time() - self::ADMIN_INTERVAL ) {
			return;
		}
		self::run( 'admin' );
	}

	/**
	 * @return array<string,int|string>
	 */
	public static function run( string $via ): array {
		global $wpdb;
		$jobs = Installer::jobs_table();
		$now  = current_time( 'mysql', true );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 1) 期限を過ぎたジョブを「期限切れ」にする（世代を上げ、遅れて戻った結果を捨てられるようにする）。
		$expired = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$jobs} SET status = 'expired', job_generation = job_generation + 1, lease_until = NULL, updated_at = %s
				WHERE status NOT IN ('committed','cancelled','expired','saving') AND expires_at < %s",
				$now,
				$now
			)
		);

		// 2) 保存中のまま止まったジョブを、提案の状態に戻す（AI-016。利用者が保存し直せる。再解析はしない）。
		$stuck = 0;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT job_id FROM {$jobs} WHERE status = 'saving' AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::SAVING_STUCK ) ), ARRAY_A );
		$repo  = new \WpAiCase\Jobs\JobRepository();
		foreach ( (array) $rows as $r ) {
			$job = $repo->get( (string) $r['job_id'] );
			if ( ! $job ) {
				continue;
			}
			$data          = $job['data'];
			$data['error'] = SafeErrorPresenter::present( 'AI-016', 'customer', 'save_interrupted', array( 'job_id' => $job['job_id'] ) );
			try {
				$repo->compare_and_set( $job, array( 'status' => 'proposal_ready', 'data' => $data ) );
				++$stuck;
			} catch ( CaseError $e ) {
				continue;
			}
		}

		// 3) 戻すべき新規作成枠の予約を戻す。
		$released = ( new QuotaService() )->reclaim();

		// 4) 古い記録を消す。
		$purged_jobs  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$jobs} WHERE status IN ('committed','cancelled','expired') AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::JOB_RETENTION_DAYS * DAY_IN_SECONDS ) ) );
		$usage        = Installer::usage_table();
		$purged_usage = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$usage} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::USAGE_RETENTION_DAYS * DAY_IN_SECONDS ) ) );
		// phpcs:enable
		$purged_support = SupportLog::purge( self::SUPPORT_RETENTION_DAYS );

		$result = array(
			'at'             => time(),
			'via'            => $via,
			'expired_jobs'   => $expired,
			'recovered_save' => $stuck,
			'released_quota' => $released,
			'purged_jobs'    => $purged_jobs,
			'purged_usage'   => $purged_usage,
			'purged_support' => $purged_support,
		);
		update_option( self::LAST_RUN, $result, false );
		return $result;
	}
}
