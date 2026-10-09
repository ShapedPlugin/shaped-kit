<?php
/**
 * The ProductSwitch file.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Mcp;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Switches one product's MCP on or off, through the handler that product registered.
 *
 * The Kit never writes a product's option: the product owns its own state, and the Kit only asks it
 * (`shaped_kit/mcp_toggle_handlers`). Because a handler is another plugin's code, three things are not
 * trusted:
 *
 * - its return value. Anything but a clear failure used to count as success, so a handler that refused
 *   by returning `false` looked like a switch that worked. The state is read back instead;
 * - its exceptions, whose raw messages are not shown to the owner;
 * - the slug, which must name a product that is listed and has a working switch.
 */
class ProductSwitch {

	/**
	 * Switch a product. Reads the two filters unless the lists are passed in (only tests do that).
	 *
	 * @param string     $slug     Product slug.
	 * @param bool       $enabled  The state asked for.
	 * @param mixed|null $products Raw product entries, or null to read `shaped_kit/products`.
	 * @param mixed|null $handlers Raw toggle handlers, or null to read `shaped_kit/mcp_toggle_handlers`.
	 *
	 * @return true|WP_Error True once the product is in the state asked for.
	 */
	public static function apply( $slug, $enabled, $products = null, $handlers = null ) {
		$enabled  = (bool) $enabled;
		$products = null === $products ? apply_filters( 'shaped_kit/products', array() ) : $products;
		$handlers = null === $handlers ? apply_filters( 'shaped_kit/mcp_toggle_handlers', array() ) : $handlers;
		$handlers = is_array( $handlers ) ? $handlers : array();
		$product  = self::find( $slug, $products, $handlers );

		if ( null === $product ) {
			return new WP_Error( 'shaped_kit_unknown_product', __( 'That product is not registered with Shaped Kit.', 'shaped-kit' ), array( 'status' => 404 ) );
		}

		if ( ! $product['toggleable'] ) {
			return new WP_Error( 'shaped_kit_not_toggleable', __( 'This product has to be switched in its own settings.', 'shaped-kit' ), array( 'status' => 409 ) );
		}

		try {
			$result = call_user_func( $handlers[ $slug ]['set_enabled'], $enabled );
		} catch ( \Throwable $error ) {
			return self::failed();
		}

		if ( is_wp_error( $result ) ) {
			return self::from_product_error( $result );
		}

		if ( false === $result ) {
			return self::failed();
		}

		// Trust what the product says it is now, not what it said it did. Only possible when it can be read.
		if ( isset( $handlers[ $slug ]['get_enabled'] ) && is_callable( $handlers[ $slug ]['get_enabled'] ) ) {
			$after = self::find( $slug, $products, $handlers );

			if ( null === $after || $after['enabled'] !== $enabled ) {
				return self::failed();
			}
		}

		return true;
	}

	/**
	 * One cleaned product by slug.
	 *
	 * @param string $slug     Product slug.
	 * @param mixed  $products Raw product entries.
	 * @param array  $handlers Toggle handlers.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function find( $slug, $products, $handlers ) {
		foreach ( ProductRegistry::collect( $products, $handlers ) as $product ) {
			if ( $product['slug'] === $slug ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * Pass a product's own refusal on, keeping its code and its words but not trusting its markup.
	 *
	 * @param WP_Error $error What the product's handler returned.
	 *
	 * @return WP_Error
	 */
	private static function from_product_error( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) && is_int( $data['status'] ) ? $data['status'] : 500;

		return new WP_Error( $error->get_error_code(), wp_strip_all_tags( $error->get_error_message() ), array( 'status' => $status ) );
	}

	/**
	 * The answer when the product did not switch and gave no usable reason.
	 *
	 * @return WP_Error
	 */
	private static function failed() {
		return new WP_Error( 'shaped_kit_toggle_failed', __( 'The product did not switch. Try again, or use its own settings.', 'shaped-kit' ), array( 'status' => 500 ) );
	}
}
