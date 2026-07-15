<?php
/**
 * WordPress database table discovery.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Db;

/**
 * Reports searchable tables, textual columns, primary keys, and protected tables.
 */
class Table_Scanner {

	/**
	 * Returns current-site table metadata without network-global tables.
	 *
	 * @return array<int,array{name:string,rows:int,data_size:int,protected:bool}>
	 */
	public function get_tables(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live table status required.
		$statuses  = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $this->like_prefix( $wpdb->prefix ) ), ARRAY_A );
		$global    = $this->global_table_names();
		$protected = $this->get_protected_tables();

		$tables = array();
		foreach ( $statuses as $status ) {
			$name = isset( $status['Name'] ) ? (string) $status['Name'] : '';
			if ( '' === $name || ! $this->belongs_to_current_site( $name ) || in_array( $name, $global, true ) ) {
				continue;
			}
			if ( self::is_plugin_table( $name ) ) {
				continue;
			}
			$tables[ $name ] = array(
				'name'      => $name,
				'rows'      => isset( $status['Rows'] ) ? (int) $status['Rows'] : 0,
				'data_size' => isset( $status['Data_length'] ) ? (int) $status['Data_length'] : 0,
				'protected' => in_array( $name, $protected, true ),
			);
		}

		ksort( $tables );
		return array_values( $tables );
	}

	/**
	 * Returns CHAR and TEXT family columns for a table.
	 *
	 * @param string $table Table name.
	 * @return string[]
	 */
	public function get_text_columns( string $table ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live column metadata required.
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
		$text    = array();
		foreach ( $columns as $column ) {
			$type = isset( $column['Type'] ) ? strtolower( (string) $column['Type'] ) : '';
			if ( 1 === preg_match( '/^(?:char|varchar|tinytext|text|mediumtext|longtext)(?:\(|$)/', $type ) ) {
				$text[] = (string) $column['Field'];
			}
		}
		return $text;
	}

	/**
	 * Returns primary-key columns in index order, or null when none exists.
	 *
	 * @param string $table Table name.
	 * @return string[]|null
	 */
	public function get_primary_key( string $table ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live primary-key metadata required.
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), ARRAY_A );
		if ( empty( $indexes ) ) {
			return null;
		}
		usort( $indexes, static fn( array $left, array $right ): int => (int) $left['Seq_in_index'] <=> (int) $right['Seq_in_index'] );
		return array_map( static fn( array $index ): string => (string) $index['Column_name'], $indexes );
	}

	/**
	 * Reports whether the table engine supports atomic replacement cells.
	 *
	 * @param string $table Physical table name.
	 * @return bool
	 */
	public function supports_transactions( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live storage engine required.
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
		return is_array( $status ) && 'innodb' === strtolower( (string) ( $status['Engine'] ?? '' ) );
	}

	/**
	 * Returns default, saved, and developer-filtered protected table names.
	 *
	 * @return string[]
	 */
	public function get_protected_tables(): array {
		global $wpdb;
		$saved = get_option( 'safesr_protected_tables', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$tables = array_merge( array( $wpdb->users, $wpdb->usermeta ), array_filter( $saved, 'is_string' ) );
		/**
		 * Filters tables that database runs must never write.
		 *
		 * @param string[] $tables Protected physical table names.
		 */
		$tables = apply_filters( 'safesr_protected_tables', $tables );
		return array_values( array_unique( array_filter( $tables, 'is_string' ) ) );
	}

	/**
	 * Returns whether writes to a table are forbidden.
	 *
	 * @param string $table Physical table name.
	 * @return bool
	 */
	public function is_protected( string $table ): bool {
		return in_array( $table, $this->get_protected_tables(), true );
	}

	/**
	 * Returns whether a physical table belongs to the current site.
	 *
	 * On the network's base site the prefix is a substring of every subsite
	 * table (wp_ is a prefix of wp_2_posts), so a bare prefix match would list
	 * other sites' tables. A trailing numeric segment marks another blog.
	 *
	 * @param string $name Physical table name.
	 * @return bool
	 */
	public function belongs_to_current_site( string $name ): bool {
		global $wpdb;
		if ( 0 !== strpos( $name, $wpdb->prefix ) ) {
			return false;
		}
		if ( $wpdb->base_prefix === $wpdb->prefix ) {
			$suffix = substr( $name, strlen( $wpdb->prefix ) );
			return 1 !== preg_match( '/^[0-9]+_/', $suffix );
		}
		return true;
	}

	/**
	 * Reduces requested table names to the ones this site may scan.
	 *
	 * Requests carry table names directly, so they are intersected here with
	 * the current site's scan set. A plugin table or another site's table is
	 * dropped before it can reach a write. Protected tables are kept because
	 * they are scanned read-only to show their skipped rows, and the write
	 * guard in the row processor already refuses to change them.
	 *
	 * @param string[] $requested Requested physical table names.
	 * @return string[]
	 */
	public function filter_allowed( array $requested ): array {
		$allowed = array();
		foreach ( $this->get_tables() as $table ) {
			$allowed[ $table['name'] ] = true;
		}
		$result = array();
		foreach ( $requested as $name ) {
			$name = (string) $name;
			if ( isset( $allowed[ $name ] ) && ! in_array( $name, $result, true ) ) {
				$result[] = $name;
			}
		}
		return $result;
	}

	/**
	 * Returns whether a table belongs to this plugin.
	 *
	 * Job records carry the search text and backup rows hold the undo
	 * snapshots, so replacing inside them would corrupt the run that is
	 * editing them. These tables never appear in a scan.
	 *
	 * @param string $table Physical table name.
	 * @return bool
	 */
	public static function is_plugin_table( string $table ): bool {
		global $wpdb;
		return 0 === strpos( $table, $wpdb->prefix . 'safesr_' );
	}

	/**
	 * Returns physical shared table names from wpdb.
	 *
	 * @return string[]
	 */
	private function global_table_names(): array {
		global $wpdb;
		$names      = array();
		$properties = array_merge( $wpdb->global_tables, $wpdb->ms_global_tables, $wpdb->old_ms_global_tables );
		foreach ( $properties as $property ) {
			if ( isset( $wpdb->{$property} ) && is_string( $wpdb->{$property} ) ) {
				$names[] = $wpdb->{$property};
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Builds a LIKE pattern for a physical table prefix.
	 *
	 * @param string $prefix Table prefix.
	 * @return string
	 */
	private function like_prefix( string $prefix ): string {
		global $wpdb;
		return $wpdb->esc_like( $prefix ) . '%';
	}
}
