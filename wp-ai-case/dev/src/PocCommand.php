<?php
/**
 * WP-CLI: C1-A 保存実証（開発専用・AIなし・合成データのみ）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev;

use WpAiCase\Acf\FieldAdapterRegistry;
use WpAiCase\Acf\SchemaInspector;
use WpAiCase\Domain\CaseDocumentValidator;
use WpAiCase\Draft\DraftWriter;
use WpAiCase\Draft\Snapshot;
use WpAiCase\Mapping\MappingService;
use WpAiCase\Mapping\ShipWorksTemplateV11;
use WpAiCase\Plugin;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

/**
 * 合成データで、施工事例の下書き保存・再読込・表示確認を行う。
 */
final class PocCommand {

	/**
	 * 合成画像をメディアに登録する（同じラベルは再登録しない）。
	 *
	 * ## OPTIONS
	 *
	 * --dir=<path>
	 * : 合成画像（*.jpg / *.png）を置いたディレクトリ。ファイル名（拡張子なし）がラベルになる。
	 *
	 * @subcommand seed-images
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function seed_images( array $args, array $assoc ): void {
		$this->require_user();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$dir   = rtrim( (string) $assoc['dir'], '/' );
		$files = array_merge( glob( "{$dir}/*.jpg" ) ?: array(), glob( "{$dir}/*.png" ) ?: array() );
		sort( $files );
		$map = Poc::images();
		foreach ( $files as $file ) {
			$label = pathinfo( $file, PATHINFO_FILENAME );
			if ( ! preg_match( '/^[a-z0-9_]+$/', $label ) ) {
				continue;
			}
			if ( ! empty( $map[ $label ] ) && get_post_type( $map[ $label ] ) === 'attachment' ) {
				\WP_CLI::line( "skip {$label} (#{$map[$label]})" );
				continue;
			}
			$tmp = wp_tempnam( basename( $file ) );
			copy( $file, $tmp );
			$id = media_handle_sideload(
				array(
					'name'     => 'wpaic-synthetic-' . basename( $file ),
					'tmp_name' => $tmp,
				),
				0,
				'【合成テスト】' . $label
			);
			if ( is_wp_error( $id ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				\WP_CLI::error( "画像を登録できません: {$label}" );
			}
			update_post_meta( $id, Poc::MARK, '1' );
			update_post_meta( $id, '_wp_attachment_image_alt', '【合成テスト】' . $label );
			$map[ $label ] = (int) $id;
			\WP_CLI::line( "added {$label} (#{$id})" );
		}
		update_option( Poc::IMAGES_OPTION, $map, false );
		\WP_CLI::success( count( $map ) . ' 件の合成画像を利用できます' );
	}

	/**
	 * 合成の CaseDocument から施工事例の下書きを作って保存し、読み戻しを確認する。
	 *
	 * 既定では、その文脈の承認済みマッピングを使う（無ければ AI-010 で止まる）。
	 * --mapping を付けると検証中の候補を使う（合成下書き専用）。
	 *
	 * ## OPTIONS
	 *
	 * [--fixture=<name>]
	 * : dev/fixtures/<name>.json
	 * ---
	 * default: poc_full
	 * ---
	 *
	 * [--hero=<hero>]
	 * : FVレイアウトを指定（single / gallery / before_after / auto）。
	 *
	 * [--test-context]
	 * : 構造変更テスト用のタームを付けた文脈で作る。
	 *
	 * [--mapping=<id:version>]
	 * : 検証中のマッピング候補を使う。
	 *
	 * [--voice=<id>]
	 * : お客様の声の投稿ID
	 *
	 * [--staff=<id>]
	 * : 担当スタッフの投稿ID
	 *
	 * [--supervisor=<id>]
	 * : 監修者の投稿ID
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function write( array $args, array $assoc ): void {
		$user_id = $this->require_user();
		try {
			$result = $this->create( $assoc, $user_id );
		} catch ( CaseError $e ) {
			\WP_CLI::line( (string) wp_json_encode( array( 'ok' => false ) + $e->to_array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::line( (string) wp_json_encode( array( 'ok' => true ) + $result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
	}

	/**
	 * マッピング候補をステージングで検証する：合成下書きを作って保存・読み戻しし、合格を記録する（管理者）。
	 * 実表示の確認は、この後 `poc render` / プレビューで行い、`mapping confirm-display` で記録する。
	 *
	 * ## OPTIONS
	 *
	 * <mapping_id>
	 * : マッピングID（uuid）
	 *
	 * <version>
	 * : 版の番号
	 *
	 * [--test-context]
	 * : 構造変更テスト用のタームを付けた文脈。
	 *
	 * [--hero=<hero>]
	 * : FVレイアウト
	 * ---
	 * default: before_after
	 * ---
	 *
	 * @subcommand verify-mapping
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function verify_mapping( array $args, array $assoc ): void {
		$user_id = $this->require_user();
		try {
			$assoc['mapping'] = $args[0] . ':' . $args[1];
			$result           = $this->create( $assoc, $user_id );
			$service          = new MappingService();
			$row              = $service->repository()->get( (string) $args[0], (int) $args[1] );
			$row              = $service->record_staging( $row, $this->context( $assoc ), $user_id, 'poc-draft#' . $result['post_id'] . ' readback=ok' );
		} catch ( CaseError $e ) {
			\WP_CLI::line( (string) wp_json_encode( array( 'ok' => false ) + $e->to_array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::line(
			(string) wp_json_encode(
				array(
					'ok'              => true,
					'post_id'         => $result['post_id'],
					'readback'        => $result['readback'],
					'mapping_status'  => $row['status'],
					'fields_written'  => count( $result['fields_written'] ),
				),
				JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * 構造変更テスト用の複製グループの版を切り替える（A/B/C/D/E、off で無効）。
	 *
	 * ## OPTIONS
	 *
	 * <variant>
	 * : A / B / C / D / E / off
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function variant( array $args, array $assoc ): void {
		$v = strtoupper( (string) $args[0] );
		if ( 'OFF' === $v ) {
			delete_option( AcfVariants::OPTION );
			\WP_CLI::success( '構造変更テスト用の複製グループを無効にしました' );
			return;
		}
		if ( ! in_array( $v, AcfVariants::VARIANTS, true ) ) {
			\WP_CLI::error( 'A / B / C / D / E / off のいずれか' );
		}
		AcfVariants::term_id( true );
		update_option( AcfVariants::OPTION, $v, false );
		\WP_CLI::success( "構造変更テスト用の複製グループを版 {$v} にしました（次のコマンドから有効）" );
	}

	/**
	 * 開発用の模擬AI（Mock）を切り替える。有効な間は外部へ通信せず、生成結果は【模擬】と表示される。
	 *
	 * ## OPTIONS
	 *
	 * <scenario>
	 * : ok / 401 / 429 / 529 / timeout / bad_ids / evil など。off で無効（本物の Claude API を使う）
	 *
	 * @subcommand mock-provider
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function mock_provider( array $args, array $assoc ): void {
		$s = strtolower( (string) $args[0] );
		if ( 'off' === $s ) {
			delete_option( MockProvider::OPTION );
			\WP_CLI::success( '模擬AIを無効にしました（本物の Claude API を使います。キーが未設定なら AI-001）' );
			return;
		}
		if ( ! in_array( $s, MockProvider::SCENARIOS, true ) ) {
			\WP_CLI::error( implode( ' / ', MockProvider::SCENARIOS ) . ' / off のいずれか' );
		}
		update_option( MockProvider::OPTION, $s, false );
		\WP_CLI::success( "模擬AIを有効にしました（{$s}）。外部へは通信しません" );
	}

	/**
	 * 保存済みの合成下書きを、DBから読み直して期待値と比べる（保存時のマッピングの版で）。
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : 対象の下書き。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function verify( array $args, array $assoc ): void {
		$post_id = (int) $args[0];
		$this->require_poc_draft( $post_id );
		$doc   = json_decode( (string) get_post_meta( $post_id, '_wp_ai_case_poc_doc', true ), true );
		$hero  = (string) get_post_meta( $post_id, '_wp_ai_case_poc_hero', true );
		$audit = get_post_meta( $post_id, DraftWriter::AUDIT_META, true );
		if ( ! is_array( $doc ) || ! is_array( $audit ) ) {
			\WP_CLI::error( '期待値（合成ドキュメント・保存記録）がありません' );
		}
		$service = new MappingService();
		$mapping = $service->repository()->get( (string) $audit['mapping_id'], (int) $audit['mapping_version'] );
		if ( ! $mapping ) {
			\WP_CLI::error( '保存時のマッピングの版が見つかりません' );
		}
		( new CaseDocumentValidator() )->validate( $doc );
		$built  = null;
		$fields = ( new DraftWriter() )->translate( $doc, $mapping, $hero ? array( 'hero' => $hero ) : array(), $built );

		Snapshot::flush( $post_id );
		$inspector = new SchemaInspector();
		$groups    = $inspector->groups( $inspector->context( Plugin::POST_TYPE, $post_id ) );
		$registry  = new FieldAdapterRegistry();
		$prepared  = $registry->prepare_top_level( SchemaInspector::top_level_index( $groups ), $fields, array() );

		$rows = array();
		foreach ( $prepared as $key => $item ) {
			$expected = SchemaInspector::stable_json( $registry->normalize( $item['field'], $item['raw'] ) );
			$actual   = SchemaInspector::stable_json( $registry->normalize( $item['field'], get_field( $key, $post_id, false ) ) );
			$rows[]   = array(
				'field'  => (string) $item['field']['name'],
				'type'   => (string) $item['field']['type'],
				'result' => $expected === $actual ? 'OK' : 'NG',
			);
		}
		$post   = get_post( $post_id );
		$fv     = get_field( 'FV_Layout', $post_id );
		$checks = array(
			array( 'check' => 'post_status=draft', 'result' => 'draft' === $post->post_status ? 'OK' : 'NG' ),
			array( 'check' => 'post_type=works', 'result' => 'works' === $post->post_type ? 'OK' : 'NG' ),
			array( 'check' => '写真ID集合の一致', 'result' => ( array_diff( $built['image_ids'], $registry->referenced_attachments() ) || array_diff( $registry->referenced_attachments(), $built['image_ids'] ) ) ? 'NG' : 'OK' ),
			array( 'check' => 'FV_Layout（テーマが読む形式）', 'result' => ( is_array( $fv ) && ( $fv['value'] ?? '' ) === $built['fields']['FV_Layout'] ) ? 'OK' : 'NG' ),
			array( 'check' => 'マッピング v' . $mapping['mapping_version'] . '（' . $mapping['status'] . '）', 'result' => 'OK' ),
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'type', 'result' ) );
		\WP_CLI\Utils\format_items( 'table', $checks, array( 'check', 'result' ) );
		$ng = count( array_filter( array_merge( $rows, $checks ), static fn( $r ) => 'OK' !== $r['result'] ) );
		if ( $ng ) {
			\WP_CLI::error( "{$ng} 件が一致しません" );
		}
		\WP_CLI::success( 'すべて一致しました' );
	}

	/**
	 * 合成下書きを、現在のテーマのテンプレートでサーバー内レンダリングし、HTMLを出力する（HTTPは使わない）。
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : 対象の下書き。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function render( array $args, array $assoc ): void {
		$this->require_user();
		$post_id = (int) $args[0];
		$this->require_poc_draft( $post_id );

		global $wp;
		$wp->query_vars = array(
			'p'         => $post_id,
			'post_type' => Plugin::POST_TYPE,
			'preview'   => 'true',
		);
		$wp->query_posts();
		$wp->register_globals();
		if ( ! have_posts() ) {
			\WP_CLI::error( '投稿を読み込めませんでした' );
		}
		$template = get_single_template();
		if ( ! $template ) {
			\WP_CLI::error( 'テンプレートが見つかりません' );
		}
		ob_start();
		include $template;
		$html = (string) ob_get_clean();
		\WP_CLI::line( $html );
	}

	/**
	 * 表示確認用の期限付きプレビューURLを発行する（合成下書きのみ）。
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : 対象の下書き。
	 *
	 * [--ttl=<seconds>]
	 * : 有効期間（60〜7200秒）。
	 * ---
	 * default: 3600
	 * ---
	 *
	 * @subcommand preview-url
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function preview_url( array $args, array $assoc ): void {
		$post_id = (int) $args[0];
		$this->require_poc_draft( $post_id );
		$token = PreviewToken::issue( $post_id, (int) ( $assoc['ttl'] ?? 3600 ) );
		\WP_CLI::line(
			add_query_arg(
				array(
					'p'                 => $post_id,
					'post_type'         => Plugin::POST_TYPE,
					PreviewToken::PARAM => $token,
				),
				home_url( '/' )
			)
		);
	}

	/**
	 * 合成データ（印の付いた施工事例の下書きと合成画像）を一覧・削除する。
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : 実際に削除する（省略時は一覧だけ）。
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cleanup( array $args, array $assoc ): void {
		$posts       = Poc::marked_posts( Plugin::POST_TYPE );
		$attachments = Poc::marked_posts( 'attachment' );
		$rows        = array();
		foreach ( $posts as $id ) {
			$rows[] = array( 'id' => $id, 'type' => 'works', 'status' => get_post_status( $id ), 'title' => get_the_title( $id ) );
		}
		foreach ( $attachments as $id ) {
			$rows[] = array( 'id' => $id, 'type' => 'attachment', 'status' => get_post_status( $id ), 'title' => get_the_title( $id ) );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'type', 'status', 'title' ) );
		if ( empty( $assoc['yes'] ) ) {
			\WP_CLI::line( '一覧のみ（削除するには --yes）' );
			return;
		}
		foreach ( $posts as $id ) {
			$status = get_post_status( $id );
			if ( ! in_array( $status, array( 'draft', 'private' ), true ) ) {
				\WP_CLI::warning( "#{$id} は {$status} のため削除しません" );
				continue;
			}
			wp_delete_post( $id, true );
		}
		foreach ( $attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		delete_option( Poc::IMAGES_OPTION );
		$term_id = AcfVariants::term_id();
		if ( $term_id ) {
			$repo = ( new MappingService() )->repository();
			foreach ( $repo->all() as $row ) {
				foreach ( (array) ( $row['document']['context_selector']['taxonomy_terms'] ?? array() ) as $term ) {
					if ( (int) $term['term_id'] === $term_id ) {
						$repo->delete_context( (string) $row['context_hash'] );
						break;
					}
				}
			}
			wp_delete_term( $term_id, AcfVariants::TERM_TAX );
		}
		delete_option( AcfVariants::OPTION );
		\WP_CLI::success( '合成データ（下書き・画像・試験用タームと、その文脈のマッピング）を削除しました' );
	}

	/**
	 * @param array<string,string> $assoc
	 * @return array<string,mixed>
	 */
	private function create( array $assoc, int $user_id ): array {
		$fixture   = (string) ( $assoc['fixture'] ?? 'poc_full' );
		$relations = array_merge( Poc::default_relations(), array_filter( array_map( 'intval', array_intersect_key( $assoc, array_flip( array( 'voice', 'staff', 'supervisor' ) ) ) ) ) );
		$doc       = Poc::load_fixture( $fixture, $relations );
		$options   = array(
			'extra_meta' => array(
				Poc::MARK                 => '1',
				'_wp_ai_case_poc_fixture' => $fixture,
			),
			'terms'      => isset( $assoc['test-context'] ) ? AcfVariants::terms() : array(),
		);
		if ( ! empty( $assoc['hero'] ) ) {
			$options['hero'] = (string) $assoc['hero'];
		}
		if ( ! empty( $assoc['mapping'] ) ) {
			[ $id, $version ] = array_pad( explode( ':', (string) $assoc['mapping'], 2 ), 2, '0' );
			$row              = ( new MappingService() )->repository()->get( $id, (int) $version );
			if ( ! $row ) {
				throw new CaseError( CaseError::MAPPING_NOT_READY, 'マッピングの版が見つかりません' );
			}
			$options['mapping'] = $row;
		}
		$result = ( new DraftWriter() )->create_from_document( $doc, $user_id, $options );
		update_post_meta( $result['post_id'], '_wp_ai_case_poc_doc', wp_slash( wp_json_encode( $doc, JSON_UNESCAPED_UNICODE ) ) );
		if ( ! empty( $options['hero'] ) ) {
			update_post_meta( $result['post_id'], '_wp_ai_case_poc_hero', $options['hero'] );
		}
		return $result;
	}

	/**
	 * @param array<string,string> $assoc
	 * @return array{post_type:string,post_id:int,post_terms:array<string,int[]>}
	 */
	private function context( array $assoc ): array {
		return ( new SchemaInspector() )->context( Plugin::POST_TYPE, 0, isset( $assoc['test-context'] ) ? AcfVariants::terms() : array() );
	}

	private function require_user(): int {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			\WP_CLI::error( '--user=<管理者> を指定してください' );
		}
		return $user_id;
	}

	private function require_poc_draft( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || Plugin::POST_TYPE !== $post->post_type || 'draft' !== $post->post_status || '1' !== (string) get_post_meta( $post_id, Poc::MARK, true ) ) {
			\WP_CLI::error( '合成データの下書きではありません' );
		}
	}
}
