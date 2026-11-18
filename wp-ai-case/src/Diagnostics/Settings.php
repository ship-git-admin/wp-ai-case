<?php
/**
 * 設定（秘密でないもの）と、APIキーの「有無」の判定。
 *
 * APIキーは wp-config.php（または環境変数）の定数だけから読む（Claude：WP_AI_ANTHROPIC_API_KEY、OpenAI：WP_AI_OPENAI_API_KEY）。
 * 管理画面でキーを入力・保存・表示する機能は作らない。値・長さ・一部・ハッシュをどこにも出さない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Diagnostics;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'wp_ai_case_settings';

	/** 使う AI の切り替え（wp-config.php）。anthropic（既定）または openai。 */
	public const PROVIDER_CONST = 'WP_AI_CASE_PROVIDER';

	/**
	 * 使える AI と、それぞれ固定のモデル（2026-11-18 依頼者の決定：Claude は Haiku 5.5 に固定、OpenAI へも切り替えられるように）。
	 * キーは、選んだ AI の定数だけを wp-config.php から読む。
	 */
	public const PROVIDERS = array(
		'anthropic' => array(
			'company'     => 'Anthropic',
			'label'       => 'Anthropic 社の AI（Claude）',
			'api'         => 'Claude API',
			'model'       => 'claude-haiku-5-5',
			'model_label' => 'Claude Haiku 5.5',
			'key_const'   => 'WP_AI_ANTHROPIC_API_KEY',
			'console'     => 'Anthropic Console',
		),
		'openai'    => array(
			'company'     => 'OpenAI',
			'label'       => 'OpenAI 社の AI（GPT）',
			'api'         => 'OpenAI API',
			'model'       => 'gpt-6-luna',
			'model_label' => 'GPT-6 Luna',
			'key_const'   => 'WP_AI_OPENAI_API_KEY',
			'console'     => 'OpenAI Platform',
		),
	);

	/** 互換のため残す（Claude のキーの定数名）。 */
	public const KEY_CONST = 'WP_AI_ANTHROPIC_API_KEY';

	/**
	 * 試験用：キーの「状態」だけを差し替える（null なら無効）。差し替え中は api_key() が常に null を返すので、
	 * 本物のプロバイダーへ偽のキーが渡ることはない。
	 *
	 * @var string|null
	 */
	public static ?string $test_status = null;

	/**
	 * 使う AI（anthropic / openai）。定数がない・値が正しくないときは anthropic。
	 */
	public static function provider(): string {
		$p = defined( self::PROVIDER_CONST ) && is_string( constant( self::PROVIDER_CONST ) ) ? strtolower( trim( (string) constant( self::PROVIDER_CONST ) ) ) : 'anthropic';
		$p = (string) apply_filters( 'wp_ai_case_provider_id', $p );
		return isset( self::PROVIDERS[ $p ] ) ? $p : 'anthropic';
	}

	/**
	 * WP_AI_CASE_PROVIDER が定義されていて、値が正しくない（診断で知らせる）。
	 */
	public static function provider_setting_invalid(): bool {
		if ( ! defined( self::PROVIDER_CONST ) ) {
			return false;
		}
		$v = constant( self::PROVIDER_CONST );
		return ! is_string( $v ) || ! isset( self::PROVIDERS[ strtolower( trim( $v ) ) ] );
	}

	/**
	 * @return array{company:string,label:string,api:string,model:string,model_label:string,key_const:string,console:string}
	 */
	public static function info(): array {
		return self::PROVIDERS[ self::provider() ];
	}

	public static function key_const(): string {
		return self::info()['key_const'];
	}

	public static function model_label(): string {
		return self::info()['model_label'];
	}

	/**
	 * @return string configured / not_configured / invalid_format（いま選んでいる AI のキー）
	 */
	public static function api_key_status(): string {
		return self::key_status_for( self::provider() );
	}

	/**
	 * @return string configured / not_configured / invalid_format
	 */
	public static function key_status_for( string $provider ): string {
		if ( null !== self::$test_status ) {
			return self::$test_status;
		}
		$const = (string) ( self::PROVIDERS[ $provider ]['key_const'] ?? '' );
		if ( '' === $const || ! defined( $const ) ) {
			return 'not_configured';
		}
		$value = constant( $const );
		if ( ! is_string( $value ) ) {
			return 'invalid_format';
		}
		if ( '' === trim( $value ) ) {
			return 'not_configured';
		}
		if ( preg_match( '/[\x00-\x20\x7f]/', $value ) ) {
			return 'invalid_format';
		}
		return 'configured';
	}

	/**
	 * プロバイダーの内部でだけ使う。画面・ログ・REST・JS へ渡さない。いま選んでいる AI のキー。
	 */
	public static function api_key(): ?string {
		return self::api_key_for( self::provider() );
	}

	/**
	 * 指定した AI のキー（その AI の定数だけを読む）。
	 */
	public static function api_key_for( string $provider ): ?string {
		if ( null !== self::$test_status ) {
			return null;
		}
		return 'configured' === self::key_status_for( $provider ) ? (string) constant( self::PROVIDERS[ $provider ]['key_const'] ) : null;
	}

	public static function model(): string {
		return self::info()['model'];
	}

	/**
	 * 新規作成枠（1サイト・暦月あたり）。wp-config.php の WP_AI_CASE_MONTHLY_QUOTA か、フィルターで変えられる。
	 */
	public static function monthly_quota(): int {
		return max( 0, (int) apply_filters( 'wp_ai_case_monthly_quota', self::constant_number( 'WP_AI_CASE_MONTHLY_QUOTA', 5 ) ) );
	}

	/**
	 * 費用の上限（USD・推定）。月の合計・1回の依頼・1日のAI修正回数。0 以下なら上限なし。
	 * 実額の請求上限は AI サービス側（Anthropic の Workspace／OpenAI のプロジェクト）の上限で別に守る（このサイトだけの集計では全体を止められない）。
	 */
	public static function monthly_usd_limit(): float {
		return (float) apply_filters( 'wp_ai_case_monthly_usd_limit', self::constant_number( 'WP_AI_CASE_MONTHLY_USD_LIMIT', 1.0 ) );
	}

	public static function request_usd_limit(): float {
		return (float) apply_filters( 'wp_ai_case_request_usd_limit', self::constant_number( 'WP_AI_CASE_REQUEST_USD_LIMIT', 0.2 ) );
	}

	public static function daily_revise_limit(): int {
		return (int) apply_filters( 'wp_ai_case_daily_revise_limit', self::constant_number( 'WP_AI_CASE_DAILY_REVISE_LIMIT', 50 ) );
	}

	/**
	 * 作成画面に、AIの呼び出しごとの推定費用を出すか（一時的な機能。2026-11-18 依頼者の希望）。
	 * 管理者（manage_options）にだけ出す。wp-config.php で WP_AI_CASE_SHOW_COST を false にすると出さない。
	 */
	public static function show_cost( int $user_id ): bool {
		if ( defined( 'WP_AI_CASE_SHOW_COST' ) && ! constant( 'WP_AI_CASE_SHOW_COST' ) ) {
			return false;
		}
		return $user_id > 0 && user_can( $user_id, 'manage_options' );
	}

	/**
	 * 円での目安の換算（1ドルあたりの円）。wp-config.php の WP_AI_CASE_JPY_PER_USD で変えられる。
	 */
	public static function jpy_per_usd(): float {
		return (float) self::constant_number( 'WP_AI_CASE_JPY_PER_USD', 150.0 );
	}

	private static function constant_number( string $name, float|int $default ): float|int {
		if ( defined( $name ) && is_numeric( constant( $name ) ) ) {
			return is_int( $default ) ? (int) constant( $name ) : (float) constant( $name );
		}
		return $default;
	}
}
