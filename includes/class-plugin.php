<?php
/**
 * Coordinates the plugin lifecycle.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR;

use SafeSR\Admin\Admin_Page;
use SafeSR\Cli\Cli_Command;
use SafeSR\Cli\Cli_IO;
use SafeSR\Db\Schema;
use SafeSR\Integrations\Cache_Purge;
use SafeSR\Jobs\Batch_Runner;
use SafeSR\Jobs\Backup_Prune;
use SafeSR\Jobs\Job_Manager;
use SafeSR\Rest\Rest_Controller;

/**
 * Main plugin controller.
 */
final class Plugin {

	/**
	 * Shared plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Stores the installed plugin version.
	 *
	 * Some activation paths fire the hook without the network flag, so the
	 * parameter stays untyped and is normalized here.
	 *
	 * @param bool|null $network_wide Whether the plugin is being activated network-wide.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		$network_wide = (bool) $network_wide;
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				self::install_for_site( (int) $site_id );
			}
			return;
		}

		self::install_current_site();
	}

	/**
	 * Leaves plugin data intact when the plugin is deactivated.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'safesr_prune_backups', array(), 'database-search-replace' );
		}
	}

	/**
	 * Schedules the daily backup cleanup after Action Scheduler initializes.
	 *
	 * @return void
	 */
	public static function schedule_cleanup(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( 'safesr_prune_backups', array(), 'database-search-replace' ) ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'safesr_prune_backups', array(), 'database-search-replace', true );
		}
	}

	/**
	 * Registers services needed for the current request.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( (string) get_option( 'safesr_schema_version', '' ) !== Schema::VERSION ) {
			Schema::install();
			update_option( 'safesr_schema_version', Schema::VERSION );
		}
		if ( (string) get_option( 'safesr_version', '' ) !== (string) constant( 'SAFESR_VERSION' ) ) {
			update_option( 'safesr_version', (string) constant( 'SAFESR_VERSION' ) );
		}

		add_action( 'wp_initialize_site', array( self::class, 'initialize_site' ) );

		$manager = new Job_Manager();
		( new Cache_Purge( $manager ) )->register();
		$runner = new Batch_Runner( $manager );
		add_action( 'safesr_run_chunk', array( $runner, 'run_chunk' ), 10, 1 );
		$backup_prune = new Backup_Prune();
		add_action( 'safesr_prune_backups', array( $backup_prune, 'prune' ) );
		add_action( 'init', array( self::class, 'schedule_cleanup' ), 2 );
		add_action( 'action_scheduler_ensure_recurring_actions', array( self::class, 'schedule_cleanup' ) );
		$rest = new Rest_Controller( $manager );
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cli_io = new Cli_IO();
			$cli_io->add_command( 'safesr', new Cli_Command( $manager, null, $cli_io ) );
		}

		if ( is_admin() ) {
			add_filter( 'plugin_action_links_' . plugin_basename( (string) constant( 'SAFESR_PLUGIN_FILE' ) ), array( self::class, 'add_plugin_action_links' ) );
			$admin_page = new Admin_Page();
			$admin_page->register();
		}
	}

	/**
	 * Adds the whole-site migration link to this plugin's row.
	 *
	 * @param string[] $links Existing plugin action links.
	 * @return string[]
	 */
	public static function add_plugin_action_links( array $links ): array {
		$migration_link = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener" style="color: #008a20; font-weight: 600;">%2$s</a>',
			esc_url( 'https://safeguard.verdelic.com/migrate?utm_source=ssr-plugin&utm_medium=plugins-list' ),
			esc_html__( 'Migrate a whole site', 'database-search-replace' )
		);
		array_unshift( $links, $migration_link );

		return $links;
	}

	/**
	 * Installs per-site storage when a network-active plugin receives a new site.
	 *
	 * @param \WP_Site $new_site Newly initialized site.
	 * @return void
	 */
	public static function initialize_site( \WP_Site $new_site ): void {
		if ( ! is_multisite() ) {
			return;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( (string) constant( 'SAFESR_PLUGIN_FILE' ) ) ) ) {
			return;
		}
		self::install_for_site( (int) $new_site->blog_id );
	}

	/**
	 * Installs schema and version state for one site without changing blog context.
	 *
	 * @return void
	 */
	private static function install_current_site(): void {
		Schema::install();
		update_option( 'safesr_version', (string) constant( 'SAFESR_VERSION' ) );
		update_option( 'safesr_schema_version', Schema::VERSION );
		if ( did_action( 'action_scheduler_init' ) ) {
			self::schedule_cleanup();
		}
	}

	/**
	 * Installs schema and version state inside one site's blog context.
	 *
	 * @param int $site_id Site identifier.
	 * @return void
	 */
	private static function install_for_site( int $site_id ): void {
		switch_to_blog( $site_id );
		try {
			self::install_current_site();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Restricts construction to the shared instance.
	 */
	private function __construct() {
	}
}
