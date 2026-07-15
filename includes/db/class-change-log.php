<?php
/**
 * Per-job CSV change logs.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Db;

use RuntimeException;

/**
 * Renders authorized CSV downloads without public filesystem persistence.
 */
class Change_Log {

	/**
	 * Renders changes for one job as CSV bytes.
	 *
	 * @param string   $job_id  Job identifier.
	 * @param Change[] $changes Change records.
	 * @return string
	 * @throws RuntimeException When the memory stream cannot be used.
	 */
	public function render( string $job_id, array $changes ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory CSV stream.
		$handle = fopen( 'php://temp', 'w+b' );
		if ( false === $handle ) {
			throw new RuntimeException( 'The change log stream could not be opened.' );
		}

		fputcsv( $handle, array( 'job_id', 'table', 'column', 'pk', 'before_excerpt', 'after_excerpt', 'formats', 'skipped', 'reason' ) );
		foreach ( $changes as $change ) {
			$row = array(
				$job_id,
				$change->get_table(),
				$change->get_column(),
				$change->get_row_pk(),
				$this->excerpt_text( $change->get_before_excerpt() ),
				$this->excerpt_text( $change->get_after_excerpt() ),
				implode( '|', $change->get_formats() ),
				$change->is_skipped() ? '1' : '0',
				$change->get_skip_reason(),
			);
			fputcsv( $handle, array_map( array( $this, 'neutralize_formula' ), $row ) );
		}
		rewind( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_stream_get_contents -- Read the memory stream.
		$csv = stream_get_contents( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the memory stream.
		fclose( $handle );
		if ( false === $csv ) {
			throw new RuntimeException( 'The change log stream could not be read.' );
		}
		return $csv;
	}

	/**
	 * Prefixes cells that spreadsheet programs can interpret as formulas.
	 *
	 * @param string $value CSV cell value.
	 * @return string
	 */
	private function neutralize_formula( string $value ): string {
		if ( 1 === preg_match( '/^[\x00-\x20]*[=+\-@]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Flattens display segments for CSV output.
	 *
	 * @param array<int,array{t:string,k:string}> $segments Diff segments.
	 * @return string
	 */
	private function excerpt_text( array $segments ): string {
		return implode( '', array_column( $segments, 't' ) );
	}
}
