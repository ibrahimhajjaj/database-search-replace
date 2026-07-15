<?php
/**
 * Integration tests for the search and replace REST API.
 *
 * @package SafeSearchReplace
 */

use SafeSR\Db\Run_Config;
use SafeSR\Jobs\Batch_Runner;
use SafeSR\Jobs\Job_Manager;

require_once dirname( __DIR__ ) . '/fixtures/class-northwind-fixture.php';

/**
 * Verifies authorization, request validation, and settings protection.
 */
class SafeSR_Rest_Controller_Test extends SafeSR_Integration_Test_Case {

	/**
	 * REST server used by the tests.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Registers routes before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The test initializes routes through WordPress's core hook.
		do_action( 'rest_api_init', $this->server );
		delete_option( 'safesr_active_job' );
	}

	/**
	 * Logged-out requests cannot read plugin data.
	 *
	 * @return void
	 */
	public function test_logged_out_requests_are_rejected() {
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/safesr/v1/tables' ) );
		$this->assertContains( $response->get_status(), array( 401, 403 ), true );
	}

	/**
	 * Subscribers receive a forbidden response from every registered route.
	 *
	 * @return void
	 */
	public function test_logged_in_non_admin_requests_are_forbidden_on_every_route() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$job_id        = str_repeat( '0', 32 );
		$run_body      = array(
			'search'         => SafeSR_Northwind_Fixture::OLD_URL,
			'replace'        => SafeSR_Northwind_Fixture::NEW_URL,
			'tables'         => array(),
			'preview_job_id' => $job_id,
		);
		$settings_body = array(
			'protected_tables' => array(),
			'batch_size'       => 250,
			'keep_logs'        => true,
		);
		$requests      = array(
			array( 'GET', '/safesr/v1/tables', array() ),
			array( 'GET', '/safesr/v1/jobs', array() ),
			array( 'POST', '/safesr/v1/preview', $run_body ),
			array( 'POST', '/safesr/v1/apply', $run_body ),
			array( 'GET', '/safesr/v1/jobs/' . $job_id, array() ),
			array( 'GET', '/safesr/v1/jobs/' . $job_id . '/changes', array() ),
			array( 'POST', '/safesr/v1/jobs/' . $job_id . '/cancel', array() ),
			array( 'POST', '/safesr/v1/jobs/' . $job_id . '/undo', array() ),
			array( 'GET', '/safesr/v1/jobs/' . $job_id . '/log', array() ),
			array( 'GET', '/safesr/v1/settings', array() ),
			array( 'POST', '/safesr/v1/settings', $settings_body ),
		);

		foreach ( $requests as $route ) {
			$request = new WP_REST_Request( $route[0], $route[1] );
			$request->set_body_params( $route[2] );
			$this->assertSame( 403, $this->server->dispatch( $request )->get_status(), $route[0] . ' ' . $route[1] );
		}
	}

	/**
	 * Applied jobs are listed newest first with paging and current backup availability.
	 *
	 * @return void
	 */
	public function test_jobs_list_returns_applied_runs_with_documented_shape_and_paging() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		new SafeSR_Northwind_Fixture( self::factory() );
		$manager = new Job_Manager();
		$runner  = new Batch_Runner( $manager );

		$with_backup = $manager->create_apply( $this->config( false, 2 ) );
		$this->finish( $runner, $manager, $with_backup );
		$without_backup = $manager->create_apply( $this->config( false, 2 ), false );
		$this->finish( $runner, $manager, $without_backup );

		$wpdb->update(
			SafeSR\Db\Schema::jobs_table_name(),
			array( 'created_at' => '2026-07-14 10:00:00' ),
			array( 'id' => $with_backup )
		);
		$wpdb->update(
			SafeSR\Db\Schema::jobs_table_name(),
			array( 'created_at' => '2026-07-14 11:00:00' ),
			array( 'id' => $without_backup )
		);

		$first = $this->jobs_request( array( 'per_page' => 1 ) );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 2, $first->get_data()['total'] );
		$this->assertCount( 1, $first->get_data()['items'] );
		$this->assertSame( $without_backup, $first->get_data()['items'][0]['id'] );
		$this->assertFalse( $first->get_data()['items'][0]['has_backup'] );
		$this->assertFalse( $first->get_data()['items'][0]['undoable'] );
		$this->assertSame( $without_backup, $manager->latest_undoable_apply_id() );

		$second = $this->jobs_request(
			array(
				'page'     => 2,
				'per_page' => 1,
			)
		);
		$item   = $second->get_data()['items'][0];
		$this->assertSame( $with_backup, $item['id'] );
		$this->assertTrue( $item['has_backup'] );
		$this->assertFalse( $item['undoable'] );
		$this->assertSame(
			array(
				'id',
				'type',
				'run_kind',
				'status',
				'created_at',
				'finished_at',
				'search',
				'replace',
				'tables',
				'replacements',
				'matches',
				'skipped',
				'has_backup',
				'undoable',
				'undone',
				'undone_at',
			),
			array_keys( $item )
		);
		$this->assertSame( SafeSR_Northwind_Fixture::OLD_URL, $item['search'] );
		$this->assertSame( SafeSR_Northwind_Fixture::NEW_URL, $item['replace'] );
		$this->assertSame( 3, $item['tables'] );
		$this->assertGreaterThan( 0, $item['replacements'] );
		$this->assertFalse( $item['undone'] );
		$this->assertNull( $item['undone_at'] );

		$wpdb->delete(
			SafeSR\Db\Schema::backup_rows_table_name(),
			array( 'backup_id' => $manager->get_job( $with_backup )['backup_id'] )
		);
		$pruned = $this->jobs_request(
			array(
				'page'     => 2,
				'per_page' => 1,
			)
		);
		$this->assertFalse( $pruned->get_data()['items'][0]['has_backup'] );
	}

	/**
	 * History includes completed previews and applies but excludes undo jobs.
	 *
	 * @return void
	 */
	public function test_history_returns_preview_and_apply_runs_newest_first() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		new SafeSR_Northwind_Fixture( self::factory() );
		$manager = new Job_Manager();
		$runner  = new Batch_Runner( $manager );

		$preview_id = $manager->create_preview( $this->config( true, 2 ) );
		$this->finish( $runner, $manager, $preview_id );
		$apply_id = $manager->create_apply( $this->config( false, 2 ) );
		$this->finish( $runner, $manager, $apply_id );
		$undo_id = $manager->create_undo( $apply_id );
		$this->finish( $runner, $manager, $undo_id );

		$wpdb->update( SafeSR\Db\Schema::jobs_table_name(), array( 'created_at' => '2026-07-14 10:00:00' ), array( 'id' => $preview_id ) );
		$wpdb->update( SafeSR\Db\Schema::jobs_table_name(), array( 'created_at' => '2026-07-14 11:00:00' ), array( 'id' => $apply_id ) );
		$wpdb->update( SafeSR\Db\Schema::jobs_table_name(), array( 'created_at' => '2026-07-14 12:00:00' ), array( 'id' => $undo_id ) );

		$list = $this->jobs_request( array( 'type' => 'history' ) )->get_data();
		$this->assertSame( 2, $list['total'] );
		$this->assertSame( array( $apply_id, $preview_id ), wp_list_pluck( $list['items'], 'id' ) );
		$this->assertSame( array( 'apply', 'preview' ), wp_list_pluck( $list['items'], 'run_kind' ) );
		$this->assertFalse( $list['items'][1]['has_backup'] );
		$this->assertFalse( $list['items'][1]['undoable'] );
		$this->assertFalse( $list['items'][1]['undone'] );
		$this->assertGreaterThan( 0, $list['items'][1]['matches'] );
	}

	/**
	 * Applies are undone newest-first without overwriting a newer value.
	 *
	 * @return void
	 */
	public function test_apply_undo_is_a_lifo_stack() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		new SafeSR_Northwind_Fixture( self::factory() );
		$manager      = new Job_Manager();
		$runner       = new Batch_Runner( $manager );
		$original     = $this->stored_siteurl();
		$intermediate = 'https://middle.northwindcoffee.test';
		$final        = 'https://final.northwindcoffee.test';

		$older_id = $manager->create_apply( $this->replacement_config( $original, $intermediate ) );
		$this->finish( $runner, $manager, $older_id );
		$this->assertSame( $intermediate, $this->stored_siteurl() );
		$newer_id = $manager->create_apply( $this->replacement_config( $intermediate, $final ) );
		$this->finish( $runner, $manager, $newer_id );
		$this->assertSame( $final, $this->stored_siteurl() );
		$wpdb->update(
			SafeSR\Db\Schema::jobs_table_name(),
			array( 'created_at' => '2026-07-14 10:00:00' ),
			array( 'id' => $older_id )
		);
		$wpdb->update(
			SafeSR\Db\Schema::jobs_table_name(),
			array( 'created_at' => '2026-07-14 11:00:00' ),
			array( 'id' => $newer_id )
		);

		$list = $this->jobs_request( array() )->get_data();
		$this->assertSame( array( $newer_id, $older_id ), wp_list_pluck( $list['items'], 'id' ) );
		$this->assertTrue( $list['items'][0]['undoable'] );
		$this->assertFalse( $list['items'][1]['undoable'] );
		$this->assertSame( $newer_id, $manager->latest_undoable_apply_id() );

		try {
			$manager->create_undo( $older_id );
			$this->fail( 'An older apply must not be undone before the newer apply.' );
		} catch ( InvalidArgumentException $error ) {
			$this->assertSame( 'A more recent run must be undone first.', $error->getMessage() );
		}

		$newer_undo_id = $manager->create_undo( $newer_id );
		$this->finish( $runner, $manager, $newer_undo_id );
		$this->assertSame( $intermediate, $this->stored_siteurl() );

		$list = $this->jobs_request( array() )->get_data();
		$this->assertFalse( $list['items'][0]['undoable'] );
		$this->assertTrue( $list['items'][0]['undone'] );
		$this->assertTrue( $list['items'][1]['undoable'] );
		$this->assertSame( $older_id, $manager->latest_undoable_apply_id() );

		$older_undo_id = $manager->create_undo( $older_id );
		$this->finish( $runner, $manager, $older_undo_id );
		$this->assertSame( $original, $this->stored_siteurl() );
	}

	/**
	 * A partial snapshot is not advertised or accepted as restorable.
	 *
	 * @return void
	 */
	public function test_partial_backup_is_not_undoable() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		new SafeSR_Northwind_Fixture( self::factory() );
		$manager  = new Job_Manager();
		$runner   = new Batch_Runner( $manager );
		$apply_id = $manager->create_apply( $this->config( false, 2 ) );
		$this->finish( $runner, $manager, $apply_id );
		$apply = $manager->get_job( $apply_id );

		$this->assertGreaterThan( 1, $apply['summary']['backup_count'] );
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE backup_id = %s ORDER BY id ASC LIMIT 1',
				SafeSR\Db\Schema::backup_rows_table_name(),
				$apply['backup_id']
			)
		);

		$item = $this->jobs_request( array() )->get_data()['items'][0];
		$this->assertFalse( $item['has_backup'] );
		$this->assertFalse( $item['undoable'] );
		try {
			$manager->create_undo( $apply_id );
			$this->fail( 'A partial backup must not create an undo job.' );
		} catch ( InvalidArgumentException $error ) {
			$this->assertSame( 'This job does not have a backup to restore.', $error->getMessage() );
		}
	}

	/**
	 * Failed applies are omitted from applied run history.
	 *
	 * @return void
	 */
	public function test_apply_history_omits_failed_jobs() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$manager   = new Job_Manager();
		$failed_id = $manager->create_apply( $this->config( false, 2 ) );
		$manager->fail( $failed_id, 'Expected test failure.' );

		$list = $this->jobs_request( array( 'type' => 'apply' ) )->get_data();
		$this->assertSame( 0, $list['total'] );
		$this->assertSame( array(), $list['items'] );
	}

	/**
	 * Reads the siteurl option straight from the database.
	 *
	 * The test configuration pins WP_SITEURL, so get_option() cannot observe
	 * the row a replace writes.
	 *
	 * @return string
	 */
	private function stored_siteurl(): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'siteurl' )
		);
	}

	/**
	 * Preview returns engine validation messages as bad requests.
	 *
	 * @return void
	 */
	public function test_preview_rejects_empty_search_and_invalid_regex() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$empty = new WP_REST_Request( 'POST', '/safesr/v1/preview' );
		$empty->set_body_params(
			array(
				'search'  => '',
				'replace' => '',
				'tables'  => array(),
			)
		);
		$this->assertSame( 400, $this->server->dispatch( $empty )->get_status() );

		$regex = new WP_REST_Request( 'POST', '/safesr/v1/preview' );
		$regex->set_body_params(
			array(
				'search'  => '[',
				'replace' => '',
				'regex'   => true,
				'tables'  => array(),
			)
		);
		$response = $this->server->dispatch( $regex );
		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'regular expression', $response->get_data()['message'] );
	}

	/**
	 * Apply rejects a preview owned by another administrator.
	 *
	 * @return void
	 */
	public function test_apply_rejects_preview_owned_by_another_user() {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $owner );
		$manager    = new Job_Manager();
		$runner     = new Batch_Runner( $manager );
		$preview_id = $manager->create_preview( $this->config( true, 2 ) );
		$this->finish( $runner, $manager, $preview_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = $this->apply_request( $preview_id );
		$this->assertSame( 400, $this->server->dispatch( $request )->get_status() );
	}

	/**
	 * Apply rejects a preview produced from different replacement settings.
	 *
	 * @return void
	 */
	public function test_apply_rejects_preview_with_different_config_hash() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$manager    = new Job_Manager();
		$runner     = new Batch_Runner( $manager );
		$preview_id = $manager->create_preview( $this->config( true, 2 ) );
		$this->finish( $runner, $manager, $preview_id );

		$request = $this->apply_request( $preview_id, array( 'replace' => 'https://different.example' ) );
		$this->assertSame( 400, $this->server->dispatch( $request )->get_status() );
	}

	/**
	 * Reading a queued job never advances background work.
	 *
	 * @return void
	 */
	public function test_get_job_is_read_only() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$manager = new Job_Manager();
		$job_id  = $manager->create_preview( $this->config( true, 2 ) );
		$before  = $manager->get_job( $job_id );
		$request = new WP_REST_Request( 'GET', '/safesr/v1/jobs/' . $job_id );

		$response = $this->server->dispatch( $request );
		$after    = $manager->get_job( $job_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $before['status'], $after['status'] );
		$this->assertSame( $before['progress'], $after['progress'] );
	}

	/**
	 * A request for tables outside the current site is refused.
	 *
	 * @return void
	 */
	public function test_preview_rejects_tables_outside_the_scan_set() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'POST', '/safesr/v1/preview' );
		$request->set_body_params(
			array(
				'search'  => 'http://staging.northwindcoffee.com',
				'replace' => 'https://northwindcoffee.com',
				'tables'  => array( 'wp_9_posts', 'not_a_real_table' ),
			)
		);

		$response = $this->server->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'tables', $response->get_data()['message'] );
	}

	/**
	 * A backslash in the search reaches the engine intact for regex.
	 *
	 * @return void
	 */
	public function test_backslash_search_survives_sanitization() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$controller = new SafeSR\Rest\Rest_Controller( new Job_Manager() );

		$this->assertSame( '\d+', $controller->sanitize_value( '\d+' ) );
		$this->assertSame( 'C:\temp', $controller->sanitize_value( 'C:\temp' ) );
	}

	/**
	 * Change pages expose totals and honor table, column, and excerpt filters.
	 *
	 * @return void
	 */
	public function test_changes_support_pagination_and_table_and_text_filters() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		new SafeSR_Northwind_Fixture( self::factory() );
		$manager = new Job_Manager();
		$runner  = new Batch_Runner( $manager );
		$job_id  = $manager->create_preview( $this->config( true, 2 ) );
		$this->finish( $runner, $manager, $job_id );

		$first  = $this->changes_request(
			$job_id,
			array(
				'page'     => 1,
				'per_page' => 2,
			)
		);
		$second = $this->changes_request(
			$job_id,
			array(
				'page'     => 2,
				'per_page' => 2,
			)
		);
		$this->assertSame( 200, $first->get_status() );
		$this->assertCount( 2, $first->get_data() );
		$this->assertCount( 2, $second->get_data() );
		$this->assertNotSame( wp_list_pluck( $first->get_data(), 'id' ), wp_list_pluck( $second->get_data(), 'id' ) );
		$this->assertGreaterThan( 2, (int) $first->get_headers()['X-WP-Total'] );
		$this->assertSame(
			(string) (int) ceil( (int) $first->get_headers()['X-WP-Total'] / 2 ),
			$first->get_headers()['X-WP-TotalPages']
		);

		$table = $this->changes_request(
			$job_id,
			array(
				'table'    => $wpdb->postmeta,
				'per_page' => 100,
			)
		);
		$this->assertNotEmpty( $table->get_data() );
		$this->assertSame( array( $wpdb->postmeta ), array_values( array_unique( wp_list_pluck( $table->get_data(), 'table_name' ) ) ) );

		$column = $this->changes_request(
			$job_id,
			array(
				'text'     => 'meta_value',
				'per_page' => 100,
			)
		);
		$this->assertNotEmpty( $column->get_data() );
		$this->assertSame( array( 'meta_value' ), array_values( array_unique( wp_list_pluck( $column->get_data(), 'column_name' ) ) ) );

		$excerpt = $this->changes_request(
			$job_id,
			array(
				'text'     => 'hero.jpg',
				'per_page' => 100,
			)
		);
		$this->assertNotEmpty( $excerpt->get_data() );
		foreach ( $excerpt->get_data() as $change ) {
			$before = implode( '', wp_list_pluck( $change['before_excerpt'], 't' ) );
			$after  = implode( '', wp_list_pluck( $change['after_excerpt'], 't' ) );
			$this->assertStringContainsString( 'hero.jpg', $before . $after );
		}
	}

	/**
	 * Completed apply logs are served as matching CSV attachments.
	 *
	 * @return void
	 */
	public function test_log_streams_csv_with_attachment_headers_and_matching_content() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		new SafeSR_Northwind_Fixture( self::factory() );
		update_option( 'safesr_keep_logs', 1, false );
		$manager = new Job_Manager();
		$runner  = new Batch_Runner( $manager );
		$job_id  = $manager->create_apply( $this->config( false, 2 ) );
		$this->finish( $runner, $manager, $job_id );

		$request  = new WP_REST_Request( 'GET', '/safesr/v1/jobs/' . $job_id . '/log' );
		$response = $this->server->dispatch( $request );
		$headers  = $response->get_headers();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'text/csv; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'attachment; filename="database-search-replace-' . $job_id . '.csv"', $headers['Content-Disposition'] );
		$this->assertStringContainsString( 'job_id,table,column,pk,before_excerpt,after_excerpt,formats,skipped,reason', $response->get_data() );
		$this->assertStringContainsString( $job_id, $response->get_data() );
		$this->assertStringContainsString( SafeSR_Northwind_Fixture::OLD_URL, $response->get_data() );
		$this->assertStringContainsString( SafeSR_Northwind_Fixture::NEW_URL, $response->get_data() );

		ob_start();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The test exercises WordPress's REST serving boundary.
		$served = apply_filters( 'rest_pre_serve_request', false, $response, $request, $this->server );
		$output = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( $response->get_data(), $output );
	}

	/**
	 * Saved settings retain mandatory user table protection.
	 *
	 * @return void
	 */
	public function test_settings_roundtrip_cannot_remove_user_table_protection() {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'POST', '/safesr/v1/settings' );
		$request->set_body_params(
			array(
				'protected_tables' => array(),
				'batch_size'       => 250,
				'keep_logs'        => false,
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $wpdb->users, $response->get_data()['protected_tables'], true );
		$this->assertContains( $wpdb->usermeta, $response->get_data()['protected_tables'], true );
		$this->assertSame( 250, $response->get_data()['batch_size'] );
		$this->assertFalse( $response->get_data()['keep_logs'] );
	}

	/**
	 * Builds the standard fixture configuration.
	 *
	 * @param bool $dry_run    Whether writes are disabled.
	 * @param int  $batch_size Rows per batch.
	 * @return Run_Config
	 */
	private function config( bool $dry_run, int $batch_size ): Run_Config {
		global $wpdb;
		return new Run_Config(
			SafeSR_Northwind_Fixture::search_config(),
			array( $wpdb->options, $wpdb->posts, $wpdb->postmeta ),
			false,
			false,
			$batch_size,
			$dry_run
		);
	}

	/**
	 * Builds a single-table configuration for a specific replacement step.
	 *
	 * @param string $search  Search value.
	 * @param string $replace Replacement value.
	 * @return Run_Config
	 */
	private function replacement_config( string $search, string $replace ): Run_Config {
		global $wpdb;
		return new Run_Config(
			new SafeSR\Engine\Search_Config( $search, $replace ),
			array( $wpdb->options ),
			false,
			false,
			2,
			false
		);
	}

	/**
	 * Dispatches a change-list request with query parameters.
	 *
	 * @param string              $job_id Job identifier.
	 * @param array<string,mixed> $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function changes_request( string $job_id, array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/safesr/v1/jobs/' . $job_id . '/changes' );
		$request->set_query_params( $params );
		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatches a job-list request with query parameters.
	 *
	 * @param array<string,mixed> $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function jobs_request( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/safesr/v1/jobs' );
		$request->set_query_params( $params );
		return $this->server->dispatch( $request );
	}

	/**
	 * Builds a REST apply request linked to a preview.
	 *
	 * @param string              $preview_id Preview identifier.
	 * @param array<string,mixed> $overrides Request overrides.
	 * @return WP_REST_Request
	 */
	private function apply_request( string $preview_id, array $overrides = array() ): WP_REST_Request {
		global $wpdb;
		$request = new WP_REST_Request( 'POST', '/safesr/v1/apply' );
		$request->set_body_params(
			array_merge(
				array(
					'search'         => SafeSR_Northwind_Fixture::OLD_URL,
					'replace'        => SafeSR_Northwind_Fixture::NEW_URL,
					'tables'         => array( $wpdb->options, $wpdb->posts, $wpdb->postmeta ),
					'preview_job_id' => $preview_id,
				),
				$overrides
			)
		);
		return $request;
	}

	/**
	 * Runs direct chunks until a job reaches a terminal state.
	 *
	 * @param Batch_Runner $runner  Batch runner.
	 * @param Job_Manager  $manager Job storage.
	 * @param string       $job_id  Job identifier.
	 * @return void
	 */
	private function finish( Batch_Runner $runner, Job_Manager $manager, string $job_id ): void {
		do {
			$runner->run_chunk( $job_id, 0 );
			$job = $manager->get_job( $job_id );
		} while ( ! in_array( $job['status'], array( 'completed', 'failed', 'canceled' ), true ) );
		$this->assertSame( 'completed', $job['status'] );
	}
}
