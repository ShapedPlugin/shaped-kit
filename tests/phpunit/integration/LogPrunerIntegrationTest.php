<?php
/**
 * LogPruner integration tests: retention window pruning, flood cap, and cron registration.
 *
 * @package ShapedKit
 */

use ShapedKit\Audit\LogPruner;
use ShapedKit\Audit\LogTable;

/**
 * Real WordPress integration tests for audit log retention and pruning.
 */
final class LogPrunerIntegrationTest extends WP_UnitTestCase {

	/**
	 * Clean up table, options, and transients before and after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		LogTable::drop();
		LogPruner::unregister();
		parent::tear_down();
	}

	/**
	 * Setup table and clean environment.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		LogTable::install();
		LogPruner::unregister();
	}

	/**
	 * Helper to insert test log rows with custom created_at dates.
	 *
	 * @param string $created_at Formatted UTC datetime.
	 * @param string $ability    Ability name.
	 *
	 * @return int Inserted ID.
	 */
	private function insert_test_row( string $created_at, string $ability = 'test/ability' ): int {
		global $wpdb;

		$table = LogTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'created_at'        => $created_at,
				'user_id'           => 1,
				'app_password_name' => 'test-client',
				'product'           => 'test-product',
				'ability'           => $ability,
				'outcome'           => 'ok',
				'duration_ms'       => 10,
				'argument_keys'     => '[]',
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Verify that prune removes rows older than the retention threshold (90 days).
	 *
	 * @return void
	 */
	public function test_prunes_records_older_than_retention_window(): void {
		global $wpdb;

		$now         = time();
		$old_date    = gmdate( 'Y-m-d H:i:s', $now - ( 95 * DAY_IN_SECONDS ) ); // 95 days old -> expired.
		$recent_date = gmdate( 'Y-m-d H:i:s', $now - ( 10 * DAY_IN_SECONDS ) ); // 10 days old -> keep.

		$old_id    = $this->insert_test_row( $old_date, 'test/old' );
		$recent_id = $this->insert_test_row( $recent_date, 'test/recent' );

		$stats = LogPruner::prune( 90, 100000 );

		$this->assertSame( 1, $stats['deleted_expired'] );

		$table = LogTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$remaining_ids = $wpdb->get_col( "SELECT id FROM {$table}" );

		$this->assertNotContains( (string) $old_id, $remaining_ids );
		$this->assertContains( (string) $recent_id, $remaining_ids );
	}

	/**
	 * Verify that prune trims excess rows when total count exceeds the max rows limit.
	 *
	 * @return void
	 */
	public function test_prunes_records_exceeding_max_rows_cap(): void {
		global $wpdb;

		$now = time();
		// Insert 5 rows within the valid retention window (all 1 day old).
		$date = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
		$ids  = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$ids[] = $this->insert_test_row( $date, "test/ability-{$i}" );
		}

		// Prune with a max_rows cap of 3.
		$stats = LogPruner::prune( 90, 3 );

		$this->assertSame( 0, $stats['deleted_expired'] );
		$this->assertSame( 2, $stats['deleted_overflow'] ); // 5 - 3 = 2 oldest rows pruned.

		$table = LogTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$remaining_ids = $wpdb->get_col( "SELECT id FROM {$table} ORDER BY id ASC" );

		$this->assertCount( 3, $remaining_ids );
		// The earliest two inserted rows should be pruned first.
		$this->assertNotContains( (string) $ids[0], $remaining_ids );
		$this->assertNotContains( (string) $ids[1], $remaining_ids );
		$this->assertContains( (string) $ids[2], $remaining_ids );
		$this->assertContains( (string) $ids[3], $remaining_ids );
		$this->assertContains( (string) $ids[4], $remaining_ids );
	}

	/**
	 * Verify that register schedules the daily cron and unregister clears it.
	 *
	 * @return void
	 */
	public function test_cron_registration_and_unregistration(): void {
		$this->assertFalse( wp_next_scheduled( LogPruner::CRON_HOOK ) );

		LogPruner::register();
		$next = wp_next_scheduled( LogPruner::CRON_HOOK );
		$this->assertIsInt( $next );

		LogPruner::unregister();
		$this->assertFalse( wp_next_scheduled( LogPruner::CRON_HOOK ) );
	}

	/**
	 * Verify that opportunistic pruning respects the 6-hour throttle transient.
	 *
	 * @return void
	 */
	public function test_opportunistic_pruning_throttle(): void {
		delete_transient( LogPruner::THROTTLE_TRANSIENT );

		$this->assertTrue( LogPruner::maybe_prune_opportunistically() );
		// Second call within throttle window should return false.
		$this->assertFalse( LogPruner::maybe_prune_opportunistically() );
	}
}
