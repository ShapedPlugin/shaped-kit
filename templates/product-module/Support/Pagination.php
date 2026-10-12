<?php
/**
 * The Pagination support helper template.
 *
 * Copy this file into a product's MCP module (e.g. `Support/Pagination.php`) and update:
 *   1. Namespace: `ShapedPlugin\Template\Mcp\Support` -> `ShapedPlugin\{Product}\Mcp\Support`
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
	 * Extracts and sanitizes `page` and `per_page` parameters from tool input.
	 *
	 * Defensive rules:
	 * - Numeric strings are cast to integers.
	 * - Negative or zero pages fall back to 1.
	 * - Values exceeding MAX_PER_PAGE are clamped to 100.
	 *
	 * @param mixed $input Tool input parameters array.
	 *
	 * @return array{page: int, per_page: int}
	 */
	public static function from_input( $input ) {
		$input = is_array( $input ) ? $input : array();

		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = (int) ( $input['per_page'] ?? self::DEFAULT_PER_PAGE );

		if ( $per_page < 1 ) {
			$per_page = self::DEFAULT_PER_PAGE;
		}

		return array(
			'page'     => $page,
			'per_page' => min( $per_page, self::MAX_PER_PAGE ),
		);
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
}
