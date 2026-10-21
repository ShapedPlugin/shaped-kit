<?php
/**
 * Build with AI — block inserter and editor parity template.
 *
 * Builds the block an MCP client asked for exactly as the block editor would
 * leave it after the Build with AI modal: its own uniqueId, only attributes that
 * differ from defaults, optional group wrapping, and safe placement on posts.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Builder;

use WP_Block_Type_Registry;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * BlockInserter Class.
 */
class BlockInserter {

	/**
	 * The wrapper group block (bare slug). Empty string if product does not use group wrapping.
	 */
	const GROUP = '';

	/**
	 * Add-on blocks placed before the main block, by the toggle attribute that adds them.
	 */
	const ADDONS_BEFORE = array();

	/**
	 * Add-on blocks placed after the main block, by the toggle attribute that adds them.
	 */
	const ADDONS_AFTER = array();

	/**
	 * `uniqueId` prefix.
	 */
	const ID_PREFIX = 'template-id-';

	/**
	 * What the editor writes between two blocks.
	 */
	const SEPARATOR = "\n\n";

	/**
	 * Build a brand-new top-level block with uniqueId and optional addons.
	 *
	 * @param string $slug  Bare block slug.
	 * @param array  $patch Validated patch attributes.
	 * @param array  $taken Taken uniqueIds on the page; new one is added.
	 * @return array Top-level block structure.
	 */
	public static function build_new( $slug, $patch, array &$taken ) {
		$attrs             = (array) $patch;
		$attrs['uniqueId'] = self::new_id( $taken );

		$type = WP_Block_Type_Registry::get_instance()->get_registered( Catalog::PREFIX . $slug );
		if ( $type ) {
			$defaults = array();
			foreach ( (array) $type->get_attributes() as $k => $schema ) {
				if ( is_array( $schema ) && array_key_exists( 'default', $schema ) ) {
					$defaults[ $k ] = $schema['default'];
				}
			}
			$attrs = array_merge( $defaults, $attrs );
		}

		$main = array(
			'blockName'    => Catalog::PREFIX . $slug,
			'attrs'        => self::comment_attributes( Catalog::PREFIX . $slug, $attrs ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		return self::with_addons( $main, $slug, $taken );
	}

	/**
	 * Update an existing block in place, preserving its uniqueId and neighbours.
	 *
	 * @param array $top   Top-level block found on the page.
	 * @param array $patch Attribute patch to apply.
	 * @param array $taken Taken uniqueIds on the page.
	 * @return array Updated top-level block.
	 */
	public static function build_update( $top, $patch, array &$taken ) {
		$found = self::main_of( $top );
		if ( null === $found ) {
			return $top;
		}

		$existing = (array) $found[0]['attrs'];
		$name     = Catalog::PREFIX . $found[1];
		$merged   = array_merge( $existing, (array) $patch );

		// Preserve original uniqueId.
		if ( isset( $existing['uniqueId'] ) ) {
			$merged['uniqueId'] = $existing['uniqueId'];
		} elseif ( empty( $merged['uniqueId'] ) ) {
			$merged['uniqueId'] = self::new_id( $taken );
		}

		$updated_main = array(
			'blockName'    => $name,
			'attrs'        => self::comment_attributes( $name, $merged ),
			'innerBlocks'  => isset( $found[0]['innerBlocks'] ) ? $found[0]['innerBlocks'] : array(),
			'innerHTML'    => isset( $found[0]['innerHTML'] ) ? $found[0]['innerHTML'] : '',
			'innerContent' => isset( $found[0]['innerContent'] ) ? $found[0]['innerContent'] : array(),
		);

		// If top is the main block directly.
		if ( $found[0] === $top ) {
			return $updated_main;
		}

		// Top is a container group; replace inner main block.
		$inners = isset( $top['innerBlocks'] ) ? $top['innerBlocks'] : array();
		foreach ( $inners as $idx => $inner ) {
			if ( isset( $inner['blockName'] ) && $inner['blockName'] === $name ) {
				$inners[ $idx ] = $updated_main;
				break;
			}
		}
		$top['innerBlocks'] = $inners;

		return $top;
	}

	/**
	 * Place a new block into a parsed block list.
	 *
	 * @param array      $blocks   Parsed blocks on page.
	 * @param array      $top      New top-level block.
	 * @param string|int $position 'start', 'end', or 0-based integer block index.
	 * @return array|WP_Error Updated block list.
	 */
	public static function place( $blocks, $top, $position ) {
		$blocks = is_array( $blocks ) ? array_values( $blocks ) : array();

		if ( 'end' === $position || empty( $blocks ) ) {
			$blocks[] = $top;
			return $blocks;
		}

		if ( 'start' === $position ) {
			array_unshift( $blocks, $top );
			return $blocks;
		}

		if ( is_numeric( $position ) ) {
			$index = (int) $position;
			if ( $index < 0 || $index > count( $blocks ) ) {
				return new WP_Error( 'invalid_position', __( 'Block index out of bounds.', 'template-domain' ) );
			}
			array_splice( $blocks, $index, 0, array( $top ) );
			return $blocks;
		}

		return new WP_Error( 'invalid_position', __( 'Invalid position specified.', 'template-domain' ) );
	}

	/**
	 * Replace a real block at a specific index in a parsed block list.
	 *
	 * @param array $blocks  Parsed blocks.
	 * @param int   $index   0-based index among real (non-empty) blocks.
	 * @param array $new_top Replacement block.
	 * @return array
	 */
	public static function replace_at( $blocks, $index, $new_top ) {
		$real = self::real_indexes( $blocks );
		if ( isset( $real[ $index ] ) ) {
			$blocks[ $real[ $index ] ] = $new_top;
		}
		return $blocks;
	}

	/**
	 * Find a non-empty block at a specific index among real blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param mixed $index  Index among real blocks.
	 * @return array|null
	 */
	public static function real_block( $blocks, $index ) {
		$real = self::real_indexes( $blocks );
		return is_int( $index ) && isset( $real[ $index ] ) ? $blocks[ $real[ $index ] ] : null;
	}

	/**
	 * Collect indices of real (non-empty) top-level blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return int[]
	 */
	private static function real_indexes( $blocks ) {
		$real = array();
		foreach ( array_values( (array) $blocks ) as $i => $block ) {
			if ( ! empty( $block['blockName'] ) || '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				$real[] = $i;
			}
		}
		return $real;
	}

	/**
	 * Find the product's main block inside a top-level block.
	 *
	 * @param array $top Top block.
	 * @return array|null Array of `array( $block, $slug_or_name )` or null.
	 */
	public static function main_of( $top ) {
		if ( ! is_array( $top ) || empty( $top['blockName'] ) ) {
			return null;
		}

		$name = (string) $top['blockName'];
		if ( 0 === strpos( $name, Catalog::PREFIX ) && ( '' === self::GROUP || Catalog::PREFIX . self::GROUP !== $name ) ) {
			$slug = substr( $name, strlen( Catalog::PREFIX ) );
			return array( $top, $slug );
		}

		// Search inner blocks if wrapped in group.
		if ( ! empty( $top['innerBlocks'] ) ) {
			foreach ( $top['innerBlocks'] as $inner ) {
				$found = self::main_of( $inner );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Collect all uniqueIds currently used across a block tree.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return string[]
	 */
	public static function unique_ids( $blocks ) {
		$ids = array();
		foreach ( (array) $blocks as $block ) {
			if ( isset( $block['attrs']['uniqueId'] ) && is_string( $block['attrs']['uniqueId'] ) ) {
				$ids[] = $block['attrs']['uniqueId'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$ids = array_merge( $ids, self::unique_ids( $block['innerBlocks'] ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Generate a fresh uniqueId unique on the page.
	 *
	 * @param array $taken Taken IDs array; new one is appended.
	 * @return string
	 */
	public static function new_id( array &$taken ) {
		do {
			$id = self::ID_PREFIX . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
		} while ( in_array( $id, $taken, true ) );

		$taken[] = $id;
		return $id;
	}

	/**
	 * Public alias for new_id.
	 *
	 * @param array $taken Taken IDs array.
	 * @return string
	 */
	public static function fresh_id( array &$taken ) {
		return self::new_id( $taken );
	}

	/**
	 * Filter attributes so comment serialization matches block editor defaults.
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Attributes to serialize.
	 * @return array
	 */
	public static function comment_attributes( $name, $attrs ) {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( ! $type ) {
			return $attrs;
		}

		$clean = array();
		foreach ( $attrs as $key => $val ) {
			$schema = $type->get_attributes();
			if ( isset( $schema[ $key ]['default'] ) && wp_json_encode( $schema[ $key ]['default'] ) === wp_json_encode( $val ) ) {
				continue;
			}
			$clean[ $key ] = $val;
		}

		return $clean;
	}

	/**
	 * Wrap the main block with group wrapper and add-ons if configured.
	 *
	 * @param array  $main  Main block array.
	 * @param string $slug  Bare block slug.
	 * @param array  $taken Taken IDs array.
	 * @return array
	 */
	private static function with_addons( $main, $slug, array &$taken ) {
		if ( '' === self::GROUP ) {
			return $main;
		}

		$attrs       = (array) $main['attrs'];
		$needs_group = false;

		// Check if any addon toggle is active.
		foreach ( array_keys( self::ADDONS_BEFORE ) as $toggle ) {
			if ( ! empty( $attrs[ $toggle ] ) ) {
				$needs_group = true;
				break;
			}
		}
		if ( ! $needs_group ) {
			foreach ( array_keys( self::ADDONS_AFTER ) as $toggle ) {
				if ( ! empty( $attrs[ $toggle ] ) ) {
					$needs_group = true;
					break;
				}
			}
		}

		if ( ! $needs_group ) {
			return $main;
		}

		$inners = array();
		foreach ( self::ADDONS_BEFORE as $toggle => $addon_slug ) {
			if ( ! empty( $attrs[ $toggle ] ) ) {
				$inners[] = array(
					'blockName'    => Catalog::PREFIX . $addon_slug,
					'attrs'        => array( 'uniqueId' => self::new_id( $taken ) ),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				);
			}
		}

		$inners[] = $main;

		foreach ( self::ADDONS_AFTER as $toggle => $addon_slug ) {
			if ( ! empty( $attrs[ $toggle ] ) ) {
				$inners[] = array(
					'blockName'    => Catalog::PREFIX . $addon_slug,
					'attrs'        => array( 'uniqueId' => self::new_id( $taken ) ),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				);
			}
		}

		return array(
			'blockName'    => Catalog::PREFIX . self::GROUP,
			'attrs'        => array( 'uniqueId' => self::new_id( $taken ) ),
			'innerBlocks'  => $inners,
			'innerHTML'    => '',
			'innerContent' => array_fill( 0, count( $inners ), null ),
		);
	}
}
