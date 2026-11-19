<?php
/**
 * AI の出力と利用者の入力から、CaseDocument（ACF非依存の下書き案）を組み立てる。
 *
 * - 事実（facts）は利用者の入力だけから作る（AI の文章から事実を作らない）。
 * - 写真は入力の集合と AI の解析の集合が完全に一致しなければ止める（不足・重複・架空のID）。
 * - 利用者が指定した工程・キャプションは AI より優先する。
 * - 文章はタグを除去し、長さを制限する。
 * - ご要望・ご提案は、AI が入力だけからまとめた文章を節（node_id request / proposal）として持つ（空なら入力そのものを使う）。
 * - 施工内容の短い一覧（work_type）は、入力がなければ AI の案を「確認が必要な値」（source editor_review）として入れる。
 * - 担当スタッフのコメントは、入力が空のものだけ AI の案で埋める（入力があればそのまま）。お客様の声は入力だけ。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Jobs;

use WpAiCase\Domain\CaseDocumentValidator;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class ResultAssembler {

	private const KINDS = array( 'before', 'during', 'after', 'comparison', 'feature', 'summary' );

	/**
	 * @param array<string,mixed> $input    正規化済みの入力（CaseInputValidator）
	 * @param array<int,array<string,mixed>> $analysis 写真ごとの解析（全写真）
	 * @param array<string,mixed> $article  記事の部分（title/catch/summary/sections/seo）
	 * @return array<string,mixed> 検証済みの CaseDocument
	 */
	public static function build( array $input, array $analysis, array $article, string $job_id ): array {
		$ids = array_map( static fn( $i ) => (int) $i['attachment_id'], $input['images'] );
		$map = array();
		foreach ( $analysis as $a ) {
			$id = is_array( $a ) ? (int) ( $a['attachment_id'] ?? 0 ) : 0;
			if ( ! in_array( $id, $ids, true ) || isset( $map[ $id ] ) ) {
				throw new CaseError( CaseError::INVALID_DOCUMENT, 'AIの結果に、依頼していない写真か重複した写真があります', array( 'reason' => 'image_set_mismatch' ) );
			}
			$map[ $id ] = $a;
		}
		if ( count( $map ) !== count( $ids ) ) {
			throw new CaseError( CaseError::INVALID_DOCUMENT, 'AIの結果で、一部の写真が解析されていません', array( 'reason' => 'image_set_mismatch', 'missing' => array_values( array_diff( $ids, array_keys( $map ) ) ) ) );
		}

		$images = array();
		foreach ( $input['images'] as $img ) {
			$id    = (int) $img['attachment_id'];
			$a     = $map[ $id ];
			$phase = 'unknown' !== $img['user_phase_hint'] ? $img['user_phase_hint'] : ( in_array( $a['phase'] ?? '', array( 'before', 'during', 'after', 'unknown' ), true ) ? $a['phase'] : 'unknown' );
			$rel   = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $a['related_image_ids'] ?? array() ) ), static fn( $r ) => $r !== $id && in_array( $r, $ids, true ) ) ) );
			$images[] = array(
				'attachment_id'     => $id,
				'original_position' => (int) $img['original_position'],
				'phase'             => $phase,
				'description'       => self::text( (string) ( $a['description'] ?? '' ), 500 ),
				'caption'           => '' !== $img['caption'] ? $img['caption'] : self::caption( (string) ( $a['caption'] ?? '' ) ),
				'related_image_ids' => $rel,
				'included'          => true,
				'uncertain'         => (bool) ( $a['uncertain'] ?? false ) && 'unknown' === $img['user_phase_hint'],
			);
		}

		$facts = array();
		foreach ( $input['primary_facts'] as $key => $value ) {
			$facts[ $key ] = array(
				'value'              => $value,
				'status'             => 'provided',
				'source'             => 'user_input',
				'evidence_image_ids' => array(),
			);
		}
		$work_type = self::line( (string) ( $article['work_type'] ?? '' ), 80 );
		if ( ! isset( $facts['work_type'] ) && '' !== $work_type ) {
			$facts['work_type'] = array(
				'value'              => $work_type,
				'status'             => 'provided',
				'source'             => 'editor_review',
				'evidence_image_ids' => array(),
			);
		}

		$sections = array(
			array(
				'node_id'            => 'intro',
				'kind'               => 'introduction',
				'heading'            => self::line( (string) ( $article['catch'] ?? '' ), 120 ),
				'body'               => self::text( (string) ( $article['summary'] ?? '' ), 800 ),
				'image_ids'          => array(),
				'evidence_image_ids' => array(),
			),
		);
		foreach ( array( 'request' => 'request_text', 'proposal' => 'proposal_text' ) as $node => $key ) {
			$body = self::text( (string) ( $article[ $key ] ?? '' ), 2000 );
			if ( '' !== $body ) {
				$sections[] = array(
					'node_id'            => $node,
					'kind'               => 'custom',
					'heading'            => '',
					'body'               => $body,
					'image_ids'          => array(),
					'evidence_image_ids' => array(),
				);
			}
		}
		foreach ( array_values( (array) ( $article['sections'] ?? array() ) ) as $n => $s ) {
			if ( ! is_array( $s ) || ! in_array( $s['kind'] ?? '', self::KINDS, true ) ) {
				continue;
			}
			$sids = array_values( array_unique( array_map( 'intval', (array) ( $s['image_ids'] ?? array() ) ) ) );
			if ( array_diff( $sids, $ids ) ) {
				throw new CaseError( CaseError::INVALID_DOCUMENT, 'AIの結果に、依頼していない写真が使われています', array( 'reason' => 'section_image_unknown' ) );
			}
			$sections[] = array(
				'node_id'            => 's' . ( $n + 1 ),
				'kind'               => $s['kind'],
				'heading'            => in_array( $s['kind'], array( 'before', 'during', 'after' ), true ) ? self::heading( (string) ( $s['heading'] ?? '' ) ) : self::line( (string) ( $s['heading'] ?? '' ), 120 ),
				'body'               => self::text( (string) ( $s['body'] ?? '' ), 1500 ),
				'image_ids'          => $sids,
				'evidence_image_ids' => $sids,
			);
		}

		$seo = array();
		foreach ( array( 'title' => 120, 'description' => 300, 'h1' => 120, 'keywords' => 200 ) as $k => $len ) {
			$v = self::line( (string) ( $article['seo'][ $k ] ?? '' ), $len );
			if ( '' !== $v ) {
				$seo[ $k ] = $v;
			}
		}

		$relations = $input['relations'];
		$drafted   = array();
		foreach ( (array) ( $article['staff_comments'] ?? array() ) as $c ) {
			if ( is_array( $c ) ) {
				$drafted[ (int) ( $c['staff_no'] ?? 0 ) ] = self::text( (string) ( $c['comment'] ?? '' ), 600 );
			}
		}
		// AI には投稿 ID を渡さず、担当スタッフの並び順（1から）で受け渡す。
		foreach ( $relations['staff'] as $i => $staff ) {
			if ( '' === $staff['comment'] && '' !== ( $drafted[ $i + 1 ] ?? '' ) ) {
				$relations['staff'][ $i ]['comment'] = $drafted[ $i + 1 ];
			}
		}

		$title = self::line( (string) ( $article['title'] ?? '' ), 120 );
		$doc   = array(
			'document_id'       => wp_generate_uuid4(),
			'source_request_id' => $job_id,
			'post_type'         => 'works',
			'title'             => '' !== $title ? $title : self::line( (string) $input['primary_facts']['overview'], 120 ),
			'summary'           => self::text( (string) ( $article['summary'] ?? '' ), 800 ),
			'facts'             => $facts,
			'images'            => $images,
			'sections'          => $sections,
			'layout_intent'     => array( 'hero' => 'auto' ),
			'seo'               => $seo,
			'status'            => 'proposal',
			'relations'         => $relations,
		);
		( new CaseDocumentValidator() )->validate( $doc );
		return $doc;
	}

	/**
	 * AI が選んだ施工事例カテゴリのうち、候補に出したものだけ（最大3つ）。
	 *
	 * @param array<string,mixed> $article
	 * @param int[]               $offered
	 * @return int[]
	 */
	public static function categories( array $article, array $offered ): array {
		$ids = array_values( array_unique( array_map( 'intval', (array) ( $article['category_ids'] ?? array() ) ) ) );
		return array_slice( array_values( array_intersect( $ids, $offered ) ), 0, 3 );
	}

	/**
	 * AI・取り込みのキャプション：工程の名前（テーマが見出しとして出す）を除き、残りが「写真」「様子」だけなら空にする。最大40文字。
	 */
	public static function caption( string $v ): string {
		$v = self::strip_labels( self::line( $v, 100 ), \WpAiCase\Display\DisplayNotes::labels( 'photo_caption' ) );
		if ( preg_match( '/^(?:の)?(?:写真|画像|表示画像|様子|状態|状況|全景|イメージ|image|photo)?$/iu', $v ) ) {
			return '';
		}
		if ( preg_match( '/(?:。|ました|ます|でした|です)$/u', $v ) ) {
			return ''; // 文は見出しにしない（本文の文章を写したもの）
		}
		return mb_substr( $v, 0, 40 );
	}

	/**
	 * 施工前・施工中・施工後の小見出し：工程の名前とテーマが出すラベルを前後から除く（残りが空なら空）。
	 */
	public static function heading( string $v ): string {
		return mb_substr( self::strip_labels( self::line( $v, 120 ), \WpAiCase\Display\DisplayNotes::labels( 'section_heading' ) ), 0, 120 );
	}

	/**
	 * 前後にある工程の名前・テーマのラベル（表示メモの theme_labels）を除く。
	 *
	 * @param string[] $labels
	 */
	public static function strip_labels( string $v, array $labels = array() ): string {
		$words = array( '施工前', '施工中', '施工後', '工事前', '工事中', '工事後', '施工完了', '完成', 'ビフォー', 'アフター', 'before', 'after' );
		foreach ( $labels as $l ) {
			$words[] = (string) $l;
		}
		$words = array_values( array_unique( array_filter( $words, static fn( $w ) => '' !== trim( $w ) ) ) );
		usort( $words, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) ); // 長い言葉から
		$alt = implode( '|', array_map( static fn( $w ) => preg_quote( $w, '/' ), $words ) );
		$sep = '[\s　の：:・\-－―／\/|｜（）()\[\]【】「」]*';
		for ( $i = 0; $i < 3; $i++ ) {
			$before = $v;
			$v      = (string) preg_replace( '/^' . $sep . '(?:' . $alt . ')' . $sep . '/iu', '', $v );
			$v      = (string) preg_replace( '/' . $sep . '(?:' . $alt . ')' . $sep . '$/iu', '', $v );
			if ( $before === $v ) {
				break;
			}
		}
		return (string) preg_replace( '/^[\s　・：:／\/|｜\-]+|[\s　・：:／\/|｜\-]+$/u', '', $v ); // trim() は文字単位でないので使わない
	}

	public static function line( string $v, int $max ): string {
		return mb_substr( trim( sanitize_text_field( $v ) ), 0, $max );
	}

	public static function text( string $v, int $max ): string {
		return mb_substr( trim( sanitize_textarea_field( $v ) ), 0, $max );
	}
}
