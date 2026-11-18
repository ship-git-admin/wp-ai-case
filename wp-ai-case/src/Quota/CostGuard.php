<?php
/**
 * 費用の見積もり・記録・上限（新規作成枠とは別に数える）。
 *
 * - 外部呼び出しの前に、推定費用（出力は上限いっぱいと仮定）で次を確かめ、超えるなら呼ばずに AI-014 で止める：
 *     今月の推定合計＋今回 ≤ 月の上限 / 今回 ≤ 1回の上限 / 今日のAI修正の回数 < 1日の上限
 * - 呼び出しの後に、応答の usage（トークン数）から推定費用を記録する（失敗・拒否の応答も記録する）。
 * - 料金表は版（確認日）付きで持つ。実額の請求はこの表と違いうるので「推定」と表示する。
 *   全体の実額の上限は Anthropic の Workspace／組織の上限で別に守る（このサイトの集計だけでは全サイトを止められない）。
 * - 記録には元画像・施主名・ホスト・キー・通信ヘッダーを書かない（数値と種類だけ）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Quota;

use WpAiCase\Diagnostics\Settings;
use WpAiCase\Support\CaseError;
use WpAiCase\Support\Installer;

defined( 'ABSPATH' ) || exit;

final class CostGuard {

	/**
	 * USD / 100万トークン。tiers は「入力（プロンプト）のトークン数がこの値以下なら」の料金。
	 * 出典：Anthropic のモデル・料金の資料（2026-11-18 確認）。料金が変わったらフィルター wp_ai_case_price_table で差し替える。
	 */
	public const PRICES = array(
		'claude-haiku-5-5' => array(
			'version'     => 'anthropic-2026-11-18',
			'tiers'       => array(
				array( 100000, 0.10, 0.50 ),
				array( PHP_INT_MAX, 0.50, 2.50 ),
			),
			'cache_read'  => 0.1,
			'cache_write' => 1.25,
		),
	);

	/** 見積もり用：画像1枚あたりのトークン（長辺1568pxに縮小した写真の上限に近い値）。 */
	public const IMAGE_TOKENS = 2600;
	/** 見積もり用：システムプロンプト・出力の形式（JSON Schema）など、毎回かかる分。 */
	public const OVERHEAD_TOKENS = 4000;
	/** 出力の上限（プロバイダーの max_tokens と同じ）。見積もりは上限いっぱいと仮定する。 */
	public const MAX_OUTPUT = 8000;

	/**
	 * @return array{version:string,tiers:array<int,array{0:int,1:float,2:float}>,cache_read:float,cache_write:float}
	 */
	public static function price( string $model ): array {
		$table = (array) apply_filters( 'wp_ai_case_price_table', self::PRICES );
		if ( ! isset( $table[ $model ] ) ) {
			throw new CaseError( CaseError::COST_LIMIT, '使用するモデルの料金表がありません', array( 'model' => $model ) );
		}
		return $table[ $model ];
	}

	public static function usd( string $model, int $input, int $output, int $cache_read = 0, int $cache_write = 0 ): float {
		$p      = self::price( $model );
		$prompt = $input + $cache_read + $cache_write;
		$tier   = end( $p['tiers'] );
		foreach ( $p['tiers'] as $t ) {
			if ( $prompt <= $t[0] ) {
				$tier = $t;
				break;
			}
		}
		[ , $in, $out ] = $tier;
		$usd = ( $input * $in + $output * $out + $cache_read * $in * $p['cache_read'] + $cache_write * $in * $p['cache_write'] ) / 1000000;
		return round( $usd, 6 );
	}

	/**
	 * 依頼の推定（入力トークンの概算と、出力が上限いっぱいのときの費用）。
	 *
	 * @return array{input_tokens:int,usd:float}
	 */
	public static function estimate( string $model, int $images, int $text_bytes ): array {
		$input = self::OVERHEAD_TOKENS + $images * self::IMAGE_TOKENS + (int) ceil( $text_bytes / 2 );
		return array(
			'input_tokens' => $input,
			'usd'          => self::usd( $model, $input, self::MAX_OUTPUT ),
		);
	}

	/**
	 * 呼ぶ前の確認。超えるなら AI-014（呼ばない）。
	 */
	public function check( string $kind, float $usd_estimate ): void {
		$request = Settings::request_usd_limit();
		if ( $request > 0 && $usd_estimate > $request ) {
			throw new CaseError( CaseError::COST_LIMIT, '1回の依頼の推定費用が上限を超えるため、送信しませんでした', array( 'limit' => 'request', 'kind' => $kind ) );
		}
		$monthly = Settings::monthly_usd_limit();
		if ( $monthly > 0 && $this->month_usd() + $usd_estimate > $monthly ) {
			throw new CaseError( CaseError::COST_LIMIT, '今月の推定費用が上限に達したため、送信しませんでした', array( 'limit' => 'monthly', 'kind' => $kind ) );
		}
		$daily = Settings::daily_revise_limit();
		if ( 'revise' === $kind && $daily > 0 && $this->today_count( 'revise' ) >= $daily ) {
			throw new CaseError( CaseError::COST_LIMIT, '今日のAIによる修正の回数が上限に達しました', array( 'limit' => 'daily_revise', 'kind' => $kind ) );
		}
	}

	/**
	 * 呼んだ後の記録。
	 *
	 * @param array<string,int> $usage input_tokens / output_tokens / cache_read_input_tokens / cache_creation_input_tokens
	 */
	public function record( string $kind, string $model, ?string $job_id, int $images, array $usage, string $outcome ): float {
		global $wpdb;
		$in    = (int) ( $usage['input_tokens'] ?? 0 );
		$out   = (int) ( $usage['output_tokens'] ?? 0 );
		$read  = (int) ( $usage['cache_read_input_tokens'] ?? 0 );
		$write = (int) ( $usage['cache_creation_input_tokens'] ?? 0 );
		$usd   = self::usd( $model, $in, $out, $read, $write );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Installer::usage_table(),
			array(
				'period'             => QuotaService::period(),
				'day'                => self::today(),
				'job_id'             => $job_id,
				'kind'               => $kind,
				'model'              => $model,
				'images'             => $images,
				'input_tokens'       => $in,
				'output_tokens'      => $out,
				'cache_read_tokens'  => $read,
				'cache_write_tokens' => $write,
				'usd_estimate'       => $usd,
				'price_version'      => (string) self::price( $model )['version'],
				'outcome'            => substr( $outcome, 0, 24 ),
				'created_at'         => current_time( 'mysql', true ),
			)
		);
		return $usd;
	}

	public function month_usd( ?string $period = null ): float {
		global $wpdb;
		$table = Installer::usage_table();
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(usd_estimate),0) FROM {$table} WHERE period = %s", $period ?? QuotaService::period() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function today_count( string $kind ): int {
		global $wpdb;
		$table = Installer::usage_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE day = %s AND kind = %s", self::today(), $kind ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * 今月の集計（管理者の診断画面用）。
	 *
	 * @return array<string,mixed>
	 */
	public function summary( ?string $period = null ): array {
		global $wpdb;
		$period = $period ?? QuotaService::period();
		$table  = Installer::usage_table();
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT kind, COUNT(*) AS calls, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(usd_estimate) AS usd FROM {$table} WHERE period = %s GROUP BY kind", $period ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total  = 0.0;
		foreach ( (array) $rows as $r ) {
			$total += (float) $r['usd'];
		}
		return array(
			'period'        => $period,
			'by_kind'       => (array) $rows,
			'usd'           => round( $total, 4 ),
			'monthly_limit' => round( Settings::monthly_usd_limit(), 4 ),
			'request_limit' => round( Settings::request_usd_limit(), 4 ),
			'daily_revise'  => array(
				'count' => $this->today_count( 'revise' ),
				'limit' => Settings::daily_revise_limit(),
			),
			'price_version' => (string) ( self::PRICES[ Settings::model() ]['version'] ?? '' ),
		);
	}

	public static function today(): string {
		return null !== QuotaService::$period_override ? QuotaService::$period_override . '-01' : wp_date( 'Y-m-d' );
	}
}
