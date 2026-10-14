<?php
/**
 * The MCP Server template file.
 *
 * Copy this file into a product's MCP module.
 * See README.md in this folder for the step-by-step token replacement checklist.
 *
 * @package ShapedPlugin
 */

namespace ShapedPlugin\Template\Mcp;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Creates the dedicated product MCP server on the active adapter.
 *
 * It is an isolated server with its own endpoint: `/wp-json/{slug}/mcp`.
 * It is completely distinct from the adapter's shared default server.
 * Every request is authorized by `Permission_Gate::transport()`.
 */
class Server {

	/**
	 * Oldest adapter version accepted: 0.1.0 (the floor verified across all released adapters).
	 *
	 * Verified across upstream 0.3.0 (WooCommerce copy), 0.4.0, 0.4.1 (Rank Math), 0.5.0 (Zip AI),
	 * 0.6.0, 0.6.1, and 0.7.0 (Shaped Kit). A copy that reports no version is refused as unknown.
	 */
	const MIN_ADAPTER_VERSION = '0.1.0';

	/**
	 * REST route of the endpoint under Module::SLUG.
	 */
	const ROUTE = 'mcp';

	/**
	 * Transient holding the last error create_server() returned.
	 * Kept so admin settings pages can display the error without waiting for a REST request.
	 */
	const ERROR_TRANSIENT = 'template_prefix_mcp_server_error';

	/**
	 * Outcome of the last register() call on this request.
	 *
	 * @var array{status: string, message: string, adapter_version: string|null}
	 */
	private static $status = array(
		'status'          => 'not_run',
		'message'         => '',
		'adapter_version' => null,
	);

	/**
	 * Create the server. Hooked to the adapter's `mcp_adapter_init` action.
	 *
	 * @param mixed       $adapter          The adapter instance (\WP\MCP\Core\McpAdapter).
	 * @param string|null $version_override Optional version override for testing.
	 *
	 * @return void
	 */
	public static function register( $adapter, $version_override = null ) {
		if ( ! Module::should_load() ) {
			return;
		}

		$version      = null !== $version_override ? $version_override : self::adapter_version();
		self::$status = array(
			'status'          => 'not_run',
			'message'         => '',
			'adapter_version' => $version,
		);

		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			self::fail( 'adapter_unusable', __( 'The MCP adapter that is loaded does not offer create_server().', 'template-domain' ) );
			return;
		}

		$problem = self::version_problem( $version );
		if ( $problem ) {
			self::fail( $problem['status'], $problem['message'] );
			return;
		}

		$prompts = version_compare( (string) $version, '0.5.0', '>=' )
			? Abilities_Registrar::prompt_names()
			: array();

		$result = $adapter->create_server(
			Module::SLUG,
			Module::SLUG,
			self::ROUTE,
			sprintf(
				/* translators: %s: Product name */
				__( '%s MCP Server', 'template-domain' ),
				Module::NAME
			),
			sprintf(
				/* translators: %1$s: Product name, %2$s: orientation tool name */
				__( 'Tools for %1$s. Call %2$s first.', 'template-domain' ),
				Module::NAME,
				Module::SLUG . '-get-context'
			),
			Module::VERSION,
			array( '\WP\MCP\Transport\HttpTransport' ),
			'\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler',
			'\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler',
			Abilities_Registrar::names(),
			array(),
			$prompts,
			array( Permission_Gate::class, 'transport' )
		);

		if ( $result instanceof WP_Error ) {
			self::fail( 'error', $result->get_error_message() );
			return;
		}

		self::$status['status'] = 'ready';
		delete_transient( self::ERROR_TRANSIENT );
	}

	/**
	 * Outcome of the last register() call on this request.
	 *
	 * @return array{status: string, message: string, adapter_version: string|null}
	 */
	public static function status() {
		return self::$status;
	}

	/**
	 * Calculates the state of the MCP feature on any request.
	 *
	 * On REST requests, register() outcome is returned.
	 * On admin pages, state is calculated dynamically from the environment.
	 *
	 * Statuses: ready, disabled, wordpress_unsupported, adapter_required,
	 * adapter_unusable, adapter_unknown, adapter_outdated, error.
	 *
	 * @return array{status: string, message: string, adapter_version: string|null}
	 */
	public static function availability() {
		global $wp_version;

		$version = self::adapter_version();
		$answer  = static function ( $status, $message = '' ) use ( $version ) {
			return array(
				'status'          => $status,
				'message'         => $message,
				'adapter_version' => $version,
			);
		};

		if ( ! Module::is_supported_wordpress( $wp_version ) || ! function_exists( 'wp_register_ability' ) ) {
			/* translators: %s: minimum WordPress version. */
			return $answer( 'wordpress_unsupported', sprintf( __( 'MCP needs WordPress %s or newer.', 'template-domain' ), Module::MIN_WP_VERSION ) );
		}

		// State check priority (E103): If the kill-switch was toggled to off, immediately report disabled,
		// outranking any in-memory server built earlier during the same request.
		if ( ! Permission_Gate::is_enabled() ) {
			return $answer( 'disabled' );
		}

		if ( 'not_run' !== self::$status['status'] ) {
			return self::$status;
		}

		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return $answer( 'adapter_required', __( 'No MCP adapter is loaded. Install Shaped Kit, which provides one.', 'template-domain' ) );
		}

		if ( ! method_exists( '\WP\MCP\Core\McpAdapter', 'create_server' ) ) {
			return $answer( 'adapter_unusable', __( 'The MCP adapter that is loaded does not offer create_server().', 'template-domain' ) );
		}

		$problem = self::version_problem( $version );
		if ( $problem ) {
			return $answer( $problem['status'], $problem['message'] );
		}

		$error = get_transient( self::ERROR_TRANSIENT );
		if ( is_string( $error ) && '' !== $error ) {
			return $answer( 'error', $error );
		}

		return $answer( 'ready' );
	}

	/**
	 * Detects which entity supplied the active adapter.
	 *
	 * @return array{type: string, name: string} Type: 'shaped-kit', 'plugin', 'other', or 'none'.
	 */
	public static function adapter_source() {
		if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			return array(
				'type' => 'none',
				'name' => '',
			);
		}

		$file = (string) ( new \ReflectionClass( '\\WP\\MCP\\Core\\McpAdapter' ) )->getFileName();

		return self::classify_adapter_path(
			$file,
			defined( 'SHAPED_KIT_DIR' ) ? (string) constant( 'SHAPED_KIT_DIR' ) : '',
			array( WP_PLUGIN_DIR, WPMU_PLUGIN_DIR )
		);
	}

	/**
	 * Classifies the supplier of the adapter based on file path.
	 * Pure function for testing.
	 *
	 * @param string   $file        Adapter main class file path.
	 * @param string   $kit_dir     Shaped Kit directory if active.
	 * @param string[] $plugin_dirs Directories holding plugins.
	 *
	 * @return array{type: string, name: string}
	 */
	public static function classify_adapter_path( $file, $kit_dir, $plugin_dirs ) {
		$file = self::normalize_path( $file );

		if ( '' !== $kit_dir && 0 === strpos( $file, self::normalize_path( $kit_dir ) . '/' ) ) {
			return array(
				'type' => 'shaped-kit',
				'name' => 'shaped-kit',
			);
		}

		foreach ( $plugin_dirs as $dir ) {
			$dir = self::normalize_path( $dir );

			if ( '' !== $dir && 0 === strpos( $file, $dir . '/' ) ) {
				$folder = strtok( substr( $file, strlen( $dir ) + 1 ), '/' );

				return array(
					'type' => 'plugin',
					'name' => (string) $folder,
				);
			}
		}

		return array(
			'type' => 'other',
			'name' => '',
		);
	}

	/**
	 * Looks up the human-readable plugin header name for the adapter supplier.
	 *
	 * @param string $folder Plugin folder name.
	 *
	 * @return string Plugin Name header or empty string.
	 */
	public static function plugin_display_name( $folder ) {
		if ( '' === (string) $folder ) {
			return '';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return self::display_name_in( get_plugins(), (string) $folder );
	}

	/**
	 * Resolves display name from a plugin headers list.
	 * Pure function for unit testing.
	 *
	 * @param array<string, array<string, mixed>> $plugins Plugin headers.
	 * @param string                              $folder  Target folder.
	 *
	 * @return string
	 */
	public static function display_name_in( $plugins, $folder ) {
		if ( '' === $folder || ! is_array( $plugins ) ) {
			return '';
		}

		foreach ( $plugins as $file => $header ) {
			if ( 0 !== strpos( (string) $file, $folder . '/' ) ) {
				continue;
			}

			$name = is_array( $header ) && isset( $header['Name'] ) && is_string( $header['Name'] ) ? trim( $header['Name'] ) : '';

			if ( '' !== $name ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Normalizes file path to forward slashes without trailing slash.
	 *
	 * @param string $path Path.
	 *
	 * @return string
	 */
	private static function normalize_path( $path ) {
		return rtrim( str_replace( '\\', '/', (string) $path ), '/' );
	}

	/**
	 * Resolves the active adapter version.
	 *
	 * @return string|null
	 */
	public static function adapter_version() {
		if ( class_exists( '\WP\MCP\Core\McpAdapter' ) && defined( '\WP\MCP\Core\McpAdapter::VERSION' ) ) {
			return (string) constant( '\WP\MCP\Core\McpAdapter::VERSION' );
		}

		return defined( 'WP_MCP_VERSION' ) ? (string) constant( 'WP_MCP_VERSION' ) : null;
	}

	/**
	 * Resolves the full REST URL for this product's MCP endpoint.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( Module::SLUG . '/' . self::ROUTE );
	}

	/**
	 * Record a registration failure.
	 *
	 * @param string $status  Failure status code.
	 * @param string $message Error message.
	 *
	 * @return void
	 */
	private static function fail( $status, $message ) {
		self::$status['status']  = $status;
		self::$status['message'] = $message;

		if ( 'error' === $status ) {
			set_transient( self::ERROR_TRANSIENT, $message, DAY_IN_SECONDS );
		}
	}

	/**
	 * Evaluates whether an adapter version is supported.
	 *
	 * @param string|null $version Adapter version.
	 *
	 * @return array{status: string, message: string}|null
	 */
	private static function version_problem( $version ) {
		if ( null === $version ) {
			return array(
				'status'  => 'adapter_unknown',
				'message' => __( 'The loaded MCP adapter does not report its version, so it cannot be used safely.', 'template-domain' ),
			);
		}

		if ( version_compare( $version, self::MIN_ADAPTER_VERSION, '<' ) ) {
			return array(
				'status'  => 'adapter_outdated',
				'message' => sprintf(
					/* translators: 1: adapter version found, 2: minimum version. */
					__( 'The loaded MCP adapter is version %1$s. Version %2$s or newer is needed.', 'template-domain' ),
					$version,
					self::MIN_ADAPTER_VERSION
				),
			);
		}

		return null;
	}
}
