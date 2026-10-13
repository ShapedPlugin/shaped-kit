<?php
/**
 * Self-updater engine for Shaped Kit (Phase S4).
 *
 * Integrates with WordPress core plugin update pipeline:
 * - `pre_set_site_transient_update_plugins`: Injects update object when newer version exists in catalog.
 * - `plugins_api`: Returns plugin metadata popup details.
 * - `upgrader_pre_download`: Enforces pre-unpack SHA-256 verification on Shaped Kit's own download package.
 * - Nonce-protected force update check: `admin.php?shaped_kit_check_update=1`.
 *
 * Source citations:
 * - pre_set_site_transient_update_plugins filter: wp-includes/update.php:121
 * - plugins_api filter: wp-admin/includes/plugin-install.php:143
 * - upgrader_pre_download filter: wp-admin/includes/class-wp-upgrader.php:322
 * - plugin_basename(): wp-includes/plugin.php:833
 * - wp_safe_redirect(): wp-includes/pluggable.php:1515
 *
 * @package ShapedKit
 */

namespace ShapedKit\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Class SelfUpdater
 */
class SelfUpdater {

	/**
	 * Shaped Kit slug.
	 *
	 * @var string
	 */
	const SLUG = 'shaped-kit';

	/**
	 * Registers WordPress core update pipeline hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'filter_update_plugins' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'filter_upgrader_pre_download' ), 10, 4 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'handle_force_check' ) );
		}
	}

	/**
	 * Injects Shaped Kit update object into site_transient_update_plugins when newer version exists.
	 *
	 * @param mixed $transient Transient data object.
	 * @return mixed
	 */
	public static function filter_update_plugins( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		$matched = self::get_kit_catalog_entry();
		if ( ! $matched || empty( $matched['stable_version'] ) ) {
			return $transient;
		}

		$current_version = defined( 'SHAPED_KIT_VERSION' ) ? SHAPED_KIT_VERSION : '0.1.0';
		$target_version  = $matched['stable_version'];

		$file = defined( 'SHAPED_KIT_FILE' ) ? plugin_basename( SHAPED_KIT_FILE ) : 'shaped-kit/shaped-kit.php';

		// If a newer version exists in catalog.
		if ( version_compare( $current_version, $target_version, '<' ) ) {
			$package_url = ! empty( $matched['package_url'] ) ? $matched['package_url'] : '';

			$update_obj = (object) array(
				'id'            => 'shaped-kit',
				'slug'          => self::SLUG,
				'plugin'        => $file,
				'new_version'   => $target_version,
				'url'           => 'https://shapedplugin.com/shaped-kit',
				'package'       => $package_url,
				'icons'         => array(),
				'banners'       => array(),
				'banners_rtl'   => array(),
				'tested'        => '6.9',
				'requires_php'  => '7.4',
				'compatibility' => new \stdClass(),
			);

			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}

			$transient->response[ $file ] = $update_obj;
		} elseif ( isset( $transient->response[ $file ] ) ) {
			// Ensure no stale update remains in response.
			unset( $transient->response[ $file ] );
		}

		return $transient;
	}

	/**
	 * Populates the plugin details popup (`plugins_api`) for Shaped Kit.
	 *
	 * @param false|object|\WP_Error $res    Result object or false.
	 * @param string                 $action API action (e.g. 'plugin_information').
	 * @param object                 $args   Plugin arguments.
	 * @return false|object|\WP_Error
	 */
	public static function filter_plugins_api( $res, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $res;
		}

		if ( empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $res;
		}

		$matched = self::get_kit_catalog_entry();
		if ( ! $matched ) {
			return $res;
		}

		$current_version = defined( 'SHAPED_KIT_VERSION' ) ? SHAPED_KIT_VERSION : '0.1.0';
		$target_version  = ! empty( $matched['stable_version'] ) ? $matched['stable_version'] : $current_version;

		return (object) array(
			'name'          => 'Shaped Kit',
			'slug'          => self::SLUG,
			'version'       => $target_version,
			'author'        => '<a href="https://shapedplugin.com">ShapedPlugin</a>',
			'homepage'      => 'https://shapedplugin.com',
			'requires'      => '6.9',
			'tested'        => '6.9',
			'requires_php'  => '7.4',
			'download_link' => ! empty( $matched['package_url'] ) ? $matched['package_url'] : '',
			'sections'      => array(
				'description' => ! empty( $matched['description'] ) ? $matched['description'] : 'Shaped Kit companion plugin.',
				'changelog'   => 'See official release notes at shapedplugin.com.',
			),
		);
	}

	/**
	 * Enforces SHA-256 hash verification on Shaped Kit's own download package (SE64).
	 *
	 * Hook: `upgrader_pre_download`.
	 *
	 * @param false|string|\WP_Error $reply      Pre-download reply.
	 * @param string                 $package    Download package URL or path.
	 * @param \WP_Upgrader           $upgrader   Upgrader instance.
	 * @param array                  $hook_extra Extra context hooks.
	 * @return false|string|\WP_Error
	 */
	public static function filter_upgrader_pre_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		// Only intercept when updating Shaped Kit.
		$kit_file = defined( 'SHAPED_KIT_FILE' ) ? plugin_basename( SHAPED_KIT_FILE ) : 'shaped-kit/shaped-kit.php';

		$is_kit_update = false;
		if ( ! empty( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $kit_file ) {
			$is_kit_update = true;
		} elseif ( ! empty( $hook_extra['plugins'] ) && in_array( $kit_file, (array) $hook_extra['plugins'], true ) ) {
			$is_kit_update = true;
		}

		if ( ! $is_kit_update ) {
			return $reply;
		}

		$matched = self::get_kit_catalog_entry();
		if ( ! $matched || empty( $matched['stable_sha256'] ) ) {
			return $reply;
		}

		// Validate host allowlist (SE06).
		if ( ! PackageVerifier::is_allowed_host( $package ) ) {
			return new \WP_Error(
				'unauthorized_download_host',
				__( 'The download host for Shaped Kit update is not allowlisted.', 'shaped-kit' ),
				array( 'status' => 403 )
			);
		}

		// Download package manually to verify checksum before upgrader extracts it (SE05).
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temp_file = download_url( $package, 300 );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$verify = PackageVerifier::verify_checksum( $temp_file, $matched['stable_sha256'] );
		if ( is_wp_error( $verify ) ) {
			return $verify;
		}

		// Return path to verified local file so upgrader unpacks this verified file directly.
		return $temp_file;
	}

	/**
	 * Handles manual force-check link action (`admin.php?shaped_kit_check_update=1`).
	 *
	 * @return void
	 */
	public static function handle_force_check() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['shaped_kit_check_update'] ) ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		check_admin_referer( 'shaped_kit_force_check_update' );

		// Flush catalog cache.
		delete_transient( CatalogClient::TRANSIENT_CATALOG_CACHE );
		delete_transient( CatalogClient::TRANSIENT_THROTTLE );

		// Force fresh fetch.
		CatalogClient::get_catalog( true );

		// Force core to check updates on next page load.
		delete_site_transient( 'update_plugins' );

		wp_safe_redirect( admin_url( 'plugins.php?shaped_kit_checked=1' ) );
		exit;
	}

	/**
	 * Helper to retrieve Shaped Kit entry from catalog.
	 *
	 * @return array|null
	 */
	private static function get_kit_catalog_entry() {
		$catalog = CatalogClient::get_catalog();
		if ( empty( $catalog['products'] ) || ! is_array( $catalog['products'] ) ) {
			return null;
		}

		foreach ( $catalog['products'] as $item ) {
			if ( self::SLUG === $item['slug'] ) {
				return $item;
			}
		}

		return null;
	}
}
