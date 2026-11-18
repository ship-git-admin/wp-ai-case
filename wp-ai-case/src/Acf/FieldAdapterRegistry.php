<?php
/**
 * ACF フィールド型ごとの保存アダプタ。
 *
 * 「名前で書いた値ツリー」を、現在の ACF 定義（field key・型・選択肢・条件・件数）と照合し、
 * update_field() にそのまま渡せる「field key のツリー」へ変換する。
 * 変換（prepare）はすべて書き込みの前に終わらせ、1つでも問題があれば何も書かずに止める。
 *
 * 実装済みの型だけを SUPPORTED に入れる。ここにない型は「未対応」として安全に停止する。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Acf;

use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class FieldAdapterRegistry {

	/** C1-A で実装・試験した型。 */
	public const SUPPORTED = array(
		'text',
		'textarea',
		'wysiwyg',
		'url',
		'image',
		'true_false',
		'select',
		'radio',
		'group',
		'repeater',
		'flexible_content',
		'relationship',
	);

	/** 値を持たない（保存対象にならない）型。 */
	public const NO_VALUE = array( 'tab', 'message', 'accordion' );

	private const CONTAINERS = array( 'group', 'repeater', 'flexible_content' );

	/** @var int[] prepare 中に参照した画像ID */
	private array $attachments = array();

	/** @var int[] prepare 中に参照した関連投稿ID */
	private array $related_posts = array();

	public static function support_status( string $type ): string {
		if ( in_array( $type, self::SUPPORTED, true ) ) {
			return 'supported';
		}
		if ( in_array( $type, self::NO_VALUE, true ) ) {
			return 'no_value';
		}
		return 'unsupported';
	}

	/**
	 * @return int[]
	 */
	public function referenced_attachments(): array {
		return array_values( array_unique( $this->attachments ) );
	}

	/**
	 * @return int[]
	 */
	public function referenced_posts(): array {
		return array_values( array_unique( $this->related_posts ) );
	}

	/**
	 * 最上位フィールドの値を変換する。指定のないフィールドは書かない（既存値を保持）。
	 *
	 * @param array<string,array<string,mixed>> $index  name => field（SchemaInspector::top_level_index）
	 * @param array<string,mixed>               $values name => 値
	 * @param array<string,mixed>               $scope  field key => 現在の値（条件分岐の判定用）
	 * @return array<string,array{field:array<string,mixed>,raw:mixed}> field key => 変換結果
	 */
	public function prepare_top_level( array $index, array $values, array $scope ): array {
		$this->attachments   = array();
		$this->related_posts = array();

		$fields = array_values( $index );
		$raw    = $this->prepare_container( $fields, $values, '', $scope, false );
		$out    = array();
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field['key'], $raw ) ) {
				$out[ $field['key'] ] = array(
					'field' => $field,
					'raw'   => $raw[ $field['key'] ],
				);
			}
		}
		return $out;
	}

	/**
	 * 読み戻した値と期待値を比べられる形にそろえる。
	 *
	 * @param array<string,mixed> $field
	 */
	public function normalize( array $field, mixed $raw ): mixed {
		$type = (string) $field['type'];
		switch ( $type ) {
			case 'image':
				return ( is_numeric( $raw ) && (int) $raw > 0 ) ? (int) $raw : 0;
			case 'true_false':
				return is_scalar( $raw ) && (bool) (int) $raw;
			case 'relationship':
				return is_array( $raw ) ? array_values( array_map( 'intval', $raw ) ) : array();
			case 'select':
				if ( ! empty( $field['multiple'] ) ) {
					return is_array( $raw ) ? array_values( array_map( 'strval', $raw ) ) : array();
				}
				return is_scalar( $raw ) ? (string) $raw : '';
			case 'group':
				$row = is_array( $raw ) ? $raw : array();
				return $this->normalize_row( $field['sub_fields'] ?? array(), $row );
			case 'repeater':
				$out = array();
				foreach ( is_array( $raw ) ? $raw : array() as $row ) {
					$out[] = $this->normalize_row( $field['sub_fields'] ?? array(), is_array( $row ) ? $row : array() );
				}
				return $out;
			case 'flexible_content':
				$layouts = SchemaInspector::layout_index( $field );
				$out     = array();
				foreach ( is_array( $raw ) ? $raw : array() as $row ) {
					$name   = is_array( $row ) ? (string) ( $row['acf_fc_layout'] ?? '' ) : '';
					$layout = $layouts[ $name ] ?? array( 'sub_fields' => array() );
					$out[]  = array( 'acf_fc_layout' => $name ) + $this->normalize_row( $layout['sub_fields'] ?? array(), is_array( $row ) ? $row : array() );
				}
				return $out;
			default:
				return ( null === $raw || false === $raw ) ? '' : ( is_scalar( $raw ) ? (string) $raw : $raw );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $fields
	 * @param array<string,mixed>            $row
	 * @return array<string,mixed>
	 */
	private function normalize_row( array $fields, array $row ): array {
		$out = array();
		foreach ( $fields as $sub ) {
			if ( self::support_status( (string) $sub['type'] ) === 'no_value' ) {
				continue;
			}
			$value = array_key_exists( $sub['key'], $row ) ? $row[ $sub['key'] ] : ( $row[ $sub['name'] ] ?? null );
			$out[ $sub['key'] ] = $this->normalize( $sub, $value );
		}
		return $out;
	}

	/**
	 * 1つの入れ物（最上位・グループ・行）の中身を変換する。
	 *
	 * @param array<int,array<string,mixed>> $fields   この入れ物の子フィールド定義
	 * @param array<string,mixed>            $values   name => 値
	 * @param array<string,mixed>            $scope    親までの値（field key => raw）
	 * @param bool                           $full_row true なら、指定のない子フィールドも既定値で埋める（行を丸ごと置き換える）
	 * @return array<string,mixed> field key => raw
	 */
	private function prepare_container( array $fields, array $values, string $path, array $scope, bool $full_row ): array {
		$index = SchemaInspector::sub_index( $fields, '' === $path ? '$' : $path );

		foreach ( array_keys( $values ) as $name ) {
			if ( ! isset( $index[ $name ] ) ) {
				throw new CaseError( CaseError::MAPPING_MISMATCH, '現在のACF定義に存在しないフィールドです', array( 'path' => $this->join( $path, (string) $name ) ) );
			}
			$type = (string) $index[ $name ]['type'];
			if ( self::support_status( $type ) === 'unsupported' ) {
				throw new CaseError( CaseError::UNSUPPORTED_FIELD_TYPE, '未対応のフィールド型です', array( 'path' => $this->join( $path, (string) $name ), 'type' => $type ) );
			}
			if ( self::support_status( $type ) === 'no_value' ) {
				throw new CaseError( CaseError::MAPPING_MISMATCH, '値を持たないフィールドには書けません', array( 'path' => $this->join( $path, (string) $name ) ) );
			}
		}

		$raw = array();
		// 1回目：値（スカラー）を先に変換して、条件分岐の判定に使えるようにする。
		foreach ( $values as $name => $value ) {
			$field = $index[ $name ];
			if ( ! in_array( $field['type'], self::CONTAINERS, true ) ) {
				$raw[ $field['key'] ] = $this->prepare_scalar( $field, $value, $this->join( $path, (string) $name ) );
			}
		}
		if ( $full_row ) {
			foreach ( $index as $name => $field ) {
				if ( array_key_exists( $name, $values ) || in_array( $field['type'], self::CONTAINERS, true ) ) {
					continue;
				}
				if ( self::support_status( (string) $field['type'] ) === 'no_value' ) {
					continue;
				}
				$raw[ $field['key'] ] = $this->empty_raw( $field, $this->join( $path, (string) $name ) );
			}
		}

		$local = array_merge( $scope, $raw );

		// 2回目：入れ物（グループ・リピーター・フレキシブル）。
		foreach ( $values as $name => $value ) {
			$field = $index[ $name ];
			if ( in_array( $field['type'], self::CONTAINERS, true ) ) {
				$raw[ $field['key'] ] = $this->prepare_container_field( $field, $value, $this->join( $path, (string) $name ), $local );
			}
		}
		if ( $full_row ) {
			foreach ( $index as $name => $field ) {
				if ( ! array_key_exists( $name, $values ) && in_array( $field['type'], self::CONTAINERS, true ) ) {
					$raw[ $field['key'] ] = $this->empty_raw( $field, $this->join( $path, (string) $name ) );
				}
			}
		}

		// 条件分岐で非表示になるフィールドへ、空でない値を入れていないか。
		$local = array_merge( $scope, $raw );
		foreach ( $values as $name => $value ) {
			$field = $index[ $name ];
			if ( ! $this->visible( $field, $local, $index ) && ! $this->is_empty_raw( $raw[ $field['key'] ] ) ) {
				throw new CaseError( CaseError::HIDDEN_BY_CONDITION, '条件分岐で表示されないフィールドに値を入れようとしました', array( 'path' => $this->join( $path, (string) $name ) ) );
			}
		}

		// 新しく作る行・グループでは、表示される必須フィールドが空であってはならない。
		if ( $full_row ) {
			foreach ( $index as $name => $field ) {
				if ( ! empty( $field['required'] ) && $this->visible( $field, $local, $index ) && $this->is_empty_raw( $raw[ $field['key'] ] ?? null ) ) {
					throw new CaseError( CaseError::INVALID_VALUE, '必須フィールドが空です', array( 'path' => $this->join( $path, (string) $name ) ) );
				}
			}
		}

		return $raw;
	}

	/**
	 * @param array<string,mixed> $field
	 * @param array<string,mixed> $scope
	 */
	private function prepare_container_field( array $field, mixed $value, string $path, array $scope ): mixed {
		switch ( $field['type'] ) {
			case 'group':
				if ( ! is_array( $value ) || ( $value && array_is_list( $value ) ) ) {
					throw new CaseError( CaseError::INVALID_VALUE, 'グループの値はオブジェクトです', array( 'path' => $path ) );
				}
				return $this->prepare_container( $field['sub_fields'] ?? array(), $value, $path, $scope, true );

			case 'repeater':
				if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
					throw new CaseError( CaseError::INVALID_VALUE, 'リピーターの値は行の配列です', array( 'path' => $path ) );
				}
				$this->check_count( count( $value ), $field['min'] ?? 0, $field['max'] ?? 0, $path );
				$rows = array();
				foreach ( $value as $i => $row ) {
					if ( ! is_array( $row ) || ( $row && array_is_list( $row ) ) ) {
						throw new CaseError( CaseError::INVALID_VALUE, '行の値はオブジェクトです', array( 'path' => "{$path}[{$i}]" ) );
					}
					$rows[] = $this->prepare_container( $field['sub_fields'] ?? array(), $row, "{$path}[{$i}]", $scope, true );
				}
				return $rows;

			case 'flexible_content':
				if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
					throw new CaseError( CaseError::INVALID_VALUE, 'フレキシブルコンテンツの値は行の配列です', array( 'path' => $path ) );
				}
				$this->check_count( count( $value ), $field['min'] ?? 0, $field['max'] ?? 0, $path );
				$layouts = SchemaInspector::layout_index( $field );
				$counts  = array();
				$rows    = array();
				foreach ( $value as $i => $row ) {
					$name = is_array( $row ) ? ( $row['acf_fc_layout'] ?? null ) : null;
					if ( ! is_string( $name ) || ! isset( $layouts[ $name ] ) ) {
						throw new CaseError( CaseError::MAPPING_MISMATCH, '現在のACF定義に存在しないレイアウトです', array( 'path' => "{$path}[{$i}]", 'layout' => is_string( $name ) ? $name : '' ) );
					}
					$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
					$data            = $row;
					unset( $data['acf_fc_layout'] );
					$rows[] = array( 'acf_fc_layout' => $name ) + $this->prepare_container( $layouts[ $name ]['sub_fields'] ?? array(), $data, "{$path}[{$i}]<{$name}>", $scope, true );
				}
				foreach ( $layouts as $name => $layout ) {
					$this->check_count( $counts[ $name ] ?? 0, $layout['min'] ?? 0, $layout['max'] ?? 0, "{$path}<{$name}>" );
				}
				return $rows;
		}
		throw new CaseError( CaseError::UNSUPPORTED_FIELD_TYPE, '未対応のフィールド型です', array( 'path' => $path, 'type' => (string) $field['type'] ) );
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private function prepare_scalar( array $field, mixed $value, string $path ): mixed {
		$type = (string) $field['type'];
		switch ( $type ) {
			case 'text':
			case 'textarea':
				if ( ! is_string( $value ) ) {
					throw new CaseError( CaseError::INVALID_VALUE, '文字列が必要です', array( 'path' => $path ) );
				}
				// テーマ側で無加工のまま出力される項目があるため、タグは必ず除去する。
				$clean = 'text' === $type ? sanitize_text_field( $value ) : sanitize_textarea_field( $value );
				$max   = (int) ( $field['maxlength'] ?? 0 );
				if ( $max > 0 && mb_strlen( $clean ) > $max ) {
					throw new CaseError( CaseError::INVALID_VALUE, '文字数の上限を超えています', array( 'path' => $path, 'maxlength' => $max ) );
				}
				return $clean;

			case 'wysiwyg':
				if ( ! is_string( $value ) ) {
					throw new CaseError( CaseError::INVALID_VALUE, '文字列が必要です', array( 'path' => $path ) );
				}
				return wp_kses_post( $value );

			case 'url':
				if ( ! is_string( $value ) ) {
					throw new CaseError( CaseError::INVALID_VALUE, 'URLは文字列です', array( 'path' => $path ) );
				}
				$value = trim( $value );
				if ( '' === $value ) {
					return '';
				}
				$clean = esc_url_raw( $value, array( 'http', 'https' ) );
				if ( $clean !== $value || ! wp_http_validate_url( $clean ) ) {
					throw new CaseError( CaseError::INVALID_VALUE, 'URLが不正です', array( 'path' => $path ) );
				}
				return $clean;

			case 'image':
				if ( null === $value || '' === $value || 0 === $value ) {
					return '';
				}
				if ( ! is_int( $value ) || $value < 1 ) {
					throw new CaseError( CaseError::INVALID_VALUE, '画像は attachment ID（整数）で指定します', array( 'path' => $path ) );
				}
				if ( get_post_type( $value ) !== 'attachment' || ! wp_attachment_is_image( $value ) ) {
					throw new CaseError( CaseError::INVALID_VALUE, '画像の添付ファイルではありません', array( 'path' => $path, 'attachment_id' => $value ) );
				}
				$this->check_mime( $field, $value, $path );
				$this->attachments[] = $value;
				return $value;

			case 'true_false':
				if ( ! is_bool( $value ) && 0 !== $value && 1 !== $value ) {
					throw new CaseError( CaseError::INVALID_VALUE, '真偽値が必要です', array( 'path' => $path ) );
				}
				return $value ? 1 : 0;

			case 'select':
			case 'radio':
				$choices = array_map( 'strval', array_keys( (array) ( $field['choices'] ?? array() ) ) );
				if ( 'select' === $type && ! empty( $field['multiple'] ) ) {
					if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
						throw new CaseError( CaseError::INVALID_VALUE, '複数選択は配列です', array( 'path' => $path ) );
					}
					foreach ( $value as $v ) {
						if ( ! in_array( (string) $v, $choices, true ) ) {
							throw new CaseError( CaseError::INVALID_VALUE, '選択肢にない値です', array( 'path' => $path, 'value' => (string) $v ) );
						}
					}
					return array_values( array_map( 'strval', $value ) );
				}
				if ( ! is_string( $value ) ) {
					throw new CaseError( CaseError::INVALID_VALUE, '選択肢の値は文字列です', array( 'path' => $path ) );
				}
				if ( '' === $value && ! empty( $field['allow_null'] ) ) {
					return '';
				}
				if ( ! in_array( $value, $choices, true ) ) {
					throw new CaseError( CaseError::INVALID_VALUE, '選択肢にない値です', array( 'path' => $path, 'value' => $value ) );
				}
				return $value;

			case 'relationship':
				if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
					throw new CaseError( CaseError::INVALID_VALUE, '関連付けは投稿IDの配列です', array( 'path' => $path ) );
				}
				$this->check_count( count( $value ), $field['min'] ?? 0, $field['max'] ?? 0, $path );
				$ids = array();
				foreach ( $value as $id ) {
					if ( ! is_int( $id ) || $id < 1 || in_array( $id, $ids, true ) ) {
						throw new CaseError( CaseError::INVALID_VALUE, '関連付けの投稿IDが不正か重複しています', array( 'path' => $path ) );
					}
					$this->check_related_post( $field, $id, $path );
					$ids[] = $id;
				}
				$this->related_posts = array_merge( $this->related_posts, $ids );
				return $ids;
		}
		throw new CaseError( CaseError::UNSUPPORTED_FIELD_TYPE, '未対応のフィールド型です', array( 'path' => $path, 'type' => $type ) );
	}

	/**
	 * 新しく作る行で、指定がなかったフィールドに入れる値（ACF の既定値に合わせる）。
	 *
	 * @param array<string,mixed> $field
	 */
	private function empty_raw( array $field, string $path ): mixed {
		$type = (string) $field['type'];
		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'wysiwyg':
			case 'url':
				return is_string( $field['default_value'] ?? null ) ? $field['default_value'] : '';
			case 'image':
				return '';
			case 'true_false':
				return empty( $field['default_value'] ) ? 0 : 1;
			case 'select':
			case 'radio':
				$choices = array_map( 'strval', array_keys( (array) ( $field['choices'] ?? array() ) ) );
				$default = $field['default_value'] ?? '';
				if ( is_array( $default ) ) {
					$default = reset( $default );
				}
				if ( 'select' === $type && ! empty( $field['multiple'] ) ) {
					return array();
				}
				return in_array( (string) $default, $choices, true ) ? (string) $default : '';
			case 'relationship':
				return array();
			case 'group':
				return $this->prepare_container( $field['sub_fields'] ?? array(), array(), $path, array(), true );
			case 'repeater':
			case 'flexible_content':
				return array();
		}
		throw new CaseError( CaseError::UNSUPPORTED_FIELD_TYPE, '未対応のフィールド型を含む行は作れません', array( 'path' => $path, 'type' => $type ) );
	}

	private function is_empty_raw( mixed $raw ): bool {
		if ( is_array( $raw ) ) {
			foreach ( $raw as $k => $v ) {
				if ( 'acf_fc_layout' === $k ) {
					return false;
				}
				if ( ! $this->is_empty_raw( $v ) ) {
					return false;
				}
			}
			return true;
		}
		return null === $raw || '' === $raw || 0 === $raw || false === $raw;
	}

	/**
	 * ACF の conditional_logic を評価する（==, !=, ==empty, !=empty のみ対応）。
	 *
	 * @param array<string,mixed>               $field
	 * @param array<string,mixed>               $scope field key => raw
	 * @param array<string,array<string,mixed>> $siblings name => field（既定値の参照用）
	 */
	private function visible( array $field, array $scope, array $siblings ): bool {
		$logic = $field['conditional_logic'] ?? 0;
		if ( empty( $logic ) || ! is_array( $logic ) ) {
			return true;
		}
		$defaults = array();
		foreach ( $siblings as $sibling ) {
			if ( array_key_exists( 'default_value', $sibling ) ) {
				$defaults[ $sibling['key'] ] = $sibling['default_value'];
			}
		}
		foreach ( $logic as $and ) {
			$ok = true;
			foreach ( (array) $and as $rule ) {
				$key    = (string) ( $rule['field'] ?? '' );
				$actual = array_key_exists( $key, $scope ) ? $scope[ $key ] : ( $defaults[ $key ] ?? null );
				$expect = (string) ( $rule['value'] ?? '' );
				switch ( $rule['operator'] ?? '' ) {
					case '==':
						$match = is_array( $actual ) ? in_array( $expect, array_map( 'strval', $actual ), true ) : ( (string) $actual === $expect );
						break;
					case '!=':
						$match = ! ( is_array( $actual ) ? in_array( $expect, array_map( 'strval', $actual ), true ) : ( (string) $actual === $expect ) );
						break;
					case '==empty':
						$match = $this->is_empty_raw( $actual );
						break;
					case '!=empty':
						$match = ! $this->is_empty_raw( $actual );
						break;
					default:
						throw new CaseError( CaseError::UNSUPPORTED_FIELD_TYPE, '未対応の条件分岐の演算子です', array( 'field' => (string) ( $field['name'] ?? '' ), 'operator' => (string) ( $rule['operator'] ?? '' ) ) );
				}
				if ( ! $match ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private function check_related_post( array $field, int $id, string $path ): void {
		$post = get_post( $id );
		if ( ! $post ) {
			throw new CaseError( CaseError::INVALID_VALUE, '関連付け先の投稿が存在しません', array( 'path' => $path, 'post_id' => $id ) );
		}
		$types = array_filter( (array) ( $field['post_type'] ?? array() ) );
		if ( $types && ! in_array( $post->post_type, $types, true ) ) {
			throw new CaseError( CaseError::INVALID_VALUE, '関連付けできない投稿タイプです', array( 'path' => $path, 'post_id' => $id ) );
		}
		if ( 'publish' !== $post->post_status ) {
			throw new CaseError( CaseError::INVALID_VALUE, '関連付け先が公開中ではありません', array( 'path' => $path, 'post_id' => $id ) );
		}
		$taxonomies = array_filter( (array) ( $field['taxonomy'] ?? array() ) );
		if ( $taxonomies ) {
			$ok = false;
			foreach ( $taxonomies as $tax_term ) {
				$parts = explode( ':', (string) $tax_term, 2 );
				if ( 2 === count( $parts ) && has_term( $parts[1], $parts[0], $id ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				throw new CaseError( CaseError::INVALID_VALUE, '関連付けの条件（タクソノミー）に合いません', array( 'path' => $path, 'post_id' => $id ) );
			}
		}
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private function check_mime( array $field, int $id, string $path ): void {
		$allowed = array_filter( array_map( 'trim', explode( ',', strtolower( (string) ( $field['mime_types'] ?? '' ) ) ) ) );
		if ( ! $allowed ) {
			return;
		}
		$file = (string) get_attached_file( $id );
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed, true ) ) {
			throw new CaseError( CaseError::INVALID_VALUE, '許可されていない画像形式です', array( 'path' => $path, 'attachment_id' => $id ) );
		}
	}

	private function check_count( int $count, mixed $min, mixed $max, string $path ): void {
		$min = (int) $min;
		$max = (int) $max;
		if ( $min > 0 && $count < $min ) {
			throw new CaseError( CaseError::INVALID_VALUE, '件数が下限未満です', array( 'path' => $path, 'min' => $min, 'count' => $count ) );
		}
		if ( $max > 0 && $count > $max ) {
			throw new CaseError( CaseError::INVALID_VALUE, '件数が上限を超えています', array( 'path' => $path, 'max' => $max, 'count' => $count ) );
		}
	}

	private function join( string $path, string $name ): string {
		return '' === $path ? $name : "{$path}.{$name}";
	}
}
