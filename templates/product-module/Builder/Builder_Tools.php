<?php
/**
 * Build with AI — builder tools callbacks template.
 *
 * Implements MCP abilities:
 *   - `get-block-catalog`
 *   - `validate-block-attributes`
 *   - `insert-block`
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Builder;

use ShapedPlugin\Template\Mcp\Module;
use ShapedPlugin\Template\Mcp\Support\WriteGuard;
use WP_Error;
use WP_Post;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Builder_Tools Class.
 */
class Builder_Tools {

	/**
	 * Input schema of every `block` argument, tools and prompt alike.
	 *
	 * The enum is the product's buildable blocks (`Catalog::BLOCKS`, static data), so the adapter refuses a block that
	 * does not exist before any callback runs. Whether a block is active on this site is checked at call time,
	 * because block registration happens after the abilities are registered. Keep the description short: the
	 * enum already lists the values, and this schema travels with every message (E126).
	 *
	 * @return array<string, mixed>
	 */
	public static function block_schema() {
		return array(
			'type'        => 'string',
			'enum'        => array_values( Catalog::BLOCKS ),
			'description' => __( 'Block to build, picked from the user\'s words.', 'template-domain' ),
		);
	}

	/**
	 * Tool `get-block-catalog`.
	 *
	 * @param mixed $input Input parameters.
	 * @return array|WP_Error
	 */
	public static function get_block_catalog( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$slug  = isset( $input['block'] ) && is_string( $input['block'] ) ? sanitize_key( $input['block'] ) : '';

		if ( '' !== $slug && ! in_array( $slug, Catalog::BLOCKS, true ) ) {
			return new WP_Error(
				'unknown_block',
				sprintf(
					/* translators: 1: block slug, 2: available blocks list */
					__( 'Unknown block "%1$s". Available blocks: %2$s.', 'template-domain' ),
					$slug,
					implode( ', ', Catalog::BLOCKS )
				)
			);
		}

		if ( '' !== $slug ) {
			return Catalog::for_block( $slug );
		}

		$all = array();
		foreach ( Catalog::BLOCKS as $block_slug ) {
			$all[ $block_slug ] = Catalog::for_block( $block_slug, false );
		}

		return array(
			'blocks'       => Catalog::BLOCKS,
			'prefix'       => Catalog::PREFIX,
			'design_rules' => Catalog::DESIGN_RULES,
			'catalog'      => $all,
		);
	}

	/**
	 * Tool `validate-block-attributes`.
	 *
	 * @param mixed $input Input parameters.
	 * @return array|WP_Error
	 */
	public static function validate_block_attributes( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$slug  = isset( $input['block'] ) && is_string( $input['block'] ) ? sanitize_key( $input['block'] ) : '';

		if ( '' === $slug || ! in_array( $slug, Catalog::BLOCKS, true ) ) {
			return new WP_Error(
				'unknown_block',
				sprintf(
					/* translators: %s: block slug */
					__( 'Give a known block slug. Available blocks: %s.', 'template-domain' ),
					implode( ', ', Catalog::BLOCKS )
				)
			);
		}

		$raw_attrs = isset( $input['attributes'] ) && is_array( $input['attributes'] ) ? $input['attributes'] : array();
		$existing  = isset( $input['existing'] ) && is_array( $input['existing'] ) ? $input['existing'] : array();
		$prompt    = isset( $input['prompt'] ) && is_string( $input['prompt'] ) ? substr( trim( $input['prompt'] ), 0, 1000 ) : '';
		$dropped   = array();

		$done = BuildPipeline::finish( $raw_attrs, $slug, $existing, $prompt, $dropped );

		return array(
			'block'            => $slug,
			'attributes'       => (object) $done['attributes'],
			'patch'            => (object) $done['patch'],
			'dropped'          => $dropped,
			'defaults_applied' => $done['defaults_applied'],
		);
	}

	/**
	 * Tool `insert-block`.
	 *
	 * @param mixed $input Input parameters.
	 * @return array|WP_Error
	 */
	public static function insert_block( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$slug  = isset( $input['block'] ) && is_string( $input['block'] ) ? sanitize_key( $input['block'] ) : '';

		if ( '' === $slug || ! in_array( $slug, Catalog::BLOCKS, true ) ) {
			return new WP_Error(
				'unknown_block',
				sprintf(
					/* translators: %s: block slug */
					__( 'Give a known block slug. Available blocks: %s.', 'template-domain' ),
					implode( ', ', Catalog::BLOCKS )
				)
			);
		}

		$target    = is_array( $input['target'] ?? null ) ? $input['target'] : array();
		$has_draft = array_key_exists( 'new_draft', $target );
		$has_post  = array_key_exists( 'post_id', $target );

		if ( $has_draft === $has_post ) {
			return new WP_Error( 'invalid_target', __( 'Give the target either new_draft (a page title) or post_id.', 'template-domain' ) );
		}

		$generated = isset( $input['attributes'] ) && is_array( $input['attributes'] ) ? $input['attributes'] : array();
		$prompt    = isset( $input['prompt'] ) && is_string( $input['prompt'] ) ? substr( trim( $input['prompt'] ), 0, 1000 ) : '';

		// Target: New Draft Page (single call, non-destructive).
		if ( $has_draft ) {
			$key = isset( $input['idempotency_key'] ) && is_string( $input['idempotency_key'] ) ? $input['idempotency_key'] : '';

			return WriteGuard::idempotent(
				Module::SLUG . '/insert-block',
				'new-draft',
				$key,
				static function () use ( $generated, $slug, $target, $prompt ) {
					$taken   = array();
					$dropped = array();
					$done    = BuildPipeline::finish( $generated, $slug, array(), $prompt, $dropped );
					$top     = BlockInserter::build_new( $slug, $done['patch'], $taken );
					$content = serialize_block( $top );
					$post_id = self::create_draft( $target['new_draft'], $content );

					return is_wp_error( $post_id ) ? $post_id : self::saved_result( $post_id, $top, $slug, $dropped, 'new_draft' );
				}
			);
		}

		// Target: Existing post (WriteGuard Level 1 confirmation).
		$post = self::target_post( $target );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$content  = (string) $post->post_content;
		$blocks   = parse_blocks( $content );
		$rewrite  = serialize_blocks( $blocks ) === $content;
		$position = self::position( $target );
		$replace  = isset( $input['replace_block'] ) && is_numeric( $input['replace_block'] ) ? (int) $input['replace_block'] : null;
		$taken    = BlockInserter::unique_ids( $blocks );
		$dropped  = array();

		if ( null !== $replace || is_int( $position ) ) {
			if ( ! $rewrite ) {
				return self::not_rewritable();
			}
		}

		if ( null !== $replace ) {
			$top   = BlockInserter::real_block( $blocks, $replace );
			$found = $top ? BlockInserter::main_of( $top ) : null;
			if ( null === $found ) {
				return new WP_Error( 'invalid_target_block', __( 'Only a matching block can be replaced.', 'template-domain' ) );
			}
			if ( $found[1] !== $slug ) {
				return new WP_Error( 'block_mismatch', __( 'The block to replace is a different block type.', 'template-domain' ), array( 'block' => $found[1] ) );
			}

			$done        = BuildPipeline::finish( $generated, $slug, (array) $found[0]['attrs'], $prompt, $dropped );
			$new_top     = BlockInserter::build_update( $top, $done['patch'], $taken );
			$new_content = serialize_blocks( BlockInserter::replace_at( $blocks, $replace, $new_top ) );
			$action      = 'replace';
		} else {
			$done        = BuildPipeline::finish( $generated, $slug, array(), $prompt, $dropped );
			$new_top     = BlockInserter::build_new( $slug, $done['patch'], $taken );
			$new_content = self::content_with( $content, array( $new_top ), $position );
			if ( is_wp_error( $new_content ) ) {
				return $new_content;
			}
			$action = 'insert';
		}

		$saved = self::guarded_update(
			Module::SLUG . '/insert-block',
			$post,
			$new_content,
			$input,
			array(
				'summary'  => 'replace' === $action
					/* translators: 1: block slug, 2: block index, 3: post title, 4: post status */
					? sprintf( __( 'Replaces block #%2$d (%1$s) in "%3$s" (%4$s).', 'template-domain' ), $slug, (int) $replace, $post->post_title, $post->post_status )
					/* translators: 1: block slug, 2: position, 3: post title, 4: post status */
					: sprintf( __( 'Adds a %1$s at position %2$s of "%3$s" (%4$s).', 'template-domain' ), $slug, (string) $position, $post->post_title, $post->post_status ),
				'action'   => $action,
				'block'    => $slug,
				'settings' => array_keys( $done['patch'] ),
				'dropped'  => $dropped,
			)
		);

		if ( true !== $saved ) {
			return $saved;
		}

		return self::saved_result( $post->ID, $new_top, $slug, $dropped, $action );
	}

	/**
	 * Create a new draft page.
	 *
	 * @param string $title   Page title.
	 * @param string $content Post content.
	 * @return int|WP_Error Post ID on success.
	 */
	public static function create_draft( $title, $content ) {
		if ( ! current_user_can( 'edit_pages' ) && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to create pages.', 'template-domain' ) );
		}

		$title = sanitize_text_field( (string) $title );
		if ( '' === $title ) {
			$title = __( 'Built with AI', 'template-domain' );
		}

		return wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_title'   => $title,
					'post_content' => $content,
				)
			),
			true
		);
	}

	/**
	 * Validate target post for modifications.
	 *
	 * @param array $target Target parameters with post_id.
	 * @return WP_Post|WP_Error
	 */
	public static function target_post( $target ) {
		$post_id = isset( $target['post_id'] ) && is_numeric( $target['post_id'] ) ? (int) $target['post_id'] : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post || in_array( $post->post_type, array( 'revision', 'attachment' ), true ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'template-domain' ) );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to edit this post.', 'template-domain' ) );
		}

		if ( 'trash' === $post->post_status || ! use_block_editor_for_post( $post ) ) {
			return new WP_Error( 'not_block_editor', __( 'This post is not edited with the block editor.', 'template-domain' ) );
		}

		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$locker = wp_check_post_lock( $post );
		if ( $locker ) {
			return new WP_Error(
				'post_locked',
				__( 'Someone is editing this post right now. Try again when they have finished.', 'template-domain' ),
				array( 'user_id' => (int) $locker )
			);
		}

		return $post;
	}

	/**
	 * Read target position.
	 *
	 * @param array $target Target object.
	 * @return string|int
	 */
	public static function position( $target ) {
		$position = array_key_exists( 'position', (array) $target ) ? $target['position'] : 'end';
		return is_numeric( $position ) ? (int) $position : $position;
	}

	/**
	 * Join top blocks with content safely at position.
	 *
	 * @param string     $content  Existing post content.
	 * @param array      $tops     Top-level blocks to place.
	 * @param string|int $position Target position.
	 * @return string|WP_Error
	 */
	public static function content_with( $content, $tops, $position ) {
		$blocks = parse_blocks( (string) $content );

		if ( serialize_blocks( $blocks ) === $content ) {
			$at = 'start' === $position ? 0 : $position;
			foreach ( array_values( $tops ) as $offset => $top ) {
				$blocks = BlockInserter::place( $blocks, $top, is_int( $at ) ? $at + $offset : $at );
				if ( is_wp_error( $blocks ) ) {
					return $blocks;
				}
			}
			return serialize_blocks( $blocks );
		}

		if ( is_int( $position ) ) {
			return self::not_rewritable();
		}

		$added = implode( BlockInserter::SEPARATOR, array_map( 'serialize_block', array_values( $tops ) ) );

		if ( 'start' === $position ) {
			return $added . BlockInserter::SEPARATOR . $content;
		}
		if ( 'end' === $position ) {
			return ( '' === trim( (string) $content ) ? '' : $content . BlockInserter::SEPARATOR ) . $added;
		}

		return new WP_Error( 'invalid_position', __( 'The position must be start, end or a block index.', 'template-domain' ) );
	}

	/**
	 * Guarded post update with WriteGuard preview & confirmation.
	 *
	 * @param string  $ability     Ability name.
	 * @param WP_Post $post        Target post.
	 * @param string  $new_content Serialized content to save.
	 * @param array   $input       Tool input array.
	 * @param array   $preview     Preview data array.
	 * @return true|array|WP_Error
	 */
	public static function guarded_update( $ability, $post, $new_content, $input, $preview ) {
		$entity_key  = 'post:' . $post->ID;
		$fingerprint = WriteGuard::fingerprint_post( $post, array( 'content' => md5( (string) $post->post_content ) ) );

		if ( empty( $input['confirmation_token'] ) ) {
			$preview['post'] = array(
				'id'     => (int) $post->ID,
				'title'  => (string) $post->post_title,
				'status' => (string) $post->post_status,
				'type'   => (string) $post->post_type,
			);

			return WriteGuard::preview( $ability, $entity_key, $fingerprint, $preview, $input );
		}

		$confirmed = WriteGuard::confirm( $ability, $entity_key, $fingerprint, (string) $input['confirmation_token'], $input );
		if ( is_wp_error( $confirmed ) ) {
			return $confirmed;
		}

		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $new_content,
				)
			),
			true
		);

		return is_wp_error( $updated ) ? $updated : true;
	}

	/**
	 * Prepare structured response of saved block.
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $top     Top-level block written.
	 * @param string $slug    Bare block slug.
	 * @param array  $dropped Dropped attributes array.
	 * @param string $action  'new_draft', 'insert', or 'replace'.
	 * @return array
	 */
	public static function saved_result( $post_id, $top, $slug, $dropped, $action ) {
		$post      = get_post( $post_id );
		$main      = BlockInserter::main_of( $top );
		$unique_id = $main && isset( $main[0]['attrs']['uniqueId'] ) ? (string) $main[0]['attrs']['uniqueId'] : '';
		$saved     = self::find_by_unique_id( parse_blocks( $post ? (string) $post->post_content : '' ), $unique_id, Catalog::PREFIX . $slug );
		$intended  = $main ? (array) $main[0]['attrs'] : array();
		$altered   = array();

		foreach ( $intended as $key => $value ) {
			if ( ! $saved || ! array_key_exists( $key, $saved ) || wp_json_encode( $saved[ $key ] ) !== wp_json_encode( $value ) ) {
				$altered[] = $key;
			}
		}

		return array(
			'status'      => 'saved',
			'action'      => $action,
			'post_id'     => (int) $post_id,
			'post_status' => $post ? (string) $post->post_status : '',
			'title'       => $post ? (string) $post->post_title : '',
			'edit_url'    => (string) get_edit_post_link( $post_id, 'raw' ),
			'preview_url' => (string) get_preview_post_link( $post_id ),
			'block'       => $slug,
			'block_name'  => Catalog::PREFIX . $slug,
			'unique_id'   => $unique_id,
			'attributes'  => (object) ( $saved ? $saved : array() ),
			'dropped'     => $dropped,
			'altered'     => $altered,
		);
	}

	/**
	 * Find attribute array of a block by its uniqueId.
	 *
	 * @param array  $blocks    Parsed blocks.
	 * @param string $unique_id uniqueId string.
	 * @param string $name      Full block name.
	 * @return array|null
	 */
	public static function find_by_unique_id( $blocks, $unique_id, $name ) {
		foreach ( (array) $blocks as $block ) {
			if ( isset( $block['blockName'] ) && $name === $block['blockName'] && isset( $block['attrs']['uniqueId'] ) && $unique_id === $block['attrs']['uniqueId'] ) {
				return (array) $block['attrs'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = self::find_by_unique_id( $block['innerBlocks'], $unique_id, $name );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Non-rewritable content error.
	 *
	 * @return WP_Error
	 */
	public static function not_rewritable() {
		return new WP_Error( 'content_not_rewritable', __( 'This post cannot be changed block by block safely. Use position start or end.', 'template-domain' ) );
	}

	/**
	 * Prompt ability callback for build-block (AI builder PRD B7).
	 *
	 * Returns structured MCP prompt messages guiding an AI client through the
	 * three-stage block creation pipeline:
	 * 1. Inspect catalog and design rules (or ready patterns).
	 * 2. Validate proposed attributes with validate-block-attributes.
	 * 3. Save with insert-block (or insert-ready-pattern).
	 *
	 * @param array<string, mixed>|null $input Prompt input arguments.
	 * @return array<string, mixed>
	 */
	public static function build_block_prompt( $input = null ) {
		$input      = is_array( $input ) ? $input : array();
		$request    = isset( $input['request'] ) && is_string( $input['request'] ) && '' !== trim( $input['request'] )
			? trim( $input['request'] )
			: __( 'a clean, modern section', 'template-domain' );
		$block      = isset( $input['block'] ) && is_string( $input['block'] ) ? trim( $input['block'] ) : '';
		$page_title = isset( $input['page_title'] ) && is_string( $input['page_title'] ) && '' !== trim( $input['page_title'] )
			? trim( $input['page_title'] )
			: sprintf(
				/* translators: %s: Product name */
				__( '%s Preview', 'template-domain' ),
				Module::NAME
			);
		$post_id    = isset( $input['post_id'] ) && is_numeric( $input['post_id'] ) && (int) $input['post_id'] > 0
			? (int) $input['post_id']
			: null;

		$site_url = function_exists( 'site_url' ) ? site_url() : 'http://example.test';
		$slug     = Module::SLUG;
		$tools    = array(
			'catalog'        => $slug . '-get-block-catalog',
			'validate'       => $slug . '-validate-block-attributes',
			'insert'         => $slug . '-insert-block',
			'patterns'       => $slug . '-list-ready-patterns',
			'insert_pattern' => $slug . '-insert-ready-pattern',
		);

		$instructions   = array();
		$instructions[] = sprintf(
			/* translators: 1: MCP slug, 2: site address */
			__( 'Use the %1$s MCP tools on %2$s.', 'template-domain' ),
			$slug,
			$site_url
		);
		$instructions[] = sprintf(
			/* translators: 1: product name, 2: user request */
			__( 'Build a %1$s block for this request: "%2$s"', 'template-domain' ),
			Module::NAME,
			$request
		);

		if ( '' !== $block ) {
			$instructions[] = sprintf(
				/* translators: 1: tool name, 2: block slug */
				__( '1. Call `%1$s` with block "%2$s" and follow its rules.', 'template-domain' ),
				$tools['catalog'],
				$block
			);
		} else {
			$instructions[] = sprintf(
				/* translators: %s: tool name */
				__( '1. Pick the best block type for the request or inspect options with `%s`.', 'template-domain' ),
				$tools['catalog']
			);
		}

		$instructions[] = sprintf(
			/* translators: %s: tool name */
			__( '2. Write the attribute JSON and check it with `%s`, passing my request as prompt. Fix anything listed under dropped.', 'template-domain' ),
			$tools['validate']
		);

		if ( null !== $post_id ) {
			$instructions[] = sprintf(
				/* translators: 1: tool name, 2: post id */
				__( '3. Save it with `%1$s` into existing post ID %2$d. Note: Modifying an existing post requires WriteGuard two-step confirmation (call first without a token to get preview and confirmation_token, then confirm with the user before committing).', 'template-domain' ),
				$tools['insert'],
				$post_id
			);
		} else {
			$instructions[] = sprintf(
				/* translators: 1: tool name, 2: page title */
				__( '3. Save it with `%1$s` into a new draft page titled "%2$s" using target.new_draft.', 'template-domain' ),
				$tools['insert'],
				$page_title
			);
		}

		$instructions[] = sprintf(
			/* translators: 1: tool name, 2: tool name */
			__( 'If a ready-made design from `%1$s` fits better, insert that with `%2$s` instead.', 'template-domain' ),
			$tools['patterns'],
			$tools['insert_pattern']
		);

		$instructions[] = __( 'Reply with the preview link.', 'template-domain' );

		$prompt_text = implode( "\n\n", $instructions );

		return array(
			'description' => sprintf(
				/* translators: %s: Product name */
				__( 'Guided prompt for building %s blocks with AI.', 'template-domain' ),
				Module::NAME
			),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $prompt_text,
					),
				),
			),
		);
	}
}
