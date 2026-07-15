<?php
/**
 * Database batch result.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Db;

/**
 * Carries progress, sampled changes, counts, and recoverable errors for one batch.
 */
class Batch_Result {

	/**
	 * Sampled change records.
	 *
	 * @var Change[]
	 */
	private array $changes;

	/**
	 * Candidate rows offered to the engine.
	 *
	 * @var int
	 */
	private int $rows_scanned;

	/**
	 * Safe replacement occurrences found.
	 *
	 * @var int
	 */
	private int $replacements_count;

	/**
	 * Cursor for the next batch.
	 *
	 * @var string|null
	 */
	private ?string $next_cursor;

	/**
	 * Whether the table has no later candidate rows.
	 *
	 * @var bool
	 */
	private bool $done;

	/**
	 * Recoverable row errors.
	 *
	 * @var string[]
	 */
	private array $errors;

	/**
	 * Batch state returned to the runner.
	 *
	 * @param Change[]    $changes            Sampled change records.
	 * @param int         $rows_scanned       Candidate rows offered to the engine.
	 * @param int         $replacements_count Safe replacement occurrences found.
	 * @param string|null $next_cursor        Cursor for the next batch.
	 * @param bool        $done               Whether the table has no later candidate rows.
	 * @param string[]    $errors             Recoverable row errors.
	 */
	public function __construct( array $changes, int $rows_scanned, int $replacements_count, ?string $next_cursor, bool $done, array $errors = array() ) {
		$this->changes            = $changes;
		$this->rows_scanned       = $rows_scanned;
		$this->replacements_count = $replacements_count;
		$this->next_cursor        = $next_cursor;
		$this->done               = $done;
		$this->errors             = $errors;
	}

	/**
	 * Returns sampled change records.
	 *
	 * @return Change[]
	 */
	public function get_changes(): array {
		return $this->changes;
	}

	/**
	 * Returns candidate rows offered to the engine.
	 *
	 * @return int
	 */
	public function get_rows_scanned(): int {
		return $this->rows_scanned;
	}

	/**
	 * Returns safe replacement occurrences found.
	 *
	 * @return int
	 */
	public function get_replacements_count(): int {
		return $this->replacements_count;
	}

	/**
	 * Returns the cursor for the next batch.
	 *
	 * @return string|null
	 */
	public function get_next_cursor(): ?string {
		return $this->next_cursor;
	}

	/**
	 * Returns whether the table has no later candidate rows.
	 *
	 * @return bool
	 */
	public function is_done(): bool {
		return $this->done;
	}

	/**
	 * Returns recoverable row errors.
	 *
	 * @return string[]
	 */
	public function get_errors(): array {
		return $this->errors;
	}
}
