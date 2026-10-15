<?php
/**
 * Whether the Shaped Kit Store is switched on.
 *
 * The Store needs things that live outside this plugin: a signed catalog on the ShapedPlugin API, release zips
 * with their hashes, the Kit's own licence-store item, and real signing keys (docs/PRODUCTION_READINESS.md).
 * Until they exist the bundled catalog is placeholder data and every install or update button would lead
 * nowhere, so the Store stays off: no tab, no Store routes, no update checks. Flip `LIVE` to true in the
 * release that ships the pipeline. Developers can turn it on for one site with the `shaped_kit/store_live` filter.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Class StoreGate
 */
class StoreGate {

	/**
	 * Default for every site. False until the release pipeline is live.
	 *
	 * @var bool
	 */
	const LIVE = false;

	/**
	 * Whether the Store is on for this site.
	 *
	 * @return bool
	 */
	public static function is_live() {
		return (bool) apply_filters( 'shaped_kit/store_live', self::LIVE );
	}

	/**
	 * The answer a Store route gives while the Store is off.
	 *
	 * @return \WP_Error
	 */
	public static function not_live_error() {
		return new \WP_Error(
			'shaped_kit_store_not_live',
			__( 'The Shaped Kit Store is not live yet.', 'shaped-kit' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Let a permission through only when the Store is on. A refusal that is already there (wrong role,
	 * not signed in) is passed on unchanged, so a stranger learns nothing about the Store's state.
	 *
	 * @param bool|\WP_Error $permission The result of the route's own permission check.
	 * @return bool|\WP_Error
	 */
	public static function allow( $permission ) {
		if ( true !== $permission ) {
			return $permission;
		}

		return self::is_live() ? true : self::not_live_error();
	}
}
