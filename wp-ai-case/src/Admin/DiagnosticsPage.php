<?php
/**
 * 管理者専用「AI施工事例 > 接続・診断」。
 *
 * - 画面を開くだけでは外部へ通信しない（ローカル診断だけ）。
 * - APIキーは「設定済み／未設定」だけを表示する。値・長さ・一部は表示しない。入力欄も作らない。
 * - 接続テストは、費用の可能性を確認するチェック付きの明示操作のときだけ（nonce・権限・間隔制限）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Admin;

use WpAiCase\Diagnostics\DiagnosticsService;
use WpAiCase\Diagnostics\ErrorCatalog;
use WpAiCase\Diagnostics\ProviderProbe;
use WpAiCase\Diagnostics\Settings;
use WpAiCase\Diagnostics\SupportLog;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class DiagnosticsPage {

	private const FLASH = 'wp_ai_case_diag_flash_';

	private const CHECK_LABELS = array(
		'api_key_presence'    => 'APIキー（wp-config.php）',
		'model_configuration' => '使用するモデル',
		'monthly_quota'       => '月5件の新規作成枠',
		'cost_guard'          => '費用の上限',
		'acf_mapping'         => 'ACFマッピング',
		'background_jobs'     => 'バックグラウンド処理（WP-Cron）',
		'rest_transport'      => 'REST・通信経路',
	);

	private const STATUS_LABELS = array(
		'ok'             => '正常',
		'not_configured' => '未設定',
		'warning'        => '警告',
		'blocked'        => '異常',
		'unknown'        => '未確認',
	);

	private const ACTIONS = array(
		'none'                    => '—',
		'configure_wp_config'     => '下の「APIキーの設定方法」に従って wp-config.php に定義し、ローカル診断を再実行',
		'verify_model'            => '接続テストで、モデル（Claude Haiku 5.5）を利用できるか確認',
		'check_quota'             => '今月の新規作成枠を使い切りました（下の「新規作成枠・使用量」を参照。翌月1日に戻ります）',
		'check_budget'            => '推定費用が上限に近い／達しています（下の「新規作成枠・使用量」と Anthropic Console の使用量を確認）',
		'repair_acf_mapping'      => '「AI施工事例 > マッピング」で状態を確認し、提案・検証・承認',
		'inspect_background_jobs' => 'WP-Cron または後片付けが動いていません。Basic認証・WAF を確認し、サーバーの cron で wp ai-case maintenance を毎時実行',
		'inspect_rest_basic_waf'  => 'REST の制限（Code Snippet の例外）・Basic認証・WAF・HTTPS の設定を確認',
		'review_provider_status'  => 'Anthropic の稼働状況を確認',
	);

	public static function register(): void {
		add_action( 'admin_post_wp_ai_case_probe', array( self::class, 'handle_probe' ) );
		add_action( 'admin_post_wp_ai_case_support_lookup', array( self::class, 'handle_support_lookup' ) );
	}

	public static function handle_probe(): void {
		self::guard( 'wp_ai_case_probe' );
		$ack = ! empty( $_POST['ack_cost'] );
		try {
			$r = ProviderProbe::run( get_current_user_id(), $ack );
		} catch ( CaseError $e ) {
			$r = array( 'outcome' => 'skipped', 'code' => $e->ai_code(), 'message' => $e->getMessage() );
		}
		$type = match ( $r['outcome'] ) {
			'passed'  => 'success',
			'failed'  => 'error',
			default   => 'warning',
		};
		$text = (string) $r['message'];
		if ( ! empty( $r['code'] ) && str_starts_with( (string) $r['code'], 'AI-' ) ) {
			$text = $r['code'] . '：' . $text;
		}
		if ( ! empty( $r['support_id'] ) ) {
			$text .= '（サポートID：' . $r['support_id'] . '）';
		}
		self::flash( array( 'type' => $type, 'text' => $text ) );
	}

	public static function handle_support_lookup(): void {
		self::guard( 'wp_ai_case_support_lookup' );
		$id  = isset( $_POST['support_id'] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_POST['support_id'] ) ) ) : '';
		$row = SupportLog::get( $id );
		self::flash(
			$row
				? array( 'type' => 'info', 'text' => 'サポートIDの記録', 'support' => $row )
				: array( 'type' => 'error', 'text' => 'その サポートID の記録は見つかりません。' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'wp-ai-case' ), 403 );
		}
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		$diag  = DiagnosticsService::run();
		$flash = get_transient( self::FLASH . get_current_user_id() );
		delete_transient( self::FLASH . get_current_user_id() );
		$last  = ProviderProbe::last();

		echo '<div class="wrap"><h1>' . esc_html( 'AI施工事例：接続・診断' ) . '</h1>';
		echo '<p>' . esc_html( 'この画面を開いただけでは外部（Claude API）へは通信しません。表示しているのはこのサーバー内の確認結果です。' ) . '</p>';
		if ( str_starts_with( \WpAiCase\AI\ProviderFactory::make()->id(), 'mock' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( '開発用の模擬AI（Mock）が有効です。APIキーの状態は「設定済み」として扱われ、生成結果は【模擬】の固定文になります。外部へは通信しません。本番前に無効にしてください（wp ai-case poc mock-provider off）。' ) . '</p></div>';
		}

		if ( is_array( $flash ) ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( (string) $flash['type'] ), esc_html( (string) $flash['text'] ) );
			if ( ! empty( $flash['support'] ) ) {
				self::render_support( $flash['support'] );
			}
		}

		if ( ! is_ssl() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( 'このサイトは HTTP（暗号化なし）で表示されています。ログイン情報や写真が暗号化されずに送られるため、実際のお客様の情報は使わず、公開時は HTTPS にしてください。' ) . '</p></div>';
		}

		// 概要と各項目
		printf( '<h2>%s</h2><p><strong>%s</strong></p>', esc_html( 'ローカル診断' ), esc_html( (string) $diag['safe_admin_message'] ) );
		echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>項目</th><th style="white-space:nowrap">状態</th><th style="white-space:nowrap">コード</th><th>対応</th></tr></thead><tbody>';
		foreach ( $diag['checks'] as $c ) {
			printf(
				'<tr><td>%s</td><td style="white-space:nowrap">%s</td><td style="white-space:nowrap">%s</td><td>%s</td></tr>',
				esc_html( self::CHECK_LABELS[ $c['check'] ] ?? $c['check'] ),
				esc_html( self::STATUS_LABELS[ $c['status'] ] ?? $c['status'] ),
				esc_html( (string) ( $c['error_code'] ?? '' ) ),
				esc_html( self::ACTIONS[ $c['action_code'] ] ?? '' )
			);
		}
		echo '</tbody></table>';
		printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( Menu::diagnostics_url() ), esc_html( 'ローカル診断を再実行' ) );

		// APIキー
		$key = Settings::api_key_status();
		echo '<h2>' . esc_html( 'APIキー' ) . '</h2>';
		printf( '<p>%s</p>', esc_html( 'configured' === $key ? '設定済み（値は表示しません。設定済みであることは、接続できることを意味しません）' : ( 'invalid_format' === $key ? '設定されていますが、形式が正しくありません（空白・改行などを含む）' : '未設定（AI-001）' ) ) );
		echo '<details' . ( 'configured' === $key ? '' : ' open' ) . '><summary>' . esc_html( 'APIキーの設定方法（管理者向け）' ) . '</summary>';
		echo '<p>' . esc_html( 'WordPress を設置したサーバーの wp-config.php に、次の定数を定義してください。位置は「/* That\'s all, stop editing! */」の近くなど、require_once ABSPATH . \'wp-settings.php\'; より前です。' ) . '</p>';
		echo '<pre style="background:#f6f7f7;padding:12px;max-width:900px;overflow:auto">' . esc_html(
			"// サーバーの環境変数から読む場合（推奨）\n" .
			"define( 'WP_AI_ANTHROPIC_API_KEY', getenv( 'WP_AI_ANTHROPIC_API_KEY' ) ?: '' );\n\n" .
			"// 環境変数を使えない場合は、サイト専用のキーを直接書く（下は例。REPLACE_… の部分を1Passwordの値に置き換える）\n" .
			"// define( 'WP_AI_ANTHROPIC_API_KEY', 'REPLACE_WITH_SITE_SPECIFIC_KEY' );"
		) . '</pre>';
		echo '<ul style="list-style:disc;margin-left:2em">';
		foreach ( array(
			'キーは1Passwordで管理し、サイトごとに別のキーを使ってください。',
			'キーを GitHub・テーマ・プラグインのファイル・管理画面の入力欄・チャットに書かないでください。',
			'サイトを複製（クローン）するときは、キーを引き継がず新しく発行してください。',
			'保存したら、この画面で「ローカル診断を再実行」し、必要なら「接続テスト」を行ってください。',
		) as $li ) {
			echo '<li>' . esc_html( $li ) . '</li>';
		}
		echo '</ul></details>';

		// モデル
		echo '<h2>' . esc_html( '使用するモデル' ) . '</h2>';
		echo '<p>' . esc_html( Settings::MODEL_LABEL . '（固定）。利用できるかは下の接続テストで確認します。' ) . '</p>';

		// 接続テスト
		echo '<h2>' . esc_html( '接続テスト（外部通信あり）' ) . '</h2>';
		echo '<p>' . esc_html( '管理者がボタンを押したときだけ、Claude API に「認証とモデルの確認」だけを送ります。写真・文章・ACFの定義・サーバーの情報は送りません。' ) . '</p>';
		echo '<p>' . esc_html( 'Anthropic の仕様により、費用やレート制限の消費が発生する可能性があります。間隔は' . ProviderProbe::COOLDOWN . '秒以上、1日' . ProviderProbe::DAILY_LIMIT . '回までです。' ) . '</p>';
		if ( $last ) {
			printf(
				'<p>%s</p>',
				esc_html( '前回の結果（' . wp_date( 'Y-m-d H:i', (int) $last['at'] ) . '）：' . ( '' !== ( $last['code'] ?? '' ) ? $last['code'] . ' ' : '' ) . ProviderProbe::message( $last ) )
			);
		} else {
			echo '<p>' . esc_html( '前回の結果：未確認' ) . '</p>';
		}
		$disabled = 'configured' !== $key || ProviderProbe::cooldown_remaining() > 0;
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wp_ai_case_probe' );
		echo '<input type="hidden" name="action" value="wp_ai_case_probe">';
		echo '<label><input type="checkbox" name="ack_cost" value="1"> ' . esc_html( '費用・レート消費が発生する可能性があることを理解しました' ) . '</label><br><br>';
		submit_button( '接続テストを実行', 'primary', 'submit', false, $disabled ? array( 'disabled' => 'disabled' ) : array() );
		if ( $disabled ) {
			echo ' <span class="description">' . esc_html( 'APIキーを設定すると実行できます（実行直後は間隔制限があります）。' ) . '</span>';
		}
		echo '</form>';

		if ( DiagnosticsService::rest_restricted_for_non_admins() ) {
			echo '<h2>' . esc_html( 'REST API の制限（AI-015）' ) . '</h2>';
			echo '<p>' . esc_html( '管理者以外の REST API がサイトの設定（Code Snippets 等）で止められているため、管理者以外のユーザーは施工事例AIを使えません。Code Snippet「RESTAPIセキュリティ設定」の「Contact Form7の除外」の下に、次の例外を追加してください（ログイン・権限の確認はプラグイン側で行います）。' ) . '</p>';
			echo '<pre style="background:#f6f7f7;padding:12px;max-width:900px;overflow:auto">' . esc_html( "  //WP AI Case（施工事例AI）の除外：ログイン・nonce・権限はプラグイン側で確認する\n  if( strpos( \$namespaces, 'wp-ai-case/' ) === 1 ){\n    return \$result;\n  }" ) . '</pre>';
		}

		// 新規作成枠・使用量
		$quota = ( new \WpAiCase\Quota\QuotaService() )->summary();
		$cost  = ( new \WpAiCase\Quota\CostGuard() )->summary();
		$usd   = static fn( float $v ): string => '$' . number_format( $v, 4 );
		echo '<h2>' . esc_html( '新規作成枠・使用量（今月：' . $quota['period'] . '）' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:1000px"><tbody>';
		printf( '<tr><th style="width:240px">%s</th><td>%s</td></tr>', esc_html( '新規作成枠' ), esc_html( sprintf( '%d 件中 %d 件使用（確定 %d・進行中 %d）、残り %d 件。%s に戻ります', $quota['limit'], $quota['committed'] + $quota['reserved'], $quota['committed'], $quota['reserved'], $quota['remaining'], $quota['resets_on'] ) ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( '推定費用（今月）' ), esc_html( $usd( (float) $cost['usd'] ) . ( $cost['monthly_limit'] > 0 ? ' ／ 上限 ' . $usd( (float) $cost['monthly_limit'] ) : '（上限なし）' ) ) );
		foreach ( (array) $cost['by_kind'] as $k ) {
			$label = array(
				'create'  => '生成（写真20枚以下）',
				'analyze' => '写真の分割解析',
				'compose' => '分割後の再統合',
				'revise'  => 'AIによる修正',
				'probe'   => '接続テスト',
			)[ (string) $k['kind'] ] ?? (string) $k['kind'];
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( '　' . $label ), esc_html( sprintf( '%d 回・入力 %s / 出力 %s トークン・%s', (int) $k['calls'], number_format( (int) $k['input_tokens'] ), number_format( (int) $k['output_tokens'] ), $usd( (float) $k['usd'] ) ) ) );
		}
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( '1回の依頼の上限（推定）' ), esc_html( $cost['request_limit'] > 0 ? $usd( (float) $cost['request_limit'] ) : '上限なし' ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( '今日のAIによる修正' ), esc_html( sprintf( '%d 回 ／ 上限 %s', $cost['daily_revise']['count'], $cost['daily_revise']['limit'] > 0 ? $cost['daily_revise']['limit'] . ' 回' : 'なし' ) ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( '料金表' ), esc_html( Settings::MODEL_LABEL . '（' . $cost['price_version'] . '）。推定費用は応答のトークン数から計算した目安で、請求額とは異なることがあります' ) );
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( '上限は wp-config.php の WP_AI_CASE_MONTHLY_QUOTA（新規作成枠）、WP_AI_CASE_MONTHLY_USD_LIMIT（月の推定費用）、WP_AI_CASE_REQUEST_USD_LIMIT（1回）、WP_AI_CASE_DAILY_REVISE_LIMIT（1日のAI修正）で変えられます。全体の実額は Anthropic の Workspace／組織の上限でも守ってください。' ) . '</p>';

		// バックグラウンド処理
		echo '<h2>' . esc_html( 'バックグラウンド処理（WP-Cron・後片付け）' ) . '</h2>';
		$overdue = DiagnosticsService::overdue_cron_events();
		printf( '<p>%s</p>', esc_html( 0 === $overdue ? '予定より大きく遅れている WP-Cron の処理はありません。' : "予定より15分以上遅れている WP-Cron の処理が {$overdue} 件あります。Basic認証などで WP-Cron の自己呼び出しが拒否されている可能性があります（AI-011）。" ) );
		$last = get_option( \WpAiCase\Support\Maintenance::LAST_RUN );
		printf(
			'<p>%s</p>',
			esc_html(
				is_array( $last )
					? sprintf( '後片付け（期限切れのジョブ・新規作成枠の回収・保存の中断からの復帰・古い記録の削除）の前回の実行：%s（%s）', wp_date( 'Y-m-d H:i', (int) $last['at'] ), array( 'cron' => 'WP-Cron', 'cli' => 'WP-CLI', 'admin' => '管理画面' )[ (string) $last['via'] ] ?? (string) $last['via'] )
					: '後片付けはまだ実行されていません。'
			)
		);
		echo '<p class="description">' . esc_html( 'WP-Cron が動かない環境では、サーバーの cron から毎時「wp --path=<WordPressのディレクトリ> ai-case maintenance」を実行してください。管理者が管理画面を開いたときにも、6時間に1回まで自動で実行します。生成の処理（外部への送信）は、利用者の画面からの操作だけで進みます。' ) . '</p>';

		// サポートID
		echo '<h2>' . esc_html( 'サポートIDで記録を確認' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wp_ai_case_support_lookup' );
		echo '<input type="hidden" name="action" value="wp_ai_case_support_lookup">';
		echo '<input type="text" name="support_id" class="regular-text" placeholder="xxxxxxxx-xxxx-4xxx-xxxx-xxxxxxxxxxxx" pattern="[0-9a-fA-F\-]{36}"> ';
		submit_button( '確認', 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html( 'エラーコード一覧' ) . '</h2><table class="widefat striped" style="max-width:1000px"><thead><tr><th style="white-space:nowrap">コード</th><th>お客様への表示</th><th>管理者の対応</th></tr></thead><tbody>';
		foreach ( ErrorCatalog::codes() as $code ) {
			printf( '<tr><td style="white-space:nowrap">%s</td><td>%s</td><td>%s</td></tr>', esc_html( $code ), esc_html( ErrorCatalog::message( $code, 'customer' ) ), esc_html( ErrorCatalog::message( $code, 'admin' ) ) );
		}
		echo '</tbody></table></div>';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_support( array $row ): void {
		$user = get_userdata( (int) $row['user_id'] );
		echo '<table class="widefat" style="max-width:1000px"><tbody>';
		foreach ( array(
			'サポートID' => $row['support_id'],
			'コード'     => $row['code'],
			'出来事'     => $row['event'],
			'日時（UTC）' => $row['created_at'],
			'操作者'     => $user ? $user->display_name : '（不明）',
			'詳細'       => wp_json_encode( $row['details'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
		) as $k => $v ) {
			printf( '<tr><th style="width:140px">%s</th><td><pre style="margin:0;white-space:pre-wrap">%s</pre></td></tr>', esc_html( $k ), esc_html( (string) $v ) );
		}
		echo '</tbody></table>';
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この操作は管理者だけが行えます。', 'wp-ai-case' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private static function flash( array $data ): void {
		set_transient( self::FLASH . get_current_user_id(), $data, 120 );
		wp_safe_redirect( Menu::diagnostics_url() );
		exit;
	}
}
