<?php
/**
 * 試験用：適用グループを差し替えられる SchemaInspector。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev\SelfTest;

use WpAiCase\Acf\SchemaInspector;

defined( 'ABSPATH' ) || exit;

final class FakeInspector extends SchemaInspector {

	/** @var array<int,array<string,mixed>> */
	public array $fake_groups = array();

	/**
	 * @param array{post_type:string,post_id:int,post_terms:array<string,int[]>} $context
	 * @return array<int,array<string,mixed>>
	 */
	public function groups( array $context ): array {
		return $this->fake_groups;
	}
}
