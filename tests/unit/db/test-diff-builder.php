<?php
/**
 * Unit tests for database diff excerpts.
 *
 * @package SafeSearchReplace
 */

use PHPUnit\Framework\TestCase;
use SafeSR\Db\Diff_Builder;

/**
 * Verifies compact, multibyte-safe diff construction.
 */
class SafeSR_Diff_Builder_Test extends TestCase {

	/**
	 * A URL replacement retains context and labels changed spans.
	 *
	 * @return void
	 */
	public function test_url_replacement_has_exact_context_and_change_segments() {
		$diff = ( new Diff_Builder() )->build(
			'<img src="http://staging.northwind.test/hero.jpg">',
			'<img src="https://northwind.test/hero.jpg">'
		);

		$this->assertSame(
			array(
				array(
					't' => '<img src="http',
					'k' => 'ctx',
				),
				array(
					't' => '://staging.',
					'k' => 'del',
				),
				array(
					't' => 'northwind.test/hero.jpg">',
					'k' => 'ctx',
				),
			),
			$diff['before']
		);
		$this->assertSame(
			array(
				array(
					't' => '<img src="http',
					'k' => 'ctx',
				),
				array(
					't' => 's://',
					'k' => 'add',
				),
				array(
					't' => 'northwind.test/hero.jpg">',
					'k' => 'ctx',
				),
			),
			$diff['after']
		);
		$this->assertFalse( $diff['truncated'] );
		$this->assertSame( 50, $diff['before_length'] );
		$this->assertSame( 43, $diff['after_length'] );
	}

	/**
	 * Distant replacements remain separate regions with intervening context.
	 *
	 * @return void
	 */
	public function test_distant_changes_are_separated_by_context() {
		$middle = str_repeat( 'middle ', 20 );
		$diff   = ( new Diff_Builder() )->build( 'old ' . $middle . 'old', 'new ' . $middle . 'new' );

		$this->assertSame( 2, count( array_filter( $diff['before'], static fn( array $segment ): bool => 'del' === $segment['k'] ) ) );
		$this->assertSame( 2, count( array_filter( $diff['after'], static fn( array $segment ): bool => 'add' === $segment['k'] ) ) );
	}

	/**
	 * Context windows do not split UTF-8 code points.
	 *
	 * @return void
	 */
	public function test_context_windows_preserve_valid_utf8() {
		$diff = ( new Diff_Builder( 3 ) )->build( 'قهوة قديمة رائعة', 'قهوة جديدة رائعة' );

		foreach ( array_merge( $diff['before'], $diff['after'] ) as $segment ) {
			$this->assertSame( 1, preg_match( '//u', $segment['t'] ) );
		}
	}

	/**
	 * Excerpts are capped independently from source value lengths.
	 *
	 * @return void
	 */
	public function test_large_excerpts_are_capped() {
		$diff = ( new Diff_Builder( 60, 256 ) )->build( str_repeat( 'a', 2000 ), str_repeat( 'b', 2000 ) );

		$this->assertTrue( $diff['truncated'] );
		$this->assertLessThanOrEqual( 256, strlen( implode( '', array_column( $diff['before'], 't' ) ) ) );
		$this->assertLessThanOrEqual( 256, strlen( implode( '', array_column( $diff['after'], 't' ) ) ) );
		$this->assertSame( 2000, $diff['before_length'] );
		$this->assertSame( 2000, $diff['after_length'] );
	}
}
