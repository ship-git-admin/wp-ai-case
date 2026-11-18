<?php
/**
 * 管理者専用「AI施工事例 > マッピング」。
 *
 * ACFの構造と、AIの下書きの保存先の対応（マッピング）を、WP-CLI なしで確認・再設定できるようにする。
 *   提案（ルールで結び付け・構造検証）→ 検証用の下書きで保存・読み戻し → プレビューで実表示を確認 → 承認
 * 構造変更の検知（保存停止）、テーマ変更後の表示の再確認、退役・巻き戻し、履歴の表示も行う。
 *
 * - 権限は manage_options。操作はすべて POST（nonce）で、MappingService が状態遷移を検査する。
 * - 外部（AI）への通信はしない。検証用の下書きは下書きのまま（公開しない）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Admin;

use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Mapping\MappingService;
use WpAiCase\Mapping\StagingValidator;
use WpAiCase\Plugin;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class MappingPage {

	public const SLUG   = 'wp-ai-case-mapping';
	private const FLASH = 'wp_ai_case_mapping_flash_';
	private const NONCE = 'wp_ai_case_mapping';

	public static string $hook = '';

	private const STATE_LABELS = array(
		'ready'               => array( '利用できます', 'success' ),
		'blocked_drift'       => array( 'ACFの構造変更を検出しました（この条件の保存を止めています）', 'error' ),
		'blocked_unsupported' => array( '未対応の構造があります（保存できません）', 'error' ),
		'pending'             => array( '検証・承認の途中です（まだ保存に使えません）', 'warning' ),
		'no_mapping'          => array( 'マッピングがありません（保存できません）', 'warning' ),
	);

	private const STATUS_LABELS = array(
		'proposed'                   => '提案',
		'proposed_rebuild'           => '作り直しの提案',
		'validated_local'            => '構造検証に合格',
		'pending_staging_validation' => '保存・読み戻しに合格（表示確認待ち）',
		'validated_staging'          => '表示確認済み（承認待ち）',
		'approved_active'            => '承認済み（使用中）',
		'blocked_drift'              => '構造変更で停止',
		'blocked_unsupported'        => '未対応の構造',
		'retired'                    => '退役',
	);

	private const CLASS_LABELS = array(
		'compatible'     => '互換（マッピングが使わない部分だけの変更）',
		'key_only'       => 'フィールドキーだけの変更',
		'root_supported' => '構造の組み替え（対応できる型）',
		'unsupported'    => '未対応の型・構造',
		'uncertain'      => '対応先が決められない',
		'initial'        => '初回',
	);

	public static function register(): void {
		add_action( 'admin_post_wp_ai_case_mapping', array( self::class, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url( string $ctx = '' ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . ( '' !== $ctx ? '&ctx=' . rawurlencode( $ctx ) : '' ) );
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
		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$ctx_raw = isset( $_POST['ctx'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ctx'] ) ) : '';
		$text    = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['reason'] ) ) : '';
		$user    = get_current_user_id();
		$service = new MappingService();
		$back    = isset( $_POST['back'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['back'] ) ) : '';
		try {
			if ( 'cleanup_all' === $op ) {
				$n = ( new StagingValidator( $service ) )->cleanup();
				self::flash( 'success', "検証用の下書き {$n} 件と検証用の画像を削除しました。", $back );
			}
			$context = self::decode( $ctx_raw );
			if ( 'propose' === $op ) {
				$r   = $service->propose( $context, $user );
				$msg = 'blocked_unsupported' === $r['state']
					? '提案しましたが、未対応の構造があるため保存には使えません（下の「問題」を確認してください）。'
					: "マッピング v{$r['row']['mapping_version']} を提案し、構造検証に合格しました。次に検証用の下書きで保存・読み戻しを確認してください。";
				self::flash( 'blocked_unsupported' === $r['state'] ? 'warning' : 'success', $msg, $back );
			}
			$row = self::row( $service, $context );
			switch ( $op ) {
				case 'stage':
					$r = ( new StagingValidator( $service ) )->run( $row, $context, $user );
					self::flash( 'success', '検証用の下書き ' . count( $r['drafts'] ) . ' 件（トップの写真 3パターン）を保存し、読み戻しが一致しました。プレビューで表示を確認してください。', $back );
					// no break（flash は終了する）
				case 'confirm_display':
					if ( empty( $_POST['seen'] ) ) {
						throw new CaseError( CaseError::INVALID_TRANSITION, '表示を確認したことのチェックを入れてください' );
					}
					$drafts   = ( new StagingValidator( $service ) )->drafts( (string) $row['mapping_id'], (int) $row['mapping_version'] );
					$evidence = 'admin-visual drafts:' . implode( ',', $drafts ) . ( '' !== $text ? ' memo:' . $text : '' );
					if ( 'approved_active' === $row['status'] ) {
						$service->reconfirm_display( $row, $context, $user, $evidence );
						self::flash( 'success', '表示の再確認を記録しました。', $back );
					}
					$service->confirm_display( $row, $context, $user, $evidence );
					self::flash( 'success', '実表示の確認を記録しました。内容を確かめて承認してください。', $back );
					// no break
				case 'approve':
					$service->approve( $row, $context, $user, $text );
					( new StagingValidator( $service ) )->cleanup( $row['mapping_id'] . ':' . $row['mapping_version'] );
					self::flash( 'success', "マッピング v{$row['mapping_version']} を承認しました。この条件の下書きの作成・保存を再開できます（検証用の下書きは削除しました）。", $back );
					// no break
				case 'retire':
					if ( '' === $text || empty( $_POST['sure'] ) ) {
						throw new CaseError( CaseError::INVALID_TRANSITION, '理由を入力し、確認のチェックを入れてください' );
					}
					$service->retire( $row, $user, $text );
					self::flash( 'success', "マッピング v{$row['mapping_version']} を退役しました。この条件では、新しい版を承認するまで保存できません。", $back );
					// no break
				case 'rollback':
					if ( '' === $text ) {
						throw new CaseError( CaseError::INVALID_TRANSITION, '巻き戻しの理由を入力してください' );
					}
					$old = $service->repository()->get( (string) $row['mapping_id'], (int) ( $_POST['target_version'] ?? 0 ) );
					if ( ! $old ) {
						throw new CaseError( CaseError::INVALID_TRANSITION, '戻す版が見つかりません' );
					}
					$new = $service->rollback( $old, $context, $user, $text );
					self::flash( 'success', "v{$old['mapping_version']} の結び付けを v{$new['mapping_version']} として戻しました（承認待ち）。承認すると再開します。", $back );
					// no break
				default:
					throw new CaseError( CaseError::INVALID_TRANSITION, '不明な操作です' );
			}
		} catch ( CaseError $e ) {
			self::flash( 'error', $e->ai_code() . '：' . $e->getMessage(), $back );
		}
	}

	/**
	 * 操作の対象の版（画面で見ていた版が、今もその条件の版であること）。
	 *
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 * @return array<string,mixed>
	 */
	private static function row( MappingService $service, array $context ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle() で検証済み。
		$id      = isset( $_POST['mapping_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mapping_id'] ) ) : '';
		$version = isset( $_POST['version'] ) ? (int) $_POST['version'] : 0;
		// phpcs:enable
		$row = $service->repository()->get( $id, $version );
		if ( ! $row || $row['context_hash'] !== $service->current( $context )['context_hash'] ) {
			throw new CaseError( CaseError::INVALID_TRANSITION, '対象の版が見つからないか、条件が一致しません。画面を読み込み直してください' );
		}
		return $row;
	}

	private static function flash( string $type, string $text, string $ctx ): void {
		set_transient( self::FLASH . get_current_user_id(), array( 'type' => $type, 'text' => $text ), 120 );
		wp_safe_redirect( self::url( $ctx ) );
		exit;
	}

	// ---------- 条件（文脈）の表し方 ----------

	/**
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 */
	public static function encode( array $context ): string {
		$parts = array();
		foreach ( $context['post_terms'] as $tax => $ids ) {
			if ( $ids ) {
				$parts[] = $tax . '=' . implode( ',', array_map( 'intval', $ids ) );
			}
		}
		return 't:' . implode( ';', $parts );
	}

	/**
	 * "t:tax=1,2;tax2=3"（新規投稿に付けるターム）または "post:123"（既存の投稿の条件）。
	 *
	 * @return array{post_type:string,post_id:int,post_terms:array<string,int[]>}
	 */
	public static function decode( string $ctx ): array {
		$inspector = new SchemaInspector();
		if ( preg_match( '/^post:(\d+)$/', $ctx, $m ) ) {
			$post = get_post( (int) $m[1] );
			if ( ! $post || Plugin::POST_TYPE !== $post->post_type ) {
				throw new CaseError( CaseError::INVALID_TRANSITION, '施工事例の投稿IDを指定してください' );
			}
			return $inspector->context( Plugin::POST_TYPE, (int) $post->ID );
		}
		$terms = array();
		$body  = str_starts_with( $ctx, 't:' ) ? substr( $ctx, 2 ) : '';
		foreach ( array_filter( explode( ';', $body ) ) as $pair ) {
			[ $tax, $ids ] = array_pad( explode( '=', $pair, 2 ), 2, '' );
			if ( ! taxonomy_exists( $tax ) ) {
				throw new CaseError( CaseError::INVALID_TRANSITION, '条件のタクソノミーが見つかりません' );
			}
			$terms[ $tax ] = array_values( array_filter( array_map( 'intval', explode( ',', $ids ) ) ) );
		}
		return $inspector->context( Plugin::POST_TYPE, 0, $terms );
	}

	/**
	 * 表示する条件：新規作成（タームなし）、マッピングの記録がある条件、指定された投稿の条件。
	 *
	 * @return array<string,array{post_type:string,post_id:int,post_terms:array<string,int[]>}>
	 */
	private static function contexts( MappingService $service, string $extra ): array {
		$inspector = new SchemaInspector();
		$out       = array();
		if ( '' !== $extra ) {
			try {
				$out[ $extra ] = self::decode( $extra );
			} catch ( CaseError $e ) {
				unset( $out[ $extra ] );
			}
		}
		$default                       = $inspector->context( Plugin::POST_TYPE, 0, array() );
		$out[ self::encode( $default ) ] = $default;
		foreach ( $service->repository()->all() as $row ) {
			$terms = array();
			foreach ( (array) ( $row['document']['context_selector']['taxonomy_terms'] ?? array() ) as $t ) {
				if ( ! term_exists( (int) $t['term_id'], (string) $t['taxonomy'] ) ) {
					continue 2; // タームが消えた条件は表示しない
				}
				$terms[ (string) $t['taxonomy'] ][] = (int) $t['term_id'];
			}
			$ctx = $inspector->context( Plugin::POST_TYPE, 0, $terms );
			$key = self::encode( $ctx );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $ctx;
			}
		}
		return $out;
	}

	/**
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 */
	private static function context_label( array $context ): string {
		$parts = array();
		foreach ( $context['post_terms'] as $tax => $ids ) {
			$obj = get_taxonomy( $tax );
			foreach ( $ids as $id ) {
				$term    = get_term( $id, $tax );
				$parts[] = ( $obj ? $obj->labels->singular_name : $tax ) . '：' . ( $term && ! is_wp_error( $term ) ? $term->name : '#' . $id );
			}
		}
		$base = $context['post_id'] > 0 ? '投稿 #' . $context['post_id'] . ' の条件' : '新規作成の施工事例';
		return $base . '（' . ( $parts ? implode( '、', $parts ) : 'タームなし' ) . '）';
	}

	// ---------- 画面 ----------

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'wp-ai-case' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示する条件の指定（読み取りのみ）。
		$extra   = isset( $_GET['ctx'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ctx'] ) ) : '';
		if ( '' === $extra && ! empty( $_GET['post_id_ctx'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$extra = 'post:' . absint( $_GET['post_id_ctx'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$flash   = get_transient( self::FLASH . get_current_user_id() );
		$service = new MappingService();
		delete_transient( self::FLASH . get_current_user_id() );

		echo '<div class="wrap wpaic-map"><h1>' . esc_html( 'AI施工事例：マッピング' ) . '</h1>';
		echo '<p>' . esc_html( 'このサイトのACF（施工事例の入力欄）の構造と、AIで作った下書きの保存先の対応（マッピング）を管理します。承認済みのマッピングがある条件でだけ、下書きを保存できます。外部（AI）へは通信しません。' ) . '</p>';
		if ( is_array( $flash ) ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( (string) $flash['type'] ), esc_html( (string) $flash['text'] ) );
		}

		foreach ( self::contexts( $service, $extra ) as $key => $context ) {
			try {
				self::render_context( $service, (string) $key, $context, $extra );
			} catch ( CaseError $e ) {
				printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $e->ai_code() . '：' . $e->getMessage() ) );
			}
		}

		echo '<h2>' . esc_html( '既存の投稿の条件で確認' ) . '</h2>';
		echo '<p class="description">' . esc_html( '下書きにカテゴリーなどを付けると、適用されるACFが変わることがあります。投稿IDを入れると、その投稿の条件で状態を確認できます。' ) . '</p>';
		printf(
			'<form method="get" action="%s"><input type="hidden" name="page" value="%s"><label>%s <input type="number" min="1" name="post_id_ctx" class="small-text" required></label> ',
			esc_url( admin_url( 'admin.php' ) ),
			esc_attr( self::SLUG ),
			esc_html( '投稿ID' )
		);
		submit_button( '確認', 'secondary', '', false );
		echo '</form>';

		echo '<h2>' . esc_html( '検証用の下書き・画像' ) . '</h2><p class="description">' . esc_html( '検証用の下書き（【検証用】）は下書きのままで公開されません。承認すると、その版の検証用の下書きは自動で削除されます。' ) . '</p>';
		self::form( 'cleanup_all', '', array(), '検証用の下書きと画像をすべて削除', 'delete', $extra );
		echo '</div>';
	}

	/**
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 */
	private static function render_context( MappingService $service, string $key, array $context, string $back ): void {
		$s    = $service->inspect( $context, get_current_user_id(), true );
		$row  = $s['row'];
		$ctx  = $context['post_id'] > 0 ? 'post:' . $context['post_id'] : self::encode( $context );
		[ $label, $tone ] = self::STATE_LABELS[ $s['state'] ] ?? array( $s['state'], 'warning' );

		echo '<div class="wpaic-map-card">';
		echo '<h2>' . esc_html( self::context_label( $context ) ) . '</h2>';
		printf( '<p class="wpaic-map-state is-%s"><strong>%s</strong></p>', esc_attr( $tone ), esc_html( $label ) );

		echo '<table class="widefat striped wpaic-map-facts"><tbody>';
		self::fact_row( '使用中・対象の版', $row ? 'v' . $row['mapping_version'] . '（' . ( self::STATUS_LABELS[ $row['status'] ] ?? $row['status'] ) . '）' : '—' );
		self::fact_row( '適用されるACFのグループ', implode( '、', $s['groups'] ) );
		if ( $s['classification'] ) {
			self::fact_row( '変更の分類', self::CLASS_LABELS[ $s['classification'] ] ?? $s['classification'] );
		}
		if ( null !== $s['display'] ) {
			self::fact_row(
				'実表示の確認',
				array(
					'verified'         => '確認済み',
					'recheck_required' => 'テーマが変わったため、再確認が必要です（保存は続けられます）',
					'unverified'       => '未確認',
				)[ $s['display'] ] ?? $s['display']
			);
		}
		if ( 'blocked_drift' === $s['state'] ) {
			$affected = $service->affected_drafts( (string) $s['context_hash'] );
			self::fact_row( '影響を受ける下書き', count( $affected ) . ' 件（この条件の下書きの保存を止めています。公開中の記事の表示は止めていません）' );
		}
		echo '</tbody></table>';

		if ( $s['problems'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( '問題（追加の開発・ACFの見直しが必要なもの）：' ) . '</p><ul>';
			foreach ( array_slice( $s['problems'], 0, 20 ) as $p ) {
				printf( '<li><code>%s</code> %s</li>', esc_html( (string) ( $p['slot'] ?? '' ) ), esc_html( (string) ( $p['message'] ?? '' ) ) );
			}
			echo '</ul></div>';
		}

		self::render_steps( $row, $s['state'], (string) $s['display'] );
		self::render_actions( $service, $s, $row, $ctx, $back );

		if ( $row ) {
			self::render_versions( $service, $row, $context, $ctx, $back, 'ready' === $s['state'] );
			self::render_bindings( $row );
			self::render_history( $service, $row );
		}
		echo '</div>';
	}

	/**
	 * @param array<string,mixed>|null $row
	 */
	private static function render_steps( ?array $row, string $state, string $display ): void {
		$v     = $row['document']['validation'] ?? array();
		$steps = array(
			array( '1. 提案・構造検証', ! empty( $v['fixture_passed'] ) ),
			array( '2. 検証用の下書きで保存・読み戻し', ! empty( $v['staging_write_read_passed'] ) ),
			array( '3. プレビューで実表示を確認', ! empty( $v['staging_display_passed'] ) ),
			array( '4. 管理者の承認', 'approved_active' === ( $row['status'] ?? '' ) ),
		);
		if ( in_array( $state, array( 'no_mapping', 'blocked_drift' ), true ) ) {
			foreach ( $steps as $i => $step ) {
				$steps[ $i ][1] = false;
			}
		}
		echo '<ol class="wpaic-map-steps">';
		foreach ( $steps as $i => [ $text, $done ] ) {
			if ( 2 === $i && 'recheck_required' === $display ) {
				printf( '<li class="is-recheck">%s</li>', esc_html( $text . '（テーマ変更後の再確認が必要）' ) );
				continue;
			}
			printf( '<li class="%s">%s%s</li>', $done ? 'is-done' : '', esc_html( $text ), $done ? esc_html( '（済）' ) : '' );
		}
		echo '</ol>';
	}

	/**
	 * @param array<string,mixed>      $s
	 * @param array<string,mixed>|null $row
	 */
	private static function render_actions( MappingService $service, array $s, ?array $row, string $ctx, string $back ): void {
		$ids    = $row ? array( 'mapping_id' => (string) $row['mapping_id'], 'version' => (string) $row['mapping_version'] ) : array();
		$status = $row['status'] ?? '';
		echo '<div class="wpaic-map-actions">';
		if ( 'ready' !== $s['state'] && ! in_array( $status, array( 'validated_local', 'pending_staging_validation', 'validated_staging' ), true ) ) {
			echo '<p>' . esc_html( '現在のACFの構造に合わせて、マッピングを（作り直して）提案します。ルールで結び付け、架空のデータで構造を検証します。' ) . '</p>';
			self::form( 'propose', $ctx, array(), 'マッピングを提案する', 'primary', $back );
		}
		if ( 'validated_local' === $status ) {
			echo '<p>' . esc_html( '検証用の下書き（トップの写真 3パターン）をこの版で保存し、読み戻して一致を確かめます。下書きは公開されません。' ) . '</p>';
			self::form( 'stage', $ctx, $ids, '検証用の下書きで保存・読み戻しを確認', 'primary', $back );
		}
		$recheck = 'ready' === $s['state'] && 'verified' !== $s['display'];
		if ( 'pending_staging_validation' === $status || $recheck ) {
			$drafts = ( new StagingValidator( $service ) )->drafts( (string) $row['mapping_id'], (int) $row['mapping_version'] );
			if ( ! $drafts && $recheck ) {
				echo '<p>' . esc_html( '表示を確認し直すための検証用の下書きを作ります。' ) . '</p>';
				self::form( 'stage', $ctx, $ids, '表示確認用の下書きを作る', 'secondary', $back );
			}
			if ( $drafts ) {
				echo '<p>' . esc_html( '次の検証用の下書きをプレビューで開き、トップの写真・施工前/中/後・本文・写真・お客様の声・担当スタッフ・SEO の表示を、PC とスマートフォンで確認してください。' ) . '</p><ul class="wpaic-map-drafts">';
				foreach ( $drafts as $hero => $id ) {
					printf(
						'<li>%s：<a href="%s" target="_blank" rel="noopener">%s</a> ／ <a href="%s">%s</a></li>',
						esc_html( 'トップの写真「' . ( StagingValidator::HERO_LABELS[ $hero ] ?? $hero ) . '」' ),
						esc_url( (string) get_preview_post_link( $id ) ),
						esc_html( 'プレビュー' ),
						esc_url( (string) get_edit_post_link( $id, 'raw' ) ),
						esc_html( '編集画面' )
					);
				}
				echo '</ul>';
				self::form(
					'confirm_display',
					$ctx,
					$ids,
					$recheck ? '表示の再確認を記録' : '実表示を確認済みにする',
					'primary',
					$back,
					'<p><label><input type="checkbox" name="seen" value="1" required> ' . esc_html( '上の下書きをプレビューで開き、表示に問題がないことを確認しました' ) . '</label></p><p><label>' . esc_html( 'メモ（任意）' ) . '<br><input type="text" name="reason" class="large-text" maxlength="300"></label></p>'
				);
			}
		}
		if ( 'validated_staging' === $status ) {
			echo '<p>' . esc_html( '構造検証・保存と読み戻し・実表示の確認がそろいました。承認すると、この条件で下書きの作成・保存を再開します。' ) . '</p>';
			self::form( 'approve', $ctx, $ids, '承認して再開する', 'primary', $back, '<p><label>' . esc_html( '承認の理由（必須）' ) . '<br><input type="text" name="reason" class="large-text" maxlength="300" required></label></p>' );
		}
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 */
	private static function render_versions( MappingService $service, array $row, array $context, string $ctx, string $back, bool $ready ): void {
		$rows = $service->repository()->by_context( (string) $row['context_hash'] );
		$cur  = $service->current( $context );
		echo '<details class="wpaic-map-more"><summary>' . esc_html( '版の一覧・退役・巻き戻し' ) . '</summary><table class="widefat striped"><thead><tr><th>' . esc_html( '版' ) . '</th><th>' . esc_html( '状態' ) . '</th><th>' . esc_html( '承認' ) . '</th><th>' . esc_html( '操作' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$approval = $r['document']['approval'] ?? null;
			$who      = $approval ? get_userdata( (int) $approval['approved_by_user_id'] ) : null;
			echo '<tr><td>v' . esc_html( (string) $r['mapping_version'] ) . '</td><td>' . esc_html( self::STATUS_LABELS[ $r['status'] ] ?? $r['status'] ) . '</td><td>';
			echo $approval ? esc_html( ( $who ? $who->display_name : '#' . $approval['approved_by_user_id'] ) . ' / ' . get_date_from_gmt( str_replace( array( 'T', 'Z' ), array( ' ', '' ), (string) $approval['approved_at'] ), 'Y-m-d H:i' ) . ' / ' . $approval['approval_reason'] ) : '—';
			echo '</td><td>';
			$ids = array( 'mapping_id' => (string) $r['mapping_id'], 'version' => (string) $r['mapping_version'] );
			if ( 'approved_active' === $r['status'] ) {
				self::form( 'retire', $ctx, $ids, '退役（この条件の保存を止める）', 'delete', $back, '<label><input type="text" name="reason" placeholder="' . esc_attr( '理由（必須）' ) . '" required></label> <label><input type="checkbox" name="sure" value="1" required> ' . esc_html( '保存が止まることを理解しました' ) . '</label> ' );
			} elseif ( ! $ready && $approval && $r['schema_sha256'] === $cur['schema_sha256'] && $r['context_hash'] === $cur['context_hash'] ) {
				self::form( 'rollback', $ctx, array( 'mapping_id' => (string) $r['mapping_id'], 'version' => (string) $row['mapping_version'], 'target_version' => (string) $r['mapping_version'] ), 'この版に戻す', 'secondary', $back, '<label><input type="text" name="reason" placeholder="' . esc_attr( '理由（必須）' ) . '" required></label> ' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></details>';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_bindings( array $row ): void {
		$bindings = (array) ( $row['document']['bindings'] ?? array() );
		echo '<details class="wpaic-map-more"><summary>' . esc_html( '結び付け（' . count( $bindings ) . ' 件）' ) . '</summary><table class="widefat striped"><thead><tr><th>' . esc_html( 'テンプレートの項目' ) . '</th><th>' . esc_html( 'このサイトの保存先' ) . '</th><th>' . esc_html( '型' ) . '</th><th>' . esc_html( '根拠' ) . '</th></tr></thead><tbody>';
		foreach ( $bindings as $b ) {
			printf(
				'<tr><td><code>%s</code></td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $b['semantic_slot'] ),
				esc_html( implode( ' › ', (array) $b['path'] ) ),
				esc_html( (string) $b['field_type'] ),
				esc_html( (string) ( $b['evidence'] ?? '' ) )
			);
		}
		echo '</tbody></table></details>';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function render_history( MappingService $service, array $row ): void {
		$events = array_reverse( $service->repository()->events( (string) $row['mapping_id'] ) );
		echo '<details class="wpaic-map-more"><summary>' . esc_html( '履歴（新しい順・最大30件）' ) . '</summary><table class="widefat striped"><thead><tr><th>' . esc_html( '日時' ) . '</th><th>' . esc_html( '版' ) . '</th><th>' . esc_html( '状態' ) . '</th><th>' . esc_html( '内容' ) . '</th><th>' . esc_html( '実施者' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $events, 0, 30 ) as $e ) {
			$who = ! empty( $e['actor_user_id'] ) ? get_userdata( (int) $e['actor_user_id'] ) : null;
			printf(
				'<tr><td>%s</td><td>v%s</td><td>%s → %s</td><td>%s%s</td><td>%s</td></tr>',
				esc_html( get_date_from_gmt( str_replace( array( 'T', 'Z' ), array( ' ', '' ), (string) $e['occurred_at'] ), 'Y-m-d H:i' ) ),
				esc_html( (string) $e['mapping_version'] ),
				esc_html( self::STATUS_LABELS[ (string) ( $e['from_status'] ?? '' ) ] ?? '（新規）' ),
				esc_html( self::STATUS_LABELS[ (string) $e['to_status'] ] ?? (string) $e['to_status'] ),
				esc_html( (string) $e['reason'] ),
				! empty( $e['evidence_refs'] ) ? '<br><small>' . esc_html( '証跡：' . implode( ' / ', (array) $e['evidence_refs'] ) ) . '</small>' : '',
				esc_html( $who ? $who->display_name : '（自動）' )
			);
		}
		echo '</tbody></table></details>';
	}

	private static function fact_row( string $label, string $value ): void {
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	/**
	 * @param array<string,string> $hidden
	 * @param string               $extra 追加の入力欄（呼び出し側でエスケープ済みのHTML）
	 */
	private static function form( string $op, string $ctx, array $hidden, string $label, string $style, string $back, string $extra = '' ): void {
		printf( '<form method="post" action="%s" class="wpaic-map-form">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="action" value="wp_ai_case_mapping"><input type="hidden" name="op" value="%s"><input type="hidden" name="ctx" value="%s"><input type="hidden" name="back" value="%s">', esc_attr( $op ), esc_attr( $ctx ), esc_attr( $back ) );
		foreach ( $hidden as $k => $v ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( $v ) );
		}
		echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 呼び出し側で組み立てたエスケープ済みの入力欄。
		printf( '<button type="submit" class="button %s">%s</button></form>', esc_attr( 'primary' === $style ? 'button-primary' : ( 'delete' === $style ? 'button-link-delete' : 'button-secondary' ) ), esc_html( $label ) );
	}
}
