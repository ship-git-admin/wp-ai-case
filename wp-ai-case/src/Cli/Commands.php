<?php
/**
 * WP-CLI: 読み取り専用の確認コマンド。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Cli;

use WpAiCase\Acf\FieldAdapterRegistry;
use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * 施工事例AI（wp-ai-case）の確認コマンド。
 */
final class Commands {

	/**
	 * 施工事例に適用されるACF定義の要約と schema hash を表示する（書き込みなし）。
	 *
	 * ## OPTIONS
	 *
	 * [--post=<id>]
	 * : 既存の投稿の条件で判定する。省略時は新規投稿（タームなし）の条件。
	 *
	 * [--format=<format>]
	 * : table か json。
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function schema( array $args, array $assoc ): void {
		$inspector = new SchemaInspector();
		$post_id   = (int) ( $assoc['post'] ?? 0 );
		$context   = $post_id > 0
			? $inspector->context( Plugin::POST_TYPE, $post_id )
			: $inspector->context( Plugin::POST_TYPE, 0, array() );
		$groups    = $inspector->groups( $context );

		$types = array();
		$walk  = static function ( array $fields ) use ( &$walk, &$types ): void {
			foreach ( $fields as $field ) {
				$type           = (string) $field['type'];
				$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
				$walk( $field['sub_fields'] ?? array() );
				foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
					$walk( $layout['sub_fields'] ?? array() );
				}
			}
		};
		foreach ( $groups as $group ) {
			$walk( $group['fields'] ?? array() );
		}
		ksort( $types );

		$summary = array(
			'post_type'      => Plugin::POST_TYPE,
			'post_id'        => $post_id,
			'groups'         => array_map( static fn( $g ) => (string) $g['title'], $groups ),
			'schema_sha256'  => $inspector->schema_hash( $groups ),
			'context_sha256' => $inspector->context_hash( $context, $groups ),
			'field_types'    => array(),
		);
		foreach ( $types as $type => $count ) {
			$summary['field_types'][] = array(
				'type'    => $type,
				'count'   => $count,
				'support' => FieldAdapterRegistry::support_status( $type ),
			);
		}

		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			return;
		}
		\WP_CLI::line( 'groups: ' . implode( ' / ', $summary['groups'] ) );
		\WP_CLI::line( 'schema_sha256: ' . $summary['schema_sha256'] );
		\WP_CLI::line( 'context_sha256: ' . $summary['context_sha256'] );
		\WP_CLI\Utils\format_items( 'table', $summary['field_types'], array( 'type', 'count', 'support' ) );
	}

	/**
	 * 定期の後片付けを実行する（ジョブの期限切れ・新規作成枠の回収・保存の中断からの復帰・古い記録の削除）。外部通信なし。
	 *
	 * WP-Cron が Basic認証などで動かない環境では、サーバーの cron から毎時実行する。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function maintenance( array $args, array $assoc ): void {
		$r = \WpAiCase\Support\Maintenance::run( 'cli' );
		\WP_CLI::success( (string) wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * 今月の新規作成枠と、AIの使用量（推定費用）を表示する。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function usage( array $args, array $assoc ): void {
		$out = array(
			'quota' => ( new \WpAiCase\Quota\QuotaService() )->summary(),
			'cost'  => ( new \WpAiCase\Quota\CostGuard() )->summary(),
		);
		\WP_CLI::line( (string) wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
	}
}
