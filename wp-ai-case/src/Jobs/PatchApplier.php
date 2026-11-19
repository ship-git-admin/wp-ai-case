<?php
/**
 * 型付きの修正操作（契約 revision_patch の operations）を CaseDocument に適用する。
 *
 * - 操作の section は allowed_sections に含まれていなければならない（「すべて」は画面側で展開済みのものだけ）。
 * - node_id・attachment_id は現在の下書きにあるものだけ。自由な ACF キー・PHP・URL は受け付けない。
 * - AI からの提案は、AI に許した操作の種類だけ（事実の変更・写真の順番や表示の切り替えは画面操作だけ）。
 * - 適用後は CaseDocument の検証を通す。1つでも不正なら何も変えない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Jobs;

use WpAiCase\Domain\CaseDocumentValidator;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class PatchApplier {

	public const SECTIONS = array( 'title', 'body', 'concept', 'client_request', 'proposal', 'summary', 'facts', 'photos', 'before_after', 'layout', 'seo', 'staff' );

	/** 操作 => 許す section */
	private const OPS = array(
		'text_set'             => array( 'title', 'concept', 'client_request', 'proposal', 'summary' ),
		'body_section_set'     => array( 'body' ),
		'seo_set'              => array( 'seo' ),
		'fact_set'             => array( 'facts' ),
		'image_order_set'      => array( 'photos' ),
		'image_visibility_set' => array( 'photos' ),
		'image_caption_set'    => array( 'photos' ),
		'image_phase_set'      => array( 'before_after' ),
		'image_relation_set'   => array( 'before_after' ),
		'layout_set'           => array( 'layout' ),
		'staff_comment_set'    => array( 'staff' ),
	);

	private const AI_OPS    = array( 'text_set', 'body_section_set', 'seo_set', 'image_caption_set', 'image_phase_set', 'layout_set', 'staff_comment_set' );
	private const FACT_KEYS = array( 'overview', 'client_request', 'work_done', 'area', 'duration', 'cost', 'materials', 'building_type', 'notes', 'floor_plan', 'site_area', 'building_age', 'parts', 'work_type', 'customer_voice' );
	/** 記事の文（お客様のご要望・ご提案）を入れるセクション。入力の事実（facts）とは別に持つ。 */
	public const PROSE_NODES = array( 'client_request' => 'request', 'proposal' => 'proposal' );

	/**
	 * @param array<string,mixed>            $doc
	 * @param string[]                       $allowed
	 * @param array<int,array<string,mixed>> $operations
	 * @return array{doc:array<string,mixed>,changed:string[]}
	 */
	public static function apply( array $doc, array $allowed, array $operations, bool $from_ai ): array {
		$allowed = array_values( array_unique( array_map( 'strval', $allowed ) ) );
		if ( ! $allowed || array_diff( $allowed, self::SECTIONS ) ) {
			throw new CaseError( CaseError::INVALID_VALUE, '修正する部分の指定が正しくありません（「すべて」は個別の部分に展開してください）', array( 'reason' => 'allowed_sections' ) );
		}
		if ( ! array_is_list( $operations ) || count( $operations ) > 100 ) {
			throw new CaseError( CaseError::INVALID_VALUE, '修正の操作が正しくありません', array( 'reason' => 'operations' ) );
		}
		$ids     = array_map( static fn( $i ) => (int) $i['attachment_id'], $doc['images'] );
		$changed = array();

		foreach ( $operations as $i => $op ) {
			$name    = is_array( $op ) ? (string) ( $op['op'] ?? '' ) : '';
			$section = is_array( $op ) ? (string) ( $op['section'] ?? '' ) : '';
			$where   = array( 'reason' => 'operation', 'count' => $i );
			if ( ! isset( self::OPS[ $name ] ) || ! in_array( $section, self::OPS[ $name ], true ) ) {
				throw new CaseError( CaseError::INVALID_VALUE, '許可されていない修正操作です', $where );
			}
			if ( ! in_array( $section, $allowed, true ) ) {
				throw new CaseError( CaseError::INVALID_VALUE, '選んでいない部分への変更が含まれています', $where );
			}
			if ( $from_ai && ! in_array( $name, self::AI_OPS, true ) ) {
				throw new CaseError( CaseError::INVALID_VALUE, 'AIからは受け付けない修正操作です', $where );
			}
			switch ( $name ) {
				case 'text_set':
					$value = (string) ( $op['value'] ?? '' );
					switch ( $section ) {
						case 'title':
							$doc['title'] = self::need( ResultAssembler::line( $value, 120 ), $where );
							break;
						case 'concept':
							$doc['sections'][ self::node( $doc, 'intro' ) ]['heading'] = ResultAssembler::line( $value, 120 );
							break;
						case 'summary':
							$doc['summary']                                       = ResultAssembler::text( $value, 800 );
							$doc['sections'][ self::node( $doc, 'intro' ) ]['body'] = $doc['summary'];
							break;
						case 'client_request':
						case 'proposal':
							// 記事の文があればそれを直す（入力の事実はそのまま残す）。なければ入力の事実を直す（以前の下書き）。
							$prose = self::find( $doc, self::PROSE_NODES[ $section ] );
							if ( null !== $prose ) {
								$doc['sections'][ $prose ]['body'] = self::need( ResultAssembler::text( $value, 2000 ), $where );
							} else {
								$key                            = 'client_request' === $section ? 'client_request' : 'work_done';
								$doc['facts'][ $key ]['value']  = self::need( ResultAssembler::text( $value, 2000 ), $where );
								$doc['facts'][ $key ]['source'] = $from_ai ? 'editor_review' : 'user_input';
							}
							break;
					}
					$changed[] = $section;
					break;

				case 'body_section_set':
					$n = self::node( $doc, (string) ( $op['node_id'] ?? '' ) );
					if ( 'intro' === $doc['sections'][ $n ]['node_id'] ) {
						throw new CaseError( CaseError::INVALID_VALUE, '導入文は concept / summary で変更してください', $where );
					}
					if ( in_array( $doc['sections'][ $n ]['node_id'], self::PROSE_NODES, true ) ) {
						throw new CaseError( CaseError::INVALID_VALUE, 'お客様のご要望・ご提案は client_request / proposal で変更してください', $where );
					}
					$phase_kind                       = in_array( $doc['sections'][ $n ]['kind'], array( 'before', 'during', 'after' ), true );
					$doc['sections'][ $n ]['heading'] = $from_ai && $phase_kind ? ResultAssembler::heading( (string) ( $op['heading'] ?? '' ) ) : ResultAssembler::line( (string) ( $op['heading'] ?? '' ), 120 );
					$doc['sections'][ $n ]['body']    = ResultAssembler::text( (string) ( $op['body'] ?? '' ), 1500 );
					$changed[]                        = 'body:' . $doc['sections'][ $n ]['node_id'];
					break;

				case 'seo_set':
					$field = (string) ( $op['field'] ?? '' );
					if ( ! in_array( $field, array( 'title', 'description', 'h1', 'keywords' ), true ) ) {
						throw new CaseError( CaseError::INVALID_VALUE, 'SEOの項目が正しくありません', $where );
					}
					$doc['seo'][ $field ] = ResultAssembler::line( (string) ( $op['value'] ?? '' ), 300 );
					$changed[]            = 'seo:' . $field;
					break;

				case 'fact_set':
					$key = (string) ( $op['fact_key'] ?? '' );
					$src = (string) ( $op['source'] ?? '' );
					if ( ! in_array( $key, self::FACT_KEYS, true ) || ! in_array( $src, array( 'user_input', 'editor_review', 'unknown' ), true ) ) {
						throw new CaseError( CaseError::INVALID_VALUE, '事実の項目が正しくありません', $where );
					}
					$value = $op['value'] ?? null;
					if ( null === $value || '' === trim( (string) $value ) ) {
						if ( in_array( $key, array( 'overview', 'client_request', 'work_done' ), true ) ) {
							throw new CaseError( CaseError::INVALID_VALUE, '必須の一次情報は空にできません', $where );
						}
						unset( $doc['facts'][ $key ] );
					} else {
						$doc['facts'][ $key ] = array(
							'value'              => ResultAssembler::text( (string) $value, 2000 ),
							'status'             => 'unknown' === $src ? 'unknown' : 'provided',
							'source'             => $src,
							'evidence_image_ids' => array(),
						);
					}
					$changed[] = 'facts:' . $key;
					break;

				case 'image_order_set':
					$order = array_map( 'intval', (array) ( $op['attachment_ids'] ?? array() ) );
					$a     = $order;
					$b     = $ids;
					sort( $a );
					sort( $b );
					if ( $a !== $b || count( array_unique( $order ) ) !== count( $order ) ) {
						throw new CaseError( CaseError::INVALID_VALUE, '写真の並びには全部の写真を1回ずつ指定してください', $where );
					}
					$by = array();
					foreach ( $doc['images'] as $img ) {
						$by[ (int) $img['attachment_id'] ] = $img;
					}
					$doc['images'] = array();
					foreach ( $order as $pos => $id ) {
						$by[ $id ]['original_position'] = $pos;
						$doc['images'][]                = $by[ $id ];
					}
					$ids       = $order;
					$changed[] = 'photos:order';
					break;

				case 'image_visibility_set':
				case 'image_caption_set':
				case 'image_phase_set':
				case 'image_relation_set':
					$id = (int) ( $op['attachment_id'] ?? 0 );
					$k  = array_search( $id, $ids, true );
					if ( false === $k ) {
						throw new CaseError( CaseError::INVALID_VALUE, '下書きにない写真です', $where );
					}
					$k = self::image_index( $doc, $id );
					if ( 'image_visibility_set' === $name ) {
						$doc['images'][ $k ]['included'] = (bool) ( $op['included'] ?? true );
						if ( ! $doc['images'][ $k ]['included'] ) {
							foreach ( $doc['sections'] as $si => $s ) {
								$doc['sections'][ $si ]['image_ids'] = array_values( array_diff( $s['image_ids'], array( $id ) ) );
							}
						}
					} elseif ( 'image_caption_set' === $name ) {
						$doc['images'][ $k ]['caption'] = $from_ai ? ResultAssembler::caption( (string) ( $op['caption'] ?? '' ) ) : ResultAssembler::line( (string) ( $op['caption'] ?? '' ), 100 );
					} elseif ( 'image_phase_set' === $name ) {
						$phase = (string) ( $op['phase'] ?? '' );
						if ( ! in_array( $phase, array( 'before', 'during', 'after', 'unknown' ), true ) ) {
							throw new CaseError( CaseError::INVALID_VALUE, '工程の指定が正しくありません', $where );
						}
						$doc['images'][ $k ]['phase']     = $phase;
						$doc['images'][ $k ]['uncertain'] = false;
					} else {
						$rel = array_values( array_unique( array_map( 'intval', (array) ( $op['related_image_ids'] ?? array() ) ) ) );
						if ( array_diff( $rel, $ids ) || in_array( $id, $rel, true ) ) {
							throw new CaseError( CaseError::INVALID_VALUE, '関連する写真の指定が正しくありません', $where );
						}
						$doc['images'][ $k ]['related_image_ids'] = $rel;
					}
					$changed[] = 'photos:' . $id;
					break;

				case 'staff_comment_set':
					$pid   = (int) ( $op['post_id'] ?? 0 );
					$found = false;
					foreach ( $doc['relations']['staff'] as $si => $staff ) {
						if ( (int) $staff['post_id'] === $pid ) {
							$doc['relations']['staff'][ $si ]['comment'] = ResultAssembler::text( (string) ( $op['comment'] ?? '' ), 600 );
							$found = true;
						}
					}
					if ( ! $found ) {
						throw new CaseError( CaseError::INVALID_VALUE, '下書きにない担当スタッフです', $where );
					}
					$changed[] = 'staff:' . $pid;
					break;

				case 'layout_set':
					$hero = (string) ( $op['hero'] ?? '' );
					if ( ! in_array( $hero, array( 'single', 'gallery', 'before_after', 'auto' ), true ) ) {
						throw new CaseError( CaseError::INVALID_VALUE, 'レイアウトの指定が正しくありません', $where );
					}
					$doc['layout_intent']['hero'] = $hero;
					$changed[]                    = 'layout';
					break;
			}
		}

		if ( ! array_filter( $doc['images'], static fn( $i ) => ! empty( $i['included'] ) ) ) {
			throw new CaseError( CaseError::INVALID_VALUE, '記事に含める写真が1枚もなくなります', array( 'reason' => 'no_images' ) );
		}
		( new CaseDocumentValidator() )->validate( $doc );
		return array(
			'doc'     => $doc,
			'changed' => array_values( array_unique( $changed ) ),
		);
	}

	/**
	 * @param array<string,mixed> $doc
	 */
	private static function node( array $doc, string $node_id ): int {
		foreach ( $doc['sections'] as $i => $s ) {
			if ( $s['node_id'] === $node_id ) {
				return $i;
			}
		}
		throw new CaseError( CaseError::INVALID_VALUE, '下書きにない部分です', array( 'reason' => 'unknown_node' ) );
	}

	/**
	 * @param array<string,mixed> $doc
	 */
	public static function find( array $doc, string $node_id ): ?int {
		foreach ( $doc['sections'] as $i => $s ) {
			if ( $s['node_id'] === $node_id ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $doc
	 */
	private static function image_index( array $doc, int $id ): int {
		foreach ( $doc['images'] as $i => $img ) {
			if ( (int) $img['attachment_id'] === $id ) {
				return $i;
			}
		}
		throw new CaseError( CaseError::INVALID_VALUE, '下書きにない写真です', array( 'reason' => 'unknown_image' ) );
	}

	/**
	 * @param array<string,mixed> $where
	 */
	private static function need( string $v, array $where ): string {
		if ( '' === $v ) {
			throw new CaseError( CaseError::INVALID_VALUE, '空にできない項目です', $where );
		}
		return $v;
	}
}
