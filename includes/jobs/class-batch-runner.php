<?php
/**
 * Resumable job chunk execution.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Jobs;

use RuntimeException;
use SafeSR\Db\Batch_Result;
use SafeSR\Db\Change;
use SafeSR\Db\Operation_Ledger;
use SafeSR\Db\Row_Processor;
use SafeSR\Db\Schema;
use SafeSR\Db\Table_Scanner;

/**
 * Processes database and restore batches within bounded requests.
 */
class Batch_Runner {

	// The lease outlasts a full run_chunk budget plus a slow batch, and is
	// renewed after every batch, so a live worker never loses the lock.
	private const LOCK_SECONDS = 120;

	// Matches the width of the backup row_pk column.
	private const MAX_ROW_PK_BYTES = 255;

	// Diff rows stored per table across the whole job. Replacement totals
	// count every occurrence; only the previewable sample is bounded.
	private const SAMPLE_LIMIT = 20;

	/**
	 * Persistent job service.
	 *
	 * @var Job_Manager
	 */
	private Job_Manager $manager;

	/**
	 * Supports an injected job service for tests.
	 *
	 * @param Job_Manager|null $manager Optional job service.
	 */
	public function __construct( ?Job_Manager $manager = null ) {
		$this->manager = $manager ?? new Job_Manager();
	}

	/**
	 * Processes batches until the budget is exhausted or the job completes.
	 *
	 * @param string $job_id              Job identifier.
	 * @param int    $time_budget_seconds Maximum wall-clock seconds after the first batch.
	 * @return void
	 */
	public function run_chunk( string $job_id, int $time_budget_seconds = 20 ): void {
		$token = $this->acquire_lock( $job_id );
		if ( '' === $token ) {
			return;
		}

		try {
			$job = $this->manager->get_job( $job_id );
			if ( null === $job || ! in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
				return;
			}
			if ( 'queued' === $job['status'] && ! $this->manager->transition( $job_id, 'running' ) ) {
				return;
			}

			$started = microtime( true );
			do {
				// A batch can outlast the lease on a slow row. If another worker
				// has taken over in the meantime, stop before writing again so
				// the two never process the same rows.
				if ( ! $this->owns_lock( $job_id, $token ) ) {
					return;
				}
				$job = $this->manager->get_job( $job_id );
				if ( null === $job || 'running' !== $job['status'] ) {
					return;
				}
				if ( 'undo' === $job['type'] ) {
					$complete = $this->run_undo_batch( $job );
				} else {
					$complete = $this->run_replace_batch( $job );
				}
				$this->renew_lock( $job_id, $token );
				if ( $complete ) {
					$this->complete( $job_id );
					return;
				}
			} while ( microtime( true ) - $started < max( 0, $time_budget_seconds ) );

			$this->manager->enqueue( $job_id );
		} catch ( \Throwable $error ) {
			$this->manager->fail( $job_id, $error->getMessage() );
		} finally {
			$this->release_lock( $job_id, $token );
		}
	}

	/**
	 * Processes one search or replacement batch.
	 *
	 * @param array<string,mixed> $job Job record.
	 * @return bool
	 * @throws RuntimeException When durable batch state cannot be persisted.
	 */
	private function run_replace_batch( array $job ): bool {
		$progress = $job['progress'];
		$config   = $this->manager->get_run_config( $job );
		$tables   = $config->get_tables();
		$index    = (int) $progress['tables_done'];
		if ( $index >= count( $tables ) ) {
			return true;
		}

		$table              = $tables[ $index ];
		$backup_id          = is_string( $job['backup_id'] ) ? $job['backup_id'] : '';
		$recorder           = null;
		$rollback           = null;
		$ledger             = new Operation_Ledger();
		$candidate_recorder = null;
		if ( 'apply' === $job['type'] && '' !== $backup_id ) {
			$recorder = function ( string $table_name, array $primary_key, array $values, array $applied_values ) use ( $backup_id ): array {
				return $this->record_backup( $backup_id, $table_name, $primary_key, $values, $applied_values );
			};
			$rollback = function ( array $backup_rows ): void {
				$this->rollback_backup( $backup_rows );
			};
		}
		if ( 'preview' === $job['type'] ) {
			$candidate_recorder = function ( string $table_name, array $primary_key, string $column, string $expected, string $applied, int $replacements ) use ( $ledger, $job ): void {
				$ledger->record_preview( (string) $job['id'], $table_name, $primary_key, $column, $expected, $applied, $replacements );
			};
		}
		$result = ( new Row_Processor(
			$recorder,
			null,
			null,
			$rollback,
			'apply' === $job['type'] ? $ledger : null,
			(string) $job['id'],
			(string) ( $job['config']['preview_job_id'] ?? '' ),
			$candidate_recorder
		) )->process_table_batch(
			$config,
			$table,
			is_string( $progress['current_cursor'] ) ? $progress['current_cursor'] : null
		);
		// The row processor is rebuilt each batch, so the per-table sample cap
		// is enforced here from a cumulative count. Without this a job over
		// many batches would store, and later load, an unbounded diff.
		$sampled = ( isset( $progress['sampled'] ) && is_array( $progress['sampled'] ) ) ? $progress['sampled'] : array();
		$already = (int) ( $sampled[ $table ] ?? 0 );
		$changes = array_slice( $result->get_changes(), 0, max( 0, self::SAMPLE_LIMIT - $already ) );
		if ( ! empty( $changes ) ) {
			$this->manager->record_changes( (string) $job['id'], $changes );
		}
		$sampled[ $table ]   = $already + count( $changes );
		$progress['sampled'] = $sampled;
		$this->merge_batch_counts( $progress, $job['summary'], $table, $result );

		if ( $result->is_done() ) {
			++$progress['tables_done'];
			$progress['current_cursor'] = null;
			$progress['current_table']  = $tables[ $progress['tables_done'] ] ?? '';
		} else {
			$progress['current_cursor'] = $result->get_next_cursor();
			$progress['current_table']  = $table;
		}
		$progress['last_run_at'] = time();
		if ( ! $this->manager->update_progress( (string) $job['id'], $progress ) ) {
			throw new RuntimeException( 'Job progress could not be persisted.' );
		}
		if ( ! $this->manager->update_summary( (string) $job['id'], $job['summary'] ) ) {
			throw new RuntimeException( 'Job summary could not be persisted.' );
		}
		return (int) $progress['tables_done'] >= count( $tables );
	}

	/**
	 * Adds one batch to global and per-table counters.
	 *
	 * @param array<string,mixed> $progress Job progress, updated by reference.
	 * @param array<string,mixed> $summary  Job summary, updated by reference.
	 * @param string              $table    Physical table name.
	 * @param Batch_Result        $result   Batch result.
	 * @return void
	 */
	private function merge_batch_counts( array &$progress, array &$summary, string $table, Batch_Result $result ): void {
		$matches = 0;
		$skipped = count( array_filter( $result->get_errors(), static fn( string $error ): bool => 0 === strpos( $error, 'conflict:' ) ) );
		foreach ( $result->get_changes() as $change ) {
			$matches += $change->get_replacements();
			if ( $change->is_skipped() && Change::SKIP_CONFLICT !== $change->get_skip_reason() ) {
				$skipped += max( 1, $change->get_replacements() );
			}
		}
		$progress['rows_scanned'] += $result->get_rows_scanned();
		$progress['matches']      += $matches;
		$progress['replacements'] += $result->get_replacements_count();
		$progress['skipped']      += $skipped;
		$current                   = $summary[ $table ] ?? array(
			'rows_scanned' => 0,
			'matches'      => 0,
			'replacements' => 0,
			'skipped'      => 0,
			'errors'       => array(),
		);
		$current['rows_scanned']  += $result->get_rows_scanned();
		$current['matches']       += $matches;
		$current['replacements']  += $result->get_replacements_count();
		$current['skipped']       += $skipped;
		$current['errors']         = array_merge( $current['errors'], $result->get_errors() );
		$summary[ $table ]         = $current;
	}

	/**
	 * Writes original column bytes before Row_Processor issues its update.
	 *
	 * @param string               $backup_id  Backup identifier.
	 * @param string               $table      Physical table name.
	 * @param array<string,mixed>  $primary_key Primary-key values.
	 * @param array<string,string> $values     Original changed values.
	 * @param array<string,string> $applied_values Applied changed values.
	 * @return int[]
	 * @throws RuntimeException When a backup row cannot be persisted before the write.
	 */
	private function record_backup( string $backup_id, string $table, array $primary_key, array $values, array $applied_values ): array {
		global $wpdb;
		$row_pk = wp_json_encode( $primary_key );
		if ( '' === $backup_id || ! is_string( $row_pk ) ) {
			throw new RuntimeException( 'The backup row key could not be encoded.' );
		}
		// The key column is fixed width. A key that would not fit cannot be
		// restored later, so refuse it before the row is changed rather than
		// let a silently truncated key make the undo unusable.
		if ( strlen( $row_pk ) > self::MAX_ROW_PK_BYTES ) {
			throw new RuntimeException( 'A primary key is too long to snapshot safely.' );
		}
		$ids = array();
		foreach ( $values as $column => $value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Pre-write snapshot.
			$inserted = $wpdb->insert(
				Schema::backup_rows_table_name(),
				array(
					'backup_id'      => $backup_id,
					'table_name'     => $table,
					'column_name'    => $column,
					'row_pk'         => $row_pk,
					'original_value' => $value,
					'applied_value'  => $applied_values[ $column ],
					'created_at'     => current_time( 'mysql', true ),
				)
			);
			if ( false === $inserted ) {
				throw new RuntimeException( 'A backup row could not be written.' );
			}
			$ids[] = (int) $wpdb->insert_id;
		}
		return $ids;
	}

	/**
	 * Removes backup rows whose guarded update did not land.
	 *
	 * @param int[] $ids Backup row identifiers.
	 * @return void
	 * @throws RuntimeException When an unused backup row cannot be removed.
	 */
	private function rollback_backup( array $ids ): void {
		global $wpdb;
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Remove snapshot after failed CAS.
			$deleted = $wpdb->delete( Schema::backup_rows_table_name(), array( 'id' => $id ) );
			if ( false === $deleted ) {
				throw new RuntimeException( 'An unused backup row could not be removed.' );
			}
		}
	}

	/**
	 * Restores one bounded backup batch by primary key.
	 *
	 * @param array<string,mixed> $job Job record.
	 * @return bool
	 * @throws RuntimeException When a backup row is unreadable or cannot be restored.
	 */
	private function run_undo_batch( array $job ): bool {
		global $wpdb;
		$progress = $job['progress'];
		$config   = $this->manager->get_run_config( $job );
		$cursor   = is_numeric( $progress['current_cursor'] ) ? (int) $progress['current_cursor'] : 0;
		$limit    = $config->get_batch_size() + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current backup rows required.
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE backup_id = %s AND id > %d ORDER BY id ASC LIMIT %d',
				Schema::backup_rows_table_name(),
				(string) $job['backup_id'],
				$cursor,
				$limit
			),
			ARRAY_A
		);
		$done      = count( $rows ) <= $config->get_batch_size();
		$rows      = array_slice( $rows, 0, $config->get_batch_size() );
		$conflicts = 0;
		$restored  = 0;
		foreach ( $rows as $row ) {
			$cursor = (int) $row['id'];

			// Only the earliest snapshot of a cell holds its true original. A
			// later duplicate can exist if a row was ever processed twice, so
			// skip it and let the earliest value be the one that lands.
			if ( $this->has_earlier_backup( (string) $job['backup_id'], $row ) ) {
				continue;
			}

			$primary_key = json_decode( (string) $row['row_pk'], true );
			if ( ! is_array( $primary_key ) || empty( $primary_key ) ) {
				throw new RuntimeException( 'A backup row contains an invalid primary key.' );
			}
			if ( ! $this->restore_target_is_allowed( $config->get_tables(), $row, $primary_key ) ) {
				++$conflicts;
				continue;
			}
			$updated = $this->restore_compare_and_swap( $row, $primary_key );
			if ( false === $updated ) {
				throw new RuntimeException( 'A backup row could not be restored.' );
			}
			if ( 0 === $updated ) {
				++$conflicts;
			} else {
				++$restored;
			}
		}
		$progress['rows_scanned']  += count( $rows );
		$progress['replacements']  += $restored;
		$progress['skipped']       += $conflicts;
		$progress['current_cursor'] = $done ? null : (string) $cursor;
		$progress['last_run_at']    = time();
		if ( $done ) {
			$progress['tables_done']   = $progress['tables_total'];
			$progress['current_table'] = '';
		}
		if ( ! $this->manager->update_progress( (string) $job['id'], $progress ) ) {
			throw new RuntimeException( 'Undo progress could not be persisted.' );
		}
		return $done;
	}

	/**
	 * Validates snapshot identifiers against the original config and live schema.
	 *
	 * @param string[]            $configured_tables Original table allowlist.
	 * @param array<string,mixed> $row Backup row.
	 * @param array<string,mixed> $primary_key Snapshot primary key.
	 * @return bool
	 */
	private function restore_target_is_allowed( array $configured_tables, array $row, array $primary_key ): bool {
		$table   = (string) $row['table_name'];
		$column  = (string) $row['column_name'];
		$scanner = new Table_Scanner();
		if ( ! in_array( $table, $configured_tables, true ) || array( $table ) !== $scanner->filter_allowed( array( $table ) ) || $scanner->is_protected( $table ) ) {
			return false;
		}
		if ( ! in_array( $column, $scanner->get_text_columns( $table ), true ) ) {
			return false;
		}
		$live_primary_key = $scanner->get_primary_key( $table );
		return null !== $live_primary_key && array_keys( $primary_key ) === $live_primary_key;
	}

	/**
	 * Restores original bytes only while the cell still contains applied bytes.
	 *
	 * @param array<string,mixed> $row Backup row.
	 * @param array<string,mixed> $primary_key Primary-key values.
	 * @return int|false
	 */
	private function restore_compare_and_swap( array $row, array $primary_key ) {
		global $wpdb;
		$table  = (string) $row['table_name'];
		$column = (string) $row['column_name'];
		$where  = array();
		$args   = array( $table, $column, $row['original_value'] );
		foreach ( $primary_key as $key => $value ) {
			$where[] = '%i = %s';
			$args[]  = $key;
			$args[]  = $value;
		}
		$where[] = 'BINARY %i = BINARY %s';
		$args[]  = $column;
		$args[]  = $row['applied_value'];
		$sql     = 'UPDATE %i SET %i = %s WHERE ' . implode( ' AND ', $where );
		// LONGBLOB bytes pass directly from the result through prepared value placeholders.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Validated identifiers; placeholders elsewhere.
		return $wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Reports whether an earlier backup exists for the same cell.
	 *
	 * @param string              $backup_id Backup identifier.
	 * @param array<string,mixed> $row       Backup row.
	 * @return bool
	 */
	private function has_earlier_backup( string $backup_id, array $row ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current backup rows required.
		$earlier = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE backup_id = %s AND table_name = %s AND column_name = %s AND row_pk = %s AND id < %d LIMIT 1',
				Schema::backup_rows_table_name(),
				$backup_id,
				(string) $row['table_name'],
				(string) $row['column_name'],
				(string) $row['row_pk'],
				(int) $row['id']
			)
		);
		return null !== $earlier;
	}

	/**
	 * Finalizes one job and runs apply or undo completion work once.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private function complete( string $job_id ): void {
		global $wpdb;
		$job = $this->manager->get_job( $job_id );
		if ( null === $job || 'running' !== $job['status'] ) {
			return;
		}

		if ( 'undo' === $job['type'] ) {
			// Mark the apply job undone while this undo job still holds the
			// active lock, so a second undo request cannot slip into the gap
			// after the lock releases and restore a stale backup.
			$apply_id = (string) ( $job['config']['apply_job_id'] ?? '' );
			$apply    = $this->manager->get_job( $apply_id );
			if ( null !== $apply ) {
				$apply['summary']['undone_at'] = current_time( 'mysql', true );
				$this->manager->update_summary( $apply_id, $apply['summary'] );
			}
			if ( ! $this->manager->transition( $job_id, 'completed' ) ) {
				return;
			}
			do_action( 'safesr_undo_completed', $this->manager->get_job( $job_id ) );
			return;
		}

		// A preview writes nothing, so it neither logs nor purges caches.
		if ( 'apply' !== $job['type'] ) {
			$this->manager->transition( $job_id, 'completed' );
			return;
		}

		if ( ! empty( $job['backup_id'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Snapshot size for undo.
			$job['summary']['backup_count'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE backup_id = %s',
					Schema::backup_rows_table_name(),
					(string) $job['backup_id']
				)
			);
		}

		if ( (bool) get_option( 'safesr_keep_logs', true ) ) {
			$job['summary']['log_available'] = true;
		}
		$this->manager->update_summary( $job_id, $job['summary'] );
		if ( ! $this->manager->transition( $job_id, 'completed' ) ) {
			return;
		}
		do_action( 'safesr_replace_completed', $this->manager->get_job( $job_id ) );
	}

	/**
	 * Acquires the per-job chunk lock and returns its ownership token.
	 *
	 * The stored value carries an owner token so a worker whose lease expired
	 * and was taken over by another worker can detect the loss and stop before
	 * it writes again. Returns an empty string when the lock is held.
	 *
	 * @param string $job_id Job identifier.
	 * @return string
	 */
	private function acquire_lock( string $job_id ): string {
		$key   = $this->lock_option( $job_id );
		$entry = (string) get_option( $key, '' );
		if ( '' !== $entry ) {
			$expires = (int) strtok( $entry, ':' );
			if ( $expires < time() ) {
				delete_option( $key );
			}
		}
		$token = $this->new_token();
		if ( add_option( $key, ( time() + self::LOCK_SECONDS ) . ':' . $token, '', false ) ) {
			return $token;
		}
		return '';
	}

	/**
	 * Reports whether the token still owns an unexpired lock.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $token  Ownership token.
	 * @return bool
	 */
	private function owns_lock( string $job_id, string $token ): bool {
		$entry = (string) get_option( $this->lock_option( $job_id ), '' );
		if ( '' === $entry ) {
			return false;
		}
		$expires = (int) strtok( $entry, ':' );
		$owner   = (string) strtok( '' );
		return $owner === $token && $expires >= time();
	}

	/**
	 * Extends the lease when the token still owns the lock.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $token  Ownership token.
	 * @return void
	 */
	private function renew_lock( string $job_id, string $token ): void {
		if ( $this->owns_lock( $job_id, $token ) ) {
			update_option( $this->lock_option( $job_id ), ( time() + self::LOCK_SECONDS ) . ':' . $token, false );
		}
	}

	/**
	 * Releases a per-job chunk lock when the token still owns it.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $token  Ownership token.
	 * @return void
	 */
	private function release_lock( string $job_id, string $token ): void {
		if ( $this->owns_lock( $job_id, $token ) ) {
			delete_option( $this->lock_option( $job_id ) );
		}
	}

	/**
	 * Returns a collision-resistant lock ownership token.
	 *
	 * @return string
	 */
	private function new_token(): string {
		return wp_generate_password( 20, false );
	}

	/**
	 * Returns the private option name used as a transient lock.
	 *
	 * @param string $job_id Job identifier.
	 * @return string
	 */
	private function lock_option( string $job_id ): string {
		return '_transient_safesr_chunk_lock_' . $job_id;
	}
}
