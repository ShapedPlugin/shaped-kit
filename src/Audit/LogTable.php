<?php
/**
 * Database table management for Shaped Kit's MCP audit log.
 *
 * Implements FR-S3 and handles table installation, schema migrations, and table name resolution.
 *
 * @package ShapedKit\Audit
 */

namespace ShapedKit\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the `{$wpdb->prefix}shaped_kit_mcp_log` table.
 */
final class LogTable {

	/**
	 * Base table name without prefix.
	 */
	public const TABLE_NAME = 'shaped_kit_mcp_log';

	/**
	 * Current schema version for the log table.
	 */
	public const SCHEMA_VERSION = '1.0.0';

	/**
	 * Option key storing the installed schema version.
	 */
	public const OPTION_SCHEMA_VERSION = 'shaped_kit_mcp_log_db_version';

	/**
	 * Returns the fully qualified table name with the current blog prefix.
	 *
	 * Verified source: $wpdb->prefix in wp-includes/class-wpdb.php:261.
	 *
	 * @return string Fully qualified table name.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Generates the CREATE TABLE SQL statement formatted for dbDelta.
	 *
	 * Note: dbDelta requires two spaces after PRIMARY KEY and every key definition
	 * on its own line (wp-admin/includes/upgrade.php:2936+).
	 *
	 * Fields per PRD §11 (FR-S3):
	 * - id: bigint unsigned auto-increment primary key
	 * - created_at: datetime UTC
	 * - user_id: bigint unsigned
	 * - app_password_name: varchar(255)
	 * - product: varchar(64) (e.g. location-weather, real-testimonials)
	 * - ability: varchar(128) (e.g. real-testimonials/get-context)
	 * - outcome: varchar(32) (ok, error, denied, confirmation_required, confirmed)
	 * - error_id: varchar(64) nullable
	 * - duration_ms: int unsigned
	 * - argument_keys: text (JSON array of argument keys only — never values)
	 *
	 * @return string SQL statement.
	 */
	public static function get_schema(): string {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			app_password_name varchar(255) NOT NULL DEFAULT '',
			product varchar(64) NOT NULL DEFAULT '',
			ability varchar(128) NOT NULL DEFAULT '',
			outcome varchar(32) NOT NULL DEFAULT 'ok',
			error_id varchar(64) DEFAULT NULL,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			argument_keys text NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY product (product),
			KEY ability (ability),
			KEY user_id (user_id),
			KEY outcome (outcome)
		) {$charset_collate};";
	}

	/**
	 * Creates or updates the log table using core's dbDelta.
	 *
	 * Verified source: dbDelta() in wp-admin/includes/upgrade.php:2936.
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		\dbDelta( self::get_schema() );

		update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Checks if schema migration is required and runs install if needed.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed_version = get_option( self::OPTION_SCHEMA_VERSION, '' );

		if ( self::SCHEMA_VERSION !== $installed_version ) {
			self::install();
		}
	}

	/**
	 * Checks whether the log table exists in the database.
	 *
	 * Uses DESCRIBE query which works reliably across both persistent and temporary tables.
	 *
	 * @return bool True if table exists, false otherwise.
	 */
	public static function exists(): bool {
		global $wpdb;

		$table_name = self::table_name();

		$suppress = $wpdb->suppress_errors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table existence check via DESCRIBE.
		$result = $wpdb->get_var( "DESCRIBE {$table_name}" );
		$wpdb->suppress_errors( $suppress );

		return ! empty( $result );
	}

	/**
	 * Drops the table upon complete plugin uninstall.
	 *
	 * Verified source: PRD FR-K8.
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;

		$table_name = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall table removal.
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name};" );

		delete_option( self::OPTION_SCHEMA_VERSION );
	}
}
