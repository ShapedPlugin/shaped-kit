<?php
/**
 * Loads the bundled WordPress MCP Adapter only when no other copy is present.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether Shaped Kit's bundled MCP Adapter loads, and reports which adapter is active.
 */
final class AdapterBootstrap {

	const ADAPTER_CLASS = 'WP\MCP\Core\McpAdapter';

	/**
	 * Oldest adapter verified to have everything ShapedPlugin products use.
	 */
	const MIN_COMPATIBLE_VERSION = '0.4.1';

	const SOURCE_KIT     = 'shaped-kit';
	const SOURCE_NONE    = 'none';
	const SOURCE_UNKNOWN = 'unknown';

	/**
	 * Load the bundled adapter if WordPress can run it and nothing else has supplied one.
	 *
	 * Any other copy wins, including the standalone MCP Adapter plugin and libraries bundled by other
	 * plugins such as Rank Math. Loading ours on top would make the adapter raise an "outdated plugin"
	 * notice on every such site.
	 *
	 * @return bool Whether this call loaded the bundled copy.
	 */
	public static function maybe_load() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return false;
		}

		// Autoloads too, so a copy another plugin has registered but not yet used still counts.
		if ( class_exists( self::ADAPTER_CLASS ) ) {
			return false;
		}

		$entry = self::bundled_dir() . 'mcp-adapter.php';

		if ( ! is_readable( $entry ) ) {
			return false;
		}

		// The plugin entry file, not the library: it defines WP_MCP_VERSION, which keeps the adapter
		// from flagging itself as a deprecated bundled dependency.
		require_once $entry;

		return class_exists( self::ADAPTER_CLASS, false );
	}

	/**
	 * What the dashboard shows about the active adapter.
	 *
	 * @return array{abilities_api: bool, loaded: bool, version: ?string, compatible: bool, source: string}
	 */
	public static function status() {
		$status = array(
			'abilities_api' => function_exists( 'wp_register_ability' ),
			'loaded'        => false,
			'version'       => null,
			'compatible'    => false,
			'source'        => self::SOURCE_NONE,
		);

		if ( ! class_exists( self::ADAPTER_CLASS ) ) {
			return $status;
		}

		$version_constant = self::ADAPTER_CLASS . '::VERSION';
		$version          = defined( $version_constant ) ? (string) constant( $version_constant ) : null;
		$reflection       = new \ReflectionClass( self::ADAPTER_CLASS );

		$status['loaded']     = true;
		$status['version']    = $version;
		$status['compatible'] = null !== $version && version_compare( $version, self::MIN_COMPATIBLE_VERSION, '>=' );
		$status['source']     = self::classify_source( (string) $reflection->getFileName(), self::bundled_dir(), WP_PLUGIN_DIR );

		return $status;
	}

	/**
	 * Which plugin supplied the adapter, judged from the file its class was loaded from.
	 *
	 * @param string $class_file  Absolute path of the loaded adapter class.
	 * @param string $bundled_dir Shaped Kit's bundled adapter directory.
	 * @param string $plugins_dir The plugins directory.
	 * @return string 'shaped-kit', another plugin's folder name (e.g. 'seo-by-rank-math', 'mcp-adapter'), or 'unknown'.
	 */
	public static function classify_source( $class_file, $bundled_dir, $plugins_dir ) {
		$class_file = self::normalize( $class_file );

		if ( '' === $class_file ) {
			return self::SOURCE_UNKNOWN;
		}

		if ( 0 === strpos( $class_file, self::normalize( $bundled_dir, true ) ) ) {
			return self::SOURCE_KIT;
		}

		$plugins_dir = self::normalize( $plugins_dir, true );

		if ( 0 !== strpos( $class_file, $plugins_dir ) ) {
			return self::SOURCE_UNKNOWN;
		}

		$folder = strtok( substr( $class_file, strlen( $plugins_dir ) ), '/' );

		return false === $folder ? self::SOURCE_UNKNOWN : $folder;
	}

	/**
	 * Forward slashes, and an optional single trailing slash so a prefix match stops at a folder boundary.
	 *
	 * @param string $path     A filesystem path.
	 * @param bool   $is_dir   Whether to end it with a slash.
	 * @return string
	 */
	private static function normalize( $path, $is_dir = false ) {
		$path = str_replace( '\\', '/', (string) $path );

		return $is_dir ? rtrim( $path, '/' ) . '/' : $path;
	}

	/**
	 * Where the unmodified upstream adapter lives inside Shaped Kit.
	 *
	 * @return string
	 */
	private static function bundled_dir() {
		return SHAPED_KIT_DIR . 'libs/mcp-adapter/';
	}
}
