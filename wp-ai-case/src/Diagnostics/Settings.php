<?php
/**
 * 設定（秘密でないもの）と、APIキーの「有無」の判定。
 *
 * APIキーは wp-config.php（または環境変数）の定数 WP_AI_ANTHROPIC_API_KEY だけから読む。
 * 管理画面でキーを入力・保存・表示する機能は作らない。値・長さ・一部・ハッシュをどこにも出さない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Diagnostics;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION    = 'wp_ai_case_settings';
	public const KEY_CONST = 'WP_AI_ANTHROPIC_API_KEY';

	/** 使うモデルは固定（2026-11-18 依頼者の決定）。利用できるかは管理者の接続テストで確かめる。 */
	public const MODEL       = 'claude-haiku-5-5';
	public const MODEL_LABEL = 'Claude Haiku 5.5';

	/**
	 * 試験用：キーの「状態」だけを差し替える（null なら無効）。差し替え中は api_key() が常に null を返すので、
	 * 本物のプロバイダーへ偽のキーが渡ることはない。
	 *
	 * @var string|null
	 */
	public static ?string $test_status = null;

	/**
	 * @return string configured / not_configured / invalid_format
	 */
	public static function api_key_status(): string {
		if ( null !== self::$test_status ) {
			return self::$test_status;
		}
		if ( ! defined( self::KEY_CONST ) ) {
			return 'not_configured';
		}
		$value = constant( self::KEY_CONST );
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
	 * プロバイダーの内部でだけ使う。画面・ログ・REST・JS へ渡さない。
	 */
	public static function api_key(): ?string {
		if ( null !== self::$test_status ) {
			return null;
		}
		return 'configured' === self::api_key_status() ? (string) constant( self::KEY_CONST ) : null;
	}

	public static function model(): string {
		return self::MODEL;
	}
}
