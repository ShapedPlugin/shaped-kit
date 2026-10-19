<?php
/**
 * Safe Beta Lifecycle Engine for Shaped Kit Store Track (Phase S3).
 *
 * Implements:
 * - One-time opt-in manual beta installation.
 * - Strict No-Downgrade Barrier (SE15): Blocks installation if current installed version >= target beta.
 * - Core Update Isolation (SE14): Guarantees that beta versions are NEVER injected into WordPress's
 *   `update_plugins` transient, preventing automated background update bleeding.
 * - Pre-unpack SHA-256 verification (SE05).
 * - Host allowlisting (SE06).
 * - Mutex locking (SE08).
 * - Telemetry audit logging to {$wpdb->prefix}shaped_kit_mcp_log (FR-S3).
 *
 * Source citations:
 * - version_compare(): PHP standard library
 * - get_plugins(): wp-admin/includes/plugin.php:300
 * - do_action( 'shaped_kit/mcp_tool_called', ... ): PRD FR-S3
 *
 * @package ShapedKit
 */

namespace ShapedKit\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Class BetaInstaller
 */
class BetaInstaller {

	/**
	 * Installs a beta version of a Free or Pro product with strict rollback/downgrade protection.
	 *
	 * @param string      $slug        Product slug (e.g. 'location-weather' or 'location-weather-pro').
	 * @param string|null $license_key Optional license key if installing a Pro beta.
	 * @param bool        $activate    Whether to activate upon installation.
	 * @return array|\WP_Error
	 */
	public static function install_beta( $slug, $license_key = null, $activate = true ) {
		$start_time = microtime( true );

		// Capability and file modification checks (SE01, SE13).
		$permission_check = Installer::can_install();
		if ( is_wp_error( $permission_check ) ) {
			self::log_telemetry( $slug, 'error', $start_time, 'permission_denied' );
			return $permission_check;
		}

		// Ensure catalog contains the product and offers a beta.
		$catalog = CatalogClient::get_catalog();
		$matched = null;
		foreach ( $catalog['products'] as $item ) {
			if ( $item['slug'] === $slug ) {
				$matched = $item;
				break;
			}
		}

		if ( ! $matched || empty( $matched['beta_version'] ) ) {
			self::log_telemetry( $slug, 'error', $start_time, 'no_beta_available' );
			return new \WP_Error(
				'no_beta_available',
				__( 'There is no beta release available for this product in the catalog.', 'shaped-kit' ),
				array( 'status' => 404 )
			);
		}

		$beta_version = $matched['beta_version'];
		$beta_sha256  = $matched['beta_sha256'];

		// Verify No-Downgrade Barrier (SE15). Checked again under the lock (E58b).
		$downgrade = self::downgrade_error( $slug, $beta_version );
		if ( $downgrade ) {
			self::log_telemetry( $slug, 'error', $start_time, 'downgrade_not_allowed' );
			return $downgrade;
		}

		// Resolve beta package URL.
		$package_url = null;
		if ( 'free' === $matched['edition'] ) {
			$package_url = ! empty( $matched['beta_url'] ) ? $matched['beta_url'] : null;
		} else {
			// Pro edition: fetch beta package via EDD with beta: 1 (Q15).
			$version_info = EddBridge::get_version( $matched, (string) $license_key, true );
			if ( is_wp_error( $version_info ) ) {
				self::log_telemetry( $slug, 'error', $start_time, $version_info->get_error_code() );
				return $version_info;
			}
			$package_url = $version_info['package_url'];
		}

		if ( empty( $package_url ) ) {
			self::log_telemetry( $slug, 'error', $start_time, 'beta_url_missing' );
			return new \WP_Error(
				'beta_url_missing',
				__( 'Could not resolve beta package download URL.', 'shaped-kit' ),
				array( 'status' => 500 )
			);
		}

		// Validate host allowlist (SE06).
		if ( ! PackageVerifier::is_allowed_host( $package_url ) ) {
			self::log_telemetry( $slug, 'error', $start_time, 'unauthorized_download_host' );
			return new \WP_Error(
				'unauthorized_download_host',
				__( 'The beta package host is not allowed.', 'shaped-kit' ),
				array( 'status' => 403 )
			);
		}

		// Acquire exclusive lock (SE08).
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( ! \WP_Upgrader::create_lock( Installer::LOCK_NAME, Installer::LOCK_TIMEOUT ) ) {
			self::log_telemetry( $slug, 'error', $start_time, 'install_in_progress' );
			return new \WP_Error(
				'install_in_progress',
				__( 'Another installation or update is in progress. Please wait and try again.', 'shaped-kit' ),
				array( 'status' => 429 )
			);
		}

		try {
			/*
			 * Another admin's install may have finished while this request waited for the lock, so what is installed is
			 * read again, from disk and not from the plugin cache the first check filled (E58b).
			 */
			wp_clean_plugins_cache( false );
			$downgrade = self::downgrade_error( $slug, $beta_version );
			if ( $downgrade ) {
				\WP_Upgrader::release_lock( Installer::LOCK_NAME );
				self::log_telemetry( $slug, 'error', $start_time, 'downgrade_not_allowed' );
				return $downgrade;
			}

			// Download and verify SHA-256 before extraction (SE05).
			$temp_file = Installer::download_and_verify( $package_url, (string) $beta_sha256 );
			if ( is_wp_error( $temp_file ) ) {
				\WP_Upgrader::release_lock( Installer::LOCK_NAME );
				self::log_telemetry( $slug, 'error', $start_time, $temp_file->get_error_code() );
				return $temp_file;
			}

			/*
			 * Core clears the destination folder when the package may overwrite (class-wp-upgrader.php:646-650). That is
			 * right for the product being upgraded and wrong for a folder that only has the same name: another vendor's
			 * plugin, or a half install. So a package overwrites only when this product is installed (E48).
			 */
			$failure = Installer::install_package_file( $temp_file, (bool) Installer::find_plugin_file( $slug ), $slug );

			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}

			\WP_Upgrader::release_lock( Installer::LOCK_NAME );

			if ( $failure ) {
				self::log_telemetry( $slug, 'error', $start_time, $failure->get_error_code() );
				return $failure;
			}

			// Activate plugin if requested.
			$target_file = Installer::find_plugin_file( $slug );
			$activated   = false;

			if ( $activate && $target_file ) {
				$act_result = Installer::activate_plugin_safely( $target_file );
				if ( is_wp_error( $act_result ) ) {
					self::log_telemetry( $slug, 'error', $start_time, 'activation_failed' );
					return new \WP_Error(
						'plugin_activation_failed',
						sprintf(
							/* translators: %s: activation error message */
							__( 'Beta installed successfully, but activation failed: %s', 'shaped-kit' ),
							$act_result->get_error_message()
						),
						array( 'status' => 500 )
					);
				}
				$activated = true;
			}

			self::log_telemetry( $slug, 'ok', $start_time, null );

			return array(
				'status'       => 'installed_beta',
				'slug'         => $slug,
				'beta_version' => $beta_version,
				'activated'    => $activated,
				'plugin_file'  => $target_file,
			);
		} catch ( \Throwable $e ) {
			\WP_Upgrader::release_lock( Installer::LOCK_NAME );
			self::log_telemetry( $slug, 'error', $start_time, 'exception' );
			return new \WP_Error(
				'beta_install_exception',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Refuses a beta that is not newer than what is installed (SE15).
	 *
	 * @param string $slug         Product slug.
	 * @param string $beta_version Version the beta would install.
	 * @return \WP_Error|null `downgrade_not_allowed` when the installed version is the same or newer, otherwise null.
	 */
	private static function downgrade_error( $slug, $beta_version ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$installed_plugins = get_plugins();
		$plugin_file       = Installer::find_plugin_file( $slug );

		if ( ! $plugin_file || ! isset( $installed_plugins[ $plugin_file ]['Version'] ) ) {
			return null;
		}

		$current_version = $installed_plugins[ $plugin_file ]['Version'];

		if ( ! version_compare( $current_version, $beta_version, '>=' ) ) {
			return null;
		}

		return new \WP_Error(
			'downgrade_not_allowed',
			sprintf(
				/* translators: 1: current version, 2: target beta version */
				__( 'Downgrades are not permitted. Installed version (%1$s) is greater than or equal to target beta version (%2$s).', 'shaped-kit' ),
				$current_version,
				$beta_version
			),
			array(
				'status'    => 400,
				'current'   => $current_version,
				'target'    => $beta_version,
				'next_step' => 'Wait for the next stable release to update.',
			)
		);
	}

	/**
	 * Guarantees that beta versions are never injected into core's update_plugins transient (SE14).
	 *
	 * Filter hook: site_transient_update_plugins.
	 *
	 * @param mixed $transient Transient data object.
	 * @return mixed Untainted transient data.
	 */
	public static function filter_update_plugins( $transient ) {
		// Pure pass-through: Shaped Kit deliberately NEVER injects beta versions into update_plugins.
		return $transient;
	}

	/**
	 * Logs beta installation action into {$wpdb->prefix}shaped_kit_mcp_log (FR-S3).
	 *
	 * @param string      $slug       Product slug.
	 * @param string      $outcome    'ok' or 'error'.
	 * @param float       $start_time Execution start timestamp.
	 * @param string|null $error_id   Optional error code.
	 * @return void
	 */
	private static function log_telemetry( $slug, $outcome, $start_time, $error_id = null ) {
		$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		$payload = array(
			'product'       => 'shaped-kit',
			'ability'       => 'shaped-kit/install-beta',
			'outcome'       => $outcome,
			'user_id'       => get_current_user_id(),
			'duration_ms'   => $duration_ms,
			'error_id'      => $error_id,
			'argument_keys' => array( 'slug' ),
		);

		do_action( 'shaped_kit/mcp_tool_called', $payload );
	}
}
