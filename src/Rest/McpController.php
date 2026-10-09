<?php
/**
 * The McpController file.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Rest;

use ShapedKit\Mcp\AdapterBootstrap;
use ShapedKit\Mcp\ProductRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * The REST route behind the "AI & MCP" dashboard: what is connected, and what each product reports.
 *
 * `GET /wp-json/shaped-kit/v1/mcp` needs `manage_options`. For a browser, WordPress itself checks the
 * REST nonce on cookie authentication and treats a request without one as signed out, so the one
 * permission check here covers both. The route is read-only: switching a product is a separate route.
 */
class McpController {

	/**
	 * REST namespace for every Kit route.
	 */
	const REST_NAMESPACE = 'shaped-kit/v1';

	/**
	 * Route, under the namespace.
	 */
	const ROUTE = '/mcp';

	/**
	 * Attach the route. Called once from `shaped-kit.php`.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the route. Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_overview' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	/**
	 * Who may use the route. A plain boolean: WordPress turns `false` into a 401 or 403.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * `GET`: the overview.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_overview() {
		return rest_ensure_response( self::overview() );
	}

	/**
	 * Everything the dashboard shows.
	 *
	 * @return array<string, mixed>
	 */
	public static function overview() {
		global $wp_version;

		$adapter = AdapterBootstrap::status();
		$source  = self::describe_source( $adapter['source'], $adapter['loaded'] );

		return array(
			'kit_version'         => SHAPED_KIT_VERSION,
			'wordpress_supported' => AdapterBootstrap::wordpress_supported( (string) $wp_version ),
			'adapter'             => array(
				'loaded'     => $adapter['loaded'],
				'compatible' => $adapter['compatible'],
				'version'    => $adapter['version'],
				'source'     => $source,
			),
			'abilities_api'       => $adapter['abilities_api'],
			'products'            => ProductRegistry::all(),
		);
	}

	/**
	 * Say who supplies the adapter, in a shape the dashboard can word: a type, the folder, and for another
	 * plugin the name from its own header.
	 *
	 * @param string $source Value from `AdapterBootstrap::status()`: 'shaped-kit', 'none', 'unknown' or a plugin folder.
	 * @param bool   $loaded Whether an adapter is loaded at all.
	 *
	 * @return array{type: string, name: string, display_name: string}
	 */
	public static function describe_source( $source, $loaded ) {
		if ( ! $loaded || AdapterBootstrap::SOURCE_NONE === $source ) {
			return self::source( 'none', '' );
		}

		if ( AdapterBootstrap::SOURCE_KIT === $source ) {
			return self::source( 'shaped-kit', 'shaped-kit' );
		}

		if ( AdapterBootstrap::SOURCE_UNKNOWN === $source || '' === $source ) {
			return self::source( 'unknown', '' );
		}

		return self::source( 'plugin', $source, self::plugin_display_name( $source ) );
	}

	/**
	 * The name a plugin gives itself in its header.
	 *
	 * @param string $folder Plugin folder name.
	 *
	 * @return string The name, or an empty string if it cannot be found.
	 */
	public static function plugin_display_name( $folder ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return self::display_name_in( get_plugins(), (string) $folder );
	}

	/**
	 * Find a plugin's header name in a list shaped like `get_plugins()`. The match is on the whole
	 * folder name, so `shaped-kit` does not pick up `shaped-kit-extras`.
	 *
	 * @param array<string, array<string, mixed>> $plugins Plugin headers keyed by `folder/file.php`.
	 * @param string                              $folder  Plugin folder name.
	 *
	 * @return string
	 */
	public static function display_name_in( $plugins, $folder ) {
		if ( '' === $folder || ! is_array( $plugins ) ) {
			return '';
		}

		foreach ( $plugins as $file => $header ) {
			if ( 0 !== strpos( (string) $file, $folder . '/' ) ) {
				continue;
			}

			$name = is_array( $header ) && isset( $header['Name'] ) && is_string( $header['Name'] ) ? trim( $header['Name'] ) : '';

			if ( '' !== $name ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Build the source block.
	 *
	 * @param string $type         One of 'none', 'shaped-kit', 'plugin', 'unknown'.
	 * @param string $name         Folder name, or an empty string.
	 * @param string $display_name Header name of the plugin, or an empty string.
	 *
	 * @return array{type: string, name: string, display_name: string}
	 */
	private static function source( $type, $name, $display_name = '' ) {
		return array(
			'type'         => $type,
			'name'         => $name,
			'display_name' => $display_name,
		);
	}
}
