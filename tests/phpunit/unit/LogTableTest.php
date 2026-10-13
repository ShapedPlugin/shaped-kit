<?php
/**
 * LogTable unit tests: table schema syntax and table name resolution.
 *
 * @package ShapedKit
 */

use PHPUnit\Framework\TestCase;
use ShapedKit\Audit\LogTable;

/**
 * Pure logic and schema syntax validation without full WordPress database.
 */
final class LogTableTest extends TestCase {

	/**
	 * Verify that table name resolution respects the global table prefix.
	 *
	 * @return void
	 */
	public function test_table_name_resolution(): void {
		global $wpdb;

		$old_wpdb = $wpdb ?? null;
		$wpdb     = (object) array(
			'prefix'              => 'custom_wp_',
			'get_charset_collate' => function () {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			},
		);

		$this->assertSame( 'custom_wp_shaped_kit_mcp_log', LogTable::table_name() );

		$wpdb = $old_wpdb;
	}

	/**
	 * Verify that schema definition conforms to dbDelta formatting requirements:
	 * - Two spaces after PRIMARY KEY
	 * - Explicit indices on queryable columns (created_at, product, ability, user_id, outcome)
	 * - argument_keys column exists for keys-only logging
	 *
	 * @return void
	 */
	public function test_schema_syntax_meets_dbdelta_rules(): void {
		global $wpdb;

		$old_wpdb = $wpdb ?? null;
		$wpdb     = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Charset collate.
			 *
			 * @return string
			 */
			public function get_charset_collate() {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
			}
		};

		$schema = LogTable::get_schema();

		// dbDelta requirement: two spaces after PRIMARY KEY.
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $schema );
		$this->assertStringContainsString( 'KEY created_at (created_at)', $schema );
		$this->assertStringContainsString( 'KEY product (product)', $schema );
		$this->assertStringContainsString( 'KEY ability (ability)', $schema );
		$this->assertStringContainsString( 'KEY user_id (user_id)', $schema );
		$this->assertStringContainsString( 'KEY outcome (outcome)', $schema );
		$this->assertStringContainsString( 'argument_keys text NOT NULL', $schema );

		$wpdb = $old_wpdb;
	}
}
