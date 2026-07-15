<?php
/**
 * Integration tests for database table discovery.
 *
 * @package SafeSearchReplace
 */

use SafeSR\Db\Schema;
use SafeSR\Db\Table_Scanner;

/**
 * Verifies table metadata and safety classification against WordPress tables.
 */
class SafeSR_Table_Scanner_Test extends SafeSR_Integration_Test_Case {

	/**
	 * Physical name of the table without a primary key.
	 *
	 * @var string
	 */
	private string $no_pk_table;

	/**
	 * Creates a table without a primary key for safety detection.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;

		$this->no_pk_table = $wpdb->prefix . 'safesr_no_pk';
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (content TEXT)', $this->no_pk_table ) );
	}

	/**
	 * Removes the table created for the current test.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->no_pk_table ) );
		parent::tear_down();
	}

	/**
	 * Plugin-owned tables stay out of scans so a run cannot edit its own job or backup rows.
	 *
	 * @return void
	 */
	public function test_plugin_tables_are_never_listed_or_processed() {
		global $wpdb;
		$names = array_column( ( new Table_Scanner() )->get_tables(), 'name' );

		foreach ( $names as $name ) {
			$this->assertStringNotContainsString( $wpdb->prefix . 'safesr_', $name );
		}

		$config = new \SafeSR\Db\Run_Config(
			new \SafeSR\Engine\Search_Config( 'safesr', 'renamed', true ),
			array( \SafeSR\Db\Schema::jobs_table_name() ),
			false,
			false,
			500,
			true
		);
		$result = ( new \SafeSR\Db\Row_Processor() )->process_table_batch( $config, \SafeSR\Db\Schema::jobs_table_name(), null );

		$this->assertTrue( $result->is_done() );
		$this->assertSame( 0, $result->get_rows_scanned() );
		$this->assertCount( 0, $result->get_changes() );
	}

	/**
	 * Requested tables are reduced to the current site's scan set.
	 *
	 * @return void
	 */
	public function test_filter_allowed_drops_tables_outside_the_scan_set() {
		global $wpdb;
		$scanner = new Table_Scanner();
		$allowed = $scanner->filter_allowed(
			array(
				$wpdb->posts,
				$wpdb->users,
				'wp_9_posts',
				Schema::jobs_table_name(),
				'not_a_real_table',
			)
		);

		$this->assertContains( $wpdb->posts, $allowed );
		$this->assertNotContains( $wpdb->users, $allowed, 'Network-global tables never enter a site-level run.' );
		$this->assertNotContains( 'wp_9_posts', $allowed );
		$this->assertNotContains( Schema::jobs_table_name(), $allowed );
		$this->assertNotContains( 'not_a_real_table', $allowed );
	}

	/**
	 * Another blog's tables never belong to the base site.
	 *
	 * @return void
	 */
	public function test_other_blog_tables_do_not_belong_to_base_site() {
		global $wpdb;
		if ( $wpdb->base_prefix !== $wpdb->prefix ) {
			$this->markTestSkipped( 'Runs on the network base site.' );
		}
		$scanner = new Table_Scanner();

		$this->assertTrue( $scanner->belongs_to_current_site( $wpdb->prefix . 'posts' ) );
		$this->assertFalse( $scanner->belongs_to_current_site( $wpdb->prefix . '2_posts' ) );
		$this->assertFalse( $scanner->belongs_to_current_site( 'other_posts' ) );
	}

	/**
	 * Network-global tables stay outside site-level discovery.
	 *
	 * @return void
	 */
	public function test_global_tables_are_excluded_from_site_level_discovery() {
		global $wpdb;
		$tables  = ( new Table_Scanner() )->get_tables();
		$by_name = array_column( $tables, null, 'name' );

		$this->assertArrayHasKey( $wpdb->posts, $by_name );
		$this->assertIsInt( $by_name[ $wpdb->posts ]['rows'] );
		$this->assertIsInt( $by_name[ $wpdb->posts ]['data_size'] );
		$this->assertFalse( $by_name[ $wpdb->posts ]['protected'] );
		foreach ( $wpdb->global_tables as $property ) {
			if ( isset( $wpdb->{$property} ) && is_string( $wpdb->{$property} ) ) {
				$this->assertArrayNotHasKey( $wpdb->{$property}, $by_name );
			}
		}
	}

	/**
	 * Saved and filtered table names extend the protected set.
	 *
	 * @return void
	 */
	public function test_saved_and_filtered_tables_extend_protection() {
		global $wpdb;
		update_option( 'safesr_protected_tables', array( $wpdb->posts ) );
		$filter = static function ( array $tables ) use ( $wpdb ): array {
			$tables[] = $wpdb->comments;
			return $tables;
		};
		add_filter( 'safesr_protected_tables', $filter );

		$protected = ( new Table_Scanner() )->get_protected_tables();

		remove_filter( 'safesr_protected_tables', $filter );
		$this->assertContains( $wpdb->posts, $protected );
		$this->assertContains( $wpdb->comments, $protected );
	}

	/**
	 * Posts expose only textual columns and their primary key.
	 *
	 * @return void
	 */
	public function test_text_columns_and_primary_keys_are_detected() {
		global $wpdb;
		$scanner = new Table_Scanner();
		$columns = $scanner->get_text_columns( $wpdb->posts );

		$this->assertContains( 'post_content', $columns );
		$this->assertContains( 'guid', $columns );
		$this->assertNotContains( 'ID', $columns );
		$this->assertSame( array( 'ID' ), $scanner->get_primary_key( $wpdb->posts ) );
		$this->assertNull( $scanner->get_primary_key( $this->no_pk_table ) );
	}
}
