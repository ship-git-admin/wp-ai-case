<?php
/**
 * 現在のサイトで、対象投稿に実際に適用される ACF 定義を取得・正規化する。
 *
 * - 適用判定は ACF 自身の location 判定（acf_get_field_groups）に任せる。
 * - 投稿タクソノミー条件は、location ルールで参照されるタクソノミーだけを集めて
 *   post_terms として明示的に渡す（新規投稿でも既存投稿でも同じ条件で判定するため）。
 * - schema_sha256 は保存に影響する属性だけを決定的な順序で正規化して計算する。
 *   ラベル・説明文・表示幅などの見た目だけの属性は含めない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Acf;

use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

class SchemaInspector {

	/** 正規化に含めるフィールド属性（保存先・型・選択肢・条件・件数に関わるもの）。 */
	private const FIELD_ATTRS = array(
		'key',
		'name',
		'type',
		'required',
		'return_format',
		'choices',
		'default_value',
		'conditional_logic',
		'min',
		'max',
		'multiple',
		'allow_null',
		'post_type',
		'taxonomy',
		'mime_types',
		'maxlength',
		'save_custom',
		'save_other_choice',
		'other_choice',
		'collapsed',
	);

	/**
	 * 対象コンテキストを作る。post_id=0 は新規投稿（タームなし）を表す。
	 *
	 * @param array<string,int[]>|null $terms タクソノミー名 => term_id[]。null なら投稿から取得。
	 * @return array{post_type:string,post_id:int,post_terms:array<string,int[]>}
	 */
	public function context( string $post_type, int $post_id = 0, ?array $terms = null ): array {
		$taxonomies = $this->location_taxonomies( $post_type );
		$post_terms = array();
		foreach ( $taxonomies as $taxonomy ) {
			if ( null !== $terms ) {
				$ids = array_map( 'intval', $terms[ $taxonomy ] ?? array() );
			} elseif ( $post_id > 0 ) {
				$ids = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
				$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
			} else {
				$ids = array();
			}
			sort( $ids );
			$post_terms[ $taxonomy ] = $ids;
		}
		ksort( $post_terms );
		return array(
			'post_type'  => $post_type,
			'post_id'    => $post_id,
			'post_terms' => $post_terms,
		);
	}

	/**
	 * コンテキストに適用されるフィールドグループ（フィールド定義つき）。
	 *
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 * @return array<int,array<string,mixed>>
	 */
	public function groups( array $context ): array {
		$groups = acf_get_field_groups(
			array(
				'post_id'    => $context['post_id'],
				'post_type'  => $context['post_type'],
				'post_terms' => $context['post_terms'],
			)
		);
		$out = array();
		foreach ( $groups as $group ) {
			if ( empty( $group['active'] ) ) {
				continue;
			}
			$group['fields'] = acf_get_fields( $group );
			$out[]           = $group;
		}
		usort( $out, static fn( $a, $b ) => strcmp( (string) $a['key'], (string) $b['key'] ) );
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $groups
	 * @return array<string,mixed>
	 */
	public function canonical( array $groups ): array {
		$out = array();
		foreach ( $groups as $group ) {
			$out[] = array(
				'key'      => (string) $group['key'],
				'location' => $group['location'] ?? array(),
				'fields'   => $this->canonical_fields( $group['fields'] ?? array() ),
			);
		}
		return array( 'groups' => $out );
	}

	/**
	 * @param array<int,array<string,mixed>> $groups
	 */
	public function schema_hash( array $groups ): string {
		return hash( 'sha256', self::stable_json( $this->canonical( $groups ) ) );
	}

	/**
	 * 適用条件の指紋：投稿タイプと「実際に適用されるグループ（key と location）」の組。
	 * タームが違っても、適用されるグループが同じなら同じ文脈として扱う。
	 *
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 * @param array<int,array<string,mixed>>                                     $groups
	 */
	public function context_hash( array $context, array $groups ): string {
		$applied = array();
		foreach ( $groups as $group ) {
			$applied[] = array(
				'key'      => (string) $group['key'],
				'location' => $group['location'] ?? array(),
			);
		}
		usort( $applied, static fn( $a, $b ) => strcmp( $a['key'], $b['key'] ) );
		return hash(
			'sha256',
			self::stable_json(
				array(
					'post_type' => $context['post_type'],
					'groups'    => $applied,
				)
			)
		);
	}

	/**
	 * 契約（acf_mapping.context_selector）用の表現。
	 *
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 * @return array<string,mixed>
	 */
	public function context_selector( array $context, string $context_hash ): array {
		$terms = array();
		foreach ( $context['post_terms'] as $taxonomy => $ids ) {
			foreach ( $ids as $id ) {
				$terms[] = array(
					'taxonomy' => (string) $taxonomy,
					'term_id'  => (int) $id,
				);
			}
		}
		return array(
			'post_type'                 => $context['post_type'],
			'location_rule_fingerprint' => $context_hash,
			'taxonomy_terms'            => $terms,
		);
	}

	/**
	 * 1つのフィールドの正規化（子フィールド・レイアウトは含めない）。マッピングが使うノードの変化検出に使う。
	 *
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	public static function canonical_attrs( array $field ): array {
		$c = array();
		foreach ( self::FIELD_ATTRS as $attr ) {
			if ( array_key_exists( $attr, $field ) ) {
				$c[ $attr ] = $field[ $attr ];
			}
		}
		return $c;
	}

	/**
	 * 有効なテーマの指紋（表示の再確認が必要かの判定用。外部へは送らない）。
	 */
	public static function theme_fingerprint(): string {
		$theme = wp_get_theme();
		$dirs  = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
		$parts = array( $theme->get_stylesheet(), (string) $theme->get( 'Version' ) );
		foreach ( $dirs as $dir ) {
			$files = array();
			$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
					$files[] = $file->getPathname();
				}
			}
			sort( $files );
			foreach ( $files as $path ) {
				$parts[] = substr( $path, strlen( $dir ) ) . ':' . (string) hash_file( 'sha256', $path );
			}
		}
		/**
		 * 試験用：テーマの変更を模擬する。
		 */
		return (string) apply_filters( 'wp_ai_case_theme_fingerprint', hash( 'sha256', implode( "\n", $parts ) ) );
	}

	/**
	 * 最上位フィールドを name で引く索引。複数グループで同名があれば曖昧なので止める。
	 *
	 * @param array<int,array<string,mixed>> $groups
	 * @return array<string,array<string,mixed>>
	 */
	public static function top_level_index( array $groups ): array {
		$index = array();
		foreach ( $groups as $group ) {
			foreach ( $group['fields'] ?? array() as $field ) {
				$name = (string) ( $field['name'] ?? '' );
				if ( '' === $name ) {
					continue; // tab / message など値を持たないもの
				}
				if ( isset( $index[ $name ] ) ) {
					throw new CaseError(
						CaseError::MAPPING_MISMATCH,
						'同じ名前のフィールドが複数のグループに適用されています',
						array( 'field' => $name )
					);
				}
				$index[ $name ] = $field;
			}
		}
		return $index;
	}

	/**
	 * 子フィールドを name で引く索引。
	 *
	 * @param array<int,array<string,mixed>> $fields
	 * @return array<string,array<string,mixed>>
	 */
	public static function sub_index( array $fields, string $path ): array {
		$index = array();
		foreach ( $fields as $field ) {
			$name = (string) ( $field['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			if ( isset( $index[ $name ] ) ) {
				throw new CaseError( CaseError::MAPPING_MISMATCH, '同じ階層に同名の子フィールドがあります', array( 'path' => $path . '.' . $name ) );
			}
			$index[ $name ] = $field;
		}
		return $index;
	}

	/**
	 * Flexible Content のレイアウトを name で引く。
	 *
	 * @param array<string,mixed> $field
	 * @return array<string,array<string,mixed>>
	 */
	public static function layout_index( array $field ): array {
		$index = array();
		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			$index[ (string) $layout['name'] ] = $layout;
		}
		return $index;
	}

	/**
	 * 値ツリーを JSON にする（キー順を再帰的に固定）。
	 */
	public static function stable_json( mixed $value ): string {
		return (string) wp_json_encode( self::ksort_recursive( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private static function ksort_recursive( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::ksort_recursive( $v );
		}
		return $value;
	}

	/**
	 * @param array<int,array<string,mixed>> $fields
	 * @return array<int,array<string,mixed>>
	 */
	private function canonical_fields( array $fields ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$c = array();
			foreach ( self::FIELD_ATTRS as $attr ) {
				if ( array_key_exists( $attr, $field ) ) {
					$c[ $attr ] = $field[ $attr ];
				}
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$c['sub_fields'] = $this->canonical_fields( $field['sub_fields'] );
			}
			if ( ! empty( $field['layouts'] ) ) {
				$layouts = array();
				foreach ( (array) $field['layouts'] as $layout ) {
					$layouts[] = array(
						'key'        => (string) ( $layout['key'] ?? '' ),
						'name'       => (string) ( $layout['name'] ?? '' ),
						'min'        => $layout['min'] ?? '',
						'max'        => $layout['max'] ?? '',
						'sub_fields' => $this->canonical_fields( $layout['sub_fields'] ?? array() ),
					);
				}
				usort( $layouts, static fn( $a, $b ) => strcmp( $a['key'], $b['key'] ) );
				$c['layouts'] = $layouts;
			}
			$out[] = $c;
		}
		usort( $out, static fn( $a, $b ) => strcmp( (string) ( $a['key'] ?? '' ), (string) ( $b['key'] ?? '' ) ) );
		return $out;
	}

	/**
	 * post_type に関わるフィールドグループの location で参照されるタクソノミー名。
	 *
	 * @return string[]
	 */
	private function location_taxonomies( string $post_type ): array {
		$taxonomies = array();
		foreach ( acf_get_field_groups() as $group ) {
			$mentions_type = false;
			$found         = array();
			foreach ( (array) ( $group['location'] ?? array() ) as $and ) {
				foreach ( (array) $and as $rule ) {
					$param = $rule['param'] ?? '';
					if ( 'post_type' === $param && ( $rule['value'] ?? '' ) === $post_type ) {
						$mentions_type = true;
					}
					if ( 'post_taxonomy' === $param ) {
						$term = acf_get_term( (string) ( $rule['value'] ?? '' ) );
						if ( $term && ! is_wp_error( $term ) ) {
							$found[] = $term->taxonomy;
						}
					}
					if ( 'post_category' === $param ) {
						$found[] = 'category';
					}
				}
			}
			if ( $mentions_type ) {
				$taxonomies = array_merge( $taxonomies, $found );
			}
		}
		$taxonomies = array_values( array_unique( $taxonomies ) );
		sort( $taxonomies );
		return $taxonomies;
	}
}
