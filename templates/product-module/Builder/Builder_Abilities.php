<?php
/**
 * Build with AI — builder ability definitions helper template.
 *
 * Provides ready ability definitions and toolset mappings for the 5 AI Builder
 * abilities to hook into `Abilities_Registrar.php`.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp\Builder;

use ShapedPlugin\Template\Mcp\Module;
use ShapedPlugin\Template\Mcp\Permission_Gate;
use ShapedPlugin\Template\Mcp\Support\Pagination;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Builder_Abilities Class.
 */
class Builder_Abilities {

	/**
	 * Definitions for the 5 AI Builder abilities.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions() {
		$prefix = Module::SLUG . '/';

		return array(
			$prefix . 'get-block-catalog'         => array(
				'label'               => __( 'Get Block Catalog', 'template-domain' ),
				'description'         => __( 'Returns the settings a block accepts, with design rules and examples, so your AI client can design it.', 'template-domain' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'block' => array_merge(
							Builder_Tools::block_schema(),
							array( 'description' => __( 'Optional block slug. When omitted, lists all available blocks.', 'template-domain' ) )
						),
					),
				),
				'execute_callback'    => array( Builder_Tools::class, 'get_block_catalog' ),
				'permission_callback' => array( Permission_Gate::class, 'can_access' ),
				'annotations'         => array(
					'readonly'      => true,
					'destructive'   => false,
					'openWorldHint' => false,
				),
			),
			$prefix . 'validate-block-attributes' => array(
				'label'               => __( 'Validate Block Attributes', 'template-domain' ),
				'description'         => __( 'Checks block settings written by an AI, lists dropped keys with reasons, and returns the final patch.', 'template-domain' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'block', 'attributes' ),
					'properties'           => array(
						'block'      => Builder_Tools::block_schema(),
						'attributes' => array(
							'type'                 => 'object',
							'description'          => __( 'Attributes the model proposes.', 'template-domain' ),
							'additionalProperties' => true,
						),
						'existing'   => array(
							'type'                 => 'object',
							'description'          => __( 'Current block attributes when updating an existing block.', 'template-domain' ),
							'additionalProperties' => true,
						),
						'prompt'     => array(
							'type'        => 'string',
							'maxLength'   => 1000,
							'description' => __( 'The user prompt, used to read an explicit column count.', 'template-domain' ),
						),
					),
				),
				'execute_callback'    => array( Builder_Tools::class, 'validate_block_attributes' ),
				'permission_callback' => array( Permission_Gate::class, 'can_access' ),
				'annotations'         => array(
					'readonly'      => true,
					'destructive'   => false,
					'openWorldHint' => false,
				),
			),
			$prefix . 'insert-block'              => array(
				'label'               => __( 'Insert Block', 'template-domain' ),
				'description'         => __( 'Saves a configured block into a new draft page, or into an existing page after a confirmation step.', 'template-domain' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'block', 'target' ),
					'properties'           => array(
						'block'              => Builder_Tools::block_schema(),
						'attributes'         => array(
							'type'                 => 'object',
							'description'          => __( 'Block attributes to apply.', 'template-domain' ),
							'additionalProperties' => true,
						),
						'prompt'             => array(
							'type'        => 'string',
							'maxLength'   => 1000,
							'description' => __( 'User prompt.', 'template-domain' ),
						),
						'target'             => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'description'          => __( 'Either new_draft (a page title) or post_id with an optional position.', 'template-domain' ),
							'properties'           => array(
								'new_draft' => array(
									'type'      => 'string',
									'maxLength' => 200,
								),
								'post_id'   => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'position'  => array(
									'type'        => array( 'string', 'integer' ),
									'description' => __( 'end (default), start, or the index of the block to insert before.', 'template-domain' ),
								),
							),
						),
						'replace_block'      => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Index of an existing block on the post to replace in place.', 'template-domain' ),
						),
						'idempotency_key'    => array(
							'type'        => 'string',
							'maxLength'   => 64,
							'description' => __( 'Optional retry idempotency key.', 'template-domain' ),
						),
						'confirmation_token' => array(
							'type'        => 'string',
							'description' => __( 'Token from the preview step, required to change an existing post.', 'template-domain' ),
						),
					),
				),
				'execute_callback'    => array( Builder_Tools::class, 'insert_block' ),
				'permission_callback' => array( Permission_Gate::class, 'can_access' ),
				'annotations'         => array(
					'readonly'      => false,
					'destructive'   => false,
					'openWorldHint' => false,
				),
			),
			$prefix . 'list-ready-patterns'       => array(
				'label'               => __( 'List Ready Patterns', 'template-domain' ),
				'description'         => __( 'List ready-made designs from the pattern library, filterable by block, category or keywords.', 'template-domain' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'block'    => array(
							'type'        => 'string',
							'description' => __( 'Block the pattern is built on.', 'template-domain' ),
						),
						'category' => array(
							'type'        => 'string',
							'description' => __( 'Category filter.', 'template-domain' ),
						),
						'search'   => array(
							'type'        => 'string',
							'maxLength'   => 100,
							'description' => __( 'Keywords to match.', 'template-domain' ),
						),
						'pro'      => array(
							'type'        => 'string',
							'enum'        => array( 'include', 'exclude' ),
							'description' => __( 'exclude lists only Free patterns.', 'template-domain' ),
						),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => Pagination::MAX_PAGE,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 15,
						),
					),
				),
				'execute_callback'    => array( Pattern_Tools::class, 'list_ready_patterns' ),
				'permission_callback' => array( Permission_Gate::class, 'can_access' ),
				'annotations'         => array(
					'readonly'      => true,
					'destructive'   => false,
					'openWorldHint' => true,
				),
			),
			$prefix . 'insert-ready-pattern'      => array(
				'label'               => __( 'Insert Ready Pattern', 'template-domain' ),
				'description'         => __( 'Insert a ready-made pattern into a new draft page, or into an existing post after confirmation.', 'template-domain' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'pattern_id', 'target' ),
					'properties'           => array(
						'pattern_id'         => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Pattern ID from list-ready-patterns.', 'template-domain' ),
						),
						'target'             => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'description'          => __( 'Either new_draft or post_id with optional position.', 'template-domain' ),
							'properties'           => array(
								'new_draft' => array(
									'type'      => 'string',
									'maxLength' => 200,
								),
								'post_id'   => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'position'  => array(
									'type'        => array( 'string', 'integer' ),
									'description' => __( 'end (default), start, or block index.', 'template-domain' ),
								),
							),
						),
						'idempotency_key'    => array(
							'type'        => 'string',
							'maxLength'   => 64,
							'description' => __( 'Optional idempotency key.', 'template-domain' ),
						),
						'confirmation_token' => array(
							'type'        => 'string',
							'description' => __( 'WriteGuard confirmation token.', 'template-domain' ),
						),
					),
				),
				'execute_callback'    => array( Pattern_Tools::class, 'insert_ready_pattern' ),
				'permission_callback' => array( Permission_Gate::class, 'can_access' ),
				'annotations'         => array(
					'readonly'      => false,
					'destructive'   => false,
					'openWorldHint' => true,
				),
			),
		);
	}

	/**
	 * Prompt definitions for the AI Builder abilities (B7).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function prompt_definitions() {
		$prefix = Module::SLUG . '/';

		return array(
			$prefix . 'build-block' => array(
				'label'               => __( 'Build Block', 'template-domain' ),
				'description'         => __( 'Guided prompt directing an AI assistant to construct and insert a block.', 'template-domain' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'request' ),
					'properties'           => array(
						'request'    => array(
							'type'        => 'string',
							'title'       => __( 'Request', 'template-domain' ),
							'description' => __( 'Natural-language description of the section or block to design.', 'template-domain' ),
						),
						'block'      => array_merge(
							Builder_Tools::block_schema(),
							array(
								'title'       => __( 'Block Type', 'template-domain' ),
								'description' => __( 'Optional specific block slug. Omit to let the assistant choose.', 'template-domain' ),
							)
						),
						'page_title' => array(
							'type'        => 'string',
							'title'       => __( 'Page Title', 'template-domain' ),
							'description' => __( 'Optional title for the new draft page if saving into a new page.', 'template-domain' ),
						),
						'post_id'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'title'       => __( 'Target Post ID', 'template-domain' ),
							'description' => __( 'Optional post ID if inserting into an existing post or page.', 'template-domain' ),
						),
					),
				),
				'execute_callback'    => array( Builder_Tools::class, 'build_block_prompt' ),
				'permission_callback' => array( Permission_Gate::class, 'can_access' ),
				'meta'                => array(
					'show_in_rest' => false,
					'mcp'          => array(
						'public' => false,
						'type'   => 'prompt',
					),
				),
				'annotations'         => array(
					'readonly'      => true,
					'destructive'   => false,
					'openWorldHint' => false,
				),
			),
		);
	}

	/**
	 * Toolset map for the AI Builder abilities.
	 *
	 * @return array<string, string>
	 */
	public static function toolset_map() {
		$prefix = Module::SLUG . '/';

		return array(
			$prefix . 'get-block-catalog'         => 'builder',
			$prefix . 'validate-block-attributes' => 'builder',
			$prefix . 'insert-block'              => 'builder',
			$prefix . 'list-ready-patterns'       => 'builder',
			$prefix . 'insert-ready-pattern'      => 'builder',
			$prefix . 'build-block'               => 'builder',
		);
	}
}
