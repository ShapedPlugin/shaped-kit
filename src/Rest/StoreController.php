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
	 * Permission check for reading store catalog.
	 *
	 * @return bool
	 */
	public static function can_manage_store() {
		return current_user_can( 'manage_options' );
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
		return true;
	}

	/**
	 * Permission check for activating plugins.
	 *
	 * @return bool
	 */
	public static function can_activate_plugins() {
		return current_user_can( 'activate_plugins' );
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

			// Read-only license introspection for Pro products.
			if ( 'pro' === $product['edition'] ) {
				$license_info                   = \ShapedKit\Store\EddBridge::get_installed_license_info( $product );
				$product_data['has_license']    = $license_info['has_license'];
				$product_data['masked_license'] = $license_info['masked_key'];
				$product_data['license_status'] = $license_info['status'];
			}

			$enriched_products[] = $product_data;
		}

		return rest_ensure_response(
			array(
				'serial'     => $catalog['serial'],
				'expires_at' => $catalog['expires_at'],
				'products'   => $enriched_products,
			)
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
