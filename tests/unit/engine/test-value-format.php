<?php
/**
 * Unit tests for value format detection.
 *
 * @package SafeSearchReplace
 */

use PHPUnit\Framework\TestCase;
use SafeSR\Engine\Value_Format;

/**
 * Verifies conservative format detection.
 */
class SafeSR_Value_Format_Test extends TestCase {

	/**
	 * Valid serialized values are recognized.
	 *
	 * @dataProvider provide_serialized_values
	 * @param string $value Serialized value.
	 * @return void
	 */
	public function test_valid_serialized_values_are_recognized( $value ) {
		$this->assertTrue( Value_Format::looks_serialized( $value ) );
	}

	/**
	 * Surrounding whitespace follows WordPress serialized detection semantics.
	 *
	 * @return void
	 */
	public function test_surrounding_whitespace_is_ignored_for_serialized_detection() {
		$this->assertTrue( Value_Format::looks_serialized( " \n\ta:0:{} \r" ) );
	}

	/**
	 * Provides serialized values.
	 *
	 * @return array<string,array<string>>
	 */
	public function provide_serialized_values() {
		return array(
			'string'                   => array( 's:3:"old";' ),
			'array'                    => array( 'a:0:{}' ),
			'object'                   => array( 'O:8:"stdClass":0:{}' ),
			'custom serialized object' => array( 'C:4:"Test":3:{old}' ),
			'enum'                     => array( 'E:11:"Suit:Hearts";' ),
			'boolean'                  => array( 'b:0;' ),
			'integer'                  => array( 'i:42;' ),
			'float'                    => array( 'd:1.5;' ),
			'not a number float'       => array( 'd:NAN;' ),
			'positive infinity float'  => array( 'd:INF;' ),
			'negative infinity float'  => array( 'd:-INF;' ),
			'null'                     => array( 'N;' ),
		);
	}

	/**
	 * Plain and structurally incomplete values are rejected.
	 *
	 * @dataProvider provide_non_serialized_values
	 * @param string $value Plain value.
	 * @return void
	 */
	public function test_non_serialized_values_are_rejected( $value ) {
		$this->assertFalse( Value_Format::looks_serialized( $value ) );
	}

	/**
	 * Provides non-serialized values.
	 *
	 * @return array<string,array<string>>
	 */
	public function provide_non_serialized_values() {
		return array(
			'empty'           => array( '' ),
			'plain'           => array( 'staging.example.test' ),
			'wrong ending'    => array( 'a:0:{' ),
			'wrong separator' => array( 's-3:"old";' ),
		);
	}

	/**
	 * JSON objects and arrays are recognized with leading whitespace.
	 *
	 * @return void
	 */
	public function test_json_documents_are_recognized() {
		$this->assertTrue( Value_Format::looks_json( " \n {\"url\":\"value\"}" ) );
		$this->assertTrue( Value_Format::looks_json( '[1,2,3]' ) );
	}

	/**
	 * JSON scalars are deliberately rejected.
	 *
	 * @dataProvider provide_json_scalars
	 * @param string $value JSON scalar.
	 * @return void
	 */
	public function test_json_scalars_are_rejected( $value ) {
		$this->assertFalse( Value_Format::looks_json( $value ) );
	}

	/**
	 * Provides JSON scalar values.
	 *
	 * @return array<string,array<string>>
	 */
	public function provide_json_scalars() {
		return array(
			'quoted numeric string' => array( '"123"' ),
			'boolean'               => array( 'true' ),
			'number'                => array( '123' ),
			'null'                  => array( 'null' ),
		);
	}

	/**
	 * Malformed JSON documents are rejected.
	 *
	 * @return void
	 */
	public function test_malformed_json_is_rejected() {
		$this->assertFalse( Value_Format::looks_json( '{"url":}' ) );
	}

	/**
	 * Canonical base64 with sufficient length is recognized.
	 *
	 * @return void
	 */
	public function test_canonical_base64_is_recognized() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The fixture verifies stored base64 detection.
		$this->assertTrue( Value_Format::is_strict_base64( base64_encode( 'a sufficiently long value' ) ) );
	}

	/**
	 * Short English words are not treated as base64.
	 *
	 * @return void
	 */
	public function test_short_english_word_is_not_base64() {
		$this->assertFalse( Value_Format::is_strict_base64( 'data' ) );
	}

	/**
	 * Invalid padding and noncanonical encodings are rejected.
	 *
	 * @return void
	 */
	public function test_invalid_base64_is_rejected() {
		$this->assertFalse( Value_Format::is_strict_base64( 'YWJjZGVmZ2hpamtsbW5vcA===' ) );
		$this->assertFalse( Value_Format::is_strict_base64( 'YWJjZGVmZ2hpamtsbW5vcA==\n' ) );
	}
}
