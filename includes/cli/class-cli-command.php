<?php
/**
 * WP-CLI commands for search and replace jobs.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Cli;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP-CLI terminal text.

use InvalidArgumentException;
use RuntimeException;
use SafeSR\Db\Run_Config;
use SafeSR\Db\Table_Scanner;
use SafeSR\Engine\Search_Config;
use SafeSR\Jobs\Batch_Runner;
use SafeSR\Jobs\Job_Manager;

/**
 * Runs previews, replacements, undo jobs, and job listings from WP-CLI.
 */
class Cli_Command {

	/**
	 * Persistent job service.
	 *
	 * @var Job_Manager
	 */
	private Job_Manager $manager;

	/**
	 * Table metadata service.
	 *
	 * @var Table_Scanner
	 */
	private Table_Scanner $scanner;

	/**
	 * CLI input and output adapter.
	 *
	 * @var Cli_IO
	 */
	private Cli_IO $io;

	/**
	 * Supports injected services for CLI tests.
	 *
	 * @param Job_Manager|null   $manager Optional job service.
	 * @param Table_Scanner|null $scanner Optional table scanner.
	 * @param Cli_IO|null        $io      Optional CLI adapter.
	 */
	public function __construct( ?Job_Manager $manager = null, ?Table_Scanner $scanner = null, ?Cli_IO $io = null ) {
		$this->manager = $manager ?? new Job_Manager();
		$this->scanner = $scanner ?? new Table_Scanner();
		$this->io      = $io ?? new Cli_IO();
	}

	/**
	 * Previews or applies a database replacement.
	 *
	 * ## OPTIONS
	 *
	 * <search>
	 * : Text or regular expression body to find.
	 *
	 * <replace>
	 * : Replacement text.
	 *
	 * [--tables=<csv>]
	 * : Physical table names. Defaults to processable, unprotected current-site tables.
	 *
	 * [--dry-run]
	 * : Preview changes without writing.
	 *
	 * [--regex]
	 * : Treat search as a regular expression body.
	 *
	 * [--case-sensitive]
	 * : Match case exactly.
	 *
	 * [--include-guids]
	 * : Permit replacement in the posts GUID column.
	 *
	 * [--thorough]
	 * : Scan every row instead of using a SQL prefilter.
	 *
	 * [--exclude=<csv>]
	 * : Literal values to protect from replacement.
	 *
	 * [--batch-size=<n>]
	 * : Maximum rows processed in one database batch.
	 *
	 * [--format=<format>]
	 * : Summary format: table, json, or csv.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--yes]
	 * : Skip confirmation for an apply run.
	 *
	 * @param string[]            $args       Positional command arguments.
	 * @param array<string,mixed> $assoc_args Command options.
	 * @return void
	 * @throws InvalidArgumentException When required arguments are missing or invalid.
	 */
	public function replace( array $args, array $assoc_args ): void {
		try {
			if ( 2 > count( $args ) ) {
				throw new InvalidArgumentException( __( 'Search and replacement values are required.', 'database-search-replace' ) );
			}
			$dry_run = ! empty( $assoc_args['dry-run'] );
			$config  = $this->run_config( (string) $args[0], (string) $args[1], $assoc_args, $dry_run );
			$format  = $this->format( $assoc_args, array( 'table', 'json', 'csv' ) );

			if ( ! $dry_run && empty( $assoc_args['yes'] ) ) {
				$this->io->confirm( __( 'Apply this replacement to the selected database tables?', 'database-search-replace' ), $assoc_args );
			}

			$job_id = $dry_run ? $this->manager->create_preview( $config ) : $this->manager->create_apply( $config );
			$job    = $this->run_synchronously( $job_id, __( 'Processing database tables', 'database-search-replace' ) );
			$this->print_summary( $job, $format );

			if ( $dry_run ) {
				$this->print_samples( $job_id );
				return;
			}
			$this->io->success(
				sprintf(
					/* translators: %s: apply job identifier. */
					__( 'Replacement completed. Undo with: wp safesr undo %s', 'database-search-replace' ),
					$job_id
				)
			);
		} catch ( InvalidArgumentException | RuntimeException $error ) {
			$this->io->error( $error->getMessage() );
		}
	}

	/**
	 * Restores a completed apply job.
	 *
	 * ## OPTIONS
	 *
	 * <job-id>
	 * : Completed apply job identifier.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * @param string[]            $args       Positional command arguments.
	 * @param array<string,mixed> $assoc_args Command options.
	 * @return void
	 * @throws InvalidArgumentException When the job identifier is missing.
	 */
	public function undo( array $args, array $assoc_args ): void {
		try {
			$job_id = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
			if ( '' === $job_id ) {
				throw new InvalidArgumentException( __( 'A job identifier is required.', 'database-search-replace' ) );
			}
			if ( empty( $assoc_args['yes'] ) ) {
				$this->io->confirm( __( 'Restore all values backed up by this job?', 'database-search-replace' ), $assoc_args );
			}
			$undo_id = $this->manager->create_undo( $job_id );
			$this->run_synchronously( $undo_id, __( 'Restoring database values', 'database-search-replace' ) );
			$this->io->success(
				sprintf(
					/* translators: %s: undo job identifier. */
					__( 'Undo completed. Job ID: %s', 'database-search-replace' ),
					$undo_id
				)
			);
		} catch ( InvalidArgumentException | RuntimeException $error ) {
			$this->io->error( $error->getMessage() );
		}
	}

	/**
	 * Lists recent search and replace jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param string[]            $args       Positional command arguments.
	 * @param array<string,mixed> $assoc_args Command options.
	 * @return void
	 */
	public function jobs( array $args, array $assoc_args ): void {
		unset( $args );
		try {
			$format = $this->format( $assoc_args, array( 'table', 'json' ) );
			$rows   = array();
			foreach ( $this->manager->get_jobs( 20 ) as $job ) {
				$progress = $job['progress'];
				$rows[]   = array(
					'id'           => $job['id'],
					'type'         => $job['type'],
					'status'       => $job['status'],
					'matches'      => (int) ( $progress['matches'] ?? 0 ),
					'replacements' => (int) ( $progress['replacements'] ?? 0 ),
					'skipped'      => (int) ( $progress['skipped'] ?? 0 ),
					'undoable'     => $this->is_undoable( $job ) ? 'yes' : 'no',
					'created_at'   => $job['created_at'],
				);
			}
			$this->io->format_items( $format, $rows, array( 'id', 'type', 'status', 'matches', 'replacements', 'skipped', 'undoable', 'created_at' ) );
		} catch ( InvalidArgumentException $error ) {
			$this->io->error( $error->getMessage() );
		}
	}

	/**
	 * Builds validated engine and traversal configuration from command options.
	 *
	 * @param string              $search     Search text.
	 * @param string              $replace    Replacement text.
	 * @param array<string,mixed> $assoc_args Command options.
	 * @param bool                $dry_run    Whether writes are disabled.
	 * @return Run_Config
	 */
	private function run_config( string $search, string $replace, array $assoc_args, bool $dry_run ): Run_Config {
		$tables     = $this->selected_tables( isset( $assoc_args['tables'] ) ? (string) $assoc_args['tables'] : '' );
		$exclusions = isset( $assoc_args['exclude'] ) ? $this->csv( (string) $assoc_args['exclude'] ) : array();
		$engine     = new Search_Config(
			$search,
			$replace,
			! empty( $assoc_args['case-sensitive'] ),
			! empty( $assoc_args['regex'] ),
			$exclusions
		);
		return new Run_Config(
			$engine,
			$tables,
			! empty( $assoc_args['include-guids'] ),
			! empty( $assoc_args['thorough'] ),
			$assoc_args['batch-size'] ?? null,
			$dry_run
		);
	}

	/**
	 * Resolves requested tables against current-site table discovery.
	 *
	 * @param string $csv Requested physical table names.
	 * @return string[]
	 * @throws InvalidArgumentException When a requested table is unknown or protected.
	 */
	private function selected_tables( string $csv ): array {
		$metadata  = $this->scanner->get_tables();
		$available = array_column( $metadata, 'name' );
		if ( '' !== $csv ) {
			$selected = $this->csv( $csv );
			foreach ( $selected as $table ) {
				if ( ! in_array( $table, $available, true ) ) {
					throw new InvalidArgumentException(
						sprintf(
							/* translators: %s: physical database table name. */
							__( 'Table is not available for the current site: %s', 'database-search-replace' ),
							$table
						)
					);
				}
			}
			return $selected;
		}

		$selected = array();
		foreach ( $metadata as $table ) {
			if ( ! $table['protected'] && null !== $this->scanner->get_primary_key( $table['name'] ) && ! empty( $this->scanner->get_text_columns( $table['name'] ) ) ) {
				$selected[] = $table['name'];
			}
		}
		return $selected;
	}

	/**
	 * Drives queued chunks until the job reaches a terminal state.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $label  Progress label.
	 * @return array<string,mixed>
	 * @throws RuntimeException When the job fails or disappears mid-run.
	 */
	private function run_synchronously( string $job_id, string $label ): array {
		$job      = $this->manager->get_job( $job_id );
		$total    = max( 1, (int) ( $job['progress']['tables_total'] ?? 1 ) );
		$done     = 0;
		$progress = $this->io->make_progress_bar( $label, $total );
		$runner   = new Batch_Runner( $this->manager );

		do {
			$runner->run_chunk( $job_id, 0 );
			$job = $this->manager->get_job( $job_id );
			if ( null === $job ) {
				throw new RuntimeException( __( 'The job could not be loaded.', 'database-search-replace' ) );
			}
			$current_done = min( $total, (int) ( $job['progress']['tables_done'] ?? 0 ) );
			if ( $current_done > $done ) {
				$progress->tick( $current_done - $done );
				$done = $current_done;
			}
		} while ( in_array( $job['status'], array( 'queued', 'running' ), true ) );

		if ( $done < $total ) {
			$progress->tick( $total - $done );
		}
		$progress->finish();
		if ( 'completed' !== $job['status'] ) {
			throw new RuntimeException( (string) ( $job['error'] ?? __( 'The job did not complete.', 'database-search-replace' ) ) );
		}
		return $job;
	}

	/**
	 * Prints per-table match and skip counters.
	 *
	 * @param array<string,mixed> $job    Completed job record.
	 * @param string              $format Output format.
	 * @return void
	 */
	private function print_summary( array $job, string $format ): void {
		$rows = array();
		foreach ( $job['summary'] as $table => $counts ) {
			if ( ! is_array( $counts ) || ! isset( $counts['matches'] ) ) {
				continue;
			}
			$rows[] = array(
				'table'        => $table,
				'matches'      => (int) $counts['matches'],
				'replacements' => (int) $counts['replacements'],
				'skipped'      => (int) $counts['skipped'],
			);
		}
		$this->io->format_items( $format, $rows, array( 'table', 'matches', 'replacements', 'skipped' ) );
	}

	/**
	 * Prints retained before and after samples for a preview job.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private function print_samples( string $job_id ): void {
		$result = $this->manager->get_changes( $job_id, 1, 100 );
		foreach ( $result['items'] as $change ) {
			$before = implode( '', array_column( $change['before_excerpt'], 't' ) );
			$after  = implode( '', array_column( $change['after_excerpt'], 't' ) );
			$this->io->log( sprintf( '%s.%s: %s => %s', $change['table_name'], $change['column_name'], $before, $after ) );
		}
	}

	/**
	 * Validates an output format.
	 *
	 * @param array<string,mixed> $assoc_args Command options.
	 * @param string[]            $allowed    Supported formats.
	 * @return string
	 * @throws InvalidArgumentException When the requested format is unsupported.
	 */
	private function format( array $assoc_args, array $allowed ): string {
		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
		if ( ! in_array( $format, $allowed, true ) ) {
			throw new InvalidArgumentException( __( 'The requested output format is not supported.', 'database-search-replace' ) );
		}
		return $format;
	}

	/**
	 * Splits comma-separated values while discarding empty fields.
	 *
	 * @param string $value Comma-separated values.
	 * @return string[]
	 */
	private function csv( string $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn( string $item ): bool => '' !== $item ) );
	}

	/**
	 * Returns whether a job can create one undo job.
	 *
	 * @param array<string,mixed> $job Job record.
	 * @return bool
	 */
	private function is_undoable( array $job ): bool {
		return 'apply' === $job['type'] && 'completed' === $job['status'] && ! empty( $job['backup_id'] ) && empty( $job['summary']['undone_at'] );
	}
}
