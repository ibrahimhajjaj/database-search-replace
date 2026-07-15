<?php
/**
 * Search configuration value object.
 *
 * @package SafeSearchReplace
 */

namespace SafeSR\Engine;

use InvalidArgumentException;

/**
 * Stores validated replacement configuration.
 */
class Search_Config {

	/**
	 * Search text or regular expression body.
	 *
	 * @var string
	 */
	private string $search;

	/**
	 * Replacement text.
	 *
	 * @var string
	 */
	private string $replace;

	/**
	 * Whether matching is case-sensitive.
	 *
	 * @var bool
	 */
	private bool $case_sensitive;

	/**
	 * Whether search text is a regular expression body.
	 *
	 * @var bool
	 */
	private bool $regex;

	/**
	 * Literal values protected from replacement.
	 *
	 * @var string[]
	 */
	private array $exclusions;

	/**
	 * Compiled regular expression including delimiter and flags.
	 *
	 * @var string
	 */
	private string $pattern = '';

	/**
	 * Creates validated replacement configuration.
	 *
	 * @param string   $search         Search text or regular expression body.
	 * @param string   $replace        Replacement text.
	 * @param bool     $case_sensitive Whether matching is case-sensitive.
	 * @param bool     $regex          Whether search is a regular expression body.
	 * @param string[] $exclusions     Literal values protected from replacement.
	 * @throws InvalidArgumentException When search text is empty or a regular expression is invalid.
	 */
	public function __construct( string $search, string $replace = '', bool $case_sensitive = false, bool $regex = false, array $exclusions = array() ) {
		if ( '' === $search ) {
			throw new InvalidArgumentException( 'Search text cannot be empty.' );
		}

		$this->search         = $search;
		$this->replace        = $replace;
		$this->case_sensitive = $case_sensitive;
		$this->regex          = $regex;
		$this->exclusions     = $exclusions;

		if ( $this->regex ) {
			$delimiter = $this->choose_delimiter( $this->search );
			$body      = $this->search;
			if ( '' === $delimiter ) {
				$delimiter = '~';
				$body      = $this->escape_delimiter( $body, $delimiter );
			}
			$this->pattern = $delimiter . $body . $delimiter . ( $this->case_sensitive ? '' : 'i' );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- User patterns may be invalid.
			if ( false === @preg_match( $this->pattern, '' ) ) {
				throw new InvalidArgumentException( 'Search pattern is not a valid regular expression.' );
			}
		}
	}

	/**
	 * Returns search text or the regular expression body.
	 *
	 * @return string
	 */
	public function get_search(): string {
		return $this->search;
	}

	/**
	 * Returns replacement text.
	 *
	 * @return string
	 */
	public function get_replace(): string {
		return $this->replace;
	}

	/**
	 * Returns whether matching is case-sensitive.
	 *
	 * @return bool
	 */
	public function is_case_sensitive(): bool {
		return $this->case_sensitive;
	}

	/**
	 * Returns whether search text is a regular expression body.
	 *
	 * @return bool
	 */
	public function is_regex(): bool {
		return $this->regex;
	}

	/**
	 * Returns literal values protected from replacement.
	 *
	 * @return string[]
	 */
	public function get_exclusions(): array {
		return $this->exclusions;
	}

	/**
	 * Returns the validated regular expression including delimiter and flags.
	 *
	 * @return string
	 */
	public function get_pattern(): string {
		return $this->pattern;
	}

	/**
	 * Chooses a delimiter absent from the regular expression body.
	 *
	 * @param string $search Regular expression body.
	 * @return string
	 */
	private function choose_delimiter( string $search ): string {
		$delimiters = array( '~', '#', '%', '!', '@', ';', '`', chr( 1 ), chr( 2 ), chr( 3 ) );
		foreach ( $delimiters as $delimiter ) {
			if ( false === strpos( $search, $delimiter ) ) {
				return $delimiter;
			}
		}

		return '';
	}

	/**
	 * Escapes delimiter bytes that are not already escaped.
	 *
	 * @param string $search    Regular expression body.
	 * @param string $delimiter Pattern delimiter.
	 * @return string
	 */
	private function escape_delimiter( string $search, string $delimiter ): string {
		$escaped = '';
		$length  = strlen( $search );
		for ( $index = 0; $index < $length; ++$index ) {
			if ( $delimiter === $search[ $index ] ) {
				$backslashes = 0;
				for ( $lookbehind = $index - 1; 0 <= $lookbehind && '\\' === $search[ $lookbehind ]; --$lookbehind ) {
					++$backslashes;
				}
				if ( 0 === $backslashes % 2 ) {
					$escaped .= '\\';
				}
			}
			$escaped .= $search[ $index ];
		}

		return $escaped;
	}
}
