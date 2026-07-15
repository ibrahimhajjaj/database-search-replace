<?php
/**
 * Durable cell operation claims.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Db;

use RuntimeException;

/**
 * Makes preview candidates and apply writes durable at cell granularity.
 */
class Operation_Ledger {

	/**
	 * Returns a preview candidate for one cell.
	 *
	 * @param string              $preview_job_id Preview identifier.
	 * @param string              $table Physical table name.
	 * @param array<string,mixed> $primary_key Primary-key values.
	 * @param string              $column Text column name.
	 * @return array<string,mixed>|null
	 * @throws RuntimeException When the primary key cannot be encoded safely.
	 */
	public function get_preview_candidate( string $preview_job_id, string $table, array $primary_key, string $column ): ?array {
		return $this->get_cell( $preview_job_id, $table, $column, $this->encode_primary_key( $primary_key ) );
	}

	/**
	 * Records one complete preview candidate with hashes of its exact bytes.
	 *
	 * @param string              $job_id      Preview job identifier.
	 * @param string              $table       Physical table name.
	 * @param array<string,mixed> $primary_key Primary-key values.
	 * @param string              $column      Text column name.
	 * @param string              $expected    Preview-time value.
	 * @param string              $applied     Proposed value.
	 * @param int                 $replacements Replacement count.
	 * @return void
	 * @throws RuntimeException When the candidate cannot be persisted.
	 */
	public function record_preview( string $job_id, string $table, array $primary_key, string $column, string $expected, string $applied, int $replacements ): void {
		global $wpdb;
		$row_pk = $this->encode_primary_key( $primary_key );
		$stored = $this->get_cell( $job_id, $table, $column, $row_pk );
		if ( $this->preview_matches( $stored, $expected, $applied, $replacements ) ) {
			return;
		}
		if ( null !== $stored ) {
			throw new RuntimeException( 'A preview candidate changed while it was being persisted.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Durable preview record.
		$inserted = $wpdb->insert(
			Schema::operations_table_name(),
			array(
				'job_id'        => $job_id,
				'cell_hash'     => $this->cell_hash( $job_id, $table, $column, $row_pk ),
				'table_name'    => $table,
				'column_name'   => $column,
				'row_pk'        => $row_pk,
				'expected_hash' => hash( 'sha256', $expected ),
				'applied_hash'  => hash( 'sha256', $applied ),
				'replacements'  => $replacements,
				'status'        => 'previewed',
				'created_at'    => current_time( 'mysql', true ),
			)
		);
		if ( false === $inserted ) {
			$stored = $this->get_cell( $job_id, $table, $column, $row_pk );
			if ( $this->preview_matches( $stored, $expected, $applied, $replacements ) ) {
				return;
			}
			throw new RuntimeException( 'A preview candidate could not be persisted.' );
		}
	}

	/**
	 * Reports whether a stored preview candidate has the same exact bytes.
	 *
	 * @param array<string,mixed>|null $stored Stored candidate.
	 * @param string                   $expected Preview-time value.
	 * @param string                   $applied Proposed value.
	 * @param int                      $replacements Replacement count.
	 * @return bool
	 */
	private function preview_matches( ?array $stored, string $expected, string $applied, int $replacements ): bool {
		return null !== $stored
			&& 'previewed' === $stored['status']
			&& hash_equals( (string) $stored['expected_hash'], hash( 'sha256', $expected ) )
			&& hash_equals( (string) $stored['applied_hash'], hash( 'sha256', $applied ) )
			&& $replacements === (int) $stored['replacements'];
	}

	/**
	 * Claims a cell before its write or reconciles a previous claim.
	 *
	 * @param string              $job_id        Apply job identifier.
	 * @param string              $preview_job_id Bound preview identifier, or empty for internal runs.
	 * @param string              $table         Physical table name.
	 * @param array<string,mixed> $primary_key   Primary-key values.
	 * @param string              $column        Text column name.
	 * @param string              $current       Current stored value.
	 * @param string              $proposed      Newly computed value.
	 * @param int                 $replacements  Replacement count.
	 * @return array{action:string,id:int,expected:string,applied:string,replacements:int}
	 * @throws RuntimeException When a claim or status cannot be persisted.
	 */
	public function claim( string $job_id, string $preview_job_id, string $table, array $primary_key, string $column, string $current, string $proposed, int $replacements ): array {
		global $wpdb;
		$row_pk   = $this->encode_primary_key( $primary_key );
		$existing = $this->get_cell( $job_id, $table, $column, $row_pk );
		if ( null !== $existing ) {
			return $this->reconcile( $existing, $current, $proposed );
		}

		$expected = $current;
		$applied  = $proposed;
		$count    = $replacements;
		if ( '' !== $preview_job_id ) {
			$preview = $this->get_cell( $preview_job_id, $table, $column, $row_pk );
			if ( null === $preview || ! hash_equals( (string) $preview['expected_hash'], hash( 'sha256', $current ) ) || ! hash_equals( (string) $preview['applied_hash'], hash( 'sha256', $proposed ) ) ) {
				return $this->conflict_result();
			}
			$count = (int) $preview['replacements'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic cell claim.
		$inserted = $wpdb->insert(
			Schema::operations_table_name(),
			array(
				'job_id'        => $job_id,
				'cell_hash'     => $this->cell_hash( $job_id, $table, $column, $row_pk ),
				'table_name'    => $table,
				'column_name'   => $column,
				'row_pk'        => $row_pk,
				'expected_hash' => hash( 'sha256', $expected ),
				'applied_hash'  => hash( 'sha256', $applied ),
				'replacements'  => $count,
				'status'        => 'claimed',
				'created_at'    => current_time( 'mysql', true ),
			)
		);
		if ( false === $inserted ) {
			$existing = $this->get_cell( $job_id, $table, $column, $row_pk );
			if ( null === $existing ) {
				throw new RuntimeException( 'A replacement cell could not be claimed.' );
			}
			return $this->reconcile( $existing, $current, $proposed );
		}

		return array(
			'action'       => 'claimed',
			'id'           => (int) $wpdb->insert_id,
			'expected'     => $expected,
			'applied'      => $applied,
			'replacements' => $count,
		);
	}

	/**
	 * Marks a claimed cell as applied.
	 *
	 * @param int $id Operation identifier.
	 * @return void
	 */
	public function mark_applied( int $id ): void {
		$this->set_status( $id, 'applied' );
	}

	/**
	 * Marks a claimed cell as conflicted.
	 *
	 * @param int $id Operation identifier.
	 * @return void
	 */
	public function mark_conflict( int $id ): void {
		if ( 0 < $id ) {
			$this->set_status( $id, 'conflict' );
		}
	}

	/**
	 * Loads one cell record.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $table Table name.
	 * @param string $column Column name.
	 * @param string $row_pk Encoded primary key.
	 * @return array<string,mixed>|null
	 */
	private function get_cell( string $job_id, string $table, string $column, string $row_pk ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current claim state required.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE cell_hash = %s LIMIT 1',
				Schema::operations_table_name(),
				$this->cell_hash( $job_id, $table, $column, $row_pk )
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Reconciles a durable operation against current bytes.
	 *
	 * @param array<string,mixed> $operation Durable operation.
	 * @param string              $current Current stored value.
	 * @param string              $proposed Proposed replacement value.
	 * @return array{action:string,id:int,expected:string,applied:string,replacements:int}
	 */
	private function reconcile( array $operation, string $current, string $proposed ): array {
		$expected_hash = (string) $operation['expected_hash'];
		$applied_hash  = (string) $operation['applied_hash'];
		$status        = (string) $operation['status'];
		$id            = (int) $operation['id'];
		if ( hash_equals( $applied_hash, hash( 'sha256', $current ) ) && in_array( $status, array( 'claimed', 'applied' ), true ) ) {
			if ( 'claimed' === $status ) {
				$this->mark_applied( $id );
			}
			return array(
				'action'       => 'complete',
				'id'           => $id,
				'expected'     => $current,
				'applied'      => $current,
				'replacements' => (int) $operation['replacements'],
			);
		}
		if ( 'claimed' === $status && hash_equals( $expected_hash, hash( 'sha256', $current ) ) && hash_equals( $applied_hash, hash( 'sha256', $proposed ) ) ) {
			return array(
				'action'       => 'claimed',
				'id'           => $id,
				'expected'     => $current,
				'applied'      => $proposed,
				'replacements' => (int) $operation['replacements'],
			);
		}
		$this->mark_conflict( $id );
		return $this->conflict_result( $id );
	}

	/**
	 * Returns a standard conflict result.
	 *
	 * @param int $id Operation identifier when a claim exists.
	 * @return array{action:string,id:int,expected:string,applied:string,replacements:int}
	 */
	private function conflict_result( int $id = 0 ): array {
		return array(
			'action'       => 'conflict',
			'id'           => $id,
			'expected'     => '',
			'applied'      => '',
			'replacements' => 0,
		);
	}

	/**
	 * Persists an operation status.
	 *
	 * @param int    $id Operation identifier.
	 * @param string $status Status value.
	 * @return void
	 * @throws RuntimeException When the status cannot be persisted.
	 */
	private function set_status( int $id, string $status ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable operation state.
		$updated = $wpdb->update( Schema::operations_table_name(), array( 'status' => $status ), array( 'id' => $id ) );
		if ( false === $updated ) {
			throw new RuntimeException( 'A replacement cell status could not be persisted.' );
		}
	}

	/**
	 * Encodes a primary key for a stable cell identity.
	 *
	 * @param array<string,mixed> $primary_key Primary-key values.
	 * @return string
	 * @throws RuntimeException When the primary key cannot be encoded safely.
	 */
	private function encode_primary_key( array $primary_key ): string {
		$encoded = wp_json_encode( $primary_key );
		if ( ! is_string( $encoded ) || 255 < strlen( $encoded ) ) {
			throw new RuntimeException( 'A replacement cell key could not be encoded safely.' );
		}
		return $encoded;
	}

	/**
	 * Hashes all variable-width cell identity parts into one fixed-width key.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $table Table name.
	 * @param string $column Column name.
	 * @param string $row_pk Encoded primary key.
	 * @return string
	 */
	private function cell_hash( string $job_id, string $table, string $column, string $row_pk ): string {
		$parts = array( $job_id, $table, $column, $row_pk );
		$key   = '';
		foreach ( $parts as $part ) {
			$key .= strlen( $part ) . ':' . $part;
		}
		return hash( 'sha256', $key );
	}
}
