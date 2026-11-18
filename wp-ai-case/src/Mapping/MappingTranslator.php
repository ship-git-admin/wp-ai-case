<?php
/**
 * テンプレートの論理名で書いた値ツリーを、承認済みマッピングの bindings に従って
 * 現在のACFの名前・型・入れ物の形に変換する。
 *
 * - bindings にない論理ノードが来たら止める（自由なフィールドへは書かない）。
 * - 変換は BindingResolver::TRANSFORMS に登録したものだけ。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Mapping;

use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class MappingTranslator {

	/** @var array<string,array<string,mixed>> semantic_slot => binding */
	private array $bindings = array();

	/**
	 * @param array<string,mixed>               $tree     論理名の値ツリー（最上位）
	 * @param array<string,array<string,mixed>> $logical  テンプレートの論理構造
	 * @param array<int,array<string,mixed>>    $bindings マッピングの bindings
	 * @return array<string,mixed> 現在のACFの名前で書いた値ツリー
	 */
	public function translate( array $tree, array $logical, array $bindings ): array {
		$this->bindings = array();
		foreach ( $bindings as $binding ) {
			$this->bindings[ (string) $binding['semantic_slot'] ] = $binding;
		}
		return $this->container( $tree, $logical, '' );
	}

	/**
	 * @param array<string,mixed>               $values
	 * @param array<string,array<string,mixed>> $logical
	 * @return array<string,mixed>
	 */
	private function container( array $values, array $logical, string $prefix ): array {
		$out = array();
		foreach ( $values as $name => $value ) {
			$slot = $prefix . $name;
			if ( ! isset( $logical[ $name ] ) ) {
				throw new CaseError( CaseError::MAPPING_MISMATCH, 'テンプレートの論理構造にない項目です', array( 'slot' => $slot ) );
			}
			$binding = $this->binding( $slot );
			$target  = (string) end( $binding['path'] );
			$out[ $target ] = $this->value( $value, $logical[ $name ], $binding, $slot );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $node
	 * @param array<string,mixed> $binding
	 */
	private function value( mixed $value, array $node, array $binding, string $slot ): mixed {
		switch ( $binding['transform_id'] ) {
			case 'identity':
			case 'choice.identity':
			case 'text.to_textarea':
				break;
			case 'text.to_html':
				return is_string( $value ) ? wpautop( esc_html( $value ) ) : $value;
			case 'container.group_to_single_row':
				return array( $this->container( is_array( $value ) ? $value : array(), $node['children'] ?? array(), $slot . '/' ) );
			default:
				throw new CaseError( CaseError::MAPPING_MISMATCH, '登録されていない変換です', array( 'slot' => $slot, 'transform' => (string) $binding['transform_id'] ) );
		}

		switch ( $node['type'] ) {
			case 'group':
				return $this->container( is_array( $value ) ? $value : array(), $node['children'] ?? array(), $slot . '/' );
			case 'repeater':
				$rows = array();
				foreach ( (array) $value as $row ) {
					$rows[] = $this->container( is_array( $row ) ? $row : array(), $node['children'] ?? array(), $slot . '/' );
				}
				return $rows;
			case 'flexible_content':
				$rows = array();
				foreach ( (array) $value as $row ) {
					$layout = is_array( $row ) ? (string) ( $row['acf_fc_layout'] ?? '' ) : '';
					if ( ! isset( $node['layouts'][ $layout ] ) ) {
						throw new CaseError( CaseError::MAPPING_MISMATCH, 'テンプレートの論理構造にないレイアウトです', array( 'slot' => "{$slot}/@{$layout}" ) );
					}
					$lb   = $this->binding( "{$slot}/@{$layout}" );
					$data = $row;
					unset( $data['acf_fc_layout'] );
					$rows[] = array( 'acf_fc_layout' => (string) $lb['layout_name'] ) + $this->container( $data, $node['layouts'][ $layout ], "{$slot}/@{$layout}/" );
				}
				return $rows;
		}
		return $value;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function binding( string $slot ): array {
		if ( ! isset( $this->bindings[ $slot ] ) ) {
			throw new CaseError( CaseError::MAPPING_MISMATCH, 'マッピングに結び付けがない項目です', array( 'slot' => $slot ) );
		}
		return $this->bindings[ $slot ];
	}
}
