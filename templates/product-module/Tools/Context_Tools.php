<?php
/**
 * The Context_Tools support helper template.
 *
 * Copy this file into a product's MCP module (e.g. `Tools/Context_Tools.php`) and update:
 *   1. Namespace: `ShapedPlugin\Template\Mcp\Tools` -> `ShapedPlugin\{Product}\Mcp\Tools`
 *   2. Product info & entity counting in get_context() / entry_counts().
 *   3. Text domain: replace 'template-domain' with your product's text domain.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Tools;

use ShapedPlugin\Template\Mcp\Abilities_Registrar;
use ShapedPlugin\Template\Mcp\Module;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Universal orientation tool (`{slug}/get-context`).
 *
 * The first ability called by AI clients. Answers:
 *   - Identity: user ID, display name, whether admin. NEVER exposes email address (E79).
 *   - Environment: site URL, WordPress version, timezone, current ISO-8601 time.
 *   - Product: name, version, edition.
 *   - Entity counts: total published/draft entries.
 *   - Callable tools: lists tools registered and permitted for the calling user.
 */
class Context_Tools {

	/**
	 * Post statuses counted in totals. Trash is left out.
	 */
	const COUNTED_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Execute `get-context`.
	 *
	 * @param mixed $input Optional input.
	 *
	 * @return array|WP_Error
	 */
	public static function get_context( $input = null ) {
		unset( $input );

		if ( '' !== Module::ENTRY_POST_TYPE && ! post_type_exists( Module::ENTRY_POST_TYPE ) ) {
			return new WP_Error(
				'post_type_missing',
				__( 'The product entry type is not registered on this request.', 'template-domain' )
			);
		}

		global $wp_version;

		$user = wp_get_current_user();

		return array(
			'you'      => array(
				'wp_user_id' => (int) $user->ID,
				'name'       => (string) $user->display_name,
				'is_admin'   => user_can( $user, 'manage_options' ),
			),
			'site'     => array(
				'site_url'          => site_url(),
				'wordpress_version' => (string) $wp_version,
				'timezone'          => wp_timezone_string(),
				'current_time'      => wp_date( 'c' ),
			),
			'product'  => array(
				'name'    => Module::NAME,
				'edition' => Module::EDITION,
				'version' => Module::VERSION,
			),
			'overview' => array(
				'entries' => self::entry_counts(),
			),
			'tools'    => self::callable_tools(),
		);
	}

	/**
	 * Counts entity posts by status.
	 *
	 * @return array<string, int>
	 */
	private static function entry_counts() {
		if ( '' === Module::ENTRY_POST_TYPE || ! post_type_exists( Module::ENTRY_POST_TYPE ) ) {
			return array( 'total' => 0 );
		}

		$counts = wp_count_posts( Module::ENTRY_POST_TYPE );
		$result = array();
		$total  = 0;

		foreach ( self::COUNTED_STATUSES as $status ) {
			$result[ $status ] = isset( $counts->$status ) ? (int) $counts->$status : 0;
			$total            += $result[ $status ];
		}
		$result['total'] = $total;

		return $result;
	}

	/**
	 * Enumerates callable tools for the authenticated user.
	 *
	 * Defensive rules (E78):
	 * - Uses `wp_has_ability()` before `wp_get_ability()` to prevent notices on unregistered tools.
	 * - Evaluates `check_permissions()` dynamically.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function callable_tools() {
		$tools = array();

		foreach ( Abilities_Registrar::names() as $name ) {
			// Not `wp_get_ability()` alone: it raises a notice for a name that is not registered.
			$ability = wp_has_ability( $name ) ? wp_get_ability( $name ) : null;

			if ( ! $ability || true !== $ability->check_permissions() ) {
				continue;
			}

			$meta        = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();

			$tools[] = array(
				'ability'  => $name,
				// What an MCP client lists: the adapter replaces the slash with a hyphen.
				'tool'     => str_replace( '/', '-', $name ),
				'label'    => $ability->get_label(),
				'readonly' => ! empty( $annotations['readonly'] ),
			);
		}

		return $tools;
	}
}
