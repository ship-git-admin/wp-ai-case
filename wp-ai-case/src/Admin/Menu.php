<?php
/**
 * 管理画面のメニュー「AI施工事例」。
 *
 * - 新規作成：施工事例を編集できるユーザー
 * - 接続・診断・マッピング：管理者（manage_options）だけ
 *
 * @package WpAiCase
 */

namespace WpAiCase\Admin;

defined( 'ABSPATH' ) || exit;

final class Menu {

	public const SLUG             = 'wp-ai-case';
	public const DIAGNOSTICS_SLUG = 'wp-ai-case-diagnostics';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menus' ) );
		DiagnosticsPage::register();
		MappingPage::register();
		CreatePage::register();
		Notices::register();
	}

	public static function menus(): void {
		$type = get_post_type_object( 'works' );
		$cap  = $type ? (string) $type->cap->edit_posts : 'edit_posts';
		add_menu_page( 'AI施工事例', 'AI施工事例', $cap, self::SLUG, array( CreatePage::class, 'render' ), 'dashicons-format-gallery', 26 );
		add_submenu_page( self::SLUG, '施工事例をAIで作成', '新規作成', $cap, self::SLUG, array( CreatePage::class, 'render' ) );
		add_submenu_page( self::SLUG, '接続・診断', '接続・診断', 'manage_options', self::DIAGNOSTICS_SLUG, array( DiagnosticsPage::class, 'render' ) );
		MappingPage::$hook = (string) add_submenu_page( self::SLUG, 'マッピング', 'マッピング', 'manage_options', MappingPage::SLUG, array( MappingPage::class, 'render' ) );
	}

	public static function mapping_url(): string {
		return MappingPage::url();
	}

	public static function diagnostics_url(): string {
		return admin_url( 'admin.php?page=' . self::DIAGNOSTICS_SLUG );
	}
}
