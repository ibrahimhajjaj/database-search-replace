<?php
/**
 * Integration tests for CSV change logs.
 *
 * @package SafeSearchReplace
 */

use SafeSR\Db\Change;
use SafeSR\Db\Change_Log;
use SafeSR\Db\Diff_Builder;

/**
 * Verifies private upload storage and CSV contents.
 */
class SafeSR_Change_Log_Test extends SafeSR_Integration_Test_Case {

	/**
	 * A change log is rendered in memory with complete fields.
	 *
	 * @return void
	 */
	public function test_csv_is_rendered_without_a_public_file() {
		$diff   = ( new Diff_Builder() )->build( 'old value', 'new value' );
		$change = new Change( 'wp_posts', 'post_content', '42', 'old value', 'new value', $diff, array( 'plain' ), false, '', 1 );
		$csv    = ( new Change_Log() )->render( 'job-123', array( $change ) );

		$this->assertStringContainsString( 'job-123,wp_posts,post_content,42', $csv );
		$this->assertStringContainsString( 'old value', $csv );
		$this->assertStringContainsString( 'new value', $csv );
	}

	/**
	 * Formula-leading database excerpts are neutralized for spreadsheets.
	 *
	 * @return void
	 */
	public function test_formula_leading_cells_are_neutralized() {
		$diff   = ( new Diff_Builder() )->build( '=HYPERLINK("old")', '+cmd' );
		$change = new Change( 'wp_posts', 'post_content', '42', '=HYPERLINK("old")', '+cmd', $diff, array( 'plain' ), false, '', 1 );
		$csv    = ( new Change_Log() )->render( 'job-123', array( $change ) );

		$this->assertStringContainsString( "'=HYPERLINK", $csv );
		$this->assertStringContainsString( "'+cmd", $csv );
	}
}
