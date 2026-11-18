<?php
/**
 * テンプレートの論理構造を、現在のACF定義のフィールドへ結び付ける（AIを使わない機械的なルール）。
 *
 * ルール（親が対応している入れ物の中だけで探す。別の親への移動は追わない）：
 *   1) 前の版で結び付けた field key / layout key が同じ入れ物にあれば、それを使う（same_key）
 *   2) なければ同じ名前のフィールド / レイアウト（same_name）
 *   3) どちらもなければ「見つからない」として問題に挙げる
 * 型が違う場合は、登録済みの変換（transform）で扱える組み合わせだけを許す。
 * 未対応の型・新しく増えた必須フィールド・同名の重複も問題に挙げる（推測で埋めない）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Mapping;

use WpAiCase\Acf\FieldAdapterRegistry;

defined( 'ABSPATH' ) || exit;

final class BindingResolver {

	/** 登録済みの変換：論理の型 => [ACFの型 => transform_id] */
	public const TRANSFORMS = array(
		'text'             => array(
			'text'     => 'identity',
			'textarea' => 'text.to_textarea',
			'wysiwyg'  => 'text.to_html',
		),
		'textarea'         => array(
			'textarea' => 'identity',
			'wysiwyg'  => 'text.to_html',
		),
		'wysiwyg'          => array( 'wysiwyg' => 'identity' ),
		'url'              => array( 'url' => 'identity' ),
		'image'            => array( 'image' => 'identity' ),
		'true_false'       => array( 'true_false' => 'identity' ),
		'select'           => array(
			'select' => 'identity',
			'radio'  => 'choice.identity',
		),
		'radio'            => array(
			'radio'  => 'identity',
			'select' => 'choice.identity',
		),
		'relationship'     => array( 'relationship' => 'identity' ),
		'group'            => array(
			'group'    => 'identity',
			'repeater' => 'container.group_to_single_row',
		),
		'repeater'         => array( 'repeater' => 'identity' ),
		'flexible_content' => array( 'flexible_content' => 'identity' ),
	);

	/** @var array<int,array<string,mixed>> */
	private array $bindings = array();

	/** @var array<int,array<string,string>> */
	private array $problems = array();

	/** @var array<string,bool> 現在の定義に存在する field key（条件分岐の参照確認用） */
	private array $all_keys = array();

	/**
	 * @param array<string,array<string,mixed>> $logical  テンプレートの論理構造
	 * @param array<int,array<string,mixed>>    $groups   現在適用されるフィールドグループ（fields 付き）
	 * @param array<string,array<string,mixed>> $previous 前の版の bindings（semantic_slot => binding）。初回は空。
	 * @return array{bindings:array<int,array<string,mixed>>,problems:array<int,array<string,string>>}
	 */
	public function resolve( array $logical, array $groups, array $previous = array() ): array {
		$this->bindings = array();
		$this->problems = array();
		$this->all_keys = array();

		$top = array();
		foreach ( $groups as $group ) {
			foreach ( $group['fields'] ?? array() as $field ) {
				$top[] = $field;
			}
		}
		$this->collect_keys( $top );
		$this->walk( $logical, $top, '', array(), $previous, false );

		return array(
			'bindings' => $this->bindings,
			'problems' => $this->problems,
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $logical
	 * @param array<int,array<string,mixed>>    $targets
	 * @param string[]                          $path
	 * @param array<string,array<string,mixed>> $previous
	 * @param bool                              $new_rows この入れ物が「新しく作る行・グループ」か（必須フィールドの確認に使う）
	 */
	private function walk( array $logical, array $targets, string $prefix, array $path, array $previous, bool $new_rows ): void {
		$by_name = array();
		$by_key  = array();
		foreach ( $targets as $field ) {
			$name = (string) ( $field['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			if ( isset( $by_name[ $name ] ) ) {
				$this->problem( $prefix . $name, 'ambiguous', '同じ階層に同名のフィールドが複数あります' );
			}
			$by_name[ $name ]                  = $field;
			$by_key[ (string) $field['key'] ] = $field;
		}

		foreach ( $logical as $name => $node ) {
			$slot  = $prefix . $name;
			$prev  = $previous[ $slot ] ?? null;
			$field = null;
			$rule  = '';
			if ( $prev && isset( $by_key[ $prev['field_key'] ] ) ) {
				$field = $by_key[ $prev['field_key'] ];
				$rule  = 'same_key';
			} elseif ( isset( $by_name[ $name ] ) ) {
				$field = $by_name[ $name ];
				$rule  = 'same_name';
			}
			if ( null === $field ) {
				$this->problem( $slot, 'missing', '対応するフィールドが現在の定義にありません（削除・別の階層への移動・名前の変更）' );
				continue;
			}

			$type      = (string) $field['type'];
			$transform = self::TRANSFORMS[ $node['type'] ][ $type ] ?? null;
			if ( FieldAdapterRegistry::support_status( $type ) !== 'supported' ) {
				$this->problem( $slot, 'unsupported_type', "未対応のフィールド型です（{$type}）。追加開発が必要です" );
				continue;
			}
			if ( null === $transform ) {
				$this->problem( $slot, 'type_changed', "型が {$node['type']} から {$type} に変わり、登録済みの変換では扱えません" );
				continue;
			}
			if ( 'container.group_to_single_row' === $transform && (int) ( $field['min'] ?? 0 ) > 1 ) {
				$this->problem( $slot, 'type_changed', 'グループがリピーター（2行以上必須）に変わり、1行では保存できません' );
				continue;
			}
			$conditions_ok = $this->conditions_ok( $field );
			if ( ! $conditions_ok ) {
				$this->problem( $slot, 'condition', '条件分岐が現在の定義にないフィールドを参照しています' );
				continue;
			}

			$field_path = array_merge( $path, array( (string) $field['name'] ) );
			$this->bindings[] = array(
				'semantic_slot' => $slot,
				'field_key'     => (string) $field['key'],
				'field_type'    => $type,
				'path'          => $field_path,
				'transform_id'  => $transform,
				'editable'      => true,
				'confidence'    => 1,
				'evidence'      => 'rule:' . $rule,
				'verification'  => array(
					'field_exists'          => true,
					'type_compatible'       => true,
					'path_compatible'       => true,
					'conditions_compatible' => true,
					'transform_registered'  => true,
				),
			);

			if ( 'flexible_content' === $node['type'] ) {
				$this->walk_layouts( $node['layouts'] ?? array(), $field, $slot, $field_path, $previous );
			} elseif ( in_array( $node['type'], array( 'group', 'repeater' ), true ) ) {
				$this->walk( $node['children'] ?? array(), $field['sub_fields'] ?? array(), $slot . '/', $field_path, $previous, true );
			}
		}

		// 新しく作る行・グループに、テンプレートが値を持たない必須フィールドが増えていないか。
		if ( $new_rows ) {
			foreach ( $by_name as $name => $field ) {
				if ( ! isset( $logical[ $name ] ) && ! empty( $field['required'] ) && ! $this->has_default( $field ) ) {
					$this->problem( $prefix . $name, 'new_required', '必須フィールドが増えましたが、入力元がありません（追加入力または追加開発が必要）' );
				}
			}
		}
	}

	/**
	 * @param array<string,array<string,array<string,mixed>>> $layouts 論理レイアウト名 => 子
	 * @param array<string,mixed>                             $field
	 * @param string[]                                        $path
	 * @param array<string,array<string,mixed>>               $previous
	 */
	private function walk_layouts( array $layouts, array $field, string $slot, array $path, array $previous ): void {
		$by_name = array();
		$by_key  = array();
		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			$by_name[ (string) $layout['name'] ] = $layout;
			$by_key[ (string) $layout['key'] ]   = $layout;
		}
		foreach ( $layouts as $name => $children ) {
			$lslot  = "{$slot}/@{$name}";
			$prev   = $previous[ $lslot ] ?? null;
			$layout = null;
			$rule   = '';
			if ( $prev && ! empty( $prev['layout_key'] ) && isset( $by_key[ $prev['layout_key'] ] ) ) {
				$layout = $by_key[ $prev['layout_key'] ];
				$rule   = 'same_key';
			} elseif ( isset( $by_name[ $name ] ) ) {
				$layout = $by_name[ $name ];
				$rule   = 'same_name';
			}
			if ( null === $layout ) {
				$this->problem( $lslot, 'missing', 'レイアウトが現在の定義にありません' );
				continue;
			}
			$lpath            = array_merge( $path, array( '@' . $layout['name'] ) );
			$this->bindings[] = array(
				'semantic_slot' => $lslot,
				'field_key'     => (string) $field['key'],
				'field_type'    => 'layout',
				'path'          => $lpath,
				'layout_name'   => (string) $layout['name'],
				'layout_key'    => (string) $layout['key'],
				'transform_id'  => 'layout.identity',
				'editable'      => true,
				'confidence'    => 1,
				'evidence'      => 'rule:' . $rule,
				'verification'  => array(
					'field_exists'          => true,
					'type_compatible'       => true,
					'path_compatible'       => true,
					'conditions_compatible' => true,
					'transform_registered'  => true,
				),
			);
			$this->walk( $children, $layout['sub_fields'] ?? array(), $lslot . '/', $lpath, $previous, true );
		}
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private function conditions_ok( array $field ): bool {
		$logic = $field['conditional_logic'] ?? 0;
		if ( empty( $logic ) || ! is_array( $logic ) ) {
			return true;
		}
		foreach ( $logic as $and ) {
			foreach ( (array) $and as $rule ) {
				if ( ! isset( $this->all_keys[ (string) ( $rule['field'] ?? '' ) ] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private function has_default( array $field ): bool {
		$default = $field['default_value'] ?? '';
		return ! ( '' === $default || null === $default || array() === $default );
	}

	/**
	 * @param array<int,array<string,mixed>> $fields
	 */
	private function collect_keys( array $fields ): void {
		foreach ( $fields as $field ) {
			$this->all_keys[ (string) $field['key'] ] = true;
			$this->collect_keys( $field['sub_fields'] ?? array() );
			foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
				$this->collect_keys( $layout['sub_fields'] ?? array() );
			}
		}
	}

	private function problem( string $slot, string $kind, string $message ): void {
		$this->problems[] = array(
			'slot'    => $slot,
			'kind'    => $kind,
			'message' => $message,
		);
	}
}
