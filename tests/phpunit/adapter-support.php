<?php
/**
 * Support for the `adapter` test suite: run a product's MCP server on a real MCP adapter copy.
 *
 * The adapter is a library that several plugins bundle, in different versions, and a product module
 * has to work on whichever copy happens to load. This file loads one copy, chosen by the
 * `SHAPED_KIT_MCP_ADAPTER_DIR` environment variable (default: the Kit's own bundled copy), attaches
 * the Real Testimonials module's hooks the way the plugin's own boot does, and creates the adapter
 * singleton. It is the Real Testimonials counterpart of Location Weather's `adapter-support.php`.
 *
 * One PHP process loads one copy: the copies share class names. `tests/run-adapter-matrix.sh` runs
 * the suite once per copy, edition and Builder-toolset state.
 *
 * Environment:
 *
 *   SHAPED_KIT_MCP_ADAPTER_DIR  Folder holding the copy's includes/. Default: libs/mcp-adapter.
 *   SHAPED_KIT_MCP_SCHEMA_DIR   Schema package for a bare checkout of a release tag (see the matrix).
 *   SHAPED_KIT_RT_EDITION       pro (default) or free: which Real Testimonials edition to load.
 *   SHAPED_KIT_RT_BUILDER       on (default) or off: whether the opt-in Builder toolset is switched on.
 *
 * @package ShapedKit
 */

/**
 * The edition under test: its folder next to the Kit, namespace and path constant.
 *
 * @return array{plugin: string, namespace: string, path_constant: string}
 */
function shaped_kit_adapter_edition() {
	if ( 'free' === getenv( 'SHAPED_KIT_RT_EDITION' ) ) {
		return array(
			'plugin'        => 'testimonial-free',
			'namespace'     => 'ShapedPlugin\\TestimonialFree',
			'path_constant' => 'SP_TFREE_PATH',
		);
	}

	return array(
		'plugin'        => 'testimonial-pro',
		'namespace'     => 'ShapedPlugin\\TestimonialPro',
		'path_constant' => 'SP_TPRO_PATH',
	);
}

/**
 * Whether the opt-in Builder toolset is switched on for this run.
 *
 * @return bool
 */
function shaped_kit_adapter_builder_on() {
	return 'off' !== getenv( 'SHAPED_KIT_RT_BUILDER' );
}

/**
 * Make one adapter copy's classes loadable, without running its plugin file.
 *
 * Older copies are plain libraries whose host plugin supplies the autoloader, and some need a
 * second package (the MCP schema) that sits next to them or inside them.
 *
 * @param string $adapter_dir Directory holding the copy's `includes/` folder.
 *
 * @return void
 */
function shaped_kit_adapter_autoload( $adapter_dir ) {
	$maps = array( 'WP\\MCP\\' => $adapter_dir . '/includes/' );

	// The copy's own schema package wins; a bare checkout of a release tag has none, so a directory
	// can be supplied for it.
	$candidates = array( $adapter_dir . '/vendor/wordpress/php-mcp-schema/src', dirname( $adapter_dir ) . '/php-mcp-schema/src' );
	if ( getenv( 'SHAPED_KIT_MCP_SCHEMA_DIR' ) ) {
		$candidates[] = rtrim( (string) getenv( 'SHAPED_KIT_MCP_SCHEMA_DIR' ), '/\\' );
	}

	foreach ( $candidates as $schema_dir ) {
		if ( is_dir( $schema_dir ) ) {
			$maps['WP\\McpSchema\\'] = $schema_dir . '/';
			break;
		}
	}

	spl_autoload_register(
		static function ( $class_name ) use ( $maps ) {
			foreach ( $maps as $prefix => $base ) {
				if ( 0 !== strpos( $class_name, $prefix ) ) {
					continue;
				}

				$file = $base . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		}
	);
}

/**
 * Load the edition's classes, attach its module hooks, load the adapter and create its singleton.
 * Runs on `muplugins_loaded`, before `init`, as a plugin's own boot would.
 *
 * Missing sibling plugins are not an error here: the test skips itself when the module's classes
 * are not available, so a checkout without the product still collects the suite.
 *
 * @param string $adapter_dir Directory of the adapter copy to load.
 *
 * @return void
 */
function shaped_kit_adapter_boot( $adapter_dir ) {
	$edition = shaped_kit_adapter_edition();
	$plugin  = dirname( __DIR__, 3 ) . '/' . $edition['plugin'];

	shaped_kit_adapter_autoload( $adapter_dir );

	// The adapter's shared default server stays off, as it does when the Kit supplies the adapter
	// (FR-S1), so the only server in the suite is the product's.
	add_filter( 'mcp_adapter_create_default_server', '__return_false' );

	if ( file_exists( $plugin . '/vendor/autoload.php' ) ) {
		require_once $plugin . '/vendor/autoload.php';

		if ( ! defined( $edition['path_constant'] ) ) {
			define( $edition['path_constant'], $plugin . '/src/' );
		}

		$module = $edition['namespace'] . '\\Mcp\\Module';
		if ( class_exists( $module ) ) {
			$module::register();
		}
	}

	\WP\MCP\Core\McpAdapter::instance();
}
