<?php
/**
 * Product MCP Module bootstrap template.
 *
 * Copy this folder into a product (e.g. `src/Mcp/` or `includes/Mcp/`).
 * See README.md in this folder for the step-by-step token replacement checklist.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Entry point of the product MCP module.
 *
 * The module loads only when WordPress is 6.9+ and the owner switched MCP on.
 * Otherwise it is silent: no hooks, no notice, no error, and the rest of the plugin
 * keeps its own WordPress minimum.
 */
class Module {

	/**
	 * Oldest WordPress that has the Abilities API the module needs.
	 */
	const MIN_WP_VERSION = '6.9';

	/**
	 * Public name of the product on the wire: ability namespace, category, server ID and REST
	 * namespace. Clients store it, so it never changes across Free/Pro editions.
	 */
	const SLUG = 'template-slug';

	/**
	 * Human-readable product name.
	 */
	const NAME = 'Template Product';

	/**
	 * Product edition: 'free' or 'pro'. Single point of configuration for all module responses.
	 */
	const EDITION = 'free';

	/**
	 * Product version string or fallback.
	 */
	const VERSION = '1.0.0';

	/**
	 * Main Custom Post Type or entity managed by this product.
	 * Kept empty string '' by default so products without CPTs (or before configured) do not register junk.
	 */
	const ENTRY_POST_TYPE = '';

	/**
	 * Hook the gate. Called once from the plugin's own boot.
	 *
	 * The ability hooks and the server hook attach here, at plugin load, not inside `boot()`.
	 * Core builds the abilities registry the first time anything asks for it once `init` has started
	 * (class-wp-abilities-registry.php:282), so a plugin that does so before `init` priority 5 would
	 * build it before `boot()` ran, and the abilities would be missing with no error. Each callback
	 * checks `should_load()` itself, so the module stays silent when it is off.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'boot' ), 5 );
		add_action( 'wp_abilities_api_categories_init', array( Abilities_Registrar::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( Abilities_Registrar::class, 'register_abilities' ) );
		add_action( 'mcp_adapter_init', array( Server::class, 'register' ) );
		Kit_Registration::register();
		Rest_Controller::register();
	}

	/**
	 * Whether a WordPress version can run the module. Judged from the raw version string, so a
	 * pre-release such as "6.9-beta1" counts as 6.9. It is not judged from `function_exists(
	 * 'wp_register_ability' )` alone, which is also true on older WordPress running the retired
	 * Abilities API plugin.
	 *
	 * @param string $version A WordPress version string, e.g. "7.1.3" or "6.9-beta1".
	 *
	 * @return bool
	 */
	public static function is_supported_wordpress( $version ) {
		$version = trim( (string) preg_replace( '/[^0-9.].*$/', '', (string) $version ), '.' );

		return '' !== $version && version_compare( $version, self::MIN_WP_VERSION, '>=' );
	}

	/**
	 * Whether the module should load on this request.
	 *
	 * @return bool
	 */
	public static function should_load() {
		global $wp_version;

		return self::is_supported_wordpress( $wp_version )
			&& function_exists( 'wp_register_ability' )
			&& Permission_Gate::is_enabled();
	}

	/**
	 * Load the module if the gate allows it.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! self::should_load() ) {
			return;
		}

		// Only attach CPT guard if this product manages a post type.
		if ( '' !== self::ENTRY_POST_TYPE ) {
			// After the admin's own registration (priority 10), so the guard only fills a gap.
			add_action( 'init', array( __CLASS__, 'ensure_entry_post_type' ), 20 );
		}
	}

	/**
	 * Make sure the post type exists on this request.
	 *
	 * The plugin registers it only inside wp-admin, but an MCP call is a REST request. Without a
	 * registered type, WordPress judges `edit_post` and `read_post` on a draft or private entry as
	 * `edit_others_posts`, which every editor holds, while wp-admin asks for the dashboard
	 * capability.
	 *
	 * @return void
	 */
	public static function ensure_entry_post_type() {
		if ( '' === self::ENTRY_POST_TYPE || post_type_exists( self::ENTRY_POST_TYPE ) ) {
			return;
		}

		$capability = Permission_Gate::base_capability();

		register_post_type(
			self::ENTRY_POST_TYPE,
			array(
				'label'           => self::NAME,
				'public'          => false,
				'show_ui'         => false,
				'show_in_menu'    => false,
				'capability_type' => 'post',
				'hierarchical'    => false,
				'query_var'       => false,
				'supports'        => array( 'title' ),
				'capabilities'    => array(
					'publish_posts'       => $capability,
					'edit_posts'          => $capability,
					'edit_others_posts'   => $capability,
					'delete_posts'        => $capability,
					'delete_others_posts' => $capability,
					'read_private_posts'  => $capability,
					'edit_post'           => $capability,
					'delete_post'         => $capability,
					'read_post'           => $capability,
				),
			)
		);
	}
}
