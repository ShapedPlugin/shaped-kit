<?php
/**
 * The Kit_Registration template file.
 *
 * Copy this file into a product's MCP module.
 * See README.md in this folder for the step-by-step token replacement checklist.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers the product with the Shaped Kit dashboard via WordPress filters.
 *
 * Design Principles:
 * - Decoupled: Interacts strictly through filters (`shaped_kit/products` and `shaped_kit/mcp_toggle_handlers`).
 *   The product functions independently whether Shaped Kit is installed or not.
 * - Always Listed (E96): Both filters are attached unconditionally at plugin load.
 *   If a product hid itself while switched off, it could never be toggled on from the dashboard.
 */
class Kit_Registration {

	/**
	 * Attach both registration filters. Called once from Module::register().
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'shaped_kit/products', array( __CLASS__, 'add_product' ) );
		add_filter( 'shaped_kit/mcp_toggle_handlers', array( __CLASS__, 'add_toggle_handler' ) );
	}

	/**
	 * Adds this product's status and endpoint to the Shaped Kit overview.
	 *
	 * @param mixed $products Product list array.
	 *
	 * @return array
	 */
	public static function add_product( $products ) {
		$products     = is_array( $products ) ? $products : array();
		$availability = Server::availability();

		$products[] = array(
			'slug'            => Module::SLUG,
			'name'            => Module::NAME,
			'edition'         => Module::EDITION,
			'mcp_enabled'     => Permission_Gate::is_enabled(),
			'tools_count'     => count( Abilities_Registrar::names() ),
			'endpoint_url'    => Server::endpoint_url(),
			'status'          => $availability['status'],
			'message'         => $availability['message'],
			'adapter_version' => $availability['adapter_version'],
		);

		return $products;
	}

	/**
	 * Registers toggle callbacks with Shaped Kit.
	 *
	 * Contract:
	 * - `set_enabled` returns `true` or a `WP_Error`, never `false`.
	 * - The Kit calls these callbacks to toggle the switch; the Kit never writes DB options directly.
	 *
	 * @param mixed $handlers Toggle handlers array keyed by slug.
	 *
	 * @return array
	 */
	public static function add_toggle_handler( $handlers ) {
		$handlers = is_array( $handlers ) ? $handlers : array();

		$handlers[ Module::SLUG ] = array(
			'get_enabled' => array( Permission_Gate::class, 'is_enabled' ),
			'set_enabled' => array( Permission_Gate::class, 'set_enabled' ),
		);

		return $handlers;
	}
}
