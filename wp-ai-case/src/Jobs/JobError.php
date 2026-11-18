<?php
/**
 * 画面へ返すエラー（error_response と HTTP ステータス、必要なら入力不足のフィールド名）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Jobs;

use WpAiCase\Diagnostics\SafeErrorPresenter;
use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class JobError extends \RuntimeException {

	/**
	 * @param array<string,mixed> $response error_response（visibility=customer）
	 * @param array<string,mixed> $extra    fields（不足・不正の項目名）など
	 */
	public function __construct( public readonly array $response, public readonly int $status = 400, public readonly array $extra = array() ) {
		parent::__construct( (string) ( $response['message'] ?? '' ) );
	}

	public static function from_case( CaseError $e, string $event, int $status = 400 ): self {
		$fields = array_merge( (array) ( $e->context['missing'] ?? array() ), (array) ( $e->context['fields'] ?? array() ) );
		return new self( SafeErrorPresenter::from_case_error( $e, 'customer', $event ), $status, $fields ? array( 'fields' => array_values( array_map( 'strval', $fields ) ) ) : array() );
	}

	public static function code( string $code, string $event, int $status ): self {
		return new self( SafeErrorPresenter::present( $code, 'customer', $event ), $status );
	}
}
