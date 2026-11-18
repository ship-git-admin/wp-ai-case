<?php
/**
 * OpenAI API のプロバイダー（Responses API）。Claude と同じ依頼文・同じ出力の形（JSON Schema）を使う。
 *
 * - 接続テスト：GET /v1/models/{model}（モデルの情報だけ。トークンを使わない）
 * - 生成・修正：POST /v1/responses、構造化出力（text.format=json_schema, strict）
 * - 写真は Claude と同じ縮小・メタデータ除去済みのコピーを base64 の data URL で送る（detail=high）
 * - store=false：OpenAI 側に応答を保存させない（既定は保存される）
 * - エラーは Claude の種別にそろえて返す（ErrorClassifier で AI-xxx に分類）。429 のうち残高・支出上限は「請求」の問題として分ける
 *
 * @package WpAiCase
 */

namespace WpAiCase\AI;

use WpAiCase\Diagnostics\Settings;

defined( 'ABSPATH' ) || exit;

final class OpenAIProvider implements ProviderInterface {

	/** 出力の上限（推論のトークンを含む）。 */
	public const MAX_OUTPUT = 16000;
	public const EFFORT     = 'medium';

	/** 429 のうち、待っても直らない（残高・支出上限）もの。 */
	private const BILLING_CODES = array( 'insufficient_quota', 'credit_balance_exhausted', 'organization_spend_limit_exceeded', 'project_spend_limit_exceeded', 'organization_usage_limit_exceeded', 'billing_hard_limit_reached' );

	private ?OpenAIClient $client;

	/**
	 * @param OpenAIClient|null $client 試験用に差し替える場合だけ指定する（通常は wp-config.php のキーで作る）
	 */
	public function __construct( ?OpenAIClient $client = null ) {
		if ( null === $client ) {
			$key    = Settings::api_key_for( 'openai' );
			$client = null !== $key ? new OpenAIClient( $key ) : null;
		}
		$this->client = $client;
	}

	public function id(): string {
		return 'openai';
	}

	public function probe( string $model ): ProviderResult {
		if ( null === $this->client ) {
			return ProviderResult::unavailable();
		}
		if ( ! preg_match( '/^[a-z0-9][a-z0-9\-\.]{1,80}$/', $model ) ) {
			return ProviderResult::http_error( 404, 'not_found_error' );
		}
		$r = $this->client->request( 'GET', '/v1/models/' . rawurlencode( $model ), null, 15 );
		if ( $r['network_error'] ) {
			return ProviderResult::network( $r['timed_out'] );
		}
		if ( $r['ok'] ) {
			$id = is_array( $r['json'] ) ? (string) ( $r['json']['id'] ?? '' ) : '';
			return ProviderResult::success(
				(int) $r['status'],
				array(
					'model_id'     => preg_match( '/^[a-z0-9\-\.]{1,80}$/', $id ) ? $id : '',
					'display_name' => preg_match( '/^[a-z0-9\-\.]{1,80}$/', $id ) ? $id : '',
				),
				$r['request_id']
			);
		}
		return ProviderResult::http_error( (int) $r['status'], self::error_type( (int) $r['status'], $r['json'] ), $r['request_id'] );
	}

	public function compose( array $payload ): ProviderResult {
		if ( null === $this->client ) {
			return ProviderResult::unavailable();
		}
		$mode    = (string) ( $payload['mode'] ?? 'full' );
		$content = array(
			array(
				'type' => 'input_text',
				'text' => Prompts::compose_text( $payload ),
			),
		);
		foreach ( (array) ( $payload['images'] ?? array() ) as $img ) {
			if ( empty( $img['data'] ) ) {
				continue;
			}
			$content[] = array( 'type' => 'input_text', 'text' => 'attachment_id: ' . (int) $img['attachment_id'] );
			$content[] = array(
				'type'      => 'input_image',
				'image_url' => 'data:' . (string) $img['media_type'] . ';base64,' . (string) $img['data'],
				'detail'    => 'high',
			);
		}
		return $this->respond( Prompts::SYSTEM_COMPOSE, $content, 'case_draft', Prompts::compose_schema( 'analyze_only' !== $mode ), (string) $payload['model'], 150 );
	}

	public function revise( array $payload ): ProviderResult {
		if ( null === $this->client ) {
			return ProviderResult::unavailable();
		}
		$content = array(
			array(
				'type' => 'input_text',
				'text' => Prompts::revise_text( $payload ),
			),
		);
		return $this->respond( Prompts::SYSTEM_REVISE, $content, 'case_revision', Prompts::revise_schema(), (string) $payload['model'], 90 );
	}

	/**
	 * @param array<int,array<string,mixed>> $content
	 * @param array<string,mixed>            $schema
	 */
	private function respond( string $instructions, array $content, string $name, array $schema, string $model, int $timeout ): ProviderResult {
		$r = $this->client->request(
			'POST',
			'/v1/responses',
			array(
				'model'             => $model,
				'instructions'      => $instructions,
				'input'             => array(
					array(
						'role'    => 'user',
						'content' => $content,
					),
				),
				'text'              => array(
					'format' => array(
						'type'   => 'json_schema',
						'name'   => $name,
						'schema' => $schema,
						'strict' => true,
					),
				),
				'reasoning'         => array( 'effort' => self::EFFORT ),
				'max_output_tokens' => self::MAX_OUTPUT,
				'store'             => false,
			),
			$timeout
		);
		if ( $r['network_error'] ) {
			return ProviderResult::network( $r['timed_out'] );
		}
		if ( ! $r['ok'] ) {
			return ProviderResult::http_error( (int) $r['status'], self::error_type( (int) $r['status'], $r['json'] ), $r['request_id'] );
		}
		$json   = $r['json'] ?? array();
		$usage  = self::usage( (array) ( $json['usage'] ?? array() ) );
		$status = (string) ( $json['status'] ?? '' );
		if ( 'incomplete' === $status ) {
			$reason = (string) ( $json['incomplete_details']['reason'] ?? '' );
			return new ProviderResult( false, (int) $r['status'], 'content_filter' === $reason ? 'refusal' : 'incomplete_output', false, false, false, $r['request_id'], array( 'usage' => $usage ) );
		}
		if ( 'completed' !== $status ) {
			return new ProviderResult( false, (int) $r['status'], 'failed' === $status ? 'api_error' : 'invalid_output', false, false, false, $r['request_id'], array( 'usage' => $usage ) );
		}
		$text = null;
		foreach ( (array) ( $json['output'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || 'message' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $item['content'] ?? array() ) as $part ) {
				if ( is_array( $part ) && 'refusal' === ( $part['type'] ?? '' ) ) {
					return new ProviderResult( false, (int) $r['status'], 'refusal', false, false, false, $r['request_id'], array( 'usage' => $usage ) );
				}
				if ( is_array( $part ) && 'output_text' === ( $part['type'] ?? '' ) && null === $text ) {
					$text = (string) ( $part['text'] ?? '' );
				}
			}
		}
		$data = null !== $text ? json_decode( $text, true ) : null;
		if ( ! is_array( $data ) ) {
			return new ProviderResult( false, (int) $r['status'], 'invalid_output', false, false, false, $r['request_id'], array( 'usage' => $usage ) );
		}
		return ProviderResult::success( (int) $r['status'], array( 'content' => $data, 'usage' => $usage ), $r['request_id'] );
	}

	/**
	 * 使用量を Claude の形にそろえる（OpenAI の input_tokens はキャッシュ分を含むので分ける）。
	 *
	 * @param array<string,mixed> $u
	 * @return array<string,int>
	 */
	public static function usage( array $u ): array {
		$input  = (int) ( $u['input_tokens'] ?? 0 );
		$cached = min( $input, (int) ( $u['input_tokens_details']['cached_tokens'] ?? 0 ) );
		return array(
			'input_tokens'                => $input - $cached,
			'output_tokens'               => (int) ( $u['output_tokens'] ?? 0 ),
			'cache_read_input_tokens'     => $cached,
			'cache_creation_input_tokens' => 0,
		);
	}

	/**
	 * OpenAI のエラーを、Claude と同じ種別の名前にそろえる（ErrorClassifier が使う）。
	 *
	 * @param array<string,mixed>|null $json
	 */
	public static function error_type( int $status, ?array $json ): ?string {
		$type = is_array( $json ) ? (string) ( $json['error']['type'] ?? '' ) : '';
		$code = is_array( $json ) ? (string) ( $json['error']['code'] ?? '' ) : '';
		if ( in_array( $type, self::BILLING_CODES, true ) || in_array( $code, self::BILLING_CODES, true ) ) {
			return 'billing_error';
		}
		return match ( true ) {
			401 === $status => 'authentication_error',
			403 === $status => 'permission_error',
			404 === $status || 'model_not_found' === $code => 'not_found_error',
			413 === $status => 'request_too_large',
			429 === $status => 'rate_limit_error',
			503 === $status || 'server_is_overloaded' === $code => 'overloaded_error',
			$status >= 500 => 'api_error',
			default => preg_match( '/^[a-z_]{1,40}$/', $type ) ? $type : null,
		};
	}
}
