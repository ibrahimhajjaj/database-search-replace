<?php
/**
 * Unit tests for the plugin shell.
 *
 * @package SafeSearchReplace
 */

use PHPUnit\Framework\TestCase;
use SafeSR\Admin\Admin_Page;
use SafeSR\Plugin;

/**
 * Verifies that the plugin shell can be loaded without WordPress.
 */
class SafeSR_Plugin_Shell_Test extends TestCase {

	/**
	 * The plugin header keeps its own homepage and identifies the author site.
	 *
	 * @return void
	 */
	public function test_plugin_header_uses_the_expected_homepages() {
		$this->assertFileExists( dirname( __DIR__, 2 ) . '/database-search-replace.php' );

		// Plugin header fields are metadata, so this assertion must inspect the source file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$plugin = file_get_contents( dirname( __DIR__, 2 ) . '/database-search-replace.php' );

		$this->assertIsString( $plugin );
		$this->assertStringContainsString( 'Plugin Name: Database Search & Replace', $plugin );
		$this->assertStringContainsString( 'Text Domain: database-search-replace', $plugin );
		$this->assertStringContainsString( 'Plugin URI: https://wordpress.org/plugins/database-search-replace/', $plugin );
		$this->assertStringContainsString( 'Author URI: https://safeguard.verdelic.com', $plugin );
	}

	/**
	 * The plugins-list funnel is exposed by the main plugin controller.
	 *
	 * @return void
	 */
	public function test_plugin_controller_exposes_plugins_list_funnel_link() {
		if ( ! method_exists( Plugin::class, 'add_plugin_action_links' ) ) {
			$this->fail( 'The plugin action-link callback is not registered.' );
		}

		// Hook registration and fixed link markup are verified without bootstrapping WordPress.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$plugin = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-plugin.php' );

		$this->assertIsString( $plugin );
		$this->assertStringContainsString( 'plugin_action_links_', $plugin );
		$this->assertStringContainsString( 'https://safeguard.verdelic.com/migrate?utm_source=ssr-plugin&utm_medium=plugins-list', $plugin );
		$this->assertStringContainsString( 'Migrate a whole site', $plugin );
		$this->assertStringNotContainsString( 'Upgrade to Pro', $plugin );
		$this->assertStringNotContainsString( 'Go Pro', $plugin );
	}

	/**
	 * The classmap resolves the main plugin class.
	 *
	 * @return void
	 */
	public function test_classmap_resolves_plugin_class() {
		$this->assertTrue( class_exists( Plugin::class ) );
	}

	/**
	 * The classmap resolves the admin page class.
	 *
	 * @return void
	 */
	public function test_classmap_resolves_admin_page_class() {
		$this->assertTrue( class_exists( Admin_Page::class ) );
	}

	/**
	 * The uninstall list contains plugin-owned option names.
	 *
	 * @return void
	 */
	public function test_uninstall_list_contains_prefixed_option_names() {
		define( 'WP_UNINSTALL_PLUGIN', true );

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertNotEmpty( SAFESR_UNINSTALL_OPTIONS );

		foreach ( SAFESR_UNINSTALL_OPTIONS as $option_name ) {
			$this->assertStringStartsWith( 'safesr_', $option_name );
		}
	}
}
