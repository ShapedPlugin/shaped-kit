<?php
/**
 * Build with AI — attribute finishing pipeline template.
 *
 * The single finishing step shared by AI generation, MCP builder tools,
 * and future chat interfaces. Sanitizes attributes, applies layout defaults,
 * and builds the complete attribute patch.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Builder;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * BuildPipeline Class.
 */
class BuildPipeline {

	/**
	 * Finish a model's answer for one block.
	 *
	 * @param array  $answer   Decoded model output.
	 * @param string $slug     Bare block slug.
	 * @param array  $existing The block's current attributes ({} for a new block).
	 * @param string $prompt   The user's request (read for column count).
	 * @param array  $dropped  Optional. Filled with `[ { key, reason } ]`.
	 * @return array {
	 *     @type array    $attributes       Validated answer, plus layout defaults on a new block.
	 *     @type array    $patch            What the block receives: preset, answer, column count.
	 *     @type string[] $defaults_applied Layout keys filled in for a new block.
	 * }
	 */
	public static function finish( $answer, $slug, $existing = array(), $prompt = '', &$dropped = null ) {
		$existing   = is_array( $existing ) ? $existing : array();
		$is_fresh   = empty( $existing );
		$attributes = ValueValidator::sanitize( $answer, $slug, $existing, $dropped );

		$defaults_applied = array();
		if ( $is_fresh && isset( Catalog::LAYOUT_KEYS[ $slug ] ) ) {
			$defaults_applied = array_values( array_diff( array_keys( Catalog::LAYOUT_KEYS[ $slug ] ), array_keys( $attributes ) ) );
			$attributes       = array_merge( Catalog::LAYOUT_KEYS[ $slug ], $attributes );
		}

		return array(
			'attributes'       => $attributes,
			'patch'            => AttributePatch::build( $slug, $attributes, array_merge( self::defaults( $slug ), $existing ), (string) $prompt ),
			'defaults_applied' => $defaults_applied,
		);
	}

	/**
	 * Registered default of every attribute of a block.
	 *
	 * @param string $slug Bare block slug.
	 * @return array
	 */
	public static function defaults( $slug ) {
		$defaults = array();

		foreach ( Catalog::schema( $slug ) as $key => $definition ) {
			if ( is_array( $definition ) && array_key_exists( 'default', $definition ) ) {
				$defaults[ $key ] = $definition['default'];
			}
		}

		return $defaults;
	}
}
