<?php
/**
 * Integration tests for retained backup snapshots.
 *
 * @package SafeSearchReplace
 */

use SafeSR\Db\Run_Config;
use SafeSR\Jobs\Backup_Prune;
use SafeSR\Jobs\Batch_Runner;
use SafeSR\Jobs\Job_Manager;

require_once dirname( __DIR__ ) . '/fixtures/class-northwind-fixture.php';

/**
 * Verifies the backup retention boundary and undo availability.
 */
class SafeSR_Backup_Pruner_Test extends SafeSR_Integration_Test_Case {

	/**
	 * Releases active job state before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'safesr_active_job' );
	}

	/**
	 * Cleanup removes expired rows, preserves recent rows, and disables undo.
	 *
	 * @return void
	 */
	public function test_prune_removes_only_expired_backups_and_disables_undo() {
		global $wpdb;
		new SafeSR_Northwind_Fixture( self::factory() );
		$manager  = new Job_Manager();
		$runner   = new Batch_Runner( $manager );
		$apply_id = $manager->create_apply( $this->config() );
		$this->finish( $runner, $manager, $apply_id );
		$apply = $manager->get_job( $apply_id );

		$wpdb->update(
			SafeSR\Db\Schema::backup_rows_table_name(),
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( ( SAFESR_BACKUP_RETENTION_DAYS + 1 ) * DAY_IN_SECONDS ) ) ),
			array( 'backup_id' => $apply['backup_id'] )
		);
		$wpdb->insert(
			SafeSR\Db\Schema::backup_rows_table_name(),
			array(
				'backup_id'      => str_repeat( 'f', 32 ),
				'table_name'     => $wpdb->options,
				'column_name'    => 'option_value',
				'row_pk'         => '1',
				'original_value' => 'recent',
				'created_at'     => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);

		( new Backup_Prune() )->prune();

		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE backup_id = %s', SafeSR\Db\Schema::backup_rows_table_name(), $apply['backup_id'] ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE backup_id = %s', SafeSR\Db\Schema::backup_rows_table_name(), str_repeat( 'f', 32 ) ) ) );
		$this->assertFalse( $manager->backup_is_complete( $manager->get_job( $apply_id ) ) );
	}

	/**
	 * An expiring snapshot with a queued undo survives pruning.
	 *
	 * @return void
	 */
	public function test_prune_keeps_backups_referenced_by_an_in_flight_undo() {
		global $wpdb;
		new SafeSR_Northwind_Fixture( self::factory() );
		$manager  = new Job_Manager();
		$runner   = new Batch_Runner( $manager );
		$apply_id = $manager->create_apply( $this->config() );
		$this->finish( $runner, $manager, $apply_id );
		$apply = $manager->get_job( $apply_id );

		// Age the snapshot past the retention window.
		$wpdb->update(
			SafeSR\Db\Schema::backup_rows_table_name(),
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( ( SAFESR_BACKUP_RETENTION_DAYS + 1 ) * DAY_IN_SECONDS ) ) ),
			array( 'backup_id' => $apply['backup_id'] )
		);

		// A restore is queued against the expiring snapshot.
		$undo_id = $manager->create_undo( $apply_id );
		$this->assertSame( 'queued', $manager->get_job( $undo_id )['status'] );

		( new Backup_Prune() )->prune();

		// The snapshot must survive so the queued restore is not truncated mid-run.
		$this->assertGreaterThan(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE backup_id = %s', SafeSR\Db\Schema::backup_rows_table_name(), $apply['backup_id'] ) )
		);
	}

	/**
	 * Cleanup applies the retention window to durable operation rows.
	 *
	 * @return void
	 */
	public function test_prune_removes_only_expired_operation_rows() {
		global $wpdb;
		$old = gmdate( 'Y-m-d H:i:s', time() - ( ( SAFESR_BACKUP_RETENTION_DAYS + 1 ) * DAY_IN_SECONDS ) );
		$now = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		foreach (
			array(
				'old' => $old,
				'new' => $now,
			) as $job_id => $created_at
		) {
			$wpdb->insert(
				SafeSR\Db\Schema::operations_table_name(),
				array(
					'job_id'        => $job_id,
					'cell_hash'     => hash( 'sha256', $job_id ),
					'table_name'    => $wpdb->options,
					'column_name'   => 'option_value',
					'row_pk'        => '1',
					'expected_hash' => hash( 'sha256', 'before' ),
					'applied_hash'  => hash( 'sha256', 'after' ),
					'replacements'  => 1,
					'status'        => 'applied',
					'created_at'    => $created_at,
				)
			);
		}

		( new Backup_Prune() )->prune();

		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE job_id = %s', SafeSR\Db\Schema::operations_table_name(), 'old' ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE job_id = %s', SafeSR\Db\Schema::operations_table_name(), 'new' ) ) );
	}

	/**
	 * Builds an apply configuration for the fixture.
	 *
	 * @return Run_Config
	 */
	private function config(): Run_Config {
		global $wpdb;
		return new Run_Config( SafeSR_Northwind_Fixture::search_config(), array( $wpdb->options ), false, false, 2, false );
	}

	/**
	 * Runs a job until it reaches a terminal state.
	 *
	 * @param Batch_Runner $runner  Batch runner.
	 * @param Job_Manager  $manager Job manager.
	 * @param string       $job_id  Job identifier.
	 * @return void
	 */
	private function finish( Batch_Runner $runner, Job_Manager $manager, string $job_id ): void {
		do {
			$runner->run_chunk( $job_id, 0 );
			$job = $manager->get_job( $job_id );
		} while ( ! in_array( $job['status'], array( 'completed', 'failed', 'canceled' ), true ) );
		$this->assertSame( 'completed', $job['status'] );
	}
}
