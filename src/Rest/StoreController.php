<?php
/**
 * REST Controller for Shaped Kit Store Track (Phase S1).
 *
 * Exposes endpoints under `/wp-json/shaped-kit/v1/store/`:
 * - `GET /catalog`: Normalized catalog with local plugin installation & active status.
 * - `POST /install`: Installs and activates a free plugin from wordpress.org.
 * - `POST /activate`: Activates an already installed plugin.
 *
 * Permissions:
 * - Gated on manage_options / install_plugins / activate_plugins.
 * - Blocked when wp_is_file_mod_allowed( 'install_plugins' ) is false (SE01).
 * - Enforces network-admin check on multisite (SE13).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Rest;

use ShapedKit\Store\CatalogClient;
use ShapedKit\Store\Installer;
use ShapedKit\Store\StoreGate;

defined( 'ABSPATH' ) || exit;

/**
 * Class StoreController
 */
class StoreController {

	/**
	 * REST namespace for Shaped Kit store routes.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'shaped-kit/v1';

	/**
	 * Attach route hooks. Called during plugin initialization.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register Store REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		// 1. GET /store/catalog
		register_rest_route(
			self::REST_NAMESPACE,
			'/store/catalog',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_catalog' ),
				'permission_callback' => array( __CLASS__, 'can_manage_store' ),
			)
		);

		// 2. POST /store/install
		register_rest_route(
			self::REST_NAMESPACE,
			'/store/install',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'install_plugin' ),
				'permission_callback' => array( __CLASS__, 'can_install_plugins' ),
				'args'                => array(
					'slug'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'activate' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// 3. POST /store/activate
		register_rest_route(
			self::REST_NAMESPACE,
			'/store/activate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'activate_plugin' ),
				'permission_callback' => array( __CLASS__, 'can_activate_plugins' ),
				'args'                => array(
					'slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// 4. POST /store/install-pro
		register_rest_route(
			self::REST_NAMESPACE,
			'/store/install-pro',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'install_pro_plugin' ),
				'permission_callback' => array( __CLASS__, 'can_install_plugins' ),
				'args'                => array(
					'slug'        => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'license_key' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'activate'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// 5. POST /store/install-beta
		register_rest_route(
			self::REST_NAMESPACE,
			'/store/install-beta',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'install_beta_plugin' ),
				'permission_callback' => array( __CLASS__, 'can_install_plugins' ),
				'args'                => array(
					'slug'        => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'license_key' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'activate'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission check for reading store catalog. Every Store permission also needs the Store to be on (StoreGate).
	 *
	 * @return bool|\WP_Error
	 */
	public static function can_manage_store() {
		return StoreGate::allow( current_user_can( 'manage_options' ) );
	}

	/**
	 * Permission check for installing plugins (SE01, SE13).
	 *
	 * @return bool|\WP_Error
	 */
	public static function can_install_plugins() {
		$can = Installer::can_install();
		if ( is_wp_error( $can ) ) {
			return $can;
		}
		return StoreGate::allow( true );
	}

	/**
	 * Permission check for activating plugins.
	 *
	 * @return bool|\WP_Error
	 */
	public static function can_activate_plugins() {
		return StoreGate::allow( current_user_can( 'activate_plugins' ) );
	}

	/**
	 * Returns sanitized, normalized catalog entries enriched with installation and active state.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function get_catalog( $request ) {
		$force_refresh = $request->get_param( 'refresh' ) ? true : false;
		$catalog       = CatalogClient::get_catalog( $force_refresh );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$installed_plugins = get_plugins();
		$update_transient  = get_site_transient( 'update_plugins' );

		$mcp_registry = array();
		if ( class_exists( '\ShapedKit\Mcp\ProductRegistry' ) ) {
			foreach ( \ShapedKit\Mcp\ProductRegistry::all() as $mcp_item ) {
				if ( ! empty( $mcp_item['slug'] ) ) {
					$mcp_registry[ $mcp_item['slug'] ] = $mcp_item;
				}
			}
		}

		// The site's WordPress version without a pre-release suffix, so 6.9-beta2 counts as 6.9.
		$site_wp = preg_replace( '/-.*$/', '', (string) get_bloginfo( 'version' ) );

		$enriched_products = array();
		foreach ( $catalog['products'] as $product ) {
			$slug        = $product['slug'];
			$plugin_file = Installer::find_plugin_file( $slug );

			$installed      = null !== $plugin_file;
			$active         = $installed ? is_plugin_active( $plugin_file ) : false;
			$active_version = null;

			if ( $installed && isset( $installed_plugins[ $plugin_file ]['Version'] ) ) {
				$active_version = $installed_plugins[ $plugin_file ]['Version'];
			}

			$product_data                   = $product;
			$product_data['installed']      = $installed;
			$product_data['active']         = $active;
			$product_data['active_version'] = $active_version;
			$product_data['can_install']    = ! is_wp_error( Installer::can_install() );

			// What the site lacks for this plugin: a newer PHP or WordPress, or a plugin it depends on that is not active
			// (an e-commerce plugin for most of them). Shown as "Needs …" and the action is withheld (HE3, HE4).
			$product_data['needs_php'] = ! empty( $product['requires_php'] ) && version_compare( PHP_VERSION, $product['requires_php'], '<' )
				? $product['requires_php']
				: null;
			$product_data['needs_wp']  = ! empty( $product['requires_wp'] ) && version_compare( $site_wp, $product['requires_wp'], '<' )
				? $product['requires_wp']
				: null;

			$unmet = array();
			foreach ( ( isset( $product['requires_plugins'] ) ? (array) $product['requires_plugins'] : array() ) as $needed ) {
				$needed_file = Installer::find_plugin_file( $needed );
				if ( $needed_file && is_plugin_active( $needed_file ) ) {
					continue;
				}
				$unmet[] = ( $needed_file && ! empty( $installed_plugins[ $needed_file ]['Name'] ) )
					? $installed_plugins[ $needed_file ]['Name']
					: ( 'woocommerce' === $needed ? 'WooCommerce' : ucwords( str_replace( '-', ' ', $needed ) ) );
			}
			$product_data['unmet_plugins'] = $unmet;

			// AI tools the catalog promises but the registry cannot show yet: a plugin that is not installed, or one
			// older than the first release with them. The live registry state wins once the plugin runs them.
			$product_data['mcp_available']    = false;
			$product_data['mcp_after_update'] = false;
			if ( ! empty( $product['mcp_since'] ) && version_compare( (string) $product['stable_version'], $product['mcp_since'], '>=' ) ) {
				if ( ! $installed ) {
					$product_data['mcp_available'] = true;
				} elseif ( version_compare( (string) $active_version, $product['mcp_since'], '<' ) ) {
					$product_data['mcp_after_update'] = true;
				}
			}

			// A beta is offered only when it is newer than what is installed, or when nothing is. Installing an older
			// or equal version is refused (SE15), so the screen must not offer it. PHP's version_compare() orders
			// pre-releases correctly: 3.1.0-beta1 is above 3.0.7 and below 3.1.0.
			$product_data['beta_newer'] = ! empty( $product['beta_version'] )
				&& ( ! $installed || version_compare( (string) $active_version, (string) $product['beta_version'], '<' ) );

			// Pending update, from the core update_plugins site transient, by the real plugin file.
			$pending                        = $installed ? self::pending_update( $update_transient, $plugin_file ) : array(
				'has_update'     => false,
				'update_version' => null,
			);
			$product_data['has_update']     = $pending['has_update'];
			$product_data['update_version'] = $pending['update_version'];

			// Live AI tools state from shaped_kit/products registry.
			$family   = ! empty( $product['product_family'] ) ? $product['product_family'] : $slug;
			$mcp_item = self::resolve_mcp_entry( $mcp_registry, $family, $slug );

			$product_data['mcp_active']      = null !== $mcp_item;
			$product_data['mcp_enabled']     = ! empty( $mcp_item['enabled'] );
			$product_data['mcp_tools_count'] = isset( $mcp_item['tools_count'] ) ? (int) $mcp_item['tools_count'] : 0;

			// Read-only license introspection for Pro products. Only whether a license exists and its status
			// leave the server. The key itself, even masked, is not sent: the screen has no use for it (PRD H1, §4.1).
			if ( 'pro' === $product['edition'] ) {
				$license_info                   = \ShapedKit\Store\EddBridge::get_installed_license_info( $product );
				$product_data['has_license']    = $license_info['has_license'];
				$product_data['license_status'] = $license_info['status'];
			}

			$enriched_products[] = $product_data;
		}

		// A Pro the site already has, but the catalog does not list as an entry of its own (the Free names it with
		// `pro_slug`). It is added so the family reads as installed and the Free is not offered next to it (PRD HE20).
		$listed = array();
		foreach ( $catalog['products'] as $product ) {
			$listed[ $product['slug'] ] = true;
		}
		foreach ( $catalog['products'] as $product ) {
			if ( empty( $product['pro_slug'] ) || isset( $listed[ $product['pro_slug'] ] ) ) {
				continue;
			}
			$pro_file = Installer::find_plugin_file( $product['pro_slug'] );
			if ( null === $pro_file ) {
				continue;
			}
			$listed[ $product['pro_slug'] ] = true;
			$enriched_products[]            = self::detected_pro( $product, $pro_file, $installed_plugins, $update_transient, $mcp_registry );
		}

		// When the catalog was really fetched, in the site's own time format and timezone. A built-in list was never
		// fetched, so it has no time and says so.
		$sync         = CatalogClient::sync_state();
		$synced_label = ( 'remote' === $sync['source'] && $sync['fetched_at'] )
			? wp_date( get_option( 'time_format' ), $sync['fetched_at'] )
			: '';

		return rest_ensure_response(
			array(
				'serial'         => $catalog['serial'],
				'expires_at'     => $catalog['expires_at'],
				'catalog_source' => $sync['source'],
				'synced_at'      => $sync['fetched_at'],
				'synced_label'   => $synced_label,
				'products'       => $enriched_products,
			)
		);
	}

	/**
	 * Whether WordPress has an update waiting for a plugin, and to which version.
	 *
	 * @param mixed  $update_transient The `update_plugins` site transient.
	 * @param string $plugin_file      Plugin file, e.g. `wp-carousel-pro/wp-carousel-pro.php`.
	 * @return array{has_update: bool, update_version: string|null}
	 */
	private static function pending_update( $update_transient, $plugin_file ) {
		if ( ! $plugin_file || ! is_object( $update_transient ) || ! isset( $update_transient->response[ $plugin_file ] ) ) {
			return array(
				'has_update'     => false,
				'update_version' => null,
			);
		}

		return array(
			'has_update'     => true,
			'update_version' => ! empty( $update_transient->response[ $plugin_file ]->new_version )
				? (string) $update_transient->response[ $plugin_file ]->new_version
				: null,
		);
	}

	/**
	 * Resolves an MCP registry entry by family, exact slug, or base slug without -free/-pro suffix.
	 *
	 * @param array  $mcp_registry Registry items keyed by slug.
	 * @param string $family       Product family name.
	 * @param string $slug         Specific edition slug.
	 * @return array|null The matched registry item or null.
	 */
	private static function resolve_mcp_entry( array $mcp_registry, $family, $slug ) {
		if ( ! empty( $family ) && isset( $mcp_registry[ $family ] ) ) {
			return $mcp_registry[ $family ];
		}
		if ( ! empty( $slug ) && isset( $mcp_registry[ $slug ] ) ) {
			return $mcp_registry[ $slug ];
		}
		if ( ! empty( $slug ) ) {
			$base_slug = preg_replace( '/-(free|pro)$/', '', $slug );
			if ( ! empty( $base_slug ) && isset( $mcp_registry[ $base_slug ] ) ) {
				return $mcp_registry[ $base_slug ];
			}
		}
		return null;
	}

	/**
	 * The row for a Pro plugin that is installed here but not in the catalog. Built only from what WordPress knows about
	 * the installed plugin and from its Free's catalog entry: no license is read, nothing is sent to the store, and the
	 * row offers no install, activation, beta or settings link (the catalog has no verified data for any of them).
	 *
	 * @param array  $free              The Free's normalised catalog entry (it has `pro_slug`).
	 * @param string $pro_file          The Pro's plugin file.
	 * @param array  $installed_plugins `get_plugins()`.
	 * @param mixed  $update_transient  The `update_plugins` site transient.
	 * @param array  $mcp_registry      Registered products by family.
	 * @return array
	 */
	private static function detected_pro( array $free, $pro_file, array $installed_plugins, $update_transient, array $mcp_registry ) {
		$header   = isset( $installed_plugins[ $pro_file ] ) ? $installed_plugins[ $pro_file ] : array();
		$version  = isset( $header['Version'] ) ? sanitize_text_field( (string) $header['Version'] ) : null;
		$family   = $free['product_family'];
		$pro_slug = $free['pro_slug'];
		$pending  = self::pending_update( $update_transient, $pro_file );
		$mcp_item = self::resolve_mcp_entry( $mcp_registry, $family, $pro_slug );

		return array(
			'slug'             => $pro_slug,
			'name'             => ! empty( $header['Name'] ) ? sanitize_text_field( (string) $header['Name'] ) : $free['name'],
			'edition'          => 'pro',
			'product_family'   => $family,
			'item_id'          => null,
			'description'      => $free['description'],
			'stable_version'   => $version,
			'beta_version'     => null,
			'beta_newer'       => false,
			'requires_plugins' => array(),
			'priority'         => $free['priority'],
			'detected'         => true,
			'installed'        => true,
			'active'           => is_plugin_active( $pro_file ),
			'active_version'   => $version,
			'can_install'      => false,
			'needs_php'        => null,
			'needs_wp'         => null,
			'unmet_plugins'    => array(),
			'mcp_available'    => false,
			'mcp_after_update' => false,
			'mcp_active'       => null !== $mcp_item,
			'mcp_enabled'      => ! empty( $mcp_item['enabled'] ),
			'mcp_tools_count'  => isset( $mcp_item['tools_count'] ) ? (int) $mcp_item['tools_count'] : 0,
			'has_update'       => $pending['has_update'],
			'update_version'   => $pending['update_version'],
		);
	}

	/**
	 * Handles POST /store/install.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function install_plugin( $request ) {
		$slug     = $request->get_param( 'slug' );
		$activate = (bool) $request->get_param( 'activate' );

		$result = Installer::install_free_plugin( $slug, $activate );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Handles POST /store/install-pro.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function install_pro_plugin( $request ) {
		$slug        = $request->get_param( 'slug' );
		$license_key = $request->get_param( 'license_key' );
		$activate    = (bool) $request->get_param( 'activate' );

		$result = Installer::install_pro_plugin( $slug, $license_key, $activate );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Handles POST /store/activate.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function activate_plugin( $request ) {
		$slug = $request->get_param( 'slug' );

		$result = Installer::activate( $slug );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Handles POST /store/install-beta.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function install_beta_plugin( $request ) {
		$slug        = $request->get_param( 'slug' );
		$license_key = $request->get_param( 'license_key' );
		$activate    = (bool) $request->get_param( 'activate' );

		$result = \ShapedKit\Store\BetaInstaller::install_beta( $slug, $license_key, $activate );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
