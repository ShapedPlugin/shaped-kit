<?php
/**
 * Safe plugin installer and activator engine for Shaped Kit Store Track (Phase S1).
 *
 * Wraps WordPress Core's Plugin_Upgrader with:
 * - Exclusive mutex locking via WP_Upgrader::create_lock() (SE08).
 * - Filesystem permission and direct access checks (SE09).
 * - Strict destination directory protection (abort_if_destination_exists) (SE07).
 * - Output buffering to discard wire contamination (SE16).
 * - Pre-unpack SHA-256 verification (SE05).
 * - Host allowlisting (SE06).
 * - Clean activation with error sandboxing (SE10).
 *
 * Source citations:
 * - wp_is_file_mod_allowed(): wp-includes/load.php:1829
 * - WP_Upgrader::create_lock(): wp-admin/includes/class-wp-upgrader.php:1057
 * - WP_Upgrader::release_lock(): wp-admin/includes/class-wp-upgrader.php:1099
 * - Plugin_Upgrader::install(): wp-admin/includes/class-plugin-upgrader.php:118
 * - The result checks after install(): wp_ajax_install_plugin(), wp-admin/includes/ajax-actions.php:4549-4574
 * - WP_Upgrader::install_package() refusing an existing folder (`folder_exists`): wp-admin/includes/class-wp-upgrader.php:668-676
 * - activate_plugin(): wp-admin/includes/plugin.php:647
 * - is_plugin_active(): wp-admin/includes/plugin.php:539
 * - get_plugins(): wp-admin/includes/plugin.php:300
 *
 * @package ShapedKit
 */

namespace ShapedKit\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Class Installer
 */
class Installer {

	/**
	 * Mutex lock identifier for installer operations.
	 *
	 * @var string
	 */
	const LOCK_NAME = 'shaped_kit_store_install';

	/**
	 * Lock timeout in seconds (10 minutes).
	 *
	 * @var int
	 */
	const LOCK_TIMEOUT = 600;

	/**
	 * Installs and optionally activates a free plugin from wordpress.org.
	 *
	 * @param string $slug       Plugin slug (e.g. 'location-weather').
	 * @param bool   $activate   Whether to activate the plugin after installation.
	 * @return array|\WP_Error Status payload on success, or WP_Error on failure.
	 */
	public static function install_free_plugin( $slug, $activate = true ) {
		// Capability and file modification checks (SE01, SE13).
		$permission_check = self::can_install();
		if ( is_wp_error( $permission_check ) ) {
			return $permission_check;
		}

		// Ensure catalog contains the plugin and get expected checksum.
		$catalog = CatalogClient::get_catalog();
		$matched = null;
		foreach ( $catalog['products'] as $item ) {
			if ( $item['slug'] === $slug && 'free' === $item['edition'] ) {
				$matched = $item;
				break;
			}
		}

		if ( ! $matched ) {
			return new \WP_Error(
				'product_not_in_catalog',
				__( 'The requested plugin is not available in the ShapedPlugin store catalog.', 'shaped-kit' ),
				array( 'status' => 404 )
			);
		}

		// Acquire exclusive upgrader lock (SE08).
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( ! \WP_Upgrader::create_lock( self::LOCK_NAME, self::LOCK_TIMEOUT ) ) {
			return new \WP_Error(
				'install_in_progress',
				__( 'Another installation or update is already running. Please wait and try again.', 'shaped-kit' ),
				array( 'status' => 429 )
			);
		}

		try {
			// Resolve wordpress.org download package URL.
			$package_url = sprintf( 'https://downloads.wordpress.org/plugin/%s.%s.zip', $slug, $matched['stable_version'] );

			// Verify host allowlist (SE06).
			if ( ! PackageVerifier::is_allowed_host( $package_url ) ) {
				\WP_Upgrader::release_lock( self::LOCK_NAME );
				return new \WP_Error(
					'unauthorized_download_host',
					__( 'The download host is not allowed.', 'shaped-kit' ),
					array( 'status' => 403 )
				);
			}

			// Run download and checksum verification (SE05).
			$download_result = self::download_and_verify( $package_url, $matched['stable_sha256'] );
			if ( is_wp_error( $download_result ) ) {
				\WP_Upgrader::release_lock( self::LOCK_NAME );
				return $download_result;
			}

			$temp_file = $download_result;

			// A fresh install never replaces a folder that is already there (SE07).
			$failure = self::install_package_file( $temp_file, false, $slug );

			// Clean up downloaded temp file if still present.
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}

			\WP_Upgrader::release_lock( self::LOCK_NAME );

			if ( $failure ) {
				return $failure;
			}

			// Locate installed plugin main file.
			$plugin_file = self::find_plugin_file( $slug );
			$activated   = false;

			if ( $activate && $plugin_file ) {
				$act_result = self::activate_plugin_safely( $plugin_file );
				if ( is_wp_error( $act_result ) ) {
					return new \WP_Error(
						'plugin_activation_failed',
						sprintf(
							/* translators: %s: activation error message */
							__( 'Plugin installed successfully, but activation failed: %s', 'shaped-kit' ),
							$act_result->get_error_message()
						),
						array( 'status' => 500 )
					);
				}
				$activated = true;
			}

			return array(
				'status'      => 'installed',
				'slug'        => $slug,
				'version'     => $matched['stable_version'],
				'activated'   => $activated,
				'plugin_file' => $plugin_file,
			);
		} catch ( \Throwable $e ) {
			\WP_Upgrader::release_lock( self::LOCK_NAME );
			return new \WP_Error(
				'install_exception',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Installs and activates a Pro plugin using single-use license verification with EDD.
	 *
	 * Zero license key persistence in database.
	 *
	 * @param string $slug        Plugin slug (e.g. 'location-weather-pro').
	 * @param string $license_key Raw license key provided in request.
	 * @param bool   $activate    Whether to activate upon successful installation.
	 * @return array|\WP_Error
	 */
	public static function install_pro_plugin( $slug, $license_key, $activate = true ) {
		// Capability and file modification checks (SE01, SE13).
		$permission_check = self::can_install();
		if ( is_wp_error( $permission_check ) ) {
			return $permission_check;
		}

		// Ensure catalog contains the Pro product.
		$catalog = CatalogClient::get_catalog();
		$matched = null;
		foreach ( $catalog['products'] as $item ) {
			if ( $item['slug'] === $slug && 'pro' === $item['edition'] ) {
				$matched = $item;
				break;
			}
		}

		if ( ! $matched ) {
			return new \WP_Error(
				'product_not_in_catalog',
				__( 'The requested Pro plugin is not available in the store catalog.', 'shaped-kit' ),
				array( 'status' => 404 )
			);
		}

		// 1. Resolve package URL via EDD POST get_version.
		$version_info = EddBridge::get_version( $matched, $license_key );
		if ( is_wp_error( $version_info ) ) {
			return $version_info;
		}

		$package_url = $version_info['package_url'];

		// 2. Validate host allowlist (SE06).
		if ( ! PackageVerifier::is_allowed_host( $package_url ) ) {
			return new \WP_Error(
				'unauthorized_download_host',
				__( 'The package download host is not allowed.', 'shaped-kit' ),
				array( 'status' => 403 )
			);
		}

		// 3. Acquire exclusive upgrader lock (SE08).
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( ! \WP_Upgrader::create_lock( self::LOCK_NAME, self::LOCK_TIMEOUT ) ) {
			return new \WP_Error(
				'install_in_progress',
				__( 'Another installation or update is already running. Please wait and try again.', 'shaped-kit' ),
				array( 'status' => 429 )
			);
		}

		try {
			// 4. Download and verify SHA-256 (SE05).
			$download_result = self::download_and_verify( $package_url, $matched['stable_sha256'] );
			if ( is_wp_error( $download_result ) ) {
				\WP_Upgrader::release_lock( self::LOCK_NAME );
				return $download_result;
			}

			$temp_file = $download_result;

			// 5. Install. A fresh install never replaces a folder that is already there (SE07).
			$failure = self::install_package_file( $temp_file, false, $slug );

			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}

			\WP_Upgrader::release_lock( self::LOCK_NAME );

			if ( $failure ) {
				return $failure;
			}

			// 6. Find plugin file and activate.
			$plugin_file = self::find_plugin_file( $slug );
			$activated   = false;

			if ( $activate && $plugin_file ) {
				$act_result = self::activate_plugin_safely( $plugin_file );
				if ( is_wp_error( $act_result ) ) {
					return new \WP_Error(
						'plugin_activation_failed',
						sprintf(
							/* translators: %s: activation error message */
							__( 'Plugin installed successfully, but activation failed: %s', 'shaped-kit' ),
							$act_result->get_error_message()
						),
						array( 'status' => 500 )
					);
				}
				$activated = true;
			}

			return array(
				'status'      => 'installed',
				'slug'        => $slug,
				'version'     => $matched['stable_version'],
				'activated'   => $activated,
				'plugin_file' => $plugin_file,
			);
		} catch ( \Throwable $e ) {
			\WP_Upgrader::release_lock( self::LOCK_NAME );
			return new \WP_Error(
				'install_exception',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Activates an already installed plugin.
	 *
	 * @param string $slug Plugin slug.
	 * @return array|\WP_Error Status payload on success, or WP_Error on failure.
	 */
	public static function activate( $slug ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to activate plugins.', 'shaped-kit' ),
				array( 'status' => 403 )
			);
		}

		$plugin_file = self::find_plugin_file( $slug );
		if ( ! $plugin_file ) {
			return new \WP_Error(
				'plugin_not_installed',
				__( 'The requested plugin is not installed on this site.', 'shaped-kit' ),
				array( 'status' => 404 )
			);
		}

		$result = self::activate_plugin_safely( $plugin_file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'status'      => 'activated',
			'slug'        => $slug,
			'plugin_file' => $plugin_file,
		);
	}

	/**
	 * Checks capability and environment barriers before executing installations (SE01, SE09, SE13).
	 *
	 * @return true|\WP_Error
	 */
	public static function can_install() {
		// 1. Check DISALLOW_FILE_MODS (SE01).
		if ( ! wp_is_file_mod_allowed( 'install_plugins' ) ) {
			return new \WP_Error(
				'file_mods_disabled',
				__( 'File modifications are disabled on this WordPress installation.', 'shaped-kit' ),
				array( 'status' => 403 )
			);
		}

		// 2. Multisite access control (SE13).
		if ( is_multisite() && ! is_network_admin() && ! current_user_can( 'manage_network_plugins' ) ) {
			return new \WP_Error(
				'multisite_subsite_forbidden',
				__( 'Plugin installations must be performed by a Network Administrator.', 'shaped-kit' ),
				array( 'status' => 403 )
			);
		}

		// 3. User capability check.
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to install plugins.', 'shaped-kit' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Downloads package archive to temporary directory and verifies SHA-256 before extraction (SE05).
	 *
	 * @param string $package_url  URL of archive to download.
	 * @param string $expected_sha Expected SHA-256 hex string.
	 * @return string|\WP_Error Path to downloaded temp file on success, or WP_Error.
	 */
	public static function download_and_verify( $package_url, $expected_sha ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$temp_file = download_url( $package_url, 300 );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$checksum_check = PackageVerifier::verify_checksum( $temp_file, $expected_sha );
		if ( is_wp_error( $checksum_check ) ) {
			return $checksum_check;
		}

		return $temp_file;
	}

	/**
	 * Installs a package file that was already downloaded and verified, and says whether it worked.
	 *
	 * The upgrader's output is discarded so it cannot reach the REST response (SE16). Core's skin can leave an output
	 * buffer open when it refuses a package, so the buffer stack is put back as it was found, even if the upgrader throws.
	 * The upgrader connects to the filesystem itself; a host that wants credentials is reported by `install_error()` (SE09).
	 *
	 * @param string $temp_file Local path of the verified zip.
	 * @param bool   $overwrite Whether the package may replace the folder it unpacks to. Core clears that folder first
	 *                          (class-wp-upgrader.php:646-650), so pass true only for a product that is installed (SE07).
	 * @param string $slug      Plugin slug, for the message.
	 * @return \WP_Error|null The refusal, or null when the plugin was installed.
	 */
	public static function install_package_file( $temp_file, $overwrite, $slug ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

		$level = ob_get_level();
		ob_start();

		try {
			$skin      = new \WP_Ajax_Upgrader_Skin();
			$upgrader  = new \Plugin_Upgrader( $skin );
			$installed = $upgrader->install( $temp_file, array( 'overwrite_package' => (bool) $overwrite ) );
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}

		return self::install_error( $installed, $skin, $slug );
	}

	/**
	 * Turns what `Plugin_Upgrader::install()` and its skin report into one verdict (SE07, SE09).
	 *
	 * `install()` returns true only when the package was installed. When core refuses it (a folder that is already there,
	 * a package it cannot unpack) the reason is on the skin and the return value is the upgrader's empty default, so
	 * checking `is_wp_error()` on the return value alone calls a refusal a success. Core's own AJAX installer checks the
	 * skin as well (`wp_ajax_install_plugin()`, ajax-actions.php:4549-4574); this follows it.
	 *
	 * @param mixed                  $installed Return value of `install()`.
	 * @param \WP_Ajax_Upgrader_Skin $skin      The skin the upgrader ran with.
	 * @param string                 $slug      Plugin slug, for the message.
	 * @return \WP_Error|null The refusal, or null when the plugin was installed.
	 */
	private static function install_error( $installed, $skin, $slug ) {
		global $wp_filesystem;

		$errors = is_wp_error( $installed ) ? $installed : null;
		if ( ! $errors && is_wp_error( $skin->result ) ) {
			$errors = $skin->result;
		}
		if ( ! $errors && $skin->get_errors()->has_errors() ) {
			$errors = $skin->get_errors();
		}

		if ( $errors ) {
			// Core's `folder_exists` carries the server path as its data; say the same thing without it.
			if ( in_array( 'folder_exists', $errors->get_error_codes(), true ) ) {
				return new \WP_Error(
					'destination_exists',
					sprintf(
						/* translators: %s: plugin folder name */
						__( 'A folder named "%s" already exists in the plugins folder, so it was not replaced. Remove or rename it, then try again.', 'shaped-kit' ),
						$slug
					),
					array( 'status' => 409 )
				);
			}

			return new \WP_Error( 'install_failed', $errors->get_error_message(), array( 'status' => 500 ) );
		}

		if ( true === $installed ) {
			return null;
		}

		// Nothing was reported, so the upgrader never started: a filesystem that wants FTP or SSH credentials.
		if ( ! ( $wp_filesystem instanceof \WP_Filesystem_Base ) ) {
			return new \WP_Error(
				'filesystem_unavailable',
				__( 'WordPress cannot write to the plugins folder from here because the server asks for FTP or SSH credentials. Install the plugin from the Plugins screen, or upload it by hand.', 'shaped-kit' ),
				array( 'status' => 400 )
			);
		}

		return new \WP_Error( 'install_failed', __( 'Installation failed.', 'shaped-kit' ), array( 'status' => 500 ) );
	}

	/**
	 * Finds the plugin file relative to WP_PLUGIN_DIR from a slug.
	 *
	 * @param string $slug Plugin directory slug.
	 * @return string|null Plugin basename (e.g. 'location-weather/location-weather.php') or null.
	 */
	public static function find_plugin_file( $slug ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();

		foreach ( array_keys( $plugins ) as $plugin_file ) {
			if ( str_starts_with( $plugin_file, $slug . '/' ) || $plugin_file === $slug . '.php' ) {
				return $plugin_file;
			}
		}

		// Fallback direct check if get_plugins() cache was not refreshed.
		$direct = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';
		if ( file_exists( $direct ) ) {
			return $slug . '/' . $slug . '.php';
		}

		return null;
	}

	/**
	 * Safely activates a plugin while catching fatal errors (SE10).
	 *
	 * @param string $plugin_file Relative plugin path.
	 * @return true|\WP_Error
	 */
	public static function activate_plugin_safely( $plugin_file ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Catch output during activation.
		ob_start();
		$result = activate_plugin( $plugin_file, '', false, false );
		ob_end_clean();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
