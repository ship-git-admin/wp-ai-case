<?php
/**
 * Claude API のプロバイダー。
 *
 * 現時点では接続テスト（モデルを1件取得：GET /v1/models/{id}）だけ。
 * 画像の解析・文章の生成は、顧客の入力フォームとジョブ処理と一緒に追加する。
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
			$key    = Settings::api_key();
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
}
