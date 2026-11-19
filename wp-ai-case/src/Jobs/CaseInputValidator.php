<?php
/**
 * 生成の依頼（契約 case_input、mode=create）の検証と正規化。
 *
 * - 必須の一次情報・写真1枚以上・外部送信への同意がそろわなければ AI-017（外部通信0）。写真一式の説明は任意（2026-11-19 依頼者の判断）。
 * - 記事の方針（article_policy）は任意の文章。施工事例カテゴリは既存のタームだけ。
 * - 写真は自サイトのメディア（画像）だけ。重複不可。編集権限のない画像は使わない。
 * - 文章はタグを除去し、長さを制限する。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Jobs;

use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class CaseInputValidator {

	public const MAX_TEXT   = 2000;
	public const MAX_IMAGES = 200;
	private const FACTS     = array( 'overview', 'client_request', 'work_done', 'area', 'duration', 'cost', 'materials', 'building_type', 'notes', 'building_age', 'parts', 'work_type', 'customer_voice' );
	public const MAX_POLICY = 1000;
	private const REQUIRED  = array( 'overview', 'client_request', 'work_done' );
	private const PHASES    = array( 'before', 'during', 'after', 'unknown' );

	/**
	 * @param array<string,mixed> $in
	 * @return array<string,mixed> 正規化した入力
	 * @throws CaseError MISSING_INPUT（不足：missing にフィールド名）/ INVALID_VALUE（形式・権限）
	 */
	public static function validate( array $in, int $user_id ): array {
		$missing = array();
		$errors  = array();

		$key = (string) ( $in['idempotency_key'] ?? '' );
		if ( ! wp_is_uuid( $key, 4 ) ) {
			$errors[] = 'idempotency_key';
		}
		if ( ( $in['mode'] ?? 'create' ) !== 'create' || ( $in['post_type'] ?? 'works' ) !== 'works' ) {
			$errors[] = 'mode';
		}

		$facts = array();
		foreach ( self::FACTS as $f ) {
			$v = isset( $in['primary_facts'][ $f ] ) && is_string( $in['primary_facts'][ $f ] ) ? self::text( $in['primary_facts'][ $f ] ) : '';
			if ( in_array( $f, self::REQUIRED, true ) && '' === $v ) {
				$missing[] = "primary_facts.{$f}";
			}
			if ( '' !== $v ) {
				$facts[ $f ] = $v;
			}
		}
		$set_desc = is_string( $in['photo_set_description'] ?? null ) ? self::text( $in['photo_set_description'] ) : '';
		$policy   = is_string( $in['article_policy'] ?? null ) ? mb_substr( trim( sanitize_textarea_field( $in['article_policy'] ) ), 0, self::MAX_POLICY ) : '';
		$terms    = self::terms( $in['terms'] ?? array(), $errors );
		if ( true !== ( $in['consent_external_ai'] ?? null ) ) {
			$missing[] = 'consent_external_ai';
		}

		$images = array();
		$seen   = array();
		$list   = is_array( $in['images'] ?? null ) ? array_values( $in['images'] ) : array();
		if ( ! $list ) {
			$missing[] = 'images';
		}
		if ( count( $list ) > self::MAX_IMAGES ) {
			$errors[] = 'images.count';
		}
		foreach ( $list as $i => $img ) {
			$id = is_array( $img ) ? (int) ( $img['attachment_id'] ?? 0 ) : 0;
			if ( $id < 1 || isset( $seen[ $id ] ) ) {
				$errors[] = "images[{$i}].attachment_id";
				continue;
			}
			if ( get_post_type( $id ) !== 'attachment' || ! wp_attachment_is_image( $id ) || ! user_can( $user_id, 'edit_post', $id ) ) {
				$errors[] = "images[{$i}].attachment_id";
				continue;
			}
			$seen[ $id ] = true;
			$hint        = (string) ( $img['user_phase_hint'] ?? 'unknown' );
			$images[]    = array(
				'attachment_id'     => $id,
				'original_position' => count( $images ),
				'caption'           => is_string( $img['caption'] ?? null ) ? mb_substr( sanitize_text_field( $img['caption'] ), 0, 200 ) : '',
				'user_phase_hint'   => in_array( $hint, self::PHASES, true ) ? $hint : 'unknown',
			);
		}

		$rel       = is_array( $in['relations'] ?? null ) ? $in['relations'] : array();
		$relations = array(
			'customer_voice_post_ids' => self::post_ids( $rel['customer_voice_post_ids'] ?? array(), 'voice', $errors, 'relations.customer_voice_post_ids' ),
			'staff'                   => array(),
			'supervisor_post_ids'     => self::post_ids( $rel['supervisor_post_ids'] ?? array(), 'staff', $errors, 'relations.supervisor_post_ids' ),
		);
		foreach ( array_slice( is_array( $rel['staff'] ?? null ) ? $rel['staff'] : array(), 0, 3 ) as $i => $s ) {
			$ids = self::post_ids( array( $s['post_id'] ?? 0 ), 'staff', $errors, "relations.staff[{$i}]" );
			if ( $ids ) {
				$relations['staff'][] = array(
					'post_id' => $ids[0],
					'comment' => is_string( $s['comment'] ?? null ) ? self::text( $s['comment'] ) : '',
				);
			}
		}

		if ( $missing ) {
			throw new CaseError( CaseError::MISSING_INPUT, '必須項目が不足しています', array( 'missing' => $missing ) );
		}
		if ( $errors ) {
			throw new CaseError( CaseError::INVALID_VALUE, '入力の形式が正しくありません', array( 'fields' => array_values( array_unique( $errors ) ) ) );
		}
		return array(
			'client_case_id'        => mb_substr( sanitize_text_field( (string) ( $in['client_case_id'] ?? '' ) ), 0, 120 ),
			'idempotency_key'       => strtolower( $key ),
			'mode'                  => 'create',
			'post_type'             => 'works',
			'primary_facts'         => $facts,
			'photo_set_description' => $set_desc,
			'article_policy'        => $policy,
			'terms'                 => $terms,
			'images'                => $images,
			'consent_external_ai'   => true,
			'relations'             => $relations,
		);
	}

	/**
	 * 施工事例カテゴリ（既存のタームの ID だけ）。
	 *
	 * @param string[] $errors
	 * @return int[]
	 */
	public static function terms( mixed $ids, array &$errors ): array {
		$tax = \WpAiCase\Plugin::category_taxonomy();
		$out = array();
		foreach ( is_array( $ids ) ? array_slice( $ids, 0, 10 ) : array() as $id ) {
			$id = (int) $id;
			if ( $id < 1 ) {
				continue;
			}
			if ( '' === $tax || ! term_exists( $id, $tax ) ) {
				$errors[] = 'terms';
				continue;
			}
			$out[] = $id;
		}
		return array_values( array_unique( $out ) );
	}

	private static function text( string $v ): string {
		return mb_substr( trim( sanitize_textarea_field( $v ) ), 0, self::MAX_TEXT );
	}

	/**
	 * @param string[] $errors
	 * @return int[]
	 */
	private static function post_ids( mixed $ids, string $type, array &$errors, string $path ): array {
		$out = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$id = (int) $id;
			if ( $id < 1 ) {
				continue;
			}
			if ( get_post_type( $id ) !== $type || get_post_status( $id ) !== 'publish' ) {
				$errors[] = $path;
				continue;
			}
			$out[] = $id;
		}
		return array_values( array_unique( $out ) );
	}
}
