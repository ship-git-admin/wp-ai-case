<?php
/**
 * C1-DIAG：設定不足・安全な診断・エラー分類・権限の試験。
 *
 * 外部（Claude API）への通信は pre_http_request で数え、試験中は遮断する。
 * 設定（モデル・接続テストの状態・間隔制限）は試験の前に控え、最後に必ず戻す。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev\SelfTest;

use WpAiCase\Admin\DiagnosticsPage;
use WpAiCase\Admin\Notices;
use WpAiCase\AI\ProviderResult;
use WpAiCase\Dev\MockProvider;
use WpAiCase\Diagnostics\DiagnosticsService;
use WpAiCase\Diagnostics\ErrorCatalog;
use WpAiCase\Diagnostics\ErrorClassifier;
use WpAiCase\Diagnostics\PreflightService;
use WpAiCase\Diagnostics\ProviderProbe;
use WpAiCase\Diagnostics\Redactor;
use WpAiCase\Diagnostics\SafeErrorPresenter;
use WpAiCase\Diagnostics\Settings;
use WpAiCase\Diagnostics\SupportLog;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class DiagnosticsTests {

	private int $external_calls = 0;

	/** 顧客向けの文面に出てはいけないもの。 */
	private const FORBIDDEN_FOR_CUSTOMER = array( 'wp-config', 'WP_AI_ANTHROPIC', 'sk-ant', 'http://', 'https://', 'PHP ', '/home', 'x-api-key', 'Authorization', 'Basic ', 'Bearer' );

	public function __construct( private Tester $t, private int $admin ) {
	}

	public function run(): void {
		$saved = array(
			'settings' => get_option( Settings::OPTION, null ),
			'state'    => get_option( ProviderProbe::STATE_OPTION, null ),
			'count'    => get_option( 'wp_ai_case_probe_count', null ),
			'mock'     => get_option( MockProvider::OPTION, null ),
		);
		$guard = function ( $pre, $args, $url ) {
			if ( is_string( $url ) && str_contains( $url, 'anthropic.com' ) ) {
				++$this->external_calls;
				return new \WP_Error( 'wpaic_test_blocked', 'blocked in selftest' );
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $guard, 1, 3 );
		try {
			$this->tests();
		} finally {
			remove_filter( 'pre_http_request', $guard, 1 );
			Settings::$test_status = null;
			foreach ( array( Settings::OPTION => 'settings', ProviderProbe::STATE_OPTION => 'state', 'wp_ai_case_probe_count' => 'count', MockProvider::OPTION => 'mock' ) as $option => $key ) {
				null === $saved[ $key ] ? delete_option( $option ) : update_option( $option, $saved[ $key ], false );
			}
			delete_transient( 'wp_ai_case_probe_cooldown' );
			wp_set_current_user( $this->admin );
			$this->t->test( 'D-99', '試験中に Claude API への通信が 0 回（設定は元に戻した）', fn() => $this->t->same( 0, $this->external_calls, '外部通信の回数' ) );
		}
	}

	private function tests(): void {
		$t = $this->t;
		wp_set_current_user( $this->admin );
		foreach ( array( 'template.php', 'class-wp-screen.php', 'screen.php' ) as $file ) {
			require_once ABSPATH . 'wp-admin/includes/' . $file;
		}

		$t->test( 'D-01', 'C1-DIAG01 APIキー未設定 → 画面表示時も生成直前も AI-001。外部通信0・ジョブ0・投稿0', function () {
			Settings::$test_status = 'not_configured';
			$posts                 = (int) wp_count_posts( 'works' )->draft;
			$a                     = PreflightService::check( $this->admin );
			$b                     = PreflightService::check( $this->admin, true );
			$this->t->same( array( 'AI-001', 'AI-001' ), array( $a['code'], $b['code'] ), 'コード' );
			$this->t->same( $posts, (int) wp_count_posts( 'works' )->draft, '下書きが増えた' );
			$this->t->same( 0, $this->external_calls, '外部通信' );
		} );

		$t->test( 'D-02', 'C1-DIAG02 一般顧客向けの文面に設定方法・キー・サーバー情報・生の応答を含めない', function () {
			Settings::$test_status = 'not_configured';
			foreach ( ErrorCatalog::codes() as $code ) {
				$msg = ErrorCatalog::message( $code, 'customer' );
				foreach ( self::FORBIDDEN_FOR_CUSTOMER as $bad ) {
					$this->t->true( ! str_contains( $msg, $bad ), "{$code} の顧客向け文面に {$bad}" );
				}
			}
			$e = PreflightService::check( $this->admin, true )['error'];
			$this->t->same( 'customer', $e['visibility'], 'visibility' );
			$this->t->true( ! array_key_exists( 'provider_http_status_class', $e ), '顧客向けに技術分類を含めない' );
			$this->t->true( wp_is_uuid( $e['support_id'], 4 ), 'support_id は uuid v4' );
			$this->check_error_response( $e );
		} );

		$t->test( 'D-03', 'C1-DIAG03/16 診断・サポート記録の REST は管理者だけ。匿名・非管理者は拒否', function () {
			$diag = '/wp-ai-case/v1/diagnostics';
			$sid  = SafeErrorPresenter::present( 'AI-018', 'customer', 'selftest' )['support_id'];
			wp_set_current_user( 0 );
			$this->t->same( 401, rest_do_request( new \WP_REST_Request( 'GET', $diag ) )->get_status(), '匿名：診断' );
			$this->t->same( 401, rest_do_request( new \WP_REST_Request( 'GET', '/wp-ai-case/v1/preflight' ) )->get_status(), '匿名：プリフライト' );
			$this->t->same( false, \WpAiCase\Rest\AjaxController::can_use( 0 ), '匿名：admin-ajax' );
			$this->t->same( false, has_action( 'wp_ajax_nopriv_wp_ai_case_preflight' ), '未ログイン用の admin-ajax を登録しない' );
			wp_set_current_user( $this->admin );
			$deny = static function ( $allcaps ) {
				$allcaps['manage_options'] = false;
				return $allcaps;
			};
			add_filter( 'user_has_cap', $deny );
			try {
				$this->t->same( 403, rest_do_request( new \WP_REST_Request( 'GET', $diag ) )->get_status(), '非管理者：診断' );
				$this->t->same( 403, rest_do_request( new \WP_REST_Request( 'GET', "/wp-ai-case/v1/support/{$sid}" ) )->get_status(), '非管理者：サポート記録' );
				// 顧客向けは admin-ajax（サイトの設定で非管理者の REST が止められていても使える）。
				$this->t->true( \WpAiCase\Rest\AjaxController::can_use( $this->admin ), '編集権限のある非管理者は利用可' );
				$pre = \WpAiCase\Rest\AjaxController::preflight( $this->admin );
				$this->t->true( ! str_contains( (string) wp_json_encode( $pre ), 'checks' ), 'プリフライトに診断の中身を含めない' );
				$this->t->true( null === $pre['error'] || 'customer' === $pre['error']['visibility'], '顧客向けの文面' );
			} finally {
				remove_filter( 'user_has_cap', $deny );
			}
			$ok = rest_do_request( new \WP_REST_Request( 'GET', $diag ) );
			$this->t->same( 200, $ok->get_status(), '管理者：診断' );
			$this->t->same( 200, rest_do_request( new \WP_REST_Request( 'GET', "/wp-ai-case/v1/support/{$sid}" ) )->get_status(), '管理者：サポート記録' );
		} );

		$t->test( 'D-04', 'C1-DIAG04 管理者画面に wp-config.php の安全な設定例を出し、キーの値は一切出さない', function () {
			Settings::$test_status = 'not_configured';
			$html = $this->capture( array( DiagnosticsPage::class, 'render' ) );
			foreach ( array( 'WP_AI_ANTHROPIC_API_KEY', 'wp-settings.php', '1Password', '未設定（AI-001）', 'ローカル診断を再実行' ) as $must ) {
				$this->t->true( str_contains( $html, $must ), "画面に {$must} がない" );
			}
			$this->t->true( ! preg_match( '/sk-ant-[A-Za-z0-9]/', $html ), '画面にキーらしき文字列' );
			$this->t->true( ! str_contains( $html, 'name="api_key"' ) && ! str_contains( $html, 'type="password"' ), 'キーの入力欄を作らない' );
			Settings::$test_status = 'configured';
			$html = $this->capture( array( DiagnosticsPage::class, 'render' ) );
			$this->t->true( str_contains( $html, '設定済み（値は表示しません' ), '設定済みの表示' );
		} );

		$t->test( 'D-05', 'C1-DIAG05 ローカル診断・画面表示では外部通信0。「設定済み」を「接続成功」と表示しない', function () {
			Settings::$test_status = 'configured';
			Settings::set_model( 'claude-opus-5-5' );
			$before = $this->external_calls;
			$d      = DiagnosticsService::run();
			$this->capture( array( DiagnosticsPage::class, 'render' ) );
			$this->t->same( $before, $this->external_calls, '外部通信' );
			$this->t->same( false, $d['external_api_called'], 'external_api_called' );
			$this->t->same( 'local_only', $d['scope'], 'scope' );
			$this->t->true( in_array( $d['summary'], array( 'ready_unverified', 'blocked_dependency' ), true ), 'summary: ' . $d['summary'] );
			$this->t->true( ! str_contains( $d['safe_admin_message'], '成功' ), '成功と表示している' );
			$this->check_diagnostic_result( $d );
		} );

		$t->test( 'D-06', 'C1-DIAG06 接続テストは管理者の明示操作だけ：同意なし・非管理者・未設定・間隔制限では通信しない', function () {
			Settings::$test_status = 'configured';
			Settings::set_model( 'claude-opus-5-5' );
			delete_transient( 'wp_ai_case_probe_cooldown' );
			$mock         = new MockProvider( 'ok' );
			MockProvider::$calls = 0;
			$this->t->same( 'skipped', ProviderProbe::run( $this->admin, false, $mock )['outcome'], '同意なし' );
			$this->t->throws_case( CaseError::FORBIDDEN, fn() => ProviderProbe::run( 0, true, $mock ) );
			Settings::$test_status = 'not_configured';
			$this->t->same( 'AI-001', ProviderProbe::run( $this->admin, true, $mock )['code'], 'キー未設定' );
			$this->t->same( 0, MockProvider::$calls, 'ここまで呼んでいない' );
			Settings::$test_status = 'configured';
			$r = ProviderProbe::run( $this->admin, true, $mock );
			$this->t->same( 'passed', $r['outcome'], '成功' );
			$this->t->true( str_contains( $r['message'], '保証するものではありません' ), '成功の意味を限定して表示' );
			$this->t->same( 'cooldown', ProviderProbe::run( $this->admin, true, $mock )['code'], '間隔制限' );
			$this->t->same( 1, MockProvider::$calls, '呼び出しは1回だけ' );
		} );

		$t->test( 'D-07', 'C1-DIAG07/08 401/402/403/404/413/429/5xx/529/通信失敗/タイムアウトを別々のコードに分類（一律「キー無効」にしない）', function () {
			$expect = array(
				'401'     => 'AI-002',
				'402'     => 'AI-003',
				'403'     => 'AI-003',
				'404'     => 'AI-004',
				'413'     => 'AI-008',
				'429'     => 'AI-005',
				'500'     => 'AI-006',
				'529'     => 'AI-006',
				'network' => 'AI-007',
				'timeout' => 'AI-007',
			);
			foreach ( $expect as $scenario => $code ) {
				$c = ErrorClassifier::from_provider( ( new MockProvider( $scenario ) )->probe( 'claude-opus-5-5' ) );
				$this->t->same( $code, $c['code'] ?? null, $scenario );
			}
			$this->t->same( null, ErrorClassifier::from_provider( ProviderResult::success( 200 ) ), '成功' );
			$this->t->true( ErrorCatalog::retryable( 'AI-005' ) && ErrorCatalog::retryable( 'AI-006' ) && ! ErrorCatalog::retryable( 'AI-002' ) && ! ErrorCatalog::retryable( 'AI-016' ), '再試行可否' );
		} );

		$t->test( 'D-08', '接続テストで認証拒否（AI-002）が分かったら、直すまで生成を始めない。診断は「要対応」', function () {
			Settings::$test_status = 'configured';
			Settings::set_model( 'claude-opus-5-5' );
			delete_transient( 'wp_ai_case_probe_cooldown' );
			$r = ProviderProbe::run( $this->admin, true, new MockProvider( '401' ) );
			$this->t->same( 'AI-002', $r['code'], '分類' );
			$this->t->same( 'AI-002', PreflightService::check( $this->admin )['code'], 'プリフライト' );
			$this->t->same( 'needs_attention', DiagnosticsService::run()['summary'], '診断' );
			Settings::set_model( 'claude-sonnet-5-5' );
			$this->t->same( null, ProviderProbe::last(), 'モデルを変えたら前の結果は使わない' );
		} );

		$t->test( 'D-09', 'C1-DIAG11/16 サポート記録：秘密・URL・パス・IP・PHP版を消し、許可した項目だけを残す', function () {
			$e = SafeErrorPresenter::present(
				'AI-007',
				'admin',
				'selftest',
				array(
					'reason'  => 'x ' . 'sk' . '-ant-api03-ABCDEF Bearer abc.def https://api.example.test/v1 /home/user/site 192.168.0.1 PHP 8.4.27',
					'headers' => 'Authorization: Basic c2hpcDpzaGlw',
					'body'    => '{"error":"raw upstream"}',
				),
				'network'
			);
			$row = SupportLog::get( $e['support_id'] );
			$txt = (string) wp_json_encode( $row );
			foreach ( array( 'sk' . '-ant-api03', 'abc.def', 'example.test', '/home/user', '192.168.0.1', '8.4.27', 'c2hpcDpzaGlw', 'raw upstream' ) as $bad ) {
				$this->t->true( ! str_contains( $txt, $bad ), "記録に {$bad} が残っている" );
			}
			$this->t->same( 'AI-007', $row['code'], 'コード' );
			$this->t->same( null, SupportLog::get( 'not-a-uuid' ), '不正なIDは null' );
			$this->t->same( '[secret]', Redactor::scrub( 'sk' . '-ant-xyz' ), 'scrub' );
		} );

		$t->test( 'D-10', 'C1-DIAG14 キー未設定でも、管理者への通知だけを出す（対象画面だけ、一般ユーザーには出さない）', function () {
			Settings::$test_status = 'not_configured';
			set_current_screen( 'dashboard' );
			$html = $this->capture( array( Notices::class, 'render' ) );
			$this->t->true( str_contains( $html, 'AI-001' ) && str_contains( $html, 'サイトの他の機能には影響しません' ), '通知' );
			$deny = static function ( $allcaps ) {
				$allcaps['manage_options'] = false;
				return $allcaps;
			};
			add_filter( 'user_has_cap', $deny );
			try {
				$this->t->same( '', $this->capture( array( Notices::class, 'render' ) ), '非管理者' );
			} finally {
				remove_filter( 'user_has_cap', $deny );
			}
			set_current_screen( 'edit-page' );
			$this->t->same( '', $this->capture( array( Notices::class, 'render' ) ), '対象外の画面' );
		} );

		$t->test( 'D-11', 'C1-DIAG12 前段の確認は入力を消さない（ジョブも投稿も作らない）。設定を直せば同じ確認が通る', function () {
			Settings::$test_status = 'not_configured';
			$this->t->same( 'AI-001', PreflightService::check( $this->admin )['code'], '直す前' );
			Settings::$test_status = 'configured';
			Settings::set_model( 'claude-opus-5-5' );
			ProviderProbe::forget();
			$this->t->same( true, PreflightService::check( $this->admin )['ok'], '直した後' );
		} );
	}

	/**
	 * @param callable():void $fn
	 */
	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $e
	 */
	private function check_error_response( array $e ): void {
		$keys = array( 'success', 'error_code', 'visibility', 'message', 'retryable', 'support_id', 'recovery_action' );
		$this->t->same( array(), array_values( array_diff( $keys, array_keys( $e ) ) ), 'error_response の必須項目' );
		$this->t->same( false, $e['success'], 'success' );
		$this->t->true( (bool) preg_match( '/^AI-0(0[1-9]|1[0-8])$/', $e['error_code'] ), 'error_code の形式' );
		$this->t->true( mb_strlen( $e['message'] ) <= 300, 'message の長さ' );
	}

	/**
	 * @param array<string,mixed> $d
	 */
	private function check_diagnostic_result( array $d ): void {
		$keys = array( 'diagnosis_id', 'checked_at', 'scope', 'summary', 'external_api_called', 'checks', 'safe_admin_message' );
		$this->t->same( $keys, array_keys( $d ), 'diagnostic_result の項目' );
		$this->t->true( mb_strlen( $d['safe_admin_message'] ) <= 280, 'safe_admin_message の長さ' );
		foreach ( $d['checks'] as $c ) {
			$this->t->same( array(), array_values( array_diff( array_keys( $c ), array( 'check', 'status', 'error_code', 'action_code' ) ) ), 'checks の項目' );
		}
		$json = (string) wp_json_encode( $d );
		foreach ( array( 'sk-ant', 'http://', 'https://', '/home', 'PHP ' ) as $bad ) {
			$this->t->true( ! str_contains( $json, $bad ), "診断結果に {$bad}" );
		}
	}
}
