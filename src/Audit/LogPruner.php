<?php
/**
 * Retention policy and automated pruning for the MCP audit log.
 *
 * Implements FR-S3 (90-day retention) and PRD §19 E33 (100,000 row flood cap).
 * Handles daily WP-Cron scheduling (`shaped_kit_daily_prune_mcp_log`) and opportunistic
 * dashboard pruning when WP-Cron is disabled (PRD §19 E34).
 *
 * @package ShapedKit\Audit
 */

namespace ShapedKit\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Manages retention limits and pruning routines for {$wpdb->prefix}shaped_kit_mcp_log.
 */
final class LogPruner {

	/**
	 * WP-Cron action hook name for daily log pruning.
	 */
	public const CRON_HOOK = 'shaped_kit_daily_prune_mcp_log';

	/**
	 * Default retention duration in days (PRD FR-S3).
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Maximum number of rows allowed before oldest records are pruned (PRD §19 E33).
	 */
	public const MAX_ROWS = 100000;

	/**
	 * Transient key to throttle opportunistic dashboard pruning (PRD §19 E34).
	 */
	public const THROTTLE_TRANSIENT = 'shaped_kit_mcp_log_prune_throttle';

	/**
	 * Minimum seconds between opportunistic prune executions (e.g. 6 hours).
	 */
	public const THROTTLE_SECONDS = 21600;

	/**
	 * Registers cron events and action hooks.
	 *
	 * Verified source:
	 * - wp_schedule_event() in wp-includes/cron.php:212.
	 * - wp_next_scheduled() in wp-includes/cron.php:737.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'prune' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedules the daily cron hook on plugin deactivation.
	 *
	 * Verified source: wp_clear_scheduled_hook() in wp-includes/cron.php:576.
	 *
	 * @return void
	 */
	public static function unregister(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( self::THROTTLE_TRANSIENT );
	}

	/**
	 * Opportunistic prune executed on admin dashboard load if WP-Cron is disabled (PRD §19 E34).
	 * Throttled to run at most once every 6 hours.
	 *
	 * @return bool True if pruning ran, false if throttled or skipped.
	 */
	public static function maybe_prune_opportunistically(): bool {
		if ( get_transient( self::THROTTLE_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::THROTTLE_TRANSIENT, 1, self::THROTTLE_SECONDS );
		self::prune();

		return true;
	}

	/**
	 * Executes both retention pruning (older than 90 days) and flood cap pruning (over 100,000 rows).
	 *
	 * @param int $retention_days Number of days to retain. Defaults to self::RETENTION_DAYS (90).
	 * @param int $max_rows       Maximum rows allowed. Defaults to self::MAX_ROWS (100000).
	 *
	 * @return array {
	 *     @type int $deleted_expired Deleted records older than retention window.
	 *     @type int $deleted_overflow Deleted records exceeding maximum row cap.
	 * }
	 */
	public static function prune( int $retention_days = self::RETENTION_DAYS, int $max_rows = self::MAX_ROWS ): array {
		global $wpdb;

		$table_name = LogTable::table_name();
		if ( ! LogTable::exists() ) {
			return array(
				'deleted_expired'  => 0,
				'deleted_overflow' => 0,
			);
		}

		// 1. Retention Window Pruning (older than N days UTC).
		$cutoff_time     = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$deleted_expired = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk retention prune.
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is computed from trusted prefix and constant.
				"DELETE FROM {$table_name} WHERE created_at < %s",
				$cutoff_time
			)
		);
		if ( false !== $result ) {
			$deleted_expired = (int) $result;
		}

		// 2. Flood Cap Pruning: If total rows exceed max_rows, prune the oldest surplus (PRD §19 E33).
		$deleted_overflow = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		if ( $total_rows > $max_rows ) {
			$surplus = $total_rows - $max_rows;

			// Find cutoff ID for oldest surplus records.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cutoff_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$table_name} ORDER BY id ASC LIMIT 1 OFFSET %d",
					$surplus - 1
				)
			);

			if ( $cutoff_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$overflow_res = $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"DELETE FROM {$table_name} WHERE id <= %d",
						$cutoff_id
					)
				);
				if ( false !== $overflow_res ) {
					$deleted_overflow = (int) $overflow_res;
				}
			}
		}

		return array(
			'deleted_expired'  => $deleted_expired,
			'deleted_overflow' => $deleted_overflow,
		);
	}
}
