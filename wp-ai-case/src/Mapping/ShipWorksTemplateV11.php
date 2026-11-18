<?php
/**
 * 共通テンプレート「施工事例 ver1.1」向けの既知マッピング（AIを使わない固定ルール）。
 *
 * CaseDocument（ACF非依存）から「フィールド名で書いた値ツリー」を作る。
 * - ここではフィールド名・レイアウト名だけを使う。field key はサイトごとに違ってよいので、
 *   保存時に FieldAdapterRegistry が現在のACF定義から解決する。
 * - 同じテンプレート名でも構造が違えば、保存時の照合で MAPPING_MISMATCH として止まる。
 * - 事実（工期・費用など）は入力された値（status=provided）だけを使い、推測で埋めない。
 * - 画像は「含める」ものをすべて、工程（before/during/after/unknown）ごとのユニットに入れる。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Mapping;

use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class ShipWorksTemplateV11 {

	public const TEMPLATE_ID      = 'ship-works';
	public const TEMPLATE_VERSION = '1.1';

	/** CaseDocument の facts → Outline のレイアウトと子フィールド（並び順もこの順）。 */
	private const OUTLINE = array(
		'area'          => array( 'Outline_Label_Area', 'Outline_Area' ),
		'building_type' => array( 'Outline_Label_Building-classification', 'Outline_Building-classification' ),
		'overview'      => array( 'Outline_Label_Content', 'Outline_Content' ),
		'duration'      => array( 'Outline_Label_Period', 'Outline_Period' ),
		'cost'          => array( 'Outline_Label_Cost', 'Outline_Cost' ),
		'floor_plan'    => array( 'Outline_Label_Floor-plan', 'Outline_Floor-plan' ),
		'site_area'     => array( 'Outline_Label_Site', 'Outline_Site' ),
	);

	/** 工程 → 施工前/中/後セクションの名前の一部。 */
	private const PHASE_SECTION = array(
		'before' => 'Mae',
		'during' => 'Chu',
		'after'  => 'Go',
	);

	private const HERO_LAYOUT = array(
		'single'       => 'layout01',
		'gallery'      => 'layout02',
		'before_after' => 'layout03',
	);

	/** 写真ユニットの表示形式（type-b = ラージ/PC2カラム）。 */
	private const UNIT_LAYOUT = 'type-b';

	/**
	 * @param array<string,mixed> $doc     検証済みの CaseDocument
	 * @param array<string,mixed> $options hero: single|gallery|before_after を強制する場合
	 * @return array{post:array<string,string>,fields:array<string,mixed>,hero:string,image_ids:int[]}
	 */
	public function build( array $doc, array $options = array() ): array {
		$images   = $this->included_images( $doc['images'] );
		$by_phase = array(
			'before'  => array(),
			'during'  => array(),
			'after'   => array(),
			'unknown' => array(),
		);
		foreach ( $images as $image ) {
			$by_phase[ $image['phase'] ][] = $image;
		}

		$hero   = $this->hero( (string) ( $options['hero'] ?? $doc['layout_intent']['hero'] ), $by_phase, $images );
		$fields = array( 'FV_Layout' => self::HERO_LAYOUT[ $hero ] );

		if ( 'single' === $hero ) {
			$main                         = $by_phase['after'][0] ?? $images[0];
			$fields['EntryData_CatchImg'] = array(
				'EntryData_CatchImg02'      => $main['attachment_id'],
				'EntryData_CatchImg02_Trim' => false,
			);
		} elseif ( 'gallery' === $hero ) {
			$rows = array();
			foreach ( $images as $image ) {
				$rows[] = array(
					'FV_Layout_Gallery_img'  => $image['attachment_id'],
					'FV_Layout_Gallery_alt'  => (string) ( $image['caption'] ?? '' ),
					'FV_Layout_Gallery_Trim' => false,
				);
			}
			$fields['FV_Layout_Gallery'] = $rows;
		} else {
			$fields['FV_Layout_BeforeAfter'] = array(
				'FV_Layout_BeforeAfter_Image_Before' => $by_phase['before'][0]['attachment_id'],
				'FV_Layout_BeforeAfter_Image_After'  => $by_phase['after'][0]['attachment_id'],
				'FV_Layout_BeforeAfter_BeforeTrim'   => false,
				'FV_Layout_BeforeAfter_AfterTrim'    => false,
			);
		}

		$intro                            = $this->first_section( $doc['sections'], 'introduction' );
		$fields['EntryData_CatchTxt']     = $intro ? $intro['heading'] : (string) $doc['title'];
		$fields['EntryData_Description']  = $intro ? $intro['body'] : (string) $doc['summary'];

		$request = $this->fact( $doc, 'client_request' );
		if ( null !== $request ) {
			$fields['Request'] = $this->paragraphs( $request );
		}
		$proposal = $this->fact( $doc, 'work_done' );
		if ( null !== $proposal ) {
			$fields['Proposal'] = $this->paragraphs( $proposal );
		}
		$materials = $this->fact( $doc, 'materials' );
		if ( null !== $materials ) {
			$fields['EntryData_Commodity'] = $materials;
		}

		$outline = array();
		foreach ( self::OUTLINE as $fact_key => [ $layout, $sub ] ) {
			$value = $this->fact( $doc, $fact_key );
			if ( null !== $value ) {
				$outline[] = array(
					'acf_fc_layout' => $layout,
					$sub            => $value,
				);
			}
		}
		if ( $outline ) {
			$fields['Outline'] = $outline;
		}

		$fields['Format'] = $this->format( $doc['sections'], $by_phase );

		$rel                                = $doc['relations'];
		$fields['EntryData_RelatedData']    = array_values( $rel['customer_voice_post_ids'] );
		$fields['entrydata_supervision']    = array_values( $rel['supervisor_post_ids'] );
		$fields['EntryData_Staff']          = array();
		foreach ( $rel['staff'] as $staff ) {
			$fields['EntryData_Staff'][] = array(
				'EntryData_Staff_Name'  => array( $staff['post_id'] ),
				'EntryData_Staff_Voice' => $staff['comment'],
			);
		}

		foreach ( array( 'title', 'h1', 'keywords', 'description' ) as $seo_key ) {
			if ( isset( $doc['seo'][ $seo_key ] ) && '' !== $doc['seo'][ $seo_key ] ) {
				$fields[ $seo_key ] = $doc['seo'][ $seo_key ];
			}
		}

		return array(
			'post'      => array( 'post_title' => (string) $doc['title'] ),
			'fields'    => $fields,
			'hero'      => $hero,
			'image_ids' => array_map( static fn( $i ) => $i['attachment_id'], $images ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>>        $sections
	 * @param array<string,array<int,array<string,mixed>>> $by_phase
	 * @return array<int,array<string,mixed>>
	 */
	private function format( array $sections, array $by_phase ): array {
		$rows = array();

		// 1) 施工前/施工中/施工後
		$group = array();
		foreach ( self::PHASE_SECTION as $phase => $suffix ) {
			$entries = array();
			foreach ( $sections as $section ) {
				if ( $section['kind'] === $phase ) {
					$entries[] = $this->subtitle_row( "EntryData_Section{$suffix}_SubTitle", 'EntryData_Detail_Layout', 'EntryData_Detail_Catch', 'EntryData_Detail_Text', $section );
				}
			}
			if ( $by_phase[ $phase ] ) {
				$entries[] = $this->unit_row( "EntryData_Section{$suffix}_Unit", $suffix, $by_phase[ $phase ] );
			}
			if ( $entries ) {
				$group[ "EntryData_Section{$suffix}" ] = $entries;
			}
		}
		if ( $group ) {
			$rows[] = array(
				'acf_fc_layout'          => 'EntryData_Label_Detail',
				'EntryData_Group_Detail' => $group,
			);
		}

		// 2) 特徴解説（feature / summary / custom と、工程不明の写真）
		$feature = array();
		foreach ( $sections as $section ) {
			if ( in_array( $section['kind'], array( 'feature', 'summary', 'custom' ), true ) ) {
				$feature[] = $this->subtitle_row( 'Feature_Label_SubtitleText', 'Feature_SubtitleText_Layout', 'Feature_SubtitleText_Subtitle', 'Feature_SubtitleText_Text', $section );
			}
		}
		if ( $by_phase['unknown'] ) {
			$feature[] = $this->unit_row( 'EntryData_SectionFeature_Unit', 'Feature', $by_phase['unknown'] );
		}
		if ( $feature ) {
			$rows[] = array(
				'acf_fc_layout' => 'Feature_Label',
				'Feature'       => $feature,
			);
		}

		// 3) ビフォーアフター比較（comparison セクション。前後の写真がそろうものだけ）
		$compare = array();
		foreach ( $sections as $section ) {
			if ( 'comparison' !== $section['kind'] ) {
				continue;
			}
			$before = $this->first_in_phase( $section['image_ids'], $by_phase['before'] );
			$after  = $this->first_in_phase( $section['image_ids'], $by_phase['after'] );
			if ( null === $before || null === $after ) {
				continue;
			}
			// 見出しは小見出し行にだけ出す（ユニット側の SubTitle にも入れると二重に表示される）。
			$compare[] = $this->subtitle_row( 'BeforeAfter_Label_SubtitleText', 'EntryData_Detail_Layout', 'EntryData_Detail_Catch', 'EntryData_Detail_Text', $section );
			$compare[] = array(
				'acf_fc_layout'                => 'EntryData_SectionBeforeAfter_Unit',
				'EntryData_PictureBeforeAfter' => array(
					'EntryData_PictureBeforeAfter_Pop'          => true,
					'EntryData_PictureBeforeAfter_Trim'         => false,
					'EntryData_PictureBeforeAfter_Trim02'       => false,
					'EntryData_PictureBeforeAfter_Image_Before' => $before['attachment_id'],
					'EntryData_PictureBeforeAfter_Image_After'  => $after['attachment_id'],
					'EntryData_PictureBeforeAfter_Txt_Before'   => $before['description'],
					'EntryData_PictureBeforeAfter_Txt_After'    => $after['description'],
				),
			);
		}
		if ( $compare ) {
			$rows[] = array(
				'acf_fc_layout' => 'BeforeAfter_Label',
				'BeforeAfter'   => $compare,
			);
		}

		return $rows;
	}

	/**
	 * @param array<string,mixed> $section
	 * @return array<string,mixed>
	 */
	private function subtitle_row( string $layout, string $layout_field, string $catch_field, string $text_field, array $section ): array {
		$heading = trim( (string) $section['heading'] );
		$body    = trim( (string) $section['body'] );
		// 01=サブタイトル+テキスト, 02=サブタイトルのみ, 03=テキストのみ
		$mode = ( '' !== $heading && '' !== $body ) ? '01' : ( '' !== $heading ? '02' : '03' );
		$row  = array(
			'acf_fc_layout' => $layout,
			$layout_field   => $mode,
		);
		if ( '' !== $heading ) {
			$row[ $catch_field ] = $heading;
		}
		if ( '' !== $body ) {
			$row[ $text_field ] = $body;
		}
		return $row;
	}

	/**
	 * @param array<int,array<string,mixed>> $images
	 * @return array<string,mixed>
	 */
	private function unit_row( string $layout, string $process, array $images ): array {
		$rows = array();
		foreach ( $images as $image ) {
			$rows[] = array(
				"EntryData_Picture{$process}_Image" => $image['attachment_id'],
				"EntryData_Picture{$process}_Title" => (string) ( $image['caption'] ?? '' ),
				"EntryData_Picture{$process}_Txt"   => (string) $image['description'],
				"EntryData_Picture{$process}_Trim"  => false,
			);
		}
		return array(
			'acf_fc_layout'                      => $layout,
			"EntryData_Picture{$process}_layout" => self::UNIT_LAYOUT,
			"EntryData_Picture{$process}_Pop"    => true,
			"EntryData_Picture{$process}_Trim02" => false,
			"EntryData_Picture{$process}"        => $rows,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $images
	 * @return array<int,array<string,mixed>>
	 */
	private function included_images( array $images ): array {
		$out = array_values( array_filter( $images, static fn( $i ) => ! empty( $i['included'] ) ) );
		usort( $out, static fn( $a, $b ) => $a['original_position'] <=> $b['original_position'] );
		if ( ! $out ) {
			throw new CaseError( CaseError::MISSING_INPUT, '記事に含める写真がありません' );
		}
		return $out;
	}

	/**
	 * @param array<string,array<int,array<string,mixed>>> $by_phase
	 * @param array<int,array<string,mixed>>               $images
	 */
	private function hero( string $requested, array $by_phase, array $images ): string {
		if ( 'auto' === $requested ) {
			if ( $by_phase['before'] && $by_phase['after'] ) {
				return 'before_after';
			}
			return count( $images ) >= 3 ? 'gallery' : 'single';
		}
		if ( ! isset( self::HERO_LAYOUT[ $requested ] ) ) {
			throw new CaseError( CaseError::INVALID_DOCUMENT, 'FVの指定が不正です', array( 'hero' => $requested ) );
		}
		if ( 'before_after' === $requested && ( ! $by_phase['before'] || ! $by_phase['after'] ) ) {
			throw new CaseError( CaseError::MISSING_INPUT, 'ビフォーアフターのFVには施工前と施工後の写真が必要です' );
		}
		return $requested;
	}

	/**
	 * 入力された事実だけを返す（推測・不明は null）。
	 *
	 * @param array<string,mixed> $doc
	 */
	private function fact( array $doc, string $key ): ?string {
		$fact = $doc['facts'][ $key ] ?? null;
		if ( ! is_array( $fact ) || 'provided' !== $fact['status'] || ! is_string( $fact['value'] ) || '' === trim( $fact['value'] ) ) {
			return null;
		}
		return trim( $fact['value'] );
	}

	/**
	 * @param array<int,array<string,mixed>> $sections
	 * @return array<string,mixed>|null
	 */
	private function first_section( array $sections, string $kind ): ?array {
		foreach ( $sections as $section ) {
			if ( $section['kind'] === $kind ) {
				return $section;
			}
		}
		return null;
	}

	/**
	 * @param int[]                          $ids
	 * @param array<int,array<string,mixed>> $phase_images
	 * @return array<string,mixed>|null
	 */
	private function first_in_phase( array $ids, array $phase_images ): ?array {
		foreach ( $phase_images as $image ) {
			if ( in_array( $image['attachment_id'], $ids, true ) ) {
				return $image;
			}
		}
		return null;
	}

	/**
	 * プレーンテキストを、wysiwyg 用の段落HTMLにする（HTMLはエスケープ）。
	 */
	private function paragraphs( string $text ): string {
		return wpautop( esc_html( $text ) );
	}
}
