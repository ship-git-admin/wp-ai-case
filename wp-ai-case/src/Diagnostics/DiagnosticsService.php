<?php
/**
 * 管理者向けのローカル診断（docs/12 のレベルA、契約 diagnostic_result）。外部へは一切通信しない。
 *
 * 「キーが設定済み」を「接続成功」と表示しない。接続の確認は ProviderProbe（管理者の明示操作）だけ。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Diagnostics;

use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Mapping\MappingService;
use WpAiCase\Plugin;

defined( 'ABSPATH' ) || exit;

final class DiagnosticsService {

	/** 予定時刻からこれ以上遅れている Cron があれば「止まっている可能性」とみなす。 */
	private const CRON_OVERDUE = 15 * MINUTE_IN_SECONDS;

	/**
	 * @return array<string,mixed> diagnostic_result 契約の文書
	 */
	public static function run(): array {
		$checks = array(
			self::api_key(),
			self::model(),
			array( 'check' => 'monthly_quota', 'status' => 'unknown', 'action_code' => 'check_quota' ),
			array( 'check' => 'cost_guard', 'status' => 'unknown', 'action_code' => 'check_budget' ),
			self::mapping(),
			self::background_jobs(),
			self::rest_transport(),
		);

		$by      = array_column( $checks, 'status', 'check' );
		$last    = ProviderProbe::last();
		$summary = 'ready_unverified';
		if ( 'ok' !== $by['api_key_presence'] || 'ok' !== $by['model_configuration'] ) {
			$summary = 'needs_configuration';
		} elseif ( 'blocked' === $by['acf_mapping'] ) {
			$summary = 'blocked_dependency';
		} elseif ( $last && 'failed' === $last['outcome'] && in_array( $last['code'], array( 'AI-002', 'AI-003', 'AI-004' ), true ) ) {
			$summary = 'needs_attention';
		} elseif ( $last && 'failed' === $last['outcome'] && in_array( $last['code'], array( 'AI-005', 'AI-006', 'AI-007' ), true ) && time() - (int) $last['at'] < HOUR_IN_SECONDS ) {
			$summary = 'temporarily_unavailable';
		}

		$messages = array(
			'needs_configuration'     => 'AI機能の設定が不足しています。下の表の「未設定」の項目を確認してください。',
			'blocked_dependency'      => 'ACFの構造とマッピングが一致しないため、生成を止めています。マッピングを確認してください。',
			'needs_attention'         => '最後の接続テストで認証・権限・モデルの問題が見つかりました。設定を確認してください。',
			'temporarily_unavailable' => '最後の接続テストで一時的な問題（混雑・通信）が見つかりました。時間をおいて確認してください。',
			'ready_unverified'        => 'ローカルの確認では問題ありません（外部APIとの接続は、この診断では確認していません）。',
		);

		return array(
			'diagnosis_id'        => wp_generate_uuid4(),
			'checked_at'          => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'scope'               => 'local_only',
			'summary'             => $summary,
			'external_api_called' => false,
			'checks'              => $checks,
			'safe_admin_message'  => $messages[ $summary ],
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function api_key(): array {
		return match ( Settings::api_key_status() ) {
			'configured'     => array( 'check' => 'api_key_presence', 'status' => 'ok', 'action_code' => 'none' ),
			'invalid_format' => array( 'check' => 'api_key_presence', 'status' => 'blocked', 'error_code' => 'AI-001', 'action_code' => 'configure_wp_config' ),
			default          => array( 'check' => 'api_key_presence', 'status' => 'not_configured', 'error_code' => 'AI-001', 'action_code' => 'configure_wp_config' ),
		};
	}

	/**
	 * @return array<string,string>
	 */
	private static function model(): array {
		if ( '' === Settings::model() ) {
			return array( 'check' => 'model_configuration', 'status' => 'not_configured', 'error_code' => 'AI-004', 'action_code' => 'verify_model' );
		}
		$last = ProviderProbe::last();
		if ( $last && 'failed' === $last['outcome'] && 'AI-004' === $last['code'] ) {
			return array( 'check' => 'model_configuration', 'status' => 'warning', 'error_code' => 'AI-004', 'action_code' => 'verify_model' );
		}
		return array( 'check' => 'model_configuration', 'status' => 'ok', 'action_code' => 'none' );
	}

	/**
	 * @return array<string,string>
	 */
	private static function mapping(): array {
		$inspector = new SchemaInspector();
		$state     = ( new MappingService( $inspector ) )->inspect( $inspector->context( Plugin::POST_TYPE, 0, array() ) )['state'];
		if ( 'ready' === $state ) {
			return array( 'check' => 'acf_mapping', 'status' => 'ok', 'action_code' => 'none' );
		}
		return array( 'check' => 'acf_mapping', 'status' => 'blocked', 'error_code' => 'AI-010', 'action_code' => 'repair_acf_mapping' );
	}

	/**
	 * WP-Cron が動いているか（予定より大きく遅れたイベントの有無）。ループバックの実通信はしない。
	 *
	 * @return array<string,string>
	 */
	private static function background_jobs(): array {
		$overdue = self::overdue_cron_events();
		if ( $overdue > 0 ) {
			return array( 'check' => 'background_jobs', 'status' => 'warning', 'error_code' => 'AI-011', 'action_code' => 'inspect_background_jobs' );
		}
		return array( 'check' => 'background_jobs', 'status' => 'ok', 'action_code' => 'none' );
	}

	public static function overdue_cron_events(): int {
		$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$count = 0;
		foreach ( (array) $crons as $time => $hooks ) {
			if ( (int) $time < time() - self::CRON_OVERDUE ) {
				$count += count( (array) $hooks );
			}
		}
		return $count;
	}

	/**
	 * REST の内部経路（このプラグインの ping）が使えるか。HTTP 経路（Basic認証・WAF）は確認できない。
	 * あわせて、管理者以外の REST がサイトの設定で止められていないかも見る（顧客画面は admin-ajax なので影響しない）。
	 *
	 * @return array<string,string>
	 */
	private static function rest_transport(): array {
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp-ai-case/v1/ping' ) );
		if ( $response->is_error() || 200 !== $response->get_status() ) {
			return array( 'check' => 'rest_transport', 'status' => 'blocked', 'error_code' => 'AI-015', 'action_code' => 'inspect_rest_basic_waf' );
		}
		if ( self::rest_restricted_for_non_admins() || ! is_ssl() ) {
			return array( 'check' => 'rest_transport', 'status' => 'warning', 'action_code' => 'inspect_rest_basic_waf' );
		}
		return array( 'check' => 'rest_transport', 'status' => 'ok', 'action_code' => 'none' );
	}

	/**
	 * 管理者権限がない場合に REST が拒否されるか（権限だけを一時的に外して内部で確かめる）。
	 */
	public static function rest_restricted_for_non_admins(): bool {
		$deny = static function ( $allcaps ) {
			$allcaps['manage_options'] = false;
			return $allcaps;
		};
		add_filter( 'user_has_cap', $deny, 9999 );
		try {
			$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp-ai-case/v1/ping' ) );
		} finally {
			remove_filter( 'user_has_cap', $deny, 9999 );
		}
		return $response->is_error() || 200 !== $response->get_status();
	}
}
