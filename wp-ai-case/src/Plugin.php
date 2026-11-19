<?php
/**
 * プラグインの起動処理。
 *
 * C0/C1-A の段階では、外部通信・管理画面・REST は持たない。
 * WP-CLI コマンドと、開発専用ツール（dev/ が存在するときのみ）だけを登録する。
 *
 * @package WpAiCase
 */

namespace WpAiCase;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const POST_TYPE = 'works';

	/** 施工事例カテゴリのタクソノミー（ACF の適用条件には使われていない）。 */
	public const CATEGORY_TAXONOMY = 'works_cat';

	public static function category_taxonomy(): string {
		$tax = (string) apply_filters( 'wp_ai_case_category_taxonomy', self::CATEGORY_TAXONOMY );
		return taxonomy_exists( $tax ) && is_object_in_taxonomy( self::POST_TYPE, $tax ) ? $tax : '';
	}

	public static function boot(): void {
		if ( ! self::acf_pro_available() ) {
			add_action( 'admin_notices', array( self::class, 'notice_acf_missing' ) );
			return;
		}

		Support\Installer::maybe_install();
		Rest\RestController::register();
		Rest\JobController::register();
		Support\Maintenance::register();
		if ( is_admin() ) {
			Admin\Menu::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ai-case', Cli\Commands::class );
			\WP_CLI::add_command( 'ai-case mapping', Cli\MappingCommand::class );
			\WP_CLI::add_command( 'ai-case display', Cli\DisplayCommand::class );
			\WP_CLI::add_command( 'ai-case article', Cli\ArticleCommand::class );
		}

		$dev_bootstrap = WP_AI_CASE_DIR . '/dev/bootstrap.php';
		if ( is_readable( $dev_bootstrap ) ) {
			require_once $dev_bootstrap;
		}
	}

	/**
	 * ACF Pro（Repeater / Flexible Content を含む）が有効か。
	 * フィールド型は init で登録されるため、plugins_loaded の時点では ACF Pro の有無だけを見る。
	 */
	public static function acf_pro_available(): bool {
		return function_exists( 'acf_get_field_groups' ) && defined( 'ACF_PRO' ) && ACF_PRO;
	}

	public static function notice_acf_missing(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>WP AI Case: Advanced Custom Fields PRO が有効ではないため、機能を停止しています。</p></div>';
	}
}
