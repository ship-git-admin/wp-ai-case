<?php
/**
 * エラーコード（AI-001〜018）の文面と復旧方針（docs/12, config/error-catalog）。
 *
 * 文面はここに書いた固定の文だけを使う。外部APIの生の応答・ユーザー入力・サーバー情報を文面に混ぜない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Diagnostics;

defined( 'ABSPATH' ) || exit;

final class ErrorCatalog {

	/**
	 * code => [顧客向け, 管理者向け, 再試行可, 復旧操作（顧客）, 復旧操作（管理者）, 自動リトライ方針]
	 */
	private const ENTRIES = array(
		'AI-001' => array( 'AI機能の準備が完了していません。サイト管理者へお問い合わせください。', 'Claude APIキーが未設定です。wp-config.php に WP_AI_ANTHROPIC_API_KEY を定義し、「ローカル診断を再実行」してください。', false, 'contact_admin', 'fix_config_and_retry', 'しない' ),
		'AI-002' => array( 'AI機能の接続設定に問題があります。サイト管理者へお問い合わせください。', 'Claude API が認証を拒否しました（401）。キーの失効・Workspace・設定値を確認し、必要ならキーを再発行して wp-config.php を更新してください。', false, 'contact_admin', 'fix_config_and_retry', 'しない' ),
		'AI-003' => array( 'AI機能の接続設定に問題があります。サイト管理者へお問い合わせください。', 'Claude API がアクセスを拒否しました（403/402）。Workspace の権限・モデルの利用許可・請求状態を Anthropic Console で確認してください。', false, 'contact_admin', 'open_admin_diagnostics', 'しない' ),
		'AI-004' => array( 'AI機能の設定確認待ちです。サイト管理者へお問い合わせください。', '使用するモデルが未設定か、利用できないモデルです。「接続・診断」で検証済みのモデルを選んでください。', false, 'contact_admin', 'open_admin_diagnostics', 'しない' ),
		'AI-005' => array( 'AI機能が一時的に使用できません。しばらくしてから再試行してください。', 'Claude API の利用制限（429）に達しました。レート制限か、Workspace／組織の上限かを Console の使用状況で確認してください。', true, 'retry_later', 'open_admin_diagnostics', '条件付・遅延' ),
		'AI-006' => array( 'AI機能が一時的に混み合っています。時間をおいて再試行してください。', 'Claude API 側の一時的な障害・過負荷（5xx/529）です。時間をおいて再試行してください。', true, 'retry_later', 'retry_later', '冪等性確認後' ),
		'AI-007' => array( 'AI機能との通信を確認中です。しばらくしてから再試行してください。', 'サーバーから Claude API への通信に失敗しました（DNS・TLS・タイムアウト等）。サーバーの外向きHTTPS通信を確認してください。', true, 'retry_later', 'open_admin_diagnostics', '安全性確認後' ),
		'AI-008' => array( '指定した画像を確認してください（形式・サイズ）。', '画像の形式・サイズ・入力容量の上限を超えました。対象の画像を確認してください（元画像は変更されません）。', false, 'review_image', 'review_image', '修正後のみ' ),
		'AI-009' => array( '生成結果を確認中です。しばらくお待ちください。', 'AIの結果が期待した形式と一致しない、または写真の一部が欠けていたため、保存せずに止めました。', true, 'refresh_status', 'refresh_status', '条件付' ),
		'AI-010' => array( 'サイト構成の再確認待ちです。サイト管理者へお問い合わせください。', 'ACFの構造とマッピングが一致しません。マッピングの再提案・検証・承認（wp ai-case mapping）を行ってください。', false, 'contact_admin', 'review_mapping', 'しない' ),
		'AI-011' => array( '処理に遅れが生じています。しばらくしてから状況を確認してください。', 'バックグラウンド処理（WP-Cron 等）が動いていない可能性があります。Cron・Basic認証・WAF を確認し、ジョブを再開してください。', true, 'refresh_status', 'resume_job', '条件付' ),
		'AI-012' => array( '更新内容に差異があります。最新の状態を確認してください。', '投稿の状態変更・他の人の更新・取消しのため、保存しませんでした。差分と基準版を確認してください。', false, 'review_draft', 'review_draft', '自動保存禁止' ),
		'AI-013' => array( '当月の新規作成枠を使い切りました。', '今月の新規AI作成枠（5件）を使い切りました。使用数・予約・翌月の開始日を確認してください。', false, 'review_quota', 'review_quota', 'しない' ),
		'AI-014' => array( '利用上限に達しました。サイト管理者へお問い合わせください。', 'プラグインに設定した費用上限を超える見込みのため停止しました。費用の設定と使用量を確認してください。', false, 'contact_admin', 'review_quota', 'しない' ),
		'AI-015' => array( '接続を確認してください（ログイン状態・権限）。', 'WordPress の権限・REST・nonce・Basic認証・WAF のいずれかで拒否されました。ログイン状態と権限、REST の到達性を確認してください。', false, 'refresh_status', 'open_admin_diagnostics', 'しない' ),
		'AI-016' => array( '保存を完了できませんでした。サイト管理者へお問い合わせください。', '下書きの保存・読み戻しに失敗しました。スナップショットからの復元結果を確認し、必要なら手動で修復してください。', false, 'contact_admin', 'review_draft', '無条件禁止' ),
		'AI-017' => array( '必須項目を確認してください。', '必須の入力・写真・外部送信への同意が不足しています（外部送信は行っていません）。', true, 'complete_input', 'complete_input', '入力後' ),
		'AI-018' => array( '処理を完了できませんでした。サイト管理者へお問い合わせください。', '分類できないエラーです。サポートIDで記録を確認してください。', false, 'contact_admin', 'open_admin_diagnostics', 'しない' ),
	);

	public static function exists( string $code ): bool {
		return isset( self::ENTRIES[ $code ] );
	}

	public static function message( string $code, string $visibility ): string {
		$e = self::ENTRIES[ $code ] ?? self::ENTRIES['AI-018'];
		return 'admin' === $visibility ? $e[1] : $e[0];
	}

	public static function retryable( string $code ): bool {
		return (bool) ( self::ENTRIES[ $code ][2] ?? false );
	}

	public static function recovery_action( string $code, string $visibility ): string {
		$e = self::ENTRIES[ $code ] ?? self::ENTRIES['AI-018'];
		return 'admin' === $visibility ? $e[4] : $e[3];
	}

	public static function auto_retry_policy( string $code ): string {
		return (string) ( self::ENTRIES[ $code ][5] ?? 'しない' );
	}

	/**
	 * @return string[]
	 */
	public static function codes(): array {
		return array_keys( self::ENTRIES );
	}
}
