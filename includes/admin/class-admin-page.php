<?php
/**
 * Registers the plugin's Tools screen.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Admin;

/**
 * Admin screen controller.
 */
final class Admin_Page {

	/**
	 * WordPress hook suffix for the registered screen.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the plugin screen beneath Tools.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$hook_suffix = add_management_page(
			__( 'Database Search & Replace', 'database-search-replace' ),
			__( 'Database Search & Replace', 'database-search-replace' ),
			'manage_options',
			'database-search-replace',
			array( $this, 'render' )
		);

		if ( false !== $hook_suffix ) {
			$this->hook_suffix = $hook_suffix;
		}
	}

	/**
	 * Renders the application mount point.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'database-search-replace' ) );
		}
		?>
		<div id="safesr-root"></div>
		<noscript><p><?php echo esc_html__( 'Database Search & Replace requires JavaScript to use this screen.', 'database-search-replace' ); ?></p></noscript>
		<?php
	}

	/**
	 * Enqueues compiled assets on the plugin screen.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$plugin_dir  = (string) constant( 'SAFESR_PLUGIN_DIR' );
		$plugin_url  = (string) constant( 'SAFESR_PLUGIN_URL' );
		$script_path = $plugin_dir . 'build/index.js';
		$style_path  = $plugin_dir . 'build/style-index.css';
		$asset_path  = $plugin_dir . 'build/index.asset.php';

		if ( $this->hook_suffix !== $hook_suffix || ! file_exists( $script_path ) || ! file_exists( $asset_path ) ) {
			return;
		}

		$asset = require $asset_path;

		if ( ! is_array( $asset ) || ! isset( $asset['dependencies'], $asset['version'] ) || ! is_array( $asset['dependencies'] ) ) {
			return;
		}

		wp_enqueue_script(
			'safesr-admin',
			$plugin_url . 'build/index.js',
			$asset['dependencies'],
			(string) $asset['version'],
			true
		);
		wp_set_script_translations( 'safesr-admin', 'database-search-replace' );
		wp_add_inline_script(
			'safesr-admin',
			'window.safesrAdmin = ' . wp_json_encode(
				array(
					'version'         => (string) constant( 'SAFESR_VERSION' ),
					'imagesUrl'       => (string) constant( 'SAFESR_PLUGIN_URL' ) . 'assets/img/',
					'logUrlBase'      => trailingslashit( rest_url( 'safesr/v1/jobs' ) ),
					'restNonce'       => wp_create_nonce( 'wp_rest' ),
					'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
					'reviewNonce'     => wp_create_nonce( 'closedpostboxes' ),
					'reviewDismissed' => in_array( 'safesr-review-nudge', (array) get_user_meta( get_current_user_id(), 'closedpostboxes_safesr', true ), true ),
					'retentionDays'   => (int) constant( 'SAFESR_BACKUP_RETENTION_DAYS' ),
					'proUrl'          => 'https://safeguard.verdelic.com/migrate?utm_source=ssr-plugin&utm_medium=pro-tools',
					'reviewUrl'       => 'https://wordpress.org/support/plugin/database-search-replace/reviews/#new-post',
				)
			) . ';',
			'before'
		);

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'safesr-admin',
				$plugin_url . 'build/style-index.css',
				array(),
				(string) $asset['version']
			);
		}
	}
}
