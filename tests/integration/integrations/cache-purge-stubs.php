<?php
/**
 * Cache plugin stubs for integration tests.
 *
 * @package SafeSearchReplace
 */

namespace {
	if ( ! function_exists( 'rocket_clean_domain' ) ) {
		/**
		 * Records WP Rocket cache purges.
		 *
		 * @return void
		 */
		function rocket_clean_domain() {
			$GLOBALS['safesr_test_rocket_purges'] = (int) ( $GLOBALS['safesr_test_rocket_purges'] ?? 0 ) + 1;
		}
	}

	if ( ! function_exists( 'w3tc_flush_all' ) ) {
		/**
		 * Records W3 Total Cache purges.
		 *
		 * @return void
		 */
		function w3tc_flush_all() {
			$GLOBALS['safesr_test_w3tc_purges'] = (int) ( $GLOBALS['safesr_test_w3tc_purges'] ?? 0 ) + 1;
		}
	}

	if ( ! class_exists( 'LiteSpeed_Cache' ) ) {
		/**
		 * Marks LiteSpeed Cache as available.
		 */
		class LiteSpeed_Cache {
		}
	}
}

namespace Elementor {
	if ( ! class_exists( 'Elementor\\Plugin' ) ) {
		/**
		 * Elementor plugin stub.
		 */
		class Plugin {

			/**
			 * Shared instance.
			 *
			 * @var Plugin
			 */
			public static $instance;

			/**
			 * CSS files manager.
			 *
			 * @var object
			 */
			public $files_manager;

			/**
			 * Creates the shared test instance.
			 */
			public function __construct() {
				$this->files_manager = new class() {
					/**
					 * Records cache clears.
					 *
					 * @return void
					 */
					public function clear_cache() {
						$GLOBALS['safesr_test_elementor_purges'] = (int) ( $GLOBALS['safesr_test_elementor_purges'] ?? 0 ) + 1;
					}
				};
			}
		}

		Plugin::$instance = new Plugin();
	}
}
