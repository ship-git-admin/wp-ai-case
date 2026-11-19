<?php
/**
 * 開発用の模擬プロバイダー。外部へは通信せず、指定した結果（成功・401・429 など）を返す。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev;

use WpAiCase\AI\ProviderInterface;
use WpAiCase\AI\ProviderResult;

defined( 'ABSPATH' ) || exit;

final class MockProvider implements ProviderInterface {

	public const OPTION    = 'wp_ai_case_dev_mock_provider';
	public const SCENARIOS = array( 'ok', '401', '402', '403', '404', '413', '429', '500', '529', 'network', 'timeout', 'bad_ids', 'evil' );

	public static int $calls = 0;

	/** @var array<int,array<string,mixed>> 最後の呼び出しの内容（試験用） */
	public static array $payloads = array();

	/** 呼び出しの途中で実行する処理（試験用：取消しの割り込みなど）。 */
	public static $during = null;

	public function __construct( private string $scenario ) {
	}

	public static function register(): void {
		// 開発用：Mock を有効にしている間は「キー設定済み」とみなして画面を試せるようにする（実キーは使わず、外部通信もしない）。
		if ( in_array( (string) get_option( self::OPTION, '' ), self::SCENARIOS, true ) ) {
			\WpAiCase\Diagnostics\Settings::$test_status = 'configured';
		}
		add_filter(
			'wp_ai_case_provider',
			static function ( $provider ) {
				$scenario = (string) get_option( self::OPTION, '' );
				return in_array( $scenario, self::SCENARIOS, true ) ? new self( $scenario ) : $provider;
			}
		);
	}

	public function id(): string {
		return 'mock:' . $this->scenario;
	}

	public function probe( string $model ): ProviderResult {
		++self::$calls;
		return match ( $this->scenario ) {
			'ok'      => ProviderResult::success( 200, array( 'display_name' => 'mock ' . $model ), 'req_mock' ),
			'401'     => ProviderResult::http_error( 401, 'authentication_error', 'req_mock' ),
			'402'     => ProviderResult::http_error( 402, 'billing_error', 'req_mock' ),
			'403'     => ProviderResult::http_error( 403, 'permission_error', 'req_mock' ),
			'404'     => ProviderResult::http_error( 404, 'not_found_error', 'req_mock' ),
			'413'     => ProviderResult::http_error( 413, 'request_too_large', 'req_mock' ),
			'429'     => ProviderResult::http_error( 429, 'rate_limit_error', 'req_mock' ),
			'500'     => ProviderResult::http_error( 500, 'api_error', 'req_mock' ),
			'529'     => ProviderResult::http_error( 529, 'overloaded_error', 'req_mock' ),
			'network' => ProviderResult::network( false ),
			'timeout' => ProviderResult::network( true ),
			default   => ProviderResult::unavailable(),
		};
	}

	public function compose( array $payload ): ProviderResult {
		++self::$calls;
		self::$payloads[] = $payload;
		if ( is_callable( self::$during ) ) {
			( self::$during )();
		}
		if ( ! in_array( $this->scenario, array( 'ok', 'bad_ids', 'evil' ), true ) ) {
			return $this->probe( (string) $payload['model'] );
		}
		$mode   = (string) ( $payload['mode'] ?? 'full' );
		$images = (array) ( $payload['images'] ?? array() );
		$n      = max( 1, count( $images ) );
		$out    = array();
		if ( 'compose_text_only' === $mode ) {
			$out = (array) ( $payload['prior_analysis'] ?? array() );
		} else {
			foreach ( array_values( $images ) as $i => $img ) {
				$hint  = (string) ( $img['phase_hint'] ?? 'unknown' );
				$phase = 'unknown' !== $hint ? $hint : ( $i < $n / 3 ? 'before' : ( $i < 2 * $n / 3 ? 'during' : 'after' ) );
				$out[] = array(
					'attachment_id'     => (int) $img['attachment_id'],
					'phase'             => $phase,
					'description'       => '【模擬】写真' . (int) $img['attachment_id'] . 'の様子',
					'caption'           => '',
					'related_image_ids' => array(),
					'uncertain'         => false,
					'uncertain_reason'  => '',
				);
			}
			if ( 'bad_ids' === $this->scenario ) {
				$out[] = array( 'attachment_id' => 999999999, 'phase' => 'after', 'description' => 'x', 'caption' => '', 'related_image_ids' => array(), 'uncertain' => false, 'uncertain_reason' => '' );
			}
		}
		if ( 'analyze_only' === $mode ) {
			return ProviderResult::success( 200, array( 'content' => array( 'images' => $out ), 'usage' => array( 'input_tokens' => 1000, 'output_tokens' => 100 ) ) );
		}
		$by = array( 'before' => array(), 'during' => array(), 'after' => array() );
		foreach ( $out as $a ) {
			if ( isset( $by[ $a['phase'] ] ) ) {
				$by[ $a['phase'] ][] = (int) $a['attachment_id'];
			}
		}
		$labels   = array( 'before' => '施工前の様子', 'during' => '施工中の様子', 'after' => '施工後の様子' );
		$sections = array();
		foreach ( $by as $phase => $ids ) {
			if ( $ids ) {
				$sections[] = array( 'kind' => $phase, 'heading' => $labels[ $phase ] . '（模擬）', 'body' => '【模擬】' . $labels[ $phase ] . 'の説明です。', 'image_ids' => $ids );
			}
		}
		if ( $by['before'] && $by['after'] ) {
			$sections[] = array( 'kind' => 'comparison', 'heading' => '施工前後の比較（模擬）', 'body' => '【模擬】施工前と施工後の比較です。', 'image_ids' => array( $by['before'][0], $by['after'][0] ) );
		}
		$facts   = (array) ( $payload['facts'] ?? array() );
		$article = array(
			'title'    => '【模擬】' . mb_substr( (string) ( $facts['overview'] ?? '施工事例' ), 0, 30 ),
			'catch'    => '【模擬】' . mb_substr( (string) ( $payload['photo_set_description'] ?? '' ), 0, 30 ),
			'summary'  => '【模擬】入力された一次情報と写真をもとにした下書きです。',
			'sections' => $sections,
			'seo'      => array( 'title' => '【模擬】施工事例', 'description' => '【模擬】施工事例の説明', 'h1' => '【模擬】施工事例', 'keywords' => '模擬,施工事例' ),
			'work_type'      => '【模擬】外壁塗装・付帯部塗装',
			'request_text'   => '【模擬】ご要望をまとめた文章です。',
			'proposal_text'  => '【模擬】ご提案をまとめた文章です。',
			'staff_comments' => array_map( static fn( $no ) => array( 'staff_no' => (int) $no, 'comment' => '【模擬】担当スタッフのコメントです。' ), (array) ( $payload['staff_comment_needed'] ?? array() ) ),
			'category_ids'   => array_slice( array_map( static fn( $c ) => (int) $c['id'], (array) ( $payload['categories'] ?? array() ) ), 0, 1 ),
		);
		$questions = isset( $facts['duration'] ) ? array() : array( '（模擬）工期はどのくらいでしたか？' );
		return ProviderResult::success( 200, array( 'content' => array( 'images' => $out, 'article' => $article, 'questions' => $questions ), 'usage' => array( 'input_tokens' => 3000, 'output_tokens' => 800 ) ) );
	}

	public function revise( array $payload ): ProviderResult {
		++self::$calls;
		self::$payloads[] = $payload;
		if ( ! in_array( $this->scenario, array( 'ok', 'bad_ids', 'evil' ), true ) ) {
			return $this->probe( (string) $payload['model'] );
		}
		$doc     = (array) ( $payload['document'] ?? array() );
		$allowed = (array) ( $payload['allowed_sections'] ?? array() );
		$ops     = array();
		if ( in_array( 'title', $allowed, true ) ) {
			$ops[] = array( 'op' => 'text_set', 'section' => 'title', 'value' => '【修正】' . (string) ( $doc['title'] ?? '' ) );
		}
		if ( in_array( 'seo', $allowed, true ) ) {
			$ops[] = array( 'op' => 'seo_set', 'section' => 'seo', 'field' => 'title', 'value' => '【修正】SEOタイトル' );
		}
		if ( in_array( 'body', $allowed, true ) ) {
			foreach ( (array) ( $doc['sections'] ?? array() ) as $s ) {
				if ( 'intro' !== $s['node_id'] ) {
					$ops[] = array( 'op' => 'body_section_set', 'section' => 'body', 'node_id' => $s['node_id'], 'heading' => $s['heading'] . '（修正）', 'body' => $s['body'] . '（修正）' );
					break;
				}
			}
		}
		if ( 'evil' === $this->scenario ) {
			$ops[] = array( 'op' => 'fact_set', 'section' => 'facts', 'fact_key' => 'cost', 'value' => '100万円', 'source' => 'user_input' );
		}
		return ProviderResult::success( 200, array( 'content' => array( 'operations' => $ops ), 'usage' => array( 'input_tokens' => 1500, 'output_tokens' => 200 ) ) );
	}

	/** 試験用：表示メモで返す、写真の見出しのテーマのラベル。 */
	public static array $display_labels = array( '施工前', '施工中', '施工後', '模擬ラベル' );

	public function display_notes( array $payload ): ProviderResult {
		++self::$calls;
		self::$payloads[] = $payload;
		if ( ! in_array( $this->scenario, array( 'ok', 'bad_ids', 'evil' ), true ) ) {
			return $this->probe( (string) $payload['model'] );
		}
		$targets = array();
		foreach ( (array) ( $payload['targets'] ?? array() ) as $t ) {
			$targets[] = array(
				'id'           => (string) $t['id'],
				'shown'        => 'seo' !== $t['id'],
				'where'        => '【模擬】' . (string) $t['name'],
				'theme_labels' => in_array( $t['id'], array( 'photo_caption', 'section_heading' ), true ) ? self::$display_labels : array(),
				'also_used_as' => 'catch' === $t['id'] ? array( '一覧のカードのタイトル' ) : array(),
				'length_hint'  => '',
				'avoid'        => array(),
			);
		}
		if ( 'evil' === $this->scenario ) {
			$targets[] = array( 'id' => 'unknown_target', 'shown' => true, 'where' => 'x', 'theme_labels' => array(), 'also_used_as' => array(), 'length_hint' => '', 'avoid' => array() );
			$targets[0]['where'] = '<script>x</script>' . str_repeat( '長', 500 );
		}
		return ProviderResult::success( 200, array( 'content' => array( 'targets' => $targets, 'warnings' => array( '【模擬】注意' ) ), 'usage' => array( 'input_tokens' => 20000, 'output_tokens' => 1500 ) ) );
	}

	public function extract( array $payload ): ProviderResult {
		++self::$calls;
		self::$payloads[] = $payload;
		if ( ! in_array( $this->scenario, array( 'ok', 'bad_ids', 'evil' ), true ) ) {
			return $this->probe( (string) $payload['model'] );
		}
		$facts = array_fill_keys( \WpAiCase\AI\Prompts::EXTRACT_FACTS, '' );
		$text  = (string) ( $payload['text'] ?? '' );
		$facts['overview']       = '【模擬】' . mb_substr( preg_replace( '/\s+/u', ' ', $text ), 0, 40 );
		$facts['client_request'] = '【模擬】ご要望';
		$facts['work_done']      = '【模擬】施工内容';
		$facts['area']           = '模擬市';
		if ( 'evil' === $this->scenario ) {
			$facts['customer_voice'] = str_repeat( '長', 5000 );
			$facts['area']           = '<script>x</script>模擬市';
		}
		return ProviderResult::success(
			200,
			array(
				'content' => array(
					'title'         => '【模擬】取り込んだ事例',
					'facts'         => $facts,
					'staff_comment' => '',
					// 写真の印 [写真n] ごとに：代替テキストに「ロゴ」を含むものは使わない、「施工後」なら工程を after に。
					'photos'        => array_values(
						array_map(
							static fn( $m ) => array(
								'no'      => (int) $m[1],
								'use'     => ! str_contains( (string) ( $m[2] ?? '' ), 'ロゴ' ),
								'phase'   => str_contains( (string) ( $m[2] ?? '' ), '施工後' ) ? 'after' : ( str_contains( (string) ( $m[2] ?? '' ), '施工前' ) ? 'before' : 'unknown' ),
								'caption' => str_contains( (string) ( $m[2] ?? '' ), '施工後' ) ? '施工後' : '',
							),
							preg_match_all( '/\[写真(\d+)\](?:（([^）]*)）)?/u', $text, $mm, PREG_SET_ORDER ) ? array_values( array_column( array_map( static fn( $x ) => array( $x[1], $x ), $mm ), 1, 0 ) ) : array()
						)
					),
				),
				'usage'   => array( 'input_tokens' => 800, 'output_tokens' => 150 ),
			)
		);
	}
}
