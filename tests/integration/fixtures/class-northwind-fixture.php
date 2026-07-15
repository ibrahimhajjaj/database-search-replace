<?php
/**
 * Northwind database fixture for search and replace integration tests.
 *
 * @package SafeSearchReplace
 */

/**
 * Seeds representative WordPress values and storage formats.
 */
class SafeSR_Northwind_Fixture {

	public const OLD_URL = 'http://staging.northwindcoffee.com';

	public const NEW_URL = 'https://northwindcoffee.com';

	/**
	 * Seeded post identifier.
	 *
	 * @var int
	 */
	public int $post_id;

	/**
	 * Seeded user identifier.
	 *
	 * @var int
	 */
	public int $user_id;

	/**
	 * Seeds the fixture and retains generated identifiers.
	 *
	 * @param WP_UnitTest_Factory $factory WordPress test factory.
	 */
	public function __construct( WP_UnitTest_Factory $factory ) {
		update_option( 'siteurl', self::OLD_URL );
		update_option( 'home', self::OLD_URL );
		update_option( 'theme_mods_northwind', array( 'logo' => self::OLD_URL . '/logo.png' ) );

		$this->post_id = $factory->post->create(
			array(
				'post_title'   => 'Northwind Roastery',
				'post_content' => '<img src="' . self::OLD_URL . '/wp-content/uploads/hero.jpg">',
				'guid'         => self::OLD_URL . '/?p=100',
			)
		);

		update_post_meta( $this->post_id, '_elementor_data', wp_slash( '{"url":"http:\/\/staging.northwindcoffee.com\/shop"}' ) );
		update_post_meta( $this->post_id, '_wp_attached_file', self::OLD_URL . '/wp-content/uploads/hero.jpg' );

		$this->user_id = $factory->user->create(
			array(
				'user_url' => self::OLD_URL . '/owner',
			)
		);
	}

	/**
	 * Returns the engine configuration shared by fixture tests.
	 *
	 * @param bool $regex Whether the search value is a regular expression.
	 * @return SafeSR\Engine\Search_Config
	 */
	public static function search_config( bool $regex = false ): SafeSR\Engine\Search_Config {
		$search = $regex ? 'http://staging\.northwindcoffee\.com' : self::OLD_URL;

		return new SafeSR\Engine\Search_Config( $search, self::NEW_URL, true, $regex );
	}
}
