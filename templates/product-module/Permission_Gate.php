<?php
/**
 * The Permission_Gate template file.
 *
 * Copy this file into a product's MCP module.
 * See README.md in this folder for the step-by-step token replacement checklist.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Controls authorization and connection gates for the product MCP endpoint.
 *
 * The adapter's default permission check is 'read' (admitting every subscriber).
 * This class replaces it:
 *   1. The product kill-switch option must be explicitly 'yes'.
 *   2. The user must be authenticated.
 *   3. The user must hold the product's base administrative capability.
 *
 * Each tool's own permission_callback still runs on top of this transport gate.
 */
class Permission_Gate {

	/**
	 * Option holding the MCP kill switch: 'yes' or 'no'.
	 */
	const KILL_SWITCH_OPTION = 'template_prefix_mcp_enabled';

	/**
	 * Option holding the enabled toolset IDs.
	 */
	const TOOLSETS_OPTION = 'template_prefix_mcp_active_toolsets';

	/**
	 * Whether the owner has switched MCP on. Anything other than the exact string 'yes' is off.
	 *
	 * Performance optimization (E67):
	 * When the option is missing, get_option() costs 2 database queries per request.
	 * The first request that finds the row null seeds it with 'no' and autoload=true.
	 * All subsequent frontend and admin requests load it for 0 additional queries.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$value = get_option( self::KILL_SWITCH_OPTION, null );

		if ( null === $value ) {
			add_option( self::KILL_SWITCH_OPTION, 'no', '', true );

			return false;
		}

		return 'yes' === $value;
	}

	/**
	 * Returns the list of active toolset IDs (FR-K9 §6, D14).
	 *
	 * A site that never saved a choice gets `core` and `management`: the opt-in toolsets (`destructive`, `builder`,
	 * `bulk`, `media`) stay off until the owner switches them on, because each one adds tools, and their schemas, to
	 * every message of every AI client (E126), and `media` makes this site fetch addresses from the internet. `core`
	 * is always on.
	 *
	 * @return string[]
	 */
	public static function get_active_toolsets() {
		$saved = get_option( self::TOOLSETS_OPTION, null );
		if ( is_array( $saved ) ) {
			// Ensure 'core' is always included.
			return array_values( array_unique( array_merge( array( 'core' ), array_map( 'strval', $saved ) ) ) );
		}

		return array( 'core', 'management' );
	}

	/**
	 * Saves the active toolset IDs. `core` is always kept.
	 *
	 * @param array $toolsets Array of toolset IDs.
	 *
	 * @return bool True if updated.
	 */
	public static function set_active_toolsets( array $toolsets ) {
		$clean = array_values( array_unique( array_merge( array( 'core' ), array_map( 'sanitize_key', $toolsets ) ) ) );

		return update_option( self::TOOLSETS_OPTION, $clean, true );
	}

	/**
	 * Switch MCP on or off.
	 *
	 * Guarded strictly by 'manage_options' (never lowered by a filter), because the switch
	 * dictates whether the public API endpoint exists.
	 *
	 * Contract:
	 * Returns `true` or a `WP_Error`, NEVER `false`.
	 * Generic consumers treat any non-WP_Error response as success.
	 * Returning `false` on denial would cause the UI to display a failed toggle as successful (E94 / B6).
	 *
	 * @param mixed $enabled Requested state: boolean, or string representations (1, 0, "true", "false", "yes", "no", "on", "off").
	 *
	 * @return true|WP_Error
	 */
	public static function set_enabled( $enabled ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'template_prefix_mcp_toggle_forbidden',
				__( 'Only an administrator can switch the MCP server on or off.', 'template-domain' )
			);
		}

		// Avoid PHP's loose cast where (bool) "false" is true (E97).
		// Non-scalar values or unparseable text strings return null and fail safely.
		$enabled = is_scalar( $enabled ) ? filter_var( $enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) : null;

		if ( null === $enabled ) {
			return new WP_Error(
				'template_prefix_mcp_toggle_invalid',
				__( 'The value must be on or off.', 'template-domain' )
			);
		}

		update_option( self::KILL_SWITCH_OPTION, $enabled ? 'yes' : 'no', true );

		// update_option() returns false if the value was already identical, which is not an error.
		// Verify actual stored state rather than the return value.
		if ( self::is_enabled() !== $enabled ) {
			return new WP_Error(
				'template_prefix_mcp_toggle_failed',
				__( 'The setting could not be saved.', 'template-domain' )
			);
		}

		return true;
	}

	/**
	 * Base management capability required to access this product's MCP endpoint.
	 *
	 * Filterable so sites that configure custom capabilities (e.g. editor access) can match wp-admin.
	 * Defaults strictly to 'manage_options'.
	 *
	 * @return string
	 */
	public static function base_capability() {
		/**
		 * Filter the capability required to reach the product MCP server.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		$capability = apply_filters( 'template_prefix_mcp_access_capability', 'manage_options' );

		return ( is_string( $capability ) && '' !== $capability ) ? $capability : 'manage_options';
	}

	/**
	 * Base permission check for tools that require administrative access to this product.
	 *
	 * Used as default permission_callback for tools (e.g. `get-context`).
	 * Cheap and free of side effects because Abilities API evaluates it multiple times per call.
	 *
	 * @return bool
	 */
	public static function can_access() {
		return current_user_can( self::base_capability() );
	}

	/**
	 * Transport permission callback passed to the adapter's `create_server()`.
	 *
	 * Critical Security Rule (E87 / B5):
	 * Returns strictly boolean `true` to allow, or `false` to deny.
	 * NEVER returns a WP_Error and NEVER throws an exception.
	 *
	 * On Adapter 0.4.1 and older (e.g. Rank Math), returning a WP_Error causes HttpTransport
	 * to fall through to the default check ("read" capability), admitting any subscriber!
	 * Exact boolean false fails closed across all adapter versions (0.1.0 through 0.7.0).
	 *
	 * @return bool
	 */
	public static function transport() {
		try {
			return true === self::check();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Performs full permission check and returns structured WP_Error on failure.
	 * Used for diagnostic REST reporting and internal assertions.
	 *
	 * @return true|WP_Error
	 */
	public static function check() {
		if ( ! self::is_enabled() ) {
			return new WP_Error(
				'template_prefix_mcp_disabled',
				__( 'The MCP server is switched off.', 'template-domain' )
			);
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'template_prefix_mcp_unauthorized',
				__( 'Authentication is required to use the MCP server.', 'template-domain' )
			);
		}

		if ( ! current_user_can( self::base_capability() ) ) {
			return new WP_Error(
				'template_prefix_mcp_forbidden',
				__( 'Your account is not allowed to use this MCP server.', 'template-domain' )
			);
		}

		return true;
	}
}
