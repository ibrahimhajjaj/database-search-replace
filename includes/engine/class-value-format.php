<?php
/**
 * Stored value format detection.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Engine;

/**
 * Provides conservative format detection without WordPress dependencies.
 */
class Value_Format {

	/**
	 * Reports whether a value has PHP serialized structure.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function looks_serialized( string $value ): bool {
		$value = trim( $value );

		if ( 'N;' === $value ) {
			return true;
		}

		$length = strlen( $value );
		if ( 4 > $length || ':' !== $value[1] ) {
			return false;
		}

		$last_character = $value[ $length - 1 ];
		if ( ';' !== $last_character && '}' !== $last_character ) {
			return false;
		}

		$token = $value[0];
		switch ( $token ) {
			case 's':
				if ( '"' !== $value[ $length - 2 ] ) {
					return false;
				}
				// Fall through because string and container tokens share the length-prefix check.
			case 'a':
			case 'O':
			case 'C':
			case 'E':
				return 1 === preg_match( "/^{$token}:[0-9]+:/s", $value );
			case 'b':
			case 'i':
				return 1 === preg_match( "/^{$token}:[0-9.E+-]+;$/", $value );
			case 'd':
				return 1 === preg_match( '/^d:(?:[0-9.E+-]+|NAN|INF|-INF);$/', $value );
		}

		return false;
	}

	/**
	 * Reports whether a value is a JSON object or array document.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function looks_json( string $value ): bool {
		$trimmed = ltrim( $value );
		if ( '' === $trimmed || ( '{' !== $trimmed[0] && '[' !== $trimmed[0] ) ) {
			return false;
		}

		json_decode( $value );

		return JSON_ERROR_NONE === json_last_error();
	}

	/**
	 * Reports whether a value is canonical base64 with a conservative length.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function is_strict_base64( string $value ): bool {
		$length = strlen( $value );
		if ( 24 > $length || 0 !== $length % 4 || 1 !== preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/', $value ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Inspect stored base64 values.
		$decoded = base64_decode( $value, true );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Reject noncanonical encodings.
		return false !== $decoded && base64_encode( $decoded ) === $value;
	}
}
