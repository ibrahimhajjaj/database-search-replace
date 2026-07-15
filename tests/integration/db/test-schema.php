<?php
/**
 * Integration tests for database schema migrations.
 *
 * @package SafeSearchReplace
 */

use SafeSR\Db\Schema;
use SafeSR\Jobs\Job_Manager;

/**
 * Verifies schema upgrades against existing plugin tables.
 */
class SafeSR_Schema_Test extends SafeSR_Integration_Test_Case {

	/**
	 * A legacy jobs table receives the monotonic sequence column.
	 *
	 * @return void
	 */
	public function test_install_adds_sequence_column_to_legacy_jobs_table() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- The legacy schema fixture must omit the sequence column.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN seq', Schema::jobs_table_name() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema metadata must reflect the fixture before the migration runs.
		$legacy_column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', Schema::jobs_table_name(), 'seq' ) );
		$this->assertNull( $legacy_column );

		Schema::install();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema metadata verifies the installed column directly.
		$installed_column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', Schema::jobs_table_name(), 'seq' ) );
		$this->assertSame( 'seq', $installed_column );

		$wpdb->last_error = '';
		$this->assertSame( '', ( new Job_Manager() )->latest_undoable_apply_id() );
		$this->assertSame( '', $wpdb->last_error );
	}

	/**
	 * The operation ledger uses fixed-width hashes for values and uniqueness.
	 *
	 * @return void
	 */
	public function test_operations_schema_uses_hash_columns() {
		global $wpdb;
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Schema::operations_table_name() ) );
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Non_unique = %d', Schema::operations_table_name(), 0 ), ARRAY_A );

		$this->assertContains( 'cell_hash', $columns );
		$this->assertContains( 'expected_hash', $columns );
		$this->assertContains( 'applied_hash', $columns );
		$this->assertNotContains( 'expected_value', $columns );
		$this->assertNotContains( 'applied_value', $columns );
		$this->assertContains( 'cell_hash', wp_list_pluck( $indexes, 'Column_name' ) );
	}
}
