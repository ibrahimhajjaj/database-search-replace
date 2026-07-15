<?php
/**
 * Plugin Name: Database Search & Replace
 * Plugin URI: https://wordpress.org/plugins/database-search-replace/
 * Description: Preview database replacements, back them up, and undo them.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Ibrahim Hajjaj
 * Author URI: https://safeguard.verdelic.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: database-search-replace
 *
 * @package SafeSearchReplace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SAFESR_VERSION', '1.0.0' );
define( 'SAFESR_PLUGIN_FILE', __FILE__ );
define( 'SAFESR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAFESR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SAFESR_MIN_PHP', '7.4' );
define( 'SAFESR_BACKUP_RETENTION_DAYS', 30 );

if ( version_compare( PHP_VERSION, SAFESR_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			?>
			<div class="notice notice-error"><p><?php echo esc_html__( 'Database Search & Replace requires PHP 7.4 or newer.', 'database-search-replace' ); ?></p></div>
			<?php
		}
	);

	return;
}

$safesr_autoloader = SAFESR_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! file_exists( $safesr_autoloader ) ) {
	add_action(
		'admin_notices',
		static function () {
			?>
			<div class="notice notice-error"><p><?php echo esc_html__( 'Database Search & Replace could not load its required files. Reinstall the plugin and try again.', 'database-search-replace' ); ?></p></div>
			<?php
		}
	);

	return;
}

require_once $safesr_autoloader;

$safesr_action_scheduler = SAFESR_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( file_exists( $safesr_action_scheduler ) ) {
	require_once $safesr_action_scheduler;
}

register_activation_hook( SAFESR_PLUGIN_FILE, array( 'SafeSR\Plugin', 'activate' ) );
register_deactivation_hook( SAFESR_PLUGIN_FILE, array( 'SafeSR\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		SafeSR\Plugin::instance()->init();
	}
);
