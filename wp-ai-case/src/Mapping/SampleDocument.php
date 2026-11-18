<?php
/**
 * マッピングの構造検証に使う、架空の CaseDocument（すべての論理ノードを通るように作る）。
 *
 * 画像・関連先のIDは架空の番号で、実在確認をしない検証（FieldAdapterRegistry の verify_references=false）専用。
 * 実在する顧客情報・画像は一切含めない。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Mapping;

defined( 'ABSPATH' ) || exit;

final class SampleDocument {

	/**
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$fact  = static fn( string $v ) => array(
			'value'              => $v,
			'status'             => 'provided',
			'source'             => 'user_input',
			'evidence_image_ids' => array(),
		);
		$image = static fn( int $id, string $phase ) => array(
			'attachment_id'     => $id,
			'original_position' => $id - 1,
			'phase'             => $phase,
			'description'       => "sample-{$phase}-{$id}",
			'caption'           => "sample caption {$id}",
			'related_image_ids' => array(),
			'included'          => true,
			'uncertain'         => false,
		);
		$section = static fn( string $node, string $kind, array $ids ) => array(
			'node_id'            => $node,
			'kind'               => $kind,
			'heading'            => "sample heading {$node}",
			'body'               => "sample body {$node}",
			'image_ids'          => $ids,
			'evidence_image_ids' => $ids,
		);
		return array(
			'document_id'       => '00000000-0000-4000-8000-0000000000a1',
			'source_request_id' => '00000000-0000-4000-8000-0000000000a2',
			'post_type'         => 'works',
			'title'             => 'sample title',
			'summary'           => 'sample summary',
			'facts'             => array(
				'overview'       => $fact( 'sample overview' ),
				'client_request' => $fact( 'sample request' ),
				'work_done'      => $fact( 'sample work' ),
				'area'           => $fact( 'sample area' ),
				'building_type'  => $fact( 'sample building' ),
				'duration'       => $fact( 'sample duration' ),
				'cost'           => $fact( 'sample cost' ),
				'materials'      => $fact( 'sample materials' ),
				'floor_plan'     => $fact( 'sample floor plan' ),
				'site_area'      => $fact( 'sample site area' ),
			),
			'images'            => array(
				$image( 1, 'before' ),
				$image( 2, 'before' ),
				$image( 3, 'during' ),
				$image( 4, 'after' ),
				$image( 5, 'after' ),
				$image( 6, 'unknown' ),
			),
			'sections'          => array(
				$section( 'intro', 'introduction', array() ),
				$section( 'before', 'before', array( 1, 2 ) ),
				$section( 'during', 'during', array( 3 ) ),
				$section( 'after', 'after', array( 4, 5 ) ),
				$section( 'compare', 'comparison', array( 1, 4 ) ),
				$section( 'feature', 'feature', array( 6 ) ),
			),
			'layout_intent'     => array( 'hero' => 'auto' ),
			'seo'               => array(
				'title'       => 'sample seo title',
				'description' => 'sample seo description',
				'h1'          => 'sample h1',
				'keywords'    => 'sample,keywords',
			),
			'status'            => 'proposal',
			'relations'         => array(
				'customer_voice_post_ids' => array( 11 ),
				'staff'                   => array(
					array(
						'post_id' => 12,
						'comment' => 'sample staff comment',
					),
				),
				'supervisor_post_ids'     => array( 12 ),
			),
		);
	}
}
