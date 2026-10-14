<?php
/**
 * Integration tests for TransportGuard (E88 Residual, E116 CORS, E25 Auth Recovery).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use ShapedKit\Mcp\TransportGuard;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Class TransportGuardIntegrationTest
 */
class TransportGuardIntegrationTest extends WP_UnitTestCase {

	/**
	 * REQUEST_URI before the test, so a test that sets it cannot leak into the next one.
	 *
	 * @var string|null
	 */
	private $original_request_uri;

	/**
	 * Remember the request URI.
	 */
	public function setUp(): void {
		parent::setUp();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved verbatim and put back verbatim in tearDown().
		$this->original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
	}

	/**
	 * Clean up globals after each test.
	 */
	public function tearDown(): void {
		TransportGuard::clean_mcp_stray_output();

		unset(
			$_SERVER['HTTP_AUTHORIZATION'],
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
			$_SERVER['HTTP_X_AUTHORIZATION'],
			$_SERVER['PHP_AUTH_USER'],
			$_SERVER['PHP_AUTH_PW'],
			$GLOBALS['shaped_kit_test_echo_in_callback']
		);

		if ( null === $this->original_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		}

		parent::tearDown();
	}

	/**
	 * Verify is_mcp_request detects MCP routes and Shaped Kit routes.
	 */
	public function test_is_mcp_request_identification() {
		$mcp_req1 = new WP_REST_Request( 'POST', '/location-weather/mcp' );
		$this->assertTrue( TransportGuard::is_mcp_request( $mcp_req1 ) );

		$mcp_req2 = new WP_REST_Request( 'POST', '/real-testimonials/mcp' );
		$this->assertTrue( TransportGuard::is_mcp_request( $mcp_req2 ) );

		$kit_req = new WP_REST_Request( 'GET', '/shaped-kit/v1/mcp' );
		$this->assertTrue( TransportGuard::is_mcp_request( $kit_req ) );

		$core_req = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->assertFalse( TransportGuard::is_mcp_request( $core_req ) );

		$this->assertFalse( TransportGuard::is_mcp_request( null ) );
	}

	/**
	 * E88 Residual: Stray output emitted during request execution is discarded before JSON response.
	 */
	public function test_e88_stray_output_scrubbing() {
		$request = new WP_REST_Request( 'POST', '/location-weather/mcp' );

		// Start buffer.
		TransportGuard::start_buffering();

		// Simulate stray output emitted by an errant third-party hook or notice.
		echo '<div class="notice">Stray PHP notice or plugin HTML</div>';
		$this->assertGreaterThan( 0, ob_get_length() );

		// Call clean_mcp_stray_output (hooked on rest_pre_serve_request and rest_pre_echo_response).
		$served = TransportGuard::clean_mcp_stray_output( false );

		$this->assertFalse( $served );
		$this->assertSame( 0, ob_get_length() );
	}

	/**
	 * E116: Mcp-Session-Id is scoped strictly to MCP routes, never added to standard routes like /wp/v2/posts.
	 */
	public function test_e116_cors_headers_filtering_scoped_to_mcp() {
		$mcp_request  = new WP_REST_Request( 'POST', '/location-weather/mcp' );
		$core_request = new WP_REST_Request( 'GET', '/wp/v2/posts' );

		// On MCP request: Mcp-Session-Id is added.
		$initial_allowed = array( 'Authorization', 'Content-Type' );
		$mcp_allowed     = TransportGuard::filter_allowed_cors_headers( $initial_allowed, $mcp_request );
		$this->assertContains( 'Mcp-Session-Id', $mcp_allowed );
		$this->assertCount( 3, $mcp_allowed );

		$initial_exposed = array( 'X-WP-Total' );
		$mcp_exposed     = TransportGuard::filter_exposed_cors_headers( $initial_exposed, $mcp_request );
		$this->assertContains( 'Mcp-Session-Id', $mcp_exposed );
		$this->assertCount( 2, $mcp_exposed );

		// On core request (/wp/v2/posts): Mcp-Session-Id is NOT added.
		$core_allowed = TransportGuard::filter_allowed_cors_headers( $initial_allowed, $core_request );
		$this->assertNotContains( 'Mcp-Session-Id', $core_allowed );
		$this->assertSame( $initial_allowed, $core_allowed );

		$core_exposed = TransportGuard::filter_exposed_cors_headers( $initial_exposed, $core_request );
		$this->assertNotContains( 'Mcp-Session-Id', $core_exposed );
		$this->assertSame( $initial_exposed, $core_exposed );
	}

	/**
	 * E25: 401 response on MCP route receives host_config_hint when Authorization is missing.
	 */
	public function test_e25_host_config_hint_on_401_mcp_response() {
		$server  = new WP_REST_Server();
		$mcp_req = new WP_REST_Request( 'POST', '/location-weather/mcp' );

		// 401 response without auth header gets hint.
		$res401   = new WP_REST_Response(
			array(
				'code'    => 'rest_forbidden',
				'message' => 'Unauthorized',
			),
			401
		);
		$filtered = TransportGuard::filter_rest_post_dispatch( $res401, $server, $mcp_req );

		$data = $filtered->get_data();
		$this->assertArrayHasKey( 'host_config_hint', $data );
		$this->assertStringContainsString( 'SetEnvIf Authorization', $data['host_config_hint'] );
		$this->assertStringContainsString( 'fastcgi_param HTTP_AUTHORIZATION', $data['host_config_hint'] );

		// 200 response on MCP route does NOT get hint.
		$res200      = new WP_REST_Response( array( 'result' => array() ), 200 );
		$filtered200 = TransportGuard::filter_rest_post_dispatch( $res200, $server, $mcp_req );
		$this->assertArrayNotHasKey( 'host_config_hint', $filtered200->get_data() );

		// 401 response on non-MCP route (/wp/v2/posts) does NOT get hint.
		$core_req      = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$core_res401   = new WP_REST_Response( array( 'message' => 'Unauthorized' ), 401 );
		$filtered_core = TransportGuard::filter_rest_post_dispatch( $core_res401, $server, $core_req );
		$this->assertArrayNotHasKey( 'host_config_hint', $filtered_core->get_data() );
	}

	/**
	 * E25: Recover Authorization header from HTTP_X_AUTHORIZATION.
	 */
	public function test_e25_recover_from_http_x_authorization() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$_SERVER['HTTP_X_AUTHORIZATION'] = 'Basic YWRtaW46eGF1dGhwYXNz';

		TransportGuard::recover_authorization_header( false );

		$this->assertSame( 'Basic YWRtaW46eGF1dGhwYXNz', $_SERVER['HTTP_AUTHORIZATION'] );
		$this->assertSame( 'admin', $_SERVER['PHP_AUTH_USER'] );
		$this->assertSame( 'xauthpass', $_SERVER['PHP_AUTH_PW'] );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * E25: Existing HTTP_AUTHORIZATION is not overwritten.
	 */
	public function test_e25_existing_authorization_preserved() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$_SERVER['HTTP_AUTHORIZATION']   = 'Basic YWRtaW46ZXhpc3Rpbmc=';
		$_SERVER['HTTP_X_AUTHORIZATION'] = 'Basic YWRtaW46b3ZlcndyaXRl';

		TransportGuard::recover_authorization_header( false );

		$this->assertSame( 'Basic YWRtaW46ZXhpc3Rpbmc=', $_SERVER['HTTP_AUTHORIZATION'] );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * E88: The early buffer only starts for a URL whose REST route is an MCP route. A query string that merely
	 * contains the letters "mcp" must not count.
	 */
	public function test_uri_targets_mcp_reads_the_route_not_the_whole_url() {
		$mcp = array(
			'/wp-json/location-weather/mcp',
			'/wp-json/shaped-kit/v1/mcp',
			'/wp-json/mcp/mcp-adapter-default-server',
			'/blog/wp-json/location-weather/mcp?x=1',
			'/index.php/wp-json/real-testimonials/mcp',
			'/?rest_route=/real-testimonials/mcp',
			'/?rest_route=%2Fshaped-kit%2Fv1%2Fmcp',
			'/?foo=1&rest_route=/location-weather/mcp',
		);
		foreach ( $mcp as $uri ) {
			$this->assertTrue( TransportGuard::uri_targets_mcp( $uri ), $uri . ' is an MCP URL' );
		}

		$other = array(
			'',
			'/',
			'/wp-json/wp/v2/types?search=mcp',
			'/wp-json/wp/v2/posts',
			'/?s=mcp',
			'/mcp',
			'/wp-admin/admin.php?page=shaped-kit',
			'/?rest_route=/wp/v2/types&search=mcp',
		);
		foreach ( $other as $uri ) {
			$this->assertFalse( TransportGuard::uri_targets_mcp( $uri ), '"' . $uri . '" is not an MCP URL' );
		}
	}

	/**
	 * E88: A REST prefix changed through the `rest_url_prefix` filter is followed.
	 */
	public function test_uri_targets_mcp_follows_a_changed_rest_prefix() {
		add_filter(
			'rest_url_prefix',
			static function () {
				return 'api';
			}
		);

		$this->assertTrue( TransportGuard::uri_targets_mcp( '/api/location-weather/mcp' ) );
		$this->assertFalse( TransportGuard::uri_targets_mcp( '/wp-json/location-weather/mcp' ) );
	}

	/**
	 * E88: The early buffer starts for an MCP URL and not for any other URL.
	 */
	public function test_early_buffer_starts_only_for_an_mcp_url() {
		$level = ob_get_level();

		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/types?search=mcp';
		TransportGuard::maybe_start_early_buffer();
		$this->assertSame( $level, ob_get_level(), 'a non-MCP URL must not start a buffer' );

		$_SERVER['REQUEST_URI'] = '/wp-json/location-weather/mcp';
		TransportGuard::maybe_start_early_buffer();
		$this->assertSame( $level + 1, ob_get_level(), 'an MCP URL starts one buffer' );

		TransportGuard::maybe_start_early_buffer();
		$this->assertSame( $level + 1, ob_get_level(), 'asking twice must not stack a second buffer' );

		TransportGuard::clean_mcp_stray_output();
		$this->assertSame( $level, ob_get_level(), 'cleaning closes the buffer it started' );
	}

	/**
	 * Where stray output can appear while WordPress builds a REST response: [ hook, priority ] or 'callback'.
	 *
	 * @return array<string, array>
	 */
	public function stray_output_points() {
		return array(
			'inside the route callback'               => array( 'callback', 10 ),
			'in rest_pre_dispatch'                    => array( 'rest_pre_dispatch', 10 ),
			'in rest_post_dispatch'                   => array( 'rest_post_dispatch', 10 ),
			'in a late rest_pre_serve_request filter' => array( 'rest_pre_serve_request', 20 ),
			'in rest_pre_echo_response'               => array( 'rest_pre_echo_response', 10 ),
		);
	}

	/**
	 * E88: Stray output at any of these points never reaches the wire. Goes through the real
	 * WP_REST_Server::serve_request(), so the order of core's own filters is part of what is tested.
	 *
	 * @dataProvider stray_output_points
	 *
	 * @param string $hook     Hook name, or 'callback'.
	 * @param int    $priority Priority.
	 */
	public function test_stray_output_is_scrubbed_through_the_real_serve_request( $hook, $priority ) {
		$server = $this->make_server();
		$this->print_stray_at( $hook, $priority );

		$this->assertSame( '{"ok":true}', $this->serve( $server ) );
	}

	/**
	 * E88: Output printed at load time, before the route runs, is scrubbed when the early buffer started.
	 */
	public function test_output_printed_before_the_route_runs_is_scrubbed_by_the_early_buffer() {
		$server                 = $this->make_server();
		$_SERVER['REQUEST_URI'] = '/wp-json/probe-product/mcp';

		$this->assertSame( '{"ok":true}', $this->serve( $server, true ) );
	}

	/**
	 * E88: Control. Without the guard the same stray output corrupts the JSON, so the tests above can fail.
	 *
	 * @dataProvider stray_output_points
	 *
	 * @param string $hook     Hook name, or 'callback'.
	 * @param int    $priority Priority.
	 */
	public function test_without_the_guard_the_same_stray_output_corrupts_the_json( $hook, $priority ) {
		remove_filter( 'rest_allowed_cors_headers', array( TransportGuard::class, 'filter_allowed_cors_headers' ), 10 );
		remove_filter( 'rest_pre_echo_response', array( TransportGuard::class, 'clean_mcp_stray_output' ), PHP_INT_MAX );

		$server = $this->make_server();
		$this->print_stray_at( $hook, $priority );

		$out = $this->serve( $server );
		$this->assertStringContainsString( 'NOTICE', $out );
		$this->assertNull( json_decode( $out ) );
	}

	/**
	 * A server that sends no real headers, with one MCP-looking route that answers {"ok":true}.
	 *
	 * @return Quiet_REST_Server
	 */
	private function make_server() {
		$server                    = new Quiet_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;

		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'probe-product',
					'/mcp',
					array(
						'methods'             => 'POST',
						'callback'            => static function () {
							if ( ! empty( $GLOBALS['shaped_kit_test_echo_in_callback'] ) ) {
								echo 'NOTICE-IN-CALLBACK'; // phpcs:ignore WordPress.Security.EscapeOutput
							}
							return array( 'ok' => true );
						},
						'permission_callback' => '__return_true',
					)
				);
			}
		);
		do_action( 'rest_api_init', $server );

		$_SERVER['REQUEST_METHOD'] = 'POST';

		return $server;
	}

	/**
	 * Make something print text at the given point of the request.
	 *
	 * @param string $hook     Hook name, or 'callback'.
	 * @param int    $priority Priority.
	 */
	private function print_stray_at( $hook, $priority ) {
		if ( 'callback' === $hook ) {
			$GLOBALS['shaped_kit_test_echo_in_callback'] = true;
			return;
		}

		add_filter(
			$hook,
			static function ( $value ) {
				echo 'NOTICE-FROM-A-HOOK'; // phpcs:ignore WordPress.Security.EscapeOutput
				return $value;
			},
			$priority
		);
	}

	/**
	 * Run one request and return what would reach the client. The capture buffer is the OUTERMOST one, as a
	 * web server or compression buffer is in production, so the guard's own buffer sits on top of it.
	 *
	 * @param Quiet_REST_Server $server        Server.
	 * @param bool              $print_at_load Also print text at "load time" after starting the early buffer.
	 * @return string
	 */
	private function serve( $server, $print_at_load = false ) {
		ob_start();
		if ( $print_at_load ) {
			TransportGuard::maybe_start_early_buffer();
			echo 'NOTICE-AT-LOAD'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$server->serve_request( '/probe-product/mcp' );

		return (string) ob_get_clean();
	}
}

/**
 * A REST server that does not call header(), so it can run inside PHPUnit.
 */
class Quiet_REST_Server extends WP_REST_Server {

	/**
	 * Ignore the header.
	 *
	 * @param string $key   Header name.
	 * @param string $value Header value.
	 */
	public function send_header( $key, $value ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * Ignore the headers.
	 *
	 * @param array $headers Headers.
	 */
	public function send_headers( $headers ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * Ignore the header removal.
	 *
	 * @param string $key Header name.
	 */
	public function remove_header( $key ) {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
}
