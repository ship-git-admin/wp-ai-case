<?php
/**
 * AI の出力と利用者の入力から、CaseDocument（ACF非依存の下書き案）を組み立てる。
 *
 * - 事実（facts）は利用者の入力だけから作る（AI の文章から事実を作らない）。
 * - 写真は入力の集合と AI の解析の集合が完全に一致しなければ止める（不足・重複・架空のID）。
 * - 利用者が指定した工程・キャプションは AI より優先する。
 * - 文章はタグを除去し、長さを制限する。
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
				'caption'           => '' !== $img['caption'] ? $img['caption'] : self::line( (string) ( $a['caption'] ?? '' ), 100 ),
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
				'heading'            => self::line( (string) ( $s['heading'] ?? '' ), 120 ),
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
			'relations'         => $input['relations'],
		);
		( new CaseDocumentValidator() )->validate( $doc );
		return $doc;
	}

	public static function line( string $v, int $max ): string {
		return mb_substr( trim( sanitize_text_field( $v ) ), 0, $max );
	}

	public static function text( string $v, int $max ): string {
		return mb_substr( trim( sanitize_textarea_field( $v ) ), 0, $max );
	}
}
