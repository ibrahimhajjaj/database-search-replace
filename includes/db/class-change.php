<?php
/**
 * Database change record.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Db;

/**
 * Describes one changed or safely skipped database column.
 */
class Change {

	public const SKIP_PROTECTED_TABLE = 'protected_table';

	public const SKIP_GUID = 'guid_excluded';

	public const SKIP_CONFLICT = 'value_conflict';

	/**
	 * Table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Column name.
	 *
	 * @var string
	 */
	private string $column;

	/**
	 * Display form of the primary key.
	 *
	 * @var string
	 */
	private string $row_pk;

	/**
	 * Original stored value.
	 *
	 * @var string
	 */
	private string $old_value;

	/**
	 * Proposed stored value.
	 *
	 * @var string
	 */
	private string $new_value;

	/**
	 * Original display segments.
	 *
	 * @var array<int,array{t:string,k:string}>
	 */
	private array $before_excerpt;

	/**
	 * Replacement display segments.
	 *
	 * @var array<int,array{t:string,k:string}>
	 */
	private array $after_excerpt;

	/**
	 * Whether excerpt text was omitted.
	 *
	 * @var bool
	 */
	private bool $truncated;

	/**
	 * Original value length in characters.
	 *
	 * @var int
	 */
	private int $before_length;

	/**
	 * Replacement value length in characters.
	 *
	 * @var int
	 */
	private int $after_length;

	/**
	 * Storage formats traversed by the engine.
	 *
	 * @var string[]
	 */
	private array $formats;

	/**
	 * Whether safety policy prevented a write.
	 *
	 * @var bool
	 */
	private bool $skipped;

	/**
	 * Machine-readable skip reason.
	 *
	 * @var string
	 */
	private string $skip_reason;

	/**
	 * Number of candidate replacements.
	 *
	 * @var int
	 */
	private int $replacements;

	/**
	 * One candidate cell change.
	 *
	 * @param string                                                                                                                                        $table        Table name.
	 * @param string                                                                                                                                        $column       Column name.
	 * @param string                                                                                                                                        $row_pk       Display form of the primary key.
	 * @param string                                                                                                                                        $old_value    Original stored value.
	 * @param string                                                                                                                                        $new_value    Proposed stored value.
	 * @param array{before:array<int,array{t:string,k:string}>,after:array<int,array{t:string,k:string}>,truncated:bool,before_length:int,after_length:int} $diff          Bounded display diff.
	 * @param string[]                                                                                                                                      $formats       Storage formats traversed by the engine.
	 * @param bool                                                                                                                                          $skipped       Whether safety policy prevented a write.
	 * @param string                                                                                                                                        $skip_reason   Machine-readable skip reason.
	 * @param int                                                                                                                                           $replacements Number of candidate replacements.
	 */
	public function __construct( string $table, string $column, string $row_pk, string $old_value, string $new_value, array $diff, array $formats, bool $skipped, string $skip_reason, int $replacements ) {
		$this->table          = $table;
		$this->column         = $column;
		$this->row_pk         = $row_pk;
		$this->old_value      = $old_value;
		$this->new_value      = $new_value;
		$this->before_excerpt = $diff['before'];
		$this->after_excerpt  = $diff['after'];
		$this->truncated      = $diff['truncated'];
		$this->before_length  = $diff['before_length'];
		$this->after_length   = $diff['after_length'];
		$this->formats        = array_values( $formats );
		$this->skipped        = $skipped;
		$this->skip_reason    = $skip_reason;
		$this->replacements   = $replacements;
	}

	/** Table name. @return string */
	public function get_table(): string {
		return $this->table;
	}

	/** Column name. @return string */
	public function get_column(): string {
		return $this->column;
	}

	/** Primary-key label. @return string */
	public function get_row_pk(): string {
		return $this->row_pk;
	}

	/** Original value. @return string */
	public function get_old_value(): string {
		return $this->old_value;
	}

	/** Replacement value. @return string */
	public function get_new_value(): string {
		return $this->new_value;
	}

	/**
	 * Returns original display segments.
	 *
	 * @return array<int,array{t:string,k:string}>
	 */
	public function get_before_excerpt(): array {
		return $this->before_excerpt;
	}

	/**
	 * Returns replacement display segments.
	 *
	 * @return array<int,array{t:string,k:string}>
	 */
	public function get_after_excerpt(): array {
		return $this->after_excerpt;
	}

	/** Whether the excerpt was omitted. @return bool */
	public function is_truncated(): bool {
		return $this->truncated;
	}

	/** Original character count. @return int */
	public function get_before_length(): int {
		return $this->before_length;
	}

	/** Replacement character count. @return int */
	public function get_after_length(): int {
		return $this->after_length;
	}

	/**
	 * Returns storage formats traversed by the engine.
	 *
	 * @return string[]
	 */
	public function get_formats(): array {
		return $this->formats;
	}

	/** Whether the write was skipped. @return bool */
	public function is_skipped(): bool {
		return $this->skipped;
	}

	/** Skip reason. @return string */
	public function get_skip_reason(): string {
		return $this->skip_reason;
	}

	/** Candidate replacement count. @return int */
	public function get_replacements(): int {
		return $this->replacements;
	}
}
