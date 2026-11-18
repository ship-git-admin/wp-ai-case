<?php
/**
 * Claude API のプロバイダー。
 *
 * 接続テスト（GET /v1/models/{id}）、生成（写真の解析・下書き）・修正（POST /v1/messages、構造化出力）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\AI;

use WpAiCase\Diagnostics\Settings;

defined( 'ABSPATH' ) || exit;

final class AnthropicProvider implements ProviderInterface {

	private ?AnthropicClient $client;

	/**
	 * @param AnthropicClient|null $client 試験用に差し替える場合だけ指定する（通常は wp-config.php のキーで作る）
	 */
	public function __construct( ?AnthropicClient $client = null ) {
		if ( null === $client ) {
			$key    = Settings::api_key_for( 'anthropic' );
			$client = null !== $key ? new AnthropicClient( $key ) : null;
		}
		$this->client = $client;
	}

	public function id(): string {
		return 'anthropic';
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
			// 写真を扱うため、画像入力に対応しないモデルは使えないものとして扱う。
			$image = is_array( $r['json'] ) ? ( $r['json']['capabilities']['image_input']['supported'] ?? null ) : null;
			if ( false === $image ) {
				return ProviderResult::http_error( (int) $r['status'], 'model_capability', $r['request_id'] );
			}
			return ProviderResult::success(
				(int) $r['status'],
				array(
					'model_id'     => preg_match( '/^[a-z0-9\-\.]{1,80}$/', $id ) ? $id : '',
					'display_name' => is_array( $r['json'] ) ? mb_substr( sanitize_text_field( (string) ( $r['json']['display_name'] ?? '' ) ), 0, 80 ) : '',
				),
				$r['request_id']
			);
		}
		$type = is_array( $r['json'] ) ? (string) ( $r['json']['error']['type'] ?? '' ) : '';
		return ProviderResult::http_error( (int) $r['status'], preg_match( '/^[a-z_]{1,40}$/', $type ) ? $type : null, $r['request_id'] );
	}

	public function compose( array $payload ): ProviderResult {
		if ( null === $this->client ) {
			return ProviderResult::unavailable();
		}
		$mode    = (string) ( $payload['mode'] ?? 'full' );
		$content = array(
			array(
				'type' => 'text',
				'text' => Prompts::compose_text( $payload ),
			),
		);
		foreach ( (array) ( $payload['images'] ?? array() ) as $img ) {
			if ( empty( $img['data'] ) ) {
				continue;
			}
			$content[] = array( 'type' => 'text', 'text' => 'attachment_id: ' . (int) $img['attachment_id'] );
			$content[] = array(
				'type'   => 'image',
				'source' => array(
					'type'       => 'base64',
					'media_type' => (string) $img['media_type'],
					'data'       => (string) $img['data'],
				),
			);
		}
		return $this->messages( Prompts::SYSTEM_COMPOSE, $content, Prompts::compose_schema( 'analyze_only' !== $mode ), (string) $payload['model'], 150 );
	}

	public function revise( array $payload ): ProviderResult {
		if ( null === $this->client ) {
			return ProviderResult::unavailable();
		}
		$content = array(
			array(
				'type' => 'text',
				'text' => Prompts::revise_text( $payload ),
			),
		);
		return $this->messages( Prompts::SYSTEM_REVISE, $content, Prompts::revise_schema(), (string) $payload['model'], 90 );
	}

	/**
	 * @param array<int,array<string,mixed>> $content
	 * @param array<string,mixed>            $schema
	 */
	private function messages( string $system, array $content, array $schema, string $model, int $timeout ): ProviderResult {
		$r = $this->client->request(
			'POST',
			'/v1/messages',
			array(
				'model'         => $model,
				'max_tokens'    => 8000,
				'system'        => $system,
				'messages'      => array(
					array(
						'role'    => 'user',
						'content' => $content,
					),
				),
				'output_config' => array(
					'effort' => 'medium',
					'format' => array(
						'type'   => 'json_schema',
						'schema' => $schema,
					),
				),
			),
			$timeout
		);
		if ( $r['network_error'] ) {
			return ProviderResult::network( $r['timed_out'] );
		}
		if ( ! $r['ok'] ) {
			$type = is_array( $r['json'] ) ? (string) ( $r['json']['error']['type'] ?? '' ) : '';
			return ProviderResult::http_error( (int) $r['status'], preg_match( '/^[a-z_]{1,40}$/', $type ) ? $type : null, $r['request_id'] );
		}
		$json  = $r['json'] ?? array();
		$stop  = (string) ( $json['stop_reason'] ?? '' );
		$usage = array(
			'input_tokens'                => (int) ( $json['usage']['input_tokens'] ?? 0 ),
			'output_tokens'               => (int) ( $json['usage']['output_tokens'] ?? 0 ),
			'cache_read_input_tokens'     => (int) ( $json['usage']['cache_read_input_tokens'] ?? 0 ),
			'cache_creation_input_tokens' => (int) ( $json['usage']['cache_creation_input_tokens'] ?? 0 ),
		);
		if ( 'refusal' === $stop || 'max_tokens' === $stop ) {
			return new ProviderResult( false, (int) $r['status'], 'refusal' === $stop ? 'refusal' : 'incomplete_output', false, false, false, $r['request_id'], array( 'usage' => $usage ) );
		}
		$text = '';
		foreach ( (array) ( $json['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text = (string) ( $block['text'] ?? '' );
				break;
			}
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			return new ProviderResult( false, (int) $r['status'], 'invalid_output', false, false, false, $r['request_id'], array( 'usage' => $usage ) );
		}
		return ProviderResult::success( (int) $r['status'], array( 'content' => $data, 'usage' => $usage ), $r['request_id'] );
	}
}
