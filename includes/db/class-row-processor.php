<?php
/**
 * Batched database row processing.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Db;

use SafeSR\Engine\Replacer;

/**
 * Offers textual database values to the replacement engine and applies safe changes.
 */
class Row_Processor {

	private const SAMPLE_LIMIT = 20;

	/**
	 * Pre-write backup callback.
	 *
	 * @var callable|null
	 */
	private $backup_recorder;

	/**
	 * Backup rollback callback.
	 *
	 * @var callable|null
	 */
	private $backup_rollback;

	/**
	 * Complete preview candidate callback.
	 *
	 * @var callable|null
	 */
	private $candidate_recorder;

	/**
	 * Durable cell operation service.
	 *
	 * @var Operation_Ledger|null
	 */
	private ?Operation_Ledger $ledger;

	/**
	 * Apply job identifier.
	 *
	 * @var string
	 */
	private string $job_id;

	/**
	 * Bound preview job identifier.
	 *
	 * @var string
	 */
	private string $preview_job_id;

	/**
	 * Table metadata service.
	 *
	 * @var Table_Scanner
	 */
	private Table_Scanner $scanner;

	/**
	 * Display excerpt builder.
	 *
	 * @var Diff_Builder
	 */
	private Diff_Builder $diff_builder;

	/**
	 * Number of samples retained for each table.
	 *
	 * @var array<string,int>
	 */
	private array $sample_counts = array();

	/**
	 * Transaction capability by physical table.
	 *
	 * @var array<string,bool>
	 */
	private array $transaction_support = array();

	/**
	 * The backup callback receives table, key, original values, and applied values.
	 *
	 * @param callable|null         $backup_recorder Pre-write backup callback.
	 * @param Table_Scanner         $scanner         Optional table metadata service.
	 * @param Diff_Builder          $diff_builder    Optional excerpt builder.
	 * @param callable|null         $backup_rollback Removes a backup whose CAS write failed.
	 * @param Operation_Ledger|null $ledger Durable apply operation service.
	 * @param string                $job_id Apply job identifier.
	 * @param string                $preview_job_id Bound preview identifier.
	 * @param callable|null         $candidate_recorder Complete preview candidate callback.
	 */
	public function __construct( ?callable $backup_recorder = null, ?Table_Scanner $scanner = null, ?Diff_Builder $diff_builder = null, ?callable $backup_rollback = null, ?Operation_Ledger $ledger = null, string $job_id = '', string $preview_job_id = '', ?callable $candidate_recorder = null ) {
		$this->backup_recorder    = $backup_recorder;
		$this->backup_rollback    = $backup_rollback;
		$this->candidate_recorder = $candidate_recorder;
		$this->ledger             = $ledger;
		$this->job_id             = $job_id;
		$this->preview_job_id     = $preview_job_id;
		$this->scanner            = $scanner ?? new Table_Scanner();
		$this->diff_builder       = $diff_builder ?? new Diff_Builder();
	}

	/**
	 * Processes at most one configured batch from a table.
	 *
	 * @param Run_Config  $config Database and engine configuration.
	 * @param string      $table  Physical table name.
	 * @param string|null $cursor Last processed primary-key cursor.
	 * @return Batch_Result
	 */
	public function process_table_batch( Run_Config $config, string $table, ?string $cursor ): Batch_Result {
		if ( Table_Scanner::is_plugin_table( $table ) ) {
			return new Batch_Result( array(), 0, 0, null, true );
		}

		$primary_key = $this->scanner->get_primary_key( $table );
		if ( null === $primary_key ) {
			return new Batch_Result( array(), 0, 0, null, true, array( 'no_primary_key' ) );
		}

		$text_columns = $this->scanner->get_text_columns( $table );
		if ( empty( $text_columns ) ) {
			return new Batch_Result( array(), 0, 0, null, true );
		}

		$rows       = $this->select_rows( $config, $table, $primary_key, $text_columns, $cursor );
		$done       = count( $rows ) <= $config->get_batch_size();
		$rows       = array_slice( $rows, 0, $config->get_batch_size() );
		$changes    = array();
		$errors     = array();
		$replacings = 0;
		$replacer   = new Replacer( $config->get_search_config() );
		$protected  = $this->scanner->is_protected( $table );

		foreach ( $rows as $row ) {
			$row_key = $this->primary_key_values( $row, $primary_key );
			$row_pk  = $this->display_primary_key( $row_key );
			$updates = array();

			foreach ( $text_columns as $column ) {
				if ( null === $row[ $column ] ) {
					continue;
				}

				$old_value = (string) $row[ $column ];
				if ( '' !== $this->preview_job_id && null !== $this->ledger ) {
					$candidate = $this->ledger->get_preview_candidate( $this->preview_job_id, $table, $row_key, $column );
					if ( null === $candidate ) {
						continue;
					}
					if ( ! hash_equals( (string) $candidate['expected_hash'], hash( 'sha256', $old_value ) ) ) {
						$operation = $this->ledger->claim(
							$this->job_id,
							$this->preview_job_id,
							$table,
							$row_key,
							$column,
							$old_value,
							$old_value,
							(int) $candidate['replacements']
						);
						if ( 'complete' === $operation['action'] ) {
							continue;
						}
						$change = new Change(
							$table,
							$column,
							$row_pk,
							$old_value,
							$old_value,
							$this->diff_builder->build( $old_value, $old_value ),
							array(),
							true,
							Change::SKIP_CONFLICT,
							(int) $candidate['replacements']
						);
						if ( $this->may_sample( $table ) ) {
							$changes[] = $change;
						}
						$errors[] = 'conflict:' . $row_pk . ':' . $column;
						continue;
					}
				}
				$result = $replacer->replace_value( $old_value );
				if ( ! $result->is_changed() && ! $result->is_skipped() ) {
					continue;
				}

				$policy_reason = '';
				if ( $protected ) {
					$policy_reason = Change::SKIP_PROTECTED_TABLE;
				} elseif ( $this->is_excluded_guid( $config, $table, $column ) ) {
					$policy_reason = Change::SKIP_GUID;
				}

				$skipped     = $result->is_skipped() || '' !== $policy_reason;
				$skip_reason = $result->is_skipped() ? $result->get_skip_reason() : $policy_reason;
				$new_value   = $result->is_changed() ? $result->get_new_value() : $old_value;
				$change      = new Change(
					$table,
					$column,
					$row_pk,
					$old_value,
					$new_value,
					$this->diff_builder->build( $old_value, $new_value ),
					$result->get_formats(),
					$skipped,
					$skip_reason,
					$result->get_count()
				);

				if ( $this->may_sample( $table ) ) {
					$changes[] = $change;
				}

				if ( $skipped ) {
					continue;
				}

				if ( null !== $this->candidate_recorder ) {
					call_user_func( $this->candidate_recorder, $table, $row_key, $column, $old_value, $new_value, $result->get_count() );
				}
				if ( $config->is_dry_run() ) {
					$replacings += $result->get_count();
				} else {
					$updates[ $column ] = array(
						'old'          => $old_value,
						'new'          => $new_value,
						'replacements' => $result->get_count(),
					);
				}
			}

			if ( $config->is_dry_run() || empty( $updates ) ) {
				continue;
			}

			foreach ( $updates as $column => $update ) {
				$written = $this->apply_cell( $table, $row_key, $row_pk, $column, $update, $errors );
				if ( $written ) {
					$replacings += (int) $update['replacements'];
				}
			}
		}

		$next_cursor = null;
		if ( ! $done && ! empty( $rows ) ) {
			$last        = end( $rows );
			$next_cursor = $this->encode_cursor( $this->primary_key_values( $last, $primary_key ) );
		}

		return new Batch_Result( $changes, count( $rows ), $replacings, $next_cursor, $done, $errors );
	}

	/**
	 * Claims, snapshots, and compare-and-swaps one changed cell.
	 *
	 * @param string                                        $table Physical table name.
	 * @param array<string,mixed>                           $row_key Primary-key values.
	 * @param string                                        $row_pk Display primary key.
	 * @param string                                        $column Text column name.
	 * @param array{old:string,new:string,replacements:int} $update Proposed change.
	 * @param string[]                                      $errors Recoverable errors, updated by reference.
	 * @return bool
	 * @throws \RuntimeException When transactional cell state cannot be persisted.
	 * @throws \Throwable When a claim or backup callback cannot complete.
	 */
	private function apply_cell( string $table, array $row_key, string $row_pk, string $column, array $update, array &$errors ): bool {
		if ( ! $this->supports_transactions( $table ) ) {
			$written = $this->apply_claimed_cell( $table, $row_key, $row_pk, $column, $update, $errors );
			if ( $written ) {
				wp_cache_flush();
			}
			return $written;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Static transaction statement.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'A replacement transaction could not be started.' );
		}
		try {
			$written = $this->apply_claimed_cell( $table, $row_key, $row_pk, $column, $update, $errors );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Static transaction statement.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'A replacement transaction could not be committed.' );
			}
			if ( $written ) {
				wp_cache_flush();
			}
			return $written;
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Static transaction statement.
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Applies one cell within the caller's durability boundary.
	 *
	 * @param string                                        $table Physical table name.
	 * @param array<string,mixed>                           $row_key Primary-key values.
	 * @param string                                        $row_pk Display primary key.
	 * @param string                                        $column Text column name.
	 * @param array{old:string,new:string,replacements:int} $update Proposed change.
	 * @param string[]                                      $errors Recoverable errors, updated by reference.
	 * @return bool
	 */
	private function apply_claimed_cell( string $table, array $row_key, string $row_pk, string $column, array $update, array &$errors ): bool {
		$operation = null;
		if ( null !== $this->ledger ) {
			$operation = $this->ledger->claim(
				$this->job_id,
				$this->preview_job_id,
				$table,
				$row_key,
				$column,
				$update['old'],
				$update['new'],
				$update['replacements']
			);
			if ( 'complete' === $operation['action'] ) {
				return false;
			}
			if ( 'conflict' === $operation['action'] ) {
				$errors[] = 'conflict:' . $row_pk . ':' . $column;
				return false;
			}
			$update['old']          = $operation['expected'];
			$update['new']          = $operation['applied'];
			$update['replacements'] = $operation['replacements'];
		}

		$backup = null;
		if ( null !== $this->backup_recorder ) {
			$backup = call_user_func( $this->backup_recorder, $table, $row_key, array( $column => $update['old'] ), array( $column => $update['new'] ) );
		}

		$updated = $this->compare_and_swap( $table, $row_key, $column, $update['old'], $update['new'] );
		if ( 1 !== $updated ) {
			if ( null !== $this->backup_rollback && null !== $backup ) {
				call_user_func( $this->backup_rollback, $backup );
			}
			if ( null !== $operation && null !== $this->ledger ) {
				$this->ledger->mark_conflict( (int) $operation['id'] );
			}
			$errors[] = 'conflict:' . $row_pk . ':' . $column;
			return false;
		}
		if ( null !== $operation && null !== $this->ledger ) {
			$this->ledger->mark_applied( (int) $operation['id'] );
		}
		return true;
	}

	/**
	 * Caches whether a table can wrap cell state in a transaction.
	 *
	 * @param string $table Physical table name.
	 * @return bool
	 */
	private function supports_transactions( string $table ): bool {
		if ( ! array_key_exists( $table, $this->transaction_support ) ) {
			$this->transaction_support[ $table ] = $this->scanner->supports_transactions( $table );
		}
		return $this->transaction_support[ $table ];
	}

	/**
	 * Updates one cell only while its selected bytes remain current.
	 *
	 * @param string              $table Physical table name.
	 * @param array<string,mixed> $row_key Primary-key values.
	 * @param string              $column Text column name.
	 * @param string              $expected Selected value.
	 * @param string              $applied Replacement value.
	 * @return int|false
	 */
	private function compare_and_swap( string $table, array $row_key, string $column, string $expected, string $applied ) {
		global $wpdb;
		$where = array();
		$args  = array( $table, $column, $applied );
		foreach ( $row_key as $key => $value ) {
			$where[] = '%i = %s';
			$args[]  = $key;
			$args[]  = $value;
		}
		$where[] = 'BINARY %i = BINARY %s';
		$args[]  = $column;
		$args[]  = $expected;
		$sql     = 'UPDATE %i SET %i = %s WHERE ' . implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers and values are placeholders.
		return $wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Selects candidate rows using keyset pagination.
	 *
	 * @param Run_Config  $config       Run configuration.
	 * @param string      $table        Physical table name.
	 * @param string[]    $primary_key  Ordered primary-key columns.
	 * @param string[]    $text_columns Text columns to scan.
	 * @param string|null $cursor       Last processed cursor.
	 * @return array<int,array<string,mixed>>
	 */
	private function select_rows( Run_Config $config, string $table, array $primary_key, array $text_columns, ?string $cursor ): array {
		global $wpdb;
		$select_columns = array_values( array_unique( array_merge( $primary_key, $text_columns ) ) );
		$select_sql     = implode( ', ', array_fill( 0, count( $select_columns ), '%i' ) );
		$order_sql      = implode( ', ', array_fill( 0, count( $primary_key ), '%i' ) );
		$sql            = 'SELECT ' . $select_sql . ' FROM %i';
		$arguments      = array_merge( $select_columns, array( $table ) );
		$where          = array();

		if ( null !== $cursor ) {
			$cursor_values = $this->decode_cursor( $cursor, $primary_key );
			$tuple_columns = '(' . implode( ', ', array_fill( 0, count( $primary_key ), '%i' ) ) . ')';
			$tuple_values  = '(' . implode( ', ', array_fill( 0, count( $primary_key ), '%s' ) ) . ')';
			$where[]       = $tuple_columns . ' > ' . $tuple_values;
			$arguments     = array_merge( $arguments, $primary_key, array_values( $cursor_values ) );
		}

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql      .= ' ORDER BY ' . $order_sql . ' LIMIT %d';
		$arguments = array_merge( $arguments, $primary_key, array( $config->get_batch_size() + 1 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic parts are placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $arguments ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Extracts ordered primary-key values from a selected row.
	 *
	 * @param array<string,mixed> $row         Selected row.
	 * @param string[]            $primary_key Primary-key columns.
	 * @return array<string,mixed>
	 */
	private function primary_key_values( array $row, array $primary_key ): array {
		$values = array();
		foreach ( $primary_key as $column ) {
			$values[ $column ] = $row[ $column ];
		}
		return $values;
	}

	/**
	 * Returns a concise primary-key label.
	 *
	 * @param array<string,mixed> $values Primary-key map.
	 * @return string
	 */
	private function display_primary_key( array $values ): string {
		return implode( '|', array_map( 'strval', array_values( $values ) ) );
	}

	/**
	 * Encodes ordered primary-key values for keyset pagination.
	 *
	 * @param array<string,mixed> $values Primary-key map.
	 * @return string
	 */
	private function encode_cursor( array $values ): string {
		$encoded = wp_json_encode( array_values( $values ) );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Decodes a cursor into its ordered primary-key map.
	 *
	 * @param string   $cursor      Encoded cursor.
	 * @param string[] $primary_key Primary-key columns.
	 * @return array<string,mixed>
	 */
	private function decode_cursor( string $cursor, array $primary_key ): array {
		$decoded = json_decode( $cursor, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array( $cursor );
		}
		$values = array();
		foreach ( $primary_key as $index => $column ) {
			$values[ $column ] = $decoded[ $index ] ?? '';
		}
		return $values;
	}

	/**
	 * Returns whether a column is a post GUID excluded by configuration.
	 *
	 * @param Run_Config $config Run configuration.
	 * @param string     $table  Table name.
	 * @param string     $column Column name.
	 * @return bool
	 */
	private function is_excluded_guid( Run_Config $config, string $table, string $column ): bool {
		global $wpdb;
		return ! $config->should_include_guids() && $wpdb->posts === $table && 'guid' === $column;
	}

	/**
	 * Reserves a sample slot for a table when its cap is not reached.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function may_sample( string $table ): bool {
		$count = $this->sample_counts[ $table ] ?? 0;
		if ( self::SAMPLE_LIMIT <= $count ) {
			return false;
		}
		$this->sample_counts[ $table ] = $count + 1;
		return true;
	}
}
