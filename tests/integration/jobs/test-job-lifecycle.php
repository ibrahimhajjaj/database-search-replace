<?php
/**
 * Integration tests for background job lifecycle behavior.
 *
 * @package SafeSearchReplace
 */

use SafeSR\Db\Run_Config;
use SafeSR\Jobs\Batch_Runner;
use SafeSR\Jobs\Job_Manager;

require_once dirname( __DIR__ ) . '/fixtures/class-northwind-fixture.php';

/**
 * Verifies job locking, progress, cancellation, backup, and undo behavior.
 */
class SafeSR_Job_Lifecycle_Test extends SafeSR_Integration_Test_Case {

	/**
	 * Isolated table used by cell-safety tests.
	 *
	 * @var string
	 */
	private string $safety_table;

	/**
	 * Removes job state before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'safesr_active_job' );
		global $wpdb;
		$this->safety_table = $wpdb->prefix . 'ssr_safety_fixture';
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->safety_table ) );
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id BIGINT UNSIGNED NOT NULL, content TEXT NOT NULL, PRIMARY KEY (id))', $this->safety_table ) );
	}

	/**
	 * Removes isolated safety data.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->safety_table ) );
		parent::tear_down();
	}

	/**
	 * Replaying a batch from an old cursor never applies a cell twice.
	 *
	 * @return void
	 */
	public function test_replayed_apply_batch_is_idempotent() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$wpdb->insert(
			$this->safety_table,
			array(
				'id'      => 1,
				'content' => 'a',
			)
		);
		$wpdb->insert(
			$this->safety_table,
			array(
				'id'      => 2,
				'content' => 'a',
			)
		);
		$manager    = new Job_Manager();
		$runner     = new Batch_Runner( $manager );
		$preview_id = $manager->create_preview( $this->safety_config( 'a', 'aa', 1, true ) );
		$this->finish( $runner, $manager, $preview_id );
		$job_id = $manager->create_apply( $this->safety_config( 'a', 'aa', 1 ), true, $preview_id );

		$runner->run_chunk( $job_id, 0 );
		$job                               = $manager->get_job( $job_id );
		$job['progress']['current_cursor'] = null;
		$this->assertTrue( $manager->update_progress( $job_id, $job['progress'] ) );
		$runner->run_chunk( $job_id, 0 );

		$values = $wpdb->get_col( $wpdb->prepare( 'SELECT content FROM %i ORDER BY id', $this->safety_table ) );
		$this->assertSame( 'aa', $values[0] );
		$this->assertNotContains( 'aaaa', $values, true );
		$this->assertSame( 0, $manager->get_job( $job_id )['progress']['skipped'] );
	}

	/**
	 * A failed progress write terminates the job after its durable cell write.
	 *
	 * @return void
	 */
	public function test_progress_persistence_failure_is_fatal() {
		global $wpdb;
		$wpdb->insert(
			$this->safety_table,
			array(
				'id'      => 1,
				'content' => 'old',
			)
		);
		$manager                  = new class() extends Job_Manager {
			/**
			 * Whether test progress writes are rejected.
			 *
			 * @var bool
			 */
			public bool $reject_progress = false;

			/**
			 * Optionally rejects progress persistence.
			 *
			 * @param string              $job_id  Job identifier.
			 * @param array<string,mixed> $progress Progress values.
			 * @return bool
			 */
			public function update_progress( string $job_id, array $progress ): bool {
				if ( $this->reject_progress ) {
					return false;
				}
				return parent::update_progress( $job_id, $progress );
			}
		};
		$job_id                   = $manager->create_apply( $this->safety_config( 'old', 'new', 50 ) );
		$manager->reject_progress = true;
		( new Batch_Runner( $manager ) )->run_chunk( $job_id, 0 );

		$job = $manager->get_job( $job_id );
		$this->assertSame( 'failed', $job['status'] );
		$this->assertStringContainsString( 'progress', strtolower( (string) $job['error'] ) );
	}

	/**
	 * Undo preserves a value edited after apply and reports the conflict.
	 *
	 * @return void
	 */
	public function test_undo_preserves_later_edits_and_reports_conflict() {
		global $wpdb;
		$wpdb->insert(
			$this->safety_table,
			array(
				'id'      => 1,
				'content' => 'old',
			)
		);
		$manager  = new Job_Manager();
		$runner   = new Batch_Runner( $manager );
		$apply_id = $manager->create_apply( $this->safety_config( 'old', 'new', 50 ) );
		$this->finish( $runner, $manager, $apply_id );
		$wpdb->update( $this->safety_table, array( 'content' => 'later edit' ), array( 'id' => 1 ) );

		$undo_id = $manager->create_undo( $apply_id );
		$this->finish( $runner, $manager, $undo_id );
		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT content FROM %i WHERE id = %d', $this->safety_table, 1 ) );
		$undo    = $manager->get_job( $undo_id );

		$this->assertSame( 'later edit', $current );
		$this->assertGreaterThan( 0, $undo['progress']['skipped'] );
	}

	/**
	 * Restore rejects snapshot identifiers outside the original live allowlist.
	 *
	 * @return void
	 */
	public function test_undo_rejects_tampered_table_identifier() {
		global $wpdb;
		$outside = $wpdb->prefix . 'ssr_outside_fixture';
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id BIGINT UNSIGNED NOT NULL, content TEXT NOT NULL, PRIMARY KEY (id))', $outside ) );
		$wpdb->insert(
			$this->safety_table,
			array(
				'id'      => 1,
				'content' => 'old',
			)
		);
		$wpdb->insert(
			$outside,
			array(
				'id'      => 1,
				'content' => 'new',
			)
		);
		$manager  = new Job_Manager();
		$runner   = new Batch_Runner( $manager );
		$apply_id = $manager->create_apply( $this->safety_config( 'old', 'new', 50 ) );
		$this->finish( $runner, $manager, $apply_id );
		$apply = $manager->get_job( $apply_id );
		$wpdb->update(
			SafeSR\Db\Schema::backup_rows_table_name(),
			array( 'table_name' => $outside ),
			array( 'backup_id' => $apply['backup_id'] )
		);

		$undo_id = $manager->create_undo( $apply_id );
		$this->finish( $runner, $manager, $undo_id );
		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT content FROM %i WHERE id = %d', $outside, 1 ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $outside ) );

		$this->assertSame( 'new', $current );
		$this->assertGreaterThan( 0, $manager->get_job( $undo_id )['progress']['skipped'] );
	}

	/**
	 * Preview-bound apply changes only candidates whose bytes still match.
	 *
	 * @return void
	 */
	public function test_preview_bound_apply_preserves_candidates_edited_after_preview() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$wpdb->insert(
			$this->safety_table,
			array(
				'id'      => 1,
				'content' => 'old',
			)
		);
		$manager    = new Job_Manager();
		$runner     = new Batch_Runner( $manager );
		$preview_id = $manager->create_preview( $this->safety_config( 'old', 'new', 50, true ) );
		$this->finish( $runner, $manager, $preview_id );
		$wpdb->update( $this->safety_table, array( 'content' => 'edited after preview' ), array( 'id' => 1 ) );

		$apply_id = $manager->create_apply( $this->safety_config( 'old', 'new', 50 ), true, $preview_id );
		$this->finish( $runner, $manager, $apply_id );
		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT content FROM %i WHERE id = %d', $this->safety_table, 1 ) );

		$this->assertSame( 'edited after preview', $current );
		$this->assertGreaterThan( 0, $manager->get_job( $apply_id )['progress']['skipped'] );
	}

	/**
	 * Conflict totals include candidates beyond the retained diff sample.
	 *
	 * @return void
	 */
	public function test_preview_bound_apply_counts_all_conflicts() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		for ( $id = 1; $id <= 25; ++$id ) {
			$wpdb->insert(
				$this->safety_table,
				array(
					'id'      => $id,
					'content' => 'old',
				)
			);
		}
		$manager    = new Job_Manager();
		$runner     = new Batch_Runner( $manager );
		$preview_id = $manager->create_preview( $this->safety_config( 'old', 'new', 50, true ) );
		$this->finish( $runner, $manager, $preview_id );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET content = %s', $this->safety_table, 'edited after preview' ) );

		$apply_id = $manager->create_apply( $this->safety_config( 'old', 'new', 50 ), true, $preview_id );
		$this->finish( $runner, $manager, $apply_id );

		$this->assertSame( 25, $manager->get_job( $apply_id )['progress']['skipped'] );
	}

	/**
	 * A preview advances through persisted cursors and releases the site lock.
	 *
	 * @return void
	 */
	public function test_preview_runs_to_completion_and_blocks_a_second_active_job() {
		new SafeSR_Northwind_Fixture( self::factory() );
		$manager = new Job_Manager();
		$job_id  = $manager->create_preview( $this->config( true, 1 ) );

		$this->expectException( RuntimeException::class );
		try {
			$manager->create_preview( $this->config( true, 1 ) );
		} finally {
			$runner = new Batch_Runner( $manager );
			do {
				$runner->run_chunk( $job_id, 0 );
				$job = $manager->get_job( $job_id );
			} while ( 'completed' !== $job['status'] );

			$this->assertGreaterThan( 0, $job['progress']['rows_scanned'] );
			$this->assertSame( $job['progress']['tables_total'], $job['progress']['tables_done'] );
			$this->assertFalse( get_option( 'safesr_active_job', false ) );
		}
	}

	/**
	 * Apply backups restore complete table rows and permit a later re-apply.
	 *
	 * @return void
	 */
	public function test_apply_backup_and_undo_restore_original_values() {
		global $wpdb;
		new SafeSR_Northwind_Fixture( self::factory() );
		$tables  = array( $wpdb->options, $wpdb->posts, $wpdb->postmeta );
		$before  = $this->dump_tables( $tables );
		$manager = new Job_Manager();
		$runner  = new Batch_Runner( $manager );

		$apply_id = $manager->create_apply( $this->config( false, 2 ) );
		$this->finish( $runner, $manager, $apply_id );
		$apply   = $manager->get_job( $apply_id );
		$applied = $this->dump_tables( $tables );
		$count   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE backup_id = %s', SafeSR\Db\Schema::backup_rows_table_name(), $apply['backup_id'] ) );
		$this->assertGreaterThan( 0, $count );
		$this->assertNotSame( $before, $applied );

		$undo_id = $manager->create_undo( $apply_id );
		$this->finish( $runner, $manager, $undo_id );
		$this->assertSame( $before, $this->dump_tables( $tables ) );
		$this->assertNotEmpty( $manager->get_job( $apply_id )['summary']['undone_at'] );

		$reapply_id = $manager->create_apply( $this->config( false, 2 ) );
		$this->finish( $runner, $manager, $reapply_id );
		$this->assertSame( $applied, $this->dump_tables( $tables ) );
		$this->assertSame( $apply['progress']['replacements'], $manager->get_job( $reapply_id )['progress']['replacements'] );
	}

	/**
	 * Apply completion is emitted once even when completed chunks are retried.
	 *
	 * @return void
	 */
	public function test_apply_completion_hook_fires_exactly_once() {
		new SafeSR_Northwind_Fixture( self::factory() );
		$fired    = 0;
		$manager  = new Job_Manager();
		$runner   = new Batch_Runner( $manager );
		$listener = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( 'safesr_replace_completed', $listener );

		$apply_id = $manager->create_apply( $this->config( false, 2 ) );
		$this->finish( $runner, $manager, $apply_id );
		$runner->run_chunk( $apply_id, 0 );
		$runner->run_chunk( $apply_id, 20 );

		remove_action( 'safesr_replace_completed', $listener );
		$this->assertSame( 1, $fired );
	}

	/**
	 * Zero-second chunks reach one-shot totals over multiple requests.
	 *
	 * @return void
	 */
	public function test_tiny_time_budget_matches_one_shot_totals_across_multiple_chunks() {
		new SafeSR_Northwind_Fixture( self::factory() );
		$manager = new Job_Manager();
		$runner  = new Batch_Runner( $manager );

		$one_shot_id = $manager->create_preview( $this->config( true, 500 ) );
		$runner->run_chunk( $one_shot_id, 20 );
		$one_shot = $manager->get_job( $one_shot_id );
		$this->assertSame( 'completed', $one_shot['status'] );

		$chunked_id = $manager->create_preview( $this->config( true, 1 ) );
		$chunks     = 0;
		do {
			$runner->run_chunk( $chunked_id, 0 );
			++$chunks;
			$chunked = $manager->get_job( $chunked_id );
		} while ( 'completed' !== $chunked['status'] );

		$this->assertGreaterThan( 1, $chunks );
		foreach ( array( 'rows_scanned', 'matches', 'replacements', 'skipped' ) as $total ) {
			$this->assertSame( $one_shot['progress'][ $total ], $chunked['progress'][ $total ] );
		}
		$this->assertSame( $one_shot['summary'], $chunked['summary'] );
	}

	/**
	 * Canceled jobs do not process further batches.
	 *
	 * @return void
	 */
	public function test_canceled_job_stops_before_scanning_rows() {
		new SafeSR_Northwind_Fixture( self::factory() );
		$manager = new Job_Manager();
		$job_id  = $manager->create_preview( $this->config( true, 1 ) );
		$this->assertTrue( $manager->cancel( $job_id ) );

		( new Batch_Runner( $manager ) )->run_chunk( $job_id, 20 );

		$job = $manager->get_job( $job_id );
		$this->assertSame( 'canceled', $job['status'] );
		$this->assertSame( 0, $job['progress']['rows_scanned'] );
	}

	/**
	 * Opting out of the snapshot writes no backup and leaves nothing to undo.
	 *
	 * @return void
	 */
	public function test_apply_without_backup_cannot_be_undone() {
		global $wpdb;
		new SafeSR_Northwind_Fixture( self::factory() );
		$manager = new Job_Manager();
		$runner  = new Batch_Runner( $manager );

		$apply_id = $manager->create_apply( $this->config( false, 2 ), false );
		$this->finish( $runner, $manager, $apply_id );

		$apply = $manager->get_job( $apply_id );
		$this->assertEmpty( $apply['backup_id'] );
		$rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				SafeSR\Db\Schema::backup_rows_table_name()
			)
		);
		$this->assertSame( 0, $rows );

		$this->expectException( InvalidArgumentException::class );
		$manager->create_undo( $apply_id );
	}

	/**
	 * Undo restores the earliest snapshot when a cell was backed up twice.
	 *
	 * @return void
	 */
	public function test_undo_restores_the_earliest_backup_of_a_cell() {
		global $wpdb;
		new SafeSR_Northwind_Fixture( self::factory() );
		$manager = new Job_Manager();
		$runner  = new Batch_Runner( $manager );

		$apply_id = $manager->create_apply( $this->config( false, 50 ) );
		$this->finish( $runner, $manager, $apply_id );
		$apply = $manager->get_job( $apply_id );

		// Reuse the key exactly as the apply stored it, then snapshot the
		// siteurl cell a second time at its post-change value, as an
		// overlapping worker restarting from an old cursor could have done.
		$siteurl_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM %i WHERE option_name = %s', $wpdb->options, 'siteurl' ) );
		$siteurl_pk = '';
		$backups    = $wpdb->get_results( $wpdb->prepare( 'SELECT row_pk FROM %i WHERE backup_id = %s AND table_name = %s', SafeSR\Db\Schema::backup_rows_table_name(), $apply['backup_id'], $wpdb->options ), ARRAY_A );
		foreach ( $backups as $backup ) {
			$decoded = json_decode( (string) $backup['row_pk'], true );
			if ( is_array( $decoded ) && (int) ( $decoded['option_id'] ?? 0 ) === $siteurl_id ) {
				$siteurl_pk = (string) $backup['row_pk'];
				break;
			}
		}
		$this->assertNotSame( '', $siteurl_pk, 'The apply must have snapshotted siteurl.' );
		$wpdb->insert(
			SafeSR\Db\Schema::backup_rows_table_name(),
			array(
				'backup_id'      => $apply['backup_id'],
				'table_name'     => $wpdb->options,
				'column_name'    => 'option_value',
				'row_pk'         => $siteurl_pk,
				'original_value' => 'https://northwindcoffee.com/tampered',
				'created_at'     => current_time( 'mysql', true ),
			)
		);
		$apply['summary']['backup_count'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE backup_id = %s',
				SafeSR\Db\Schema::backup_rows_table_name(),
				$apply['backup_id']
			)
		);
		$manager->update_summary( $apply_id, $apply['summary'] );

		$undo_id = $manager->create_undo( $apply_id );
		$this->finish( $runner, $manager, $undo_id );

		$restored = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'siteurl' ) );
		$this->assertSame(
			SafeSR_Northwind_Fixture::OLD_URL,
			$restored,
			'The earliest snapshot, not the later duplicate, must win.'
		);
	}

	/**
	 * A failed run with partial writes is undone before an earlier completed run.
	 *
	 * @return void
	 */
	public function test_failed_run_with_partial_writes_is_undone_before_an_earlier_run() {
		global $wpdb;
		new SafeSR_Northwind_Fixture( self::factory() );
		$manager = new Job_Manager();
		$runner  = new Batch_Runner( $manager );

		// Run A applies fully, rewriting the staging URL to the live one.
		$a_id = $manager->create_apply( $this->config( false, 2 ) );
		$this->finish( $runner, $manager, $a_id );

		// Run B targets the now-live URL so it still has rows to change, snapshots
		// one partial batch, then fails before completing.
		$b_config  = new Run_Config(
			new SafeSR\Engine\Search_Config( SafeSR_Northwind_Fixture::NEW_URL, 'https://example.test', true, false ),
			array( $wpdb->options, $wpdb->posts, $wpdb->postmeta ),
			false,
			false,
			2,
			false
		);
		$b_id      = $manager->create_apply( $b_config );
		$backup_id = (string) $manager->get_job( $b_id )['backup_id'];
		$runner->run_chunk( $b_id, 0 );
		$b       = $manager->get_job( $b_id );
		$partial = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE backup_id = %s', SafeSR\Db\Schema::backup_rows_table_name(), $backup_id ) );
		$this->assertNotSame( 'completed', $b['status'], 'The scenario needs run B still mid-flight.' );
		$this->assertGreaterThan( 0, $partial, 'Run B must have snapshotted its partial writes.' );
		$manager->fail( $b_id, 'Simulated mid-run failure.' );
		$this->assertSame( 'failed', $manager->get_job( $b_id )['status'] );

		// The failed newer run, not the completed older one, is next in line.
		$this->assertSame( $b_id, $manager->latest_undoable_apply_id() );
		try {
			$manager->create_undo( $a_id );
			$this->fail( 'Undoing the earlier run before the failed newer run must be refused.' );
		} catch ( InvalidArgumentException $error ) {
			$this->assertStringContainsString( 'more recent', $error->getMessage() );
		}

		// Undoing B restores its partial writes and unblocks the earlier run.
		$undo_id = $manager->create_undo( $b_id );
		$this->finish( $runner, $manager, $undo_id );
		$this->assertNotEmpty( $manager->get_job( $b_id )['summary']['undone_at'] );
		$this->assertSame( $a_id, $manager->latest_undoable_apply_id() );
	}

	/**
	 * Returns the standard fixture configuration.
	 *
	 * @param bool $dry_run    Whether writes are disabled.
	 * @param int  $batch_size Rows per batch.
	 * @return Run_Config
	 */
	private function config( bool $dry_run, int $batch_size ): Run_Config {
		global $wpdb;
		return new Run_Config(
			SafeSR_Northwind_Fixture::search_config(),
			array( $wpdb->options, $wpdb->posts, $wpdb->postmeta ),
			false,
			false,
			$batch_size,
			$dry_run
		);
	}

	/**
	 * Builds a focused apply configuration for the isolated safety table.
	 *
	 * @param string $search     Search value.
	 * @param string $replace    Replacement value.
	 * @param int    $batch_size Rows per batch.
	 * @param bool   $dry_run    Whether writes are disabled.
	 * @return Run_Config
	 */
	private function safety_config( string $search, string $replace, int $batch_size, bool $dry_run = false ): Run_Config {
		return new Run_Config(
			new SafeSR\Engine\Search_Config( $search, $replace, true ),
			array( $this->safety_table ),
			false,
			false,
			$batch_size,
			$dry_run
		);
	}

	/**
	 * Runs direct chunks until a job reaches a terminal state.
	 *
	 * @param Batch_Runner $runner  Batch runner.
	 * @param Job_Manager  $manager Job storage.
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

	/**
	 * Returns every column value ordered by each table's primary key.
	 *
	 * @param string[] $tables Physical table names.
	 * @return array<string,array<int,array<string,mixed>>>
	 * @throws RuntimeException When a selected table does not have a primary key.
	 */
	private function dump_tables( array $tables ): array {
		global $wpdb;
		$dumps = array();
		foreach ( $tables as $table ) {
			$primary_key = ( new SafeSR\Db\Table_Scanner() )->get_primary_key( $table );
			if ( null === $primary_key ) {
				throw new RuntimeException( 'A table selected for the restore test does not have a primary key.' );
			}
			$this->assertCount( 1, $primary_key );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Full raw rows are the byte-for-byte restore oracle.
			$dumps[ $table ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY %i', $table, $primary_key[0] ), ARRAY_A );
		}
		return $dumps;
	}
}
