<?php
/**
 * Shared integration test case.
 *
 * @package SafeSearchReplace
 */

use SafeSR\Db\Schema;

/**
 * Resets plugin-owned state before each integration test.
 */
abstract class SafeSR_Integration_Test_Case extends WP_UnitTestCase {

	/**
	 * Removes state that production commits can persist past test rollback.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->delete_plugin_rows();
		$this->delete_job_locks();
	}

	/**
	 * Deletes rows without issuing DDL inside the test transaction.
	 *
	 * @return void
	 */
	private function delete_plugin_rows(): void {
		global $wpdb;

		$tables = array(
			Schema::jobs_table_name(),
			Schema::table_name(),
			Schema::operations_table_name(),
			Schema::backup_rows_table_name(),
		);
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Committed fixture rows must not survive into the next test.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
	}

	/**
	 * Removes the site-wide job lock and any per-job worker locks.
	 *
	 * @return void
	 */
	private function delete_job_locks(): void {
		global $wpdb;

		delete_option( 'safesr_active_job' );
		$pattern = $wpdb->esc_like( '_transient_safesr_chunk_lock_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic lock names must be read before deletion so option caches are invalidated.
		$locks = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $pattern ) );
		foreach ( $locks as $lock ) {
			delete_option( $lock );
		}
	}
}
