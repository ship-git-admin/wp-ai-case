<?php
/**
 * WP-CLI: ACFマッピングの確認・提案・承認（管理者用）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Cli;

use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Mapping\MappingService;
use WpAiCase\Plugin;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

/**
 * ACFマッピングの確認・提案・承認・巻き戻し。
 *
 * 文脈の指定（共通）：
 *   --post=<id>            既存の投稿の条件で判定
 *   --terms=<tax:slug,...> 新規投稿に付ける予定のターム（例 sys_tag:old_layout）
 *   どちらもなければ、新規投稿（タームなし）の条件。
 */
final class MappingCommand {

	/**
	 * 文脈の状態（承認済みの版・構造変更の有無・表示確認の状態）を表示する。
	 *
	 * ## OPTIONS
	 *
	 * [--post=<id>]
	 * : 既存の投稿の条件
	 *
	 * [--terms=<terms>]
	 * : 新規投稿に付ける予定のターム（taxonomy:slug,...）
	 *
	 * [--format=<format>]
	 * : table か json
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function status( array $args, array $assoc ): void {
		$this->run(
			function () use ( $assoc ) {
				$s = ( new MappingService() )->inspect( $this->context( $assoc ), get_current_user_id(), true );
				unset( $s['row'] );
				return $s;
			},
			$assoc
		);
	}

	/**
	 * 現在の定義に合わせてマッピングを提案し、架空データで構造検証まで行う（管理者）。
	 *
	 * ## OPTIONS
	 *
	 * [--post=<id>]
	 * : 既存の投稿の条件
	 *
	 * [--terms=<terms>]
	 * : 新規投稿に付ける予定のターム（taxonomy:slug,...）
	 *
	 * [--format=<format>]
	 * : table か json
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function propose( array $args, array $assoc ): void {
		$this->run(
			function () use ( $assoc ) {
				$r = ( new MappingService() )->propose( $this->context( $assoc ), get_current_user_id() );
				return array(
					'state'           => $r['state'],
					'classification'  => $r['classification'],
					'mapping_id'      => $r['row']['mapping_id'],
					'mapping_version' => $r['row']['mapping_version'],
					'status'          => $r['row']['status'],
					'bindings'        => count( (array) $r['row']['document']['bindings'] ),
					'problems'        => $r['problems'],
				);
			},
			$assoc
		);
	}

	/**
	 * すべての版の一覧。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function list( array $args, array $assoc ): void {
		$rows = array();
		foreach ( ( new MappingService() )->repository()->all() as $row ) {
			$rows[] = array(
				'mapping_id' => $row['mapping_id'],
				'version'    => $row['mapping_version'],
				'status'     => $row['status'],
				'context'    => substr( (string) $row['context_hash'], 0, 12 ),
				'schema'     => substr( (string) $row['schema_sha256'], 0, 12 ),
				'approved'   => isset( $row['document']['approval'] ) ? (string) $row['document']['approval']['approved_at'] : '',
				'updated_at' => $row['updated_at'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'mapping_id', 'version', 'status', 'context', 'schema', 'approved', 'updated_at' ) );
	}

	/**
	 * 1つの版の内容（契約 acf_mapping の文書）を表示する。
	 *
	 * ## OPTIONS
	 *
	 * <mapping_id>
	 * : マッピングID（uuid）
	 *
	 * <version>
	 * : 版の番号
	 *
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function show( array $args, array $assoc ): void {
		$row = $this->row( $args );
		\WP_CLI::line( (string) wp_json_encode( $row['document'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
	}

	/**
	 * 状態遷移の履歴（誰が・いつ・何を）。
	 *
	 * ## OPTIONS
	 *
	 * <mapping_id>
	 * : マッピングID（uuid）
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function history( array $args, array $assoc ): void {
		$rows = array();
		foreach ( ( new MappingService() )->repository()->events( (string) $args[0] ) as $e ) {
			$rows[] = array(
				'v'      => $e['mapping_version'],
				'at'     => $e['occurred_at'],
				'event'  => $e['event_type'],
				'from'   => (string) $e['from_status'],
				'to'     => $e['to_status'],
				'actor'  => (string) $e['actor_user_id'],
				'reason' => mb_substr( (string) $e['reason'], 0, 80 ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'v', 'at', 'event', 'from', 'to', 'actor', 'reason' ) );
	}

	/**
	 * ステージングで実表示（テーマでの見た目）を確認したことを記録する（管理者）。
	 *
	 * ## OPTIONS
	 *
	 * <mapping_id>
	 * : マッピングID（uuid）
	 *
	 * <version>
	 * : 版の番号
	 *
	 * --evidence=<text>
	 * : 確認内容・証跡の参照（秘密やURLのトークンは書かない）
	 *
	 * [--post=<id>]
	 * : 既存の投稿の条件
	 *
	 * [--terms=<terms>]
	 * : 新規投稿に付ける予定のターム（taxonomy:slug,...）
	 *
	 * @subcommand confirm-display
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function confirm_display( array $args, array $assoc ): void {
		$this->run( fn() => $this->brief( ( new MappingService() )->confirm_display( $this->row( $args ), $this->context( $assoc ), get_current_user_id(), (string) $assoc['evidence'] ) ), $assoc );
	}

	/**
	 * 管理者が承認し、この版で生成・保存を再開する。
	 *
	 * ## OPTIONS
	 *
	 * <mapping_id>
	 * : マッピングID（uuid）
	 *
	 * <version>
	 * : 版の番号
	 *
	 * --reason=<text>
	 * : 理由
	 *
	 * [--post=<id>]
	 * : 既存の投稿の条件
	 *
	 * [--terms=<terms>]
	 * : 新規投稿に付ける予定のターム（taxonomy:slug,...）
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function approve( array $args, array $assoc ): void {
		$this->run( fn() => $this->brief( ( new MappingService() )->approve( $this->row( $args ), $this->context( $assoc ), get_current_user_id(), (string) $assoc['reason'] ) ), $assoc );
	}

	/**
	 * 版を退役させる（取り消し）。
	 *
	 * ## OPTIONS
	 *
	 * <mapping_id>
	 * : マッピングID（uuid）
	 *
	 * <version>
	 * : 版の番号
	 *
	 * --reason=<text>
	 * : 理由
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function retire( array $args, array $assoc ): void {
		$this->run( fn() => $this->brief( ( new MappingService() )->retire( $this->row( $args ), get_current_user_id(), (string) $assoc['reason'] ) ), $assoc );
	}

	/**
	 * 以前に承認された版へ戻す（定義が当時と同じ場合だけ。承認は別途必要）。
	 *
	 * ## OPTIONS
	 *
	 * <mapping_id>
	 * : マッピングID（uuid）
	 *
	 * <version>
	 * : 版の番号
	 *
	 * --reason=<text>
	 * : 理由
	 *
	 * [--post=<id>]
	 * : 既存の投稿の条件
	 *
	 * [--terms=<terms>]
	 * : 新規投稿に付ける予定のターム（taxonomy:slug,...）
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function rollback( array $args, array $assoc ): void {
		$this->run( fn() => $this->brief( ( new MappingService() )->rollback( $this->row( $args ), $this->context( $assoc ), get_current_user_id(), (string) $assoc['reason'] ) ), $assoc );
	}

	/**
	 * --post / --terms から文脈を作る。
	 *
	 * @param array<string,string> $assoc
	 * @return array{post_type:string,post_id:int,post_terms:array<string,int[]>}
	 */
	public static function context( array $assoc ): array {
		$inspector = new SchemaInspector();
		if ( ! empty( $assoc['post'] ) ) {
			return $inspector->context( Plugin::POST_TYPE, (int) $assoc['post'] );
		}
		return $inspector->context( Plugin::POST_TYPE, 0, self::terms( (string) ( $assoc['terms'] ?? '' ) ) );
	}

	/**
	 * "sys_tag:old_layout,works_cat:bath" → ['sys_tag' => [id], ...]
	 *
	 * @return array<string,int[]>
	 */
	public static function terms( string $spec ): array {
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $spec ) ) ) as $pair ) {
			[ $taxonomy, $slug ] = array_pad( explode( ':', $pair, 2 ), 2, '' );
			$term                = get_term_by( 'slug', $slug, $taxonomy );
			if ( ! $term ) {
				\WP_CLI::error( "タームが見つかりません: {$pair}" );
			}
			$out[ $taxonomy ][] = (int) $term->term_id;
		}
		return $out;
	}

	/**
	 * @param array<int,string> $args
	 * @return array<string,mixed>
	 */
	private function row( array $args ): array {
		$row = ( new MappingService() )->repository()->get( (string) ( $args[0] ?? '' ), (int) ( $args[1] ?? 0 ) );
		if ( ! $row ) {
			\WP_CLI::error( 'マッピングの版が見つかりません' );
		}
		return $row;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function brief( array $row ): array {
		return array(
			'mapping_id'      => $row['mapping_id'],
			'mapping_version' => $row['mapping_version'],
			'status'          => $row['status'],
		);
	}

	/**
	 * @param callable():array<string,mixed> $fn
	 * @param array<string,string>           $assoc
	 */
	private function run( callable $fn, array $assoc ): void {
		try {
			$out = $fn();
		} catch ( CaseError $e ) {
			\WP_CLI::line( (string) wp_json_encode( array( 'ok' => false ) + $e->to_array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::line( (string) wp_json_encode( array( 'ok' => true ) + $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
	}
}
