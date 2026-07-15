<?php
/**
 * Cache invalidation after database writes.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Integrations;

use SafeSR\Jobs\Job_Manager;

/**
 * Flushes WordPress and supported plugin caches after apply and undo jobs.
 */
class Cache_Purge {

	/**
	 * Persistent job service.
	 *
	 * @var Job_Manager
	 */
	private Job_Manager $manager;

	/**
	 * Supports an injected job service for tests.
	 *
	 * @param Job_Manager|null $manager Optional job service.
	 */
	public function __construct( ?Job_Manager $manager = null ) {
		$this->manager = $manager ?? new Job_Manager();
	}

	/**
	 * Registers completion hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'safesr_replace_completed', array( $this, 'purge' ) );
		add_action( 'safesr_undo_completed', array( $this, 'purge' ) );
	}

	/**
	 * Runs available cache handlers and records successful dispatches.
	 *
	 * @param array<string,mixed>|null $job Completed job record.
	 * @return void
	 */
	public function purge( ?array $job ): void {
		if ( null === $job || empty( $job['id'] ) ) {
			return;
		}

		$handlers = array(
			'object_cache'   => array( $this, 'purge_object_cache' ),
			'elementor'      => array( $this, 'purge_elementor' ),
			'wp_rocket'      => array( $this, 'purge_wp_rocket' ),
			'litespeed'      => array( $this, 'purge_litespeed' ),
			'w3_total_cache' => array( $this, 'purge_w3_total_cache' ),
		);
		/**
		 * Filters cache handlers run after database changes complete.
		 *
		 * A handler returns false when its cache is unavailable. Any other return value records
		 * the handler as dispatched.
		 *
		 * @param array<string,callable> $handlers Cache handlers keyed by display identifier.
		 * @param array<string,mixed>    $job      Completed job record.
		 */
		/**
		 * Filter output is unconstrained, so entries are re-validated below.
		 *
		 * @var array<int|string,mixed> $handlers
		 */
		$handlers = apply_filters( 'safesr_cache_purge_handlers', $handlers, $job );
		$ran      = array();
		foreach ( $handlers as $name => $handler ) {
			if ( ! is_string( $name ) || ! is_callable( $handler ) ) {
				continue;
			}
			try {
				if ( false !== call_user_func( $handler, $job ) ) {
					$ran[] = $name;
				}
			} catch ( \Throwable $error ) {
				unset( $error );
			}
		}

		$current = $this->manager->get_job( (string) $job['id'] );
		if ( null !== $current ) {
			$current['summary']['cache_purge_handlers'] = $ran;
			$this->manager->update_summary( (string) $job['id'], $current['summary'] );
		}
	}

	/**
	 * Flushes the WordPress object cache after direct database writes.
	 *
	 * @return true
	 */
	public function purge_object_cache(): bool {
		wp_cache_flush();
		return true;
	}

	/**
	 * Clears Elementor's generated CSS files when Elementor is loaded.
	 *
	 * @return bool Whether the handler was dispatched.
	 */
	public function purge_elementor(): bool {
		if ( ! class_exists( '\\Elementor\\Plugin' ) || ! isset( \Elementor\Plugin::$instance->files_manager ) ) {
			return false;
		}
		\Elementor\Plugin::$instance->files_manager->clear_cache();
		return true;
	}

	/**
	 * Clears WP Rocket's domain cache when its API is loaded.
	 *
	 * @return bool Whether the handler was dispatched.
	 */
	public function purge_wp_rocket(): bool {
		if ( ! function_exists( 'rocket_clean_domain' ) ) {
			return false;
		}
		rocket_clean_domain();
		return true;
	}

	/**
	 * Dispatches LiteSpeed Cache's full-purge action when the plugin is loaded.
	 *
	 * @return bool Whether the handler was dispatched.
	 */
	public function purge_litespeed(): bool {
		if ( ! defined( 'LSCWP_V' ) && ! class_exists( '\\LiteSpeed_Cache' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed hook.
		do_action( 'litespeed_purge_all' );
		return true;
	}

	/**
	 * Clears W3 Total Cache when its API is loaded.
	 *
	 * @return bool Whether the handler was dispatched.
	 */
	public function purge_w3_total_cache(): bool {
		if ( ! function_exists( 'w3tc_flush_all' ) ) {
			return false;
		}
		w3tc_flush_all();
		return true;
	}
}
