<?php
/**
 * Build with AI — attribute validator template.
 *
 * Everything the model returns passes through here before it reaches the
 * editor. Checks every leaf against the shape of the attribute's registered
 * default: wrong types, unlisted values, or unknown child keys are dropped with reasons.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Builder;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * ValueValidator Class.
 */
class ValueValidator {

	/**
	 * Generic numeric bounds for lengths and offsets.
	 */
	const NUMBER_MIN = -2000;

	/**
	 * Generic numeric ceiling.
	 */
	const NUMBER_MAX = 5000;

	/**
	 * Longest free-text value accepted.
	 */
	const TEXT_MAX = 300;

	/**
	 * CSS functions allowed inside a gradient.
	 */
	const GRADIENT_FUNCTIONS = array( 'rgb', 'rgba', 'hsl', 'hsla', 'var', 'calc' );

	/**
	 * Attribute whose shaped value is being validated (root of a dotted path).
	 *
	 * @var string
	 */
	private static $path_root = '';

	/**
	 * Values dropped inside the attribute being validated.
	 *
	 * @var array<int, array{key: string, reason: string}>
	 */
	private static $leaf_drops = array();

	/**
	 * Validate an AI answer against a block's catalog.
	 *
	 * @param array  $generated Decoded model output.
	 * @param string $slug      Bare block slug.
	 * @param array  $existing  The block's current attributes ({} on a fresh insert).
	 * @param array  $dropped   Optional. Filled with `[ { key, reason } ]`.
	 * @return array Only the attributes that changed, validated.
	 */
	public static function sanitize( $generated, $slug, $existing = array(), &$dropped = null ) {
		$editable = Catalog::editable_attributes( $slug );
		$result   = array();
		$dropped  = array();

		foreach ( (array) $generated as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( $editable[ $key ] ) ) {
				$dropped[] = array(
					'key'    => (string) $key,
					'reason' => self::drop_reason( $slug, $key ),
				);
				continue;
			}

			$definition       = $editable[ $key ]['definition'];
			$default          = isset( $definition['default'] ) ? $definition['default'] : null;
			$current          = array_key_exists( $key, (array) $existing ) ? $existing[ $key ] : $default;
			self::$path_root  = $key;
			self::$leaf_drops = array();

			$valid = self::validate_value( $value, $definition, $current, $slug, $key );

			if ( null !== $valid ) {
				$result[ $key ] = $valid;
			} elseif ( ! empty( self::$leaf_drops ) ) {
				$dropped = array_merge( $dropped, self::$leaf_drops );
			} else {
				$dropped[] = array(
					'key'    => (string) $key,
					'reason' => 'invalid_value',
				);
			}

			if ( null !== $valid && ! empty( self::$leaf_drops ) ) {
				$dropped = array_merge( $dropped, self::$leaf_drops );
			}
		}

		return $result;
	}

	/**
	 * Why an attribute key is not accepted.
	 *
	 * @param string $slug Bare block slug.
	 * @param string $key  Attribute key.
	 * @return string `denied_key`, `not_editable`, or `unknown_key`.
	 */
	public static function drop_reason( $slug, $key ) {
		if ( Catalog::is_denied( $key ) ) {
			return 'denied_key';
		}

		$all = Catalog::all_attributes( $slug );
		if ( isset( $all[ $key ] ) ) {
			return 'not_editable';
		}

		return 'unknown_key';
	}

	/**
	 * Validate a value against its schema definition.
	 *
	 * @param mixed  $value      Proposed value.
	 * @param array  $definition Schema definition.
	 * @param mixed  $current    Current value.
	 * @param string $slug       Bare block slug.
	 * @param string $key        Attribute key.
	 * @return mixed|null Validated value or null if invalid.
	 */
	private static function validate_value( $value, $definition, $current, $slug, $key ) {
		$type = isset( $definition['type'] ) ? $definition['type'] : 'string';

		switch ( $type ) {
			case 'boolean':
				return is_bool( $value ) ? $value : null;

			case 'integer':
			case 'number':
				if ( ! is_numeric( $value ) ) {
					return null;
				}
				$num = 'integer' === $type ? (int) $value : (float) $value;
				if ( isset( Catalog::NUMBER_RANGES[ $key ] ) ) {
					$range = Catalog::NUMBER_RANGES[ $key ];
					return ( $num >= $range[0] && $num <= $range[1] ) ? $num : null;
				}
				return ( $num >= self::NUMBER_MIN && $num <= self::NUMBER_MAX ) ? $num : null;

			case 'string':
				if ( ! is_string( $value ) ) {
					return null;
				}
				$allowed = Catalog::allowed_values( $slug, $key, $definition );
				if ( null !== $allowed ) {
					return in_array( $value, $allowed, true ) ? $value : null;
				}
				return sanitize_text_field( substr( $value, 0, self::TEXT_MAX ) );

			case 'array':
				return is_array( $value ) ? self::sanitize_array( $value, $definition ) : null;

			case 'object':
				return is_array( $value ) ? self::sanitize_object( $value, $definition, $current, $key ) : null;

			default:
				return $value;
		}
	}

	/**
	 * Sanitize an array of items.
	 *
	 * @param array $raw_array  Input array.
	 * @param array $definition Schema definition.
	 * @return array
	 */
	private static function sanitize_array( $raw_array, $definition ) {
		$items = array();
		foreach ( $raw_array as $item ) {
			if ( is_scalar( $item ) ) {
				$items[] = sanitize_text_field( (string) $item );
			}
		}
		return $items;
	}

	/**
	 * Sanitize an object (associative array).
	 *
	 * @param array  $raw_object Input associative array.
	 * @param array  $definition Schema definition.
	 * @param mixed  $current    Current object value.
	 * @param string $key        Attribute key.
	 * @return array|null
	 */
	private static function sanitize_object( $raw_object, $definition, $current, $key ) {
		$current = is_array( $current ) ? $current : array();
		$merged  = $current;

		foreach ( $raw_object as $sub_key => $sub_val ) {
			$path = self::$path_root . '.' . $sub_key;

			// Validate color values.
			if ( false !== strpos( strtolower( $sub_key ), 'color' ) ) {
				$color = self::sanitize_color( $sub_val );
				if ( null !== $color ) {
					$merged[ $sub_key ] = $color;
				} else {
					self::$leaf_drops[] = array(
						'key'    => $path,
						'reason' => 'invalid_value',
					);
				}
				continue;
			}

			// Validate gradients (BL19).
			if ( 'gradient' === $sub_key ) {
				$gradient = self::sanitize_gradient( $sub_val );
				if ( null !== $gradient ) {
					$merged[ $sub_key ] = $gradient;
				} else {
					self::$leaf_drops[] = array(
						'key'    => $path,
						'reason' => 'invalid_value',
					);
				}
				continue;
			}

			if ( is_scalar( $sub_val ) ) {
				$merged[ $sub_key ] = sanitize_text_field( (string) $sub_val );
			}
		}

		return $merged;
	}

	/**
	 * Sanitize a CSS color value (#hex, rgb, rgba, hsl, hsla).
	 *
	 * @param mixed $color Color string.
	 * @return string|null
	 */
	public static function sanitize_color( $color ) {
		if ( ! is_string( $color ) ) {
			return null;
		}
		$color = trim( $color );
		if ( '' === $color ) {
			return '';
		}

		// Hex match.
		if ( preg_match( '/^#([A-Fa-f0-9]{3,4}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $color ) ) {
			return $color;
		}

		// Functional rgb/rgba/hsl/hsla match.
		if ( preg_match( '/^(rgb|rgba|hsl|hsla)\(\s*[0-9.%\s,]+\s*\)$/i', $color ) ) {
			return $color;
		}

		return null;
	}

	/**
	 * Sanitize a CSS gradient (BL19: ensures single gradient layer, no url() injections).
	 *
	 * @param mixed $gradient Gradient string.
	 * @return string|null
	 */
	public static function sanitize_gradient( $gradient ) {
		if ( ! is_string( $gradient ) ) {
			return null;
		}
		$gradient = trim( $gradient );

		// Deny dangerous characters or outside URLs.
		if ( false !== strpos( strtolower( $gradient ), 'url(' ) || false !== strpos( $gradient, ';' ) ) {
			return null;
		}

		// Must start with valid linear-gradient or radial-gradient.
		if ( ! preg_match( '/^(linear-gradient|radial-gradient)\(/i', $gradient ) ) {
			return null;
		}

		return sanitize_text_field( $gradient );
	}
}
