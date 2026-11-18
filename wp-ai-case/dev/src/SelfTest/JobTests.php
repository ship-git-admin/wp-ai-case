<?php
/**
 * C1-C 後半：生成ジョブ（入力 → 解析・下書き → 修正 → 保存）の試験。プロバイダーは Mock（外部通信なし）。
 * 作ったジョブ・下書きは最後に必ず削除し、設定は元に戻す。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev\SelfTest;

use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Dev\MockProvider;
use WpAiCase\Draft\DraftState;
use WpAiCase\Draft\Snapshot;
use WpAiCase\Mapping\MappingService;
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
			$state['post_id'] = (int) $post->ID;
		} );

		$this->revise_tests( $state );

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

		$t->test( 'J-18', 'C1-UI02/03 写真45枚：20枚ずつ分割して解析（外部3回）→ 全体で再統合（1回）。全IDを1回ずつ・元の順、束をまたいでまとめる', function () use ( $images ) {
			$tmp = $this->temp_images( 45 );
			try {
				MockProvider::$calls    = 0;
				MockProvider::$payloads = array();
				$s  = $this->service();
				$in = $this->input( $images );
				$in['images'] = array_map( static fn( $id, $i ) => array( 'attachment_id' => $id, 'original_position' => $i, 'caption' => '', 'user_phase_hint' => 'unknown' ), $tmp, array_keys( $tmp ) );
				$job = $this->create( $s, $in );
				$this->t->same( array( 0, 4 ), array( JobService::view( $job, $this->user )['progress']['step'], JobService::view( $job, $this->user )['progress']['total'] ), '進行状況（開始時）' );
				$steps = 0;
				while ( in_array( $job['status'], array( 'queued', 'analyzing' ), true ) && $steps < 10 ) {
					$job = $s->tick( $job['job_id'], $this->user );
					++$steps;
				}
				$this->t->same( array( 'proposal_ready', 4, 4 ), array( $job['status'], $steps, MockProvider::$calls ), '4回で完了（1回の進行で外部1回）' );
				$modes = array_column( MockProvider::$payloads, 'mode' );
				$this->t->same( array( 'analyze_only', 'analyze_only', 'analyze_only', 'compose_text_only' ), $modes, '分割→再統合' );
				$this->t->same( array( 20, 20, 5 ), array_map( static fn( $p ) => count( $p['images'] ), array_slice( MockProvider::$payloads, 0, 3 ) ), '束の枚数' );
				$compose = MockProvider::$payloads[3];
				$this->t->same( 45, count( (array) $compose['prior_analysis'] ), '再統合に全写真の解析を渡す' );
				$this->t->true( ! str_contains( (string) wp_json_encode( $compose ), 'base64' ), '再統合では画像データを送らない' );
				$doc = $job['data']['versions'][0];
				$this->t->same( $tmp, array_map( static fn( $i ) => (int) $i['attachment_id'], $doc['images'] ), '全IDを1回ずつ・元の順' );
				$before = array_values( array_filter( $doc['sections'], static fn( $x ) => 'before' === $x['kind'] ) )[0] ?? array( 'image_ids' => array() );
				$this->t->true( ! array_diff( array( $tmp[0], $tmp[20], $tmp[40] ), $before['image_ids'] ), '3つの束の施工前の写真を1つのセクションにまとめる（束をまたぐ再統合）' );
				$s->cancel( $job['job_id'], $this->user );
			} finally {
				foreach ( $tmp as $id ) {
					wp_delete_attachment( $id, true );
				}
			}
		} );

		$this->t->test( 'J-16', '試験中に Claude API への通信が 0 回', fn() => $this->t->same( 0, $this->external, '外部通信' ) );
	}

	/**
	 * 既存の下書きの修正（mode=revise_draft）。J-08 で保存した下書きを使う。
	 *
	 * @param array<string,mixed> $state
	 */
	private function revise_tests( array &$state ): void {
		$t    = $this->t;
		$post = (int) ( $state['post_id'] ?? 0 );
		$open = function ( int $base, array $override = array() ) use ( &$post ): array {
			$job          = $this->service()->create(
				array_replace(
					array(
						'client_case_id'      => 'selftest-rev',
						'idempotency_key'     => wp_generate_uuid4(),
						'mode'                => 'revise_draft',
						'post_type'           => 'works',
						'post_id'             => $post,
						'base_version'        => $base,
						'consent_external_ai' => true,
					),
					$override
				),
				$this->user
			);
			$this->jobs[] = $job['job_id'];
			return $job;
		};
		$meta = static function ( int $id ): array {
			$m = Snapshot::capture( $id )['meta'];
			return $m;
		};
		$diff = static function ( array $a, array $b ): array {
			$keys = array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) );
			return array_values( array_filter( $keys, static fn( $k ) => ( $a[ $k ] ?? null ) != ( $b[ $k ] ?? null ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons
		};
		$field_key = static function ( int $id, string $slot ): string {
			$inspector = new SchemaInspector();
			$context   = $inspector->context( Plugin::POST_TYPE, $id );
			$mapping   = ( new MappingService() )->gate( $context );
			return (string) DraftState::slot_fields( $mapping, $inspector->groups( $context ) )[ $slot ]['key'];
		};

		$t->test( 'J-20', 'C1-UI07 修正できるのはこの機能で保存した下書きだけ。版の不一致・同意なし・手作業の下書きは開けない（外部0）', function () use ( &$post, $open ) {
			if ( ! $post ) {
				throw new SkipTest( 'J-08 が失敗' );
			}
			MockProvider::$calls = 0;
			$this->t->same( 1, DraftState::version( $post ), '保存時の版' );
			$listed = array_column( JobService::list_drafts( $this->user ), 'version', 'post_id' );
			$this->t->same( 1, $listed[ $post ] ?? null, '一覧に版1で出る' );
			$this->t->same( 'AI-012', $this->err( fn() => $open( 2 ) )->response['error_code'], '版の不一致' );
			$e = $this->err( fn() => $open( 1, array( 'consent_external_ai' => false ) ) );
			$this->t->same( array( 'AI-017', array( 'consent_external_ai' ) ), array( $e->response['error_code'], $e->extra['fields'] ?? array() ), '同意なし' );
			$plain = wp_insert_post( array( 'post_type' => 'works', 'post_status' => 'draft', 'post_title' => '【試験】手作業の下書き' ) );
			try {
				$this->t->true( ! isset( array_column( JobService::list_drafts( $this->user ), 'post_id', 'post_id' )[ $plain ] ), '手作業の下書きは一覧に出ない' );
				$this->t->same( 'AI-012', $this->err( fn() => $open( 1, array( 'post_id' => $plain ) ) )->response['error_code'], '手作業の下書き' );
			} finally {
				wp_delete_post( $plain, true );
			}
			$this->t->same( 0, MockProvider::$calls, '外部呼び出し' );
		} );

		$t->test( 'J-21', '下書きを開く（最初の版＝保存時の文書）→ 画面で修正 → 保存で、変わった部分（タイトル・SEO H1）だけ書く', function () use ( &$post, $open, $meta, $diff ) {
			if ( ! $post ) {
				throw new SkipTest( 'J-08 が失敗' );
			}
			$saved = DraftState::load( $post );
			$job   = $open( 1 );
			$this->t->same( array( 'proposal_ready', 'revise_draft', 1, $post ), array( $job['status'], $job['mode'], count( $job['data']['versions'] ), (int) $job['post_id'] ), '開いた状態' );
			$this->t->same( $saved['doc'], $job['data']['versions'][0], '最初の版は保存時の文書' );
			$this->t->same( array(), $job['data']['target']['edited'], '人の編集なし' );
			$before = $meta( $post );
			$s      = $this->service();
			$job    = $s->revise_local( $job['job_id'], $this->user, 0, array( 'title', 'seo' ), array(
				array( 'op' => 'text_set', 'section' => 'title', 'value' => '【試験】修正したタイトル' ),
				array( 'op' => 'seo_set', 'section' => 'seo', 'field' => 'h1', 'value' => '【試験】修正したH1' ),
			) );
			$job = $s->save( $job['job_id'], $this->user, 1 );
			$this->t->same( 'committed', $job['status'], '状態' );
			$this->t->same( array( 'タイトル', 'SEO：H1' ), $job['data']['saved_changes'], '更新した部分' );
			$changed = $diff( $before, $meta( $post ) );
			$this->t->same( array( 'h1' ), $changed, '変わったメタは H1 だけ' );
			$p = get_post( $post );
			$this->t->same( array( '【試験】修正したタイトル', 'draft', 2 ), array( $p->post_title, $p->post_status, DraftState::version( $post ) ), 'タイトル・下書きのまま・版2' );
		} );

		$t->test( 'J-22', '保存後に編集画面で変えた部分に重なる修正は保存しない（AI-012・部分名）。重ならない修正は保存でき、人の編集は残る', function () use ( &$post, $open, $field_key ) {
			if ( ! $post || DraftState::version( $post ) !== 2 ) {
				throw new SkipTest( '前の試験が失敗' );
			}
			$key = $field_key( $post, 'EntryData_CatchTxt' );
			update_field( $key, '【試験】人が直した見出し', $post );
			$job = $open( 2 );
			$this->t->same( array( 'コンセプト（見出し）' ), $job['data']['target']['edited'], '人が変えた部分の案内' );
			$s   = $this->service();
			$job = $s->revise_local( $job['job_id'], $this->user, 0, array( 'concept' ), array( array( 'op' => 'text_set', 'section' => 'concept', 'value' => '【試験】AI側の見出し' ) ) );
			$e   = $this->err( fn() => $s->save( $job['job_id'], $this->user, 1 ) );
			$this->t->same( array( 409, 'AI-012' ), array( $e->status, $e->response['error_code'] ), '保存しない' );
			$this->t->same( array( 'コンセプト（見出し）' ), $e->extra['fields'] ?? array(), '部分名' );
			Snapshot::flush( $post );
			$this->t->same( '【試験】人が直した見出し', get_field( $key, $post, false ), '人の編集は残る' );
			$this->t->same( 2, DraftState::version( $post ), '版は進まない' );

			$job = $open( 2 );
			$job = $s->revise_local( $job['job_id'], $this->user, 0, array( 'seo' ), array( array( 'op' => 'seo_set', 'section' => 'seo', 'field' => 'description', 'value' => '【試験】説明文' ) ) );
			$job = $s->save( $job['job_id'], $this->user, 1 );
			$this->t->same( array( 'committed', array( 'SEO：説明文' ) ), array( $job['status'], $job['data']['saved_changes'] ), '重ならない修正は保存' );
			Snapshot::flush( $post );
			$this->t->same( '【試験】人が直した見出し', get_field( $key, $post, false ), '人の編集は残る（保存後も）' );
			$this->t->same( 3, DraftState::version( $post ), '版3' );
		} );

		$t->test( 'J-23', '同じ下書きを2つの画面で開いたら、先に保存した方だけ通る（後は AI-012）', function () use ( &$post, $open ) {
			if ( ! $post || DraftState::version( $post ) !== 3 ) {
				throw new SkipTest( '前の試験が失敗' );
			}
			$s = $this->service();
			$a = $open( 3 );
			$b = $open( 3 );
			$a = $s->revise_local( $a['job_id'], $this->user, 0, array( 'seo' ), array( array( 'op' => 'seo_set', 'section' => 'seo', 'field' => 'keywords', 'value' => '試験A' ) ) );
			$b = $s->revise_local( $b['job_id'], $this->user, 0, array( 'seo' ), array( array( 'op' => 'seo_set', 'section' => 'seo', 'field' => 'title', 'value' => '試験B' ) ) );
			$this->t->same( 'committed', $s->save( $a['job_id'], $this->user, 1 )['status'], '先に保存' );
			$e = $this->err( fn() => $s->save( $b['job_id'], $this->user, 1 ) );
			$this->t->same( array( 409, 'AI-012' ), array( $e->status, $e->response['error_code'] ), '後から保存' );
			$this->t->same( 4, DraftState::version( $post ), '版4' );
		} );

		$t->test( 'J-24', 'C1-CON01 修正中に編集画面で保存された・下書きでなくなったら保存しない。下書き以外は一覧に出ない', function () use ( &$post, $open ) {
			if ( ! $post || DraftState::version( $post ) !== 4 ) {
				throw new SkipTest( '前の試験が失敗' );
			}
			global $wpdb;
			$s   = $this->service();
			$job = $open( 4 );
			$job = $s->revise_local( $job['job_id'], $this->user, 0, array( 'seo' ), array( array( 'op' => 'seo_set', 'section' => 'seo', 'field' => 'keywords', 'value' => '試験C' ) ) );
			$orig = get_post( $post );
			$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2001-01-01 00:00:00' ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $post );
			$e = $this->err( fn() => $s->save( $job['job_id'], $this->user, 1 ) );
			$this->t->same( 'AI-012', $e->response['error_code'], '編集画面で保存された' );
			$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $orig->post_modified_gmt, 'post_status' => 'private' ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $post );
			try {
				$this->t->true( ! isset( array_column( JobService::list_drafts( $this->user ), 'post_id', 'post_id' )[ $post ] ), '非公開は一覧に出ない' );
				$this->t->same( 'AI-012', $this->err( fn() => $open( 4 ) )->response['error_code'], '非公開は開けない' );
				$job2 = $open( 4, array( 'post_id' => $post, 'idempotency_key' => wp_generate_uuid4() ) );
			} catch ( JobError $e2 ) {
				$job2 = null;
			} finally {
				$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $post );
			}
			$this->t->same( null, $job2, '非公開のまま開けた' );
			$this->t->same( 4, DraftState::version( $post ), '版は進まない' );
		} );

		$t->test( 'J-25', 'AIで修正（選んだ部分だけ）と写真の工程変更 → 保存で、タイトルと本文・写真の欄だけ更新。ご要望・施工概要・SEOなどは不変', function () use ( &$post, $open, $meta, $diff ) {
			if ( ! $post || DraftState::version( $post ) !== 4 ) {
				throw new SkipTest( '前の試験が失敗' );
			}
			MockProvider::$calls = 0;
			$s   = $this->service();
			$job = $open( 4 );
			$doc = $job['data']['versions'][0];
			$job = $s->revise_ai( $job['job_id'], $this->user, 0, array( 'title' ), 'タイトルを分かりやすく' );
			$img = null;
			foreach ( $doc['images'] as $i ) {
				if ( 'during' === $i['phase'] ) {
					$img = (int) $i['attachment_id'];
				}
			}
			if ( ! $img ) {
				throw new SkipTest( '施工中の写真がない' );
			}
			$job    = $s->revise_local( $job['job_id'], $this->user, 1, array( 'before_after' ), array( array( 'op' => 'image_phase_set', 'section' => 'before_after', 'attachment_id' => $img, 'phase' => 'after' ) ) );
			$before = $meta( $post );
			$job    = $s->save( $job['job_id'], $this->user, 2 );
			$this->t->same( 1, MockProvider::$calls, 'AI呼び出しは修正の1回' );
			// 施工中の写真が施工後になると、トップのビフォーアフターの「後」の写真も変わる。
			$this->t->same( array( 'committed', array( 'タイトル', 'トップの写真（ビフォーアフター）', '本文と写真' ) ), array( $job['status'], $job['data']['saved_changes'] ), '更新した部分' );
			$changed = $diff( $before, $meta( $post ) );
			$this->t->true( ! array_intersect( $changed, array( 'Request', 'Proposal', 'Outline', 'EntryData_CatchTxt', 'EntryData_Description', 'title', 'h1', 'description', 'keywords', 'EntryData_RelatedData', 'EntryData_Staff' ) ), '選んでいない欄は不変（' . implode( ',', array_slice( $changed, 0, 8 ) ) . '）' );
			$this->t->true( str_starts_with( get_post( $post )->post_title, '【修正】' ), 'タイトルはAIの修正' );
			$this->t->same( 5, DraftState::version( $post ), '版5' );
		} );

		$t->test( 'J-26', '変更がなければ書き換えない（版も進めない）', function () use ( &$post, $open, $meta ) {
			if ( ! $post || DraftState::version( $post ) !== 5 ) {
				throw new SkipTest( '前の試験が失敗' );
			}
			$before = $meta( $post );
			$job    = $this->service()->save( $open( 5 )['job_id'], $this->user, 0 );
			$this->t->same( array( 'committed', array() ), array( $job['status'], $job['data']['saved_changes'] ), '変更なし' );
			$this->t->same( $before, $meta( $post ), 'メタは不変' );
			$this->t->same( 5, DraftState::version( $post ), '版は進まない' );
		} );

		$t->test( 'J-28', '入力を消した項目（使用商材）は、下書きの欄も空にする', function () use ( &$post, $open, $field_key ) {
			if ( ! $post || DraftState::version( $post ) !== 5 ) {
				throw new SkipTest( '前の試験が失敗' );
			}
			$s   = $this->service();
			$key = $field_key( $post, 'EntryData_Commodity' );
			$job = $s->revise_local( $open( 5 )['job_id'], $this->user, 0, array( 'facts' ), array( array( 'op' => 'fact_set', 'section' => 'facts', 'fact_key' => 'materials', 'value' => '【試験】塗料A', 'source' => 'user_input' ) ) );
			$s->save( $job['job_id'], $this->user, 1 );
			Snapshot::flush( $post );
			$this->t->same( '【試験】塗料A', get_field( $key, $post, false ), '書いた' );
			$job = $s->revise_local( $open( 6 )['job_id'], $this->user, 0, array( 'facts' ), array( array( 'op' => 'fact_set', 'section' => 'facts', 'fact_key' => 'materials', 'value' => '', 'source' => 'user_input' ) ) );
			$job = $s->save( $job['job_id'], $this->user, 1 );
			Snapshot::flush( $post );
			$this->t->same( array( array( '使用商材・建材' ), '' ), array( $job['data']['saved_changes'], (string) get_field( $key, $post, false ) ), '空にした' );
			$this->t->same( 7, DraftState::version( $post ), '版7' );
		} );

		$t->test( 'J-27', 'REST：修正できる下書きの一覧は、ログインした編集者だけ', function () use ( &$post ) {
			if ( ! $post ) {
				throw new SkipTest( 'J-08 が失敗' );
			}
			$r = rest_do_request( new \WP_REST_Request( 'GET', '/wp-ai-case/v1/drafts' ) );
			$this->t->same( 200, $r->get_status(), 'ログイン中' );
			$this->t->true( in_array( $post, array_column( (array) ( $r->get_data()['drafts'] ?? array() ), 'post_id' ), true ), '一覧に含まれる' );
			wp_set_current_user( 0 );
			try {
				$this->t->same( 401, rest_do_request( new \WP_REST_Request( 'GET', '/wp-ai-case/v1/drafts' ) )->get_status(), '未ログイン' );
			} finally {
				wp_set_current_user( $this->user );
			}
		} );
	}

	/**
	 * 試験用の一時画像（GDで作る小さな単色画像）。呼び出し側で削除する。
	 *
	 * @return int[]
	 */
	private function temp_images( int $n ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$ids  = array();
		$keep = static fn( $sizes ) => array(); // サムネイルを作らない（速くするため）
		add_filter( 'intermediate_image_sizes_advanced', $keep );
		try {
			for ( $i = 0; $i < $n; $i++ ) {
				$img = imagecreatetruecolor( 320, 240 );
				imagefill( $img, 0, 0, imagecolorallocate( $img, 40 + $i * 4, 120, 200 - $i * 3 ) );
				imagestring( $img, 5, 10, 10, 'SELFTEST ' . ( $i + 1 ), imagecolorallocate( $img, 255, 255, 255 ) );
				$tmp = wp_tempnam( 'wpaic-selftest.jpg' );
				imagejpeg( $img, $tmp, 70 );
				imagedestroy( $img );
				$id = media_handle_sideload( array( 'name' => 'wpaic-selftest-' . ( $i + 1 ) . '.jpg', 'tmp_name' => $tmp ), 0, '【試験】一時画像' );
				if ( is_wp_error( $id ) ) {
					throw new \RuntimeException( '一時画像を作れません' );
				}
				update_post_meta( $id, Poc::MARK, '1' );
				$ids[] = (int) $id;
			}
		} catch ( \Throwable $e ) {
			foreach ( $ids as $id ) {
				wp_delete_attachment( $id, true );
			}
			throw $e;
		} finally {
			remove_filter( 'intermediate_image_sizes_advanced', $keep );
		}
		return $ids;
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
