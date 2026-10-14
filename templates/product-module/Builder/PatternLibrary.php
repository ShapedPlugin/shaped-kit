<?php
/**
 * Build with AI — Ready Patterns library integration template.
 *
 * Reads local cached patterns, fetches remote pattern markup,
 * enforces KSES and block allowlists, and adjusts uniqueIds and query attributes.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Builder;

use WP_Block_Type;
use WP_Block_Type_Registry;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * PatternLibrary Class.
 */
class PatternLibrary {

	/**
	 * Whether this edition may insert patterns flagged `pro`.
	 */
	const PRO_PATTERNS_INSERTABLE = true;

	/**
	 * Upgrade pricing page URL.
	 */
	const UPGRADE_URL = 'https://example.com/pricing/';

	/**
	 * Pattern list endpoint.
	 */
	const LIST_URL = 'https://demo.example.com/wp-json/template-slug/v1/pattern-list';

	/**
	 * Single pattern markup endpoint.
	 */
	const SINGLE_URL = 'https://demo.example.com/wp-json/template-slug/v1/single-pattern';

	/**
	 * Cache folder under uploads directory.
	 */
	const CACHE_DIR = 'template-slug';

	/**
	 * Cache file name.
	 */
	const CACHE_FILE = 'premade-patterns.json';

	/**
	 * Seconds for the list fetch self-healing request.
	 */
	const LIST_TIMEOUT = 5;

	/**
	 * Seconds for single pattern markup fetch.
	 */
	const SINGLE_TIMEOUT = 10;

	/**
	 * Transient cache TTL for fetched markup (reused across preview & confirm).
	 */
	const MARKUP_TTL = 900;

	/**
	 * Core blocks a pattern may not carry.
	 */
	const DENIED_CORE_BLOCKS = array(
		'core/html',
		'core/shortcode',
		'core/freeform',
		'core/legacy-widget',
		'core/widget-group',
		'core/block',
		'core/pattern',
		'core/template-part',
		'core/post-content',
		'core/missing',
	);

	/**
	 * Query attributes the editor resets on import to avoid demo hardcoding.
	 */
	const QUERY_RESET = array(
		'filterBy' => 'latest',
		'limit'    => '6',
		'orderBy'  => 'date',
		'order'    => 'DESC',
	);

	/**
	 * Load pattern list: from cache or one fast fetch when missing.
	 *
	 * @return array|WP_Error
	 */
	public static function load() {
		$path = self::cache_path();
		$data = file_exists( $path ) ? self::decode( (string) file_get_contents( $path ) ) : null;

		if ( null === $data ) {
			$response = wp_safe_remote_get( self::LIST_URL, array( 'timeout' => self::LIST_TIMEOUT ) );
			$body     = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
			$data     = self::decode( $body );

			if ( null === $data ) {
				return new WP_Error(
					'patterns_not_loaded',
					__( 'The pattern library could not be loaded. Please try again later.', 'template-domain' )
				);
			}

			wp_mkdir_p( dirname( $path ) );
			file_put_contents( $path, $body );
		}

		return $data;
	}

	/**
	 * Cache file path.
	 *
	 * @return string
	 */
	public static function cache_path() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . self::CACHE_DIR . '/' . self::CACHE_FILE;
	}

	/**
	 * Decode JSON response into pattern records.
	 *
	 * @param string $body JSON payload.
	 * @return array|null
	 */
	public static function decode( $body ) {
		$json = json_decode( (string) $body, true );
		if ( ! is_array( $json ) || ! isset( $json['patterns'] ) || ! is_array( $json['patterns'] ) ) {
			return null;
		}

		$categories = array();
		foreach ( (array) ( $json['meta_data']['categories'] ?? array() ) as $category ) {
			if ( isset( $category['value'], $category['label'] ) ) {
				$categories[ (int) $category['value'] ] = sanitize_text_field( (string) $category['label'] );
			}
		}

		$records = array();
		foreach ( $json['patterns'] as $block => $list ) {
			foreach ( (array) $list as $item ) {
				if ( ! is_array( $item ) || empty( $item['ID'] ) ) {
					continue;
				}
				$labels = array();
				foreach ( (array) ( $item['category'] ?? array() ) as $cat_id ) {
					if ( isset( $categories[ (int) $cat_id ] ) ) {
						$labels[] = $categories[ (int) $cat_id ];
					}
				}
				$records[] = array(
					'id'          => (int) $item['ID'],
					'name'        => sanitize_text_field( html_entity_decode( (string) ( $item['name'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ),
					'block'       => sanitize_key( (string) $block ),
					'categories'  => $labels,
					'image'       => esc_url_raw( (string) ( $item['image'] ?? '' ) ),
					'preview_url' => esc_url_raw( (string) ( $item['url'] ?? '' ) ),
					'pro'         => ! empty( $item['pro'] ),
					'hits'        => (int) ( $item['hit'] ?? 0 ),
				);
			}
		}

		return array(
			'categories' => $categories,
			'records'    => $records,
		);
	}

	/**
	 * Whether this edition is permitted to insert the pattern.
	 *
	 * @param array $record Pattern record.
	 * @return bool
	 */
	public static function allowed_here( $record ) {
		return self::PRO_PATTERNS_INSERTABLE || empty( $record['pro'] );
	}

	/**
	 * Fetch raw pattern markup with transient caching.
	 *
	 * @param int $id Pattern ID.
	 * @return string|WP_Error
	 */
	public static function markup( $id ) {
		$key    = 'template_prefix_mcp_pattern_' . (int) $id;
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_safe_remote_post(
			self::SINGLE_URL,
			array(
				'timeout' => self::SINGLE_TIMEOUT,
				'body'    => array(
					'license'     => '',
					'template_id' => (int) $id,
				),
			)
		);

		$json = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) || empty( $json['success'] ) || empty( $json['rawData'] ) || ! is_string( $json['rawData'] ) ) {
			return new WP_Error( 'pattern_unavailable', __( 'The pattern could not be fetched from the library. Try again later.', 'template-domain' ) );
		}

		set_transient( $key, $json['rawData'], self::MARKUP_TTL );

		return $json['rawData'];
	}

	/**
	 * Prepare pattern blocks safely for placement.
	 *
	 * @param string $markup Raw pattern markup.
	 * @param array  $taken  Taken uniqueIds array.
	 * @return array|WP_Error Top-level blocks.
	 */
	public static function prepare( $markup, array &$taken ) {
		$blocks = array_values(
			array_filter(
				parse_blocks( (string) $markup ),
				static function ( $block ) {
					return ! empty( $block['blockName'] ) || '' !== trim( (string) $block['innerHTML'] );
				}
			)
		);

		if ( empty( $blocks ) ) {
			return new WP_Error( 'pattern_empty', __( 'The pattern holds no blocks.', 'template-domain' ) );
		}

		$problems = array(
			'denied'       => array(),
			'unregistered' => array(),
		);
		self::check( $blocks, $problems );

		if ( ! empty( $problems['denied'] ) ) {
			return new WP_Error(
				'pattern_not_allowed',
				__( 'The pattern contains blocks that are not allowed here (raw HTML, code or outside content).', 'template-domain' ),
				array( 'blocks' => array_values( array_unique( $problems['denied'] ) ) )
			);
		}

		if ( ! empty( $problems['unregistered'] ) ) {
			return new WP_Error(
				'block_unavailable',
				__( 'The pattern uses blocks that are not active on this site.', 'template-domain' ),
				array( 'missing' => array_values( array_unique( $problems['unregistered'] ) ) )
			);
		}

		$blocks = self::adjust( $blocks, $taken );

		// KSES check for users without unfiltered_html.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$serialized = serialize_blocks( $blocks );
			if ( wp_kses_post( $serialized ) !== $serialized ) {
				return new WP_Error( 'pattern_content_filtered', __( 'The pattern contains markup your account is not allowed to save.', 'template-domain' ) );
			}
		}

		return $blocks;
	}

	/**
	 * Recursively check blocks against denylist and registry.
	 *
	 * @param array $blocks   Parsed blocks.
	 * @param array $problems Problems output array.
	 * @return void
	 */
	private static function check( $blocks, array &$problems ) {
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			if ( '' === $name ) {
				if ( '' !== trim( (string) $block['innerHTML'] ) ) {
					$problems['denied'][] = 'core/freeform';
				}
				continue;
			}

			$own   = 0 === strpos( $name, Catalog::PREFIX );
			$group = '' !== BlockInserter::GROUP && Catalog::PREFIX . BlockInserter::GROUP === $name;

			if ( ! $own && ( 0 !== strpos( $name, 'core/' ) || in_array( $name, self::DENIED_CORE_BLOCKS, true ) ) ) {
				$problems['denied'][] = $name;
			} elseif ( ! $group && ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				$problems['unregistered'][] = $name;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::check( $block['innerBlocks'], $problems );
			}
		}
	}

	/**
	 * Reset query attributes and assign fresh uniqueIds where clashing.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $taken  Taken uniqueIds array.
	 * @return array
	 */
	private static function adjust( $blocks, array &$taken ) {
		foreach ( $blocks as $i => $block ) {
			$name = (string) $block['blockName'];

			if ( 0 === strpos( $name, Catalog::PREFIX ) && ( '' === BlockInserter::GROUP || Catalog::PREFIX . BlockInserter::GROUP !== $name ) ) {
				$attrs = (array) $block['attrs'];
				foreach ( self::QUERY_RESET as $key => $value ) {
					if ( array_key_exists( $key, $attrs ) ) {
						$attrs[ $key ] = $value;
					}
				}

				$id = isset( $attrs['uniqueId'] ) ? (string) $attrs['uniqueId'] : '';
				if ( '' === $id || in_array( $id, $taken, true ) ) {
					$attrs['uniqueId'] = BlockInserter::fresh_id( $taken );
				} else {
					$taken[] = $id;
				}

				$type  = WP_Block_Type_Registry::get_instance()->get_registered( $name );
				$attrs = array_merge( $type ? self::defaults_of( $type ) : array(), $attrs );

				$blocks[ $i ]['attrs'] = BlockInserter::comment_attributes( $name, $attrs );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = self::adjust( $block['innerBlocks'], $taken );
			}
		}

		return $blocks;
	}

	/**
	 * Default attributes of a registered block type.
	 *
	 * @param WP_Block_Type $type Block type instance.
	 * @return array
	 */
	private static function defaults_of( $type ) {
		$defaults = array();
		foreach ( (array) $type->get_attributes() as $key => $schema ) {
			if ( is_array( $schema ) && array_key_exists( 'default', $schema ) ) {
				$defaults[ $key ] = $schema['default'];
			}
		}
		return $defaults;
	}
}
