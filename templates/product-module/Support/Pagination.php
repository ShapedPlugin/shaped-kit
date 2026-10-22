<?php
/**
 * The Pagination template file: page limits, the list envelope, and the real total past the last page.
 *
 * Copy this file into a product's MCP module. See README.md.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Standard pagination and response envelope formatting for list tools.
 *
 * Why this matters (E17):
 * Unbounded or excessive results quickly blow an AI model's context window.
 * This class guarantees:
 *   - Default page size: 15 items
 *   - Strict upper ceiling: 100 items
 *   - Page ceiling: 10,000 (E181)
 *   - Type coercion and sanitization on raw tool input
 *   - Standardized output structure: items, total, page, per_page, pages
 */
class Pagination {

	/**
	 * Default item count when not specified in tool arguments.
	 */
	const DEFAULT_PER_PAGE = 15;

	/**
	 * Absolute maximum items a client can request in a single page.
	 */
	const MAX_PER_PAGE = 100;

	/**
	 * Highest page a client can ask for. Use it as the schema `maximum` of every `page` argument too.
	 *
	 * Without it, `( page - 1 ) * per_page` becomes a float for a huge page: `array_slice()` throws a TypeError and
	 * WP_Query's offset wraps (E176, E181).
	 */
	const MAX_PAGE = 10000;

	/**
	 * Extracts and sanitizes `page` and `per_page` parameters from tool input.
	 *
	 * Defensive rules:
	 * - Only finite numbers count; anything else gives the default.
	 * - Pages below 1 fall back to 1; pages above MAX_PAGE are clamped to it.
	 * - A page size below 1 falls back to the default; above MAX_PER_PAGE it is clamped to it.
	 *
	 * The schema is the gate over MCP; this is the gate for a tool called from PHP.
	 *
	 * @param mixed $input Tool input parameters array.
	 *
	 * @return array{page: int, per_page: int}
	 */
	public static function from_input( $input ) {
		$input = is_array( $input ) ? $input : array();

		$page     = self::whole_number( $input['page'] ?? null, 1 );
		$per_page = self::whole_number( $input['per_page'] ?? null, self::DEFAULT_PER_PAGE );

		if ( $per_page < 1 ) {
			$per_page = self::DEFAULT_PER_PAGE;
		}

		return array(
			'page'     => (int) min( self::MAX_PAGE, max( 1, $page ) ),
			'per_page' => (int) min( $per_page, self::MAX_PER_PAGE ),
		);
	}

	/**
	 * The real number of matching items when a WP_Query page came back empty.
	 *
	 * WordPress skips its row count when the requested page holds no posts (`class-wp-query.php`,
	 * `set_found_posts()`), so `found_posts` reads 0 past the last page and an agent concludes nothing exists
	 * (E182). Only in that case this runs the same query again for one id on page 1 and takes its count.
	 *
	 * @param \WP_Query $query The page query, already run.
	 * @param array     $args  The arguments it was built from.
	 *
	 * @return int
	 */
	public static function found_posts( \WP_Query $query, array $args ) {
		$found       = (int) $query->found_posts;
		$after_first = (int) ( $args['paged'] ?? 1 ) > 1 || (int) ( $args['offset'] ?? 0 ) > 0;

		if ( $found > 0 || ! empty( $query->posts ) || ! empty( $args['no_found_rows'] ) || ! $after_first ) {
			return $found;
		}

		$count_args = array_merge(
			$args,
			array(
				'paged'                  => 1,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		unset( $count_args['offset'] );

		$count = new \WP_Query( $count_args );

		return (int) $count->found_posts;
	}

	/**
	 * Assembles a standardized pagination envelope.
	 *
	 * @param array $items    Records on current page.
	 * @param int   $total    Total records matching criteria.
	 * @param int   $page     Current page number.
	 * @param int   $per_page Page size applied.
	 *
	 * @return array{items: array, total: int, page: int, per_page: int, pages: int}
	 */
	public static function result( $items, $total, $page, $per_page ) {
		return array(
			'items'    => array_values( $items ),
			'total'    => (int) $total,
			'page'     => (int) $page,
			'per_page' => (int) $per_page,
			'pages'    => max( 1, (int) ceil( $total / max( 1, $per_page ) ) ),
		);
	}

	/**
	 * A whole number from raw input, or the default when the input is not a finite number.
	 *
	 * Compared as a float first, so a value beyond the integer range is clamped by the caller rather than cast.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $fallback Value when the input is not a finite number.
	 *
	 * @return float
	 */
	private static function whole_number( $value, $fallback ) {
		if ( ! is_numeric( $value ) ) {
			return (float) $fallback;
		}

		$number = floor( (float) $value );

		return is_finite( $number ) ? $number : (float) $fallback;
	}
}
