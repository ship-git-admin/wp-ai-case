<?php
/**
 * 新規作成枠（1サイト・暦月あたり。初期値 5 件）。
 *
 *   absent → reserved（生成の開始時）→ committed（新しい下書きを保存・読み戻しできたとき）
 *                                    → released（取消し・期限切れ）
 *
 * - 数えるのは「AIで新しく作った下書きの保存に成功したもの」と、進行中の予約。修正・再試行・
 *   既存の下書きの修正・手作業の投稿・接続テストは数えない。
 * - 月はサイトのタイムゾーンの暦月。予約した月に帰属させる（月をまたいで保存しても予約した月）。
 * - 予約は DB の名前付きロック（GET_LOCK）の中で「使用＋予約 < 上限」を確かめてから入れる（同時に押しても超えない）。
 *   同じ idempotency_key は一意（同じ作成意図の再送で二重に予約しない）。
 * - 失敗したジョブ（再試行できる状態）の予約は保持する（再試行で二重に数えない）。放置されたジョブは
 *   期限切れ（24時間）で予約を戻す。生存中のジョブの予約は戻さない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Quota;

use WpAiCase\Diagnostics\Settings;
use WpAiCase\Support\CaseError;
use WpAiCase\Support\Installer;

defined( 'ABSPATH' ) || exit;

final class QuotaService {

	/** 試験用：月・上限の差し替え（null なら無効）。実サイトの今月の枠を試験で使わないため。 */
	public static ?string $period_override = null;
	public static ?int $limit_override     = null;

	/** ジョブが作られる前の予約を「作成途中」とみなす時間（秒）。これより古く、ジョブが無い予約は戻す。 */
	private const ORPHAN_GRACE = 600;

	public static function period( ?int $timestamp = null ): string {
		if ( null !== self::$period_override ) {
			return self::$period_override;
		}
		return wp_date( 'Y-m', $timestamp ?? time() );
	}

	public static function limit(): int {
		return self::$limit_override ?? Settings::monthly_quota();
	}

	/**
	 * 今月の状況。
	 *
	 * @return array{period:string,limit:int,committed:int,reserved:int,remaining:int,resets_on:string}
	 */
	public function summary( ?string $period = null ): array {
		global $wpdb;
		$period = $period ?? self::period();
		$table  = Installer::quota_table();
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE period = %s GROUP BY status", $period ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by     = array_column( (array) $rows, 'n', 'status' );
		$limit  = self::limit();
		$used   = (int) ( $by['committed'] ?? 0 ) + (int) ( $by['reserved'] ?? 0 );
		return array(
			'period'    => $period,
			'limit'     => $limit,
			'committed' => (int) ( $by['committed'] ?? 0 ),
			'reserved'  => (int) ( $by['reserved'] ?? 0 ),
			'remaining' => max( 0, $limit - $used ),
			'resets_on' => self::next_period_start( $period ),
		);
	}

	/**
	 * 新規作成の予約。同じキーの予約があればそれを返す。枠がなければ AI-013。
	 *
	 * @return array<string,mixed> 予約の行
	 */
	public function reserve( string $idempotency_key, int $user_id ): array {
		$existing = $this->get( $idempotency_key );
		if ( $existing && in_array( $existing['status'], array( 'reserved', 'committed' ), true ) ) {
			return $existing;
		}
		if ( ! $this->lock() ) {
			throw new CaseError( CaseError::QUOTA_BUSY, '新規作成の受付が混み合っています。少し待ってから、もう一度お試しください' );
		}
		try {
			global $wpdb;
			$period = self::period();
			$this->reclaim();
			$s = $this->summary( $period );
			if ( $s['remaining'] < 1 ) {
				throw new CaseError(
					CaseError::QUOTA_EXCEEDED,
					'当月の新規作成枠を使い切りました',
					array(
						'period'    => $period,
						'limit'     => $s['limit'],
						'committed' => $s['committed'],
						'reserved'  => $s['reserved'],
						'resets_on' => $s['resets_on'],
					)
				);
			}
			$now   = current_time( 'mysql', true );
			$table = Installer::quota_table();
			if ( $existing ) {
				// 取消し後に同じ作成意図をもう一度始めた（released → reserved）。
				$wpdb->update( $table, array( 'status' => 'reserved', 'period' => $period, 'job_id' => null, 'updated_at' => $now ), array( 'id' => (int) $existing['id'], 'status' => 'released' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$table,
					array(
						'idempotency_key' => $idempotency_key,
						'period'          => $period,
						'status'          => 'reserved',
						'user_id'         => $user_id,
						'reserved_at'     => $now,
						'updated_at'      => $now,
					),
					array( '%s', '%s', '%s', '%d', '%s', '%s' )
				);
				if ( ! $ok ) {
					$row = $this->get( $idempotency_key );
					if ( $row ) {
						return $row;
					}
					throw new CaseError( CaseError::WRITE_FAILED, '新規作成枠を予約できませんでした' );
				}
			}
			return (array) $this->get( $idempotency_key );
		} finally {
			$this->unlock();
		}
	}

	public function attach_job( string $idempotency_key, string $job_id ): void {
		global $wpdb;
		$wpdb->update( Installer::quota_table(), array( 'job_id' => $job_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'idempotency_key' => $idempotency_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 保存に成功した：予約を確定する。予約が戻されていた（まれな競合）場合も、作った下書きは数える。
	 */
	public function commit( string $idempotency_key, int $post_id ): bool {
		global $wpdb;
		$table = Installer::quota_table();
		$n     = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'committed', post_id = %d, updated_at = %s WHERE idempotency_key = %s AND status IN ('reserved','released')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id,
				current_time( 'mysql', true ),
				$idempotency_key
			)
		);
		return 1 === (int) $n;
	}

	/**
	 * 取消し・期限切れ：予約を戻す（確定済みは戻さない）。
	 */
	public function release( string $idempotency_key ): bool {
		global $wpdb;
		$table = Installer::quota_table();
		$n     = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'released', updated_at = %s WHERE idempotency_key = %s AND status = 'reserved'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				$idempotency_key
			)
		);
		return 1 === (int) $n;
	}

	/**
	 * 戻すべき予約を戻す：ジョブが取消し・期限切れ、ジョブが期限を過ぎて未保存、作成途中のまま放置（ジョブなし）。
	 * 生存中（期限内で、取消し・期限切れでない）のジョブの予約は戻さない。
	 *
	 * @return int 戻した数
	 */
	public function reclaim(): int {
		global $wpdb;
		$quota = Installer::quota_table();
		$jobs  = Installer::jobs_table();
		$now   = current_time( 'mysql', true );
		$grace = gmdate( 'Y-m-d H:i:s', time() - self::ORPHAN_GRACE );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$quota} q LEFT JOIN {$jobs} j ON j.idempotency_key = q.idempotency_key
				SET q.status = 'released', q.updated_at = %s
				WHERE q.status = 'reserved' AND (
					( j.id IS NULL AND q.reserved_at < %s )
					OR j.status IN ('cancelled','expired')
					OR ( j.status NOT IN ('committed','saving') AND j.expires_at < %s )
				)",
				$now,
				$grace,
				$now
			)
		);
		// phpcs:enable
		return $n;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( string $idempotency_key ): ?array {
		global $wpdb;
		$table = Installer::quota_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $idempotency_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function next_period_start( string $period ): string {
		[ $y, $m ] = array_map( 'intval', explode( '-', $period ) + array( 1 => '1' ) );
		$m++;
		if ( $m > 12 ) {
			$m = 1;
			$y++;
		}
		return sprintf( '%04d-%02d-01', $y, $m );
	}

	public function lock_name(): string {
		global $wpdb;
		return 'wpaic_quota_' . substr( md5( Installer::site_scope_id() . $wpdb->prefix ), 0, 32 );
	}

	private function lock(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $this->lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function unlock(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
