<?php
/**
 * The Rest_Controller template file.
 *
 * Copy this file into a product's MCP module and update:
 *   1. Namespace: `ShapedPlugin\Template\Mcp` -> `ShapedPlugin\{Product}\Mcp`
 *   2. REST_NAMESPACE: 'template/v1' -> product's settings REST namespace (e.g. 'my-product/v1')
 *   3. Filter names: `template_prefix/...` -> `{prefix}/...` (replace `template_prefix` with the product prefix).
 *   4. SETTINGS_PATH: the admin address of the product's "MCP for AI Agents" settings tab.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * REST routes powering the product's internal "MCP for AI agents" admin settings card.
 *
 * Exposes:
 * - `GET  /wp-json/{namespace}/mcp`: Current MCP status, tool count, and adapter state.
 * - `POST /wp-json/{namespace}/mcp`: Toggle switch handler with `{ "enabled": boolean }`.
 *
 * Security:
 * - Requires `manage_options` capability.
 * - Nonce-verified by WordPress cookie authentication.
 * - No ajax handlers used (FR-K7).
 */
class Rest_Controller {

	/**
	 * REST namespace for the product's administrative settings.
	 */
	const REST_NAMESPACE = 'template/v1';

	/**
	 * Route under the namespace.
	 */
	const ROUTE = '/mcp';

	/**
	 * Public download and information link for Shaped Kit (E100).
	 * Free editions on WordPress.org link only (Guideline 8 compliance).
	 */
	const KIT_DOWNLOAD_URL = 'https://github.com/ShapedPlugin/shaped-kit';

	/**
	 * Menu slug for Shaped Kit in WP Admin.
	 */
	const KIT_PAGE = 'shaped-kit';

	/**
	 * Admin address of the product's "MCP for AI Agents" settings tab, relative to wp-admin. Product data: the editor's
	 * Build with AI modal links to it when the owner has not switched MCP on yet (see `builder_client()`).
	 */
	const SETTINGS_PATH = 'admin.php?page=template-slug-settings#settings=mcp-agents';

	/**
	 * Attach REST routes. Called from Module::register().
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers GET and POST routes. Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public static function register_routes() {
		// E119: If Free edition and Pro is active module owner, Free yields its settings route.
		$owner_const = Module::owner_constant();
		if ( 'free' === Module::EDITION && defined( $owner_const ) && 'pro' === constant( $owner_const ) ) {
			return;
		}

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_status' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'set_status' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Capability check for administrative settings access.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * `GET`: Current status payload.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_status() {
		return rest_ensure_response( self::summary() );
	}

	/**
	 * `POST`: Switch MCP on or off, returning fresh summary.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function set_status( $request ) {
		$result = Permission_Gate::set_enabled( $request->get_param( 'enabled' ) );

		if ( is_wp_error( $result ) ) {
			$statuses = array(
				'template_prefix_mcp_toggle_forbidden' => rest_authorization_required_code(),
				'template_prefix_mcp_toggle_invalid'   => 400,
			);

			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => $statuses[ $result->get_error_code() ] ?? 500 )
			);
		}

		// Update toolsets if supplied in the payload (FR-K9 §6, D14). Only `manage_options` reaches this route.
		$toolsets = $request->get_param( 'toolsets' );
		if ( is_array( $toolsets ) ) {
			Permission_Gate::set_active_toolsets( $toolsets );
		}

		return rest_ensure_response( self::summary() );
	}

	/**
	 * What the editor's Build with AI modal needs to hand the job to the owner's own AI client when no AI key is
	 * connected: whether MCP and the Builder toolset are on, and where to switch them on or connect a client (the
	 * Kit's connect panel for this product, or where to get the Kit).
	 *
	 * @param bool|null $kit_active Optional override for unit testing.
	 *
	 * @return array<string, mixed>
	 */
	public static function builder_client( $kit_active = null ) {
		$kit_active = null === $kit_active ? defined( 'SHAPED_KIT_VERSION' ) : (bool) $kit_active;

		/** This filter is documented in summary(). */
		$download_url = esc_url_raw( (string) apply_filters( 'template_prefix/mcp_kit_download_url', self::KIT_DOWNLOAD_URL ) );

		return array(
			'slug'        => Module::SLUG,
			'enabled'     => Permission_Gate::is_enabled(),
			'builder'     => in_array( 'builder', Permission_Gate::get_active_toolsets(), true ),
			'settingsUrl' => admin_url( self::SETTINGS_PATH ),
			'connectUrl'  => $kit_active ? admin_url( 'admin.php?page=' . self::KIT_PAGE . '&connect=' . Module::SLUG ) : '',
			'kitUrl'      => $download_url,
			'siteUrl'     => home_url(),
		);
	}

	/**
	 * Assembles the state data for the settings card.
	 *
	 * @param bool|null $kit_active Optional override for unit testing.
	 *
	 * @return array<string, mixed>
	 */
	public static function summary( $kit_active = null ) {
		$products = Kit_Registration::add_product( array() );
		$entry    = ! empty( $products ) ? $products[0] : array(
			'slug'            => Module::SLUG,
			'name'            => Module::NAME,
			'edition'         => Module::EDITION,
			'mcp_enabled'     => Permission_Gate::is_enabled(),
			'tools_count'     => count( Abilities_Registrar::names() ),
			'endpoint_url'    => Server::endpoint_url(),
			'status'          => 'disabled',
			'message'         => '',
			'adapter_version' => null,
		);
		$kit_active = null === $kit_active ? defined( 'SHAPED_KIT_VERSION' ) : (bool) $kit_active;
		$source     = Server::adapter_source();

		if ( 'plugin' === $source['type'] ) {
			$source['display_name'] = Server::plugin_display_name( $source['name'] );
		}

		/**
		 * Filter where "Get Shaped Kit" points. Return empty string to suppress link.
		 *
		 * @param string $url The target URL.
		 */
		$download_url = esc_url_raw( (string) apply_filters( 'template_prefix/mcp_kit_download_url', self::KIT_DOWNLOAD_URL ) );

		/**
		 * Filter the setup guide URL.
		 *
		 * @param string $url Setup guide URL. Default empty.
		 */
		$guide_url = esc_url_raw( (string) apply_filters( 'template_prefix/mcp_setup_guide_url', '' ) );

		// What the Quick Connect command needs: the signed-in user's login (not a secret) and where an application
		// password is made. Only the login is filled in; the password is never known here.
		$user                = wp_get_current_user();
		$passwords_available = $user->exists() && function_exists( 'wp_is_application_passwords_available_for_user' ) && wp_is_application_passwords_available_for_user( $user );

		return array(
			'guide_url'    => '' !== $guide_url ? $guide_url : null,
			'slug'         => $entry['slug'],
			'name'         => $entry['name'],
			'edition'      => $entry['edition'],
			'enabled'      => $entry['mcp_enabled'],
			'status'       => $entry['status'],
			'message'      => $entry['message'],
			'endpoint_url' => $entry['endpoint_url'],
			'tools_count'  => $entry['tools_count'],
			'toolsets'     => Abilities_Registrar::toolsets(),
			'adapter'      => array_merge( $source, array( 'version' => $entry['adapter_version'] ) ),
			'connect'      => array(
				'user_login'          => $user->exists() ? (string) $user->user_login : '',
				'passwords_available' => $passwords_available,
				'passwords_url'       => admin_url( 'profile.php#application-passwords-section' ),
				'kit_url'             => $kit_active ? admin_url( 'admin.php?page=' . self::KIT_PAGE . '&connect=' . Module::SLUG ) : null,
			),
			'kit'          => array(
				'active'        => $kit_active,
				'dashboard_url' => $kit_active ? admin_url( 'admin.php?page=' . self::KIT_PAGE ) : null,
				'download_url'  => '' !== $download_url ? $download_url : null,
			),
		);
	}
}
