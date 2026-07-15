<?php
/**
 * Integration tests for plugin activation and admin registration.
 *
 * @package SafeSearchReplace
 */

use SafeSR\Plugin;
use SafeSR\Db\Schema;

/**
 * Verifies the plugin lifecycle inside WordPress.
 */
class SafeSR_Activation_Test extends SafeSR_Integration_Test_Case {

	/**
	 * The plugin is loaded in the integration environment.
	 *
	 * @return void
	 */
	public function test_plugin_is_loaded() {
		$this->assertTrue( class_exists( Plugin::class ) );
	}

	/**
	 * Activation stores the installed plugin version.
	 *
	 * @return void
	 */
	public function test_activation_stores_plugin_version() {
		Plugin::activate();

		$this->assertSame( SAFESR_VERSION, get_option( 'safesr_version' ) );
		$this->assertSame( Schema::VERSION, get_option( 'safesr_schema_version' ) );
	}

	/**
	 * Schema upgrades run even when the plugin version is unchanged.
	 *
	 * @return void
	 */
	public function test_schema_upgrade_is_independent_of_plugin_version() {
		global $wpdb;
		Schema::install();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Schema::operations_table_name() ) );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN applied_value', Schema::backup_rows_table_name() ) );
		update_option( 'safesr_version', SAFESR_VERSION );
		update_option( 'safesr_schema_version', '1' );

		Plugin::instance()->init();

		$operations = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::operations_table_name() ) );
		$applied    = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', Schema::backup_rows_table_name(), 'applied_value' ) );
		$this->assertSame( Schema::operations_table_name(), $operations );
		$this->assertSame( 'applied_value', $applied );
		$this->assertSame( Schema::VERSION, get_option( 'safesr_schema_version' ) );
	}

	/**
	 * Activation schedules one recurring cleanup and deactivation removes it.
	 *
	 * @return void
	 */
	public function test_activation_schedules_backup_cleanup_idempotently() {
		as_unschedule_all_actions( 'safesr_prune_backups', array(), 'database-search-replace' );

		Plugin::activate();
		Plugin::activate();

		$this->assertTrue( as_has_scheduled_action( 'safesr_prune_backups', array(), 'database-search-replace' ) );

		Plugin::deactivate();
		$this->assertFalse( as_has_scheduled_action( 'safesr_prune_backups', array(), 'database-search-replace' ) );
	}

	/**
	 * Activation installs the change excerpt table idempotently.
	 *
	 * @return void
	 */
	public function test_activation_installs_change_table_idempotently() {
		global $wpdb;
		Plugin::activate();
		Plugin::activate();

		$table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table_name() ) );
		$this->assertSame( Schema::table_name(), $table );
	}

	/**
	 * Administrators receive the Tools submenu entry.
	 *
	 * @return void
	 */
	public function test_tools_submenu_is_registered_for_administrators() {
		global $submenu;

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'dashboard' );

		Plugin::instance()->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The test invokes WordPress's core menu registration hook.
		do_action( 'admin_menu' );

		$tools_pages = wp_list_pluck( $submenu['tools.php'], 2 );

		$this->assertContains( 'database-search-replace', $tools_pages );
	}
}
