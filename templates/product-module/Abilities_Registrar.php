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

use ShapedPlugin\Template\Mcp\Builder\Builder_Abilities;
use ShapedPlugin\Template\Mcp\Media\Media_Abilities;
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
	 * Every tool this module serves, keyed by ability name: the product's own (`core_definitions()`) and, when the
	 * `Builder/` or `Media/` folder is copied in, the Build with AI or media tools. Nothing else needs wiring.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions() {
		$definitions = self::core_definitions();

		if ( self::has_builder() ) {
			$definitions = array_merge( $definitions, Builder_Abilities::definitions() );
		}

		if ( self::has_media() ) {
			$definitions = array_merge( $definitions, Media_Abilities::definitions() );
		}

		return $definitions;
	}

	/**
	 * The product's own tool definitions, keyed by ability name. Add each tool here and give it an entry in
	 * `core_toolset_map()`: a tool left out of the map is treated as `core`, which is always on.
	 *
	 * Each entry requires:
	 *   - label: Human-readable ability title
	 *   - description: Compact description (under 30 tokens)
	 *   - input_schema: Strict JSON schema (type: object, additionalProperties: false)
	 *   - execute_callback: Callable returning payload or WP_Error
	 *   - permission_callback: Capability check for the authenticated caller
	 *   - annotations: readonly, destructive, openWorldHint
	 *
	 * Token budget (E126): the name, description and input schema of every served tool travel with every message
	 * of every AI client. Keep schemas small, put no per-value enum in one, and move anything large into an
	 * opt-in toolset. `Support\Token_Budget` measures it, and a product test should pin the default-on size.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function core_definitions() {
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
		return self::has_builder() ? Builder_Abilities::prompt_definitions() : array();
	}

	/**
	 * Whether the `Builder/` folder has been copied into this module.
	 *
	 * @return bool
	 */
	private static function has_builder() {
		return class_exists( Builder_Abilities::class );
	}

	/**
	 * Whether the `Media/` folder has been copied into this module.
	 *
	 * @return bool
	 */
	private static function has_media() {
		return class_exists( Media_Abilities::class );
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
	 * The toolsets a product can offer, as data (FR-K9 §6, D14). A toolset with no tool is left out of the
	 * dashboard, so a product with no deletion tool shows no deletion switch. `core` is always on.
	 *
	 * A product that needs another toolset adds it here, and uses its id in the map.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	private static function toolset_catalog() {
		return array(
			'core'        => array(
				'label'       => __( 'Core / Read-Only', 'template-domain' ),
				'description' => __( 'Context, listing and read tools. Always on.', 'template-domain' ),
			),
			'management'  => array(
				'label'       => __( 'Management Operations', 'template-domain' ),
				'description' => __( 'Create and update content. Recommended when managing content.', 'template-domain' ),
			),
			'destructive' => array(
				'label'       => __( 'Permanent Deletion (WriteGuard)', 'template-domain' ),
				'description' => __( 'Permanent deletion with two-step server-side confirmation.', 'template-domain' ),
			),
			'builder'     => array(
				'label'       => __( 'Block Builder', 'template-domain' ),
				'description' => __( 'Let your AI client design blocks with its own model. No API key on this site.', 'template-domain' ),
			),
			'bulk'        => array(
				'label'       => __( 'Bulk Import', 'template-domain' ),
				'description' => __( 'Create many items in one call. It adds a large tool to every message, so keep it off until you need it.', 'template-domain' ),
			),
			'media'       => array(
				'label'       => __( 'Media Import', 'template-domain' ),
				'description' => __( 'Let your AI client download https images into the media library. This site then fetches addresses from the internet, so keep it off until you need it.', 'template-domain' ),
			),
		);
	}

	/**
	 * Returns the toolsets definition metadata, for the settings card and the Shaped Kit dashboard.
	 *
	 * Implements PRD FR-K9 §6 and Decision D14. Tool counts come from the toolset map, so they follow the tools
	 * actually served.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function toolsets() {
		$active = Permission_Gate::get_active_toolsets();
		$map    = self::ability_toolset_map();

		/** This filter is documented in `names()`. */
		$all = (array) apply_filters( 'template_prefix/mcp_ability_names', array_keys( self::definitions() ) );

		$counts = array();
		foreach ( array_unique( $all ) as $name ) {
			if ( ! is_string( $name ) || 0 !== strpos( $name, self::NAME_PREFIX ) ) {
				continue;
			}

			$id            = $map[ $name ] ?? 'core';
			$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
		}

		$toolsets = array();
		foreach ( self::toolset_catalog() as $id => $meta ) {
			$count = $counts[ $id ] ?? 0;
			if ( 'core' !== $id && 0 === $count ) {
				continue;
			}

			$toolsets[] = array(
				'id'          => $id,
				'label'       => $meta['label'],
				'description' => $meta['description'],
				'tools_count' => $count,
				'enabled'     => 'core' === $id || in_array( $id, $active, true ),
				'locked'      => 'core' === $id,
			);
		}

		return $toolsets;
	}

	/**
	 * Toolset of each of the product's own tools. `core` is the always-on, read-only set.
	 *
	 * @return array<string, string>
	 */
	public static function core_toolset_map() {
		return array(
			self::NAME_PREFIX . 'get-context' => 'core',
		);
	}

	/**
	 * Map of ability name to the toolset that must be on for it to be served: the product's own tools, the Builder's
	 * when that folder is present, and whatever a Pro edition adds through the filter.
	 *
	 * A name missing from the map counts as `core`, which is always on, so every new tool needs an entry.
	 *
	 * @return array<string, string>
	 */
	public static function ability_toolset_map() {
		$map = self::core_toolset_map();

		if ( self::has_builder() ) {
			$map = array_merge( $map, Builder_Abilities::toolset_map() );
		}

		if ( self::has_media() ) {
			$map = array_merge( $map, Media_Abilities::toolset_map() );
		}

		/**
		 * Filters the toolset map. A Pro edition that adds tools through `template_prefix/mcp_ability_names` adds their
		 * entries here too, or its write tools would be on for everyone.
		 *
		 * @param array<string, string> $map Ability name => toolset id.
		 */
		return (array) apply_filters( 'template_prefix/mcp_toolset_map', $map );
	}

	/**
	 * Returns the ability names to serve from the dedicated MCP server endpoint.
	 *
	 * Filters abilities based on active toolsets (FR-K9 §6, D14): when a toolset is off, its tools are omitted from
	 * the server, which protects the client's context window and narrows the blast radius.
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

		return self::served( (array) $names );
	}

	/**
	 * Keep the names that are this module's own and whose toolset is on.
	 *
	 * @param array $names Candidate ability names.
	 *
	 * @return string[]
	 */
	private static function served( array $names ) {
		$active_toolsets = Permission_Gate::get_active_toolsets();
		$map             = self::ability_toolset_map();

		$own = array_filter(
			$names,
			static function ( $name ) use ( $active_toolsets, $map ) {
				if ( ! is_string( $name ) || 0 !== strpos( $name, self::NAME_PREFIX ) ) {
					return false;
				}

				$required_toolset = $map[ $name ] ?? 'core';

				return in_array( $required_toolset, $active_toolsets, true );
			}
		);

		return array_values( array_unique( $own ) );
	}

	/**
	 * Returns prompt ability names to serve from the dedicated MCP server endpoint (B7). A prompt follows its
	 * toolset: the Builder's prompt is served only while the Builder toolset is on.
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

		return self::served( (array) $names );
	}

	/**
	 * Defensively wrap a tool's execute callback.
	 *
	 * Protective features:
	 * 1. Output buffering (E88): Discards stray PHP notices, warnings, or echos that would
	 *    corrupt JSON-RPC wire responses.
	 * 2. Error sanitation (E69): Catches all `\Throwable`. Returns a generic error and unique id
	 *    to the client so database schema or sensitive server paths never cross the wire.
	 * 3. Audit telemetry (FR-S3): every call, whatever its outcome, is reported to Shaped Kit's audit log.
	 *
	 * @param string   $tool_name Fully qualified ability name.
	 * @param callable $callback  The tool's execution callback.
	 *
	 * @return callable
	 */
	public static function wrap( $tool_name, $callback ) {
		return static function ( $params = null ) use ( $tool_name, $callback ) {
			$start_time   = microtime( true );
			$buffer_level = ob_get_level();
			ob_start();

			try {
				$result = call_user_func( $callback, $params );
				self::discard_output( $buffer_level, $tool_name );
				$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

				self::log_telemetry( $tool_name, $params, $result, null, $duration_ms );

				return $result;
			} catch ( \Throwable $e ) {
				self::discard_output( $buffer_level, $tool_name );
				$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );
				$error_id    = substr( md5( uniqid( 'template_prefix_mcp_', true ) ), 0, 12 );

				self::log_telemetry( $tool_name, $params, null, $error_id, $duration_ms );

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
	 * Emits telemetry event for audit logging.
	 *
	 * Without this call a product's tools never reach Shaped Kit's audit log, and nothing says so (TESTING.md rule 5).
	 * Only the argument keys are sent, each with a null value: any plugin can listen to this action, and values can
	 * be personal data (an email, a phone) or a credential (a signed image address). The Kit's listener reads the
	 * keys only.
	 *
	 * @param string      $tool_name   Ability name.
	 * @param mixed       $params      Input parameters.
	 * @param mixed       $result      Tool execution result.
	 * @param string|null $error_id    Sanitized error tracking id if an exception occurred.
	 * @param int         $duration_ms Execution time in milliseconds.
	 *
	 * @return void
	 */
	private static function log_telemetry( $tool_name, $params, $result, $error_id, $duration_ms ) {
		$outcome = 'ok';

		if ( null !== $error_id ) {
			$outcome = 'error';
		} elseif ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( 'confirmation_required' === $code ) {
				$outcome = 'confirmation_required';
			} elseif ( in_array( $code, array( 'forbidden', 'rest_forbidden', 'unauthorized' ), true ) ) {
				$outcome = 'denied';
			} else {
				$outcome = 'error';
			}
		} elseif ( is_array( $result ) && isset( $result['status'] ) && 'confirmation_required' === $result['status'] ) {
			$outcome = 'confirmation_required';
		} elseif ( is_array( $params ) && ( ! empty( $params['confirmation_token'] ) || ! empty( $params['confirm_token'] ) ) ) {
			$outcome = 'confirmed';
		}

		/**
		 * Emits telemetry event for audit logging.
		 * Listened to by Shaped Kit if active.
		 *
		 * @param array $payload Telemetry metadata.
		 */
		do_action(
			'shaped_kit/mcp_tool_called',
			array(
				'product'     => Module::SLUG,
				'ability'     => $tool_name,
				'outcome'     => $outcome,
				'error_id'    => $error_id,
				'duration_ms' => $duration_ms,
				'arguments'   => is_array( $params ) ? array_fill_keys( array_map( 'strval', array_keys( $params ) ), null ) : array(),
			)
		);
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
