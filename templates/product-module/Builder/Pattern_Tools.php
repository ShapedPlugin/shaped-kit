<?php
/**
 * Build with AI — ready patterns tools callbacks template.
 *
 * Implements MCP abilities:
 *   - `list-ready-patterns`
 *   - `insert-ready-pattern`
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Builder;

use ShapedPlugin\Template\Mcp\Module;
use ShapedPlugin\Template\Mcp\Support\Pagination;
use ShapedPlugin\Template\Mcp\Support\WriteGuard;
use WP_Block_Type_Registry;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Pattern_Tools Class.
 */
class Pattern_Tools {

	/**
	 * Tool `list-ready-patterns`.
	 *
	 * @param mixed $input Input parameters.
	 * @return array|WP_Error
	 */
	public static function list_ready_patterns( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$library = PatternLibrary::load();
		if ( is_wp_error( $library ) ) {
			return $library;
		}

		$block    = isset( $input['block'] ) && is_string( $input['block'] ) ? sanitize_key( $input['block'] ) : '';
		$category = isset( $input['category'] ) && is_string( $input['category'] ) ? strtolower( trim( $input['category'] ) ) : '';
		$search   = isset( $input['search'] ) && is_string( $input['search'] ) ? strtolower( trim( $input['search'] ) ) : '';
		$free     = isset( $input['pro'] ) && 'exclude' === $input['pro'];

		$records = array_values(
			array_filter(
				$library['records'],
				static function ( $record ) use ( $block, $category, $search, $free ) {
					if ( '' !== $block && $block !== $record['block'] ) {
						return false;
					}
					if ( $free && $record['pro'] ) {
						return false;
					}
					if ( '' !== $search && false === strpos( strtolower( $record['name'] . ' ' . implode( ' ', $record['categories'] ) ), $search ) ) {
						return false;
					}
					if ( '' !== $category ) {
						foreach ( $record['categories'] as $label ) {
							if ( false !== strpos( strtolower( $label ), $category ) ) {
								return true;
							}
						}
						return false;
					}
					return true;
				}
			)
		);

		// Order: usable first, then Pro-only.
		$usable  = array_values( array_filter( $records, array( PatternLibrary::class, 'allowed_here' ) ) );
		$others  = array_values(
			array_filter(
				$records,
				static function ( $record ) {
					return ! PatternLibrary::allowed_here( $record );
				}
			)
		);
		$records = array_merge( $usable, $others );

		$pagination = Pagination::from_input( $input );
		$slice      = array_slice( $records, ( $pagination['page'] - 1 ) * $pagination['per_page'], $pagination['per_page'] );
		$items      = array_map( array( __CLASS__, 'item' ), $slice );
		$result     = Pagination::result( $items, count( $records ), $pagination['page'], $pagination['per_page'] );

		if ( ! empty( $others ) ) {
			$result['note'] = __( 'Patterns with insertable false need the Pro edition. Offer one only when it fits, and inform the user it requires an upgrade.', 'template-domain' );
		}

		return $result;
	}

	/**
	 * Transform single pattern record for client response.
	 *
	 * @param array $record Pattern record.
	 * @return array
	 */
	public static function item( $record ) {
		if ( ! PatternLibrary::allowed_here( $record ) ) {
			return array(
				'id'          => $record['id'],
				'name'        => $record['name'],
				'block'       => $record['block'],
				'pro'         => true,
				'insertable'  => false,
				'upgrade_url' => PatternLibrary::UPGRADE_URL,
			);
		}

		return array(
			'id'          => $record['id'],
			'name'        => $record['name'],
			'block'       => $record['block'],
			'categories'  => $record['categories'],
			'image'       => $record['image'],
			'preview_url' => $record['preview_url'],
			'pro'         => $record['pro'],
			'insertable'  => self::block_active( $record['block'] ),
		);
	}

	/**
	 * Tool `insert-ready-pattern`.
	 *
	 * @param mixed $input Input parameters.
	 * @return array|WP_Error
	 */
	public static function insert_ready_pattern( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$library = PatternLibrary::load();
		if ( is_wp_error( $library ) ) {
			return $library;
		}

		$id     = isset( $input['pattern_id'] ) && is_numeric( $input['pattern_id'] ) ? (int) $input['pattern_id'] : 0;
		$record = null;
		foreach ( $library['records'] as $candidate ) {
			if ( $id === $candidate['id'] ) {
				$record = $candidate;
				break;
			}
		}

		if ( null === $record ) {
			return new WP_Error( 'pattern_not_found', __( 'No pattern with this ID. Use list-ready-patterns.', 'template-domain' ) );
		}

		if ( ! PatternLibrary::allowed_here( $record ) ) {
			return new WP_Error(
				'pro_required',
				__( 'This pattern requires the Pro edition.', 'template-domain' ),
				array( 'upgrade_url' => PatternLibrary::UPGRADE_URL )
			);
		}

		if ( ! self::block_active( $record['block'] ) ) {
			return new WP_Error(
				'block_unavailable',
				__( 'This pattern is built on a block that is not active on this site.', 'template-domain' ),
				array( 'missing' => array( $record['block'] ) )
			);
		}

		$target    = is_array( $input['target'] ?? null ) ? $input['target'] : array();
		$has_draft = array_key_exists( 'new_draft', $target );
		$has_post  = array_key_exists( 'post_id', $target );
		if ( $has_draft === $has_post ) {
			return new WP_Error( 'invalid_target', __( 'Give the target either new_draft (a page title) or post_id.', 'template-domain' ) );
		}

		// Target: New Draft.
		if ( $has_draft ) {
			$key = isset( $input['idempotency_key'] ) && is_string( $input['idempotency_key'] ) ? $input['idempotency_key'] : '';

			return WriteGuard::idempotent(
				Module::SLUG . '/insert-ready-pattern',
				'new-draft',
				$key,
				static function () use ( $record, $target ) {
					$taken = array();
					$tops  = self::prepared( $record, $taken );
					if ( is_wp_error( $tops ) ) {
						return $tops;
					}
					$content = self::join( $tops );
					$post_id = Builder_Tools::create_draft( $target['new_draft'], $content );

					return is_wp_error( $post_id ) ? $post_id : self::result( $post_id, $record, $tops, $content, 'new_draft' );
				}
			);
		}

		// Target: Existing post.
		$post = Builder_Tools::target_post( $target );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$taken = BlockInserter::unique_ids( parse_blocks( (string) $post->post_content ) );
		$tops  = self::prepared( $record, $taken );
		if ( is_wp_error( $tops ) ) {
			return $tops;
		}

		$position    = Builder_Tools::position( $target );
		$new_content = Builder_Tools::content_with( (string) $post->post_content, $tops, $position );
		if ( is_wp_error( $new_content ) ) {
			return $new_content;
		}

		$saved = Builder_Tools::guarded_update(
			Module::SLUG . '/insert-ready-pattern',
			$post,
			$new_content,
			$input,
			array(
				/* translators: 1: pattern name, 2: position, 3: post title, 4: post status */
				'summary' => sprintf( __( 'Adds the "%1$s" pattern at position %2$s of "%3$s" (%4$s).', 'template-domain' ), $record['name'], (string) $position, $post->post_title, $post->post_status ),
				'action'  => 'insert',
				'pattern' => array(
					'id'    => $record['id'],
					'name'  => $record['name'],
					'block' => $record['block'],
				),
				'blocks'  => self::names( $tops ),
			)
		);

		if ( true !== $saved ) {
			return $saved;
		}

		return self::result( $post->ID, $record, $tops, $new_content, 'insert' );
	}

	/**
	 * Prepare pattern blocks safely.
	 *
	 * @param array $record Pattern record.
	 * @param array $taken  Taken uniqueIds array.
	 * @return array|WP_Error
	 */
	private static function prepared( $record, array &$taken ) {
		$markup = PatternLibrary::markup( $record['id'] );
		return is_wp_error( $markup ) ? $markup : PatternLibrary::prepare( $markup, $taken );
	}

	/**
	 * Join top-level blocks into markup.
	 *
	 * @param array $tops Blocks array.
	 * @return string
	 */
	private static function join( $tops ) {
		return implode( BlockInserter::SEPARATOR, array_map( 'serialize_block', array_values( $tops ) ) );
	}

	/**
	 * List all block names in order.
	 *
	 * @param array $blocks Blocks array.
	 * @return string[]
	 */
	private static function names( $blocks ) {
		$names = array();
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$names[] = (string) $block['blockName'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$names = array_merge( $names, self::names( $block['innerBlocks'] ) );
			}
		}
		return $names;
	}

	/**
	 * Check if block is registered.
	 *
	 * @param string $block Bare block slug.
	 * @return bool
	 */
	private static function block_active( $block ) {
		return in_array( $block, Catalog::BLOCKS, true ) && WP_Block_Type_Registry::get_instance()->is_registered( Catalog::PREFIX . $block );
	}

	/**
	 * Build structured response for pattern insertion.
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $record  Pattern record.
	 * @param array  $tops    Blocks inserted.
	 * @param string $content Content intended to be saved.
	 * @param string $action  'new_draft' or 'insert'.
	 * @return array
	 */
	private static function result( $post_id, $record, $tops, $content, $action ) {
		$post = get_post( $post_id );

		return array(
			'status'      => 'saved',
			'action'      => $action,
			'post_id'     => (int) $post_id,
			'post_status' => $post ? (string) $post->post_status : '',
			'title'       => $post ? (string) $post->post_title : '',
			'edit_url'    => (string) get_edit_post_link( $post_id, 'raw' ),
			'preview_url' => (string) get_preview_post_link( $post_id ),
			'pattern'     => array(
				'id'    => $record['id'],
				'name'  => $record['name'],
				'block' => $record['block'],
			),
			'blocks'      => self::names( $tops ),
			'altered'     => ! $post || (string) $post->post_content !== $content,
		);
	}
}
