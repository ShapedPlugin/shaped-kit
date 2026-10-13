<?php
/**
 * Catalog Client for fetching, verifying, and caching the remote ShapedPlugin product catalog (Phase S1).
 *
 * Implements:
 * - Remote fetching over HTTPS from api.shapedplugin.com with 15s timeout.
 * - Detached Ed25519 signature verification (SE03).
 * - Monotonic serial enforcement (SE02) and 24h clock skew tolerance (SE17).
 * - 12h persistent cache (`shaped_kit_catalog_cache`) with 6h throttle on error.
 * - Immutable bundled fallback (`CatalogData::get_bundled_catalog()`) when remote is unreachable or invalid (SE04).
 *
 * Source citations:
 * - wp_remote_get(): wp-includes/http.php:178
 * - wp_remote_retrieve_response_code(): wp-includes/http.php:387
 * - wp_remote_retrieve_body(): wp-includes/http.php:403
 * - get_transient(): wp-includes/option.php:1431
 * - set_transient(): wp-includes/option.php:1509
 * - get_option(): wp-includes/option.php:86
 * - update_option(): wp-includes/option.php:487
 *
 * @package ShapedKit
 */

namespace ShapedKit\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Class CatalogClient
 */
class CatalogClient {

	/**
	 * Remote catalog endpoint URL.
	 *
	 * @var string
	 */
	const REMOTE_CATALOG_URL = 'https://api.shapedplugin.com/kit/v1/catalog.json';

	/**
	 * Remote detached signature URL.
	 *
	 * @var string
	 */
	const REMOTE_SIGNATURE_URL = 'https://api.shapedplugin.com/kit/v1/catalog.json.sig';

	/**
	 * Option key storing last successfully verified monotonic serial.
	 *
	 * @var string
	 */
	const OPTION_LAST_SERIAL = 'shaped_kit_catalog_last_serial';

	/**
	 * Transient key for caching verified catalog data (12 hours).
	 *
	 * @var string
	 */
	const TRANSIENT_CATALOG_CACHE = 'shaped_kit_catalog_cache';

	/**
	 * Transient key for throttling failed network attempts (1 hour).
	 *
	 * @var string
	 */
	const TRANSIENT_THROTTLE = 'shaped_kit_catalog_throttle';

	/**
	 * Cache TTL in seconds (12 hours).
	 *
	 * @var int
	 */
	const CACHE_TTL = 43200;

	/**
	 * Error throttle TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const THROTTLE_TTL = 3600;

	/**
	 * Retrieves the verified catalog, falling back to cache or bundled data.
	 *
	 * @param bool $force_refresh Whether to bypass transient cache and fetch remote immediately.
	 * @return array Normalized, validated catalog data.
	 */
	public static function get_catalog( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT_CATALOG_CACHE );
			if ( is_array( $cached ) && ! empty( $cached['products'] ) ) {
				return $cached;
			}
		}

		// Check if remote request is throttled due to recent failure.
		if ( ! $force_refresh && false !== get_transient( self::TRANSIENT_THROTTLE ) ) {
			return self::get_fallback_catalog();
		}

		$remote = self::fetch_remote_catalog();
		if ( is_array( $remote ) && ! empty( $remote['products'] ) ) {
			set_transient( self::TRANSIENT_CATALOG_CACHE, $remote, self::CACHE_TTL );
			return $remote;
		}

		// Set throttle on failed remote attempt.
		set_transient( self::TRANSIENT_THROTTLE, time(), self::THROTTLE_TTL );

		return self::get_fallback_catalog();
	}

	/**
	 * Fetches and verifies remote catalog and its detached signature.
	 *
	 * @return array|null Validated catalog array or null on any verification failure.
	 */
	public static function fetch_remote_catalog() {
		// If ext-sodium is missing, refuse remote execution and fallback safely (SE04).
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return null;
		}

		$catalog_response = wp_remote_get(
			self::REMOTE_CATALOG_URL,
			array(
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $catalog_response ) || 200 !== (int) wp_remote_retrieve_response_code( $catalog_response ) ) {
			return null;
		}

		$catalog_body = wp_remote_retrieve_body( $catalog_response );
		if ( empty( $catalog_body ) ) {
			return null;
		}

		$sig_response = wp_remote_get(
			self::REMOTE_SIGNATURE_URL,
			array(
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $sig_response ) || 200 !== (int) wp_remote_retrieve_response_code( $sig_response ) ) {
			return null;
		}

		$sig_body = trim( (string) wp_remote_retrieve_body( $sig_response ) );
		if ( empty( $sig_body ) ) {
			return null;
		}

		// Verify Ed25519 signature against pinned primary key or rotation key.
		$is_valid = self::verify_signature( $catalog_body, $sig_body, CatalogData::PUBLIC_KEY_BASE64 );
		if ( ! $is_valid && defined( 'CatalogData::NEXT_PUBLIC_KEY_BASE64' ) ) {
			$is_valid = self::verify_signature( $catalog_body, $sig_body, CatalogData::NEXT_PUBLIC_KEY_BASE64 );
		}

		if ( ! $is_valid ) {
			return null;
		}

		$parsed = json_decode( $catalog_body, true );
		if ( ! is_array( $parsed ) ) {
			return null;
		}

		$validated = CatalogData::validate_catalog( $parsed );
		if ( null === $validated ) {
			return null;
		}

		// Monotonic serial check: reject replay attacks with lower serials (SE02).
		$last_serial = (int) get_option( self::OPTION_LAST_SERIAL, 0 );
		if ( $validated['serial'] < $last_serial ) {
			return null;
		}

		// Check expiration with 24-hour clock skew tolerance (SE17).
		$expires_ts = strtotime( $validated['expires_at'] );
		if ( false === $expires_ts || ( $expires_ts + DAY_IN_SECONDS ) < time() ) {
			return null;
		}

		// Update stored monotonic serial.
		if ( $validated['serial'] > $last_serial ) {
			update_option( self::OPTION_LAST_SERIAL, $validated['serial'], false );
		}

		return $validated;
	}

	/**
	 * Verifies detached Ed25519 signature using PHP ext-sodium.
	 *
	 * @param string $payload    Catalog JSON payload string.
	 * @param string $signature  Base64-encoded signature.
	 * @param string $public_key Base64-encoded public key.
	 * @return bool True if signature is cryptographically valid.
	 */
	public static function verify_signature( $payload, $signature, $public_key ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes Ed25519 signature and public key bytes.
		$sig_bytes = base64_decode( trim( $signature ), true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes Ed25519 public key bytes.
		$pk_bytes = base64_decode( trim( $public_key ), true );

		if ( false === $sig_bytes || strlen( $sig_bytes ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
			return false;
		}

		if ( false === $pk_bytes || strlen( $pk_bytes ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
			return false;
		}

		return sodium_crypto_sign_verify_detached( $sig_bytes, $payload, $pk_bytes );
	}

	/**
	 * Returns the bundled fallback catalog.
	 *
	 * @return array Validated bundled catalog.
	 */
	public static function get_fallback_catalog() {
		return CatalogData::get_bundled_catalog();
	}
}
