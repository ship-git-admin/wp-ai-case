<?php
/**
 * 共通テンプレート「施工事例 ver1.1」（ACF のグループ名）向けの既知マッピング（AIを使わない固定ルール）。
 *
 * 書き方の版（TEMPLATE_VERSION）1.2（2026-11-19）：依頼者が示した手本の事例に合わせた。
 * - 概要（Outline）は 地域 → 建物の種類 → 築年数 → 施工箇所 → 施工内容（短い一覧）→ 工期 → 費用 …の順
 * - 施主様名には地域を入れ、お客様の声は入力をそのまま入れる（AI に作らせない）
 * - ご要望・ご提案は、入力だけから AI がまとめた文章（なければ入力そのもの）
 * - 施工前：写真（短い見出し付き）→ 見出し＋文章
 * - 施工中：工程ごとに 見出しだけの行 → その工程の写真 → 文章だけの行
 * - 施工後：1枚目の写真を大きく → 見出し＋文章 → 残りの写真
 * - 写真の下の説明文（_Txt）は空にし、短い見出し（_Title）だけを入れる
 *
 * 書き方 1.3（2026-11-19、手本の事例と実際に作った記事を比べて）：
 * - 写真の見出し（_Title）も空にする（手本はすべて空）
 * - 施工前・施工後の文章は小見出しを付けず、文章だけにする（手本と同じ）
 * - 写真の縦横で表示の設定を決める：縦長の写真は「縦長用リサイズ」（_Trim）を ON、
 *   4:3 から外れる横長・正方形の写真があるまとまりは「トリミング解除」（_Trim02）を ON、トップの写真の縦長も同じ
 * - トップの写真・施工後の大きい1枚は、写真の並びの1枚目のまま（縦長なら縦長用リサイズを ON。写真は差し替えない）
 * ACF の構造（論理名）は 1.2 と同じなので、TEMPLATE_VERSION は変えない（マッピングはそのまま使える）。
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
	public const TEMPLATE_VERSION = '1.2';
	/** 書き方（値の入れ方）の版。構造は TEMPLATE_VERSION のまま。 */
	public const WRITING_VERSION = '1.3';

	/** 試験用：画像の大きさ（attachment_id => [幅, 高さ]）。指定がなければメディアの情報を使う。 */
	private array $dims = array();

	/** 文章の節（本文ではない）の node_id。 */
	public const PROSE_NODES = array( 'request', 'proposal' );

	/** CaseDocument の facts → Outline のレイアウトと子フィールド（並び順もこの順）。 */
	private const OUTLINE = array(
		'area'          => array( 'Outline_Label_Area', 'Outline_Area' ),
		'building_type' => array( 'Outline_Label_Building-classification', 'Outline_Building-classification' ),
		'building_age'  => array( 'Outline_Label_Age', 'Outline_Age' ),
		'parts'         => array( 'Outline_Label_Parts', 'Outline_Parts' ),
		'work_type'     => array( 'Outline_Label_Content', 'Outline_Content' ),
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

	/** 写真ユニットの表示形式（type-a = フルサイズ/PC1カラム、type-b = ラージ/PC2カラム、type-c = ミドル/PC3カラム）。 */
	private const UNIT_LAYOUT = 'type-b';
	private const UNIT_LARGE  = 'type-a';
	private const UNIT_GRID   = 'type-c';

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
			'EntryData_CustomerName'  => $f( 'text' ),
			'EntryData_CustomerVoice' => $f( 'wysiwyg' ),
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
		$this->dims = (array) ( $options['dims'] ?? array() );
		$images     = $this->included_images( $doc['images'] );
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
			$main                         = $this->main_image( $by_phase['after'] ) ?? $this->main_image( $images );
			$fields['EntryData_CatchImg'] = array(
				'EntryData_CatchImg02'      => $main['attachment_id'],
				'EntryData_CatchImg02_Trim' => $this->portrait( (int) $main['attachment_id'] ),
			);
		} elseif ( 'gallery' === $hero ) {
			$rows = array();
			foreach ( $images as $image ) {
				$rows[] = array(
					'FV_Layout_Gallery_img'  => $image['attachment_id'],
					'FV_Layout_Gallery_alt'  => (string) ( $image['caption'] ?? '' ),
					'FV_Layout_Gallery_Trim' => $this->portrait( (int) $image['attachment_id'] ),
				);
			}
			$fields['FV_Layout_Gallery'] = $rows;
		} else {
			$before                          = $this->main_image( $by_phase['before'] );
			$after                           = $this->main_image( $by_phase['after'] );
			$fields['FV_Layout_BeforeAfter'] = array(
				'FV_Layout_BeforeAfter_Image_Before' => $before['attachment_id'],
				'FV_Layout_BeforeAfter_Image_After'  => $after['attachment_id'],
				'FV_Layout_BeforeAfter_BeforeTrim'   => $this->portrait( (int) $before['attachment_id'] ),
				'FV_Layout_BeforeAfter_AfterTrim'    => $this->portrait( (int) $after['attachment_id'] ),
			);
		}

		$intro                            = $this->first_section( $doc['sections'], 'introduction' );
		$fields['EntryData_CatchTxt']     = $intro ? $intro['heading'] : (string) $doc['title'];
		$fields['EntryData_Description']  = $intro ? $intro['body'] : (string) $doc['summary'];

		// ご要望・ご提案：AI がまとめた文章（節）があればそれを、なければ入力そのものを使う。
		foreach ( array( 'Request' => array( 'request', 'client_request' ), 'Proposal' => array( 'proposal', 'work_done' ) ) as $slot => [ $node, $fact_key ] ) {
			$prose = $this->prose( $doc['sections'], $node ) ?? $this->fact( $doc, $fact_key );
			if ( null !== $prose ) {
				$fields[ $slot ] = $this->paragraphs( $prose );
			}
		}
		$area = $this->fact( $doc, 'area' );
		if ( null !== $area ) {
			$fields['EntryData_CustomerName'] = $area;
		}
		$voice = $this->fact( $doc, 'customer_voice' );
		if ( null !== $voice ) {
			$fields['EntryData_CustomerVoice'] = $this->paragraphs( $voice );
		}
		$materials = $this->fact( $doc, 'materials' );
		if ( null !== $materials ) {
			$fields['EntryData_Commodity'] = $materials;
		}

		$outline = array();
		foreach ( self::OUTLINE as $fact_key => [ $layout, $sub ] ) {
			$value = $this->fact( $doc, $fact_key );
			if ( null === $value && 'work_type' === $fact_key ) {
				$value = $this->fact( $doc, 'overview' ); // 施工内容の一覧がない（1.1 で作った下書き）ときは、従来どおり概要
			}
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
		$sub   = fn( string $suffix, array $section ) => $this->subtitle_row( "EntryData_Section{$suffix}_SubTitle", 'EntryData_Detail_Layout', 'EntryData_Detail_Catch', 'EntryData_Detail_Text', $section );
		$of    = static fn( string $kind ) => array_values( array_filter( $sections, static fn( $s ) => $s['kind'] === $kind ) );

		// 施工前：写真 → 見出し＋文章
		$mae = array();
		if ( $by_phase['before'] ) {
			$mae[] = $this->unit_row( 'EntryData_SectionMae_Unit', 'Mae', $by_phase['before'], self::grid( count( $by_phase['before'] ) ) );
		}
		foreach ( $of( 'before' ) as $section ) {
			$mae[] = $sub( 'Mae', array( 'heading' => '', 'body' => $section['body'] ) ); // 小見出しなし（手本と同じ）
		}

		// 施工中：工程ごとに 見出しだけ → その工程の写真 → 文章だけ
		$chu = array();
		foreach ( self::steps( $of( 'during' ), $by_phase['during'] ) as [ $section, $photos ] ) {
			if ( null !== $section && '' !== trim( (string) $section['heading'] ) ) {
				$chu[] = $sub( 'Chu', array( 'heading' => $section['heading'], 'body' => '' ) );
			}
			if ( $photos ) {
				$chu[] = $this->unit_row( 'EntryData_SectionChu_Unit', 'Chu', $photos, self::UNIT_LAYOUT );
			}
			if ( null !== $section && '' !== trim( (string) $section['body'] ) ) {
				$chu[] = $sub( 'Chu', array( 'heading' => '', 'body' => $section['body'] ) );
			}
		}

		// 施工後：大きい1枚（1枚目）→ 文章 → 残りの写真
		$go    = array();
		$after = $by_phase['after'];
		$big   = $this->main_image( $after );
		if ( $big ) {
			$go[] = $this->unit_row( 'EntryData_SectionGo_Unit', 'Go', array( $big ), self::UNIT_LARGE );
		}
		foreach ( $of( 'after' ) as $section ) {
			$go[] = $sub( 'Go', array( 'heading' => '', 'body' => $section['body'] ) ); // 小見出しなし（手本と同じ）
		}
		$rest = array_values( array_filter( $after, static fn( $i ) => $big && $i['attachment_id'] !== $big['attachment_id'] ) );
		if ( $rest ) {
			$go[] = $this->unit_row( 'EntryData_SectionGo_Unit', 'Go', $rest, self::grid( count( $rest ) ) );
		}

		foreach ( array( 'Mae' => $mae, 'Chu' => $chu, 'Go' => $go ) as $suffix => $entries ) {
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
			if ( in_array( $section['kind'], array( 'feature', 'summary', 'custom' ), true ) && ! in_array( $section['node_id'] ?? '', self::PROSE_NODES, true ) ) {
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
					'EntryData_PictureBeforeAfter_Trim'         => $this->portrait( (int) $before['attachment_id'] ) || $this->portrait( (int) $after['attachment_id'] ),
					'EntryData_PictureBeforeAfter_Trim02'       => $this->off43( (int) $before['attachment_id'] ) || $this->off43( (int) $after['attachment_id'] ),
					'EntryData_PictureBeforeAfter_Image_Before' => $before['attachment_id'],
					'EntryData_PictureBeforeAfter_Image_After'  => $after['attachment_id'],
					'EntryData_PictureBeforeAfter_Txt_Before'   => '',
					'EntryData_PictureBeforeAfter_Txt_After'    => '',
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
	private function unit_row( string $layout, string $process, array $images, string $unit_layout = self::UNIT_LAYOUT ): array {
		$rows    = array();
		$untrim  = false;
		foreach ( $images as $image ) {
			$id      = (int) $image['attachment_id'];
			$untrim  = $untrim || $this->off43( $id );
			$rows[]  = array(
				"EntryData_Picture{$process}_Image" => $id,
				"EntryData_Picture{$process}_Title" => '', // 写真の見出しは出さない（手本と同じ）
				"EntryData_Picture{$process}_Txt"   => '',
				"EntryData_Picture{$process}_Trim"  => $this->portrait( $id ), // 縦長用リサイズ
			);
		}
		return array(
			'acf_fc_layout'                      => $layout,
			"EntryData_Picture{$process}_layout" => $unit_layout,
			"EntryData_Picture{$process}_Pop"    => true,
			"EntryData_Picture{$process}_Trim02" => $untrim, // 4:3 のトリミング解除
			"EntryData_Picture{$process}"        => $rows,
		);
	}

	/**
	 * 画像の幅 ÷ 高さ（分からなければ null）。
	 */
	public static function image_ratio( int $id, array $dims = array() ): ?float {
		if ( isset( $dims[ $id ] ) ) {
			[ $w, $h ] = $dims[ $id ];
		} else {
			$meta = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $id ) : false;
			$w    = is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0;
			$h    = is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0;
		}
		return ( $w > 0 && $h > 0 ) ? $w / $h : null;
	}

	/** 縦長（高さが幅の 1.05 倍を超える）。 */
	private function portrait( int $id ): bool {
		$r = self::image_ratio( $id, $this->dims );
		return null !== $r && $r < 0.95;
	}

	/** 縦長ではなく、4:3 から外れる（横に長い・正方形に近い）。 */
	private function off43( int $id ): bool {
		$r = self::image_ratio( $id, $this->dims );
		return null !== $r && $r >= 0.95 && abs( $r - 4 / 3 ) > 0.1;
	}

	/**
	 * 大きく見せる1枚：写真の並びの1枚目（縦長でも差し替えず、縦長用リサイズで合わせる）。
	 *
	 * @param array<int,array<string,mixed>> $images
	 * @return array<string,mixed>|null
	 */
	private function main_image( array $images ): ?array {
		return $images[0] ?? null;
	}

	/** 写真の枚数に合わせた並べ方（3枚以上は PC 3列、それ以下は 2列）。 */
	private static function grid( int $count ): string {
		return $count >= 3 ? self::UNIT_GRID : self::UNIT_LAYOUT;
	}

	/**
	 * 施工中の工程（節）ごとの写真。節の写真は施工中の写真のうち、まだ使っていないもの（元の並び順）。
	 * どの節にも入らなかった写真は、最後に見出しなしでまとめる。
	 *
	 * @param array<int,array<string,mixed>> $sections 施工中の節
	 * @param array<int,array<string,mixed>> $photos   施工中の写真（元の並び順）
	 * @return array<int,array{0:array<string,mixed>|null,1:array<int,array<string,mixed>>}>
	 */
	public static function steps( array $sections, array $photos ): array {
		$used  = array();
		$steps = array();
		foreach ( $sections as $section ) {
			$ids  = array_map( 'intval', (array) ( $section['image_ids'] ?? array() ) );
			$mine = array();
			foreach ( $photos as $photo ) {
				$id = (int) $photo['attachment_id'];
				if ( in_array( $id, $ids, true ) && ! isset( $used[ $id ] ) ) {
					$mine[]      = $photo;
					$used[ $id ] = true;
				}
			}
			$steps[] = array( $section, $mine );
		}
		$left = array_values( array_filter( $photos, static fn( $p ) => ! isset( $used[ (int) $p['attachment_id'] ] ) ) );
		if ( $left ) {
			$steps[] = array( null, $left );
		}
		return $steps;
	}

	/**
	 * ご要望・ご提案の文章（節）。空なら null。
	 *
	 * @param array<int,array<string,mixed>> $sections
	 */
	private function prose( array $sections, string $node_id ): ?string {
		foreach ( $sections as $section ) {
			if ( ( $section['node_id'] ?? '' ) === $node_id ) {
				$body = trim( (string) $section['body'] );
				return '' !== $body ? $body : null;
			}
		}
		return null;
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
