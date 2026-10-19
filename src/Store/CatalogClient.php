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
	 * Transient key holding the time the cached catalog was fetched from the remote (same lifetime as the cache).
	 *
	 * @var string
	 */
	const TRANSIENT_FETCHED_AT = 'shaped_kit_catalog_fetched_at';

	/**
	 * Where the catalog returned by the last `get_catalog()` call came from: `remote` (a verified remote catalog,
	 * fresh or cached) or `builtin` (the list bundled in the Kit). Set once per call, so it describes the data the
	 * caller actually received.
	 *
	 * @var string
	 */
	private static $last_source = 'builtin';

	/**
	 * When that remote catalog was fetched (Unix time), or null when unknown or built in.
	 *
	 * @var int|null
	 */
	private static $last_fetched_at = null;

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
				$at                    = get_transient( self::TRANSIENT_FETCHED_AT );
				self::$last_source     = 'remote';
				self::$last_fetched_at = is_numeric( $at ) ? (int) $at : null;
				return $cached;
			}
		}

		// Check if remote request is throttled due to recent failure.
		if ( ! $force_refresh && false !== get_transient( self::TRANSIENT_THROTTLE ) ) {
			return self::use_fallback();
		}

		$remote = self::fetch_remote_catalog();
		if ( is_array( $remote ) && ! empty( $remote['products'] ) ) {
			self::remember_remote( $remote );
			return $remote;
		}

		// Set throttle on failed remote attempt.
		set_transient( self::TRANSIENT_THROTTLE, time(), self::THROTTLE_TTL );

		return self::use_fallback();
	}

	/**
	 * Keeps a verified remote catalog and the time it was fetched.
	 *
	 * @param array $remote Validated remote catalog.
	 * @return void
	 */
	public static function remember_remote( array $remote ) {
		$now = time();
		set_transient( self::TRANSIENT_CATALOG_CACHE, $remote, self::CACHE_TTL );
		set_transient( self::TRANSIENT_FETCHED_AT, $now, self::CACHE_TTL );

		self::$last_source     = 'remote';
		self::$last_fetched_at = $now;
	}

	/**
	 * The built-in list, and a note that this is what the caller got.
	 *
	 * @return array
	 */
	private static function use_fallback() {
		self::$last_source     = 'builtin';
		self::$last_fetched_at = null;

		return self::get_fallback_catalog();
	}

	/**
	 * Where the catalog the last `get_catalog()` call returned came from.
	 *
	 * @return array{source: string, fetched_at: int|null} `remote` or `builtin`, and when a remote one was fetched.
	 */
	public static function sync_state() {
		return array(
			'source'     => self::$last_source,
			'fetched_at' => self::$last_fetched_at,
		);
	}

	/**
	 * Fetches and verifies remote catalog and its detached signature.
	 *
	 * @param string[]|null $keys Base64 public keys to trust. Only tests pass this; the plugin always uses `trusted_public_keys()`.
	 * @return array|null Validated catalog array or null on any verification failure.
	 */
	public static function fetch_remote_catalog( $keys = null ) {
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

		// Verify Ed25519 signature against the pinned key or the rotation key.
		if ( ! self::signature_is_trusted( $catalog_body, $sig_body, $keys ) ) {
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
	 * The public keys a catalog may be signed with: the pinned key, then the rotation key when one is set.
	 *
	 * @return string[] Base64 public keys.
	 */
	public static function trusted_public_keys() {
		return array_values( array_filter( array( CatalogData::PUBLIC_KEY_BASE64, CatalogData::NEXT_PUBLIC_KEY_BASE64 ) ) );
	}

	/**
	 * Whether a signature is valid under any trusted key.
	 *
	 * @param string        $payload   Catalog JSON payload string.
	 * @param string        $signature Base64-encoded signature.
	 * @param string[]|null $keys      Base64 public keys to try. Defaults to `trusted_public_keys()`.
	 * @return bool
	 */
	public static function signature_is_trusted( $payload, $signature, $keys = null ) {
		foreach ( null === $keys ? self::trusted_public_keys() : $keys as $key ) {
			if ( self::verify_signature( $payload, $signature, $key ) ) {
				return true;
			}
		}

		return false;
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
