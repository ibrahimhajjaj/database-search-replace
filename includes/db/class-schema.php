<?php
/**
 * Plugin database schema.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Db;

/**
 * Installs and removes plugin-owned custom tables.
 */
class Schema {

	/**
	 * Monotonic custom-table schema version.
	 */
	public const VERSION = '2';

	/**
	 * Installs plugin-owned tables idempotently.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb charset and collation.
		$changes_sql    = $wpdb->prepare(
			"CREATE TABLE %i (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id VARCHAR(32) NOT NULL,
				table_name VARCHAR(191) NOT NULL,
				column_name VARCHAR(191) NOT NULL,
				row_pk VARCHAR(191) NOT NULL,
				before_excerpt LONGTEXT NOT NULL,
				after_excerpt LONGTEXT NOT NULL,
				formats VARCHAR(64) NOT NULL DEFAULT '',
				skipped TINYINT(1) NOT NULL DEFAULT 0,
				skip_reason VARCHAR(32) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY job_id (job_id)
			) {$charset_collate}",
			self::table_name()
		);
		$jobs_sql       = $wpdb->prepare(
			"CREATE TABLE %i (
				seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				id VARCHAR(32) NOT NULL,
				type VARCHAR(16) NOT NULL,
				status VARCHAR(16) NOT NULL,
				config LONGTEXT NOT NULL,
				progress LONGTEXT NOT NULL,
				summary LONGTEXT NOT NULL,
				backup_id VARCHAR(32) NULL,
				error TEXT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL,
				started_at DATETIME NULL,
				finished_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY seq (seq),
				KEY status (status)
			) {$charset_collate}",
			self::jobs_table_name()
		);
		$backup_sql     = $wpdb->prepare(
			"CREATE TABLE %i (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				backup_id VARCHAR(32) NOT NULL,
				table_name VARCHAR(191) NOT NULL,
				column_name VARCHAR(191) NOT NULL,
				row_pk VARCHAR(255) NOT NULL,
				original_value LONGBLOB NOT NULL,
				applied_value LONGBLOB NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY backup_id (backup_id)
			) {$charset_collate}",
			self::backup_rows_table_name()
		);
		$operations_sql = $wpdb->prepare(
			"CREATE TABLE %i (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id VARCHAR(32) NOT NULL,
				cell_hash CHAR(64) NOT NULL,
				table_name VARCHAR(191) NOT NULL,
				column_name VARCHAR(191) NOT NULL,
				row_pk VARCHAR(255) NOT NULL,
				expected_hash CHAR(64) NOT NULL,
				applied_hash CHAR(64) NOT NULL,
				replacements BIGINT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(16) NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY job_id (job_id),
				KEY created_at (created_at)
			) {$charset_collate}",
			self::operations_table_name()
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		dbDelta( $changes_sql );
		dbDelta( $jobs_sql );
		dbDelta( $backup_sql );
		dbDelta( $operations_sql );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current schema required.
		$seq_column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', self::jobs_table_name(), 'seq' ) );
		if ( 'seq' !== $seq_column ) {
			// dbDelta cannot add an AUTO_INCREMENT column and its key atomically to an existing table.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Add column and key together.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE FIRST', self::jobs_table_name() ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current schema required.
		$cell_index = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', self::operations_table_name(), 'cell' ) );
		if ( null === $cell_index ) {
			// dbDelta does not reliably detect this named unique key on re-install, so it is added idempotently here.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Idempotent key creation.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY cell (cell_hash)', self::operations_table_name() ) );
		}
	}

	/**
	 * Drops plugin-owned tables.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;
		foreach ( array( self::table_name(), self::jobs_table_name(), self::backup_rows_table_name(), self::operations_table_name() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin table removal.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}

	/**
	 * Returns the current site's change excerpt table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'safesr_changes';
	}

	/**
	 * Returns the current site's jobs table name.
	 *
	 * @return string
	 */
	public static function jobs_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'safesr_jobs';
	}

	/**
	 * Returns the current site's backup rows table name.
	 *
	 * @return string
	 */
	public static function backup_rows_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'safesr_backup_rows';
	}

	/**
	 * Returns the current site's durable cell operation table name.
	 *
	 * @return string
	 */
	public static function operations_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'safesr_operations';
	}
}
