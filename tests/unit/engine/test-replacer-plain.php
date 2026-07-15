<?php
/**
 * Unit tests for plain, regular expression, and excluded replacements.
 *
 * @package SafeSearchReplace
 */

use PHPUnit\Framework\TestCase;
use SafeSR\Engine\Replacer;
use SafeSR\Engine\Search_Config;

/**
 * Verifies replacement behavior outside structured formats.
 */
class SafeSR_Replacer_Plain_Test extends TestCase {

	/**
	 * A plain match reports its replacement count and format.
	 *
	 * @return void
	 */
	public function test_plain_match_reports_count_and_format() {
		$result = $this->replace( 'Visit http://staging.northwind.test now.' );

		$this->assertSame( 'Visit https://northwind.test now.', $result->get_new_value() );
		$this->assertSame( 1, $result->get_count() );
		$this->assertTrue( $result->is_changed() );
		$this->assertSame( array( 'plain' ), $result->get_formats() );
	}

	/**
	 * Multiple occurrences are counted.
	 *
	 * @return void
	 */
	public function test_multiple_occurrences_are_counted() {
		$result = $this->replace( 'http://staging.northwind.test http://staging.northwind.test' );

		$this->assertSame( 2, $result->get_count() );
	}

	/**
	 * Mixed case input matches by default.
	 *
	 * @return void
	 */
	public function test_mixed_case_input_matches_by_default() {
		$result = $this->replace( 'HTTP://STAGING.NORTHWIND.TEST/shop' );

		$this->assertSame( 'https://northwind.test/shop', $result->get_new_value() );
	}

	/**
	 * Case-sensitive replacement leaves different casing untouched.
	 *
	 * @return void
	 */
	public function test_case_sensitive_replacement_leaves_different_casing_untouched() {
		$result = $this->replace( 'HTTP://STAGING.NORTHWIND.TEST', true );

		$this->assertFalse( $result->is_changed() );
	}

	/**
	 * No match returns the original bytes.
	 *
	 * @return void
	 */
	public function test_no_match_returns_original_bytes() {
		$value  = " café \x00 عربي ";
		$result = $this->replace( $value );

		$this->assertSame( $value, $result->get_new_value() );
		$this->assertSame( 0, $result->get_count() );
		$this->assertFalse( $result->is_changed() );
	}

	/**
	 * Replacement text is not scanned again.
	 *
	 * @return void
	 */
	public function test_replacement_text_is_not_scanned_again() {
		$config   = new Search_Config( 'old', 'old-new', true );
		$replacer = new Replacer( $config );
		$result   = $replacer->replace_value( 'old old' );

		$this->assertSame( 'old-new old-new', $result->get_new_value() );
		$this->assertSame( 2, $result->get_count() );
	}

	/**
	 * Multibyte context is retained around matches.
	 *
	 * @return void
	 */
	public function test_multibyte_context_is_retained() {
		$result = $this->replace( 'قهوة café http://staging.northwind.test متجر' );

		$this->assertSame( 'قهوة café https://northwind.test متجر', $result->get_new_value() );
	}

	/**
	 * Capture groups can be used in replacement text.
	 *
	 * @return void
	 */
	public function test_regex_capture_groups_are_expanded() {
		$config = new Search_Config( 'item-(\d+)', 'product-$1', true, true );
		$result = ( new Replacer( $config ) )->replace_value( 'item-12 item-34' );

		$this->assertSame( 'product-12 product-34', $result->get_new_value() );
		$this->assertSame( 2, $result->get_count() );
	}

	/**
	 * Regular expressions are case-insensitive when configured that way.
	 *
	 * @return void
	 */
	public function test_regex_can_match_case_insensitively() {
		$config = new Search_Config( 'northwind', 'contoso', false, true );
		$result = ( new Replacer( $config ) )->replace_value( 'NORTHWIND northwind' );

		$this->assertSame( 'contoso contoso', $result->get_new_value() );
		$this->assertSame( 2, $result->get_count() );
	}

	/**
	 * A matching exclusion protects the complete occurrence.
	 *
	 * @return void
	 */
	public function test_matching_exclusion_protects_complete_occurrence() {
		$config = new Search_Config(
			'http://staging.northwind.test',
			'https://northwind.test',
			true,
			false,
			array( 'http://staging.northwind.test/webhooks' )
		);
		$value  = 'http://staging.northwind.test/shop http://staging.northwind.test/webhooks';
		$result = ( new Replacer( $config ) )->replace_value( $value );

		$this->assertSame( 'https://northwind.test/shop http://staging.northwind.test/webhooks', $result->get_new_value() );
		$this->assertSame( 1, $result->get_count() );
	}

	/**
	 * Multiple exclusions are restored byte-identically.
	 *
	 * @return void
	 */
	public function test_multiple_exclusions_are_restored_byte_identically() {
		$config = new Search_Config(
			'staging.northwind.test',
			'northwind.test',
			true,
			false,
			array( 'staging.northwind.test/webhooks?x=1', 'staging.northwind.test/api' )
		);
		$value  = 'staging.northwind.test staging.northwind.test/webhooks?x=1 staging.northwind.test/api';
		$result = ( new Replacer( $config ) )->replace_value( $value );

		$this->assertSame( 'northwind.test staging.northwind.test/webhooks?x=1 staging.northwind.test/api', $result->get_new_value() );
	}

	/**
	 * An exclusion without a search match is inert.
	 *
	 * @return void
	 */
	public function test_exclusion_without_search_match_is_inert() {
		$config = new Search_Config( 'old', 'new', true, false, array( 'protected-value' ) );
		$result = ( new Replacer( $config ) )->replace_value( 'old protected-value' );

		$this->assertSame( 'new protected-value', $result->get_new_value() );
	}

	/**
	 * Regex replacement cannot consume exclusion mask tokens.
	 *
	 * @return void
	 */
	public function test_regex_replacement_cannot_consume_exclusion_tokens() {
		$config = new Search_Config( '\\w+', 'new', true, true, array( 'protected' ) );
		$result = ( new Replacer( $config ) )->replace_value( 'change protected' );

		$this->assertSame( 'new protected', $result->get_new_value() );
		$this->assertSame( 1, $result->get_count() );
	}

	/**
	 * Replacement text matching a candidate mask token remains literal.
	 *
	 * @return void
	 */
	public function test_replacement_matching_mask_token_is_not_restored_as_an_exclusion() {
		$replacement = "\x00SAFESR_PROTECTED\x00";
		$config      = new Search_Config( 'old', $replacement, true, false, array( 'old-keep' ) );
		$result      = ( new Replacer( $config ) )->replace_value( 'old old-keep' );

		$this->assertSame( $replacement . ' old-keep', $result->get_new_value() );
		$this->assertSame( 1, $result->get_count() );
	}

	/**
	 * Byte-identical replacements are reported as no-ops.
	 *
	 * @return void
	 */
	public function test_search_equal_to_replacement_reports_no_change() {
		$config = new Search_Config( 'old', 'old', true );
		$result = ( new Replacer( $config ) )->replace_value( 'old' );

		$this->assertSame( 'old', $result->get_new_value() );
		$this->assertSame( 0, $result->get_count() );
		$this->assertFalse( $result->is_changed() );
		$this->assertSame( array(), $result->get_formats() );
	}

	/**
	 * Runs the Northwind URL replacement.
	 *
	 * @param string $value          Input value.
	 * @param bool   $case_sensitive Whether matching is case-sensitive.
	 * @return \SafeSR\Engine\Replace_Result
	 */
	private function replace( $value, $case_sensitive = false ) {
		$config = new Search_Config(
			'http://staging.northwind.test',
			'https://northwind.test',
			$case_sensitive
		);

		return ( new Replacer( $config ) )->replace_value( $value );
	}
}
