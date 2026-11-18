<?php
/**
 * この機能で保存した下書きの「保存時の状態」を記録し、後から AI で修正するときに使う。
 *
 * 記録するもの（投稿メタ。値そのものではなく指紋＝SHA-256）：
 *   - built：テンプレートが作った論理名ごとの値（ACFに依存しない）の指紋。修正でどこが変わるかの判定に使う。
 *   - db   ：保存直後のACFの値（現在のフィールド定義で正規化）の指紋。保存後に人が編集画面で
 *            変えたかどうかの判定に使う。人が変えた部分に重なる修正は保存しない（上書きしない）。
 *   - 文書（CaseDocument）と版番号。同じ下書きを2つの画面で同時に直したときの後勝ちを防ぐ。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Draft;

use WpAiCase\Acf\FieldAdapterRegistry;
use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Mapping\ShipWorksTemplateV11;

defined( 'ABSPATH' ) || exit;

final class DraftState {

	public const META_STATE = '_wp_ai_case_state';
	public const META_DOC   = '_wp_ai_case_document';
	public const META_VER   = '_wp_ai_case_version';
	public const TITLE_SLOT = '@post_title';

	/** 論理名 → 画面での呼び名（人が編集した部分の案内に使う）。 */
	public const SLOT_LABELS = array(
		self::TITLE_SLOT        => 'タイトル',
		'FV_Layout'             => 'トップの写真レイアウト',
		'EntryData_CatchImg'    => 'トップの写真（1枚）',
		'FV_Layout_Gallery'     => 'トップの写真（ギャラリー）',
		'FV_Layout_BeforeAfter' => 'トップの写真（ビフォーアフター）',
		'EntryData_CatchTxt'    => 'コンセプト（見出し）',
		'EntryData_Description' => 'コンセプト説明',
		'Request'               => 'お客様のご要望',
		'Proposal'              => '実施した工事・工夫',
		'EntryData_Commodity'   => '使用商材・建材',
		'Outline'               => '施工概要',
		'Format'                => '本文と写真',
		'EntryData_RelatedData' => 'お客様の声',
		'entrydata_supervision' => '監修者',
		'EntryData_Staff'       => '担当スタッフ',
		'title'                 => 'SEO：タイトル',
		'h1'                    => 'SEO：H1',
		'keywords'              => 'SEO：キーワード',
		'description'           => 'SEO：説明文',
	);

	/**
	 * 保存した状態を記録する（新規作成・修正の保存の直後に呼ぶ）。
	 *
	 * @param array<string,mixed>            $doc
	 * @param array<string,mixed>            $mapping この保存に使った版
	 * @param array<int,array<string,mixed>> $groups  投稿に適用されている現在のACF定義
	 * @return array<string,mixed> 記録した状態
	 */
	public static function record( int $post_id, array $doc, array $mapping, array $groups, int $version, int $user_id ): array {
		Snapshot::flush( $post_id );
		$built = ( new ShipWorksTemplateV11() )->build( $doc );
		$post  = get_post( $post_id );
		$state = array(
			'version'           => $version,
			'mapping_id'        => (string) $mapping['mapping_id'],
			'mapping_version'   => (int) $mapping['mapping_version'],
			'schema_sha256'     => (string) $mapping['schema_sha256'],
			'context_hash'      => (string) $mapping['context_hash'],
			'built'             => self::built_prints( $built ),
			'db'                => self::db_prints( $post_id, self::slot_fields( $mapping, $groups ) ),
			'post_modified_gmt' => $post ? (string) $post->post_modified_gmt : '',
			'saved_at'          => gmdate( 'c' ),
			'user_id'           => $user_id,
		);
		update_post_meta( $post_id, self::META_DOC, wp_slash( (string) wp_json_encode( $doc, JSON_UNESCAPED_UNICODE ) ) );
		update_post_meta( $post_id, self::META_STATE, wp_slash( $state ) );
		update_post_meta( $post_id, self::META_VER, (string) $version );
		return $state;
	}

	/**
	 * いまの版番号（この機能で保存していなければ 0）。
	 */
	public static function version( int $post_id ): int {
		wp_cache_delete( $post_id, 'post_meta' );
		return (int) get_post_meta( $post_id, self::META_VER, true );
	}

	/**
	 * 版を from → from+1 に進めて保存の権利を取る（DB上で from のときだけ成功する）。
	 */
	public static function claim( int $post_id, int $from ): bool {
		wp_cache_delete( $post_id, 'post_meta' );
		return (bool) update_post_meta( $post_id, self::META_VER, (string) ( $from + 1 ), (string) $from );
	}

	/**
	 * 保存しなかった（失敗・変更なし）ときに、取った版を戻す。
	 */
	public static function release( int $post_id, int $from ): void {
		wp_cache_delete( $post_id, 'post_meta' );
		update_post_meta( $post_id, self::META_VER, (string) $from, (string) ( $from + 1 ) );
	}

	/**
	 * @return array{state:array<string,mixed>,doc:array<string,mixed>}|null この機能で保存した下書きでなければ null
	 */
	public static function load( int $post_id ): ?array {
		wp_cache_delete( $post_id, 'post_meta' );
		$state = get_post_meta( $post_id, self::META_STATE, true );
		$doc   = json_decode( (string) get_post_meta( $post_id, self::META_DOC, true ), true );
		if ( ! is_array( $state ) || ! is_array( $doc ) || empty( $state['version'] ) || self::version( $post_id ) < 1 ) {
			return null;
		}
		return array(
			'state' => $state,
			'doc'   => $doc,
		);
	}

	/**
	 * テンプレートの出力（論理名）の指紋。値のない論理名は null。
	 *
	 * @param array{post:array<string,string>,fields:array<string,mixed>} $built
	 * @return array<string,string|null>
	 */
	public static function built_prints( array $built ): array {
		$out = array( self::TITLE_SLOT => self::print( (string) $built['post']['post_title'] ) );
		foreach ( array_keys( ShipWorksTemplateV11::logical_schema() ) as $slot ) {
			$out[ $slot ] = array_key_exists( $slot, $built['fields'] ) ? self::print( $built['fields'][ $slot ] ) : null;
		}
		return $out;
	}

	/**
	 * 論理名 → 現在のACFの最上位フィールド定義（マッピングの結び付けで決まる）。
	 *
	 * @param array<string,mixed>            $mapping
	 * @param array<int,array<string,mixed>> $groups
	 * @return array<string,array<string,mixed>>
	 */
	public static function slot_fields( array $mapping, array $groups ): array {
		$by_key = array();
		foreach ( SchemaInspector::top_level_index( $groups ) as $field ) {
			$by_key[ (string) $field['key'] ] = $field;
		}
		$out = array();
		foreach ( (array) ( $mapping['document']['bindings'] ?? array() ) as $binding ) {
			$slot = (string) $binding['semantic_slot'];
			if ( str_contains( $slot, '/' ) ) {
				continue; // 最上位だけ
			}
			if ( isset( $by_key[ (string) $binding['field_key'] ] ) ) {
				$out[ $slot ] = $by_key[ (string) $binding['field_key'] ];
			}
		}
		return $out;
	}

	/**
	 * いまDBにある値の指紋（論理名ごと）。タイトルは投稿のタイトル。
	 *
	 * @param array<string,array<string,mixed>> $slot_fields
	 * @param string[]|null                     $only 指定した論理名だけ
	 * @return array<string,string>
	 */
	public static function db_prints( int $post_id, array $slot_fields, ?array $only = null ): array {
		Snapshot::flush( $post_id );
		$registry = new FieldAdapterRegistry( false );
		$post     = get_post( $post_id );
		$out      = array();
		if ( null === $only || in_array( self::TITLE_SLOT, $only, true ) ) {
			$out[ self::TITLE_SLOT ] = self::print( $post ? (string) $post->post_title : '' );
		}
		foreach ( $slot_fields as $slot => $field ) {
			if ( null !== $only && ! in_array( $slot, $only, true ) ) {
				continue;
			}
			$out[ $slot ] = self::print( $registry->normalize( $field, get_field( (string) $field['key'], $post_id, false ) ) );
		}
		return $out;
	}

	/**
	 * 保存後に、人が編集画面で変えた部分（論理名）。
	 *
	 * @param array<string,mixed>            $state
	 * @param array<string,mixed>            $mapping
	 * @param array<int,array<string,mixed>> $groups
	 * @param string[]|null                  $only
	 * @return string[]
	 */
	public static function edited_slots( int $post_id, array $state, array $mapping, array $groups, ?array $only = null ): array {
		$now     = self::db_prints( $post_id, self::slot_fields( $mapping, $groups ), $only );
		$changed = array();
		foreach ( $now as $slot => $print ) {
			$saved = $state['db'][ $slot ] ?? null;
			if ( null === $saved || ! hash_equals( (string) $saved, $print ) ) {
				$changed[] = $slot;
			}
		}
		return $changed;
	}

	/**
	 * @param string[] $slots
	 * @return string[]
	 */
	public static function labels( array $slots ): array {
		return array_values( array_unique( array_map( static fn( $s ) => self::SLOT_LABELS[ $s ] ?? (string) $s, $slots ) ) );
	}

	private static function print( mixed $value ): string {
		return hash( 'sha256', SchemaInspector::stable_json( $value ) );
	}
}
