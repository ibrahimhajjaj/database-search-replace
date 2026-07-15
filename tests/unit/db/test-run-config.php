<?php
/**
 * Unit tests for database run configuration.
 *
 * @package SafeSearchReplace
 */

use PHPUnit\Framework\TestCase;
use SafeSR\Db\Run_Config;
use SafeSR\Engine\Search_Config;

/**
 * Verifies database run configuration values and validation.
 */
class SafeSR_Run_Config_Test extends TestCase {

	/**
	 * Explicit run settings are retained.
	 *
	 * @return void
	 */
	public function test_explicit_settings_are_retained() {
		$search = new Search_Config( 'old', 'new' );
		$config = new Run_Config( $search, array( 'wp_posts' ), true, true, 25, false );

		$this->assertSame( $search, $config->get_search_config() );
		$this->assertSame( array( 'wp_posts' ), $config->get_tables() );
		$this->assertTrue( $config->should_include_guids() );
		$this->assertTrue( $config->is_thorough_scan() );
		$this->assertSame( 25, $config->get_batch_size() );
		$this->assertFalse( $config->is_dry_run() );
	}

	/**
	 * Auto batch size resolves without a WordPress runtime.
	 *
	 * @return void
	 */
	public function test_auto_batch_size_resolves_to_safe_default() {
		$config = new Run_Config( new Search_Config( 'old' ), array( 'wp_posts' ) );

		$this->assertSame( 500, $config->get_batch_size() );
		$this->assertFalse( $config->should_include_guids() );
		$this->assertFalse( $config->is_thorough_scan() );
		$this->assertTrue( $config->is_dry_run() );
	}

	/**
	 * Nonpositive batch sizes are rejected.
	 *
	 * @return void
	 */
	public function test_nonpositive_batch_size_is_rejected() {
		$this->expectException( InvalidArgumentException::class );

		new Run_Config( new Search_Config( 'old' ), array( 'wp_posts' ), false, false, 0 );
	}
}
