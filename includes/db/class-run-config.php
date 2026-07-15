<?php
/**
 * Database run configuration.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Db;

use InvalidArgumentException;
use SafeSR\Engine\Search_Config;

/**
 * Combines engine settings with database traversal settings.
 */
class Run_Config {

	private const DEFAULT_BATCH_SIZE = 500;

	/**
	 * Engine search configuration.
	 *
	 * @var Search_Config
	 */
	private Search_Config $search_config;

	/**
	 * Selected physical table names.
	 *
	 * @var string[]
	 */
	private array $tables;

	/**
	 * Whether post GUIDs may be changed.
	 *
	 * @var bool
	 */
	private bool $include_guids;

	/**
	 * Whether SQL prefiltering is disabled.
	 *
	 * @var bool
	 */
	private bool $thorough_scan;

	/**
	 * Maximum rows processed per batch.
	 *
	 * @var int
	 */
	private int $batch_size;

	/**
	 * Whether database writes are disabled.
	 *
	 * @var bool
	 */
	private bool $dry_run;

	/**
	 * Validates database run options.
	 *
	 * @param Search_Config   $search_config Engine search configuration.
	 * @param string[]        $tables        Selected table names.
	 * @param bool            $include_guids Whether post GUIDs may be changed.
	 * @param bool            $thorough_scan Whether every row is offered to the engine.
	 * @param int|string|null $batch_size    Positive row count or auto selection.
	 * @param bool            $dry_run       Whether changes are collected without writes.
	 * @throws InvalidArgumentException When table names or batch size are invalid.
	 */
	public function __construct( Search_Config $search_config, array $tables, bool $include_guids = false, bool $thorough_scan = false, $batch_size = null, bool $dry_run = true ) {
		foreach ( $tables as $table ) {
			if ( ! is_string( $table ) || '' === $table ) {
				throw new InvalidArgumentException( 'Table names must be nonempty strings.' );
			}
		}

		$this->search_config = $search_config;
		$this->tables        = array_values( array_unique( $tables ) );
		$this->include_guids = $include_guids;
		$this->thorough_scan = $thorough_scan;
		$this->batch_size    = $this->resolve_batch_size( $batch_size );
		$this->dry_run       = $dry_run;
	}

	/**
	 * Returns the engine search configuration.
	 *
	 * @return Search_Config
	 */
	public function get_search_config(): Search_Config {
		return $this->search_config;
	}

	/**
	 * Returns selected physical table names.
	 *
	 * @return string[]
	 */
	public function get_tables(): array {
		return $this->tables;
	}

	/**
	 * Returns whether post GUIDs may be changed.
	 *
	 * @return bool
	 */
	public function should_include_guids(): bool {
		return $this->include_guids;
	}

	/**
	 * Returns whether SQL prefiltering is disabled.
	 *
	 * @return bool
	 */
	public function is_thorough_scan(): bool {
		return $this->thorough_scan;
	}

	/**
	 * Returns the maximum rows processed per batch.
	 *
	 * @return int
	 */
	public function get_batch_size(): int {
		return $this->batch_size;
	}

	/**
	 * Returns whether database writes are disabled.
	 *
	 * @return bool
	 */
	public function is_dry_run(): bool {
		return $this->dry_run;
	}

	/**
	 * Resolves an explicit, saved, or automatic batch size.
	 *
	 * @param int|string|null $batch_size Requested batch size.
	 * @return int
	 * @throws InvalidArgumentException When the value is not a positive integer or auto.
	 */
	private function resolve_batch_size( $batch_size ): int {
		if ( null === $batch_size && function_exists( 'get_option' ) ) {
			$batch_size = get_option( 'safesr_batch_size', 'auto' );
		}

		if ( null === $batch_size || 'auto' === $batch_size ) {
			return self::DEFAULT_BATCH_SIZE;
		}

		if ( ! is_numeric( $batch_size ) || 1 > (int) $batch_size || (string) (int) $batch_size !== (string) $batch_size ) {
			throw new InvalidArgumentException( 'Batch size must be a positive integer or auto.' );
		}

		return (int) $batch_size;
	}
}
