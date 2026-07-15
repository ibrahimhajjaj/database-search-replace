<?php
/**
 * Removes expired backup snapshots.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Jobs;

use SafeSR\Db\Schema;

/**
 * Enforces the backup retention window.
 */
class Backup_Prune {

	/**
	 * Deletes backup rows older than the configured retention window.
	 *
	 * Snapshots referenced by a queued or running undo are kept regardless of
	 * age, so a restore that is already in flight against an expiring snapshot
	 * cannot have its rows deleted out from under it mid-run.
	 *
	 * @return void
	 */
	public function prune(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) constant( 'SAFESR_BACKUP_RETENTION_DAYS' ) * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Expired snapshots.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s AND backup_id NOT IN (
					SELECT backup_id FROM %i WHERE type = %s AND status IN (%s, %s) AND backup_id IS NOT NULL
				)',
				Schema::backup_rows_table_name(),
				$cutoff,
				Schema::jobs_table_name(),
				'undo',
				'queued',
				'running'
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Expired operation claims.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', Schema::operations_table_name(), $cutoff ) );
	}
}
