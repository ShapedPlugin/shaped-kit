<?php
/**
 * The Abilities_Registrar template file.
 *
 * Copy this file into a product's MCP module.
 * See README.md in this folder for the step-by-step token replacement checklist.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp;

use ShapedPlugin\Template\Mcp\Tools\Context_Tools;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers product abilities with the WordPress Abilities API (WP 6.9+).
 *
 * `definitions()` is the single source of truth for every tool: label, description, schemas,
 * callbacks and annotations.
 *
 * Security & Exposure rules:
 * - Abilities declare `show_in_rest: false` and `mcp.public: false`.
 * - They are reachable ONLY through this product's dedicated endpoint (`/wp-json/{slug}/mcp`),
 *   never via core's Abilities REST API or the adapter's default server.
 */
class Abilities_Registrar {

	/**
	 * Prefix of every ability name this module owns.
	 */
	const NAME_PREFIX = Module::SLUG . '/';

	/**
	 * The tool definitions, keyed by ability name.
	 *
	 * Each entry requires:
	 *   - label: Human-readable ability title
	 *   - description: Compact description (under 30 tokens)
	 *   - input_schema: Strict JSON schema (type: object, additionalProperties: false)
	 *   - execute_callback: Callable returning payload or WP_Error
	 *   - permission_callback: Capability check for the authenticated caller
	 *   - annotations: readonly, destructive, openWorldHint
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions() {
		$read_only = array(
			'readonly'      => true,
			'destructive'   => false,
			'openWorldHint' => false,
		);

		return array(
			self::NAME_PREFIX . 'get-context' => array(
				'label'               => __( 'Get Context', 'template-domain' ),
				'description'         => __( 'Call first. User identity, site timezone, entity counts, edition, and callable tools.', 'template-domain' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					// No `properties` key (E77): empty stdClass crashes WP 7.1 validation as soon as any input is sent.
				),
				'execute_callback'    => array( Context_Tools::class, 'get_context' ),
				'permission_callback' => array( Permission_Gate::class, 'can_access' ),
				'annotations'         => $read_only,
			),
		);
	}

	/**
	 * Register the ability category. Hooked to `wp_abilities_api_categories_init`.
	 *
	 * @return void
	 */
	public static function register_category() {
		if ( ! Module::should_load() ) {
			return;
		}

		wp_register_ability_category(
			Module::SLUG,
			array(
				'label'       => Module::NAME,
				'description' => sprintf(
					/* translators: %s: product name */
					__( 'Abilities for %s.', 'template-domain' ),
					Module::NAME
				),
			)
		);
	}

	/**
	 * Prompt definitions for this module (B7).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function prompt_definitions() {
		return array();
	}

	/**
	 * Register every ability, then fire hook for Pro abilities. Hooked to `wp_abilities_api_init`.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! Module::should_load() ) {
			return;
		}

		self::register( self::definitions() );
		self::register( self::prompt_definitions() );

		/**
		 * Fires after the free/core product abilities are registered.
		 * Pro editions hook this action to register Pro-only abilities under the same namespace.
		 */
		do_action( 'template_prefix/mcp_loaded' );
	}

	/**
	 * Register a set of ability definitions with core's wp_register_ability().
	 *
	 * @param array<string, array<string, mixed>> $definitions Definitions keyed by ability name.
	 *
	 * @return string[] The names successfully registered.
	 */
	public static function register( $definitions ) {
		$registered = array();

		foreach ( $definitions as $name => $definition ) {
			$mcp_meta = array(
				'public' => false,
			);
			if ( isset( $definition['meta']['mcp']['type'] ) ) {
				$mcp_meta['type'] = $definition['meta']['mcp']['type'];
			} elseif ( isset( self::prompt_definitions()[ $name ] ) ) {
				$mcp_meta['type'] = 'prompt';
			}

			$args = array(
				'label'               => $definition['label'],
				'description'         => $definition['description'],
				'category'            => Module::SLUG,
				'execute_callback'    => self::wrap( $name, $definition['execute_callback'] ),
				'permission_callback' => $definition['permission_callback'],
				'meta'                => array(
					'show_in_rest' => false,
					'mcp'          => $mcp_meta,
					'annotations'  => $definition['annotations'],
				),
			);

			if ( ! empty( $definition['input_schema'] ) ) {
				$args['input_schema'] = $definition['input_schema'];
			}

			if ( ! empty( $definition['output_schema'] ) ) {
				$args['output_schema'] = $definition['output_schema'];
			}

			if ( null !== wp_register_ability( $name, $args ) ) {
				$registered[] = $name;
			}
		}

		return $registered;
	}

	/**
	 * Returns the ability names to serve from the dedicated MCP server endpoint.
	 *
	 * Namespace isolation (E12):
	 * Filters ability names, then strictly enforces that all names start with this module's
	 * own prefix. Prevents any third-party plugin from piggybacking on our endpoint.
	 *
	 * @return string[]
	 */
	public static function names() {
		/**
		 * Filters the ability names served by the product MCP server.
		 *
		 * @param string[] $names Fully qualified ability names.
		 */
		$names = apply_filters( 'template_prefix/mcp_ability_names', array_keys( self::definitions() ) );

		$own = array_filter(
			(array) $names,
			static function ( $name ) {
				return is_string( $name ) && 0 === strpos( $name, self::NAME_PREFIX );
			}
		);

		return array_values( array_unique( $own ) );
	}

	/**
	 * Returns prompt ability names to serve from the dedicated MCP server endpoint (B7).
	 *
	 * @return string[]
	 */
	public static function prompt_names() {
		/**
		 * Filters the prompt ability names served by the product MCP server.
		 *
		 * @param string[] $names Fully qualified prompt ability names.
		 */
		$names = apply_filters( 'template_prefix/mcp_prompt_names', array_keys( self::prompt_definitions() ) );

		$own = array_filter(
			(array) $names,
			static function ( $name ) {
				return is_string( $name ) && 0 === strpos( $name, self::NAME_PREFIX );
			}
		);

		return array_values( array_unique( $own ) );
	}

	/**
	 * Defensively wrap a tool's execute callback.
	 *
	 * Protective features:
	 * 1. Output buffering (E88): Discards stray PHP notices, warnings, or echos that would
	 *    corrupt JSON-RPC wire responses.
	 * 2. Error sanitation (E69): Catches all `\Throwable`. Returns a generic error and unique id
	 *    to the client so database schema or sensitive server paths never cross the wire.
	 *
	 * @param string   $tool_name Fully qualified ability name.
	 * @param callable $callback  The tool's execution callback.
	 *
	 * @return callable
	 */
	public static function wrap( $tool_name, $callback ) {
		return static function ( $params = null ) use ( $tool_name, $callback ) {
			$buffer_level = ob_get_level();
			ob_start();

			try {
				$result = call_user_func( $callback, $params );
				self::discard_output( $buffer_level, $tool_name );

				return $result;
			} catch ( \Throwable $e ) {
				self::discard_output( $buffer_level, $tool_name );

				$error_id = substr( md5( uniqid( 'template_prefix_mcp_', true ) ), 0, 12 );

				/**
				 * Fires when a tool throws, before generic failure returns to the client.
				 *
				 * @param \Throwable $e         The caught exception.
				 * @param string     $tool_name Fully qualified ability name.
				 * @param mixed      $params    Input parameters.
				 * @param string     $error_id  Sanitized error tracking id.
				 */
				do_action( 'template_prefix/mcp_tool_exception', $e, $tool_name, $params, $error_id );

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Server-side record; not sent to client.
				error_log(
					sprintf(
						'%s MCP tool exception [%s] %s: %s: %s at %s:%d',
						Module::NAME,
						$error_id,
						$tool_name,
						get_class( $e ),
						$e->getMessage(),
						$e->getFile(),
						$e->getLine()
					)
				);

				return new WP_Error(
					'failed',
					__( 'The tool failed with an internal error. Quote the error id to the site administrator.', 'template-domain' ),
					array(
						'tool'     => $tool_name,
						'error_id' => $error_id,
					)
				);
			}
		};
	}

	/**
	 * Clean up and discard any output printed during tool execution.
	 *
	 * @param int    $buffer_level Initial buffer depth.
	 * @param string $tool_name    Ability name for diagnostic logging.
	 *
	 * @return void
	 */
	private static function discard_output( $buffer_level, $tool_name ) {
		$printed = '';

		while ( ob_get_level() > $buffer_level ) {
			$printed = (string) ob_get_clean() . $printed;
		}

		if ( '' !== trim( $printed ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Server-side diagnostic record.
			error_log( sprintf( '%s MCP tool %s printed output, which was discarded: %s', Module::NAME, $tool_name, substr( $printed, 0, 300 ) ) );
		}
	}
}
