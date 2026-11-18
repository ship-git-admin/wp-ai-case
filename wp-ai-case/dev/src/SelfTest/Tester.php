<?php
/**
 * 最小限の試験ヘルパー。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev\SelfTest;

use WpAiCase\Support\CaseError;

defined( 'ABSPATH' ) || exit;

final class Tester {

	/** @var array<int,array{id:string,name:string,result:string,detail:string}> */
	private array $results = array();

	/**
	 * @param callable():void $fn 失敗時は例外を投げる。
	 */
	public function test( string $id, string $name, callable $fn ): void {
		try {
			$fn();
			$this->results[] = array( 'id' => $id, 'name' => $name, 'result' => 'PASS', 'detail' => '' );
		} catch ( SkipTest $e ) {
			$this->results[] = array( 'id' => $id, 'name' => $name, 'result' => 'SKIP', 'detail' => $e->getMessage() );
		} catch ( \Throwable $e ) {
			$detail = $e->getMessage();
			if ( $e instanceof CaseError ) {
				$detail .= ' ' . wp_json_encode( $e->to_array(), JSON_UNESCAPED_UNICODE );
			}
			$this->results[] = array( 'id' => $id, 'name' => $name, 'result' => 'FAIL', 'detail' => mb_substr( $detail, 0, 400 ) );
		}
	}

	public function true( bool $cond, string $message ): void {
		if ( ! $cond ) {
			throw new \RuntimeException( $message );
		}
	}

	public function same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . ' expected=' . wp_json_encode( $expected, JSON_UNESCAPED_UNICODE ) . ' actual=' . wp_json_encode( $actual, JSON_UNESCAPED_UNICODE ) );
		}
	}

	/**
	 * 指定の理由で CaseError になることを確かめる。
	 *
	 * @param callable():mixed $fn
	 */
	public function throws_case( string $reason, callable $fn ): CaseError {
		try {
			$fn();
		} catch ( CaseError $e ) {
			if ( $e->reason !== $reason ) {
				throw new \RuntimeException( "理由が違います expected={$reason} actual={$e->reason}: " . $e->getMessage() );
			}
			return $e;
		}
		throw new \RuntimeException( "CaseError({$reason}) になりませんでした" );
	}

	/**
	 * @return array<int,array{id:string,name:string,result:string,detail:string}>
	 */
	public function results(): array {
		return $this->results;
	}
}
