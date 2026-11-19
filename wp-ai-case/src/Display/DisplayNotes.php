<?php
/**
 * テーマでの表示のされ方のメモ（表示メモ）。
 *
 * - 管理者がマッピング画面（または WP-CLI）で作る：ThemeReader が選んだテーマのファイルを AI に1回送り、
 *   欄（target）ごとに「表示されるか・どこに出るか・テーマが自分で出す見出しやラベル・ほかの使われ方（alt・一覧カードなど）・長さ・書かないこと」をまとめさせる。
 * - 作ったメモは「承認待ち」。管理者が確かめて承認したものだけを、記事の作成・修正の指示と、保存前の機械的なチェックに使う。
 * - 選んだファイルの内容が変われば（テーマの更新など）「作り直しが必要」とし、承認済みのメモは使わない（標準のメモに戻す）。
 * - メモがない・使えないときは、標準のテーマ（shipinc）を読んで確かめた内容（DEFAULTS）を使う。
 * - 保存先はオプション（サイトに1つ）。送ったファイルの一覧と指紋・費用・作った人と日時を記録する（ファイルの中身は記録しない）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Display;

use WpAiCase\AI\ProviderFactory;
use WpAiCase\AI\ProviderInterface;
use WpAiCase\Diagnostics\ErrorClassifier;
use WpAiCase\Diagnostics\PreflightService;
use WpAiCase\Diagnostics\Settings;
use WpAiCase\Jobs\ResultAssembler;
use WpAiCase\Quota\CostGuard;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class DisplayNotes {

	public const OPTION = 'wp_ai_case_display_notes';

	/** 欄（プラグインが書く場所）：id => [名前, ACF の項目名, プラグインが入れるもの] */
	public const TARGETS = array(
		'title'           => array( '投稿のタイトル', 'post_title', '記事のタイトル（【地域】工事の内容｜特徴）' ),
		'catch'           => array( 'コンセプトの見出し', 'EntryData_CatchTxt', '記事の要点を表す見出し' ),
		'summary'         => array( 'コンセプトの説明', 'EntryData_Description', '建物の状態・お悩み・行った工事の説明文' ),
		'request'         => array( 'お客様のご要望', 'Request', 'ご要望をまとめた文章' ),
		'proposal'        => array( 'ご提案', 'Proposal', 'ご提案・施工内容をまとめた文章' ),
		'outline'         => array( '施工概要の表', 'Outline（Outline_Area など）', '地域・建物・築年数・施工箇所・施工内容などの短い値' ),
		'materials'       => array( '使用商材・建材', 'EntryData_Commodity', '使った材料' ),
		'customer_name'   => array( '施主様名', 'EntryData_CustomerName', '地域（市区町村）' ),
		'customer_voice'  => array( 'お客様からの声', 'EntryData_CustomerVoice', 'お客様の声（入力のまま）' ),
		'section_heading' => array( '施工前・施工中・施工後の小見出し', 'EntryData_Section{Mae,Chu,Go}_SubTitle の EntryData_Detail_Catch', '工程ごとの見出し' ),
		'section_text'    => array( '施工前・施工中・施工後の文章', 'EntryData_Detail_Text', '工程ごとの説明文' ),
		'photo_caption'   => array( '写真の見出し', 'EntryData_Picture{Mae,Chu,Go,Feature}_Title', '写真ごとの短い名前' ),
		'photo_text'      => array( '写真の説明文', 'EntryData_Picture{Mae,Chu,Go,Feature}_Txt', '（空にしている）' ),
		'feature'         => array( '特徴の欄', 'Feature_SubtitleText_Subtitle / _Text', '工程に入らない特徴・まとめ' ),
		'before_after'    => array( 'ビフォーアフターの比較', 'EntryData_PictureBeforeAfter_Txt_Before / _After', '比較する写真の短い名前' ),
		'staff_comment'   => array( '担当スタッフのコメント', 'EntryData_Staff_Voice', '担当者からお客様へのコメント' ),
		'fv'              => array( 'トップの写真', 'FV_Layout / FV_Layout_Gallery_alt など', 'トップの写真（1枚・ギャラリー・ビフォーアフター）' ),
		'seo'             => array( 'SEO の設定', 'title / h1 / description / keywords', '検索結果用のタイトル・説明など' ),
	);

	/** 標準のテーマ（shipinc。2026-11-19 にテンプレートを読んで確かめた）。 */
	private const DEFAULTS = array(
		'section_heading' => array( 'where' => '施工前・施工中・施工後の各欄の中の小見出し（h3）', 'theme_labels' => array( '施工前', '施工中', '施工後', 'BEFORE', 'UNDER CONSTRUCTION', 'AFTER' ), 'avoid' => array( '工程の名前' ) ),
		'photo_caption'   => array( 'where' => '写真の下の見出し（h3）', 'theme_labels' => array( '施工前', '施工中', '施工後', 'BEFORE', 'UNDER CONSTRUCTION', 'AFTER' ), 'length_hint' => '20文字以内', 'avoid' => array( '工程の名前', '文' ) ),
		'photo_text'      => array( 'where' => '写真の下の説明文', 'also_used_as' => array( '画像の alt' ) ),
		'catch'           => array( 'where' => '記事の冒頭の見出し', 'also_used_as' => array( '施工事例一覧のカードのタイトル' ), 'length_hint' => '40文字程度・句点なし' ),
		'summary'         => array( 'where' => '記事の冒頭の説明文', 'length_hint' => '200〜350字' ),
		'customer_name'   => array( 'where' => '施工事例一覧のカード・検索結果', 'also_used_as' => array( '一覧のカードの地域ラベル' ) ),
		'customer_voice'  => array( 'where' => '記事の後半「お客様からの声」の欄', 'theme_labels' => array( 'お客様からの声', 'CUSTOMER REVIEWS' ) ),
	);

	private static ?array $cache = null;

	// ---------- 状態 ----------

	/**
	 * @return array<string,mixed>|null
	 */
	public static function stored(): ?array {
		$v = get_option( self::OPTION, null );
		return is_array( $v ) && isset( $v['notes'] ) ? $v : null;
	}

	/**
	 * none（未作成）/ draft（承認待ち）/ approved（使用中）/ stale（テーマが変わった。作り直しが必要）
	 */
	public static function status(): string {
		$s = self::stored();
		if ( ! $s ) {
			return 'none';
		}
		if ( ! hash_equals( (string) $s['fingerprint'], ThemeReader::fingerprint() ) ) {
			return 'stale';
		}
		return 'approved' === $s['status'] ? 'approved' : 'draft';
	}

	/**
	 * 記事の作成・修正に使うメモ（承認済みで、テーマが変わっていなければそれ。なければ標準）。
	 *
	 * @return array{source:string,targets:array<int,array<string,mixed>>,warnings:string[]}
	 */
	public static function for_prompt(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$s = self::stored();
		if ( $s && 'approved' === self::status() ) {
			$targets = array();
			foreach ( (array) $s['notes']['targets'] as $t ) {
				$targets[] = array( 'id' => $t['id'], 'name' => self::TARGETS[ $t['id'] ][0] ?? $t['id'] ) + array_intersect_key( $t, array_flip( array( 'shown', 'where', 'theme_labels', 'also_used_as', 'length_hint', 'avoid' ) ) );
			}
			return self::$cache = array( 'source' => 'theme', 'targets' => $targets, 'warnings' => array_values( (array) $s['notes']['warnings'] ) );
		}
		$targets = array();
		foreach ( self::DEFAULTS as $id => $d ) {
			$targets[] = array( 'id' => $id, 'name' => self::TARGETS[ $id ][0], 'shown' => true ) + $d;
		}
		return self::$cache = array( 'source' => 'default', 'targets' => $targets, 'warnings' => array() );
	}

	/**
	 * その欄でテーマが自分で出す見出し・ラベル（保存前のチェックで、同じ言葉を取り除く）。
	 *
	 * @return string[]
	 */
	public static function labels( string $target ): array {
		// 写真は工程の欄の中に出るので、工程の欄の見出し（施工前など）も同じ言葉として扱う。
		$ids = 'photo_caption' === $target ? array( 'photo_caption', 'section_heading' ) : array( $target );
		$out = array();
		foreach ( self::for_prompt()['targets'] as $t ) {
			if ( in_array( $t['id'], $ids, true ) ) {
				foreach ( (array) ( $t['theme_labels'] ?? array() ) as $l ) {
					$l = (string) $l;
					if ( '' !== trim( $l ) && mb_strlen( $l ) <= 40 ) {
						$out[] = $l;
					}
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function flush(): void {
		self::$cache = null;
	}

	// ---------- 作成・承認 ----------

	/**
	 * テーマのファイルを AI に送り、表示メモを作る（承認待ちで保存）。
	 *
	 * @return array{record:array<string,mixed>,cost:array<string,mixed>}
	 * @throws CaseError
	 */
	public static function build( int $user, ?ProviderInterface $provider = null ): array {
		self::require_admin( $user );
		$pre = PreflightService::check( $user, true );
		if ( ! $pre['ok'] ) {
			throw new CaseError( CaseError::PROVIDER_FAILED, (string) $pre['error']['message'], array( 'reason' => 'preflight', 'code' => (string) $pre['code'] ) );
		}
		$set = ThemeReader::collect();
		if ( ! $set['files'] ) {
			throw new CaseError( CaseError::INVALID_VALUE, '施工事例の表示に使うテーマのファイルが見つかりません', array( 'reason' => 'no_files' ) );
		}
		$files   = ThemeReader::read( $set['files'] );
		$targets = array();
		foreach ( self::TARGETS as $id => [ $name, $fields, $writes ] ) {
			$targets[] = array( 'id' => $id, 'name' => $name, 'acf_fields' => $fields, 'plugin_writes' => $writes );
		}
		$payload = array(
			'model'   => Settings::model(),
			'frame'   => $set['frame'],
			'targets' => $targets,
			'files'   => $files,
		);
		$cost  = new CostGuard();
		$bytes = strlen( (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) );
		$cost->check( 'display', CostGuard::estimate( Settings::model(), 0, $bytes )['usd'] );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$provider = $provider ?? ProviderFactory::make();
		$result   = $provider->display_notes( $payload );
		$usd      = $cost->record( 'display', Settings::model(), null, 0, (array) ( $result->data['usage'] ?? array() ), $result->ok ? 'ok' : (string) ( $result->error_type ?? 'error' ) );
		$u        = (array) ( $result->data['usage'] ?? array() );
		$entry    = array(
			'kind'              => 'display',
			'usd'               => round( $usd, 6 ),
			'input_tokens'      => (int) ( $u['input_tokens'] ?? 0 ),
			'cache_read_tokens' => (int) ( $u['cache_read_input_tokens'] ?? 0 ),
			'output_tokens'     => (int) ( $u['output_tokens'] ?? 0 ),
			'ok'                => $result->ok,
			'at'                => time(),
		);
		if ( ! $result->ok ) {
			$class = ErrorClassifier::from_provider( $result ) ?? array( 'code' => 'AI-018' );
			throw new CaseError( CaseError::PROVIDER_FAILED, 'AIで表示メモを作れませんでした（' . $class['code'] . '）', array( 'reason' => 'provider', 'code' => $class['code'], 'cost' => $entry ) );
		}
		$record = array(
			'status'      => 'draft',
			'fingerprint' => ThemeReader::fingerprint( $set ),
			'frame'       => $set['frame'],
			'theme'       => wp_get_theme()->get_stylesheet() . ' ' . (string) wp_get_theme()->get( 'Version' ),
			'files'       => array_map( static fn( $f ) => array_intersect_key( $f, array_flip( array( 'path', 'reason', 'bytes', 'sha256', 'truncated' ) ) ), $set['files'] ),
			'skipped'     => array_map( static fn( $f ) => (string) $f['path'], $set['skipped'] ),
			'notes'       => self::clean( (array) ( $result->data['content'] ?? array() ) ),
			'provider'    => $provider->id(),
			'model'       => Settings::model(),
			'usd'         => $entry['usd'],
			'created_by'  => $user,
			'created_at'  => gmdate( 'c' ),
		);
		update_option( self::OPTION, $record, false );
		self::flush();
		return array( 'record' => $record, 'cost' => $entry );
	}

	public static function approve( int $user ): array {
		self::require_admin( $user );
		$s = self::stored();
		if ( ! $s ) {
			throw new CaseError( CaseError::INVALID_TRANSITION, '表示メモがありません' );
		}
		if ( 'stale' === self::status() ) {
			throw new CaseError( CaseError::INVALID_TRANSITION, '作った後にテーマのファイルが変わりました。表示メモを作り直してください' );
		}
		$s['status']      = 'approved';
		$s['approved_by'] = $user;
		$s['approved_at'] = gmdate( 'c' );
		update_option( self::OPTION, $s, false );
		self::flush();
		return $s;
	}

	public static function discard( int $user ): void {
		self::require_admin( $user );
		delete_option( self::OPTION );
		self::flush();
	}

	/**
	 * AI の出力を、知っている欄・短い文字列だけにする。
	 *
	 * @param array<string,mixed> $content
	 * @return array{targets:array<int,array<string,mixed>>,warnings:string[]}
	 */
	public static function clean( array $content ): array {
		$list    = static fn( $v, int $n, int $len ) => array_values( array_filter( array_slice( array_map( static fn( $x ) => ResultAssembler::line( (string) $x, $len ), (array) $v ), 0, $n ), static fn( $x ) => '' !== $x ) );
		$targets = array();
		$seen    = array();
		foreach ( (array) ( $content['targets'] ?? array() ) as $t ) {
			$id = is_array( $t ) ? (string) ( $t['id'] ?? '' ) : '';
			if ( ! isset( self::TARGETS[ $id ] ) || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$targets[]   = array(
				'id'           => $id,
				'shown'        => (bool) ( $t['shown'] ?? false ),
				'where'        => ResultAssembler::line( (string) ( $t['where'] ?? '' ), 200 ),
				'theme_labels' => $list( $t['theme_labels'] ?? array(), 12, 40 ),
				'also_used_as' => $list( $t['also_used_as'] ?? array(), 8, 80 ),
				'length_hint'  => ResultAssembler::line( (string) ( $t['length_hint'] ?? '' ), 80 ),
				'avoid'        => $list( $t['avoid'] ?? array(), 8, 80 ),
			);
		}
		return array(
			'targets'  => $targets,
			'warnings' => $list( $content['warnings'] ?? array(), 10, 300 ),
		);
	}

	private static function require_admin( int $user ): void {
		if ( $user < 1 || ! user_can( $user, 'manage_options' ) ) {
			throw new CaseError( CaseError::FORBIDDEN, '表示メモの操作は管理者だけが行えます' );
		}
	}
}
