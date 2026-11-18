<?php
/**
 * OpenAI API への HTTP 呼び出し（WordPress の HTTP API を使う。Claude と同じ扱い）。
 *
 * - 送信先は https://api.openai.com に固定。リダイレクトは追わない。証明書は検証する。
 * - APIキーは Authorization ヘッダーにだけ入れ、戻り値・例外・ログには一切含めない。
 * - 応答の本文は JSON として解釈した値だけを返す（呼び出し側は必要な項目だけを使い、生のまま表示・保存しない）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\AI;

defined( 'ABSPATH' ) || exit;

final class OpenAIClient {

	public const BASE_URL = 'https://api.openai.com';

	public function __construct( private string $api_key, private int $timeout = 60 ) {
	}

	/**
	 * @param array<string,mixed>|null $body JSON で送る本文（GET のときは null）
	 * @return array{ok:bool,status:int|null,json:array<string,mixed>|null,request_id:string|null,network_error:bool,timed_out:bool}
	 */
	public function request( string $method, string $path, ?array $body = null, ?int $timeout = null ): array {
		if ( ! str_starts_with( $path, '/v1/' ) ) {
			throw new \InvalidArgumentException( 'OpenAI API のパスは /v1/ から始まる必要があります' );
		}
		$args = array(
			'method'      => $method,
			'timeout'     => $timeout ?? $this->timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'user-agent'  => 'wp-ai-case/' . ( defined( 'WP_AI_CASE_VERSION' ) ? WP_AI_CASE_VERSION : 'dev' ),
			'headers'     => array(
				'authorization' => 'Bearer ' . $this->api_key,
				'accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['content-type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$response = wp_remote_request( self::BASE_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			$message = strtolower( $response->get_error_message() );
			return array(
				'ok'            => false,
				'status'        => null,
				'json'          => null,
				'request_id'    => null,
				'network_error' => true,
				'timed_out'     => str_contains( $message, 'timed out' ) || str_contains( $message, 'curl error 28' ),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$rid    = wp_remote_retrieve_header( $response, 'x-request-id' );
		return array(
			'ok'            => $status >= 200 && $status < 300,
			'status'        => $status,
			'json'          => is_array( $json ) ? $json : null,
			'request_id'    => is_string( $rid ) && preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $rid ) ? $rid : null,
			'network_error' => false,
			'timed_out'     => false,
		);
	}
}
