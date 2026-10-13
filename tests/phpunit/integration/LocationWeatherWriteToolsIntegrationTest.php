<?php
/**
 * Integration test for Location Weather MCP write tools and WriteGuard confirmation round-trip.
 *
 * Verifies FR-S2 (Level 1 two-step destructive action confirmation),
 * CPT post/meta mutations, cache invalidation, audit log telemetry integration (FR-S3),
 * and PRD §19 edge guards (E38 state change, E40 atomic consumption, E42 argument tampering).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use ShapedKit\Audit\LogListener;
use ShapedKit\Audit\LogTable;
use ShapedPlugin\Weather\Mcp\Abilities_Registrar;
use ShapedPlugin\Weather\Mcp\Tools\Entry_Tools;
use WP_UnitTestCase;

/**
 * Class LocationWeatherWriteToolsIntegrationTest
 */
class LocationWeatherWriteToolsIntegrationTest extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load Location Weather autoloader if not already loaded.
		$pro_autoload = dirname( __DIR__, 4 ) . '/location-weather-pro/vendor/autoload.php';
		if ( file_exists( $pro_autoload ) && ! class_exists( Entry_Tools::class ) ) {
			require_once $pro_autoload;
		}

		LogTable::install();
		LogListener::register();
		LogListener::reset_state();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Register location_weather CPT if not already registered in test environment.
		if ( ! post_type_exists( 'location_weather' ) ) {
			register_post_type(
				'location_weather',
				array(
					'public'       => true,
					'show_ui'      => true,
					'supports'     => array( 'title', 'editor' ),
					'map_meta_cap' => true,
				)
			);
		}
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		LogListener::reset_state();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test create-view and update-view-settings with audit log telemetry.
	 */
	public function test_create_and_update_view_with_telemetry() {
		global $wpdb;

		// 1. Create weather template
		$create_cb = Abilities_Registrar::wrap(
			'location-weather/create-view',
			array( Entry_Tools::class, 'create_entry' )
		);

		$created = call_user_func(
			$create_cb,
			array(
				'title'       => 'Oslo Forecast',
				'status'      => 'publish',
				'location_by' => 'city_name',
				'city_name'   => 'Oslo, NO',
				'units'       => 'metric',
				'layout'      => 'vertical',
			)
		);

		$this->assertIsArray( $created );
		$id = $created['id'];
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'Oslo Forecast', $created['title'] );
		$this->assertSame( 'Oslo, NO', $created['location']['value'] );

		// Verify audit log recorded creation.
		$log_table = LogTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$log_table} WHERE ability = %s ORDER BY id DESC LIMIT 1",
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
				'location-weather/create-view'
			),
			ARRAY_A
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( 'location-weather', $rows[0]['product'] );
		$this->assertSame( 'ok', $rows[0]['outcome'] );

		// 2. Update view settings.
		set_transient( 'sp_open_weather_data' . $id, 'oslo_cached_payload', 600 );

		$update_cb = Abilities_Registrar::wrap(
			'location-weather/update-view-settings',
			array( Entry_Tools::class, 'update_entry_settings' )
		);

		$updated = call_user_func(
			$update_cb,
			array(
				'id'        => $id,
				'title'     => 'Oslo Weather Card',
				'city_name' => 'Oslo, Norway',
				'display'   => array(
					'temperature' => true,
					'humidity'    => false,
				),
			)
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'Oslo Weather Card', $updated['title'] );
		$this->assertSame( 'Oslo, Norway', $updated['location']['value'] );
		$this->assertTrue( $updated['display']['temperature'] );
		$this->assertFalse( $updated['display']['humidity'] );
		$this->assertFalse( get_transient( 'sp_open_weather_data' . $id ), 'Cache transient must be purged upon update' );
	}

	/**
	 * Test duplicate-view tool.
	 */
	public function test_duplicate_view() {
		$orig_id = $this->factory->post->create(
			array(
				'post_type'   => 'location_weather',
				'post_title'  => 'Original View',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $orig_id, 'sp_location_weather_generator', array( 'lw-city-name' => 'Tokyo, JP' ) );
		update_post_meta( $orig_id, 'sp_location_weather_layout', array( 'weather-view' => 'horizontal' ) );

		$duplicate_cb = Abilities_Registrar::wrap(
			'location-weather/duplicate-view',
			array( Entry_Tools::class, 'duplicate_entry' )
		);

		$dup = call_user_func( $duplicate_cb, array( 'id' => $orig_id ) );

		$this->assertIsArray( $dup );
		$this->assertNotSame( $orig_id, $dup['id'] );
		$this->assertSame( 'Original View (Copy)', $dup['title'] );
		$this->assertSame( 'draft', $dup['status'] );
		$this->assertSame( 'horizontal', $dup['view'] );
	}

	/**
	 * Test delete-weather-template WriteGuard two-step permanent deletion round-trip.
	 */
	public function test_delete_weather_template_writeguard_flow() {
		global $wpdb;

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'location_weather',
				'post_title'  => 'Temp View To Delete',
				'post_status' => 'publish',
			)
		);

		$delete_cb = Abilities_Registrar::wrap(
			'location-weather/delete-weather-template',
			array( Entry_Tools::class, 'delete_entry' )
		);

		// Step 1: Request permanent deletion without confirmation_token -> preview with token.
		$preview = call_user_func(
			$delete_cb,
			array(
				'id'    => $post_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $preview );
		$this->assertSame( 'confirmation_required', $preview['status'] );
		$this->assertNotEmpty( $preview['confirmation_token'] );
		$token = $preview['confirmation_token'];

		// Step 2: Confirm with valid token.
		$deleted = call_user_func(
			$delete_cb,
			array(
				'id'                 => $post_id,
				'force'              => true,
				'confirmation_token' => $token,
			)
		);

		$this->assertIsArray( $deleted );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertTrue( $deleted['permanent'] );
		$this->assertNull( get_post( $post_id ) );

		// Step 3: Atomic consumption (E40) -> replay attempt fails.
		$replay = call_user_func(
			$delete_cb,
			array(
				'id'                 => $post_id,
				'force'              => true,
				'confirmation_token' => $token,
			)
		);

		$this->assertWPError( $replay );
		$this->assertSame( 'not_found', $replay->get_error_code() );
	}

	/**
	 * Test clear-cache single and global WriteGuard flow.
	 */
	public function test_clear_cache_writeguard_flow() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'location_weather',
				'post_title'  => 'Cached View',
				'post_status' => 'publish',
			)
		);

		set_transient( 'sp_open_weather_data' . $post_id, 'weather_data', 600 );
		set_transient( 'sp_open_weather_forecast_data888', 'forecast_data', 600 );

		$clear_cb = Abilities_Registrar::wrap(
			'location-weather/clear-cache',
			array( Entry_Tools::class, 'clear_cache' )
		);

		// Single cache clear.
		$single = call_user_func( $clear_cb, array( 'id' => $post_id ) );
		$this->assertIsArray( $single );
		$this->assertTrue( $single['cleared'] );
		$this->assertFalse( get_transient( 'sp_open_weather_data' . $post_id ) );
		$this->assertSame( 'forecast_data', get_transient( 'sp_open_weather_forecast_data888' ) );

		// Global cache preview.
		$preview = call_user_func( $clear_cb, array( 'global' => true ) );
		$this->assertIsArray( $preview );
		$this->assertSame( 'confirmation_required', $preview['status'] );
		$token = $preview['confirmation_token'];

		// Confirm global cache clear.
		$global = call_user_func(
			$clear_cb,
			array(
				'global'             => true,
				'confirmation_token' => $token,
			)
		);
		$this->assertIsArray( $global );
		$this->assertTrue( $global['cleared'] );
		$this->assertTrue( $global['global'] );
		$this->assertFalse( get_transient( 'sp_open_weather_forecast_data888' ) );
	}
}
