<?php
/**
 * 記事の作り方の設定（管理者が「記事の作り方」画面で変える。開発者に頼まなくても決まりを変えられるように）。
 *
 * - 写真の並べ方（layout）：プラグインのテンプレートが決める部分。AI は使わない。
 * - 書き方（writing）：AI に毎回渡す部分。サイト共通のルール（自由記述）と、各欄の文章の長さの目安。
 * - 変えた設定は、次に作る記事と、次に保存する下書きから効く（保存済みの下書きは、次に反映したときに並べ方が変わる）。
 * - 値はここで検証し、知らない値は初期値に戻す。書き出し・読み込み（別の案件への複製）にも同じ検証を使う。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Display;

defined( 'ABSPATH' ) || exit;

final class ArticleSettings {

	public const OPTION = 'wp_ai_case_article_settings';

	/** 列の数の選び方：auto=写真が3枚以上なら3列・それ以下は2列。 */
	public const COLUMNS = array(
		'auto' => '写真の枚数で自動（3枚以上は3列）',
		'2'    => '2列',
		'3'    => '3列',
	);

	/** 文章の長さの目安の欄（名前・既定の最小・最大）。 */
	public const LENGTH_FIELDS = array(
		'catch'    => array( 'コンセプトの見出し', 30, 40 ),
		'summary'  => array( 'コンセプトの説明', 250, 350 ),
		'request'  => array( 'お客様のご要望', 80, 200 ),
		'proposal' => array( 'ご提案', 250, 450 ),
		'before'   => array( '施工前の文章', 200, 300 ),
		'during'   => array( '施工中の各工程の文章', 80, 200 ),
		'after'    => array( '施工後の文章', 200, 300 ),
		'staff'    => array( '担当スタッフのコメント', 100, 200 ),
	);

	public const MAX_RULES = 2000;

	private static ?array $cache = null;

	/**
	 * @return array{layout:array<string,mixed>,writing:array<string,mixed>}
	 */
	public static function defaults(): array {
		$lengths = array();
		foreach ( self::LENGTH_FIELDS as $k => [ , $min, $max ] ) {
			$lengths[ $k ] = array( $min, $max );
		}
		return array(
			'layout'  => array(
				'before_columns' => 'auto',
				'during_columns' => '2',
				'after_columns'  => '2',
				'after_large'    => false, // 施工後の1枚目を1列（フルサイズ）で大きく出す
				'phase_headings' => false, // 施工前・施工後に小見出しを付ける
				'photo_titles'   => false, // 写真の見出し（写真のメモ）をページに出す
				'auto_trim'      => true,  // 縦長・横長に合わせて「縦長用リサイズ」「トリミング解除」を設定する
			),
			'writing' => array(
				'rules'   => '',
				'lengths' => $lengths,
			),
		);
	}

	/**
	 * @return array{layout:array<string,mixed>,writing:array<string,mixed>}
	 */
	public static function get(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * @param array<string,mixed> $in
	 * @return array{layout:array<string,mixed>,writing:array<string,mixed>}
	 */
	public static function save( array $in ): array {
		$clean = self::sanitize( $in );
		update_option( self::OPTION, $clean, false );
		self::$cache = null;
		return $clean;
	}

	public static function reset(): void {
		delete_option( self::OPTION );
		self::$cache = null;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * 知らない値は初期値に戻す。
	 *
	 * @param array<string,mixed> $in
	 * @return array{layout:array<string,mixed>,writing:array<string,mixed>}
	 */
	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$out = $d;
		$l   = (array) ( $in['layout'] ?? array() );
		foreach ( array( 'before_columns', 'during_columns', 'after_columns' ) as $k ) {
			$v                   = (string) ( $l[ $k ] ?? $d['layout'][ $k ] );
			$out['layout'][ $k ] = isset( self::COLUMNS[ $v ] ) ? $v : $d['layout'][ $k ];
		}
		foreach ( array( 'after_large', 'phase_headings', 'photo_titles', 'auto_trim' ) as $k ) {
			$out['layout'][ $k ] = array_key_exists( $k, $l ) ? self::bool( $l[ $k ] ) : $d['layout'][ $k ];
		}
		$w                       = (array) ( $in['writing'] ?? array() );
		$out['writing']['rules'] = mb_substr( trim( sanitize_textarea_field( (string) ( $w['rules'] ?? '' ) ) ), 0, self::MAX_RULES );
		foreach ( self::LENGTH_FIELDS as $k => $def ) {
			$pair = (array) ( $w['lengths'][ $k ] ?? array() );
			$min  = isset( $pair[0] ) && is_numeric( $pair[0] ) ? max( 0, min( 2000, (int) $pair[0] ) ) : $def[1];
			$max  = isset( $pair[1] ) && is_numeric( $pair[1] ) ? max( 0, min( 2000, (int) $pair[1] ) ) : $def[2];
			if ( $max < $min ) {
				[ $min, $max ] = array( $max, $min );
			}
			$out['writing']['lengths'][ $k ] = array( $min, $max );
		}
		return $out;
	}

	/**
	 * AI に渡す形（書き方のルール・長さの目安・小見出しと写真の見出しを使うか）。
	 *
	 * @return array<string,mixed>
	 */
	public static function for_prompt(): array {
		$s       = self::get();
		$lengths = array();
		foreach ( $s['writing']['lengths'] as $k => [ $min, $max ] ) {
			$lengths[ $k ] = $min . '〜' . $max . '字';
		}
		return array(
			'site_rules'     => $s['writing']['rules'],
			'lengths'        => $lengths,
			'phase_headings' => (bool) $s['layout']['phase_headings'],
			'photo_titles'   => (bool) $s['layout']['photo_titles'],
		);
	}

	/**
	 * 書き出し（別の案件へ読み込むための JSON）。
	 */
	public static function export(): string {
		return (string) wp_json_encode(
			array(
				'format'   => 'wp-ai-case-article-settings',
				'version'  => 1,
				'settings' => self::get(),
			),
			JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
		);
	}

	/**
	 * 読み込み（書き出した JSON）。
	 *
	 * @throws \InvalidArgumentException 形が違うとき
	 */
	public static function import( string $json ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || 'wp-ai-case-article-settings' !== ( $data['format'] ?? '' ) || ! is_array( $data['settings'] ?? null ) ) {
			throw new \InvalidArgumentException( '「記事の作り方」から書き出した設定ではありません' );
		}
		return self::save( $data['settings'] );
	}

	private static function bool( mixed $v ): bool {
		return true === $v || 1 === $v || '1' === $v || 'on' === $v || 'true' === $v;
	}
}
