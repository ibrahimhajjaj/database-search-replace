<?php
/**
 * Unit tests for structured replacement formats.
 *
 * @package SafeSearchReplace
 */

use PHPUnit\Framework\TestCase;
use SafeSR\Engine\Replace_Result;
use SafeSR\Engine\Replacer;
use SafeSR\Engine\Search_Config;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- The fixtures exercise serialized storage behavior.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The fixtures exercise base64 storage behavior.
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit tests run without WordPress.

/**
 * Verifies serialized, JSON, and base64 transformations.
 */
class SafeSR_Replacer_Structured_Test extends TestCase {

	/**
	 * Serialized string lengths are recalculated exactly.
	 *
	 * @return void
	 */
	public function test_serialized_string_lengths_are_recalculated_exactly() {
		$value    = 'a:1:{s:4:"logo";s:43:"http://staging.northwindcoffee.com/logo.png";}';
		$expected = 'a:1:{s:4:"logo";s:36:"https://northwindcoffee.com/logo.png";}';
		$result   = $this->northwind_replacer()->replace_value( $value );

		$this->assertSame( $expected, $result->get_new_value() );
		$this->assertSame( 1, $result->get_count() );
		$this->assertContains( 'serialized', $result->get_formats() );
	}

	/**
	 * Serialized prefixes count bytes in multibyte replacements.
	 *
	 * @return void
	 */
	public function test_serialized_prefix_counts_multibyte_replacement_bytes() {
		$config = new Search_Config( 'old', 'قهوة', true );
		$result = ( new Replacer( $config ) )->replace_value( 'a:1:{i:0;s:3:"old";}' );

		$this->assertSame( 'a:1:{i:0;s:8:"قهوة";}', $result->get_new_value() );
	}

	/**
	 * Deeply nested arrays are walked.
	 *
	 * @return void
	 */
	public function test_nested_arrays_are_walked() {
		$value  = serialize( array( array( array( array( 'http://staging.northwindcoffee.com/shop' ) ) ) ) );
		$result = $this->northwind_replacer()->replace_value( $value );

		$this->assertSame( array( array( array( array( 'https://northwindcoffee.com/shop' ) ) ) ), unserialize( $result->get_new_value() ) );
	}

	/**
	 * Standard objects are walked without changing their type.
	 *
	 * @return void
	 */
	public function test_standard_objects_are_walked() {
		$object      = new stdClass();
		$object->url = 'http://staging.northwindcoffee.com/shop';
		$result      = $this->northwind_replacer()->replace_value( serialize( $object ) );
		$decoded     = unserialize( $result->get_new_value() );

		$this->assertInstanceOf( stdClass::class, $decoded );
		$this->assertSame( 'https://northwindcoffee.com/shop', $decoded->url );
	}

	/**
	 * Named object class markers survive secure processing.
	 *
	 * @return void
	 */
	public function test_named_object_class_marker_survives_processing() {
		$value  = 'O:16:"Northwind_Widget":1:{s:3:"url";s:43:"http://staging.northwindcoffee.com/shop.jpg";}';
		$result = $this->northwind_replacer()->replace_value( $value );

		$this->assertStringStartsWith( 'O:16:"Northwind_Widget":1:', $result->get_new_value() );
		$this->assertStringContainsString( 'https://northwindcoffee.com/shop.jpg', $result->get_new_value() );
	}

	/**
	 * A named object nested in an array is written back after replacement.
	 *
	 * @return void
	 */
	public function test_nested_named_object_is_written_back_after_replacement() {
		$value    = 'a:1:{s:1:"k";O:8:"stdClass":1:{s:3:"url";s:3:"old";}}';
		$expected = 'a:1:{s:1:"k";O:8:"stdClass":1:{s:3:"url";s:3:"new";}}';
		$result   = ( new Replacer( new Search_Config( 'old', 'new', true ) ) )->replace_value( $value );

		$this->assertSame( $expected, $result->get_new_value() );
		$this->assertSame( 1, $result->get_count() );
	}

	/**
	 * Nested object and string siblings both retain their replacements.
	 *
	 * @return void
	 */
	public function test_nested_object_and_string_siblings_are_both_replaced() {
		$value    = 'a:2:{s:3:"obj";O:8:"stdClass":1:{s:3:"url";s:13:"old-in-object";}s:3:"str";s:13:"old-in-string";}';
		$expected = 'a:2:{s:3:"obj";O:8:"stdClass":1:{s:3:"url";s:13:"new-in-object";}s:3:"str";s:13:"new-in-string";}';
		$result   = ( new Replacer( new Search_Config( 'old', 'new', true ) ) )->replace_value( $value );

		$this->assertSame( $expected, $result->get_new_value() );
		$this->assertSame( 2, $result->get_count() );
	}

	/**
	 * A named object nested through an array and object is written back.
	 *
	 * @return void
	 */
	public function test_named_object_nested_two_levels_deep_is_written_back() {
		$value    = 'O:5:"Outer":1:{s:5:"items";a:1:{i:0;O:5:"Inner":1:{s:3:"url";s:3:"old";}}}';
		$expected = 'O:5:"Outer":1:{s:5:"items";a:1:{i:0;O:5:"Inner":1:{s:3:"url";s:3:"new";}}}';
		$result   = ( new Replacer( new Search_Config( 'old', 'new', true ) ) )->replace_value( $value );

		$this->assertSame( $expected, $result->get_new_value() );
		$this->assertSame( 1, $result->get_count() );
	}

	/**
	 * Serialized strings inside serialized values are transformed.
	 *
	 * @return void
	 */
	public function test_double_serialized_values_are_transformed() {
		$inner  = serialize( array( 'url' => 'http://staging.northwindcoffee.com/shop' ) );
		$result = $this->northwind_replacer()->replace_value( serialize( array( $inner ) ) );
		$outer  = unserialize( $result->get_new_value() );

		$this->assertSame( array( 'url' => 'https://northwindcoffee.com/shop' ), unserialize( $outer[0] ) );
		$this->assertSame( 1, $result->get_count() );
	}

	/**
	 * Serialized false remains byte-identical.
	 *
	 * @return void
	 */
	public function test_serialized_false_remains_byte_identical() {
		$result = $this->northwind_replacer()->replace_value( 'b:0;' );

		$this->assertSame( 'b:0;', $result->get_new_value() );
		$this->assertFalse( $result->is_changed() );
		$this->assertFalse( $result->is_skipped() );
	}

	/**
	 * Malformed serialized values are skipped safely.
	 *
	 * @return void
	 */
	public function test_malformed_serialized_value_is_skipped_safely() {
		$value  = 'a:1:{i:0;s:99:"http://staging.northwindcoffee.com";}';
		$result = $this->northwind_replacer()->replace_value( $value );

		$this->assertSame( $value, $result->get_new_value() );
		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( Replace_Result::SKIP_MALFORMED_SERIALIZED, $result->get_skip_reason() );
	}

	/**
	 * Unavailable serialized enum values never fall through to plain replacement.
	 *
	 * @return void
	 */
	public function test_unavailable_serialized_enum_is_skipped_without_prefix_corruption() {
		$value  = 'E:11:"Suit:Hearts";';
		$config = new Search_Config( 'Suit', 'LongSuit', true );
		$result = ( new Replacer( $config ) )->replace_value( $value );

		$this->assertSame( $value, $result->get_new_value() );
		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( Replace_Result::SKIP_MALFORMED_SERIALIZED, $result->get_skip_reason() );
	}

	/**
	 * Opaque Serializable payloads are skipped without editing their prefixes.
	 *
	 * @return void
	 */
	public function test_custom_serialized_payload_is_skipped_and_reported() {
		$value  = 'C:4:"Test":3:{old}';
		$config = new Search_Config( 'old', 'new', true );
		$result = ( new Replacer( $config ) )->replace_value( $value );

		$this->assertSame( $value, $result->get_new_value() );
		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( Replace_Result::SKIP_MALFORMED_SERIALIZED, $result->get_skip_reason() );
	}

	/**
	 * Serialized NAN remains byte-identical.
	 *
	 * @return void
	 */
	public function test_serialized_nan_remains_byte_identical() {
		$result = ( new Replacer( new Search_Config( 'NAN', 'INF', true ) ) )->replace_value( 'd:NAN;' );

		$this->assertSame( 'd:NAN;', $result->get_new_value() );
		$this->assertSame( 0, $result->get_count() );
		$this->assertFalse( $result->is_changed() );
	}

	/**
	 * Serialized aliases remain references and count their stored string once.
	 *
	 * @return void
	 */
	public function test_serialized_object_reference_survives_with_one_replacement() {
		$value  = 'O:6:"RefObj":2:{s:1:"a";s:3:"old";s:1:"b";R:2;}';
		$result = ( new Replacer( new Search_Config( 'old', 'new', true ) ) )->replace_value( $value );

		$this->assertStringContainsString( 's:1:"a";s:3:"new";s:1:"b";R:2;', $result->get_new_value() );
		$this->assertSame( 1, $result->get_count() );
	}

	/**
	 * An aliased array string is replaced once when replacement contains search.
	 *
	 * @return void
	 */
	public function test_aliased_array_string_is_not_replaced_twice_by_superset_replacement() {
		$value    = 'a:2:{i:0;s:3:"old";i:1;R:2;}';
		$expected = 'a:2:{i:0;s:7:"old_new";i:1;R:2;}';
		$result   = ( new Replacer( new Search_Config( 'old', 'old_new', true ) ) )->replace_value( $value );

		$this->assertSame( $expected, $result->get_new_value() );
		$this->assertSame( 1, $result->get_count() );
	}

	/**
	 * An aliased object property is replaced once when replacement contains search.
	 *
	 * @return void
	 */
	public function test_aliased_object_property_is_not_replaced_twice_by_superset_replacement() {
		$value    = 'O:6:"RefObj":2:{s:1:"a";s:3:"old";s:1:"b";R:2;}';
		$expected = 'O:6:"RefObj":2:{s:1:"a";s:7:"old_new";s:1:"b";R:2;}';
		$result   = ( new Replacer( new Search_Config( 'old', 'old_new', true ) ) )->replace_value( $value );

		$this->assertSame( $expected, $result->get_new_value() );
		$this->assertSame( 1, $result->get_count() );
	}

	/**
	 * Independent duplicate strings each receive a superset replacement.
	 *
	 * @return void
	 */
	public function test_non_aliased_duplicate_strings_each_receive_superset_replacement() {
		$value    = 'a:2:{i:0;s:3:"old";i:1;s:3:"old";}';
		$expected = 'a:2:{i:0;s:7:"old_new";i:1;s:7:"old_new";}';
		$result   = ( new Replacer( new Search_Config( 'old', 'old_new', true ) ) )->replace_value( $value );

		$this->assertSame( $expected, $result->get_new_value() );
		$this->assertSame( 2, $result->get_count() );
	}

	/**
	 * Mangled private property names survive serialized mutation byte-exact.
	 *
	 * @return void
	 */
	public function test_mangled_private_property_name_survives_replacement() {
		$key    = "\x00Vault\x00prop";
		$value  = 'O:5:"Vault":1:{s:11:"' . $key . '";s:3:"old";}';
		$result = ( new Replacer( new Search_Config( 'old', 'new', true ) ) )->replace_value( $value );

		$this->assertSame( 'O:5:"Vault":1:{s:11:"' . $key . '";s:3:"new";}', $result->get_new_value() );
	}

	/**
	 * Structures deeper than the safety limit are skipped atomically.
	 *
	 * @return void
	 */
	public function test_depth_over_safety_limit_is_skipped_atomically() {
		$data = 'http://staging.northwindcoffee.com';
		for ( $depth = 0; $depth < 66; ++$depth ) {
			$data = array( $data );
		}
		$value  = serialize( $data );
		$result = $this->northwind_replacer()->replace_value( $value );

		$this->assertSame( $value, $result->get_new_value() );
		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( Replace_Result::SKIP_DEPTH_EXCEEDED, $result->get_skip_reason() );
	}

	/**
	 * Elementor escaped slash style survives JSON replacement.
	 *
	 * @return void
	 */
	public function test_elementor_escaped_slash_style_survives_json_replacement() {
		$json     = '{"button_link":{"url":"http:\/\/staging.northwindcoffee.com\/shop"}}';
		$value    = serialize( array( $json ) );
		$expected = serialize( array( '{"button_link":{"url":"https:\/\/northwindcoffee.com\/shop"}}' ) );
		$result   = $this->northwind_replacer()->replace_value( $value );

		$this->assertSame( $expected, $result->get_new_value() );
		$this->assertContains( 'json', $result->get_formats() );
		$this->assertContains( 'serialized', $result->get_formats() );
	}

	/**
	 * Bare JSON columns are transformed.
	 *
	 * @return void
	 */
	public function test_bare_json_column_is_transformed() {
		$value  = '{"url":"http://staging.northwindcoffee.com/shop"}';
		$result = $this->northwind_replacer()->replace_value( $value );

		$this->assertSame( '{"url":"https://northwindcoffee.com/shop"}', $result->get_new_value() );
		$this->assertContains( 'json', $result->get_formats() );
	}

	/**
	 * Unescaped JSON slashes stay unescaped.
	 *
	 * @return void
	 */
	public function test_unescaped_json_slashes_stay_unescaped() {
		$result = $this->northwind_replacer()->replace_value( '["http://staging.northwindcoffee.com/shop"]' );

		$this->assertSame( '["https://northwindcoffee.com/shop"]', $result->get_new_value() );
		$this->assertStringNotContainsString( '\\/', $result->get_new_value() );
	}

	/**
	 * Escaped Unicode style survives a changed JSON document.
	 *
	 * @return void
	 */
	public function test_escaped_unicode_style_survives_changed_json() {
		$value  = '{"label":"\u0642\u0647\u0648\u0629","url":"http://staging.northwindcoffee.com"}';
		$result = $this->northwind_replacer()->replace_value( $value );

		$this->assertStringContainsString( '\u0642\u0647\u0648\u0629', $result->get_new_value() );
	}

	/**
	 * Raw UTF-8 style survives a changed JSON document.
	 *
	 * @return void
	 */
	public function test_raw_utf8_style_survives_changed_json() {
		$value  = '{"label":"قهوة","url":"http://staging.northwindcoffee.com"}';
		$result = $this->northwind_replacer()->replace_value( $value );

		$this->assertStringContainsString( 'قهوة', $result->get_new_value() );
		$this->assertStringNotContainsString( '\\u', $result->get_new_value() );
	}

	/**
	 * Empty JSON objects and arrays keep distinct types after a sibling change.
	 *
	 * @return void
	 */
	public function test_empty_json_objects_and_arrays_keep_distinct_types() {
		$value  = '{"object":{},"array":[],"url":"http://staging.northwindcoffee.com"}';
		$result = $this->northwind_replacer()->replace_value( $value );

		$this->assertStringContainsString( '"object":{}', $result->get_new_value() );
		$this->assertStringContainsString( '"array":[]', $result->get_new_value() );
	}

	/**
	 * JSON-looking scalar leaves remain ordinary strings.
	 *
	 * @dataProvider provide_json_scalar_leaves
	 * @param string $scalar JSON-looking scalar.
	 * @return void
	 */
	public function test_json_scalar_leaves_remain_ordinary_strings( $scalar ) {
		$value  = serialize( array( $scalar, 'http://staging.northwindcoffee.com' ) );
		$result = $this->northwind_replacer()->replace_value( $value );
		$data   = unserialize( $result->get_new_value() );

		$this->assertSame( $scalar, $data[0] );
	}

	/**
	 * Provides JSON-looking scalar leaves.
	 *
	 * @return array<string,array<string>>
	 */
	public function provide_json_scalar_leaves() {
		return array(
			'numeric string' => array( '"123"' ),
			'boolean'        => array( 'true' ),
			'quoted scalar'  => array( '"northwind"' ),
		);
	}

	/**
	 * An unchanged JSON sibling remains byte-identical.
	 *
	 * @return void
	 */
	public function test_unchanged_json_sibling_remains_byte_identical() {
		$unchanged = '{ "ratio" : 1.0, "path" : "a\/b" }';
		$value     = serialize( array( $unchanged, 'http://staging.northwindcoffee.com' ) );
		$result    = $this->northwind_replacer()->replace_value( $value );
		$data      = unserialize( $result->get_new_value() );

		$this->assertSame( $unchanged, $data[0] );
	}

	/**
	 * Stringified JSON nested in JSON is transformed recursively.
	 *
	 * @return void
	 */
	public function test_stringified_json_nested_in_json_is_transformed() {
		$inner  = '{"url":"http://staging.northwindcoffee.com/shop"}';
		$value  = json_encode( array( 'settings' => $inner ), JSON_UNESCAPED_SLASHES );
		$result = $this->northwind_replacer()->replace_value( $value );
		$outer  = json_decode( $result->get_new_value() );
		$data   = json_decode( $outer->settings );

		$this->assertSame( 'https://northwindcoffee.com/shop', $data->url );
	}

	/**
	 * Duplicate JSON object keys survive raw text replacement.
	 *
	 * @return void
	 */
	public function test_duplicate_json_object_keys_are_not_dropped() {
		$value  = '{"a":"keep","a":"old"}';
		$result = ( new Replacer( new Search_Config( 'old', 'new', true ) ) )->replace_value( $value );

		$this->assertSame( '{"a":"keep","a":"new"}', $result->get_new_value() );
		$this->assertSame( array( 'plain' ), $result->get_formats() );
	}

	/**
	 * Long JSON integers survive raw text replacement.
	 *
	 * @return void
	 */
	public function test_json_big_integer_is_not_coerced_during_replacement() {
		$value  = '{"id":9223372036854775809,"label":"old"}';
		$result = ( new Replacer( new Search_Config( 'old', 'new', true ) ) )->replace_value( $value );

		$this->assertSame( '{"id":9223372036854775809,"label":"new"}', $result->get_new_value() );
		$this->assertSame( array( 'plain' ), $result->get_formats() );
	}

	/**
	 * Risky JSON with an escaped URL is reported instead of silently missed.
	 *
	 * @return void
	 */
	public function test_json_with_sixteen_digit_number_and_escaped_url_is_skipped() {
		$value  = '{"id":1234567890123456,"url":"http:\/\/staging.northwindcoffee.com\/shop"}';
		$result = $this->northwind_replacer()->replace_value( $value );

		$this->assertSame( $value, $result->get_new_value() );
		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( Replace_Result::SKIP_LOSSY_JSON, $result->get_skip_reason() );
	}

	/**
	 * Duplicate-key JSON with escaped URLs is reported without dropping a key.
	 *
	 * @return void
	 */
	public function test_json_with_duplicate_keys_and_escaped_urls_is_skipped() {
		$value  = '{"url":"http:\/\/staging.northwindcoffee.com\/one","url":"http:\/\/staging.northwindcoffee.com\/two"}';
		$result = $this->northwind_replacer()->replace_value( $value );

		$this->assertSame( $value, $result->get_new_value() );
		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( Replace_Result::SKIP_LOSSY_JSON, $result->get_skip_reason() );
	}

	/**
	 * Base64 serialized data is transformed through both layers.
	 *
	 * @return void
	 */
	public function test_base64_serialized_data_is_transformed_through_both_layers() {
		$value  = base64_encode( serialize( array( 'url' => 'http://staging.northwindcoffee.com/shop' ) ) );
		$result = $this->northwind_replacer()->replace_value( $value );
		$data   = unserialize( base64_decode( $result->get_new_value(), true ) );

		$this->assertSame( 'https://northwindcoffee.com/shop', $data['url'] );
		$this->assertContains( 'base64', $result->get_formats() );
		$this->assertContains( 'serialized', $result->get_formats() );
	}

	/**
	 * Base64 containing malformed serialized bytes remains opaque and unskipped.
	 *
	 * @return void
	 */
	public function test_base64_malformed_serialized_bytes_remain_opaque_and_unskipped() {
		$value  = base64_encode( 'a:1:{i:0;s:99:"x";}' );
		$result = ( new Replacer( new Search_Config( 'x', 'y', true ) ) )->replace_value( $value );

		$this->assertSame( $value, $result->get_new_value() );
		$this->assertSame( 0, $result->get_count() );
		$this->assertFalse( $result->is_skipped() );
	}

	/**
	 * Base64 plain text is transformed.
	 *
	 * @return void
	 */
	public function test_base64_plain_text_is_transformed() {
		$value  = base64_encode( 'Visit http://staging.northwindcoffee.com/shop today.' );
		$result = $this->northwind_replacer()->replace_value( $value );

		$this->assertSame( 'Visit https://northwindcoffee.com/shop today.', base64_decode( $result->get_new_value(), true ) );
		$this->assertContains( 'base64', $result->get_formats() );
	}

	/**
	 * Encoded base64 text is never replaced directly.
	 *
	 * @return void
	 */
	public function test_base64_encoded_representation_is_not_edited() {
		$value  = 'YWJjZGVmZ2hpamtsbW5vcHFy';
		$result = ( new Replacer( new Search_Config( 'YWJj', 'XXXX', true ) ) )->replace_value( $value );

		$this->assertSame( $value, $result->get_new_value() );
		$this->assertFalse( $result->is_changed() );
	}

	/**
	 * Binary base64 is untouched even when its encoded text contains a match.
	 *
	 * @return void
	 */
	public function test_binary_base64_encoded_representation_is_not_edited() {
		$value  = 'aaaaaaaaaaaaaaaaaaaaaaaa';
		$result = ( new Replacer( new Search_Config( 'aaaa', 'bbbb', true ) ) )->replace_value( $value );

		$this->assertSame( $value, $result->get_new_value() );
		$this->assertFalse( $result->is_changed() );
	}

	/**
	 * Short base64-looking words remain untouched.
	 *
	 * @return void
	 */
	public function test_short_base64_looking_word_remains_untouched() {
		$result = $this->northwind_replacer()->replace_value( 'data' );

		$this->assertSame( 'data', $result->get_new_value() );
		$this->assertFalse( $result->is_changed() );
	}

	/**
	 * Base64 with invalid padding remains untouched.
	 *
	 * @return void
	 */
	public function test_base64_with_invalid_padding_remains_untouched() {
		$value  = 'aHR0cDovL3N0YWdpbmcubm9ydGh3aW5kY29mZmVlLmNvbQ===';
		$result = $this->northwind_replacer()->replace_value( $value );

		$this->assertSame( $value, $result->get_new_value() );
	}

	/**
	 * Base64 binary garbage remains untouched.
	 *
	 * @return void
	 */
	public function test_base64_binary_garbage_remains_untouched() {
		$value  = base64_encode( "\x00\xFF\x10\x80\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E" );
		$result = $this->northwind_replacer()->replace_value( $value );

		$this->assertSame( $value, $result->get_new_value() );
	}

	/**
	 * Regex counts matches across serialized leaves.
	 *
	 * @return void
	 */
	public function test_regex_counts_matches_across_serialized_leaves() {
		$config = new Search_Config( 'item-(\d+)', 'product-$1', true, true );
		$value  = serialize( array( 'item-1', array( 'item-2 item-3' ) ) );
		$result = ( new Replacer( $config ) )->replace_value( $value );

		$this->assertSame( 3, $result->get_count() );
	}

	/**
	 * Creates the Northwind scenario replacer.
	 *
	 * @return Replacer
	 */
	private function northwind_replacer() {
		return new Replacer(
			new Search_Config(
				'http://staging.northwindcoffee.com',
				'https://northwindcoffee.com'
			)
		);
	}
}
