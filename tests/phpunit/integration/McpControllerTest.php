<?php
/**
 * The overview route behind the dashboard.
 *
 * Risk covered: a signed-out visitor or a non-admin reading it, another plugin's bad product entry
 * reaching the dashboard through it, and the adapter's supplier being named wrongly.
 *
 * @package ShapedKit
 */

use ShapedKit\Rest\McpController;

/**
 * Real WordPress: the route is dispatched, not called directly.
 */
final class McpControllerTest extends WP_UnitTestCase {

	/**
	 * Register the route on a fresh REST server.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! has_action( 'rest_api_init', array( McpController::class, 'register_routes' ) ) ) {
			McpController::register();
		}

		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * Leave no filters or server behind for the next test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'shaped_kit/products' );
		remove_all_filters( 'shaped_kit/mcp_toggle_handlers' );
		$GLOBALS['wp_rest_server'] = null;

		parent::tear_down();
	}

	/**
	 * Dispatch a GET to the route.
	 *
	 * @return WP_REST_Response
	 */
	private function get(): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'GET', '/shaped-kit/v1/mcp' ) );
	}

	/**
	 * Sign in as a user with the given role.
	 *
	 * @param string $role Role name.
	 *
	 * @return void
	 */
	private function sign_in_as( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	public function test_an_administrator_reads_the_overview(): void {
		$this->sign_in_as( 'administrator' );

		$response = $this->get();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( SHAPED_KIT_VERSION, $data['kit_version'] );
		$this->assertTrue( $data['adapter']['loaded'] );
		$this->assertSame( 'shaped-kit', $data['adapter']['source']['type'], 'The isolated test install has no other adapter.' );
		$this->assertSame( array(), $data['products'], 'No product has registered.' );
	}

	public function test_an_editor_and_a_visitor_are_refused(): void {
		$this->sign_in_as( 'editor' );
		$this->assertSame( 403, $this->get()->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->get()->get_status() );
	}

	public function test_products_reach_the_overview_cleaned(): void {
		add_filter(
			'shaped_kit/products',
			static function ( $products ) {
				$products[] = array(
					'slug'         => 'location-weather',
					'name'         => 'Location Weather',
					'mcp_enabled'  => true,
					'status'       => 'ready',
					'endpoint_url' => 'javascript:alert(1)',
				);
				$products[] = 'not an entry';

				return $products;
			}
		);
		$this->sign_in_as( 'administrator' );

		$products = $this->get()->get_data()['products'];

		$this->assertCount( 1, $products, 'The malformed entry is dropped.' );
		$this->assertSame( 'location-weather', $products[0]['slug'] );
		$this->assertSame( '', $products[0]['endpoint_url'], 'A script address is not passed on.' );
	}

	public function test_the_route_only_reads(): void {
		$this->sign_in_as( 'administrator' );

		$response = rest_do_request( new WP_REST_Request( 'POST', '/shaped-kit/v1/mcp' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_the_supplier_is_named_from_where_the_adapter_came_from(): void {
		$this->assertSame(
			array(
				'type'         => 'none',
				'name'         => '',
				'display_name' => '',
			),
			McpController::describe_source( 'none', false )
		);
		$this->assertSame( 'none', McpController::describe_source( 'shaped-kit', false )['type'], 'Nothing loaded means no supplier, whatever the source says.' );
		$this->assertSame( 'shaped-kit', McpController::describe_source( 'shaped-kit', true )['type'] );
		$this->assertSame( 'unknown', McpController::describe_source( 'unknown', true )['type'] );

		$plugin = McpController::describe_source( 'no-such-plugin-folder', true );
		$this->assertSame( 'plugin', $plugin['type'] );
		$this->assertSame( 'no-such-plugin-folder', $plugin['name'] );
		$this->assertSame( '', $plugin['display_name'], 'No header found, so the dashboard falls back to the folder name.' );
	}

	public function test_a_plugin_name_comes_from_the_header_of_that_exact_folder(): void {
		$plugins = array(
			'seo-by-rank-math/rank-math.php' => array( 'Name' => 'Rank Math SEO' ),
			'shaped-kit-extras/extras.php'   => array( 'Name' => 'Kit Extras' ),
			'blank/blank.php'                => array( 'Name' => '  ' ),
			'two/a.php'                      => array( 'Name' => '' ),
			'two/b.php'                      => array( 'Name' => ' Second ' ),
			'hello.php'                      => array( 'Name' => 'Hello Dolly' ),
		);

		$this->assertSame( 'Rank Math SEO', McpController::display_name_in( $plugins, 'seo-by-rank-math' ) );
		$this->assertSame( '', McpController::display_name_in( $plugins, 'shaped-kit' ), 'A folder that only starts the same is not a match.' );
		$this->assertSame( 'Kit Extras', McpController::display_name_in( $plugins, 'shaped-kit-extras' ) );
		$this->assertSame( '', McpController::display_name_in( $plugins, 'blank' ) );
		$this->assertSame( 'Second', McpController::display_name_in( $plugins, 'two' ), 'A blank header is skipped for the next file in the folder.' );
		$this->assertSame( '', McpController::display_name_in( $plugins, 'hello.php' ), 'A single-file plugin has no folder.' );
		$this->assertSame( '', McpController::display_name_in( $plugins, '' ) );
	}
}
