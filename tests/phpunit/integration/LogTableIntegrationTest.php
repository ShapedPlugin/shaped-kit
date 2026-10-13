<?php
/**
 * LogTable integration tests: table creation via dbDelta and drop on uninstall.
 *
 * @package ShapedKit
 */

use ShapedKit\Audit\LogTable;

/**
 * Real WordPress integration tests running against the test MySQL database.
 */
final class LogTableIntegrationTest extends WP_UnitTestCase {

	/**
	 * Clean up log table and options before and after each test.
	 *
	 * Note: WP_UnitTestCase hooks _drop_temporary_tables into the 'query' filter, which
	 * alters DROP TABLE into DROP TEMPORARY TABLE. Removing this filter allows dropping
	 * the real table created by dbDelta.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		LogTable::drop();
		parent::tear_down();
	}

	/**
	 * Verify that install creates the table and sets the schema version option.
	 *
	 * @return void
	 */
	public function test_install_creates_table_and_sets_option(): void {
		global $wpdb;

		LogTable::install();

		$table_name = LogTable::table_name();
		$this->assertTrue( LogTable::exists() );
		$this->assertSame( LogTable::SCHEMA_VERSION, get_option( LogTable::OPTION_SCHEMA_VERSION ) );

		// Check columns.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema verification in integration test.
		$columns = $wpdb->get_col( "DESCRIBE {$table_name}", 0 );
		$this->assertContains( 'id', $columns );
		$this->assertContains( 'created_at', $columns );
		$this->assertContains( 'user_id', $columns );
		$this->assertContains( 'app_password_name', $columns );
		$this->assertContains( 'product', $columns );
		$this->assertContains( 'ability', $columns );
		$this->assertContains( 'outcome', $columns );
		$this->assertContains( 'error_id', $columns );
		$this->assertContains( 'duration_ms', $columns );
		$this->assertContains( 'argument_keys', $columns );
	}

	/**
	 * Verify that maybe_upgrade executes install when schema version option is outdated or missing.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_runs_when_outdated(): void {
		delete_option( LogTable::OPTION_SCHEMA_VERSION );

		LogTable::maybe_upgrade();

		$this->assertTrue( LogTable::exists() );
		$this->assertSame( LogTable::SCHEMA_VERSION, get_option( LogTable::OPTION_SCHEMA_VERSION ) );
	}

	/**
	 * Verify that drop deletes the table and cleans up the schema version option.
	 *
	 * @return void
	 */
	public function test_drop_removes_table_and_option(): void {
		LogTable::install();

		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		LogTable::drop();

		$this->assertFalse( LogTable::exists() );
		$this->assertFalse( get_option( LogTable::OPTION_SCHEMA_VERSION ) );
	}

	/**
	 * Verify that uninstall.php cleans up the table, options, and transients (PRD FR-K8, §19 E37).
	 *
	 * @return void
	 */
	public function test_uninstall_removes_table_and_options(): void {
		LogTable::install();
		update_option( 'shaped_kit_mcp_log_db_version', LogTable::SCHEMA_VERSION );
		set_transient( 'shaped_kit_mcp_log_prune_throttle', 1, 3600 );

		$this->assertTrue( LogTable::exists() );
		$this->assertSame( LogTable::SCHEMA_VERSION, get_option( 'shaped_kit_mcp_log_db_version' ) );
		$this->assertEquals( 1, get_transient( 'shaped_kit_mcp_log_prune_throttle' ) );

		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		require SHAPED_KIT_DIR . 'uninstall.php';

		$this->assertFalse( LogTable::exists() );
		$this->assertFalse( get_option( 'shaped_kit_mcp_log_db_version' ) );
		$this->assertFalse( get_transient( 'shaped_kit_mcp_log_prune_throttle' ) );
	}
}
