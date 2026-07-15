<?php
/**
 * Unit tests for search configuration.
 *
 * @package SafeSearchReplace
 */

use PHPUnit\Framework\TestCase;
use SafeSR\Engine\Search_Config;

/**
 * Verifies search configuration validation and defaults.
 */
class SafeSR_Search_Config_Test extends TestCase {

	/**
	 * Empty search text is rejected.
	 *
	 * @return void
	 */
	public function test_empty_search_is_rejected() {
		$this->expectException( InvalidArgumentException::class );

		new Search_Config( '' );
	}

	/**
	 * Defaults favor case-insensitive literal replacement.
	 *
	 * @return void
	 */
	public function test_defaults_favor_case_insensitive_literal_replacement() {
		$config = new Search_Config( 'old', 'new' );

		$this->assertSame( 'old', $config->get_search() );
		$this->assertSame( 'new', $config->get_replace() );
		$this->assertFalse( $config->is_case_sensitive() );
		$this->assertFalse( $config->is_regex() );
		$this->assertSame( array(), $config->get_exclusions() );
		$this->assertSame( '', $config->get_pattern() );
	}

	/**
	 * Explicit options are retained.
	 *
	 * @return void
	 */
	public function test_explicit_options_are_retained() {
		$config = new Search_Config( 'old', 'new', true, false, array( 'old/private' ) );

		$this->assertTrue( $config->is_case_sensitive() );
		$this->assertSame( array( 'old/private' ), $config->get_exclusions() );
	}

	/**
	 * Invalid regular expressions are rejected.
	 *
	 * @return void
	 */
	public function test_invalid_regular_expression_is_rejected() {
		$this->expectException( InvalidArgumentException::class );

		new Search_Config( '([a-z]+', 'new', true, true );
	}

	/**
	 * Case-insensitive regular expressions receive the i flag.
	 *
	 * @return void
	 */
	public function test_case_insensitive_regular_expression_receives_i_flag() {
		$config = new Search_Config( '(old)', '$1-new', false, true );

		$this->assertStringEndsWith( 'i', $config->get_pattern() );
	}

	/**
	 * An escaped delimiter remains part of the regular expression body.
	 *
	 * @return void
	 */
	public function test_escaped_delimiter_remains_part_of_regex_body() {
		$config = new Search_Config( 'value\\~old', 'new', true, true );
		$result = ( new \SafeSR\Engine\Replacer( $config ) )->replace_value( 'value~old' );

		$this->assertSame( 'new', $result->get_new_value() );
	}

	/**
	 * Patterns containing every preferred delimiter remain valid.
	 *
	 * @return void
	 */
	public function test_pattern_containing_every_preferred_delimiter_remains_valid() {
		$search = "~#%!@;`\x01\x02\x03";
		$config = new Search_Config( $search, 'new', true, true );
		$result = ( new \SafeSR\Engine\Replacer( $config ) )->replace_value( $search );

		$this->assertSame( 'new', $result->get_new_value() );
	}
}
