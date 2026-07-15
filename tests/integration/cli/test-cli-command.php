<?php
/**
 * Integration tests for the WP-CLI command logic.
 *
 * @package SafeSearchReplace
 */

require_once dirname( __DIR__ ) . '/fixtures/class-northwind-fixture.php';

use SafeSR\Cli\Cli_Command;
use SafeSR\Cli\Cli_IO;
use SafeSR\Cli\Cli_Progress;
use SafeSR\Jobs\Batch_Runner;
use SafeSR\Jobs\Job_Manager;
use SafeSR\Rest\Rest_Controller;

/**
 * Stops fake CLI requests at the same boundary as WP_CLI::error().
 */
class SafeSR_Cli_Test_Exception extends RuntimeException {
}

/**
 * Captures command output without loading the WP-CLI harness.
 */
class SafeSR_Test_Cli_IO extends Cli_IO {

	/**
	 * Whether confirmation should be accepted.
	 *
	 * @var bool
	 */
	public bool $confirm = true;

	/**
	 * Number of confirmation requests.
	 *
	 * @var int
	 */
	public int $confirmation_count = 0;

	/**
	 * Logged lines.
	 *
	 * @var string[]
	 */
	public array $logs = array();

	/**
	 * Success lines.
	 *
	 * @var string[]
	 */
	public array $successes = array();

	/**
	 * Formatted output calls.
	 *
	 * @var array<int,array{format:string,items:array<int,array<string,mixed>>,fields:string[]}>
	 */
	public array $formatted = array();

	/**
	 * Accepts or rejects a confirmation request.
	 *
	 * @param string              $message    Prompt text.
	 * @param array<string,mixed> $assoc_args Command options.
	 * @return void
	 */
	public function confirm( string $message, array $assoc_args ): void {
		unset( $message, $assoc_args );
		++$this->confirmation_count;
		if ( ! $this->confirm ) {
			throw new SafeSR_Cli_Test_Exception( 'Aborted.' );
		}
	}

	/**
	 * Captures an informational line.
	 *
	 * @param string $message Output text.
	 * @return void
	 */
	public function log( string $message ): void {
		$this->logs[] = $message;
	}

	/**
	 * Captures a success line.
	 *
	 * @param string $message Output text.
	 * @return void
	 */
	public function success( string $message ): void {
		$this->successes[] = $message;
	}

	/**
	 * Raises a clean command error.
	 *
	 * @param string $message Error text.
	 * @return void
	 * @throws SafeSR_Cli_Test_Exception Always.
	 */
	public function error( string $message ): void {
		throw new SafeSR_Cli_Test_Exception( $message );
	}

	/**
	 * Captures structured output.
	 *
	 * @param string                         $format Output format.
	 * @param array<int,array<string,mixed>> $items  Output rows.
	 * @param string[]                       $fields Ordered columns.
	 * @return void
	 */
	public function format_items( string $format, array $items, array $fields ): void {
		$this->formatted[] = compact( 'format', 'items', 'fields' );
	}

	/**
	 * Returns a no-op progress adapter.
	 *
	 * @param string $message Progress label.
	 * @param int    $count   Total units.
	 * @return Cli_Progress
	 */
	public function make_progress_bar( string $message, int $count ): Cli_Progress {
		unset( $message, $count );
		return new Cli_Progress();
	}
}

/**
 * Verifies command behavior against real job and database services.
 */
class SafeSR_Cli_Command_Test extends SafeSR_Integration_Test_Case {

	/**
	 * Persistent job service.
	 *
	 * @var Job_Manager
	 */
	private Job_Manager $manager;

	/**
	 * Configures the job service for each command test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->manager = new Job_Manager();
		update_option( 'safesr_keep_logs', 0 );
	}

	/**
	 * Releases job locks and restores normal options.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( 'safesr_active_job' );
		delete_option( 'safesr_keep_logs' );
		parent::tear_down();
	}

	/**
	 * Dry-run table counts match the REST preview path for the same fixture and inputs.
	 *
	 * @return void
	 */
	public function test_dry_run_counts_match_rest_preview_counts() {
		global $wpdb;
		new SafeSR_Northwind_Fixture( self::factory() );
		$tables = array( $wpdb->options, $wpdb->posts, $wpdb->postmeta );
		$io     = new SafeSR_Test_Cli_IO();
		$cli    = new Cli_Command( $this->manager, null, $io );

		$cli->replace(
			array( SafeSR_Northwind_Fixture::OLD_URL, SafeSR_Northwind_Fixture::NEW_URL ),
			array(
				'tables'         => implode( ',', $tables ),
				'dry-run'        => true,
				'case-sensitive' => true,
			)
		);

		$request = new WP_REST_Request( 'POST', '/safesr/v1/preview' );
		$request->set_body_params(
			array(
				'search'         => SafeSR_Northwind_Fixture::OLD_URL,
				'replace'        => SafeSR_Northwind_Fixture::NEW_URL,
				'tables'         => $tables,
				'case_sensitive' => true,
				'regex'          => false,
				'exclusions'     => array(),
				'include_guids'  => false,
				'thorough_scan'  => false,
			)
		);
		$response = ( new Rest_Controller( $this->manager ) )->create_preview( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$job_id = $response->get_data()['job_id'];
		$this->finish_job( $job_id );
		$summary = $this->manager->get_job( $job_id )['summary'];

		$actual = array();
		foreach ( $io->formatted[0]['items'] as $row ) {
			$actual[ $row['table'] ] = array(
				'matches' => $row['matches'],
				'skipped' => $row['skipped'],
			);
		}
		foreach ( $tables as $table ) {
			$this->assertSame( $summary[ $table ]['matches'], $actual[ $table ]['matches'] );
			$this->assertSame( $summary[ $table ]['skipped'], $actual[ $table ]['skipped'] );
		}
	}

	/**
	 * Apply and undo use durable jobs and restore original database bytes.
	 *
	 * @return void
	 */
	public function test_apply_and_undo_run_synchronously() {
		global $wpdb;
		new SafeSR_Northwind_Fixture( self::factory() );
		$io  = new SafeSR_Test_Cli_IO();
		$cli = new Cli_Command( $this->manager, null, $io );

		$cli->replace(
			array( SafeSR_Northwind_Fixture::OLD_URL, SafeSR_Northwind_Fixture::NEW_URL ),
			array(
				'tables' => $wpdb->options,
				'yes'    => true,
			)
		);
		$this->assertSame( SafeSR_Northwind_Fixture::NEW_URL, $this->stored_home_url() );
		preg_match( '/[a-f0-9]{32}/', end( $io->successes ), $matches );
		$this->assertNotEmpty( $matches[0] );

		$cli->undo( array( $matches[0] ), array( 'yes' => true ) );

		$this->assertSame( SafeSR_Northwind_Fixture::OLD_URL, $this->stored_home_url() );
		$this->assertNotEmpty( $this->manager->get_job( $matches[0] )['summary']['undone_at'] );
	}

	/**
	 * Apply stops before creating a job when confirmation is rejected.
	 *
	 * @return void
	 */
	public function test_apply_refuses_to_run_without_confirmation() {
		global $wpdb;
		$io          = new SafeSR_Test_Cli_IO();
		$io->confirm = false;
		$cli         = new Cli_Command( $this->manager, null, $io );

		try {
			$cli->replace( array( 'old', 'new' ), array( 'tables' => $wpdb->options ) );
			$this->fail( 'The command did not stop at confirmation.' );
		} catch ( SafeSR_Cli_Test_Exception $error ) {
			$this->assertSame( 'Aborted.', $error->getMessage() );
		}

		$this->assertSame( 1, $io->confirmation_count );
		$this->assertSame( '', get_option( 'safesr_active_job', '' ) );
	}

	/**
	 * Invalid regular expressions become clean command errors.
	 *
	 * @return void
	 */
	public function test_bad_regex_exits_with_clean_message() {
		global $wpdb;
		$cli = new Cli_Command( $this->manager, null, new SafeSR_Test_Cli_IO() );

		$this->expectException( SafeSR_Cli_Test_Exception::class );
		$this->expectExceptionMessage( 'Search pattern is not a valid regular expression.' );
		$cli->replace(
			array( '[', 'new' ),
			array(
				'tables'  => $wpdb->options,
				'regex'   => true,
				'dry-run' => true,
			)
		);
	}

	/**
	 * Advances one durable job until it reaches a terminal state.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private function finish_job( string $job_id ): void {
		$runner = new Batch_Runner( $this->manager );
		do {
			$runner->run_chunk( $job_id, 0 );
			$job = $this->manager->get_job( $job_id );
		} while ( in_array( $job['status'], array( 'queued', 'running' ), true ) );
	}

	/**
	 * Reads the stored home URL directly.
	 *
	 * The test configuration pins WP_HOME, so get_option() cannot observe
	 * the row this command writes.
	 *
	 * @return string
	 */
	private function stored_home_url(): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'home' )
		);
	}
}
