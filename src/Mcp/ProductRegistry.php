<?php
/**
 * The ProductRegistry file.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Turns whatever products put on the `shaped_kit/products` filter into entries the dashboard can trust.
 *
 * Any plugin can add to that filter, so every entry is treated as untrusted input: an entry that is not
 * usable is dropped, and every field that is kept has been forced to a known type and shape. The
 * dashboard still escapes its output; this keeps a bad entry from reaching it at all.
 *
 * The cleaning is pure (arrays in, arrays out, no WordPress calls) so it is tested without WordPress.
 * Only `all()` reads the two filters.
 */
class ProductRegistry {

	/**
	 * Every usable product, in the order the filter returned them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all() {
		/**
		 * Products announce themselves here (name, slug, edition, enabled, tools_count, endpoint_url, status).
		 *
		 * @param array $products Entries added so far.
		 */
		$products = apply_filters( 'shaped_kit/products', array() );

		/**
		 * Products register how the Kit may read and switch their on/off state, keyed by slug.
		 * Each value holds `get_enabled` and `set_enabled` callables. The Kit never writes a product's option.
		 *
		 * @param array $handlers Handlers added so far.
		 */
		$handlers = apply_filters( 'shaped_kit/mcp_toggle_handlers', array() );

		return self::collect( $products, $handlers );
	}

	/**
	 * Clean a whole list. An entry whose slug was already seen is dropped, so the first one wins and the
	 * dashboard never shows two cards (or two switches) for one product.
	 *
	 * @param mixed $products Raw filter value.
	 * @param mixed $handlers Raw toggle handlers, keyed by slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function collect( $products, $handlers ) {
		$handlers = is_array( $handlers ) ? $handlers : array();
		$clean    = array();
		$seen     = array();

		foreach ( is_array( $products ) ? $products : array() as $entry ) {
			$product = self::normalise( $entry, $handlers );

			if ( null === $product || isset( $seen[ $product['slug'] ] ) ) {
				continue;
			}

			$seen[ $product['slug'] ] = true;
			$clean[]                  = $product;
		}

		return $clean;
	}

	/**
	 * Clean one entry, or return null when it cannot be shown.
	 *
	 * An entry needs a name and a slug. A slug is lower-case letters, digits, `-` and `_` and is never
	 * repaired: a slug that has to be altered is a slug the product's own handler would not be found
	 * under.
	 *
	 * @param mixed $entry    One value from the filter.
	 * @param array $handlers Toggle handlers, keyed by slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function normalise( $entry, $handlers = array() ) {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$slug = isset( $entry['slug'] ) && is_string( $entry['slug'] ) ? trim( $entry['slug'] ) : '';
		$name = self::text( isset( $entry['name'] ) ? $entry['name'] : '' );

		if ( '' === $name || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $slug ) ) {
			return null;
		}

		$handler    = isset( $handlers[ $slug ] ) && is_array( $handlers[ $slug ] ) ? $handlers[ $slug ] : array();
		$toggleable = isset( $handler['set_enabled'] ) && is_callable( $handler['set_enabled'] );
		$enabled    = self::flag( isset( $entry['mcp_enabled'] ) ? $entry['mcp_enabled'] : false );

		// The product owns its switch, so its own answer beats what the entry claimed.
		if ( isset( $handler['get_enabled'] ) && is_callable( $handler['get_enabled'] ) ) {
			$enabled = self::read_enabled( $handler['get_enabled'] );
		}

		$status = isset( $entry['status'] ) && is_string( $entry['status'] ) ? strtolower( trim( $entry['status'] ) ) : '';
		$status = 1 === preg_match( '/^[a-z0-9_]+$/', $status ) ? $status : 'unknown';

		// "Ready" while switched off would send the owner to a connection that does not exist.
		if ( ! $enabled && 'ready' === $status ) {
			$status = 'disabled';
		}

		$edition = isset( $entry['edition'] ) && is_string( $entry['edition'] ) ? strtolower( trim( $entry['edition'] ) ) : '';
		$tools   = isset( $entry['tools_count'] ) && is_numeric( $entry['tools_count'] ) ? (int) $entry['tools_count'] : 0;
		$url     = isset( $entry['endpoint_url'] ) && is_string( $entry['endpoint_url'] ) ? trim( $entry['endpoint_url'] ) : '';
		$version = isset( $entry['adapter_version'] ) && is_string( $entry['adapter_version'] ) ? trim( $entry['adapter_version'] ) : '';

		return array(
			'slug'            => $slug,
			'name'            => $name,
			'edition'         => in_array( $edition, array( 'free', 'pro' ), true ) ? $edition : '',
			'enabled'         => $enabled,
			'toggleable'      => $toggleable,
			'tools_count'     => max( 0, $tools ),
			// Only a plain web address is ever shown as one.
			'endpoint_url'    => 1 === preg_match( '#^https?://#i', $url ) ? $url : '',
			'status'          => $status,
			'message'         => self::text( isset( $entry['message'] ) ? $entry['message'] : '' ),
			'adapter_version' => 1 === preg_match( '/^[0-9A-Za-z.+-]+$/', $version ) ? $version : '',
		);
	}

	/**
	 * Ask a product whether it is on. A handler that throws counts as off: the switch must never read
	 * "on" because the product failed to answer.
	 *
	 * @param callable $getter The product's `get_enabled`.
	 *
	 * @return bool
	 */
	private static function read_enabled( $getter ) {
		try {
			return self::flag( call_user_func( $getter ) );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * A value read as on or off. Only a real true, 1 or a word like "yes" or "true" is on; the string
	 * "false" is off (a plain cast would call it on).
	 *
	 * @param mixed $value Anything.
	 *
	 * @return bool
	 */
	private static function flag( $value ) {
		return true === filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	}

	/**
	 * Plain text from a value: not a string gives an empty string, tags are removed, and whitespace is
	 * trimmed.
	 *
	 * @param mixed $value Anything.
	 *
	 * @return string
	 */
	private static function text( $value ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Kept free of WordPress so it runs in a plain PHP unit test.
		return is_string( $value ) ? trim( strip_tags( $value ) ) : '';
	}
}
