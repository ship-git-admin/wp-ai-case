<?php
/**
 * 構造変更の試験用に、ACF の実定義を書き換えずに「別の文脈の複製グループ」を作る（開発専用）。
 *
 * - 試験用タームの付いた施工事例だけに、共通テンプレートの複製グループ（ローカル登録）を適用する。
 * - そのときは本来のグループが適用されないよう、location の判定結果だけを差し替える（定義は変えない）。
 * - 複製は実行時に実定義から作り、field key は架空のものに置き換える（実キーをコードに持たない）。
 *
 * 版（variant）:
 *   A … 共通テンプレートと同じ構造（キーだけ架空）
 *   B … 根本変更：EntryData_Group_Detail をグループ→リピーター（最大1行）、FV_Layout をセレクト→ラジオ
 *   C … 未対応の変更：Outline_Area を未対応の型（google_map）に
 *   D … キーだけの違い（構造は A と同じ）
 *   E … 互換の変更：マッピングが使わないテキストフィールドを1つ追加
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev;

defined( 'ABSPATH' ) || exit;

final class AcfVariants {

	public const OPTION       = 'wp_ai_case_dev_acf_variant';
	public const TERM_SLUG    = 'wpaic-root-change-test';
	public const TERM_TAX     = 'sys_tag';
	public const GROUP_KEY    = 'group_wpaicsyn_rootchange';
	private const SOURCE_TITLE = '施工事例_ver1.1';
	public const VARIANTS     = array( 'A', 'B', 'C', 'D', 'E' );

	/** @var array<string,string> 元のキー => 架空のキー（条件分岐の参照の付け替えに使う） */
	private static array $keymap = array();

	public static function register(): void {
		add_action( 'acf/init', array( self::class, 'add_group' ), 20 );
		add_filter( 'acf/location/match_rule', array( self::class, 'exclude_source' ), 10, 4 );
	}

	public static function variant(): string {
		$v = (string) get_option( self::OPTION, '' );
		return in_array( $v, self::VARIANTS, true ) ? $v : '';
	}

	public static function term_id( bool $create = false ): int {
		$term = get_term_by( 'slug', self::TERM_SLUG, self::TERM_TAX );
		if ( $term ) {
			return (int) $term->term_id;
		}
		if ( ! $create ) {
			return 0;
		}
		$r = wp_insert_term( '【開発】構造変更テスト', self::TERM_TAX, array( 'slug' => self::TERM_SLUG ) );
		return is_wp_error( $r ) ? 0 : (int) $r['term_id'];
	}

	/**
	 * 試験用の文脈（新規投稿に試験用タームを付ける前提）。
	 *
	 * @return array<string,int[]>
	 */
	public static function terms(): array {
		return array( self::TERM_TAX => array( self::term_id() ) );
	}

	public static function add_group(): void {
		$variant = self::variant();
		if ( '' === $variant || ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		$group = self::group( $variant );
		if ( $group ) {
			acf_add_local_field_group( $group );
		}
	}

	/**
	 * 指定した版の複製グループ（登録はしない）。元のグループが見つからなければ null。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function group( string $variant ): ?array {
		$source = null;
		foreach ( acf_get_field_groups() as $group ) {
			if ( self::SOURCE_TITLE === ( $group['title'] ?? '' ) && empty( $group['local'] ) ) {
				$source = $group;
				break;
			}
		}
		if ( ! $source ) {
			return null;
		}
		self::$keymap = array();
		$salt         = 'D' === $variant ? 'D' : 'A';
		$fields       = self::clone_fields( acf_get_fields( $source ), $salt, $variant );
		$fields       = self::remap_conditions( $fields );
		if ( 'E' === $variant ) {
			$fields[] = array(
				'key'   => 'field_wpaicsyn_extra_note',
				'name'  => 'WpaicDev_Extra_Note',
				'label' => '（開発）追加メモ',
				'type'  => 'text',
			);
		}
		return array(
			'key'      => self::GROUP_KEY,
			'title'    => '【開発】構造変更テスト用の施工事例',
			'fields'   => $fields,
			'location' => array(
				array(
					array( 'param' => 'post_type', 'operator' => '==', 'value' => 'works' ),
					array( 'param' => 'post_taxonomy', 'operator' => '==', 'value' => self::TERM_TAX . ':' . self::TERM_SLUG ),
				),
			),
			'active'   => true,
		);
	}

	/**
	 * 試験用タームの文脈では、元のグループを適用しない（判定結果だけを差し替え。定義は変えない）。
	 *
	 * @param array<string,mixed> $rule
	 * @param array<string,mixed> $screen
	 * @param array<string,mixed> $group
	 */
	public static function exclude_source( mixed $result, array $rule, array $screen, array $group ): mixed {
		if ( '' === self::variant() || self::SOURCE_TITLE !== ( $group['title'] ?? '' ) || 'post_type' !== ( $rule['param'] ?? '' ) ) {
			return $result;
		}
		$term_id = self::term_id();
		if ( ! $term_id ) {
			return $result;
		}
		if ( isset( $screen['post_terms'] ) ) {
			$terms = (array) ( $screen['post_terms'][ self::TERM_TAX ] ?? array() );
		} elseif ( ! empty( $screen['post_id'] ) ) {
			$terms = wp_get_post_terms( (int) $screen['post_id'], self::TERM_TAX, array( 'fields' => 'ids' ) );
			$terms = is_wp_error( $terms ) ? array() : $terms;
		} else {
			$terms = array();
		}
		return in_array( $term_id, array_map( 'intval', $terms ), true ) ? false : $result;
	}

	/**
	 * @param array<int,array<string,mixed>> $fields
	 * @return array<int,array<string,mixed>>
	 */
	private static function clone_fields( array $fields, string $salt, string $variant ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$name = (string) ( $field['name'] ?? '' );
			$key_salt = $salt;
			// 変更するノードは別のキーにする（本物の根本変更と同じく、定義を作り直した扱い）。
			if ( ( 'B' === $variant && in_array( $name, array( 'EntryData_Group_Detail', 'FV_Layout' ), true ) ) || ( 'C' === $variant && 'Outline_Area' === $name ) ) {
				$key_salt = $variant;
			}
			$new_key                                    = 'field_wpaicsyn' . substr( md5( $key_salt . (string) $field['key'] ), 0, 12 );
			self::$keymap[ (string) $field['key'] ]     = $new_key;
			$copy                                       = $field;
			unset( $copy['ID'], $copy['id'], $copy['parent'], $copy['prefix'], $copy['value'], $copy['_valid'], $copy['menu_order'], $copy['parent_layout'] );
			$copy['key'] = $new_key;

			if ( 'B' === $variant && 'EntryData_Group_Detail' === $name && 'group' === $field['type'] ) {
				$copy['type']         = 'repeater';
				$copy['min']          = 0;
				$copy['max']          = 1;
				$copy['layout']       = 'block';
				$copy['button_label'] = '';
			}
			if ( 'B' === $variant && 'FV_Layout' === $name && 'select' === $field['type'] ) {
				$copy['type']          = 'radio';
				$copy['return_format'] = 'array';
				$copy['layout']        = 'horizontal';
				unset( $copy['multiple'], $copy['ui'], $copy['ajax'] );
			}
			if ( 'C' === $variant && 'Outline_Area' === $name ) {
				$copy['type'] = 'google_map';
			}

			if ( ! empty( $field['sub_fields'] ) ) {
				$copy['sub_fields'] = self::clone_fields( $field['sub_fields'], $salt, $variant );
			}
			if ( ! empty( $field['layouts'] ) ) {
				$layouts = array();
				foreach ( (array) $field['layouts'] as $layout ) {
					$lkey                   = 'layout_wpaicsyn' . substr( md5( $salt . (string) $layout['key'] ), 0, 12 );
					$l                      = $layout;
					$l['key']               = $lkey;
					$l['sub_fields']        = self::clone_fields( (array) ( $layout['sub_fields'] ?? array() ), $salt, $variant );
					foreach ( $l['sub_fields'] as $i => $sub ) {
						$l['sub_fields'][ $i ]['parent_layout'] = $lkey;
					}
					$layouts[ $lkey ] = $l;
				}
				$copy['layouts'] = $layouts;
			}
			$out[] = $copy;
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $fields
	 * @return array<int,array<string,mixed>>
	 */
	private static function remap_conditions( array $fields ): array {
		foreach ( $fields as $i => $field ) {
			if ( ! empty( $field['conditional_logic'] ) && is_array( $field['conditional_logic'] ) ) {
				foreach ( $field['conditional_logic'] as $a => $and ) {
					foreach ( (array) $and as $b => $rule ) {
						$ref = (string) ( $rule['field'] ?? '' );
						if ( isset( self::$keymap[ $ref ] ) ) {
							$fields[ $i ]['conditional_logic'][ $a ][ $b ]['field'] = self::$keymap[ $ref ];
						}
					}
				}
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$fields[ $i ]['sub_fields'] = self::remap_conditions( $field['sub_fields'] );
			}
			if ( ! empty( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $lk => $layout ) {
					$fields[ $i ]['layouts'][ $lk ]['sub_fields'] = self::remap_conditions( (array) ( $layout['sub_fields'] ?? array() ) );
				}
			}
		}
		return $fields;
	}
}
