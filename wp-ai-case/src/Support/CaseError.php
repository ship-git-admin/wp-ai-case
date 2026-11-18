<?php
/**
 * 安全に停止するための例外。
 *
 * - reason は内部分類（ログ・試験用）。
 * - ai_code は docs/12 の利用者向けコード（AI-xxx）。
 * - context には秘密・サーバー情報・生のACF定義を入れない（フィールド名やパス程度に限る）。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Support;

defined( 'ABSPATH' ) || exit;

final class CaseError extends \RuntimeException {

	public const UNSUPPORTED_FIELD_TYPE = 'unsupported_field_type';
	public const MAPPING_MISMATCH       = 'mapping_mismatch';
	public const HIDDEN_BY_CONDITION    = 'hidden_by_condition';
	public const INVALID_VALUE          = 'invalid_value';
	public const INVALID_DOCUMENT       = 'invalid_document';
	public const MISSING_INPUT          = 'missing_input';
	public const POST_STATE             = 'post_state';
	public const STALE_BASE             = 'stale_base';
	public const SCHEMA_CHANGED         = 'schema_changed';
	public const WRITE_FAILED           = 'write_failed';
	public const READBACK_MISMATCH      = 'readback_mismatch';

	private const AI_CODES = array(
		self::UNSUPPORTED_FIELD_TYPE => 'AI-010',
		self::MAPPING_MISMATCH       => 'AI-010',
		self::HIDDEN_BY_CONDITION    => 'AI-010',
		self::SCHEMA_CHANGED         => 'AI-010',
		self::INVALID_VALUE          => 'AI-009',
		self::INVALID_DOCUMENT       => 'AI-009',
		self::MISSING_INPUT          => 'AI-017',
		self::POST_STATE             => 'AI-012',
		self::STALE_BASE             => 'AI-012',
		self::WRITE_FAILED           => 'AI-016',
		self::READBACK_MISMATCH      => 'AI-016',
	);

	/** @var string */
	public readonly string $reason;

	/** @var array<string,mixed> */
	public readonly array $context;

	/**
	 * @param array<string,mixed> $context
	 */
	public function __construct( string $reason, string $message, array $context = array() ) {
		parent::__construct( $message );
		$this->reason  = $reason;
		$this->context = $context;
	}

	public function ai_code(): string {
		return self::AI_CODES[ $this->reason ] ?? 'AI-018';
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'reason'  => $this->reason,
			'code'    => $this->ai_code(),
			'message' => $this->getMessage(),
			'context' => $this->context,
		);
	}
}
