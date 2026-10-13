<?php
/**
 * LogListener integration tests: logging tool calls, argument privacy, and Application Password capture.
 *
 * @package ShapedKit
 */

use ShapedKit\Audit\LogListener;
use ShapedKit\Audit\LogTable;

/**
 * Real WordPress integration tests for MCP audit log ingestion.
 */
final class LogListenerIntegrationTest extends WP_UnitTestCase {

	/**
	 * Clean up table, options, and in-memory listener state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		LogTable::drop();
		LogListener::reset_state();
		parent::tear_down();
	}

	/**
	 * Ensure table exists before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		LogTable::install();
		LogListener::reset_state();
	}

	/**
	 * Verify that valid tool telemetry is inserted with argument keys only (values stripped).
	 *
	 * @return void
	 */
	public function test_logs_tool_call_with_argument_keys_only(): void {
		global $wpdb;

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$logged = LogListener::on_tool_called(
			array(
				'product'     => 'location-weather',
				'ability'     => 'location-weather/get-weather-template',
				'outcome'     => 'ok',
				'duration_ms' => 42,
				'arguments'   => array(
					'id'            => 123,
					'secret_key'    => 'my_secret_token_never_log_this',
					'customer_name' => 'Alice',
				),
			)
		);

		$this->assertTrue( $logged );

		$table = LogTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1" );

		$this->assertNotNull( $row );
		$this->assertSame( 'location-weather', $row->product );
		$this->assertSame( 'location-weather/get-weather-template', $row->ability );
		$this->assertSame( 'ok', $row->outcome );
		$this->assertSame( (string) $user_id, (string) $row->user_id );
		$this->assertSame( '42', (string) $row->duration_ms );

		// Critical Privacy Guard: Verify argument keys ONLY.
		$keys = json_decode( $row->argument_keys, true );
		$this->assertSame( array( 'id', 'secret_key', 'customer_name' ), $keys );
		$this->assertStringNotContainsString( 'my_secret_token_never_log_this', $row->argument_keys );
		$this->assertStringNotContainsString( 'Alice', $row->argument_keys );
	}

	/**
	 * Verify that application_password_did_authenticate populates the app_password_name attribution.
	 *
	 * @return void
	 */
	public function test_captures_application_password_attribution(): void {
		global $wpdb;

		$user = $this->factory->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user->ID );

		// Simulate core's application_password_did_authenticate hook.
		do_action(
			'application_password_did_authenticate',
			$user,
			array(
				'name' => 'Claude Code on MacBook',
				'uuid' => 'abcd-1234-efgh',
			)
		);

		$this->assertSame( 'Claude Code on MacBook', LogListener::get_current_app_password_name() );

		// Now trigger tool call action.
		do_action(
			'shaped_kit/mcp_tool_called',
			array(
				'product'     => 'real-testimonials',
				'ability'     => 'real-testimonials/get-context',
				'outcome'     => 'ok',
				'duration_ms' => 15,
			)
		);

		$table = LogTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1" );

		$this->assertNotNull( $row );
		$this->assertSame( 'real-testimonials', $row->product );
		$this->assertSame( 'Claude Code on MacBook', $row->app_password_name );
	}

	/**
	 * Verify that missing required fields fail closed without inserting bad rows.
	 *
	 * @return void
	 */
	public function test_rejects_malformed_payload(): void {
		global $wpdb;

		$this->assertFalse( LogListener::on_tool_called( 'not an array' ) );
		$this->assertFalse( LogListener::on_tool_called( array() ) );
		$this->assertFalse( LogListener::on_tool_called( array( 'product' => 'location-weather' ) ) ); // Missing ability.
		$this->assertFalse( LogListener::on_tool_called( array( 'ability' => 'location-weather/test' ) ) ); // Missing product.

		$table = LogTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertSame( '0', (string) $count );
	}
}
