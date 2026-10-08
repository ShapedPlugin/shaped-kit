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
	 * Relative to a plugin folder: present only in a plugin that is itself an MCP Adapter.
	 */
	const ADAPTER_MARKER = 'includes/Core/McpAdapter.php';

	/**
	 * Adapter 0.6+ supports only the Abilities API in core, which arrived in WordPress 6.9.
	 */
	const MIN_WP_VERSION = '6.9';

	/**
	 * Oldest adapter verified to have everything ShapedPlugin products use.
	 */
	const MIN_COMPATIBLE_VERSION = '0.4.1';

	/**
	 * Define as true in wp-config.php to stop the Kit from ever loading its bundled copy.
	 */
	const DISABLE_CONSTANT = 'SHAPED_KIT_DISABLE_BUNDLED_MCP_ADAPTER';

	const SOURCE_KIT     = 'shaped-kit';
	const SOURCE_NONE    = 'none';
	const SOURCE_UNKNOWN = 'unknown';

	/**
	 * Load the bundled adapter if WordPress can run it and no other copy is present or coming.
	 *
	 * Runs at plugin include time, not plugins_loaded: by plugins_loaded, a plugin that bundles an
	 * unstarted adapter library (WooCommerce) has registered its autoloader, and the adapter's own
	 * duplicate check would then step aside for that library while nothing runs.
	 *
	 * @return bool Whether this call loaded the bundled copy.
	 */
	public static function maybe_load() {
		if ( defined( self::DISABLE_CONSTANT ) && constant( self::DISABLE_CONSTANT ) ) {
			return false;
		}

		if ( ! self::wordpress_supported( isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '' ) ) {
			return false;
		}

		// Autoloads too, so a copy another plugin has registered but not yet used still counts.
		if ( class_exists( self::ADAPTER_CLASS ) ) {
			return false;
		}

		$file_exists = 'file_exists';

		// An adapter plugin that loads after us would redeclare the adapter's classes: fatal.
		if ( '' !== self::find_adapter_plugin( self::active_plugin_files(), SHAPED_KIT_DIR, WP_PLUGIN_DIR, $file_exists ) ) {
			return false;
		}

		// WordPress includes the plugin being activated after us, with the same fatal.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check, never acted on.
		if ( self::is_adapter_activation_request( $_REQUEST, WP_PLUGIN_DIR, $file_exists ) ) {
			return false;
		}

		// The same, from WP-CLI, which has no $_REQUEST.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			list( $args, $assoc_args ) = self::cli_arguments();

			if ( self::is_cli_adapter_activation( $args, $assoc_args, WP_PLUGIN_DIR, $file_exists, self::adapter_plugin_installed() ) ) {
				return false;
			}
		}

		$entry = self::bundled_dir() . 'mcp-adapter.php';

		if ( ! is_readable( $entry ) ) {
			return false;
		}

		// Installing the Kit must enable nothing by itself, including the adapter's shared default server.
		add_filter( 'mcp_adapter_create_default_server', array( __CLASS__, 'filter_default_server' ), 5 );

		// The plugin entry file, not the library: it defines WP_MCP_VERSION, which keeps the adapter
		// from flagging itself as a deprecated bundled dependency.
		require_once $entry;

		if ( class_exists( self::ADAPTER_CLASS, false ) ) {
			return true;
		}

		remove_filter( 'mcp_adapter_create_default_server', array( __CLASS__, 'filter_default_server' ), 5 );

		return false;
	}

	/**
	 * Keeps the adapter's shared default server off while the Kit supplies the adapter.
	 *
	 * @param bool $enabled Whether the adapter would create its default server.
	 * @return bool
	 */
	public static function filter_default_server( $enabled ) {
		/**
		 * Whether the Kit's bundled adapter may create its shared default MCP server.
		 *
		 * @param bool $allow Default false.
		 */
		return $enabled && (bool) apply_filters( 'shaped_kit/allow_default_mcp_server', false );
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
		$status['source']     = self::classify_source(
			self::real_path( (string) $reflection->getFileName() ),
			self::real_path( self::bundled_dir() ),
			self::real_path( WP_PLUGIN_DIR )
		);

		return $status;
	}

	/**
	 * Whether this WordPress version can run the bundled adapter. Read from $wp_version rather than
	 * get_bloginfo(), whose filter another plugin could use to switch MCP off.
	 *
	 * @param string $version A WordPress version string, e.g. "7.1.3" or "6.9-beta1".
	 * @return bool
	 */
	public static function wordpress_supported( $version ) {
		$version = trim( (string) preg_replace( '/[^0-9.].*$/', '', (string) $version ), '.' );

		return '' !== $version && version_compare( $version, self::MIN_WP_VERSION, '>=' );
	}

	/**
	 * The first active plugin, other than the Kit, that is itself an MCP Adapter.
	 *
	 * @param string[] $plugin_files Absolute paths of active plugin main files.
	 * @param string   $kit_dir      The Kit's own directory.
	 * @param string   $plugins_dir  The plugins directory.
	 * @param callable $file_exists  file_exists, or a stand-in in tests.
	 * @return string The plugin's directory, or '' if there is none.
	 */
	public static function find_adapter_plugin( $plugin_files, $kit_dir, $plugins_dir, $file_exists ) {
		$kit_dir     = self::normalize( $kit_dir, true );
		$plugins_dir = self::normalize( $plugins_dir, true );

		foreach ( (array) $plugin_files as $plugin_file ) {
			$dir = self::normalize( dirname( self::normalize( $plugin_file ) ), true );

			if ( $dir === $kit_dir || $dir === $plugins_dir ) {
				continue;
			}

			if ( call_user_func( $file_exists, $dir . self::ADAPTER_MARKER ) ) {
				return $dir;
			}
		}

		return '';
	}

	/**
	 * Whether this request activates a plugin that is itself an MCP Adapter (single or bulk activation).
	 *
	 * @param array    $request     The request parameters ($_REQUEST).
	 * @param string   $plugins_dir The plugins directory.
	 * @param callable $file_exists file_exists, or a stand-in in tests.
	 * @return bool
	 */
	public static function is_adapter_activation_request( $request, $plugins_dir, $file_exists ) {
		$actions = array(
			isset( $request['action'] ) ? $request['action'] : '',
			isset( $request['action2'] ) ? $request['action2'] : '',
		);

		if ( ! array_intersect( $actions, array( 'activate', 'activate-selected' ) ) ) {
			return false;
		}

		$plugins   = isset( $request['checked'] ) && is_array( $request['checked'] ) ? $request['checked'] : array();
		$plugins[] = isset( $request['plugin'] ) ? $request['plugin'] : '';

		$plugins_dir = self::normalize( $plugins_dir, true );

		foreach ( $plugins as $plugin ) {
			if ( ! is_string( $plugin ) ) {
				continue;
			}

			$plugin = function_exists( 'wp_unslash' ) ? wp_unslash( $plugin ) : $plugin;
			$plugin = ltrim( self::normalize( trim( $plugin ) ), '/' );
			$folder = dirname( $plugin );

			if ( '' === $plugin || '.' === $folder || false !== strpos( $folder, '..' ) ) {
				continue;
			}

			if ( call_user_func( $file_exists, $plugins_dir . $folder . '/' . self::ADAPTER_MARKER ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a WP-CLI command will activate a plugin that is itself an MCP Adapter.
	 *
	 * Covers `plugin activate <name|path>…`, `plugin activate --all`, `plugin toggle`, and
	 * `plugin install … --activate[-network]`. An install's plugin is not on disk yet, so it is
	 * matched by name instead.
	 *
	 * @param string[] $args                  Positional arguments, e.g. [ 'plugin', 'activate', 'mcp-adapter' ].
	 * @param array    $assoc_args            Flags, e.g. [ 'all' => true ].
	 * @param string   $plugins_dir           The plugins directory.
	 * @param callable $file_exists           file_exists, or a stand-in in tests.
	 * @param bool     $any_adapter_installed Whether any installed plugin, other than the Kit, is an adapter.
	 * @return bool
	 */
	public static function is_cli_adapter_activation( $args, $assoc_args, $plugins_dir, $file_exists, $any_adapter_installed ) {
		$args       = array_values( (array) $args );
		$assoc_args = (array) $assoc_args;

		if ( ! isset( $args[0], $args[1] ) || 'plugin' !== $args[0] ) {
			return false;
		}

		$names = array_slice( $args, 2 );

		if ( 'install' === $args[1] ) {
			if ( empty( $assoc_args['activate'] ) && empty( $assoc_args['activate-network'] ) ) {
				return false;
			}

			foreach ( $names as $name ) {
				if ( is_string( $name ) && false !== stripos( $name, 'mcp-adapter' ) ) {
					return true;
				}
			}

			return false;
		}

		if ( ! in_array( $args[1], array( 'activate', 'toggle' ), true ) ) {
			return false;
		}

		if ( ! empty( $assoc_args['all'] ) ) {
			return (bool) $any_adapter_installed;
		}

		$plugins_dir = self::normalize( $plugins_dir, true );

		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			$folder = strtok( ltrim( self::normalize( trim( $name ) ), '/' ), '/' );

			if ( false === $folder || '' === $folder || false !== strpos( $folder, '..' ) ) {
				continue;
			}

			if ( call_user_func( $file_exists, $plugins_dir . $folder . '/' . self::ADAPTER_MARKER ) ) {
				return true;
			}
		}

		return false;
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
	 * Absolute paths of every active plugin main file, network-wide ones included.
	 *
	 * @return string[]
	 */
	private static function active_plugin_files() {
		$files = function_exists( 'wp_get_active_and_valid_plugins' ) ? wp_get_active_and_valid_plugins() : array();

		if ( is_multisite() && function_exists( 'wp_get_active_network_plugins' ) ) {
			$files = array_merge( $files, wp_get_active_network_plugins() );
		}

		return $files;
	}

	/**
	 * The command WP-CLI is running, already parsed before WordPress loads.
	 *
	 * @return array{0: string[], 1: array} Positional arguments and flags.
	 */
	private static function cli_arguments() {
		if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'get_runner' ) ) {
			$runner = \WP_CLI::get_runner();

			return array( (array) $runner->arguments, (array) $runner->assoc_args );
		}

		// Fallback: the raw command line, minus the script name.
		$args  = array();
		$assoc = array();

		foreach ( array_slice( isset( $GLOBALS['argv'] ) ? (array) $GLOBALS['argv'] : array(), 1 ) as $arg ) {
			if ( 0 === strpos( $arg, '--' ) ) {
				$parts              = explode( '=', substr( $arg, 2 ), 2 );
				$assoc[ $parts[0] ] = isset( $parts[1] ) ? $parts[1] : true;
				continue;
			}

			$args[] = $arg;
		}

		return array( $args, $assoc );
	}

	/**
	 * Whether any installed plugin, other than the Kit, is itself an MCP Adapter (for `activate --all`).
	 *
	 * @return bool
	 */
	private static function adapter_plugin_installed() {
		$kit_dir = self::normalize( SHAPED_KIT_DIR, true );
		$markers = glob( self::normalize( WP_PLUGIN_DIR, true ) . '*/' . self::ADAPTER_MARKER );

		if ( ! is_array( $markers ) ) {
			return false;
		}

		foreach ( $markers as $marker ) {
			if ( 0 !== strpos( self::normalize( $marker ), $kit_dir ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolves symlinks so a symlinked plugins directory still matches the class file's real path.
	 *
	 * @param string $path A filesystem path.
	 * @return string
	 */
	private static function real_path( $path ) {
		$real = '' === $path ? false : realpath( $path );

		return false === $real ? $path : $real;
	}

	/**
	 * Forward slashes, and an optional single trailing slash so a prefix match stops at a folder boundary.
	 *
	 * @param string $path   A filesystem path.
	 * @param bool   $is_dir Whether to end it with a slash.
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
