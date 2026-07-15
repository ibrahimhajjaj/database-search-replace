<?php
/**
 * Multisite integration tests for schema and table isolation.
 *
 * @package SafeSearchReplace
 */

use SafeSR\Db\Schema;
use SafeSR\Db\Table_Scanner;
use SafeSR\Plugin;

/**
 * Verifies network lifecycle and current-site table discovery.
 *
 * @group multisite
 */
class SafeSR_Multisite_Test extends SafeSR_Integration_Test_Case {

	/**
	 * Releases the active job option on the current site.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( 'safesr_active_job' );
		parent::tear_down();
	}

	/**
	 * Network activation installs plugin tables for every existing site.
	 *
	 * @return void
	 */
	public function test_network_activation_installs_tables_for_every_site() {
		$site_id = self::factory()->blog->create();

		Plugin::activate( true );

		$this->assert_site_tables_exist( get_current_blog_id() );
		$this->assert_site_tables_exist( $site_id );
	}

	/**
	 * Network-active installs create tables when WordPress initializes a new site.
	 *
	 * @return void
	 */
	public function test_new_network_site_receives_plugin_tables() {
		$plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		$plugins[ plugin_basename( SAFESR_PLUGIN_FILE ) ] = time();
		update_site_option( 'active_sitewide_plugins', $plugins );
		$site_id = self::factory()->blog->create();
		$site    = get_site( $site_id );

		Plugin::initialize_site( $site );

		$this->assert_site_tables_exist( $site_id );
	}

	/**
	 * A subsite sees only its own site tables.
	 *
	 * @return void
	 */
	public function test_subsite_scanner_excludes_other_sites_and_global_tables() {
		global $wpdb;
		$first_site  = self::factory()->blog->create();
		$second_site = self::factory()->blog->create();
		switch_to_blog( $first_site );
		$first_prefix  = $wpdb->prefix;
		$global_users  = $wpdb->users;
		$global_meta   = $wpdb->usermeta;
		$second_prefix = $wpdb->get_blog_prefix( $second_site );

		$scanner = new Table_Scanner();
		$tables  = wp_list_pluck( $scanner->get_tables(), 'name' );

		$this->assertContains( $first_prefix . 'options', $tables );
		$this->assertNotContains( $global_users, $tables );
		$this->assertNotContains( $global_meta, $tables );
		$this->assertTrue( $scanner->is_protected( $global_users ) );
		$this->assertTrue( $scanner->is_protected( $global_meta ) );
		foreach ( $tables as $table ) {
			$this->assertStringStartsNotWith( $second_prefix, $table );
		}
		restore_current_blog();
	}

	/**
	 * The main site cannot discover or allow network metadata tables.
	 *
	 * @return void
	 */
	public function test_main_site_scanner_excludes_network_global_tables() {
		global $wpdb;
		switch_to_blog( get_main_site_id() );
		$scanner = new Table_Scanner();
		$tables  = wp_list_pluck( $scanner->get_tables(), 'name' );
		$allowed = $scanner->filter_allowed( array( $wpdb->posts, $wpdb->sitemeta, $wpdb->blogs ) );

		$this->assertContains( $wpdb->posts, $tables );
		$this->assertNotContains( $wpdb->sitemeta, $tables );
		$this->assertNotContains( $wpdb->blogs, $tables );
		$this->assertSame( array( $wpdb->posts ), $allowed );
		restore_current_blog();
	}

	/**
	 * Asserts all plugin tables exist for one site.
	 *
	 * @param int $site_id Site identifier.
	 * @return void
	 */
	private function assert_site_tables_exist( int $site_id ): void {
		global $wpdb;
		switch_to_blog( $site_id );
		foreach ( array( Schema::table_name(), Schema::jobs_table_name(), Schema::backup_rows_table_name(), Schema::operations_table_name() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $found );
		}
		restore_current_blog();
	}
}
