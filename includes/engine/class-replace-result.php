<?php
/**
 * Replacement result value object.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Engine;

/**
 * Describes the outcome of transforming one stored value.
 */
class Replace_Result {

	const SKIP_MALFORMED_SERIALIZED = 'malformed_serialized';

	const SKIP_DEPTH_EXCEEDED = 'depth_exceeded';

	const SKIP_LOSSY_JSON = 'lossy_json';

	/**
	 * Transformed or original value.
	 *
	 * @var string
	 */
	private string $new_value;

	/**
	 * Number of replacements made.
	 *
	 * @var int
	 */
	private int $count;

	/**
	 * Whether the value changed.
	 *
	 * @var bool
	 */
	private bool $changed;

	/**
	 * Whether transformation was skipped for safety.
	 *
	 * @var bool
	 */
	private bool $skipped;

	/**
	 * Machine-readable skip reason or an empty string.
	 *
	 * @var string
	 */
	private string $skip_reason;

	/**
	 * Layers in which replacements landed.
	 *
	 * @var string[]
	 */
	private array $formats;

	/**
	 * Captures one engine result.
	 *
	 * @param string   $new_value   Transformed or original value.
	 * @param int      $count       Number of replacements made.
	 * @param bool     $changed     Whether the value changed.
	 * @param bool     $skipped     Whether transformation was skipped.
	 * @param string   $skip_reason Machine-readable skip reason.
	 * @param string[] $formats     Layers in which replacements landed.
	 */
	public function __construct( string $new_value, int $count, bool $changed, bool $skipped, string $skip_reason, array $formats ) {
		$this->new_value   = $new_value;
		$this->count       = $count;
		$this->changed     = $changed;
		$this->skipped     = $skipped;
		$this->skip_reason = $skip_reason;
		$this->formats     = $formats;
	}

	/**
	 * Returns the transformed or original value.
	 *
	 * @return string
	 */
	public function get_new_value(): string {
		return $this->new_value;
	}

	/**
	 * Returns the number of replacements made.
	 *
	 * @return int
	 */
	public function get_count(): int {
		return $this->count;
	}

	/**
	 * Returns whether the value changed.
	 *
	 * @return bool
	 */
	public function is_changed(): bool {
		return $this->changed;
	}

	/**
	 * Returns whether transformation was skipped for safety.
	 *
	 * @return bool
	 */
	public function is_skipped(): bool {
		return $this->skipped;
	}

	/**
	 * Returns the machine-readable skip reason.
	 *
	 * @return string
	 */
	public function get_skip_reason(): string {
		return $this->skip_reason;
	}

	/**
	 * Returns layers in which replacements landed.
	 *
	 * @return string[]
	 */
	public function get_formats(): array {
		return $this->formats;
	}
}
