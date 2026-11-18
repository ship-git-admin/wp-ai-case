<?php
/**
 * 管理者が明示的に実行する「接続テスト」（docs/12 のレベルB）。
 *
 * - 管理者（manage_options）だけ。費用・レート消費の可能性に同意したときだけ。
 * - 間隔制限（既定300秒）と1日の上限回数。ページ表示・プラグイン起動・Cron では実行しない。
 * - 送るのはモデルの確認だけ。写真・文章・ACF定義・サーバー情報は送らない。
 * - 結果は分類コードと日時だけを保存する（生の応答は保存しない）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Diagnostics;

use WpAiCase\AI\ProviderFactory;
use WpAiCase\AI\ProviderInterface;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class ProviderProbe {

	public const STATE_OPTION   = 'wp_ai_case_provider_state';
	private const COOLDOWN_KEY  = 'wp_ai_case_probe_cooldown';
	private const COUNT_OPTION  = 'wp_ai_case_probe_count';
	public const COOLDOWN       = 300;
	public const DAILY_LIMIT    = 10;
	/** これより古い結果は「未確認」に戻す。 */
	public const STALE_AFTER    = DAY_IN_SECONDS;

	/**
	 * @return array<string,mixed> outcome: passed / failed / not_available / skipped、code、message など
	 */
	public static function run( int $actor, bool $acknowledged_cost, ?ProviderInterface $provider = null ): array {
		if ( $actor < 1 || ! user_can( $actor, 'manage_options' ) ) {
			throw new CaseError( CaseError::FORBIDDEN, '接続テストは管理者だけが実行できます' );
		}
		if ( ! $acknowledged_cost ) {
			return self::skipped( 'confirm', '費用・レート消費の可能性を確認するチェックが必要です。' );
		}
		if ( 'configured' !== Settings::api_key_status() ) {
			return self::skipped( 'AI-001', ErrorCatalog::message( 'AI-001', 'admin' ) );
		}
		$model = Settings::model();
		if ( '' === $model ) {
			return self::skipped( 'AI-004', ErrorCatalog::message( 'AI-004', 'admin' ) );
		}
		$wait = self::cooldown_remaining();
		if ( $wait > 0 ) {
			return self::skipped( 'cooldown', "前回のテストから間隔が短すぎます。あと {$wait} 秒待ってください。" );
		}
		if ( self::count_today() >= self::DAILY_LIMIT ) {
			return self::skipped( 'limit', '本日の接続テストの上限回数に達しました。' );
		}

		set_transient( self::COOLDOWN_KEY, time() + self::COOLDOWN, self::COOLDOWN );
		self::increment_today();

		$provider ??= ProviderFactory::make();
		$support_id = wp_generate_uuid4();

		$result = $provider->probe( $model );
		// 接続テストは新規作成枠に数えないが、外部呼び出しとして使用量の記録には残す（モデルの確認はトークンを使わない）。
		( new \WpAiCase\Quota\CostGuard() )->record( 'probe', $model, null, 0, array(), $result->ok ? 'ok' : (string) ( $result->error_type ?? 'error' ) );
		if ( $result->not_available ) {
			$state = array( 'outcome' => 'not_available', 'code' => '', 'http_class' => 'none' );
		} else {
			$class = ErrorClassifier::from_provider( $result );
			$state = null === $class
				? array( 'outcome' => 'passed', 'code' => '', 'http_class' => 'none' )
				: array( 'outcome' => 'failed', 'code' => $class['code'], 'http_class' => $class['http_class'] );
		}
		$state += array(
			'at'       => time(),
			'provider' => $provider->id(),
			'model'    => $model,
		);
		update_option( self::STATE_OPTION, $state, false );
		SupportLog::add(
			$support_id,
			'' !== $state['code'] ? $state['code'] : 'AI-000',
			'manual_connectivity_test_' . $state['outcome'],
			array(
				'type'                => $provider->id(),
				'http_status_class'   => $state['http_class'],
				'provider_error_type' => (string) $result->error_type,
			),
			$actor
		);
		return $state + array(
			'support_id' => $support_id,
			'message'    => self::message( $state ),
		);
	}

	/**
	 * 最後の接続テストの結果（古ければ null）。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function last(): ?array {
		$state = get_option( self::STATE_OPTION );
		if ( ! is_array( $state ) || empty( $state['at'] ) ) {
			return null;
		}
		if ( time() - (int) $state['at'] > self::STALE_AFTER || ( $state['model'] ?? '' ) !== Settings::model() ) {
			return null;
		}
		return $state;
	}

	public static function forget(): void {
		delete_option( self::STATE_OPTION );
	}

	public static function cooldown_remaining(): int {
		$until = (int) get_transient( self::COOLDOWN_KEY );
		return max( 0, $until - time() );
	}

	/**
	 * @param array<string,mixed> $state
	 */
	public static function message( array $state ): string {
		return match ( $state['outcome'] ) {
			'passed'        => '接続テストに成功しました（認証とモデルの到達を確認）。以後の生成が必ず成功することを保証するものではありません。',
			'not_available' => 'APIキーを読み取れなかったため、接続テストを実行していません（外部への通信は行っていません）。',
			default         => ErrorCatalog::message( (string) $state['code'], 'admin' ),
		};
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function skipped( string $code, string $message ): array {
		return array(
			'outcome' => 'skipped',
			'code'    => $code,
			'message' => $message,
		);
	}

	private static function count_today(): int {
		$c = get_option( self::COUNT_OPTION );
		return ( is_array( $c ) && ( $c['date'] ?? '' ) === gmdate( 'Y-m-d' ) ) ? (int) $c['count'] : 0;
	}

	private static function increment_today(): void {
		update_option( self::COUNT_OPTION, array( 'date' => gmdate( 'Y-m-d' ), 'count' => self::count_today() + 1 ), false );
	}
}
