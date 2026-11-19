<?php
/**
 * 書き方の手本（このサイトの施工事例の記事を1つ選び、その文章を AI に「書き方・長さの手本」として渡す）。
 *
 * - 管理者がマッピング画面（または WP-CLI）で選ぶ。選ばなければ使わない（記事の書き方のルールの長さの目安だけ）。
 * - 渡すのは文章の欄だけ（コンセプトの見出し・説明、ご要望、ご提案、施工前・中・後の文章）と各欄の文字数。
 *   お客様の声・担当スタッフのコメント・写真・地域などの概要の表は渡さない。
 * - AI には「別の現場の記事。文体・構成・長さだけを手本にし、事実は使わない」と指示する（Prompts::SYSTEM_COMPOSE）。
 * - 各欄は 600 字まで、合計 4000 字まで。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Display;

use WpAiCase\Plugin;

defined( 'ABSPATH' ) || exit;

final class StyleExample {

	public const OPTION = 'wp_ai_case_style_example';

	private static ?array $cache = null;

	public static function post_id(): int {
		return (int) get_option( self::OPTION, 0 );
	}

	/**
	 * @throws \InvalidArgumentException 施工事例の記事でないとき
	 */
	public static function set( int $post_id ): void {
		if ( 0 === $post_id ) {
			delete_option( self::OPTION );
		} else {
			$post = get_post( $post_id );
			if ( ! $post || Plugin::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
				throw new \InvalidArgumentException( '施工事例の記事を選んでください' );
			}
			update_option( self::OPTION, $post_id, false );
		}
		self::$cache = null;
	}

	/**
	 * AI に渡す手本（選んでいない・記事がない・文章が取れなければ null）。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function for_prompt(): ?array {
		if ( null !== self::$cache ) {
			return self::$cache ?: null;
		}
		$id   = self::post_id();
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || Plugin::POST_TYPE !== $post->post_type || 'trash' === $post->post_status || ! function_exists( 'get_field' ) ) {
			self::$cache = array();
			return null;
		}
		$clip = static fn( $v ) => mb_substr( trim( (string) preg_replace( '/[ \t]+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) ), 0, 600 );
		$ex   = array(
			'catch'    => $clip( get_field( 'EntryData_CatchTxt', $id ) ),
			'summary'  => $clip( get_field( 'EntryData_Description', $id ) ),
			'request'  => $clip( get_field( 'Request', $id ) ),
			'proposal' => $clip( get_field( 'Proposal', $id ) ),
			'before'   => '',
			'during'   => array(),
			'after'    => '',
		);
		foreach ( (array) get_field( 'Format', $id ) as $row ) {
			if ( ! is_array( $row ) || 'EntryData_Label_Detail' !== ( $row['acf_fc_layout'] ?? '' ) ) {
				continue;
			}
			$group = (array) ( $row['EntryData_Group_Detail'] ?? array() );
			$texts = static function ( array $rows ) use ( $clip ): array {
				$out = array();
				foreach ( $rows as $r ) {
					if ( is_array( $r ) && str_ends_with( (string) ( $r['acf_fc_layout'] ?? '' ), '_SubTitle' ) ) {
						$layout = is_array( $r['EntryData_Detail_Layout'] ?? null ) ? (string) ( $r['EntryData_Detail_Layout']['value'] ?? '' ) : (string) ( $r['EntryData_Detail_Layout'] ?? '' );
						$out[]  = array(
							'heading' => in_array( (int) $layout, array( 1, 2 ), true ) ? $clip( $r['EntryData_Detail_Catch'] ?? '' ) : '',
							'text'    => in_array( (int) $layout, array( 1, 3 ), true ) ? $clip( $r['EntryData_Detail_Text'] ?? '' ) : '',
						);
					}
				}
				return $out;
			};
			$ex['before'] = implode( "\n", array_filter( array_column( $texts( (array) ( $group['EntryData_SectionMae'] ?? array() ) ), 'text' ) ) );
			$ex['after']  = implode( "\n", array_filter( array_column( $texts( (array) ( $group['EntryData_SectionGo'] ?? array() ) ), 'text' ) ) );
			// 施工中：見出しだけの行のあとに来る文章だけの行を、その工程の文章としてまとめる。
			$step = null;
			foreach ( $texts( (array) ( $group['EntryData_SectionChu'] ?? array() ) ) as $t ) {
				if ( '' !== $t['heading'] ) {
					if ( null !== $step ) {
						$ex['during'][] = $step;
					}
					$step = array( 'heading' => $t['heading'], 'text' => $t['text'] );
				} elseif ( '' !== $t['text'] ) {
					$step         = $step ?? array( 'heading' => '', 'text' => '' );
					$step['text'] = trim( $step['text'] . "\n" . $t['text'] );
				}
			}
			if ( null !== $step ) {
				$ex['during'][] = $step;
			}
			break;
		}
		$ex['during'] = array_slice( $ex['during'], 0, 6 );
		$ex['lengths'] = array(
			'catch'    => mb_strlen( $ex['catch'] ),
			'summary'  => mb_strlen( $ex['summary'] ),
			'request'  => mb_strlen( $ex['request'] ),
			'proposal' => mb_strlen( $ex['proposal'] ),
			'before'   => mb_strlen( $ex['before'] ),
			'during'   => array_map( static fn( $s ) => mb_strlen( $s['text'] ), $ex['during'] ),
			'after'    => mb_strlen( $ex['after'] ),
		);
		// 合計 4000 字まで（後ろの工程から削る）。
		while ( count( $ex['during'] ) > 1 && mb_strlen( (string) wp_json_encode( $ex, JSON_UNESCAPED_UNICODE ) ) > 4000 ) {
			array_pop( $ex['during'] );
		}
		if ( '' === $ex['summary'] . $ex['request'] . $ex['proposal'] . $ex['before'] . $ex['after'] ) {
			self::$cache = array();
			return null;
		}
		return self::$cache = $ex;
	}

	public static function flush(): void {
		self::$cache = null;
	}
}
