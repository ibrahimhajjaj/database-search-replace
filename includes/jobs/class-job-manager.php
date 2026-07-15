<?php
/**
 * Persistent background job management.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Jobs;

use InvalidArgumentException;
use RuntimeException;
use SafeSR\Db\Change;
use SafeSR\Db\Run_Config;
use SafeSR\Db\Schema;
use SafeSR\Engine\Search_Config;

/**
 * Creates jobs, enforces lifecycle transitions, and owns the site-wide run lock.
 */
class Job_Manager {

	private const ACTIVE_OPTION = 'safesr_active_job';

	private const STALE_SECONDS = 21600;

	private const PREVIEW_MAX_AGE = 86400;

	/**
	 * Creates a queued preview job.
	 *
	 * @param Run_Config $config Validated run configuration.
	 * @return string
	 */
	public function create_preview( Run_Config $config ): string {
		return $this->create( 'preview', $config, null );
	}

	/**
	 * Creates a queued apply job, with a backup unless the caller opts out.
	 *
	 * @param Run_Config $config        Validated run configuration.
	 * @param bool       $create_backup Whether to snapshot rows for undo.
	 * @param string     $preview_job_id Bound preview identifier for an interactive apply.
	 * @return string
	 */
	public function create_apply( Run_Config $config, bool $create_backup = true, string $preview_job_id = '' ): string {
		$extra = array();
		if ( '' !== $preview_job_id ) {
			$this->validate_preview( $preview_job_id, $config );
			$extra['preview_job_id'] = $preview_job_id;
		}
		return $this->create( 'apply', $config, $create_backup ? $this->generate_id() : null, $extra );
	}

	/**
	 * Validates that an interactive apply is bound to the caller's exact preview.
	 *
	 * @param string     $preview_job_id Preview identifier.
	 * @param Run_Config $config Apply configuration.
	 * @return void
	 * @throws InvalidArgumentException When the preview cannot authorize the apply.
	 */
	private function validate_preview( string $preview_job_id, Run_Config $config ): void {
		$preview = $this->get_job( $preview_job_id );
		if ( null === $preview || 'preview' !== $preview['type'] || 'completed' !== $preview['status'] ) {
			throw new InvalidArgumentException( esc_html__( 'Apply requires a completed preview.', 'database-search-replace' ) );
		}
		if ( get_current_user_id() !== (int) $preview['created_by'] ) {
			throw new InvalidArgumentException( esc_html__( 'The preview belongs to another user.', 'database-search-replace' ) );
		}
		$created = strtotime( (string) $preview['created_at'] . ' UTC' );
		if ( false === $created || time() - $created > self::PREVIEW_MAX_AGE ) {
			throw new InvalidArgumentException( esc_html__( 'The preview has expired. Run it again before applying.', 'database-search-replace' ) );
		}
		if ( ! hash_equals( $this->config_hash( $preview['config'] ), $this->config_hash( $this->config_to_array( $config ) ) ) ) {
			throw new InvalidArgumentException( esc_html__( 'The apply settings do not match the preview.', 'database-search-replace' ) );
		}
	}

	/**
	 * Hashes user-visible replacement settings independently of batch tuning.
	 *
	 * @param array<string,mixed> $config Stored or requested configuration.
	 * @return string
	 */
	private function config_hash( array $config ): string {
		unset( $config['batch_size'], $config['preview_job_id'], $config['apply_job_id'] );
		return hash( 'sha256', $this->encode_json( $config ) );
	}

	/**
	 * Creates an undo job for a completed apply job.
	 *
	 * @param string $apply_job_id Apply job identifier.
	 * @return string
	 * @throws InvalidArgumentException When the apply job cannot be undone.
	 */
	public function create_undo( string $apply_job_id ): string {
		$apply = $this->get_job( $apply_job_id );
		if ( null === $apply || 'apply' !== $apply['type'] || ! in_array( $apply['status'], array( 'completed', 'failed' ), true ) ) {
			throw new InvalidArgumentException( esc_html__( 'Only a completed or failed apply job can be undone.', 'database-search-replace' ) );
		}
		// A completed run validates against its recorded snapshot size; a failed
		// run never recorded one, so it is undoable as long as its partial
		// writes still have restorable snapshot rows.
		$has_snapshot = 'failed' === $apply['status']
			? $this->has_backup_rows( (string) ( $apply['backup_id'] ?? '' ) )
			: ( ! empty( $apply['backup_id'] ) && $this->backup_is_complete( $apply ) );
		if ( ! $has_snapshot ) {
			throw new InvalidArgumentException( esc_html__( 'This job does not have a backup to restore.', 'database-search-replace' ) );
		}
		if ( ! empty( $apply['summary']['undone_at'] ) ) {
			throw new InvalidArgumentException( esc_html__( 'This job has already been undone.', 'database-search-replace' ) );
		}
		if ( $apply_job_id !== $this->latest_undoable_apply_id() ) {
			throw new InvalidArgumentException( esc_html__( 'A more recent run must be undone first.', 'database-search-replace' ) );
		}

		$config = $this->config_from_array( $apply['config'], false );
		return $this->create( 'undo', $config, (string) $apply['backup_id'], array( 'apply_job_id' => $apply_job_id ) );
	}

	/**
	 * Returns a job with decoded JSON fields.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string,mixed>|null
	 */
	public function get_job( string $job_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current job state required.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %s', Schema::jobs_table_name(), $job_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['config']   = $this->decode_json( (string) $row['config'] );
		$row['progress'] = $this->decode_json( (string) $row['progress'] );
		$row['summary']  = $this->decode_json( (string) $row['summary'] );
		return $row;
	}

	/**
	 * Returns recent jobs with decoded JSON fields.
	 *
	 * @param int $limit Maximum jobs to return.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_jobs( int $limit = 20 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current CLI listing.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d', Schema::jobs_table_name(), $limit ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['config']   = $this->decode_json( (string) $row['config'] );
			$row['progress'] = $this->decode_json( (string) $row['progress'] );
			$row['summary']  = $this->decode_json( (string) $row['summary'] );
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Returns the newest apply that can still be undone.
	 *
	 * A completed apply qualifies until it is undone. A failed apply qualifies
	 * only while its partial writes still have snapshot rows to restore, so a
	 * failure that wrote nothing never blocks undo of an earlier run. Ordering
	 * is by the monotonic insert sequence rather than the second-precision
	 * timestamp, so two runs created within the same second still resolve to a
	 * deterministic newest.
	 *
	 * @return string
	 */
	public function latest_undoable_apply_id(): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current undo order.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, status, backup_id, summary FROM %i WHERE type = %s AND status IN (%s, %s) ORDER BY seq DESC',
				Schema::jobs_table_name(),
				'apply',
				'completed',
				'failed'
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$summary = $this->decode_json( (string) $row['summary'] );
			if ( ! empty( $summary['undone_at'] ) ) {
				continue;
			}
			if ( 'failed' === $row['status'] && ! $this->has_backup_rows( (string) $row['backup_id'] ) ) {
				continue;
			}
			return (string) $row['id'];
		}
		return '';
	}

	/**
	 * Returns a page of recent jobs and the matching total.
	 *
	 * @param string $type     Job type or all job types.
	 * @param int    $page     One-based page.
	 * @param int    $per_page Maximum jobs per page.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function get_jobs_page( string $type = 'apply', int $page = 1, int $per_page = 20 ): array {
		global $wpdb;
		$page     = max( 1, min( 1000000, $page ) );
		$per_page = max( 1, min( 50, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( 'all' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current history state.
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::jobs_table_name() ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current history state.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
					Schema::jobs_table_name(),
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} elseif ( 'history' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current history state.
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type IN (%s, %s) AND status = %s', Schema::jobs_table_name(), 'preview', 'apply', 'completed' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current history state.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE type IN (%s, %s) AND status = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
					Schema::jobs_table_name(),
					'preview',
					'apply',
					'completed',
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current history state.
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s AND status = %s', Schema::jobs_table_name(), $type, 'completed' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current history state.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE type = %s AND status = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
					Schema::jobs_table_name(),
					$type,
					'completed',
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		foreach ( $rows as &$row ) {
			$row['config']     = $this->decode_json( (string) $row['config'] );
			$row['progress']   = $this->decode_json( (string) $row['progress'] );
			$row['summary']    = $this->decode_json( (string) $row['summary'] );
			$row['has_backup'] = $this->backup_is_complete( $row );
		}
		unset( $row );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}

	/**
	 * Reports whether a backup still contains restorable rows.
	 *
	 * @param string $backup_id Backup identifier.
	 * @return bool
	 */
	public function has_backup_rows( string $backup_id ): bool {
		global $wpdb;
		if ( '' === $backup_id ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current undo rows.
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE backup_id = %s LIMIT 1', Schema::backup_rows_table_name(), $backup_id ) );
	}

	/**
	 * Reports whether every row recorded at apply completion still exists.
	 *
	 * @param array<string,mixed> $apply Apply job record with decoded summary.
	 * @return bool
	 */
	public function backup_is_complete( array $apply ): bool {
		global $wpdb;
		$summary      = is_array( $apply['summary'] ?? null ) ? $apply['summary'] : array();
		$backup_id    = is_string( $apply['backup_id'] ?? null ) ? $apply['backup_id'] : '';
		$backup_count = (int) ( $summary['backup_count'] ?? 0 );
		if ( '' === $backup_id || 0 >= $backup_count ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Complete current snapshot.
		$current_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE backup_id = %s', Schema::backup_rows_table_name(), $backup_id ) );
		return $backup_count === $current_count;
	}

	/**
	 * Reconstructs a run configuration stored with a job.
	 *
	 * @param array<string,mixed> $job Job record.
	 * @return Run_Config
	 */
	public function get_run_config( array $job ): Run_Config {
		return $this->config_from_array( $job['config'], 'preview' === $job['type'] );
	}

	/**
	 * Persists complete progress after a batch.
	 *
	 * @param string              $job_id  Job identifier.
	 * @param array<string,mixed> $progress Progress values.
	 * @return bool
	 */
	public function update_progress( string $job_id, array $progress ): bool {
		return $this->update_fields( $job_id, array( 'progress' => $this->encode_json( $progress ) ) );
	}

	/**
	 * Persists complete per-table summary values.
	 *
	 * @param string              $job_id Job identifier.
	 * @param array<string,mixed> $summary Summary values.
	 * @return bool
	 */
	public function update_summary( string $job_id, array $summary ): bool {
		return $this->update_fields( $job_id, array( 'summary' => $this->encode_json( $summary ) ) );
	}

	/**
	 * Stores a terminal error before failing a job.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $error  Error message.
	 * @return void
	 */
	public function fail( string $job_id, string $error ): void {
		$this->update_fields( $job_id, array( 'error' => $error ) );
		$this->transition( $job_id, 'failed' );
	}

	/**
	 * Cancels a queued or running job.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool
	 */
	public function cancel( string $job_id ): bool {
		return $this->transition( $job_id, 'canceled' );
	}

	/**
	 * Applies a guarded status transition.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $status Target status.
	 * @return bool
	 */
	public function transition( string $job_id, string $status ): bool {
		$job = $this->get_job( $job_id );
		if ( null === $job ) {
			return false;
		}
		$allowed = array(
			'queued'  => array( 'running', 'canceled', 'failed' ),
			'running' => array( 'completed', 'canceled', 'failed' ),
		);
		if ( ! in_array( $status, $allowed[ $job['status'] ] ?? array(), true ) ) {
			return false;
		}

		$fields = array( 'status' => $status );
		if ( 'running' === $status ) {
			$fields['started_at'] = current_time( 'mysql', true );
		}
		if ( in_array( $status, array( 'completed', 'failed', 'canceled' ), true ) ) {
			$fields['finished_at'] = current_time( 'mysql', true );
		}
		$updated = $this->update_fields( $job_id, $fields, (string) $job['status'] );
		if ( $updated && in_array( $status, array( 'completed', 'failed', 'canceled' ), true ) ) {
			$this->release_active_lock( $job_id );
		}
		return $updated;
	}

	/**
	 * Adds sampled changes to the persistent diff store.
	 *
	 * @param string   $job_id Job identifier.
	 * @param Change[] $changes Change records.
	 * @return void
	 */
	public function record_changes( string $job_id, array $changes ): void {
		global $wpdb;
		foreach ( $changes as $change ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Append-only change excerpt.
			$wpdb->insert(
				Schema::table_name(),
				array(
					'job_id'         => $job_id,
					'table_name'     => $change->get_table(),
					'column_name'    => $change->get_column(),
					'row_pk'         => $change->get_row_pk(),
					'before_excerpt' => $this->encode_json( $change->get_before_excerpt() ),
					'after_excerpt'  => $this->encode_json( $change->get_after_excerpt() ),
					'formats'        => $this->encode_json( $change->get_formats() ),
					'skipped'        => $change->is_skipped() ? 1 : 0,
					'skip_reason'    => $change->get_skip_reason(),
					'created_at'     => current_time( 'mysql', true ),
				)
			);
		}
	}

	/**
	 * Returns paginated change excerpts and a total count.
	 *
	 * @param string $job_id  Job identifier.
	 * @param int    $page    One-based page.
	 * @param int    $per_page Page size.
	 * @param string $table   Optional table filter.
	 * @param string $text    Optional excerpt or column filter.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function get_changes( string $job_id, int $page, int $per_page, string $table = '', string $text = '' ): array {
		global $wpdb;
		$where = array( 'job_id = %s' );
		$args  = array( $job_id );
		if ( '' !== $table ) {
			$where[] = 'table_name = %s';
			$args[]  = $table;
		}
		if ( '' !== $text ) {
			$like    = '%' . $wpdb->esc_like( $text ) . '%';
			$where[] = '(column_name LIKE %s OR before_excerpt LIKE %s OR after_excerpt LIKE %s)';
			$args    = array_merge( $args, array( $like, $like, $like ) );
		}
		$where_sql = implode( ' AND ', $where );
		$count_sql = 'SELECT COUNT(*) FROM %i WHERE ' . $where_sql;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed SQL and placeholders.
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, array_merge( array( Schema::table_name() ), $args ) ) );

		$list_sql  = 'SELECT * FROM %i WHERE ' . $where_sql . ' ORDER BY id ASC LIMIT %d OFFSET %d';
		$list_args = array_merge( array( Schema::table_name() ), $args, array( $per_page, ( $page - 1 ) * $per_page ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed SQL and placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_args ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['before_excerpt'] = $this->decode_json( (string) $row['before_excerpt'] );
			$row['after_excerpt']  = $this->decode_json( (string) $row['after_excerpt'] );
			$row['formats']        = $this->decode_json( (string) $row['formats'] );
			$row['skipped']        = (bool) $row['skipped'];
		}
		unset( $row );
		return array(
			'items' => $rows,
			'total' => $total,
		);
	}

	/**
	 * Adds linkage fields to a stored job configuration.
	 *
	 * @param string              $job_id Job identifier.
	 * @param array<string,mixed> $values Additional fields.
	 * @return bool
	 */
	public function merge_config( string $job_id, array $values ): bool {
		$job = $this->get_job( $job_id );
		if ( null === $job ) {
			return false;
		}
		return $this->update_fields( $job_id, array( 'config' => $this->encode_json( array_merge( $job['config'], $values ) ) ) );
	}

	/**
	 * Creates and enqueues one job record.
	 *
	 * @param string              $type      Job type.
	 * @param Run_Config          $config    Run configuration.
	 * @param string|null         $backup_id Backup identifier.
	 * @param array<string,mixed> $extra     Extra config keys persisted before the job is enqueued.
	 * @return string
	 * @throws RuntimeException When another active job exists or persistence fails.
	 */
	private function create( string $type, Run_Config $config, ?string $backup_id, array $extra = array() ): string {
		global $wpdb;
		$job_id = $this->generate_id();
		$this->acquire_active_lock( $job_id );
		$progress = array(
			'tables_total'   => count( $config->get_tables() ),
			'tables_done'    => 0,
			'current_table'  => $config->get_tables()[0] ?? '',
			'current_cursor' => null,
			'rows_scanned'   => 0,
			'matches'        => 0,
			'replacements'   => 0,
			'skipped'        => 0,
			'last_run_at'    => null,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Durable job insert.
		$inserted = $wpdb->insert(
			Schema::jobs_table_name(),
			array(
				'id'         => $job_id,
				'type'       => $type,
				'status'     => 'queued',
				'config'     => $this->encode_json( array_merge( $this->config_to_array( $config ), $extra ) ),
				'progress'   => $this->encode_json( $progress ),
				'summary'    => '{}',
				'backup_id'  => $backup_id,
				'error'      => null,
				'created_by' => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		if ( false === $inserted ) {
			$this->release_active_lock( $job_id );
			throw new RuntimeException( esc_html__( 'The background job could not be created.', 'database-search-replace' ) );
		}
		$this->enqueue( $job_id );
		return $job_id;
	}

	/**
	 * Enqueues a chunk when Action Scheduler is available.
	 *
	 * The unique flag is off on purpose. A chunk enqueues its own successor
	 * while still marked running, and a unique action refuses to schedule
	 * against a running one, which would strand the job after its first
	 * chunk. Overlap is instead prevented by the per-job chunk lock.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public function enqueue( string $job_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'safesr_run_chunk', array( $job_id ), 'database-search-replace', false );
		}
	}

	/**
	 * Acquires the single active job option after recovering stale state.
	 *
	 * @param string $job_id New job identifier.
	 * @return void
	 * @throws RuntimeException When a live lock exists.
	 */
	private function acquire_active_lock( string $job_id ): void {
		if ( $this->claim_active_option( $job_id ) ) {
			return;
		}
		$active_id = (string) get_option( self::ACTIVE_OPTION, '' );
		$active    = '' !== $active_id ? $this->get_job( $active_id ) : null;
		$stale     = null === $active || in_array( $active['status'], array( 'completed', 'failed', 'canceled' ), true );
		if ( ! $stale ) {
			// A job that is still making progress is not stale however long it
			// has run, so measure from its last batch, or from creation if no
			// batch has run yet. A crashed job stops updating and ages out.
			$last_run = $active['progress']['last_run_at'] ?? null;
			$since    = is_numeric( $last_run ) ? (int) $last_run : strtotime( (string) ( $active['created_at'] ?? '' ) . ' UTC' );
			$stale    = false !== $since && time() - (int) $since > self::STALE_SECONDS;
		}
		if ( $stale ) {
			delete_option( self::ACTIVE_OPTION );
			if ( $this->claim_active_option( $job_id ) ) {
				return;
			}
		}
		throw new RuntimeException( esc_html__( 'Another search and replace job is already active.', 'database-search-replace' ) );
	}

	/**
	 * Attempts an atomic claim of the active job option.
	 *
	 * The add_option insert carries an IGNORE guard, so exactly one
	 * concurrent caller wins even without row locks.
	 *
	 * @phpstan-impure
	 *
	 * @param string $job_id New job identifier.
	 * @return bool
	 */
	private function claim_active_option( string $job_id ): bool {
		return add_option( self::ACTIVE_OPTION, $job_id, '', false );
	}

	/**
	 * Releases the site lock only when owned by the supplied job.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private function release_active_lock( string $job_id ): void {
		if ( (string) get_option( self::ACTIVE_OPTION, '' ) === $job_id ) {
			delete_option( self::ACTIVE_OPTION );
		}
	}

	/**
	 * Updates selected job fields with an optional current-status guard.
	 *
	 * @param string              $job_id        Job identifier.
	 * @param array<string,mixed> $fields Fields to update.
	 * @param string|null         $current_status Required current status.
	 * @return bool
	 */
	private function update_fields( string $job_id, array $fields, ?string $current_status = null ): bool {
		global $wpdb;
		$where = array( 'id' => $job_id );
		if ( null !== $current_status ) {
			$where['status'] = $current_status;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable progress state.
		$updated = $wpdb->update( Schema::jobs_table_name(), $fields, $where );
		return false !== $updated && 0 < $updated;
	}

	/**
	 * Converts a run configuration to JSON-safe values.
	 *
	 * @param Run_Config $config Run configuration.
	 * @return array<string,mixed>
	 */
	private function config_to_array( Run_Config $config ): array {
		$search = $config->get_search_config();
		return array(
			'search'         => $search->get_search(),
			'replace'        => $search->get_replace(),
			'case_sensitive' => $search->is_case_sensitive(),
			'regex'          => $search->is_regex(),
			'exclusions'     => $search->get_exclusions(),
			'tables'         => $config->get_tables(),
			'include_guids'  => $config->should_include_guids(),
			'thorough_scan'  => $config->is_thorough_scan(),
			'batch_size'     => $config->get_batch_size(),
		);
	}

	/**
	 * Rebuilds validated engine and traversal configuration.
	 *
	 * @param array<string,mixed> $values Stored values.
	 * @param bool                $dry_run Whether writes are disabled.
	 * @return Run_Config
	 */
	private function config_from_array( array $values, bool $dry_run ): Run_Config {
		$search = new Search_Config(
			(string) ( $values['search'] ?? '' ),
			(string) ( $values['replace'] ?? '' ),
			(bool) ( $values['case_sensitive'] ?? false ),
			(bool) ( $values['regex'] ?? false ),
			is_array( $values['exclusions'] ?? null ) ? $values['exclusions'] : array()
		);
		return new Run_Config(
			$search,
			is_array( $values['tables'] ?? null ) ? $values['tables'] : array(),
			(bool) ( $values['include_guids'] ?? false ),
			(bool) ( $values['thorough_scan'] ?? false ),
			$values['batch_size'] ?? null,
			$dry_run
		);
	}

	/**
	 * Generates a compact random identifier.
	 *
	 * @return string
	 */
	private function generate_id(): string {
		return str_replace( '-', '', wp_generate_uuid4() );
	}

	/**
	 * Encodes an array for durable storage.
	 *
	 * @param array<string|int,mixed> $value Values to encode.
	 * @return string
	 */
	private function encode_json( array $value ): string {
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) ? $encoded : '{}';
	}

	/**
	 * Decodes a stored JSON object or list.
	 *
	 * @param string $value JSON value.
	 * @return array<string|int,mixed>
	 */
	private function decode_json( string $value ): array {
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
