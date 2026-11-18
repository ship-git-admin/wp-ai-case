<?php
/**
 * マッピング候補のステージング検証（配布版でも使える。管理画面「マッピング」から使う）。
 *
 *   1) 検証用の画像（GDで作る単色の見本。実在の写真は使わない）を用意する
 *   2) 見本の文書を、検証中の版で「検証用の下書き」として保存する（トップの写真 3パターン分）
 *   3) 保存 → 読み戻しが一致したら、マッピングに「ステージング保存・読み戻し合格」を記録する
 *   4) 管理者がプレビューで実表示を確認し、確認済みにする（MappingService::confirm_display）
 *
 * 検証用の下書きは下書きのまま（公開しない）。印（_wp_ai_case_validation）を付け、管理画面から削除できる。
 * 外部（AI）への通信はしない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Mapping;

use WpAiCase\Draft\DraftWriter;
use WpAiCase\Plugin;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class StagingValidator {

	public const MARK          = '_wp_ai_case_validation';
	public const IMAGE_MARK    = '_wp_ai_case_validation_image';
	public const IMAGES_OPTION = 'wp_ai_case_validation_images';
	public const HEROES        = array( 'before_after', 'gallery', 'single' );

	/** 見本の画像（SampleDocument の架空ID 1〜6 に対応）。 */
	private const IMAGES = array(
		1 => array( 'before1', 'BEFORE 1', array( 140, 120, 100 ) ),
		2 => array( 'before2', 'BEFORE 2', array( 120, 104, 88 ) ),
		3 => array( 'during1', 'DURING 1', array( 90, 110, 130 ) ),
		4 => array( 'after1', 'AFTER 1', array( 70, 130, 90 ) ),
		5 => array( 'after2', 'AFTER 2', array( 60, 115, 80 ) ),
		6 => array( 'other1', 'OTHER 1', array( 130, 130, 130 ) ),
	);

	public const HERO_LABELS = array(
		'before_after' => 'ビフォーアフター',
		'gallery'      => 'ギャラリー',
		'single'       => '1枚',
	);

	private MappingService $service;

	public function __construct( ?MappingService $service = null ) {
		$this->service = $service ?? new MappingService();
	}

	/**
	 * 検証用の下書きを作って保存・読み戻しを確かめる。構造検証済み（validated_local）の版なら合格を記録する。
	 * 承認済みの版では、テーマ変更後の表示の再確認用に下書きだけを作る（状態は変えない）。
	 *
	 * @param array<string,mixed>                                                $row
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 * @return array{row:array<string,mixed>,drafts:array<string,int>,fields:int}
	 */
	public function run( array $row, array $context, int $actor ): array {
		if ( $actor < 1 || ! user_can( $actor, 'manage_options' ) ) {
			throw new CaseError( CaseError::FORBIDDEN, 'マッピングの操作は管理者だけが行えます' );
		}
		if ( ! in_array( $row['status'], array( 'validated_local', 'approved_active' ), true ) ) {
			throw new CaseError( CaseError::INVALID_TRANSITION, '構造検証が済んだ版（または承認済みの版）だけ、ステージングで検証できます' );
		}
		$key = $row['mapping_id'] . ':' . $row['mapping_version'];
		$this->cleanup( $key );

		$doc     = $this->document( $this->images() );
		$created = array();
		$fields  = 0;
		try {
			foreach ( self::HEROES as $hero ) {
				$doc['title'] = sprintf( '【検証用】マッピング v%d（トップの写真：%s）', (int) $row['mapping_version'], self::HERO_LABELS[ $hero ] );
				$result       = ( new DraftWriter() )->create_from_document(
					$doc,
					$actor,
					array(
						'mapping'    => $row,
						'hero'       => $hero,
						'terms'      => $context['post_terms'],
						'extra_meta' => array(
							DraftWriter::POC_MARK => '1',
							self::MARK            => $key,
							self::MARK . '_hero'  => $hero,
						),
					)
				);
				$created[ $hero ] = (int) $result['post_id'];
				$fields           = max( $fields, count( $result['fields_written'] ) );
			}
		} catch ( CaseError $e ) {
			foreach ( $created as $id ) {
				$this->delete_draft( $id );
			}
			throw $e;
		}

		if ( 'validated_local' === $row['status'] ) {
			$evidence = sprintf( 'validation-drafts:%s readback=ok fields=%d', implode( ',', $created ), $fields );
			$row      = $this->service->record_staging( $row, $context, $actor, $evidence );
		}
		return array(
			'row'    => $row,
			'drafts' => $created,
			'fields' => $fields,
		);
	}

	/**
	 * この版の検証用の下書き（トップの写真の型 => 投稿ID）。
	 *
	 * @return array<string,int>
	 */
	public function drafts( string $mapping_id, int $version ): array {
		$out = array();
		foreach ( $this->marked( $mapping_id . ':' . $version ) as $id ) {
			$out[ (string) get_post_meta( $id, self::MARK . '_hero', true ) ] = $id;
		}
		// 表示の順をそろえる（ビフォーアフター → ギャラリー → 1枚）。
		$order = array_flip( self::HEROES );
		uksort( $out, static fn( $a, $b ) => ( $order[ $a ] ?? 9 ) <=> ( $order[ $b ] ?? 9 ) );
		return $out;
	}

	/**
	 * 検証用の下書きを削除する（key を省くと全部）。全部消したときは検証用の画像も消す。
	 *
	 * @return int 削除した下書きの数
	 */
	public function cleanup( ?string $key = null ): int {
		$n = 0;
		foreach ( $this->marked( $key ) as $id ) {
			if ( $this->delete_draft( $id ) ) {
				++$n;
			}
		}
		if ( null === $key ) {
			foreach ( $this->stored_images() as $id ) {
				if ( '1' === (string) get_post_meta( $id, self::IMAGE_MARK, true ) ) {
					wp_delete_attachment( $id, true );
				}
			}
			delete_option( self::IMAGES_OPTION );
		}
		return $n;
	}

	/**
	 * 見本の文書（SampleDocument）の架空IDを、検証用の画像・公開中の関連先に置き換える。
	 *
	 * @param array<int,int> $images 架空ID => attachment ID
	 * @return array<string,mixed>
	 */
	private function document( array $images ): array {
		$doc = SampleDocument::get();
		foreach ( $doc['images'] as $i => $img ) {
			$doc['images'][ $i ]['attachment_id'] = $images[ (int) $img['attachment_id'] ];
			$doc['images'][ $i ]['description']   = '検証用の見本画像 ' . self::IMAGES[ (int) $img['attachment_id'] ][1];
			$doc['images'][ $i ]['caption']       = '検証用のキャプション ' . ( $i + 1 );
		}
		foreach ( $doc['sections'] as $i => $section ) {
			$doc['sections'][ $i ]['image_ids']          = array_map( static fn( $id ) => $images[ (int) $id ], $section['image_ids'] );
			$doc['sections'][ $i ]['evidence_image_ids'] = array_map( static fn( $id ) => $images[ (int) $id ], $section['evidence_image_ids'] );
			$doc['sections'][ $i ]['heading']            = '検証用の見出し（' . $section['node_id'] . '）';
			$doc['sections'][ $i ]['body']               = '検証用の本文です。マッピングの保存先と表示を確認するための見本で、実際の施工事例ではありません。';
		}
		foreach ( $doc['facts'] as $k => $fact ) {
			$doc['facts'][ $k ]['value'] = '検証用（' . $k . '）';
		}
		$doc['summary'] = '検証用の説明文です。';
		$doc['seo']     = array(
			'title'       => '検証用のSEOタイトル',
			'description' => '検証用のSEO説明文',
			'h1'          => '検証用のH1',
			'keywords'    => '検証用',
		);
		$voice = $this->first_published( 'voice' );
		$staff = $this->first_published( 'staff' );
		$doc['relations'] = array(
			'customer_voice_post_ids' => $voice ? array( $voice ) : array(),
			'staff'                   => $staff ? array( array( 'post_id' => $staff, 'comment' => '検証用のコメント' ) ) : array(),
			'supervisor_post_ids'     => $staff ? array( $staff ) : array(),
		);
		$doc['document_id']       = wp_generate_uuid4();
		$doc['source_request_id'] = wp_generate_uuid4();
		return $doc;
	}

	/**
	 * 検証用の画像をそろえる（無ければ GD で作ってメディアに登録）。
	 *
	 * @return array<int,int> 架空ID => attachment ID
	 */
	private function images(): array {
		$stored = $this->stored_images();
		$out    = array();
		foreach ( self::IMAGES as $fake => [ $label, $text, $rgb ] ) {
			$id = (int) ( $stored[ $label ] ?? 0 );
			if ( ! $id || get_post_type( $id ) !== 'attachment' ) {
				$id               = $this->create_image( $label, $text, $rgb );
				$stored[ $label ] = $id;
				update_option( self::IMAGES_OPTION, $stored, false );
			}
			$out[ $fake ] = $id;
		}
		return $out;
	}

	/**
	 * @param int[] $rgb
	 */
	private function create_image( string $label, string $text, array $rgb ): int {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagejpeg' ) ) {
			throw new CaseError( CaseError::WRITE_FAILED, '検証用の画像を作れません（サーバーの GD が使えません）' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$w   = 1200;
		$h   = 800;
		$img = imagecreatetruecolor( $w, $h );
		imagefill( $img, 0, 0, imagecolorallocate( $img, $rgb[0], $rgb[1], $rgb[2] ) );
		$line = imagecolorallocate( $img, min( 255, $rgb[0] + 30 ), min( 255, $rgb[1] + 30 ), min( 255, $rgb[2] + 30 ) );
		for ( $x = 0; $x < $w; $x += 60 ) {
			imageline( $img, $x, 0, $x, $h, $line );
		}
		$white = imagecolorallocate( $img, 255, 255, 255 );
		$scale = 6;
		$small = imagecreatetruecolor( 200, 40 );
		imagefill( $small, 0, 0, imagecolorallocate( $small, $rgb[0], $rgb[1], $rgb[2] ) );
		imagestring( $small, 5, 4, 4, $text, imagecolorallocate( $small, 255, 255, 255 ) );
		imagestring( $small, 3, 4, 24, 'VALIDATION SAMPLE', imagecolorallocate( $small, 255, 255, 255 ) );
		imagecopyresized( $img, $small, (int) ( ( $w - 200 * $scale / 2 ) / 2 ), (int) ( ( $h - 40 * $scale / 2 ) / 2 ), 0, 0, 200 * $scale / 2, 40 * $scale / 2, 200, 40 );
		imagestring( $img, 2, 10, $h - 20, 'WP AI Case - validation sample (not a real photo)', $white );

		$tmp = wp_tempnam( 'wpaic-validation-' . $label . '.jpg' );
		imagejpeg( $img, $tmp, 85 );
		imagedestroy( $img );
		imagedestroy( $small );
		$id = media_handle_sideload(
			array(
				'name'     => 'wpaic-validation-' . $label . '.jpg',
				'tmp_name' => $tmp,
			),
			0,
			'【検証用】' . $label
		);
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new CaseError( CaseError::WRITE_FAILED, '検証用の画像を登録できませんでした' );
		}
		update_post_meta( $id, self::IMAGE_MARK, '1' );
		update_post_meta( $id, '_wp_attachment_image_alt', '【検証用】' . $label );
		return (int) $id;
	}

	/**
	 * @return array<string,int>
	 */
	private function stored_images(): array {
		$map = get_option( self::IMAGES_OPTION, array() );
		return is_array( $map ) ? array_map( 'intval', $map ) : array();
	}

	/**
	 * @return int[]
	 */
	private function marked( ?string $key ): array {
		$args = array(
			'post_type'      => Plugin::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'meta_key'       => self::MARK, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		);
		if ( null !== $key ) {
			$args['meta_value'] = $key; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * 印の付いた下書きだけを削除する（公開・非公開などになっていたら消さない）。
	 */
	private function delete_draft( int $id ): bool {
		if ( 'draft' !== get_post_status( $id ) || '' === (string) get_post_meta( $id, self::MARK, true ) ) {
			return false;
		}
		return (bool) wp_delete_post( $id, true );
	}

	private function first_published( string $type ): int {
		$ids = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}
}
