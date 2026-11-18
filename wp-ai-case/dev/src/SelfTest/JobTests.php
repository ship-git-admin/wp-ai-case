<?php
/**
 * C1-C 後半：生成ジョブ（入力 → 解析・下書き → 修正 → 保存）の試験。プロバイダーは Mock（外部通信なし）。
 * 作ったジョブ・下書きは最後に必ず削除し、設定は元に戻す。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev\SelfTest;

use WpAiCase\Dev\MockProvider;
use WpAiCase\Dev\Poc;
use WpAiCase\Diagnostics\ProviderProbe;
use WpAiCase\Diagnostics\Settings;
use WpAiCase\Jobs\ImagePreparer;
use WpAiCase\Jobs\JobError;
use WpAiCase\Jobs\JobService;
use WpAiCase\Plugin;
use WpAiCase\Support\Installer;

defined( 'ABSPATH' ) || exit;

final class JobTests {

	/** @var string[] */
	private array $jobs = array();
	private int $external = 0;

	public function __construct( private Tester $t, private int $user ) {
	}

	public function run(): void {
		$images = Poc::images();
		if ( count( $images ) < 4 ) {
			$this->t->test( 'J-00', '前提：合成画像', fn() => throw new SkipTest( '合成画像が未登録です（wp ai-case poc seed-images）' ) );
			return;
		}
		$saved_state = get_option( ProviderProbe::STATE_OPTION, null );
		ProviderProbe::forget();
		$guard = function ( $pre, $args, $url ) {
			if ( false === $pre && str_contains( (string) $url, 'anthropic.com' ) ) {
				++$this->external;
				return new \WP_Error( 'wpaic_test_blocked', 'blocked' );
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $guard, 1, 3 );
		wp_set_current_user( $this->user );
		try {
			$this->tests( $images );
		} finally {
			remove_filter( 'pre_http_request', $guard, 1 );
			Settings::$test_status = null;
			MockProvider::$during  = null;
			null === $saved_state ? delete_option( ProviderProbe::STATE_OPTION ) : update_option( ProviderProbe::STATE_OPTION, $saved_state, false );
			$this->cleanup();
		}
	}

	/**
	 * @param array<string,int> $images
	 */
	private function input( array $images, array $override = array() ): array {
		$ids = array_values( array_intersect_key( $images, array_flip( array( 'before1', 'before2', 'during1', 'after1', 'after2' ) ) ) );
		$rel = Poc::default_relations();
		return array_replace_recursive(
			array(
				'client_case_id'        => 'selftest',
				'idempotency_key'       => wp_generate_uuid4(),
				'mode'                  => 'create',
				'post_type'             => 'works',
				'primary_facts'         => array( 'overview' => '外壁塗装（試験）', 'client_request' => '色あせを直したい（試験）', 'work_done' => '3回塗り（試験）', 'area' => '〇〇市（試験）' ),
				'photo_set_description' => '施工前・中・後の写真（試験）',
				'images'                => array_map( static fn( $id, $i ) => array( 'attachment_id' => $id, 'original_position' => $i, 'caption' => '', 'user_phase_hint' => 'unknown' ), $ids, array_keys( $ids ) ),
				'consent_external_ai'   => true,
				'relations'             => array( 'customer_voice_post_ids' => array( $rel['voice'] ), 'staff' => array( array( 'post_id' => $rel['staff'], 'comment' => '試験' ) ), 'supervisor_post_ids' => array() ),
			),
			$override
		);
	}

	private function service( string $scenario = 'ok' ): JobService {
		return new JobService( null, new MockProvider( $scenario ) );
	}

	/**
	 * @param array<string,mixed> $in
	 */
	private function create( JobService $s, array $in ): array {
		$job            = $s->create( $in, $this->user );
		$this->jobs[]   = $job['job_id'];
		return $job;
	}

	private function err( callable $fn ): JobError {
		try {
			$fn();
		} catch ( JobError $e ) {
			return $e;
		}
		throw new \RuntimeException( 'JobError になりませんでした' );
	}

	/**
	 * @param array<string,int> $images
	 */
	private function tests( array $images ): void {
		$t = $this->t;
		$state = array();

		$t->test( 'J-01', 'C1-UI01 必須の一次情報・写真一式の説明・写真・同意が欠けたら AI-017、ジョブ0・外部0', function () use ( $images ) {
			Settings::$test_status = 'configured';
			MockProvider::$calls   = 0;
			$s = $this->service();
			$e = $this->err( fn() => $s->create( $this->input( $images, array( 'primary_facts' => array( 'overview' => '' ), 'consent_external_ai' => false, 'photo_set_description' => '' ) ), $this->user ) );
			$this->t->same( 'AI-017', $e->response['error_code'], 'コード' );
			foreach ( array( 'primary_facts.overview', 'photo_set_description', 'consent_external_ai' ) as $f ) {
				$this->t->true( in_array( $f, $e->extra['fields'] ?? array(), true ), "不足項目 {$f}" );
			}
			$in           = $this->input( $images );
			$in['images'] = array();
			$this->t->same( 'AI-017', $this->err( fn() => $s->create( $in, $this->user ) )->response['error_code'], '写真0枚' );
			$in           = $this->input( $images );
			$in['images'][1]['attachment_id'] = $in['images'][0]['attachment_id'];
			$this->t->same( 'AI-009', $this->err( fn() => $s->create( $in, $this->user ) )->response['error_code'], '写真の重複' );
			$in = $this->input( $images );
			$in['images'][0]['attachment_id'] = (int) get_posts( array( 'post_type' => 'works', 'posts_per_page' => 1, 'fields' => 'ids' ) )[0];
			$this->t->same( 'AI-009', $this->err( fn() => $s->create( $in, $this->user ) )->response['error_code'], '画像でないID' );
			$this->t->same( 0, MockProvider::$calls, '外部呼び出し' );
			$this->t->same( 0, (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . Installer::jobs_table() . ' WHERE user_id = %d AND created_at > %s', $this->user, gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ), 'ジョブが作られた' );
		} );

		$t->test( 'J-02', 'C1-DIAG01 APIキー未設定ならジョブを作らない（AI-001）', function () use ( $images ) {
			Settings::$test_status = 'not_configured';
			$e = $this->err( fn() => $this->service()->create( $this->input( $images ), $this->user ) );
			$this->t->same( 'AI-001', $e->response['error_code'], 'コード' );
			Settings::$test_status = 'configured';
		} );

		$t->test( 'J-03', 'C1-UI02/03/04 作成→解析（全写真1回ずつ）→ CaseDocument（事実は入力だけ）', function () use ( $images, &$state ) {
			Settings::$test_status = 'configured';
			MockProvider::$calls   = 0;
			MockProvider::$payloads = array();
			$s   = $this->service();
			$in  = $this->input( $images );
			$job = $this->create( $s, $in );
			$this->t->same( 'queued', $job['status'], '作成直後' );
			$job = $s->tick( $job['job_id'], $this->user );
			$this->t->same( 'proposal_ready', $job['status'], '解析後' );
			$doc = $job['data']['versions'][0];
			$ids = array_map( static fn( $i ) => $i['attachment_id'], $in['images'] );
			$this->t->same( $ids, array_map( static fn( $i ) => $i['attachment_id'], $doc['images'] ), '写真の集合と順番' );
			$this->t->same( array( 'overview', 'client_request', 'work_done', 'area' ), array_keys( $doc['facts'] ), '事実は入力された項目だけ' );
			$this->t->true( ! isset( $doc['facts']['cost'] ) && ! isset( $doc['facts']['duration'] ), '費用・工期を推測で入れない' );
			$this->t->same( 1, MockProvider::$calls, '外部呼び出しは1回' );
			$p = MockProvider::$payloads[0];
			$this->t->true( ! isset( $p['images'][0]['data'] ), 'Mock には画像データを渡さない（Claude のときだけ縮小コピーを作る）' );
			$this->t->same( array(), array_values( array_diff( array_keys( $p ), array( 'model', 'mode', 'facts', 'photo_set_description', 'images', 'prior_analysis' ) ) ), '送る項目（関連付け・投稿IDなどを送らない）' );
			$this->t->same( 'claude-haiku-5-5', $p['model'], 'モデル' );
			$this->t->true( ! empty( $job['data']['questions'] ), 'AIからの確認事項' );
			$state['job'] = $job;
		} );

		$t->test( 'J-04', '同じ idempotency_key の再送は同じジョブ。別ユーザーの同じキーは拒否（AI-012）', function () use ( $images, &$state ) {
			$job = $state['job'] ?? null;
			if ( ! $job ) {
				throw new SkipTest( 'J-03 が失敗' );
			}
			$s     = $this->service();
			$again = $s->create( $this->input( $images, array( 'idempotency_key' => $job['idempotency_key'] ) ), $this->user );
			$this->t->same( $job['job_id'], $again['job_id'], '同じジョブ' );
			$other = get_users( array( 'role' => 'administrator', 'exclude' => array( $this->user ), 'number' => 1, 'fields' => 'ID' ) );
			if ( $other ) {
				$e = $this->err( fn() => $s->create( $this->input( $images, array( 'idempotency_key' => $job['idempotency_key'] ) ), (int) $other[0] ) );
				$this->t->same( 'AI-012', $e->response['error_code'], '別ユーザー' );
				$deny = static function ( $allcaps, $caps, $args ) use ( $other ) {
					if ( (int) ( $args[1] ?? 0 ) === (int) $other[0] ) {
						$allcaps['manage_options'] = false;
					}
					return $allcaps;
				};
				add_filter( 'user_has_cap', $deny, 10, 3 );
				try {
					$this->t->same( 404, $this->err( fn() => $s->get_owned( $job['job_id'], (int) $other[0] ) )->status, '他人のジョブは見えない' );
				} finally {
					remove_filter( 'user_has_cap', $deny, 10 );
				}
			}
		} );

		$t->test( 'J-05', 'C1-UI05/06/08 画面での修正（複数・型付き）、基準版の不一致・許可外・不正な操作は拒否', function () use ( &$state ) {
			$job = $state['job'] ?? null;
			if ( ! $job ) {
				throw new SkipTest( 'J-03 が失敗' );
			}
			$s   = $this->service();
			$doc = $job['data']['versions'][0];
			$ids = array_map( static fn( $i ) => $i['attachment_id'], $doc['images'] );
			$job = $s->revise_local( $job['job_id'], $this->user, 0, array( 'title', 'photos', 'facts' ), array(
				array( 'op' => 'text_set', 'section' => 'title', 'value' => '【試験】タイトル変更' ),
				array( 'op' => 'image_caption_set', 'section' => 'photos', 'attachment_id' => $ids[0], 'caption' => '試験キャプション' ),
				array( 'op' => 'fact_set', 'section' => 'facts', 'fact_key' => 'duration', 'value' => '約10日（入力）', 'source' => 'user_input' ),
			) );
			$cur = $job['data']['versions'][ $job['data']['index'] ];
			$this->t->same( array( 1, '【試験】タイトル変更', '試験キャプション', '約10日（入力）' ), array( $job['data']['index'], $cur['title'], $cur['images'][0]['caption'], $cur['facts']['duration']['value'] ), '反映' );
			$this->t->same( $doc['sections'], $cur['sections'], '選んでいない本文は変わらない' );
			$bad = array(
				'基準版の不一致'   => array( 0, array( 'title' ), array( array( 'op' => 'text_set', 'section' => 'title', 'value' => 'x' ) ) ),
				'許可外の部分'     => array( 1, array( 'title' ), array( array( 'op' => 'seo_set', 'section' => 'seo', 'field' => 'title', 'value' => 'x' ) ) ),
				'未展開の all'     => array( 1, array( 'all' ), array( array( 'op' => 'text_set', 'section' => 'title', 'value' => 'x' ) ) ),
				'存在しない本文'   => array( 1, array( 'body' ), array( array( 'op' => 'body_section_set', 'section' => 'body', 'node_id' => 'nope', 'heading' => 'x', 'body' => 'y' ) ) ),
				'下書きにない写真' => array( 1, array( 'photos' ), array( array( 'op' => 'image_caption_set', 'section' => 'photos', 'attachment_id' => 999999999, 'caption' => 'x' ) ) ),
				'並びの欠け'       => array( 1, array( 'photos' ), array( array( 'op' => 'image_order_set', 'section' => 'photos', 'attachment_ids' => array_slice( $ids, 1 ) ) ) ),
				'自由なACFキー'    => array( 1, array( 'title' ), array( array( 'op' => 'acf_set', 'section' => 'title', 'field_key' => 'field_x', 'value' => 'x' ) ) ),
				'必須の事実を空に' => array( 1, array( 'facts' ), array( array( 'op' => 'fact_set', 'section' => 'facts', 'fact_key' => 'overview', 'value' => '', 'source' => 'user_input' ) ) ),
			);
			foreach ( $bad as $label => [ $base, $allowed, $ops ] ) {
				$e = $this->err( fn() => $s->revise_local( $job['job_id'], $this->user, $base, $allowed, $ops ) );
				$this->t->true( in_array( $e->response['error_code'], array( 'AI-009', 'AI-012' ), true ), $label );
			}
			$this->t->same( 1, $s->get_owned( $job['job_id'], $this->user )['data']['index'], '拒否された修正は版を増やさない' );
			$state['job'] = $s->get_owned( $job['job_id'], $this->user );
		} );

		$t->test( 'J-06', 'AIによる修正：選んだ部分だけ、AIが許可外の操作（事実の変更）を混ぜたら全体を拒否', function () use ( &$state ) {
			$job = $state['job'] ?? null;
			if ( ! $job ) {
				throw new SkipTest( '前の試験が失敗' );
			}
			MockProvider::$payloads = array();
			$s   = $this->service();
			$job = $s->revise_ai( $job['job_id'], $this->user, 1, array( 'title', 'seo' ), 'タイトルを分かりやすく' );
			$cur = $job['data']['versions'][ $job['data']['index'] ];
			$this->t->same( 2, $job['data']['index'], '版' );
			$this->t->true( str_starts_with( $cur['title'], '【修正】' ) && '【修正】SEOタイトル' === $cur['seo']['title'], 'AIの修正' );
			$p = MockProvider::$payloads[0];
			$this->t->same( array( 'title', 'seo' ), $p['allowed_sections'], '許可した部分だけを渡す' );
			$this->t->true( ! str_contains( (string) wp_json_encode( $p ), 'base64' ), '画像データを送らない' );
			$e = $this->err( fn() => $this->service( 'evil' )->revise_ai( $job['job_id'], $this->user, 2, array( 'title' ), '修正して' ) );
			$this->t->same( 'AI-009', $e->response['error_code'], '許可外の操作を含む提案' );
			$this->t->same( 2, $s->get_owned( $job['job_id'], $this->user )['data']['index'], '拒否された提案は反映しない' );
			$state['job'] = $s->get_owned( $job['job_id'], $this->user );
		} );

		$t->test( 'J-07', 'C1-UI06 元に戻す・やり直す', function () use ( &$state ) {
			$job = $state['job'] ?? null;
			if ( ! $job ) {
				throw new SkipTest( '前の試験が失敗' );
			}
			$s   = $this->service();
			$job = $s->move( $job['job_id'], $this->user, 2, -1 );
			$this->t->same( 1, $job['data']['index'], '元に戻す' );
			$job = $s->move( $job['job_id'], $this->user, 1, 1 );
			$this->t->same( 2, $job['data']['index'], 'やり直す' );
			$this->t->same( 409, $this->err( fn() => $s->move( $job['job_id'], $this->user, 2, 1 ) )->status, '範囲外' );
			$state['job'] = $job;
		} );

		$t->test( 'J-08', '下書きとして保存：承認済みマッピングで保存・読み戻し、下書きのまま、ジョブは committed', function () use ( &$state ) {
			$job = $state['job'] ?? null;
			if ( ! $job ) {
				throw new SkipTest( '前の試験が失敗' );
			}
			$s   = $this->service();
			$job = $s->save( $job['job_id'], $this->user, 2 );
			$this->t->same( 'committed', $job['status'], '状態' );
			$post = get_post( (int) $job['post_id'] );
			$this->t->same( array( 'works', 'draft' ), array( $post->post_type, $post->post_status ), '下書き' );
			$this->t->true( str_starts_with( $post->post_title, '【修正】' ), '最新の版のタイトル' );
			$audit = get_post_meta( $post->ID, '_wp_ai_case_last_write', true );
			$this->t->same( array( 'ok', $job['data']['snapshot']['mapping_id'] ), array( $audit['readback'] ?? null, $audit['mapping_id'] ?? null ), '読み戻し・マッピング' );
			$this->t->same( 409, $this->err( fn() => $s->save( $job['job_id'], $this->user, 2 ) )->status, '保存後は再保存できない' );
		} );

		$t->test( 'J-09', 'C1-CON02 解析中に取り消したら、戻ってきた結果は捨てる（保存も版も作らない）', function () use ( $images ) {
			$s   = $this->service();
			$job = $this->create( $s, $this->input( $images ) );
			MockProvider::$during = function () use ( $s, $job ) {
				MockProvider::$during = null;
				$s->cancel( $job['job_id'], $this->user );
			};
			$after = $s->tick( $job['job_id'], $this->user );
			$this->t->same( 'cancelled', $after['status'], '状態' );
			$this->t->same( array(), $after['data']['versions'], '結果を保存していない' );
		} );

		$t->test( 'J-10', 'C1-DIAG13 送信中に処理が止まったら、自動で再送せず要確認。利用者の再試行で初めて再送', function () use ( $images ) {
			$s    = $this->service();
			$job  = $this->create( $s, $this->input( $images ) );
			$data = $job['data'];
			$data['provider'] = array( 'state' => 'sending', 'attempt' => wp_generate_uuid4(), 'started_at' => time() - 600, 'step' => 0 );
			$job  = $s->repository()->compare_and_set( $job, array( 'status' => 'analyzing', 'data' => $data ) );
			MockProvider::$calls = 0;
			$job  = $s->tick( $job['job_id'], $this->user );
			$this->t->same( 'needs_recovery', $job['status'], '要確認' );
			$this->t->same( 0, MockProvider::$calls, '自動で再送しない' );
			$this->t->same( 'AI-011', $job['data']['error']['error_code'], 'コード' );
			$job = $s->retry( $job['job_id'], $this->user );
			$this->t->same( array( 'queued', 2 ), array( $job['status'], $job['job_generation'] ), '再試行（世代が上がる）' );
			$job = $s->tick( $job['job_id'], $this->user );
			$this->t->same( array( 'proposal_ready', 1 ), array( $job['status'], MockProvider::$calls ), '再送して完了' );
		} );

		$t->test( 'J-11', 'AIの結果に依頼していない写真があれば AI-009 で止め、再試行できる。429/401 は分類して止める', function () use ( $images ) {
			foreach ( array( 'bad_ids' => 'AI-009', '429' => 'AI-005', '401' => 'AI-002', 'timeout' => 'AI-007' ) as $scenario => $code ) {
				$s   = $this->service( $scenario );
				$job = $this->create( $s, $this->input( $images ) );
				$job = $s->tick( $job['job_id'], $this->user );
				$this->t->same( array( 'failed', $code ), array( $job['status'], $job['data']['error']['error_code'] ?? null ), $scenario );
				$this->t->same( 'customer', $job['data']['error']['visibility'], '顧客向けの文面' );
			}
		} );

		$t->test( 'J-12', '期限切れのジョブは進めない', function () use ( $images ) {
			global $wpdb;
			$s   = $this->service();
			$job = $this->create( $s, $this->input( $images ) );
			$wpdb->update( Installer::jobs_table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'job_id' => $job['job_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			MockProvider::$calls = 0;
			$job = $s->tick( $job['job_id'], $this->user );
			$this->t->same( array( 'expired', 0 ), array( $job['status'], MockProvider::$calls ), '期限切れ' );
		} );

		$t->test( 'J-13', '写真が多いときは分割して解析する計画（1ステップ＝外部呼び出し1回）', function () {
			$this->t->same( array( 'full' ), JobService::plan( 20 ), '20枚' );
			$this->t->same( array( 'chunk:0', 'chunk:1', 'compose' ), JobService::plan( 21 ), '21枚' );
			$this->t->same( 7, count( JobService::plan( 101 ) ), '101枚＝6回に分けて解析＋まとめ1回（固定の上限を作らない）' );
		} );

		$t->test( 'J-14', '外部へ送る写真のコピーから撮影位置などの情報を除く（元の画像は変えない）', function () use ( $images ) {
			if ( empty( $images['exif1'] ) ) {
				throw new SkipTest( 'EXIF付きの合成画像 exif1 が未登録' );
			}
			$orig = get_attached_file( $images['exif1'] );
			$this->t->true( ImagePreparer::has_metadata( $orig ), '元の画像には位置情報がある（試験データの前提）' );
			$before = hash_file( 'sha256', $orig );
			$p      = ImagePreparer::prepare( $images['exif1'] );
			$tmp    = wp_tempnam( 'wpaic-test' );
			file_put_contents( $tmp, base64_decode( $p['data'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			try {
				$this->t->same( false, ImagePreparer::has_metadata( $tmp ), '送るコピーに位置情報がない' );
				$size = getimagesize( $tmp );
				$this->t->true( max( $size[0], $size[1] ) <= ImagePreparer::MAX_EDGE, '長辺 1568px 以下' );
			} finally {
				wp_delete_file( $tmp );
			}
			$this->t->same( $before, hash_file( 'sha256', $orig ), '元の画像は変わっていない' );
		} );

		$t->test( 'J-15', 'REST：未ログインは拒否、自分のジョブは取得できる', function () use ( $images ) {
			$s   = $this->service();
			$job = $this->create( $s, $this->input( $images ) );
			wp_set_current_user( 0 );
			$this->t->same( 401, rest_do_request( new \WP_REST_Request( 'GET', '/wp-ai-case/v1/jobs/' . $job['job_id'] ) )->get_status(), '未ログイン' );
			$this->t->same( 401, rest_do_request( new \WP_REST_Request( 'POST', '/wp-ai-case/v1/jobs' ) )->get_status(), '未ログインの作成' );
			wp_set_current_user( $this->user );
			$r = rest_do_request( new \WP_REST_Request( 'GET', '/wp-ai-case/v1/jobs/' . $job['job_id'] ) );
			$this->t->same( 200, $r->get_status(), '自分のジョブ' );
			$this->t->same( 404, rest_do_request( new \WP_REST_Request( 'GET', '/wp-ai-case/v1/jobs/' . wp_generate_uuid4() ) )->get_status(), '存在しないジョブ' );
		} );

		$t->test( 'J-17', 'Claude API への生成リクエスト（模擬応答）：構造化出力・画像は位置情報なしの縮小コピー・余計な項目なし。拒否は AI-009', function () use ( $images ) {
			$sent  = array();
			$mode  = 'ok';
			$fake  = static function ( $pre, $args, $url ) use ( &$sent, &$mode ) {
				if ( ! str_contains( (string) $url, 'anthropic.com' ) ) {
					return $pre;
				}
				$body   = json_decode( (string) $args['body'], true );
				$sent[] = array( 'url' => $url, 'body' => $body, 'headers' => $args['headers'] );
				$first  = json_decode( substr( (string) $body['messages'][0]['content'][0]['text'], strpos( (string) $body['messages'][0]['content'][0]['text'], '{' ) ), true );
				$ids    = array_map( static fn( $p ) => (int) $p['attachment_id'], (array) ( $first['photos'] ?? array() ) );
				$out    = array(
					'images'    => array_map( static fn( $id ) => array( 'attachment_id' => $id, 'phase' => 'after', 'description' => '写真の説明', 'caption' => '', 'related_image_ids' => array(), 'uncertain' => false, 'uncertain_reason' => '' ), $ids ),
					'article'   => array( 'title' => '試験タイトル', 'catch' => '試験', 'summary' => '試験', 'sections' => array( array( 'kind' => 'after', 'heading' => '施工後', 'body' => '本文', 'image_ids' => $ids ) ), 'seo' => array( 'title' => 't', 'description' => 'd', 'h1' => 'h', 'keywords' => 'k' ) ),
					'questions' => array(),
				);
				$resp = array( 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $out ) ) ), 'stop_reason' => 'refusal' === $mode ? 'refusal' : 'end_turn', 'usage' => array( 'input_tokens' => 1234, 'output_tokens' => 321 ) );
				return array( 'headers' => array( 'request-id' => 'req_test' ), 'body' => wp_json_encode( $resp ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
			};
			add_filter( 'pre_http_request', $fake, 0, 3 );
			try {
				$provider = new \WpAiCase\AI\AnthropicProvider( new \WpAiCase\AI\AnthropicClient( 'test-key-not-real-2222' ) );
				$s        = new JobService( null, $provider );
				$in       = $this->input( $images );
				$in['images'] = array_slice( $in['images'], 0, 2 );
				$job      = $this->create( $s, $in );
				$job      = $s->tick( $job['job_id'], $this->user );
				$this->t->same( 'proposal_ready', $job['status'], '状態' );
				$this->t->same( array( 1234, 321 ), array( $job['data']['usage']['input_tokens'], $job['data']['usage']['output_tokens'] ), '使用量を記録' );
				$b = $sent[0]['body'];
				$this->t->same( 'https://api.anthropic.com/v1/messages', $sent[0]['url'], '送信先' );
				$this->t->same( array( 'claude-haiku-5-5', 8000, 'json_schema', 'medium' ), array( $b['model'], $b['max_tokens'], $b['output_config']['format']['type'], $b['output_config']['effort'] ), 'モデル・構造化出力' );
				$this->t->true( ! isset( $b['temperature'] ) && ! isset( $b['top_p'] ) && ! isset( $b['thinking'] ), '送らない項目（temperature 等）' );
				$imgs = array_values( array_filter( $b['messages'][0]['content'], static fn( $c ) => 'image' === $c['type'] ) );
				$this->t->same( 2, count( $imgs ), '画像2枚' );
				$tmp = wp_tempnam( 'wpaic-test' );
				file_put_contents( $tmp, base64_decode( $imgs[0]['source']['data'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$this->t->same( array( 'base64', 'image/jpeg', false ), array( $imgs[0]['source']['type'], $imgs[0]['source']['media_type'], ImagePreparer::has_metadata( $tmp ) ), '画像ブロック' );
				wp_delete_file( $tmp );
				$json = (string) wp_json_encode( $b );
				foreach ( array( 'relations', 'customer_voice', 'post_id', 'wp-content', 'uploads', home_url() ) as $bad ) {
					$this->t->true( ! str_contains( $json, $bad ), "送信内容に {$bad}" );
				}
				$mode = 'refusal';
				$job2 = $this->create( $s, array_merge( $in, array( 'idempotency_key' => wp_generate_uuid4() ) ) );
				$job2 = $s->tick( $job2['job_id'], $this->user );
				$this->t->same( array( 'failed', 'AI-009' ), array( $job2['status'], $job2['data']['error']['error_code'] ?? null ), 'AIが応答を断った場合' );
			} finally {
				remove_filter( 'pre_http_request', $fake, 0 );
			}
		} );

		$this->t->test( 'J-16', '試験中に Claude API への通信が 0 回', fn() => $this->t->same( 0, $this->external, '外部通信' ) );
	}

	private function cleanup(): void {
		$s = new JobService();
		foreach ( array_unique( $this->jobs ) as $id ) {
			$job = $s->repository()->get( $id );
			if ( $job && $job['post_id'] && '1' !== (string) get_post_meta( (int) $job['post_id'], Poc::MARK, true ) && get_post_meta( (int) $job['post_id'], JobService::META_JOB, true ) === $id && 'draft' === get_post_status( (int) $job['post_id'] ) ) {
				wp_delete_post( (int) $job['post_id'], true );
			}
			$s->repository()->delete( $id );
		}
		$this->t->test( 'J-99', '試験で作ったジョブ・下書きを削除した', function () use ( $s ) {
			foreach ( $this->jobs as $id ) {
				$this->t->same( null, $s->repository()->get( $id ), "ジョブ {$id} が残っている" );
			}
		} );
	}
}
