<?php
/**
 * 前提がそろわず実行しない試験。
 *
 * @package WpAiCase
 */

namespace WpAiCase\Dev\SelfTest;

defined( 'ABSPATH' ) || exit;

final class SkipTest extends \RuntimeException {
}
