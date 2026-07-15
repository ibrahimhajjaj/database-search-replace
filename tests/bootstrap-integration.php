<?php
/**
 * PHPUnit bootstrap for tests that load WordPress.
 *
 * @package SafeSearchReplace
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- wp-phpunit requires this constant before bootstrap.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

$safesr_tests_dir = getenv( 'WP_PHPUNIT__DIR' );

if ( ! $safesr_tests_dir ) {
	$safesr_tests_dir = '/wordpress-phpunit';
}

require_once $safesr_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin before WordPress finishes bootstrapping.
 *
 * @return void
 */
function safesr_load_plugin_for_tests() {
	require dirname( __DIR__ ) . '/database-search-replace.php';
}

tests_add_filter( 'muplugins_loaded', 'safesr_load_plugin_for_tests' );

require $safesr_tests_dir . '/includes/bootstrap.php';

// Schema DDL must finish before WP_UnitTestCase starts per-test transactions.
SafeSR\Db\Schema::install();

require_once __DIR__ . '/integration/class-integration-test-case.php';
