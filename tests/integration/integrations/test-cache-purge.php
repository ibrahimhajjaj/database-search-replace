<?php
/**
 * Integration tests for post-replacement cache purging.
 *
 * @package SafeSearchReplace
 */

require_once __DIR__ . '/cache-purge-stubs.php';

use SafeSR\Db\Run_Config;
use SafeSR\Engine\Search_Config;
use SafeSR\Integrations\Cache_Purge;
use SafeSR\Jobs\Job_Manager;

/**
 * Verifies cache integrations are isolated and recorded.
 */
class SafeSR_Cache_Purge_Test extends SafeSR_Integration_Test_Case {

	/**
	 * Job service used by each test.
	 *
	 * @var Job_Manager
	 */
	private Job_Manager $manager;

	/**
	 * Resets cache counters before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->manager                           = new Job_Manager();
		$GLOBALS['safesr_test_elementor_purges'] = 0;
		$GLOBALS['safesr_test_rocket_purges']    = 0;
		$GLOBALS['safesr_test_w3tc_purges']      = 0;
	}

	/**
	 * Removes hooks and active-job state between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'safesr_cache_purge_handlers' );
		remove_all_actions( 'litespeed_purge_all' );
		delete_option( 'safesr_active_job' );
		parent::tear_down();
	}

	/**
	 * Available cache plugins are dispatched and saved in the job summary.
	 *
	 * @return void
	 */
	public function test_available_purgers_are_dispatched_and_recorded() {
		$litespeed_purges = 0;
		add_action(
			'litespeed_purge_all',
			static function () use ( &$litespeed_purges ) {
				++$litespeed_purges;
			}
		);

		$job_id = $this->create_job();
		( new Cache_Purge( $this->manager ) )->purge( $this->manager->get_job( $job_id ) );
		$job = $this->manager->get_job( $job_id );

		$this->assertSame( 1, $GLOBALS['safesr_test_elementor_purges'] );
		$this->assertSame( 1, $GLOBALS['safesr_test_rocket_purges'] );
		$this->assertSame( 1, $litespeed_purges );
		$this->assertSame( 1, $GLOBALS['safesr_test_w3tc_purges'] );
		$this->assertSame(
			array( 'object_cache', 'elementor', 'wp_rocket', 'litespeed', 'w3_total_cache' ),
			$job['summary']['cache_purge_handlers']
		);
	}

	/**
	 * A throwing extension handler does not prevent later handlers or summary persistence.
	 *
	 * @return void
	 */
	public function test_throwing_handler_does_not_stop_cache_purge() {
		$after_ran = false;
		add_filter(
			'safesr_cache_purge_handlers',
			static function ( array $handlers ) use ( &$after_ran ): array {
				$handlers['throwing'] = static function (): void {
					throw new RuntimeException( 'Cache backend unavailable.' );
				};
				$handlers['after']    = static function () use ( &$after_ran ): void {
					$after_ran = true;
				};
				return $handlers;
			}
		);

		$job_id = $this->create_job();
		( new Cache_Purge( $this->manager ) )->purge( $this->manager->get_job( $job_id ) );
		$job = $this->manager->get_job( $job_id );

		$this->assertTrue( $after_ran );
		$this->assertNotContains( 'throwing', $job['summary']['cache_purge_handlers'] );
		$this->assertContains( 'after', $job['summary']['cache_purge_handlers'] );
	}

	/**
	 * Creates a durable job whose summary can receive cache results.
	 *
	 * @return string
	 */
	private function create_job(): string {
		global $wpdb;
		$config = new Run_Config( new Search_Config( 'old', 'new' ), array( $wpdb->options ) );
		return $this->manager->create_preview( $config );
	}
}
