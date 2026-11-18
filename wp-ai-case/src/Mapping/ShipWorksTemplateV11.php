<?php
/**
 * 共通テンプレート「施工事例 ver1.1」向けの既知マッピング（AIを使わない固定ルール）。
 *
 * CaseDocument（ACF非依存）から「テンプレートの論理名で書いた値ツリー」を作る。
 * - 論理名は、このテンプレートが前提とする構造（logical_schema）の名前。
 * - 実際の保存先（field key・名前・型・階層）は、サイトごとに承認されたマッピング（bindings）で
 *   MappingTranslator が変換する。構造が変わったときはマッピングを作り直して承認する。
 * - 事実（工期・費用など）は入力された値（status=provided）だけを使い、推測で埋めない。
 * - 画像は「含める」ものをすべて、工程（before/during/after/unknown）ごとのユニットに入れる。
 * - 関連付け（お客様の声・担当スタッフ・監修者）は紐付けがあるときだけ書く。
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
	 * このテンプレートが値を書く論理構造。
	 * 各ノード：['type' => ACF型, 'children' => [名前 => ノード]（group/repeater）, 'layouts' => [レイアウト名 => [名前 => ノード]]（flexible_content）]
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function logical_schema(): array {
		$f  = static fn( string $type ): array => array( 'type' => $type );
		$g  = static fn( array $children ): array => array( 'type' => 'group', 'children' => $children );
		$r  = static fn( array $children ): array => array( 'type' => 'repeater', 'children' => $children );
		$fc = static fn( array $layouts ): array => array( 'type' => 'flexible_content', 'layouts' => $layouts );

		$subtitle = static fn( string $layout_field, string $catch, string $text ): array => array(
			$layout_field => $f( 'radio' ),
			$catch        => $f( 'text' ),
			$text         => $f( 'textarea' ),
		);
		$unit = static function ( string $p ) use ( $f, $r ): array {
			return array(
				"EntryData_Picture{$p}_layout" => $f( 'radio' ),
				"EntryData_Picture{$p}_Pop"    => $f( 'true_false' ),
				"EntryData_Picture{$p}_Trim02" => $f( 'true_false' ),
				"EntryData_Picture{$p}"        => $r(
					array(
						"EntryData_Picture{$p}_Image" => $f( 'image' ),
						"EntryData_Picture{$p}_Title" => $f( 'text' ),
						"EntryData_Picture{$p}_Txt"   => $f( 'textarea' ),
						"EntryData_Picture{$p}_Trim"  => $f( 'true_false' ),
					)
				),
			);
		};
		$phase = static fn( string $p ) => $fc(
			array(
				"EntryData_Section{$p}_SubTitle" => $subtitle( 'EntryData_Detail_Layout', 'EntryData_Detail_Catch', 'EntryData_Detail_Text' ),
				"EntryData_Section{$p}_Unit"     => $unit( $p ),
			)
		);
		$outline = array();
		foreach ( self::OUTLINE as [ $layout, $sub ] ) {
			$outline[ $layout ] = array( $sub => $f( 'text' ) );
		}

		return array(
			'FV_Layout'             => $f( 'select' ),
			'EntryData_CatchImg'    => $g(
				array(
					'EntryData_CatchImg02'      => $f( 'image' ),
					'EntryData_CatchImg02_Trim' => $f( 'true_false' ),
				)
			),
			'FV_Layout_Gallery'     => $r(
				array(
					'FV_Layout_Gallery_img'  => $f( 'image' ),
					'FV_Layout_Gallery_alt'  => $f( 'text' ),
					'FV_Layout_Gallery_Trim' => $f( 'true_false' ),
				)
			),
			'FV_Layout_BeforeAfter' => $g(
				array(
					'FV_Layout_BeforeAfter_Image_Before' => $f( 'image' ),
					'FV_Layout_BeforeAfter_Image_After'  => $f( 'image' ),
					'FV_Layout_BeforeAfter_BeforeTrim'   => $f( 'true_false' ),
					'FV_Layout_BeforeAfter_AfterTrim'    => $f( 'true_false' ),
				)
			),
			'EntryData_CatchTxt'    => $f( 'text' ),
			'EntryData_Description' => $f( 'textarea' ),
			'Request'               => $f( 'wysiwyg' ),
			'Proposal'              => $f( 'wysiwyg' ),
			'EntryData_Commodity'   => $f( 'textarea' ),
			'Outline'               => $fc( $outline ),
			'Format'                => $fc(
				array(
					'EntryData_Label_Detail' => array(
						'EntryData_Group_Detail' => $g(
							array(
								'EntryData_SectionMae' => $phase( 'Mae' ),
								'EntryData_SectionChu' => $phase( 'Chu' ),
								'EntryData_SectionGo'  => $phase( 'Go' ),
							)
						),
					),
					'Feature_Label'          => array(
						'Feature' => $fc(
							array(
								'Feature_Label_SubtitleText'    => $subtitle( 'Feature_SubtitleText_Layout', 'Feature_SubtitleText_Subtitle', 'Feature_SubtitleText_Text' ),
								'EntryData_SectionFeature_Unit' => $unit( 'Feature' ),
							)
						),
					),
					'BeforeAfter_Label'      => array(
						'BeforeAfter' => $fc(
							array(
								'BeforeAfter_Label_SubtitleText'    => $subtitle( 'EntryData_Detail_Layout', 'EntryData_Detail_Catch', 'EntryData_Detail_Text' ),
								'EntryData_SectionBeforeAfter_Unit' => array(
									'EntryData_PictureBeforeAfter' => $g(
										array(
											'EntryData_PictureBeforeAfter_Pop'          => $f( 'true_false' ),
											'EntryData_PictureBeforeAfter_Trim'         => $f( 'true_false' ),
											'EntryData_PictureBeforeAfter_Trim02'       => $f( 'true_false' ),
											'EntryData_PictureBeforeAfter_Image_Before' => $f( 'image' ),
											'EntryData_PictureBeforeAfter_Image_After'  => $f( 'image' ),
											'EntryData_PictureBeforeAfter_Txt_Before'   => $f( 'textarea' ),
											'EntryData_PictureBeforeAfter_Txt_After'    => $f( 'textarea' ),
										)
									),
								),
							)
						),
					),
				)
			),
			'EntryData_RelatedData' => $f( 'relationship' ),
			'entrydata_supervision' => $f( 'relationship' ),
			'EntryData_Staff'       => $r(
				array(
					'EntryData_Staff_Name'  => $f( 'relationship' ),
					'EntryData_Staff_Voice' => $f( 'textarea' ),
				)
			),
			'title'                 => $f( 'textarea' ),
			'h1'                    => $f( 'textarea' ),
			'keywords'              => $f( 'textarea' ),
			'description'           => $f( 'textarea' ),
		);
	}

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

		// 関連付けは紐付けがあるものだけ書く（空なら書かず、下書きはそのまま作る）。
		$rel = $doc['relations'];
		if ( $rel['customer_voice_post_ids'] ) {
			$fields['EntryData_RelatedData'] = array_values( $rel['customer_voice_post_ids'] );
		}
		if ( $rel['supervisor_post_ids'] ) {
			$fields['entrydata_supervision'] = array_values( $rel['supervisor_post_ids'] );
		}
		if ( $rel['staff'] ) {
			$fields['EntryData_Staff'] = array();
			foreach ( $rel['staff'] as $staff ) {
				$fields['EntryData_Staff'][] = array(
					'EntryData_Staff_Name'  => array( $staff['post_id'] ),
					'EntryData_Staff_Voice' => $staff['comment'],
				);
			}
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
