<?php
/**
 * Serialization-safe stored value replacement.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Engine;

use __PHP_Incomplete_Class;
use stdClass;

/**
 * Replaces matches while preserving structured storage formats.
 */
class Replacer {

	const MAX_DEPTH = 64;

	/**
	 * Validated search configuration.
	 *
	 * @var Search_Config
	 */
	private Search_Config $config;

	/**
	 * Uses one validated search configuration.
	 *
	 * @param Search_Config $config Validated search configuration.
	 */
	public function __construct( Search_Config $config ) {
		$this->config = $config;
	}

	/**
	 * Replaces matches within one stored value.
	 *
	 * @param string $value Stored value.
	 * @return Replace_Result
	 */
	public function replace_value( string $value ): Replace_Result {
		$result = $this->transform_string( $value, 0 );

		if ( '' !== $result['skip_reason'] ) {
			return new Replace_Result( $value, 0, false, true, $result['skip_reason'], array() );
		}

		if ( $result['value'] === $value ) {
			return new Replace_Result( $value, 0, false, false, '', array() );
		}

		return new Replace_Result(
			$result['value'],
			$result['count'],
			true,
			false,
			'',
			array_values( array_unique( $result['formats'] ) )
		);
	}

	/**
	 * Transforms a string using structured format priority.
	 *
	 * @param string $value Stored or nested string.
	 * @param int    $depth Current structure depth.
	 * @return array{value:mixed,count:int,formats:string[],skip_reason:string}
	 */
	private function transform_string( $value, $depth ) {
		if ( self::MAX_DEPTH < $depth ) {
			return $this->skipped_transform( Replace_Result::SKIP_DEPTH_EXCEEDED );
		}

		if ( Value_Format::looks_serialized( $value ) ) {
			return $this->transform_serialized( $value, $depth );
		}

		if ( Value_Format::looks_json( $value ) ) {
			return $this->transform_json( $value, $depth );
		}

		if ( Value_Format::is_strict_base64( $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Canonical values only.
			$decoded = base64_decode( $value, true );
			if ( false !== $decoded && $this->decoded_value_is_meaningful( $decoded ) ) {
				$result = $this->transform_string( $decoded, $depth + 1 );
				if ( '' !== $result['skip_reason'] ) {
					return $this->unchanged_transform( $value );
				}

				if ( 0 < $result['count'] ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Preserve the storage layer.
					$result['value']     = base64_encode( $result['value'] );
					$result['formats'][] = 'base64';

					return $result;
				}
			}

			return $this->unchanged_transform( $value );
		}

		return $this->transform_plain( $value );
	}

	/**
	 * Securely transforms a PHP serialized value.
	 *
	 * @param string $value Serialized value.
	 * @param int    $depth Current structure depth.
	 * @return array{value:mixed,count:int,formats:string[],skip_reason:string}
	 */
	private function transform_serialized( $value, $depth ) {
		if ( 0 === strpos( ltrim( $value ), 'C:' ) ) {
			return $this->skipped_transform( Replace_Result::SKIP_MALFORMED_SERIALIZED );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- No classes; malformed input is expected.
		$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );
		if ( false === $decoded && 'b:0;' !== $value ) {
			return $this->skipped_transform( Replace_Result::SKIP_MALFORMED_SERIALIZED );
		}

		if ( 1 === preg_match( '/(?:^|[;{])[Rr]:[0-9]+;/', $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Copy references without loading classes.
			$snapshot = @unserialize( $value, array( 'allowed_classes' => false ) );
			$result   = $this->walk_value_with_snapshot( $decoded, $snapshot, $depth + 1 );
		} else {
			$result = $this->walk_value( $decoded, $depth + 1 );
		}

		if ( '' !== $result['skip_reason'] || 0 === $result['count'] ) {
			$result['value'] = $value;

			return $result;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Recalculate byte lengths.
		$result['value']     = serialize( $result['value'] );
		$result['formats'][] = 'serialized';

		return $result;
	}

	/**
	 * Transforms a JSON object or array document.
	 *
	 * @param string $value JSON document.
	 * @param int    $depth Current structure depth.
	 * @return array{value:mixed,count:int,formats:string[],skip_reason:string}
	 */
	private function transform_json( $value, $depth ) {
		if ( 1 === preg_match( '/[0-9]{16}/', $value ) || $this->json_has_duplicate_keys( $value ) ) {
			$plain = $this->transform_plain( $value );
			if ( 0 < $plain['count'] ) {
				return $plain;
			}

			$decoded = json_decode( $value, false, 512, JSON_BIGINT_AS_STRING );
			$result  = $this->walk_value( $decoded, $depth + 1 );
			if ( 0 < $result['count'] ) {
				return $this->skipped_transform( Replace_Result::SKIP_LOSSY_JSON );
			}

			return $this->unchanged_transform( $value );
		}

		$decoded = json_decode( $value, false );
		$result  = $this->walk_value( $decoded, $depth + 1 );

		if ( '' !== $result['skip_reason'] || 0 === $result['count'] ) {
			$result['value'] = $value;

			return $result;
		}

		$flags = JSON_PRESERVE_ZERO_FRACTION;
		if ( false === strpos( $value, '\\/' ) ) {
			$flags |= JSON_UNESCAPED_SLASHES;
		}
		if ( false === strpos( $value, '\\u' ) ) {
			$flags |= JSON_UNESCAPED_UNICODE;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Engine is WordPress-free.
		$encoded = json_encode( $result['value'], $flags );
		if ( false === $encoded ) {
			return $this->unchanged_transform( $value );
		}

		$result['value']     = $encoded;
		$result['formats'][] = 'json';

		return $result;
	}

	/**
	 * Walks arrays and safe object representations recursively.
	 *
	 * @param mixed $value Value at the current depth.
	 * @param int   $depth Current structure depth.
	 * @return array{value:mixed,count:int,formats:string[],skip_reason:string}
	 */
	private function walk_value( &$value, $depth ) {
		if ( self::MAX_DEPTH < $depth ) {
			return $this->skipped_transform( Replace_Result::SKIP_DEPTH_EXCEEDED );
		}

		if ( is_string( $value ) ) {
			$result = $this->transform_string( $value, $depth );
			if ( '' === $result['skip_reason'] && 0 < $result['count'] ) {
				$value = $result['value'];
			}

			return $result;
		}

		if ( is_array( $value ) ) {
			$result = $this->unchanged_transform( $value );
			foreach ( $value as &$item ) {
				$item_result = $this->walk_value( $item, $depth + 1 );
				$result      = $this->merge_child_result( $result, $item_result );
				if ( '' !== $result['skip_reason'] ) {
					unset( $item );

					return $result;
				}
			}
			unset( $item );
			$result['value'] = $value;

			return $result;
		}

		if ( $value instanceof __PHP_Incomplete_Class ) {
			$result = $this->walk_incomplete_object( $value, $depth );
			if ( '' === $result['skip_reason'] && 0 < $result['count'] ) {
				$value = $result['value'];
			}

			return $result;
		}

		if ( $value instanceof stdClass ) {
			$result = $this->unchanged_transform( $value );
			foreach ( array_keys( get_object_vars( $value ) ) as $key ) {
				$item        =& $value->{$key};
				$item_result = $this->walk_value( $item, $depth + 1 );
				$result      = $this->merge_child_result( $result, $item_result );
				if ( '' !== $result['skip_reason'] ) {
					unset( $item );

					return $result;
				}
				unset( $item );
			}
			$result['value'] = $value;

			return $result;
		}

		return $this->unchanged_transform( $value );
	}

	/**
	 * Walks a value while comparing each live leaf with an unmodified snapshot.
	 *
	 * @param mixed $value    Live value at the current depth.
	 * @param mixed $snapshot Original value with matching reference topology.
	 * @param int   $depth    Current structure depth.
	 * @return array{value:mixed,count:int,formats:string[],skip_reason:string}
	 */
	private function walk_value_with_snapshot( &$value, $snapshot, $depth ) {
		if ( self::MAX_DEPTH < $depth ) {
			return $this->skipped_transform( Replace_Result::SKIP_DEPTH_EXCEEDED );
		}

		if ( is_string( $value ) && is_string( $snapshot ) ) {
			$result = $this->transform_string( $snapshot, $depth );
			if ( '' === $result['skip_reason'] && 0 < $result['count'] ) {
				if ( $value === $snapshot ) {
					$value = $result['value'];

					return $result;
				}

				return $this->unchanged_transform( $value );
			}

			return $result;
		}

		if ( is_array( $value ) && is_array( $snapshot ) ) {
			$result = $this->unchanged_transform( $value );
			foreach ( $value as $key => &$item ) {
				$item_result = $this->walk_value_with_snapshot( $item, $snapshot[ $key ], $depth + 1 );
				$result      = $this->merge_child_result( $result, $item_result );
				if ( '' !== $result['skip_reason'] ) {
					unset( $item );

					return $result;
				}
			}
			unset( $item );
			$result['value'] = $value;

			return $result;
		}

		if ( $value instanceof __PHP_Incomplete_Class && $snapshot instanceof __PHP_Incomplete_Class ) {
			$result = $this->walk_incomplete_object_with_snapshot( $value, $snapshot, $depth );
			if ( '' === $result['skip_reason'] && 0 < $result['count'] ) {
				$value = $result['value'];
			}

			return $result;
		}

		if ( $value instanceof stdClass && $snapshot instanceof stdClass ) {
			$result = $this->unchanged_transform( $value );
			foreach ( array_keys( get_object_vars( $value ) ) as $key ) {
				$item        =& $value->{$key};
				$item_result = $this->walk_value_with_snapshot( $item, $snapshot->{$key}, $depth + 1 );
				$result      = $this->merge_child_result( $result, $item_result );
				if ( '' !== $result['skip_reason'] ) {
					unset( $item );

					return $result;
				}
				unset( $item );
			}
			$result['value'] = $value;

			return $result;
		}

		return $this->unchanged_transform( $value );
	}

	/**
	 * Walks an incomplete object's property graph without loading its class.
	 *
	 * @param __PHP_Incomplete_Class $value Incomplete object representation.
	 * @param int                    $depth Current structure depth.
	 * @return array{value:mixed,count:int,formats:string[],skip_reason:string}
	 */
	private function walk_incomplete_object( __PHP_Incomplete_Class $value, $depth ) {
		$properties = (array) $value;
		$class_name = isset( $properties['__PHP_Incomplete_Class_Name'] ) ? $properties['__PHP_Incomplete_Class_Name'] : '';
		unset( $properties['__PHP_Incomplete_Class_Name'] );

		$result = $this->unchanged_transform( $value );
		foreach ( $properties as &$item ) {
			$item_result = $this->walk_value( $item, $depth + 1 );
			$result      = $this->merge_child_result( $result, $item_result );
			if ( '' !== $result['skip_reason'] ) {
				unset( $item );

				return $result;
			}
		}
		unset( $item );

		if ( 0 === $result['count'] ) {
			return $result;
		}

		// A single serialization pass retains reference identifiers across properties.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Preserve the property graph.
		$serialized_properties = serialize( $properties );
		$header_length         = strpos( $serialized_properties, '{' ) + 1;
		$serialized            = 'O:' . strlen( $class_name ) . ':"' . $class_name . '":' . count( $properties ) . ':{' . substr( $serialized_properties, $header_length );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Keep the class marker without loading it.
		$result['value'] = @unserialize( $serialized, array( 'allowed_classes' => false ) );

		return $result;
	}

	/**
	 * Walks an incomplete object against an unmodified snapshot.
	 *
	 * @param __PHP_Incomplete_Class $value    Live incomplete object representation.
	 * @param __PHP_Incomplete_Class $snapshot Original incomplete object representation.
	 * @param int                    $depth    Current structure depth.
	 * @return array{value:mixed,count:int,formats:string[],skip_reason:string}
	 */
	private function walk_incomplete_object_with_snapshot( __PHP_Incomplete_Class $value, __PHP_Incomplete_Class $snapshot, $depth ) {
		$properties          = (array) $value;
		$snapshot_properties = (array) $snapshot;
		$class_name          = isset( $properties['__PHP_Incomplete_Class_Name'] ) ? $properties['__PHP_Incomplete_Class_Name'] : '';
		unset( $properties['__PHP_Incomplete_Class_Name'], $snapshot_properties['__PHP_Incomplete_Class_Name'] );

		$result = $this->unchanged_transform( $value );
		foreach ( $properties as $key => &$item ) {
			$item_result = $this->walk_value_with_snapshot( $item, $snapshot_properties[ $key ], $depth + 1 );
			$result      = $this->merge_child_result( $result, $item_result );
			if ( '' !== $result['skip_reason'] ) {
				unset( $item );

				return $result;
			}
		}
		unset( $item );

		if ( 0 === $result['count'] ) {
			return $result;
		}

		// A single serialization pass retains reference identifiers across properties.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Preserve the property graph.
		$serialized_properties = serialize( $properties );
		$header_length         = strpos( $serialized_properties, '{' ) + 1;
		$serialized            = 'O:' . strlen( $class_name ) . ':"' . $class_name . '":' . count( $properties ) . ':{' . substr( $serialized_properties, $header_length );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Keep the class marker without loading it.
		$result['value'] = @unserialize( $serialized, array( 'allowed_classes' => false ) );

		return $result;
	}

	/**
	 * Performs plain replacement with occurrence-level exclusions.
	 *
	 * @param string $value Plain string.
	 * @return array{value:mixed,count:int,formats:string[],skip_reason:string}
	 */
	private function transform_plain( $value ) {
		$working      = $value;
		$masked       = array();
		$token_suffix = '';

		foreach ( $this->config->get_exclusions() as $exclusion ) {
			if ( '' === $exclusion || ! $this->has_search_match( $exclusion ) || false === strpos( $working, $exclusion ) ) {
				continue;
			}

			$token         = "\x00SAFESR_PROTECTED{$token_suffix}\x00";
			$token_sources = array_merge(
				array(
					$value,
					$working,
					$this->config->get_search(),
					$this->config->get_replace(),
				),
				$this->config->get_exclusions()
			);
			while ( $this->token_appears_in_sources( $token, $token_sources ) ) {
				$token_suffix .= '_';
				$token         = "\x00SAFESR_PROTECTED{$token_suffix}\x00";
			}

			$working          = str_replace( $exclusion, $token, $working );
			$masked[ $token ] = $exclusion;
			$token_suffix    .= '_';
		}

		$segments = array( $working );
		if ( ! empty( $masked ) ) {
			$escaped_tokens = array();
			foreach ( array_keys( $masked ) as $token ) {
				$escaped_tokens[] = preg_quote( $token, '~' );
			}

			$segments = preg_split( '~(' . implode( '|', $escaped_tokens ) . ')~', $working, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
			if ( false === $segments ) {
				return $this->unchanged_transform( $value );
			}
		}

		$count   = 0;
		$working = '';
		foreach ( $segments as $segment ) {
			if ( isset( $masked[ $segment ] ) ) {
				$working .= $segment;
				continue;
			}

			$segment_result = $this->replace_plain_segment( $segment );
			if ( null === $segment_result['value'] ) {
				return $this->unchanged_transform( $value );
			}

			$working .= $segment_result['value'];
			$count   += $segment_result['count'];
		}

		if ( ! empty( $masked ) ) {
			$working = str_replace( array_keys( $masked ), array_values( $masked ), $working );
		}

		if ( 0 === $count ) {
			return $this->unchanged_transform( $value );
		}

		return array(
			'value'       => $working,
			'count'       => $count,
			'formats'     => array( 'plain' ),
			'skip_reason' => '',
		);
	}

	/**
	 * Replaces matches within one unprotected plain-text segment.
	 *
	 * @param string $value Unprotected text segment.
	 * @return array{value:string|null,count:int}
	 */
	private function replace_plain_segment( $value ) {
		$count = 0;
		if ( $this->config->is_regex() ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Leave invalid runtime matches untouched.
			$replaced = @preg_replace( $this->config->get_pattern(), $this->config->get_replace(), $value, -1, $count );

			return array(
				'value' => $replaced,
				'count' => $count,
			);
		}

		if ( $this->config->is_case_sensitive() ) {
			$replaced = str_replace( $this->config->get_search(), $this->config->get_replace(), $value, $count );
		} else {
			$replaced = str_ireplace( $this->config->get_search(), $this->config->get_replace(), $value, $count );
		}

		return array(
			'value' => $replaced,
			'count' => $count,
		);
	}

	/**
	 * Reports whether decoded base64 bytes can be traversed safely.
	 *
	 * @param string $decoded Decoded bytes.
	 * @return bool
	 */
	private function decoded_value_is_meaningful( $decoded ) {
		if ( Value_Format::looks_serialized( $decoded ) || Value_Format::looks_json( $decoded ) ) {
			return true;
		}

		if ( 1 !== preg_match( '//u', $decoded ) ) {
			return false;
		}

		$printable = str_replace( array( "\t", "\n", "\r" ), '', $decoded );

		return 0 === preg_match( '/\p{C}/u', $printable );
	}

	/**
	 * Reports whether a token occurs in any reserved source.
	 *
	 * @param string   $token   Candidate mask token.
	 * @param string[] $sources Values that must not contain the token.
	 * @return bool
	 */
	private function token_appears_in_sources( $token, array $sources ) {
		foreach ( $sources as $source ) {
			if ( false !== strpos( $source, $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reports whether a JSON object repeats a decoded member name.
	 *
	 * @param string $value JSON document.
	 * @return bool
	 */
	private function json_has_duplicate_keys( $value ) {
		$length = strlen( $value );
		$scopes = array();

		for ( $index = 0; $index < $length; ++$index ) {
			$character = $value[ $index ];
			if ( '{' === $character ) {
				$scopes[] = array(
					'type' => 'object',
					'keys' => array(),
				);
				continue;
			}

			if ( '[' === $character ) {
				$scopes[] = array(
					'type' => 'array',
					'keys' => array(),
				);
				continue;
			}

			if ( '}' === $character || ']' === $character ) {
				array_pop( $scopes );
				continue;
			}

			if ( '"' !== $character ) {
				continue;
			}

			$string_start = $index;
			$string_end   = $index + 1;
			for ( ; $string_end < $length; ++$string_end ) {
				if ( '\\' === $value[ $string_end ] ) {
					++$string_end;
					continue;
				}

				if ( '"' === $value[ $string_end ] ) {
					break;
				}
			}
			$index = $string_end;

			$next = $string_end + 1;
			while ( $next < $length && false !== strpos( " \t\r\n", $value[ $next ] ) ) {
				++$next;
			}

			$scope_index = count( $scopes ) - 1;
			if ( 0 > $scope_index || 'object' !== $scopes[ $scope_index ]['type'] || $next >= $length || ':' !== $value[ $next ] ) {
				continue;
			}

			$key = json_decode( substr( $value, $string_start, $string_end - $string_start + 1 ) );
			if ( ! is_string( $key ) ) {
				continue;
			}

			if ( array_key_exists( $key, $scopes[ $scope_index ]['keys'] ) ) {
				return true;
			}
			$scopes[ $scope_index ]['keys'][ $key ] = true;
		}

		return false;
	}

	/**
	 * Reports whether plain text contains a configured match.
	 *
	 * @param string $value Candidate text.
	 * @return bool
	 */
	private function has_search_match( $value ) {
		if ( $this->config->is_regex() ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid probes are not errors here.
			return 1 === @preg_match( $this->config->get_pattern(), $value );
		}

		if ( $this->config->is_case_sensitive() ) {
			return false !== strpos( $value, $this->config->get_search() );
		}

		return false !== stripos( $value, $this->config->get_search() );
	}

	/**
	 * Merges count, formats, and safety state from a child traversal.
	 *
	 * @param array{value:mixed,count:int,formats:string[],skip_reason:string} $result Current aggregate.
	 * @param array{value:mixed,count:int,formats:string[],skip_reason:string} $child  Child traversal result.
	 * @return array{value:mixed,count:int,formats:string[],skip_reason:string}
	 */
	private function merge_child_result( array $result, array $child ) {
		$result['count']  += $child['count'];
		$result['formats'] = array_merge( $result['formats'], $child['formats'] );
		if ( '' !== $child['skip_reason'] ) {
			$result['skip_reason'] = $child['skip_reason'];
		}

		return $result;
	}

	/**
	 * Creates an unchanged internal traversal result.
	 *
	 * @param mixed $value Original value.
	 * @return array{value:mixed,count:int,formats:string[],skip_reason:string}
	 */
	private function unchanged_transform( $value ) {
		return array(
			'value'       => $value,
			'count'       => 0,
			'formats'     => array(),
			'skip_reason' => '',
		);
	}

	/**
	 * Skipped traversal state.
	 *
	 * @param string $reason Machine-readable skip reason.
	 * @return array{value:mixed,count:int,formats:string[],skip_reason:string}
	 */
	private function skipped_transform( $reason ) {
		return array(
			'value'       => '',
			'count'       => 0,
			'formats'     => array(),
			'skip_reason' => $reason,
		);
	}
}
