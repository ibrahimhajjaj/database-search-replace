<?php
/**
 * Integration tests for batched database row processing.
 *
 * @package SafeSearchReplace
 */

use SafeSR\Db\Batch_Result;
use SafeSR\Db\Change;
use SafeSR\Db\Row_Processor;
use SafeSR\Db\Run_Config;
use SafeSR\Engine\Replace_Result;

require_once dirname( __DIR__ ) . '/fixtures/class-northwind-fixture.php';

/**
 * Verifies dry-run and apply behavior across representative WordPress data.
 */
class SafeSR_Row_Processor_Test extends SafeSR_Integration_Test_Case {

	/**
	 * Seeded Northwind data.
	 *
	 * @var SafeSR_Northwind_Fixture
	 */
	private SafeSR_Northwind_Fixture $fixture;

	/**
	 * Seeds Northwind data for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->fixture = new SafeSR_Northwind_Fixture( self::factory() );
	}

	/**
	 * Literal collection finds plain, serialized, and JSON-escaped candidates.
	 *
	 * @return void
	 */
	public function test_collect_finds_northwind_changes_and_policy_skips() {
		global $wpdb;
		$results = $this->process_all( $this->config( true, 500 ) );
		$changes = $this->changes( $results );
		$this->assertCount( 8, $changes );
		$this->assertSame( 6, $this->replacement_total( $results ) );
		$elementor = $this->find_change( $changes, $wpdb->postmeta, 'meta_value', 'http:\/\/staging' );
		$this->assertNotNull( $elementor );
		$this->assertStringContainsString( 'https:\/\/northwindcoffee.com', $elementor->get_new_value() );

		$hero = $this->find_change( $changes, $wpdb->posts, 'post_content', 'hero.jpg' );
		$this->assertNotNull( $hero );
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
					't' => 'northwindcoffee.com/wp-content/uploads/hero.jpg">',
					'k' => 'ctx',
				),
			),
			$hero->get_before_excerpt()
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
					't' => 'northwindcoffee.com/wp-content/uploads/hero.jpg">',
					'k' => 'ctx',
				),
			),
			$hero->get_after_excerpt()
		);

		$guid = $this->find_change( $changes, $wpdb->posts, 'guid', '?p=100' );
		$this->assertTrue( $guid->is_skipped() );
		$this->assertSame( Change::SKIP_GUID, $guid->get_skip_reason() );

		$user = $this->find_change( $changes, $wpdb->users, 'user_url', '/owner' );
		$this->assertTrue( $user->is_skipped() );
		$this->assertSame( Change::SKIP_PROTECTED_TABLE, $user->get_skip_reason() );
	}

	/**
	 * Apply writes safe values and records originals before each changed row.
	 *
	 * @return void
	 */
	public function test_apply_writes_values_and_records_original_rows() {
		global $wpdb;
		$original_guid = get_post_field( 'guid', $this->fixture->post_id, 'raw' );
		$original_user = get_userdata( $this->fixture->user_id )->user_url;
		$recorded      = array();
		$processor     = new Row_Processor(
			static function ( string $table, array $primary_key, array $values ) use ( &$recorded ): void {
				$recorded[] = array(
					'table'       => $table,
					'primary_key' => $primary_key,
					'values'      => $values,
				);
			}
		);

		$this->process_all( $this->config( false, 500 ), $processor );

		$siteurl = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'siteurl' ) );
		$this->assertSame( SafeSR_Northwind_Fixture::NEW_URL, $siteurl );
		$this->assertSame( SafeSR_Northwind_Fixture::NEW_URL . '/logo.png', get_option( 'theme_mods_northwind' )['logo'] );
		$elementor = get_post_meta( $this->fixture->post_id, '_elementor_data', true );
		$this->assertNotNull( json_decode( $elementor, true ) );
		$this->assertStringContainsString( 'https:\/\/northwindcoffee.com', $elementor );
		$this->assertSame( $original_guid, get_post_field( 'guid', $this->fixture->post_id, 'raw' ) );
		$this->assertSame( $original_user, get_userdata( $this->fixture->user_id )->user_url );
		$this->assertCount( 6, $recorded );
		$recorded_values = array();
		foreach ( array_column( $recorded, 'values' ) as $values ) {
			$recorded_values = array_merge( $recorded_values, array_values( $values ) );
		}
		$this->assertContains( SafeSR_Northwind_Fixture::OLD_URL, $recorded_values );
	}

	/**
	 * Malformed serialized data is reported and remains unchanged in apply mode.
	 *
	 * @return void
	 */
	public function test_malformed_serialized_value_is_reported_without_write() {
		global $wpdb;
		$value = 'a:1:{i:0;s:99:"' . SafeSR_Northwind_Fixture::OLD_URL . '";}';
		add_option( 'safesr_malformed_fixture', $value );

		$changes = $this->changes( $this->process_all( $this->config( false, 500 ) ) );
		$change  = $this->find_change( $changes, $wpdb->options, 'option_value', 's:99' );

		$this->assertNotNull( $change );
		$this->assertTrue( $change->is_skipped() );
		$this->assertSame( Replace_Result::SKIP_MALFORMED_SERIALIZED, $change->get_skip_reason() );
		$this->assertSame( $value, get_option( 'safesr_malformed_fixture' ) );
	}

	/**
	 * One-row batches reach the same totals through monotonic cursors.
	 *
	 * @return void
	 */
	public function test_batch_size_one_matches_one_shot_totals_with_monotonic_cursors() {
		global $wpdb;
		$one_shot = $this->process_table( new Row_Processor(), $this->config( true, 500 ), $wpdb->options );
		$batched  = $this->process_table( new Row_Processor(), $this->config( true, 1 ), $wpdb->options );
		$cursors  = array_values( array_filter( array_map( static fn( Batch_Result $result ): ?string => $result->get_next_cursor(), $batched ) ) );

		$this->assertSame( $this->replacement_total( $one_shot ), $this->replacement_total( $batched ) );
		$this->assertSame( count( $this->changes( $one_shot ) ), count( $this->changes( $batched ) ) );
		$this->assertSame( $cursors, array_values( array_unique( $cursors ) ) );
		$this->assertTrue( end( $batched )->is_done() );
	}

	/**
	 * Regex collection scans post content without a SQL literal prefilter.
	 *
	 * @return void
	 */
	public function test_regex_collect_finds_post_content() {
		global $wpdb;
		$config  = new Run_Config( SafeSR_Northwind_Fixture::search_config( true ), array( $wpdb->posts ), false, false, 500, true );
		$changes = $this->changes( $this->process_all( $config ) );

		$this->assertNotNull( $this->find_change( $changes, $wpdb->posts, 'post_content', 'hero.jpg' ) );
	}

	/**
	 * Base64-wrapped values reach the engine without an opt-in thorough scan.
	 *
	 * @return void
	 */
	public function test_default_scan_finds_base64_wrapped_values() {
		global $wpdb;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The fixture verifies advertised base64 traversal.
		$encoded = base64_encode( SafeSR_Northwind_Fixture::OLD_URL . '/encoded' );
		add_option( 'safesr_base64_fixture', $encoded );
		$config  = new Run_Config(
			SafeSR_Northwind_Fixture::search_config(),
			array( $wpdb->options ),
			false,
			false,
			500,
			true
		);
		$changes = $this->changes( $this->process_all( $config ) );
		$change  = $this->find_change( $changes, $wpdb->options, 'option_value', $encoded );

		$this->assertNotNull( $change );
		$this->assertContains( 'base64', $change->get_formats() );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The decoded assertion verifies the stored wrapper was preserved.
		$this->assertSame( SafeSR_Northwind_Fixture::NEW_URL . '/encoded', base64_decode( $change->get_new_value(), true ) );
	}

	/**
	 * A concurrent edit wins the compare-and-swap and removes its unused backup.
	 *
	 * @return void
	 */
	public function test_apply_reports_concurrent_edit_and_rolls_back_backup() {
		global $wpdb;
		$table = $wpdb->prefix . 'ssr_cas_fixture';
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id BIGINT UNSIGNED NOT NULL, content TEXT NOT NULL, PRIMARY KEY (id))', $table ) );
		$wpdb->insert(
			$table,
			array(
				'id'      => 1,
				'content' => 'old',
			)
		);
		$rolled_back = array();
		$processor   = new Row_Processor(
			static function () use ( $wpdb, $table ) {
				$wpdb->update( $table, array( 'content' => 'concurrent edit' ), array( 'id' => 1 ) );
				return 91;
			},
			null,
			null,
			static function ( $backup_id ) use ( &$rolled_back ): void {
				$rolled_back[] = $backup_id;
			}
		);
		$config      = new Run_Config( new \SafeSR\Engine\Search_Config( 'old', 'new', true ), array( $table ), false, false, 50, false );
		$result      = $processor->process_table_batch( $config, $table, null );
		$current     = $wpdb->get_var( $wpdb->prepare( 'SELECT content FROM %i WHERE id = %d', $table, 1 ) );

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		$this->assertSame( 'concurrent edit', $current );
		$this->assertSame( array( 91 ), $rolled_back );
		$this->assertContains( 'conflict:1:content', $result->get_errors() );
	}

	/**
	 * Transactional writes use SQL supported by MySQL and MariaDB.
	 *
	 * @return void
	 */
	public function test_apply_uses_portable_transaction_control() {
		global $wpdb;
		$table = $wpdb->prefix . 'ssr_transaction_fixture';
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id BIGINT UNSIGNED NOT NULL, content TEXT NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB', $table ) );
		$wpdb->insert(
			$table,
			array(
				'id'      => 1,
				'content' => 'old',
			)
		);
		$queries = array();
		$record  = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record );

		$config = new Run_Config( new \SafeSR\Engine\Search_Config( 'old', 'new', true ), array( $table ), false, false, 50, false );
		( new Row_Processor() )->process_table_batch( $config, $table, null );

		remove_filter( 'query', $record );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		$this->assertContains( 'START TRANSACTION', $queries );
		$this->assertContains( 'COMMIT', $queries );
		$this->assertNotContains( 'SELECT @@in_transaction', $queries );
	}

	/**
	 * Builds the standard run configuration.
	 *
	 * @param bool $dry_run    Whether writes are disabled.
	 * @param int  $batch_size Rows per batch.
	 * @return Run_Config
	 */
	private function config( bool $dry_run, int $batch_size ): Run_Config {
		global $wpdb;
		return new Run_Config(
			SafeSR_Northwind_Fixture::search_config(),
			array( $wpdb->options, $wpdb->posts, $wpdb->postmeta, $wpdb->users ),
			false,
			false,
			$batch_size,
			$dry_run
		);
	}

	/**
	 * Processes every selected table to completion.
	 *
	 * @param Run_Config         $config    Run configuration.
	 * @param Row_Processor|null $processor Optional shared processor.
	 * @return Batch_Result[]
	 */
	private function process_all( Run_Config $config, ?Row_Processor $processor = null ): array {
		$processor = $processor ?? new Row_Processor();
		$results   = array();
		foreach ( $config->get_tables() as $table ) {
			$results = array_merge( $results, $this->process_table( $processor, $config, $table ) );
		}
		return $results;
	}

	/**
	 * Processes one table to completion.
	 *
	 * @param Row_Processor $processor Row processor.
	 * @param Run_Config    $config    Run configuration.
	 * @param string        $table     Physical table name.
	 * @return Batch_Result[]
	 */
	private function process_table( Row_Processor $processor, Run_Config $config, string $table ): array {
		$results = array();
		$cursor  = null;
		do {
			$result    = $processor->process_table_batch( $config, $table, $cursor );
			$results[] = $result;
			$cursor    = $result->get_next_cursor();
		} while ( ! $result->is_done() );
		return $results;
	}

	/**
	 * Flattens sampled changes from batch results.
	 *
	 * @param Batch_Result[] $results Batch results.
	 * @return Change[]
	 */
	private function changes( array $results ): array {
		$changes = array();
		foreach ( $results as $result ) {
			$changes = array_merge( $changes, $result->get_changes() );
		}
		return $changes;
	}

	/**
	 * Totals replacements across batch results.
	 *
	 * @param Batch_Result[] $results Batch results.
	 * @return int
	 */
	private function replacement_total( array $results ): int {
		return array_sum( array_map( static fn( Batch_Result $result ): int => $result->get_replacements_count(), $results ) );
	}

	/**
	 * Finds a change by storage location and original value fragment.
	 *
	 * @param Change[] $changes Change records.
	 * @param string   $table   Physical table name.
	 * @param string   $column  Column name.
	 * @param string   $needle  Original value fragment.
	 * @return Change|null
	 */
	private function find_change( array $changes, string $table, string $column, string $needle ): ?Change {
		foreach ( $changes as $change ) {
			if ( $table === $change->get_table() && $column === $change->get_column() && false !== strpos( $change->get_old_value(), $needle ) ) {
				return $change;
			}
		}
		return null;
	}
}
