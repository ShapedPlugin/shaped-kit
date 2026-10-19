<?php
/**
 * Self-updater engine for Shaped Kit (Phase S4).
 *
 * Integrates with WordPress core plugin update pipeline:
 * - `pre_set_site_transient_update_plugins`: Injects update object when newer version exists in catalog.
 * - `plugins_api`: Returns plugin metadata popup details.
 * - `upgrader_pre_download`: Enforces pre-unpack SHA-256 verification on Shaped Kit's own download package.
 * - "Check for update" link in the plugin's row on the Plugins screen (`plugin_row_meta`), and the
 *   nonce-protected force check it points at: `plugins.php?shaped_kit_check_update=1&_wpnonce=…`.
 *
 * Everything but the download check does nothing while the Store is off (StoreGate). The download check stays
 * on: it only ever acts on a Kit update some other source put in the update list, and it refuses what it cannot verify.
 *
 * Source citations:
 * - pre_set_site_transient_update_plugins filter: wp-includes/update.php:121
 * - plugins_api filter: wp-admin/includes/plugin-install.php:143
 * - plugin_row_meta filter: wp-admin/includes/class-wp-plugins-list-table.php:1341 (args: meta, file, data, status)
 * - wp_nonce_url(): wp-includes/functions.php:1875; wp_admin_notice(): wp-includes/functions.php:9307
 * - upgrader_pre_download filter: wp-admin/includes/class-wp-upgrader.php:322 (any reply other than false is used as the download, :323-325)
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
			add_action( 'admin_init', array( __CLASS__, 'on_admin_init' ) );
			add_filter( 'plugin_row_meta', array( __CLASS__, 'filter_row_meta' ), 10, 2 );
			add_action( 'admin_notices', array( __CLASS__, 'checked_notice' ) );
			add_action( 'network_admin_notices', array( __CLASS__, 'checked_notice' ) );
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

		// Store off: no catalog request, no update offered.
		if ( ! StoreGate::is_live() ) {
			return $transient;
		}

		$matched = self::get_kit_catalog_entry();
		if ( ! $matched || empty( $matched['stable_version'] ) ) {
			return $transient;
		}

		$current_version = defined( 'SHAPED_KIT_VERSION' ) ? SHAPED_KIT_VERSION : '0.1.0';
		$target_version  = $matched['stable_version'];

		$file = defined( 'SHAPED_KIT_FILE' ) ? plugin_basename( SHAPED_KIT_FILE ) : 'shaped-kit/shaped-kit.php';

		$package_url = ! empty( $matched['package_url'] ) ? $matched['package_url'] : '';

		// A newer version counts only when the catalog says where to get it. An update with no package would show
		// a notice that cannot be acted on, so it is not offered.
		if ( '' !== $package_url && version_compare( $current_version, $target_version, '<' ) ) {

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
			// Ensure no stale update remains in response (up to date, or nothing to download).
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

		if ( empty( $args->slug ) || self::SLUG !== $args->slug || ! StoreGate::is_live() ) {
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

		// Another filter already refused the download: leave its refusal as it is.
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$matched = self::get_kit_catalog_entry();
		if ( ! $matched || empty( $matched['stable_sha256'] ) ) {
			return new \WP_Error(
				'unverified_package',
				__( 'Shaped Kit update package cannot be verified: missing catalog checksum.', 'shaped-kit' ),
				array( 'status' => 403 )
			);
		}

		// Another filter already fetched the package (a mirror, a cache). Core would unpack whatever path it
		// returns, so it gets the same checksum check as a file the Kit downloads itself. The download host
		// is not ours to judge here: the file is already on disk and the checksum is what vouches for it.
		if ( is_string( $reply ) && '' !== $reply ) {
			$verified = PackageVerifier::verify_checksum( $reply, $matched['stable_sha256'] );

			return is_wp_error( $verified ) ? $verified : $reply;
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
	 * What WordPress runs on `admin_init`. It takes no argument on purpose.
	 *
	 * `do_action( 'admin_init' )` carries none, and core then hands every callback an empty string as its first argument
	 * (wp-includes/plugin.php:513-515). Registered directly, `handle_force_check( $terminate = true )` would read that
	 * as `$terminate = ''`, and the request would carry on after the redirect instead of ending.
	 *
	 * @return void
	 */
	public static function on_admin_init() {
		self::handle_force_check();
	}

	/**
	 * Handles the force-check link (`plugins.php?shaped_kit_check_update=1`, nonce `shaped_kit_force_check_update`).
	 * It works on any admin page because it runs on `admin_init`.
	 *
	 * @param bool $terminate Whether to terminate request with exit. Defaults to true.
	 * @return bool True if force check executed, false otherwise.
	 */
	public static function handle_force_check( $terminate = true ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['shaped_kit_check_update'] ) ) {
			return false;
		}

		if ( ! current_user_can( 'update_plugins' ) || ! StoreGate::is_live() ) {
			return false;
		}

		check_admin_referer( 'shaped_kit_force_check_update' );

		// Flush catalog cache.
		delete_transient( CatalogClient::TRANSIENT_CATALOG_CACHE );
		delete_transient( CatalogClient::TRANSIENT_THROTTLE );

		// Force fresh fetch.
		CatalogClient::get_catalog( true );

		// Force core to check updates on next page load.
		delete_site_transient( 'update_plugins' );

		// Back to the Plugins screen filtered to the Kit, so its row (with an update, if there is one) is what is seen.
		wp_safe_redirect( self::plugins_screen_url( array( 'shaped_kit_checked' => '1' ) ) );
		if ( $terminate ) {
			exit;
		}

		return true;
	}

	/**
	 * Adds "Check for update" to the Kit's row on the Plugins screen. It is shown only to someone the check would
	 * accept (`update_plugins`) and only while the Store is on, so it is never a link that does nothing.
	 *
	 * @param string[] $links       The row's meta links.
	 * @param string   $plugin_file The row's plugin file.
	 * @return string[]
	 */
	public static function filter_row_meta( $links, $plugin_file ) {
		$kit_file = defined( 'SHAPED_KIT_FILE' ) ? plugin_basename( SHAPED_KIT_FILE ) : 'shaped-kit/shaped-kit.php';

		if ( $plugin_file !== $kit_file || ! StoreGate::is_live() || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$url = esc_url( wp_nonce_url( self::plugins_screen_url( array( 'shaped_kit_check_update' => '1' ), false ), 'shaped_kit_force_check_update' ) );

		$links['shaped_kit_check_update'] = '<a href="' . $url . '">' . esc_html__( 'Check for update', 'shaped-kit' ) . '</a>';

		return $links;
	}

	/**
	 * Says the check ran, on the Plugins screen the force check sends the user back to. The message is fixed
	 * text: nothing from the request is printed.
	 *
	 * @return void
	 */
	public static function checked_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['shaped_kit_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) ) {
			return;
		}

		wp_admin_notice(
			esc_html__( 'Checked for a Shaped Kit update. If a newer version is available, it is listed in the Shaped Kit row below.', 'shaped-kit' ),
			array(
				'type'        => 'info',
				'dismissible' => true,
			)
		);
	}

	/**
	 * The Plugins screen of where the user is (the network's in the network admin), with extra query arguments.
	 *
	 * @param array<string, string> $args   Query arguments to add.
	 * @param bool                  $filter Whether to also narrow the list to the Kit and show every status.
	 * @return string
	 */
	private static function plugins_screen_url( $args, $filter = true ) {
		if ( $filter ) {
			$args = array_merge(
				array(
					's'             => 'shaped-kit',
					'plugin_status' => 'all',
				),
				$args
			);
		}

		return add_query_arg( $args, is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ) );
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
