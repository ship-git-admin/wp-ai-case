<?php
/**
 * CaseDocument（ACF非依存の施工事例データ）の検証。
 *
 * contracts/case_document.schema.json の構造に加え、Schema では表せない不変条件を PHP で確認する。
 * - 画像 attachment_id の重複なし、関連・根拠IDが画像集合に含まれる
 * - セクションが参照する画像は「含める」画像に限る
 * - 工期・費用は画像からの推測を認めない（捏造防止）
 * - 関連付け（お客様の声・担当スタッフ・監修者）は型・重複を確認する。空（紐付けなし）でもよい
 *
 * WordPress の投稿・ACF 定義との照合（存在・投稿タイプ・公開状態・min/max）は Acf\FieldAdapterRegistry が行う。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Domain;

use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class CaseDocumentValidator {

	private const TOP_KEYS      = array( 'document_id', 'source_request_id', 'post_type', 'title', 'summary', 'facts', 'images', 'sections', 'layout_intent', 'seo', 'status', 'relations' );
	private const REQUIRED_TOP  = array( 'document_id', 'source_request_id', 'post_type', 'title', 'summary', 'facts', 'images', 'sections', 'layout_intent', 'status', 'relations' );
	private const FACT_KEYS     = array( 'overview', 'client_request', 'work_done', 'area', 'duration', 'cost', 'materials', 'building_type', 'notes', 'floor_plan', 'site_area', 'building_age', 'parts', 'work_type', 'customer_voice' );
	private const FACT_STATUS   = array( 'provided', 'inferred_from_image', 'unknown' );
	private const FACT_SOURCE   = array( 'user_input', 'photo', 'editor_review', 'unknown' );
	private const NO_INFERENCE  = array( 'cost', 'duration', 'building_age', 'customer_voice' );
	private const PHASES        = array( 'before', 'during', 'after', 'unknown' );
	private const SECTION_KINDS = array( 'introduction', 'before', 'during', 'after', 'comparison', 'feature', 'summary', 'custom' );
	private const HERO          = array( 'single', 'gallery', 'before_after', 'auto' );
	private const SEO_KEYS      = array( 'title', 'description', 'h1', 'keywords' );

	/** @var string[] */
	private array $errors = array();

	/**
	 * @param array<string,mixed> $doc
	 * @throws CaseError 検証に失敗したとき（INVALID_DOCUMENT）。
	 */
	public function validate( array $doc ): void {
		$this->errors = array();

		$this->only_keys( $doc, self::TOP_KEYS, '$' );
		foreach ( self::REQUIRED_TOP as $key ) {
			if ( ! array_key_exists( $key, $doc ) ) {
				$this->errors[] = "\$.{$key} がありません";
			}
		}

		$this->uuid( $doc['document_id'] ?? null, '$.document_id' );
		$this->uuid( $doc['source_request_id'] ?? null, '$.source_request_id' );
		if ( ( $doc['post_type'] ?? null ) !== 'works' ) {
			$this->errors[] = '$.post_type は works のみ';
		}
		if ( ! is_string( $doc['title'] ?? null ) || '' === trim( $doc['title'] ) ) {
			$this->errors[] = '$.title は空にできません';
		}
		if ( array_key_exists( 'summary', $doc ) && ! is_string( $doc['summary'] ) ) {
			$this->errors[] = '$.summary は文字列';
		}
		if ( ( $doc['status'] ?? null ) !== 'proposal' ) {
			$this->errors[] = '$.status は proposal のみ';
		}

		$image_ids    = $this->images( $doc['images'] ?? null );
		$included_ids = array();
		foreach ( (array) ( $doc['images'] ?? array() ) as $image ) {
			if ( is_array( $image ) && ! empty( $image['included'] ) && is_int( $image['attachment_id'] ?? null ) ) {
				$included_ids[] = $image['attachment_id'];
			}
		}

		$this->facts( $doc['facts'] ?? null, $image_ids );
		$this->sections( $doc['sections'] ?? null, $image_ids, $included_ids );
		$this->layout_intent( $doc['layout_intent'] ?? null );
		if ( array_key_exists( 'seo', $doc ) ) {
			$this->seo( $doc['seo'] );
		}
		if ( array_key_exists( 'relations', $doc ) ) {
			$this->relations( $doc['relations'] );
		}

		if ( $this->errors ) {
			throw new CaseError(
				CaseError::INVALID_DOCUMENT,
				'CaseDocument の検証に失敗しました',
				array( 'errors' => $this->errors )
			);
		}
	}

	/**
	 * @return int[] 画像IDの一覧
	 */
	private function images( mixed $images ): array {
		if ( ! is_array( $images ) || ! array_is_list( $images ) || count( $images ) < 1 ) {
			$this->errors[] = '$.images は1件以上の配列';
			return array();
		}
		$ids       = array();
		$positions = array();
		foreach ( $images as $i => $image ) {
			$path = "\$.images[{$i}]";
			if ( ! is_array( $image ) ) {
				$this->errors[] = "{$path} はオブジェクト";
				continue;
			}
			$this->only_keys( $image, array( 'attachment_id', 'original_position', 'phase', 'description', 'caption', 'related_image_ids', 'included', 'uncertain' ), $path );
			$id = $image['attachment_id'] ?? null;
			if ( ! is_int( $id ) || $id < 1 ) {
				$this->errors[] = "{$path}.attachment_id は1以上の整数";
			} elseif ( in_array( $id, $ids, true ) ) {
				$this->errors[] = "{$path}.attachment_id {$id} が重複しています";
			} else {
				$ids[] = $id;
			}
			$pos = $image['original_position'] ?? null;
			if ( ! is_int( $pos ) || $pos < 0 ) {
				$this->errors[] = "{$path}.original_position は0以上の整数";
			} elseif ( in_array( $pos, $positions, true ) ) {
				$this->errors[] = "{$path}.original_position {$pos} が重複しています";
			} else {
				$positions[] = $pos;
			}
			if ( ! in_array( $image['phase'] ?? null, self::PHASES, true ) ) {
				$this->errors[] = "{$path}.phase が不正";
			}
			if ( ! is_string( $image['description'] ?? null ) ) {
				$this->errors[] = "{$path}.description は文字列";
			}
			if ( array_key_exists( 'caption', $image ) && ! is_string( $image['caption'] ) ) {
				$this->errors[] = "{$path}.caption は文字列";
			}
			foreach ( array( 'included', 'uncertain' ) as $flag ) {
				if ( ! is_bool( $image[ $flag ] ?? null ) ) {
					$this->errors[] = "{$path}.{$flag} は真偽値";
				}
			}
			$this->int_list( $image['related_image_ids'] ?? null, "{$path}.related_image_ids" );
		}
		// 関連画像IDは画像集合の中だけ。自分自身は不可。
		foreach ( $images as $i => $image ) {
			foreach ( (array) ( $image['related_image_ids'] ?? array() ) as $rel ) {
				if ( ! in_array( $rel, $ids, true ) || $rel === ( $image['attachment_id'] ?? null ) ) {
					$this->errors[] = "\$.images[{$i}].related_image_ids に不正なID {$rel}";
				}
			}
		}
		return $ids;
	}

	/**
	 * @param int[] $image_ids
	 */
	private function facts( mixed $facts, array $image_ids ): void {
		if ( ! is_array( $facts ) || array_is_list( $facts ) || count( $facts ) < 3 ) {
			$this->errors[] = '$.facts は3項目以上のオブジェクト';
			return;
		}
		foreach ( $facts as $key => $fact ) {
			$path = "\$.facts.{$key}";
			if ( ! in_array( $key, self::FACT_KEYS, true ) ) {
				$this->errors[] = "{$path} は未定義の項目";
				continue;
			}
			if ( ! is_array( $fact ) ) {
				$this->errors[] = "{$path} はオブジェクト";
				continue;
			}
			$this->only_keys( $fact, array( 'value', 'status', 'source', 'evidence_image_ids' ), $path );
			if ( ! array_key_exists( 'value', $fact ) || ! ( is_string( $fact['value'] ) || null === $fact['value'] ) ) {
				$this->errors[] = "{$path}.value は文字列またはnull";
			}
			if ( ! in_array( $fact['status'] ?? null, self::FACT_STATUS, true ) ) {
				$this->errors[] = "{$path}.status が不正";
			}
			if ( ! in_array( $fact['source'] ?? null, self::FACT_SOURCE, true ) ) {
				$this->errors[] = "{$path}.source が不正";
			}
			$this->int_list( $fact['evidence_image_ids'] ?? null, "{$path}.evidence_image_ids" );
			foreach ( (array) ( $fact['evidence_image_ids'] ?? array() ) as $ev ) {
				if ( ! in_array( $ev, $image_ids, true ) ) {
					$this->errors[] = "{$path}.evidence_image_ids に存在しない画像ID {$ev}";
				}
			}
			if ( in_array( $key, self::NO_INFERENCE, true ) && ( 'inferred_from_image' === ( $fact['status'] ?? null ) || 'photo' === ( $fact['source'] ?? null ) ) ) {
				$this->errors[] = "{$path} は画像から推測できません（入力された値のみ）";
			}
		}
	}

	/**
	 * @param int[] $image_ids
	 * @param int[] $included_ids
	 */
	private function sections( mixed $sections, array $image_ids, array $included_ids ): void {
		if ( ! is_array( $sections ) || ! array_is_list( $sections ) ) {
			$this->errors[] = '$.sections は配列';
			return;
		}
		$node_ids = array();
		foreach ( $sections as $i => $section ) {
			$path = "\$.sections[{$i}]";
			if ( ! is_array( $section ) ) {
				$this->errors[] = "{$path} はオブジェクト";
				continue;
			}
			$this->only_keys( $section, array( 'node_id', 'kind', 'heading', 'body', 'image_ids', 'evidence_image_ids' ), $path );
			$node = $section['node_id'] ?? null;
			if ( ! is_string( $node ) || '' === $node ) {
				$this->errors[] = "{$path}.node_id は空にできません";
			} elseif ( in_array( $node, $node_ids, true ) ) {
				$this->errors[] = "{$path}.node_id {$node} が重複しています";
			} else {
				$node_ids[] = $node;
			}
			if ( ! in_array( $section['kind'] ?? null, self::SECTION_KINDS, true ) ) {
				$this->errors[] = "{$path}.kind が不正";
			}
			foreach ( array( 'heading', 'body' ) as $text ) {
				if ( ! is_string( $section[ $text ] ?? null ) ) {
					$this->errors[] = "{$path}.{$text} は文字列";
				}
			}
			$this->int_list( $section['image_ids'] ?? null, "{$path}.image_ids" );
			$this->int_list( $section['evidence_image_ids'] ?? null, "{$path}.evidence_image_ids" );
			foreach ( (array) ( $section['image_ids'] ?? array() ) as $id ) {
				if ( ! in_array( $id, $included_ids, true ) ) {
					$this->errors[] = "{$path}.image_ids の {$id} は含める画像ではありません";
				}
			}
			foreach ( (array) ( $section['evidence_image_ids'] ?? array() ) as $id ) {
				if ( ! in_array( $id, $image_ids, true ) ) {
					$this->errors[] = "{$path}.evidence_image_ids に存在しない画像ID {$id}";
				}
			}
		}
	}

	private function layout_intent( mixed $intent ): void {
		if ( ! is_array( $intent ) ) {
			$this->errors[] = '$.layout_intent はオブジェクト';
			return;
		}
		$this->only_keys( $intent, array( 'hero', 'notes' ), '$.layout_intent' );
		if ( ! in_array( $intent['hero'] ?? null, self::HERO, true ) ) {
			$this->errors[] = '$.layout_intent.hero が不正';
		}
		if ( array_key_exists( 'notes', $intent ) && ! is_string( $intent['notes'] ) ) {
			$this->errors[] = '$.layout_intent.notes は文字列';
		}
	}

	private function seo( mixed $seo ): void {
		if ( ! is_array( $seo ) || ( $seo && array_is_list( $seo ) ) ) {
			$this->errors[] = '$.seo はオブジェクト';
			return;
		}
		$this->only_keys( $seo, self::SEO_KEYS, '$.seo' );
		foreach ( $seo as $key => $value ) {
			if ( ! is_string( $value ) ) {
				$this->errors[] = "\$.seo.{$key} は文字列";
			}
		}
	}

	private function relations( mixed $rel ): void {
		if ( ! is_array( $rel ) || ( $rel && array_is_list( $rel ) ) ) {
			$this->errors[] = '$.relations はオブジェクト';
			return;
		}
		$this->only_keys( $rel, array( 'customer_voice_post_ids', 'staff', 'supervisor_post_ids' ), '$.relations' );
		// 空配列は「紐付けなし」。下書きは作り、該当フィールドには書き込まない。
		foreach ( array( 'customer_voice_post_ids', 'supervisor_post_ids' ) as $key ) {
			$this->int_list( $rel[ $key ] ?? null, "\$.relations.{$key}" );
		}
		$staff = $rel['staff'] ?? null;
		if ( ! is_array( $staff ) || ! array_is_list( $staff ) ) {
			$this->errors[] = '$.relations.staff は配列';
		} else {
			$seen = array();
			foreach ( $staff as $i => $row ) {
				$path = "\$.relations.staff[{$i}]";
				if ( ! is_array( $row ) ) {
					$this->errors[] = "{$path} はオブジェクト";
					continue;
				}
				$this->only_keys( $row, array( 'post_id', 'comment' ), $path );
				$pid = $row['post_id'] ?? null;
				if ( ! is_int( $pid ) || $pid < 1 ) {
					$this->errors[] = "{$path}.post_id は1以上の整数";
				} elseif ( in_array( $pid, $seen, true ) ) {
					$this->errors[] = "{$path}.post_id {$pid} が重複しています";
				} else {
					$seen[] = $pid;
				}
				if ( ! is_string( $row['comment'] ?? null ) ) {
					$this->errors[] = "{$path}.comment は文字列";
				}
			}
		}
	}

	private function int_list( mixed $list, string $path ): void {
		if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
			$this->errors[] = "{$path} は整数の配列";
			return;
		}
		$seen = array();
		foreach ( $list as $v ) {
			if ( ! is_int( $v ) || $v < 1 ) {
				$this->errors[] = "{$path} に1以上の整数以外が含まれます";
				return;
			}
			if ( in_array( $v, $seen, true ) ) {
				$this->errors[] = "{$path} に重複 {$v}";
				return;
			}
			$seen[] = $v;
		}
	}

	/**
	 * @param array<string,mixed> $obj
	 * @param string[]            $allowed
	 */
	private function only_keys( array $obj, array $allowed, string $path ): void {
		foreach ( array_keys( $obj ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				$this->errors[] = "{$path}.{$key} は定義外の項目です";
			}
		}
	}

	private function uuid( mixed $value, string $path ): void {
		if ( ! is_string( $value ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) ) {
			$this->errors[] = "{$path} はUUID";
		}
	}
}
