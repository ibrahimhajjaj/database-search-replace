<?php
/**
 * PHPUnit bootstrap for tests that do not load WordPress.
 *
 * @package SafeSearchReplace
 */

$safesr_test_autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';

require_once $safesr_test_autoloader;
