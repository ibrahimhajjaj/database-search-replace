<?php
/**
 * Removes plugin-owned data when uninstalling.
 *
 * @package SafeSearchReplace
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Option names removed during uninstall.
 *
 * Later data stores must add their option names to this list.
 */
const SAFESR_UNINSTALL_OPTIONS = array(
	'safesr_version',
	'safesr_batch_size',
	'safesr_protected_tables',
	'safesr_keep_logs',
	'safesr_active_job',
);

$safesr_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $safesr_autoloader ) && function_exists( 'is_multisite' ) ) {
	require_once $safesr_autoloader;

	$safesr_site_ids = is_multisite()
		? get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		)
		: array( get_current_blog_id() );

	foreach ( $safesr_site_ids as $safesr_site_id ) {
		if ( is_multisite() ) {
			switch_to_blog( (int) $safesr_site_id );
		}
		foreach ( SAFESR_UNINSTALL_OPTIONS as $safesr_option_name ) {
			delete_option( $safesr_option_name );
		}
		SafeSR\Db\Schema::uninstall();
		if ( is_multisite() ) {
			restore_current_blog();
		}
	}
}
