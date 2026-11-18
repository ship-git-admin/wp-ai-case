<?php
/**
 * 生成ジョブの保存。状態の変更は compare-and-swap（元の状態・世代が一致したときだけ）で行う。
 *
 * data（JSON）には入力・開始時の文脈・提案の版・外部呼び出しの状態・エラーを入れる。
 * APIキー・外部APIの生の応答・画像データは入れない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Jobs;

use WpAiCase\Support\CaseError;
use WpAiCase\Support\Installer;

defined( 'ABSPATH' ) || exit;

final class JobRepository {

	public const TTL = DAY_IN_SECONDS;

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function insert( string $idempotency_key, int $user_id, string $mode, array $data ): array {
		global $wpdb;
		$now    = current_time( 'mysql', true );
		$job_id = wp_generate_uuid4();
		$ok     = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Installer::jobs_table(),
			array(
				'job_id'          => $job_id,
				'idempotency_key' => $idempotency_key,
				'user_id'         => $user_id,
				'mode'            => $mode,
				'status'          => 'queued',
				'job_generation'  => 1,
				'data'            => (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
				'created_at'      => $now,
				'updated_at'      => $now,
				'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + self::TTL ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			// 同じ idempotency_key の同時作成など。既存を返す側で処理する。
			$existing = $this->by_idempotency( $idempotency_key );
			if ( $existing ) {
				return $existing;
			}
			throw new CaseError( CaseError::WRITE_FAILED, 'ジョブを作成できませんでした' );
		}
		return $this->get( $job_id );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( string $job_id ): ?array {
		global $wpdb;
		if ( ! wp_is_uuid( $job_id, 4 ) ) {
			return null;
		}
		$table = Installer::jobs_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %s", $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function by_idempotency( string $key ): ?array {
		global $wpdb;
		$table = Installer::jobs_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * 状態・世代が一致したときだけ更新する。
	 *
	 * @param array<string,mixed> $job
	 * @param array<string,mixed> $fields status / job_generation / post_id / data / lease_until / lease_owner
	 * @return array<string,mixed>
	 */
	public function compare_and_set( array $job, array $fields ): array {
		global $wpdb;
		$table = Installer::jobs_table();
		$set   = array( 'updated_at = %s' );
		$vals  = array( current_time( 'mysql', true ) );
		foreach ( $fields as $col => $value ) {
			if ( ! in_array( $col, array( 'status', 'job_generation', 'post_id', 'data', 'lease_until', 'lease_owner' ), true ) ) {
				continue;
			}
			if ( null === $value ) {
				$set[] = "{$col} = NULL";
				continue;
			}
			$set[]  = "{$col} = " . ( in_array( $col, array( 'job_generation', 'post_id' ), true ) ? '%d' : '%s' );
			$vals[] = 'data' === $col ? (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : $value;
		}
		$vals[] = (string) $job['job_id'];
		$vals[] = (string) $job['status'];
		$vals[] = (int) $job['job_generation'];
		$sql    = "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE job_id = %s AND status = %s AND job_generation = %d';
		$n      = $wpdb->query( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( 1 !== (int) $n ) {
			throw new CaseError( CaseError::STALE_BASE, 'ジョブの状態が他の操作で変わりました', array( 'job_id' => $job['job_id'] ) );
		}
		return $this->get( (string) $job['job_id'] );
	}

	/**
	 * 処理の担当（lease）を取る。他の画面・タブが処理中なら取れない。
	 *
	 * @param array<string,mixed> $job
	 */
	public function acquire_lease( array $job, int $seconds ): ?string {
		global $wpdb;
		$table = Installer::jobs_table();
		$owner = wp_generate_uuid4();
		$now   = current_time( 'mysql', true );
		$n     = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET lease_until = %s, lease_owner = %s, updated_at = %s WHERE job_id = %s AND job_generation = %d AND status IN ('queued','analyzing') AND (lease_until IS NULL OR lease_until < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() + $seconds ),
				$owner,
				$now,
				(string) $job['job_id'],
				(int) $job['job_generation'],
				$now
			)
		);
		return 1 === (int) $n ? $owner : null;
	}

	/**
	 * 試験・後片付け用：ユーザーのジョブを消す。
	 */
	public function delete( string $job_id ): void {
		global $wpdb;
		$wpdb->delete( Installer::jobs_table(), array( 'job_id' => $job_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		$row['id']             = (int) $row['id'];
		$row['user_id']        = (int) $row['user_id'];
		$row['job_generation'] = (int) $row['job_generation'];
		$row['post_id']        = null === $row['post_id'] ? null : (int) $row['post_id'];
		$row['data']           = json_decode( (string) $row['data'], true ) ?: array();
		return $row;
	}
}
