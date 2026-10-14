<?php
/**
 * MCP Transport Guard for HTTP, CORS, and wire cleanliness.
 *
 * Provides safety mechanisms across all MCP endpoints on the site:
 * - E88 Residual: Scrubs stray output/notices emitted before the JSON-RPC response is echoed.
 *   Buffering starts when this file loads, if the request URL is an MCP route, so output from everything
 *   that runs after the Kit loads is caught. The buffer is discarded at the last hook before core echoes
 *   (`rest_pre_echo_response`, priority PHP_INT_MAX). Not covered: output printed before the Kit loads
 *   (must-use plugins, plugins that sort before `shaped-kit`, at include time), and a site whose REST prefix
 *   is changed by a plugin that loads after the Kit (it still gets the dispatch-time buffer started from the
 *   CORS filter below).
 * - E116: Exposes and allows 'Mcp-Session-Id' header for cross-origin browser requests (scoped to MCP routes).
 * - E25: Recovers stripped Authorization header in FastCGI/reverse-proxy setups and provides actionable host hints on 401.
 *
 * Verified WordPress Core source references (Constraint C1):
 * - rest_pre_serve_request: wp-includes/rest-api/class-wp-rest-server.php:516 (not used: runs before the last hook)
 * - rest_pre_echo_response: wp-includes/rest-api/class-wp-rest-server.php:539 (runs right before the echo)
 * - rest_allowed_cors_headers: wp-includes/rest-api/class-wp-rest-server.php:434
 * - rest_exposed_cors_headers: wp-includes/rest-api/class-wp-rest-server.php:408
 * - rest_post_dispatch: wp-includes/rest-api/class-wp-rest-server.php:464
 * - rest_get_url_prefix: wp-includes/rest-api.php:501 (loaded before plugins, wp-settings.php:317)
 * - wp_parse_url: wp-includes/http.php:761; wp_parse_str: wp-includes/formatting.php:5240
 * - determine_current_user: wp-includes/user.php:3379
 * - wp_populate_basic_auth_from_authorization_header: wp-includes/load.php:106
 *
 * @package ShapedKit
 */

namespace ShapedKit\Mcp;

use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Class TransportGuard
 */
final class TransportGuard {

	/**
	 * MCP Session ID header name.
	 */
	const SESSION_HEADER = 'Mcp-Session-Id';

	/**
	 * Track active buffer level for current MCP request.
	 *
	 * @var int
	 */
	private static $buffer_level = 0;

	/**
	 * Register transport guards.
	 *
	 * @return void
	 */
	public static function register() {
		// E25: Recover Authorization header early before authentication runs.
		add_filter( 'determine_current_user', array( __CLASS__, 'recover_authorization_header' ), 1 );

		// E25: Append actionable host configuration hint to 401 responses on MCP routes.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'filter_rest_post_dispatch' ), 10, 3 );

		// E116: CORS header filters for cross-origin support (scoped strictly to MCP routes).
		add_filter( 'rest_allowed_cors_headers', array( __CLASS__, 'filter_allowed_cors_headers' ), 10, 2 );
		add_filter( 'rest_exposed_cors_headers', array( __CLASS__, 'filter_exposed_cors_headers' ), 10, 2 );

		// E88 Residual: discard stray output at the LAST hook before core echoes the JSON. An earlier hook
		// (`rest_pre_serve_request`) would leave a window in which other filters can still print.
		add_filter( 'rest_pre_echo_response', array( __CLASS__, 'clean_mcp_stray_output' ), PHP_INT_MAX, 3 );

		// Start buffering now if the incoming request is for an MCP endpoint, so output from plugins that load
		// after this one, and from `plugins_loaded`, `init` and `rest_api_init`, is caught too.
		self::maybe_start_early_buffer();
	}

	/**
	 * Start the buffer at load time when the raw request URL targets an MCP endpoint.
	 *
	 * @return void
	 */
	public static function maybe_start_early_buffer() {
		if ( self::is_raw_mcp_request() ) {
			self::start_buffering();
		}
	}

	/**
	 * Start output buffering to capture early notices/stray output.
	 *
	 * @return void
	 */
	public static function start_buffering() {
		if ( 0 === self::$buffer_level ) {
			ob_start();
			self::$buffer_level = ob_get_level();
		}
	}

	/**
	 * Determines if the raw HTTP request is targeted at an MCP or Shaped Kit endpoint.
	 *
	 * @return bool
	 */
	public static function is_raw_mcp_request() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only matched against a pattern, never output or stored. Sanitising would strip the percent-encoded characters of a `rest_route` value.
		return self::uri_targets_mcp( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	}

	/**
	 * Does a request URL point at an MCP route or a Shaped Kit route?
	 *
	 * Only the REST route is examined, not the rest of the URL, so a query string that merely contains the
	 * letters "mcp" does not count. Understands both URL forms (`/wp-json/{route}` under any site path, and
	 * `?rest_route=/{route}`) and the REST prefix that `rest_url_prefix` returns.
	 *
	 * @param string $uri Request URI (path and query string).
	 * @return bool
	 */
	public static function uri_targets_mcp( $uri ) {
		$parts = wp_parse_url( (string) $uri );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$route  = '';
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		$token  = '/' . trim( (string) $prefix, '/' ) . '/';
		$path   = isset( $parts['path'] ) ? rawurldecode( (string) $parts['path'] ) : '';
		$at     = strpos( $path, $token );

		if ( false !== $at ) {
			$route = '/' . substr( $path, $at + strlen( $token ) );
		} elseif ( ! empty( $parts['query'] ) ) {
			$query = array();
			wp_parse_str( (string) $parts['query'], $query );
			if ( isset( $query['rest_route'] ) && is_string( $query['rest_route'] ) ) {
				$route = '/' . ltrim( $query['rest_route'], '/' );
			}
		}

		return '' !== $route && self::route_is_mcp( $route );
	}

	/**
	 * Is a REST route (for example `/location-weather/mcp`) an MCP route or a Shaped Kit route?
	 *
	 * @param string $route REST route.
	 * @return bool
	 */
	public static function route_is_mcp( $route ) {
		$route = (string) $route;

		// Product MCP routes (/slug/mcp, /mcp/...) or Shaped Kit routes.
		if ( preg_match( '#/(?:[^/]+/)?mcp(?:/|$)#', $route ) ) {
			return true;
		}

		return 0 === strpos( $route, '/shaped-kit/' );
	}

	/**
	 * Determines if a WP_REST_Request is an MCP endpoint or Shaped Kit endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function is_mcp_request( $request ) {
		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return false;
		}

		return self::route_is_mcp( (string) $request->get_route() );
	}

	/**
	 * E25: Recovers stripped Authorization header in FastCGI / reverse-proxy setups.
	 *
	 * Core already checks REDIRECT_HTTP_AUTHORIZATION (load.php:118).
	 * This guard recovers headers preserved under HTTP_X_AUTHORIZATION or apache_request_headers().
	 *
	 * @param int|false $user_id Current user ID.
	 * @return int|false
	 */
	public static function recover_authorization_header( $user_id ) {
		if ( empty( $_SERVER['HTTP_AUTHORIZATION'] ) && empty( $_SERVER['PHP_AUTH_USER'] ) ) {
			if ( ! empty( $_SERVER['HTTP_X_AUTHORIZATION'] ) ) {
				$_SERVER['HTTP_AUTHORIZATION'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AUTHORIZATION'] ) );
			} elseif ( function_exists( 'apache_request_headers' ) ) {
				$headers = apache_request_headers();
				if ( is_array( $headers ) ) {
					$auth_key = '';
					foreach ( array( 'Authorization', 'authorization', 'AUTHORIZATION' ) as $key ) {
						if ( isset( $headers[ $key ] ) ) {
							$auth_key = $key;
							break;
						}
					}
					if ( '' !== $auth_key && ! empty( $headers[ $auth_key ] ) ) {
						$_SERVER['HTTP_AUTHORIZATION'] = sanitize_text_field( wp_unslash( $headers[ $auth_key ] ) );
					}
				}
			}

			// If Authorization header was recovered, ensure Basic Auth server globals are populated.
			if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) && function_exists( 'wp_populate_basic_auth_from_authorization_header' ) ) {
				wp_populate_basic_auth_from_authorization_header();
			}
		}

		return $user_id;
	}

	/**
	 * E25: Appends web server configuration hint to 401 responses for MCP requests
	 * when credentials appear to have been stripped by the host.
	 *
	 * @param \WP_REST_Response|\WP_HTTP_Response $response Response object.
	 * @param \WP_REST_Server                     $server   Server instance.
	 * @param \WP_REST_Request                    $request  Request object.
	 * @return \WP_REST_Response|\WP_HTTP_Response
	 */
	public static function filter_rest_post_dispatch( $response, $server, $request ) {
		unset( $server );

		if ( self::is_mcp_request( $request ) && 401 === $response->get_status() ) {
			if ( empty( $_SERVER['HTTP_AUTHORIZATION'] ) && empty( $_SERVER['PHP_AUTH_USER'] ) ) {
				$data = $response->get_data();
				if ( is_array( $data ) ) {
					$data['host_config_hint'] = __( 'If you sent credentials, your web server may be stripping the Authorization header. In Apache/.htaccess add: "SetEnvIf Authorization (.*) HTTP_AUTHORIZATION=$1" or "RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]". In Nginx add: "fastcgi_param HTTP_AUTHORIZATION $http_authorization;".', 'shaped-kit' );
					$response->set_data( $data );
				}
			}
		}

		return $response;
	}

	/**
	 * E116: Allow 'Mcp-Session-Id' header in CORS requests (scoped strictly to MCP routes).
	 *
	 * @param string[]             $allow_headers Allowed headers.
	 * @param WP_REST_Request|null $request       Request object.
	 * @return string[]
	 */
	public static function filter_allowed_cors_headers( $allow_headers, $request = null ) {
		$allow_headers = is_array( $allow_headers ) ? $allow_headers : array();

		if ( $request instanceof WP_REST_Request && self::is_mcp_request( $request ) ) {
			if ( ! in_array( self::SESSION_HEADER, $allow_headers, true ) ) {
				$allow_headers[] = self::SESSION_HEADER;
			}

			if ( 0 === self::$buffer_level ) {
				self::start_buffering();
			}
		}

		return $allow_headers;
	}

	/**
	 * E116: Expose 'Mcp-Session-Id' header in CORS responses (scoped strictly to MCP routes).
	 *
	 * @param string[]             $expose_headers Exposed headers.
	 * @param WP_REST_Request|null $request        Request object.
	 * @return string[]
	 */
	public static function filter_exposed_cors_headers( $expose_headers, $request = null ) {
		$expose_headers = is_array( $expose_headers ) ? $expose_headers : array();

		if ( $request instanceof WP_REST_Request && self::is_mcp_request( $request ) ) {
			if ( ! in_array( self::SESSION_HEADER, $expose_headers, true ) ) {
				$expose_headers[] = self::SESSION_HEADER;
			}
		}

		return $expose_headers;
	}

	/**
	 * E88 Residual: Discards any stray output produced before the REST response is echoed.
	 *
	 * Hooked on `rest_pre_echo_response`, the last filter before core echoes, and returns its argument
	 * unchanged. If a request never reaches that filter (a HEAD request, or a filter that serves the response
	 * itself), the buffer is left open and PHP flushes it when the script ends, so nothing is lost.
	 *
	 * @param mixed $passthrough Value passed by the filter hook.
	 * @return mixed
	 */
	public static function clean_mcp_stray_output( $passthrough = null ) {
		if ( self::$buffer_level > 0 ) {
			while ( ob_get_level() >= self::$buffer_level ) {
				$stray = ob_get_clean();
				if ( is_string( $stray ) && '' !== $stray && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// Log stray output for administrative debugging without corrupting JSON wire.
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'Shaped Kit TransportGuard (E88): Discarded %d bytes of stray output before MCP REST response: %.300s',
							strlen( $stray ),
							$stray
						)
					);
				}
				if ( ob_get_level() < self::$buffer_level ) {
					break;
				}
			}
			self::$buffer_level = 0;
		}

		return $passthrough;
	}
}
