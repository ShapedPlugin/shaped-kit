<?php
/**
 * Package integrity and host verifier for Shaped Kit Store Track (Phases S0–S1).
 *
 * Implements:
 * - Host allowlisting (SE06) to prevent SSRF and rogue package injection.
 * - Pre-unpack SHA-256 checksum verification (SE05).
 * - Safe file cleanup on checksum failure.
 *
 * Source citations:
 * - wp_parse_url(): wp-includes/http.php:549
 * - hash_file(): PHP standard library
 * - unlink(): PHP standard library
 *
 * @package ShapedKit
 */

namespace ShapedKit\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Class PackageVerifier
 */
class PackageVerifier {

	/**
	 * Allowlisted download domains permitted to serve plugin archives.
	 *
	 * @var string[]
	 */
	const ALLOWED_HOSTS = array(
		'downloads.wordpress.org',
		'account.shapedplugin.com',
		'api.shapedplugin.com',
	);

	/**
	 * Validates whether a download URL originates from an allowlisted host.
	 *
	 * @param string $url Target download URL.
	 * @return bool True if host is permitted over HTTPS.
	 */
	public static function is_allowed_host( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return false;
		}

		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) ) {
			return false;
		}

		// Must use HTTPS scheme.
		if ( empty( $parsed['scheme'] ) || 'https' !== strtolower( $parsed['scheme'] ) ) {
			return false;
		}

		if ( empty( $parsed['host'] ) ) {
			return false;
		}

		$host = strtolower( $parsed['host'] );
		return in_array( $host, self::ALLOWED_HOSTS, true );
	}

	/**
	 * Verifies the SHA-256 checksum of a downloaded file against an expected catalog hash.
	 *
	 * If the checksum fails, the corrupt file is unlinked immediately to prevent execution (SE05).
	 *
	 * @param string $file_path     Full path to local downloaded file.
	 * @param string $expected_hash Expected 64-character lowercase SHA-256 hex string.
	 * @return true|\WP_Error True on success, or WP_Error with 422 status on failure.
	 */
	public static function verify_checksum( $file_path, $expected_hash ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new \WP_Error(
				'package_file_missing',
				__( 'Downloaded package file could not be read.', 'shaped-kit' ),
				array( 'status' => 500 )
			);
		}

		$actual_hash = hash_file( 'sha256', $file_path );
		if ( false === $actual_hash ) {
			return new \WP_Error(
				'checksum_calculation_failed',
				__( 'Failed to calculate checksum for downloaded package.', 'shaped-kit' ),
				array( 'status' => 500 )
			);
		}

		$expected_normalized = strtolower( trim( $expected_hash ) );
		$actual_normalized   = strtolower( trim( $actual_hash ) );

		if ( ! hash_equals( $expected_normalized, $actual_normalized ) ) {
			// Clean up corrupt/tampered file immediately.
			wp_delete_file( $file_path );

			return new \WP_Error(
				'checksum_mismatch',
				__( 'Package checksum verification failed. The downloaded file may be corrupted or tampered with.', 'shaped-kit' ),
				array(
					'status'   => 422,
					'expected' => $expected_normalized,
					'actual'   => $actual_normalized,
				)
			);
		}

		return true;
	}
}
