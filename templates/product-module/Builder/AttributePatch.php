<?php
/**
 * Build with AI — attribute patch template.
 *
 * Assembles the final patch the block receives: design preset first,
 * AI values on top, and an explicit column count from the prompt.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Builder;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * AttributePatch Class.
 */
class AttributePatch {

	/**
	 * Build the final attribute patch.
	 *
	 * @param string $slug       Bare block slug.
	 * @param array  $attributes Validated AI attributes.
	 * @param array  $existing   The block's existing attributes.
	 * @param string $prompt     User prompt (scanned for column count).
	 * @return array Final patch of attributes to apply.
	 */
	public static function build( $slug, $attributes, $existing, $prompt = '' ) {
		$patch = is_array( $attributes ) ? $attributes : array();

		// Check prompt for explicit column requests (e.g., "3 columns", "4-column").
		$columns = self::columns_from_prompt( (string) $prompt );
		if ( null !== $columns ) {
			$patch = self::apply_columns( $patch, $columns );
		}

		return $patch;
	}

	/**
	 * Read an explicit column count from the prompt text.
	 *
	 * @param string $prompt Prompt string.
	 * @return int|null Column count 1-8, or null.
	 */
	public static function columns_from_prompt( $prompt ) {
		if ( '' === trim( $prompt ) ) {
			return null;
		}

		if ( preg_match( '/\b([1-8])\s*(?:columns?|-columns?)\b/i', $prompt, $matches ) ) {
			return (int) $matches[1];
		}

		$words = array(
			'one'   => 1,
			'two'   => 2,
			'three' => 3,
			'four'  => 4,
			'five'  => 5,
			'six'   => 6,
		);

		foreach ( $words as $word => $num ) {
			if ( preg_match( '/\b' . $word . '\s*(?:columns?|-columns?)\b/i', $prompt ) ) {
				return $num;
			}
		}

		return null;
	}

	/**
	 * Apply column count to responsive columns attribute.
	 *
	 * @param array $patch   Current patch.
	 * @param int   $columns Column count.
	 * @return array
	 */
	public static function apply_columns( $patch, $columns ) {
		$patch['columns'] = array(
			'device' => array(
				'Desktop' => $columns,
				'Tablet'  => min( $columns, 2 ),
				'Mobile'  => 1,
			),
		);

		return $patch;
	}
}
