<?php
/**
 * 「記事の作り方」画面（管理者）。開発者に頼まなくても、記事の決まりを変えられるようにする。
 *
 * - 写真の並べ方（列の数・施工後の1枚目を大きくするか・小見出し・写真の見出し・トリミングの自動設定）
 * - 書き方（サイト共通の書き方のルール・各欄の文章の長さの目安）
 * - 書き方の手本（このサイトの施工事例の記事を1つ選ぶ）
 * - テーマでの表示（表示メモ。テーマのファイルを読み取り専用で AI に送ってまとめる）
 * - 設定の書き出し・読み込み（別の案件へ同じ決まりを移す）
 * 変えた設定は、次に作る記事と、次に保存する下書きから効く。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Admin;

use WpAiCase\Display\ArticleSettings;
use WpAiCase\Display\DisplayNotes;
use WpAiCase\Display\ThemeReader;
use WpAiCase\Plugin;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class ArticlePage {

	public const SLUG   = 'wp-ai-case-article';
	private const FLASH = 'wp_ai_case_article_flash_';
	private const NONCE = 'wp_ai_case_article';

	public static string $hook = '';

	public static function register(): void {
		add_action( 'admin_post_wp_ai_case_article', array( self::class, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function enqueue( string $hook ): void {
		if ( '' === self::$hook || $hook !== self::$hook ) {
			return;
		}
		$file = plugin_dir_path( WP_AI_CASE_FILE ) . 'assets/css/admin.css';
		wp_enqueue_style( 'wp-ai-case-admin', plugins_url( 'assets/css/admin.css', WP_AI_CASE_FILE ), array(), ( defined( 'WP_AI_CASE_VERSION' ) ? WP_AI_CASE_VERSION : '0' ) . '.' . (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	// ---------- 操作 ----------

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この操作は管理者だけが行えます。', 'wp-ai-case' ), 403 );
		}
		check_admin_referer( self::NONCE );
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$user = get_current_user_id();
		$back = '';
		try {
			if ( 'save_settings' === $op ) {
				$in = isset( $_POST['wpaic'] ) && is_array( $_POST['wpaic'] ) ? wp_unslash( $_POST['wpaic'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ArticleSettings::sanitize で検証する
				$in['layout'] = (array) ( $in['layout'] ?? array() );
				foreach ( array( 'after_large', 'phase_headings', 'photo_titles', 'auto_trim' ) as $k ) {
					$in['layout'][ $k ] = ! empty( $in['layout'][ $k ] ); // チェックを外した項目は送られてこない
				}
				ArticleSettings::save( $in );
				self::flash( 'success', '記事の作り方を保存しました。次に作る記事と、次に保存する下書きから使います。' );
			}
			if ( 'reset_settings' === $op ) {
				ArticleSettings::reset();
				self::flash( 'success', '記事の作り方を初期値に戻しました。' );
			}
			if ( 'import_settings' === $op ) {
				$json = isset( $_POST['settings_json'] ) ? (string) wp_unslash( $_POST['settings_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON として読み、ArticleSettings::sanitize で検証する
				try {
					ArticleSettings::import( $json );
				} catch ( \InvalidArgumentException $e ) {
					throw new CaseError( CaseError::INVALID_TRANSITION, $e->getMessage() );
				}
				self::flash( 'success', '設定を読み込みました。' );
			}
			if ( 'display_build' === $op ) {
				if ( empty( $_POST['consent'] ) ) {
					throw new CaseError( CaseError::INVALID_TRANSITION, '送信するファイルを確認したことのチェックを入れてください' );
				}
				try {
					$r = DisplayNotes::build( $user );
				} catch ( CaseError $e ) {
					$found = (array) ( $e->context['found'] ?? array() );
					throw new CaseError( $e->reason, $e->getMessage() . ( $found ? '：' . implode( '、', $found ) : '' ), $e->context );
				}
				self::flash( 'success', '表示メモを作りました（承認待ち）。内容を確かめて承認してください。推定費用 $' . number_format( (float) $r['cost']['usd'], 4 ) . '。', $back );
			}
			if ( 'display_approve' === $op ) {
				DisplayNotes::approve( $user );
				self::flash( 'success', '表示メモを承認しました。これから作る・修正する下書きで使います。', $back );
			}
			if ( 'example_set' === $op ) {
				$id = isset( $_POST['example_post'] ) ? absint( $_POST['example_post'] ) : 0;
				try {
					\WpAiCase\Display\StyleExample::set( $id );
				} catch ( \InvalidArgumentException $e ) {
					throw new CaseError( CaseError::INVALID_TRANSITION, $e->getMessage() );
				}
				self::flash( 'success', $id ? '書き方の手本を設定しました。これから作る・修正する下書きで使います。' : '書き方の手本を外しました。', $back );
			}
			if ( 'display_discard' === $op ) {
				DisplayNotes::discard( $user );
				self::flash( 'success', '表示メモを削除しました。標準のメモ（shipinc テーマで確かめた内容）を使います。', $back );
			}
			throw new CaseError( CaseError::INVALID_TRANSITION, '不明な操作です' );
		} catch ( CaseError $e ) {
			self::flash( 'error', $e->ai_code() . '：' . $e->getMessage() );
		}
	}

	private static function flash( string $type, string $text, string $unused = '' ): void {
		set_transient( self::FLASH . get_current_user_id(), array( 'type' => $type, 'text' => $text ), 120 );
		wp_safe_redirect( self::url() );
		exit;
	}

	// ---------- 画面 ----------

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'wp-ai-case' ), 403 );
		}
		$flash = get_transient( self::FLASH . get_current_user_id() );
		delete_transient( self::FLASH . get_current_user_id() );
		echo '<div class="wrap wpaic-map"><h1>' . esc_html( 'AI施工事例：記事の作り方' ) . '</h1>';
		echo '<p>' . esc_html( 'AIで作る施工事例の、写真の並べ方と書き方の決まりです。変えた設定は、次に作る記事と、次に保存する下書きから使います（保存済みの下書きは、次に「反映」したときに並べ方が変わります）。' ) . '</p>';
		if ( is_array( $flash ) ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( (string) $flash['type'] ), esc_html( (string) $flash['text'] ) );
		}
		self::render_settings();
		self::render_example( '' );
		self::render_display( '' );
		self::render_transfer();
		echo '</div>';
	}

	private static function render_settings(): void {
		$s = ArticleSettings::get();
		$l = $s['layout'];
		$w = $s['writing'];
		$cols = static function ( string $key, string $value ): string {
			$h = sprintf( '<select name="wpaic[layout][%s]">', esc_attr( $key ) );
			foreach ( ArticleSettings::COLUMNS as $k => $label ) {
				$h .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( (string) $k ), selected( $value, (string) $k, false ), esc_html( $label ) );
			}
			return $h . '</select>';
		};
		$check = static fn( string $key, bool $on, string $label, string $help ) => sprintf( '<p><label><input type="checkbox" name="wpaic[layout][%s]" value="1"%s> %s</label><br><span class="description">%s</span></p>', esc_attr( $key ), checked( $on, true, false ), esc_html( $label ), esc_html( $help ) );

		$fields  = '<h2>' . esc_html( '写真の並べ方' ) . '</h2><div class="wpaic-map-card">';
		$fields .= '<p class="description">' . esc_html( 'プラグインが決める部分です（AIは使いません）。列の数は PC での並び方で、スマートフォンでは1列になります。' ) . '</p>';
		$fields .= '<table class="form-table" role="presentation"><tbody>';
		$fields .= '<tr><th>' . esc_html( '施工前の写真' ) . '</th><td>' . $cols( 'before_columns', (string) $l['before_columns'] ) . '</td></tr>';
		$fields .= '<tr><th>' . esc_html( '施工中の写真（工程ごと）' ) . '</th><td>' . $cols( 'during_columns', (string) $l['during_columns'] ) . '</td></tr>';
		$fields .= '<tr><th>' . esc_html( '施工後の写真' ) . '</th><td>' . $cols( 'after_columns', (string) $l['after_columns'] ) . '</td></tr>';
		$fields .= '</tbody></table>';
		$fields .= $check( 'after_large', (bool) $l['after_large'], '施工後の1枚目を1列（フルサイズ）で大きく出す', '初期値は OFF（1列は使わない）。ON にすると、施工後は「大きい1枚 → 文章 → 残りの写真」の順になります。' );
		$fields .= $check( 'phase_headings', (bool) $l['phase_headings'], '施工前・施工後の文章に小見出しを付ける', '初期値は OFF（文章だけ）。施工中の工程の見出しは、この設定に関係なく付けます。' );
		$fields .= $check( 'photo_titles', (bool) $l['photo_titles'], '写真の見出し（写真のメモ）をページに出す', '初期値は OFF。ON にすると、写真ごとの短いメモを写真の下の見出しとして出します。' );
		$fields .= $check( 'auto_trim', (bool) $l['auto_trim'], '写真の縦横に合わせて「縦長用リサイズ」「トリミング解除」を自動で設定する', '初期値は ON。縦長の写真は縦長用リサイズ、4:3 から外れる横長の写真があるまとまりはトリミング解除にします。' );
		$fields .= '</div>';

		$fields .= '<h2>' . esc_html( '書き方' ) . '</h2><div class="wpaic-map-card">';
		$fields .= '<p><label><strong>' . esc_html( 'サイト共通の書き方のルール（毎回 AI に渡します）' ) . '</strong><br>';
		$fields .= sprintf( '<textarea name="wpaic[writing][rules]" rows="6" class="large-text" maxlength="%d" placeholder="%s">%s</textarea></label></p>', ArticleSettings::MAX_RULES, esc_attr( '例：ご提案は、診断で分かったこと → そのためのご提案、の順に書く。専門用語には短い説明を付ける。お客様のお名前は書かない。' ), esc_textarea( (string) $w['rules'] ) );
		$fields .= '<p class="description">' . esc_html( '記事ごとの「記事の方針」と食い違うときは、記事の方針を優先します。事実を足す・推測で書く・お客様の声を作る、といったルールは守られません。' ) . '</p>';
		$fields .= '<p><strong>' . esc_html( '文章の長さの目安（字数）' ) . '</strong></p><table class="widefat striped wpaic-map-facts"><tbody>';
		foreach ( ArticleSettings::LENGTH_FIELDS as $k => [ $label ] ) {
			[ $min, $max ] = $w['lengths'][ $k ];
			$fields .= sprintf(
				'<tr><th>%s</th><td><input type="number" min="0" max="2000" class="small-text" name="wpaic[writing][lengths][%s][0]" value="%d"> 〜 <input type="number" min="0" max="2000" class="small-text" name="wpaic[writing][lengths][%s][1]" value="%d"> %s</td></tr>',
				esc_html( $label ),
				esc_attr( $k ),
				(int) $min,
				esc_attr( $k ),
				(int) $max,
				esc_html( '字' )
			);
		}
		$fields .= '</tbody></table><p class="description">' . esc_html( '長さは目安です。入力が少ないときは、事実を作らない範囲で短くなります。' ) . '</p></div>';

		self::form( 'save_settings', '', array(), '記事の作り方を保存', 'primary', '', $fields );
		self::form( 'reset_settings', '', array(), '初期値に戻す', 'secondary', '', '' );
	}

	private static function render_transfer(): void {
		echo '<h2>' . esc_html( '設定の書き出し・読み込み' ) . '</h2><div class="wpaic-map-card">';
		echo '<p class="description">' . esc_html( '「写真の並べ方」と「書き方」の設定を、別の案件のサイトへ移すときに使います（書き方の手本と表示メモはサイトごとのため含めません）。' ) . '</p>';
		printf( '<p><label>%s<br><textarea readonly rows="6" class="large-text code" onclick="this.select()">%s</textarea></label></p>', esc_html( '書き出し（コピーして、移したいサイトの「読み込み」に貼り付けます）' ), esc_textarea( ArticleSettings::export() ) );
		self::form( 'import_settings', '', array(), '読み込む', 'secondary', '', '<p><label>' . esc_html( '読み込み（書き出した内容を貼り付けます。今の設定は置き換わります）' ) . '<br><textarea name="settings_json" rows="4" class="large-text code" required></textarea></label></p>' );
		echo '</div>';
	}

	/**
	 * テーマでの表示（表示メモ）：状態・送るファイル・メモの内容・操作。
	 */
	private static function render_display( string $back ): void {
		$status = DisplayNotes::status();
		$s      = DisplayNotes::stored();
		$labels = array(
			'none'     => array( 'warning', '表示メモはまだありません。標準のメモ（shipinc テーマで確かめた内容）を使っています。' ),
			'draft'    => array( 'warning', '表示メモを作りました（承認待ち）。内容を確かめて承認すると、記事の作成・修正に使います。' ),
			'approved' => array( 'success', '承認済みの表示メモを、記事の作成・修正と保存前のチェックに使っています。' ),
			'stale'    => array( 'error', '表示メモを作った後に、テーマのファイルが変わりました。作り直して承認するまで、標準のメモを使います。' ),
		);
		[ $tone, $text ] = $labels[ $status ];
		echo '<h2>' . esc_html( 'テーマでの表示（表示メモ）' ) . '</h2><div class="wpaic-map-card">';
		echo '<p class="description">' . esc_html( '施工事例の表示に使うテーマのファイルを読み取り専用で AI に1回送り、欄ごとに「どこに出るか・テーマが自分で出す見出し（施工前など）・ほかの使われ方（alt・一覧カード）・長さ」をまとめます。記事を作るときの AI への指示と、保存前のチェック（テーマが出す言葉と同じ見出し・キャプションを除く）に使います。テーマのファイルは書き換えません。' ) . '</p>';
		printf( '<p class="wpaic-map-state is-%s"><strong>%s</strong></p>', esc_attr( $tone ), esc_html( $text ) );

		try {
			$set = ThemeReader::collect();
		} catch ( \Throwable $e ) {
			$set = array( 'frame' => '', 'files' => array(), 'skipped' => array() );
		}
		$total  = array_sum( array_map( static fn( $f ) => min( (int) $f['bytes'], ThemeReader::MAX_FILE ), $set['files'] ) );
		$reason = array( 'works' => '名前に works', 'part' => '呼ばれる部品（施工事例の項目を使う）', 'style' => 'スタイル（SCSS）' );
		echo '<details class="wpaic-map-more"' . ( 'none' === $status ? ' open' : '' ) . '><summary>' . esc_html( sprintf( '送るファイル（%d 件・約 %s KB%s）', count( $set['files'] ), number_format( $total / 1024, 0 ), '' !== $set['frame'] ? '・フレーム ' . strtoupper( $set['frame'] ) : '' ) ) . '</summary><table class="widefat striped"><thead><tr><th>' . esc_html( 'ファイル' ) . '</th><th>' . esc_html( '理由' ) . '</th><th>' . esc_html( '大きさ' ) . '</th></tr></thead><tbody>';
		foreach ( $set['files'] as $f ) {
			printf( '<tr><td><code>%s</code></td><td>%s</td><td>%s</td></tr>', esc_html( (string) $f['path'] ), esc_html( $reason[ $f['reason'] ] ?? $f['reason'] ), esc_html( number_format( (int) $f['bytes'] / 1024, 1 ) . ' KB' . ( ! empty( $f['truncated'] ) ? '（途中まで）' : '' ) ) );
		}
		foreach ( $set['skipped'] as $f ) {
			printf( '<tr><td><code>%s</code></td><td colspan="2">%s</td></tr>', esc_html( (string) $f['path'] ), esc_html( '大きさの上限のため送らない' ) );
		}
		echo '</tbody></table><p class="description">' . esc_html( 'コンパイル済みの CSS・使っていないフレーム・old_ のファイルは送りません。送る前に、APIキーなどの秘密らしい文字列がないか調べ、あれば送りません。' ) . '</p></details>';

		if ( $s ) {
			printf( '<p class="description">%s</p>', esc_html( sprintf( '作成：%s（%s・%s）。推定費用 $%s。%s', get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( (string) $s['created_at'] ) ), 'Y-m-d H:i' ), (string) $s['theme'], (string) $s['model'], number_format( (float) $s['usd'], 4 ), ! empty( $s['approved_at'] ) ? '承認：' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( (string) $s['approved_at'] ) ), 'Y-m-d H:i' ) : '' ) ) );
			echo '<table class="widefat striped wpaic-map-notes"><thead><tr><th>' . esc_html( '欄' ) . '</th><th>' . esc_html( '表示' ) . '</th><th>' . esc_html( 'どこに出るか' ) . '</th><th>' . esc_html( 'テーマが出す見出し・ラベル' ) . '</th><th>' . esc_html( 'ほかの使われ方・長さ・書かないこと' ) . '</th></tr></thead><tbody>';
			foreach ( (array) $s['notes']['targets'] as $t ) {
				$other = array_merge( (array) $t['also_used_as'], '' !== $t['length_hint'] ? array( '長さ：' . $t['length_hint'] ) : array(), array_map( static fn( $a ) => '書かない：' . $a, (array) $t['avoid'] ) );
				printf(
					'<tr><th>%s</th><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( DisplayNotes::TARGETS[ $t['id'] ][0] ?? $t['id'] ),
					esc_html( $t['shown'] ? '出る' : '出ない' ),
					esc_html( (string) $t['where'] ),
					esc_html( implode( '、', (array) $t['theme_labels'] ) ),
					esc_html( implode( ' ／ ', $other ) )
				);
			}
			echo '</tbody></table>';
			if ( ! empty( $s['notes']['warnings'] ) ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( '注意：' ) . '</p><ul>';
				foreach ( (array) $s['notes']['warnings'] as $w ) {
					printf( '<li>%s</li>', esc_html( (string) $w ) );
				}
				echo '</ul></div>';
			}
		}

		echo '<div class="wpaic-map-actions">';
		if ( 'draft' === $status ) {
			self::form( 'display_approve', '', array(), '表示メモを承認して使う', 'primary', $back );
		}
		self::form(
			'display_build',
			'',
			array(),
			'none' === $status ? 'テーマのファイルを読み取って表示メモを作る' : '表示メモを作り直す',
			'none' === $status || 'stale' === $status ? 'primary' : 'secondary',
			$back,
			'<p><label><input type="checkbox" name="consent" value="1" required> ' . esc_html( '上の一覧のテーマのファイル（中身）を ' . \WpAiCase\Diagnostics\Settings::info()['label'] . ' へ送信することに同意します（費用の目安：1回 約 $0.01）' ) . '</label></p>'
		);
		if ( $s ) {
			self::form( 'display_discard', '', array(), '表示メモを削除する', 'delete', $back );
		}
		echo '</div></div>';
	}

	/**
	 * 書き方の手本（任意）。
	 */
	private static function render_example( string $back ): void {
		$current = \WpAiCase\Display\StyleExample::post_id();
		echo '<h2>' . esc_html( '書き方の手本（任意）' ) . '</h2><div class="wpaic-map-card">';
		echo '<p class="description">' . esc_html( 'このサイトの施工事例の記事を1つ選ぶと、その文章（コンセプト・ご要望・ご提案・施工前・中・後の文章と各欄の文字数）を、AI に書き方と長さの手本として渡します。手本の記事の事実（地名・材料・色など）は使わせません。お客様の声・担当スタッフのコメント・写真は渡しません。' ) . '</p>';
		$posts = get_posts(
			array(
				'post_type'      => Plugin::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( array( 'key' => \WpAiCase\Draft\DraftWriter::POC_MARK, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$opts = '<option value="0">' . esc_html( '（使わない）' ) . '</option>';
		foreach ( $posts as $p ) {
			$opts .= sprintf( '<option value="%d"%s>%s</option>', (int) $p->ID, selected( $current, (int) $p->ID, false ), esc_html( html_entity_decode( get_the_title( $p ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '（ID: ' . $p->ID . ( 'publish' !== $p->post_status ? '・' . $p->post_status : '' ) . '）' ) );
		}
		$ex = \WpAiCase\Display\StyleExample::for_prompt();
		if ( $current && $ex ) {
			$l = $ex['lengths'];
			printf( '<p>%s</p>', esc_html( sprintf( '使用中：ID %d（説明 %d字・ご要望 %d字・ご提案 %d字・施工前 %d字・施工中 %s字・施工後 %d字）', $current, $l['summary'], $l['request'], $l['proposal'], $l['before'], implode( '／', $l['during'] ) ?: '0', $l['after'] ) ) );
		} elseif ( $current ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( '選んだ記事から文章を読み取れませんでした（削除されたか、文章の欄が空です）。' ) . '</p></div>';
		}
		self::form( 'example_set', '', array(), '手本を保存', 'secondary', $back, '<p><select name="example_post" style="max-width:100%">' . $opts . '</select></p>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上で組み立てたエスケープ済みの選択肢
		echo '</div>';
	}

	/**
	 * @param array<string,string> $hidden
	 * @param string               $extra 追加の入力欄（呼び出し側でエスケープ済みのHTML）
	 */
	private static function form( string $op, string $unused_ctx, array $hidden, string $label, string $style, string $unused_back, string $extra = '' ): void {
		printf( '<form method="post" action="%s" class="wpaic-map-form">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="action" value="wp_ai_case_article"><input type="hidden" name="op" value="%s">', esc_attr( $op ) );
		foreach ( $hidden as $k => $v ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( $v ) );
		}
		echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 呼び出し側で組み立てたエスケープ済みの入力欄。
		printf( '<button type="submit" class="button %s">%s</button></form>', esc_attr( 'primary' === $style ? 'button-primary' : ( 'delete' === $style ? 'button-link-delete' : 'button-secondary' ) ), esc_html( $label ) );
	}
}
