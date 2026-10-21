<?php
/**
 * Support for the `adapter` test suite: run a product's MCP server on a real MCP adapter copy.
 *
 * The adapter is a library that several plugins bundle, in different versions, and a product module
 * has to work on whichever copy happens to load. This file loads one copy, chosen by the
 * `SHAPED_KIT_MCP_ADAPTER_DIR` environment variable (default: the Kit's own bundled copy), attaches
 * the chosen product's module hooks the way the plugin's own boot does, and creates the adapter
 * singleton. It is the counterpart of Location Weather's `adapter-support.php`.
 *
 * Which product is a profile (`tests/phpunit/adapter/products/{product}.php`): data only, so a new product
 * needs a profile and a thin test class, not new plumbing (Phase 8.0). The `template` profile runs the
 * product-module template itself, with no sibling plugin.
 *
 * One PHP process loads one copy: the copies share class names. `tests/run-adapter-matrix.sh` runs
 * the suite once per copy, product, edition and Builder-toolset state.
 *
 * Environment:
 *
 *   SHAPED_KIT_MCP_ADAPTER_DIR  Folder holding the copy's includes/. Default: libs/mcp-adapter.
 *   SHAPED_KIT_MCP_SCHEMA_DIR   Schema package for a bare checkout of a release tag (see the matrix).
 *   SHAPED_KIT_PRODUCT          Which profile: real-testimonials (default) or template.
 *   SHAPED_KIT_EDITION          The profile's default edition (pro for Real Testimonials), or free: which edition to load.
 *   SHAPED_KIT_BUILDER          on (default) or off: whether the opt-in toolsets are switched on.
 *
 * The earlier names SHAPED_KIT_RT_EDITION and SHAPED_KIT_RT_BUILDER still work.
 *
 * @package ShapedKit
 */

/**
 * The environment variable's value, or the older name's, or a default.
 *
 * @param string $name     Current variable name.
 * @param string $old_name Earlier name, still honoured.
 * @param string $fallback Value when neither is set.
 *
 * @return string
 */
function shaped_kit_adapter_env( $name, $old_name, $fallback = '' ) {
	foreach ( array( $name, $old_name ) as $candidate ) {
		$value = getenv( $candidate );
		if ( false !== $value && '' !== $value ) {
			return (string) $value;
		}
	}

	return $fallback;
}

/**
 * The product under test: its profile, as data.
 *
 * @return array The profile (see `tests/phpunit/adapter/products/`).
 */
function shaped_kit_adapter_product() {
	static $profile = null;

	if ( null === $profile ) {
		$name = shaped_kit_adapter_env( 'SHAPED_KIT_PRODUCT', 'SHAPED_KIT_PRODUCT', 'real-testimonials' );
		$file = __DIR__ . '/adapter/products/' . preg_replace( '/[^a-z0-9-]/', '', $name ) . '.php';

		if ( ! is_readable( $file ) ) {
			fwrite( STDERR, "No wire-suite profile for SHAPED_KIT_PRODUCT={$name} (expected {$file}).\n" );
			exit( 1 );
		}

		$profile = require $file;
	}

	return $profile;
}

/**
 * The edition under test: its folder next to the Kit (or its source folder), namespace and path constant.
 *
 * @return array{namespace: string, plugin?: string, path_constant?: string, source?: string}
 */
function shaped_kit_adapter_edition() {
	$profile = shaped_kit_adapter_product();
	$wanted  = shaped_kit_adapter_env( 'SHAPED_KIT_EDITION', 'SHAPED_KIT_RT_EDITION', $profile['default_edition'] );

	if ( ! isset( $profile['editions'][ $wanted ] ) ) {
		fwrite( STDERR, "{$profile['slug']} has no '{$wanted}' edition in its wire-suite profile.\n" );
		exit( 1 );
	}

	return $profile['editions'][ $wanted ];
}

/**
 * Whether the opt-in toolsets are switched on for this run.
 *
 * @return bool
 */
function shaped_kit_adapter_builder_on() {
	return 'off' !== shaped_kit_adapter_env( 'SHAPED_KIT_BUILDER', 'SHAPED_KIT_RT_BUILDER', 'on' );
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

	shaped_kit_adapter_autoload( $adapter_dir );

	// The adapter's shared default server stays off, as it does when the Kit supplies the adapter
	// (FR-S1), so the only server in the suite is the product's.
	add_filter( 'mcp_adapter_create_default_server', '__return_false' );

	$module = $edition['namespace'] . '\\Mcp\\Module';

	if ( isset( $edition['source'] ) ) {
		// The product-module template: no plugin, its classes load from its own folder.
		$source = dirname( __DIR__, 2 ) . '/' . $edition['source'] . '/';
		$prefix = $edition['namespace'] . '\\Mcp\\';

		spl_autoload_register(
			static function ( $class_name ) use ( $prefix, $source ) {
				if ( 0 !== strpos( $class_name, $prefix ) ) {
					return;
				}

				$file = $source . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);

		if ( class_exists( $module ) ) {
			$module::register();
		}
	} else {
		$plugin = dirname( __DIR__, 3 ) . '/' . $edition['plugin'];

		if ( file_exists( $plugin . '/vendor/autoload.php' ) ) {
			require_once $plugin . '/vendor/autoload.php';

			if ( ! defined( $edition['path_constant'] ) ) {
				define( $edition['path_constant'], $plugin . '/src/' );
			}

			if ( isset( $edition['version_constant'] ) && ! defined( $edition['version_constant'] ) ) {
				define( $edition['version_constant'], $edition['version'] ?? '1.0.0' );
			}

			if ( class_exists( $module ) ) {
				$module::register();
			}
		}
	}

	\WP\MCP\Core\McpAdapter::instance();
}
