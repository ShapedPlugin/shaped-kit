<?php
/**
 * Build with AI — attribute vocabulary and catalog template.
 *
 * Defines which attributes a block accepts, what values they may take,
 * and design rules for AI generation.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Builder;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Catalog Class.
 */
class Catalog {

	/**
	 * Block name prefix, including trailing slash.
	 */
	const PREFIX = 'template-slug/';

	/**
	 * Every block Build with AI can build, in router preference order.
	 */
	const BLOCKS = array(
		'carousel',
		'grid',
	);

	/**
	 * The attributes a block needs before it leaves its first-insert picker,
	 * with the value a fresh AI insert falls back to.
	 */
	const LAYOUT_KEYS = array(
		'carousel' => array( 'layout' => 'default' ),
		'grid'     => array( 'layout' => 'default' ),
	);

	/**
	 * Keys the model never sets: identity, editor plumbing, IDs it cannot know,
	 * raw code, and behaviour that belongs to the site owner.
	 */
	const DENIED_KEYS = array(
		'uniqueId',
		'parentId',
		'blockName',
		'template',
		'align',
		'isPreview',
		'fontLists',
		'preloader',
		'hideOnDesktop',
		'hideOnTablet',
		'hideOnMobile',
		'customClassName',
		'customIdName',
		'customCSS',
		'customJS',
	);

	/**
	 * Allowed values for string attributes, shared by every block that has them.
	 */
	const ENUMS = array(
		'columns'       => array( '1', '2', '3', '4', '5', '6' ),
		'alignment'     => array( 'left', 'center', 'right' ),
		'titleTag'      => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span' ),
		'cardHover'     => array( 'none', 'scale-up', 'slide-up' ),
	);

	/**
	 * Per-block enum overrides, where a block offers a different set.
	 */
	const BLOCK_ENUMS = array();

	/**
	 * String attributes that take free text (labels and messages).
	 */
	const FREE_TEXT = array(
		'readMoreLabel',
		'buttonLabel',
	);

	/**
	 * Leaf string enums inside composite settings objects.
	 */
	const LEAF_ENUMS = array(
		'border.style'    => array( 'none', 'solid', 'dashed', 'dotted', 'double' ),
		'shadow.unit'     => array( 'Outset', 'Inset' ),
		'bg.style'        => array( 'solid', 'gradient' ),
		'font.fontWeight' => array( '100', '200', '300', '400', '500', '600', '700', '800', '900' ),
		'font.style'      => array( 'normal', 'italic' ),
		'font.transform'  => array( 'none', 'uppercase', 'lowercase', 'capitalize' ),
		'font.decoration' => array( 'none', 'underline', 'line-through', 'overline' ),
		'length.unit'     => array( 'px', '%', 'em', 'rem' ),
	);

	/**
	 * Numeric ranges for leaves: array( min, max ).
	 */
	const NUMBER_RANGES = array(
		'columns' => array( 1, 8 ),
	);

	/**
	 * Design rules for the block catalog prompt.
	 */
	const DESIGN_RULES = array(
		'contrast' => 'Text and background colors must have strong readability contrast.',
		'spacing'  => 'Keep consistent padding (16-32px) and clean typography hierarchies.',
	);

	/**
	 * Worked examples shown to AI models in get-block-catalog.
	 */
	const WORKED_EXAMPLES = array();

	/**
	 * Registered attribute schema of a block.
	 *
	 * @param string $slug Bare block slug.
	 * @return array<string, array> Attribute definitions, or an empty array.
	 */
	public static function schema( $slug ) {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return array();
		}

		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( self::PREFIX . $slug );
		if ( ! $block_type ) {
			return array();
		}

		$attributes = $block_type->get_attributes();
		return is_array( $attributes ) ? $attributes : array();
	}

	/**
	 * Attributes the model may set on a block.
	 *
	 * @param string $slug Bare block slug.
	 * @return array<string, array> Keyed by attribute name.
	 */
	public static function editable_attributes( $slug ) {
		$all      = self::all_attributes( $slug );
		$editable = array();

		foreach ( $all as $key => $meta ) {
			if ( ! self::is_denied( $key ) ) {
				$editable[ $key ] = $meta;
			}
		}

		return $editable;
	}

	/**
	 * All attributes of a block with their schema and allowed values.
	 *
	 * @param string $slug Bare block slug.
	 * @return array<string, array>
	 */
	public static function all_attributes( $slug ) {
		$schema = self::schema( $slug );
		$result = array();

		foreach ( $schema as $key => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$result[ $key ] = array(
				'definition'     => $definition,
				'allowed_values' => self::allowed_values( $slug, $key, $definition ),
			);
		}

		return $result;
	}

	/**
	 * Allowed values of an attribute.
	 *
	 * @param string $slug       Bare block slug.
	 * @param string $key        Attribute key.
	 * @param array  $definition Attribute schema definition.
	 * @return array|null
	 */
	public static function allowed_values( $slug, $key, $definition ) {
		if ( isset( self::BLOCK_ENUMS[ $slug ][ $key ] ) ) {
			return self::BLOCK_ENUMS[ $slug ][ $key ];
		}
		if ( isset( self::ENUMS[ $key ] ) ) {
			return self::ENUMS[ $key ];
		}
		if ( isset( $definition['enum'] ) && is_array( $definition['enum'] ) ) {
			return $definition['enum'];
		}
		return null;
	}

	/**
	 * Whether an attribute key is denied to AI models.
	 *
	 * @param string $key Attribute key.
	 * @return bool
	 */
	public static function is_denied( $key ) {
		return in_array( $key, self::DENIED_KEYS, true );
	}

	/**
	 * Format catalog summary for an AI client.
	 *
	 * @param string $slug             Bare block slug.
	 * @param bool   $include_examples Whether to include worked examples.
	 * @return array
	 */
	public static function for_block( $slug, $include_examples = true ) {
		$editable = self::editable_attributes( $slug );
		$catalog  = array();

		foreach ( $editable as $key => $meta ) {
			$entry = array(
				'type' => isset( $meta['definition']['type'] ) ? $meta['definition']['type'] : 'any',
			);
			if ( null !== $meta['allowed_values'] ) {
				$entry['allowed_values'] = $meta['allowed_values'];
			}
			if ( isset( $meta['definition']['default'] ) ) {
				$entry['default'] = $meta['definition']['default'];
			}
			$catalog[ $key ] = $entry;
		}

		$response = array(
			'block'        => $slug,
			'prefix'       => self::PREFIX,
			'attributes'   => $catalog,
			'design_rules' => self::DESIGN_RULES,
		);

		if ( $include_examples && isset( self::WORKED_EXAMPLES[ $slug ] ) ) {
			$response['examples'] = self::WORKED_EXAMPLES[ $slug ];
		}

		return $response;
	}
}
