<?php
/**
 * テーマでの表示（表示メモ）：ファイルの選び方・秘密の検査・作成（模擬AI）・承認・テーマの変更・記事の作成と保存前のチェックへの反映。
 *
 * 実サイトの表示メモ（オプション）は試験の前に控え、最後に元に戻す。プロバイダーは Mock（外部通信なし）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev\SelfTest;

use WpAiCase\Dev\MockProvider;
use WpAiCase\Dev\Poc;
use WpAiCase\Diagnostics\Settings;
use WpAiCase\Display\DisplayNotes;
use WpAiCase\Display\ThemeReader;
use WpAiCase\Jobs\JobService;
use WpAiCase\Jobs\ResultAssembler;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class DisplayTests {

	/** @var string[] */
	private array $jobs = array();

	public function __construct( private Tester $t, private int $user ) {
	}

	public function run(): void {
		$saved = get_option( DisplayNotes::OPTION, null );
		Settings::$test_status = 'configured';
		wp_set_current_user( $this->user );
		try {
			$this->tests();
		} finally {
			null === $saved ? delete_option( DisplayNotes::OPTION ) : update_option( DisplayNotes::OPTION, $saved, false );
			DisplayNotes::flush();
			Settings::$test_status = null;
			$s = new JobService();
			foreach ( $this->jobs as $id ) {
				$s->repository()->delete( $id );
			}
		}
		$this->t->test( 'DN-99', '表示メモを試験の前の状態に戻し、試験のジョブを削除した', function () use ( $saved ) {
			$this->t->same( $saved, get_option( DisplayNotes::OPTION, null ), '表示メモ' );
		} );
	}

	private function tests(): void {
		$t = $this->t;

		$t->test( 'DN-01', 'ファイルの選び方：名前に works の PHP・施工事例の項目を使う部品・works の SCSS。コンパイル済みの CSS・使っていないフレーム・old_ は除く。合計の上限', function () {
			if ( 'shipinc' !== get_template() ) {
				throw new SkipTest( 'shipinc テーマではありません' );
			}
			$set   = ThemeReader::collect();
			$paths = array_column( $set['files'], 'reason', 'path' );
			foreach ( array( 'theme:single-works.php' => 'works', 'theme:template-parts/content-works-single.php' => 'works', 'theme:template-parts/parts-works-item.php' => 'works', 'theme:template-parts/global-relative-staff2.php' => 'part', 'theme:template-parts/article-supervisor.php' => 'part' ) as $p => $why ) {
				$this->t->same( $why, $paths[ $p ] ?? null, "含める {$p}" );
			}
			$this->t->true( ! isset( $paths['theme:template-parts/sidebar-global.php'] ) && ! isset( $paths['theme:template-parts/parts-menu-card.php'] ), '施工事例の項目を使わない部品は含めない' );
			$bad = array_filter( array_keys( $paths ), static fn( $p ) => str_starts_with( $p, 'theme:css/' ) || str_contains( $p, 'old_' ) || ( '' !== $set['frame'] && preg_match( '#(^|[/_.\-])' . ( 'gra' === $set['frame'] ? 'grb' : 'gra' ) . '([/_.\-]|$)#', $p ) ) );
			$this->t->same( array(), array_values( $bad ), '除くファイル' );
			if ( 'gra' === $set['frame'] ) {
				$this->t->same( 'style', $paths['theme:scss/works-gra.scss'] ?? null, '使っているフレームの SCSS' );
			}
			$this->t->true( array_sum( array_map( static fn( $f ) => min( (int) $f['bytes'], ThemeReader::MAX_FILE ), $set['files'] ) ) <= ThemeReader::MAX_TOTAL, '合計の上限' );
			$this->t->same( ThemeReader::fingerprint( $set ), ThemeReader::fingerprint(), '指紋は同じファイルなら同じ' );
		} );

		$t->test( 'DN-02', '秘密らしい文字列の検査：APIキー・トークン・秘密鍵・パスワードの代入を見つけ（行と種類だけ。値は返さない）、普通のコードは見逃す。見つかれば送らない', function () {
			$key  = 'AIza' . str_repeat( 'x', 35 );
			$code = "<?php\n\$ok = get_field('password');\n\$map = '{$key}';\n\$password = 'hunter22secret';\n// sk-ant-" . str_repeat( 'a', 30 ) . "\n\$x = array( 'api_key' => \$val );\n";
			$hits = ThemeReader::scan( $code );
			$this->t->same( array( '3行目（Google API キー）', '4行目（パスワード等の代入）', '5行目（OpenAI/Anthropic キー）' ), $hits, '見つけた場所' );
			$this->t->true( ! str_contains( implode( '', $hits ), 'hunter22' ) && ! str_contains( implode( '', $hits ), $key ), '値を返さない' );
			$tmp = wp_tempnam( 'wpaic-display' );
			file_put_contents( $tmp, $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			try {
				$e = $this->t->throws_case( CaseError::INVALID_VALUE, fn() => ThemeReader::read( array( array( 'path' => 'theme:test.php', 'abs' => $tmp ) ) ) );
				$this->t->same( array( 'secret', 'theme:test.php:3行目（Google API キー）' ), array( $e->context['reason'], $e->context['found'][0] ?? '' ), '送らない理由' );
			} finally {
				wp_delete_file( $tmp );
			}
		} );

		$state = array();
		$t->test( 'DN-03', '作成（模擬AI）：選んだファイルを1回送り、知っている欄だけを短くして承認待ちで保存。承認するまでは標準のメモを使う。管理者だけ', function () use ( &$state ) {
			delete_option( DisplayNotes::OPTION );
			DisplayNotes::flush();
			$this->t->same( array( 'none', 'default' ), array( DisplayNotes::status(), DisplayNotes::for_prompt()['source'] ), '作る前' );
			$deny = static function ( $allcaps ) {
				$allcaps['manage_options'] = false;
				return $allcaps;
			};
			add_filter( 'user_has_cap', $deny );
			try {
				$this->t->throws_case( CaseError::FORBIDDEN, fn() => DisplayNotes::build( $this->user, new MockProvider( 'ok' ) ) );
			} finally {
				remove_filter( 'user_has_cap', $deny );
			}
			MockProvider::$calls    = 0;
			MockProvider::$payloads = array();
			$r = DisplayNotes::build( $this->user, new MockProvider( 'evil' ) );
			$this->t->same( 1, MockProvider::$calls, '外部呼び出しは1回' );
			$p = end( MockProvider::$payloads );
			$this->t->same( array_column( ThemeReader::collect()['files'], 'path' ), array_column( $p['files'], 'path' ), '送ったファイル' );
			$this->t->same( array_keys( DisplayNotes::TARGETS ), array_column( $p['targets'], 'id' ), '欄の一覧' );
			$ids = array_column( $r['record']['notes']['targets'], 'id' );
			$this->t->true( ! in_array( 'unknown_target', $ids, true ), '知らない欄は捨てる' );
			$first = $r['record']['notes']['targets'][0];
			$this->t->true( mb_strlen( $first['where'] ) <= 200 && ! str_contains( $first['where'], '<script' ), '短くしてタグを除く' );
			$this->t->true( ! isset( $r['record']['files'][0]['abs'] ) && ! isset( $r['record']['files'][0]['content'] ), '記録にファイルの中身・場所を残さない' );
			$this->t->same( array( 'draft', 'default' ), array( DisplayNotes::status(), DisplayNotes::for_prompt()['source'] ), '承認待ちのあいだは標準' );
			$state['ok'] = true;
		} );

		$t->test( 'DN-04', '承認すると記事の作成に使い、テーマが出すラベルと同じ言葉を写真の見出し・小見出しから除く。テーマのファイルが変われば作り直しが必要（承認できない・標準に戻る）', function () use ( &$state ) {
			if ( empty( $state['ok'] ) ) {
				throw new SkipTest( 'DN-03 が失敗' );
			}
			DisplayNotes::build( $this->user, new MockProvider( 'ok' ) );
			DisplayNotes::approve( $this->user );
			$this->t->same( array( 'approved', 'theme' ), array( DisplayNotes::status(), DisplayNotes::for_prompt()['source'] ), '承認後' );
			$this->t->true( in_array( '模擬ラベル', DisplayNotes::labels( 'photo_caption' ), true ), 'テーマのラベル' );
			MockProvider::$display_labels = array( '工程ラベル' );
			DisplayNotes::build( $this->user, new MockProvider( 'ok' ) );
			$rec = DisplayNotes::stored();
			foreach ( $rec['notes']['targets'] as $i => $tg ) {
				if ( 'photo_caption' === $tg['id'] ) {
					$rec['notes']['targets'][ $i ]['theme_labels'] = array();
				}
			}
			update_option( DisplayNotes::OPTION, $rec, false );
			DisplayNotes::approve( $this->user );
			$this->t->same( array( '工程ラベル' ), DisplayNotes::labels( 'photo_caption' ), '写真の見出しには工程の欄のラベルも使う' );
			MockProvider::$display_labels = array( '施工前', '施工中', '施工後', '模擬ラベル' );
			DisplayNotes::build( $this->user, new MockProvider( 'ok' ) );
			DisplayNotes::approve( $this->user );
			$this->t->same( array( '外壁', '下地補修', '軒天' ), array( ResultAssembler::caption( '模擬ラベル 外壁' ), ResultAssembler::heading( '模擬ラベル：下地補修' ), ResultAssembler::caption( '軒天（施工前）' ) ), '取り除く' );

			$fake = static fn( $h ) => $h . '-changed';
			add_filter( 'wp_ai_case_display_fingerprint', $fake );
			DisplayNotes::flush();
			try {
				$this->t->same( array( 'stale', 'default' ), array( DisplayNotes::status(), DisplayNotes::for_prompt()['source'] ), 'テーマが変わった' );
				$this->t->throws_case( CaseError::INVALID_TRANSITION, fn() => DisplayNotes::approve( $this->user ) );
			} finally {
				remove_filter( 'wp_ai_case_display_fingerprint', $fake );
				DisplayNotes::flush();
			}
			$this->t->same( 'approved', DisplayNotes::status(), '元に戻した' );
		} );

		$t->test( 'DN-05', '記事の作成・AI修正の依頼に、使っている表示メモを入れる', function () use ( &$state ) {
			if ( empty( $state['ok'] ) ) {
				throw new SkipTest( 'DN-03 が失敗' );
			}
			$images = Poc::images();
			if ( count( $images ) < 3 ) {
				throw new SkipTest( '合成画像がありません' );
			}
			$ids = array( $images['before1'], $images['during1'], $images['after1'] );
			MockProvider::$payloads = array();
			$s   = new JobService( null, new MockProvider( 'ok' ) );
			$job = $s->create(
				array(
					'client_case_id'      => 'selftest',
					'idempotency_key'     => wp_generate_uuid4(),
					'mode'                => 'create',
					'post_type'           => 'works',
					'primary_facts'       => array( 'overview' => '試験', 'client_request' => '試験', 'work_done' => '試験' ),
					'images'              => array_map( static fn( $id, $i ) => array( 'attachment_id' => $id, 'original_position' => $i ), $ids, array_keys( $ids ) ),
					'consent_external_ai' => true,
					'relations'           => array( 'customer_voice_post_ids' => array(), 'staff' => array(), 'supervisor_post_ids' => array() ),
				),
				$this->user
			);
			$this->jobs[] = $job['job_id'];
			$job          = $s->tick( $job['job_id'], $this->user );
			$this->t->same( 'theme', MockProvider::$payloads[0]['display_notes']['source'] ?? null, '作成の依頼' );
			$this->t->true( str_contains( \WpAiCase\AI\Prompts::compose_text( MockProvider::$payloads[0] ), '模擬ラベル' ), '依頼の本文' );
			$job = $s->revise_ai( $job['job_id'], $this->user, 0, array( 'title' ), '短く' );
			$this->t->same( 'theme', end( MockProvider::$payloads )['display_notes']['source'] ?? null, 'AI修正の依頼' );
			$s->cancel( $job['job_id'], $this->user );
		} );
	}
}
