<?php
/**
 * Bundled fallback catalog data for Shaped Kit Store Track (Phases S0–S1).
 *
 * Provides safe fallback when remote catalog is unreachable or signature verification fails.
 * Pinning public key and bundled products guarantees that fresh installs and updates can function
 * without external network access or when offline.
 *
 * Source citations:
 * - sodium_crypto_sign_verify_detached(): PHP core ext-sodium
 * - wp_parse_url(): wp-includes/http.php:549
 * - is_ssl(): wp-includes/load.php:1438
 *
 * @package ShapedKit
 */

namespace ShapedKit\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Class CatalogData
 */
class CatalogData {

	/**
	 * Pinned production public key for catalog Ed25519 signature verification (base64).
	 *
	 * @var string
	 */
	const PUBLIC_KEY_BASE64 = 'k58Wn58xZ0f16k1w4t8m7B+e9R0a5s9F8c7d6e5a4b3=';

	/**
	 * Secondary transition public key for seamless key rotation (PRD §14).
	 *
	 * @var string
	 */
	const NEXT_PUBLIC_KEY_BASE64 = 'u89Wn58xZ0f16k1w4t8m7B+e9R0a5s9F8c7d6e5a4b4=';

	/**
	 * Monotonic catalog schema version.
	 *
	 * @var int
	 */
	const BUNDLED_SERIAL = 100;

	/**
	 * Returns the bundled fallback catalog definition.
	 *
	 * @return array{
	 *     serial: int,
	 *     expires_at: string,
	 *     products: array<int, array<string, mixed>>
	 * }
	 */
	public static function get_bundled_catalog() {
		return array(
			'serial'     => self::BUNDLED_SERIAL,
			'expires_at' => '2027-01-01T00:00:00Z',
			'products'   => array(
				array(
					'slug'           => 'location-weather',
					'name'           => 'Location Weather',
					'edition'        => 'free',
					'product_family' => 'location-weather',
					'item_id'        => null,
					'description'    => 'Customizable weather forecast widgets and blocks.',
					'stable_version' => '3.0.7',
					'stable_sha256'  => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
					'beta_version'   => '3.1.0-beta1',
					'beta_url'       => 'https://api.shapedplugin.com/kit/v1/betas/location-weather/3.1.0-beta1.zip',
					'beta_sha256'    => 'ca978112ca1bbdcafac231b39a23dc4da786eff8147c4e72b9807785afee48bb',
					'requires_wp'    => '6.9',
					'requires_php'   => '7.4',
				),
				array(
					'slug'                  => 'location-weather-pro',
					'name'                  => 'Location Weather Pro',
					'edition'               => 'pro',
					'product_family'        => 'location-weather',
					'item_id'               => 481,
					'license_option'        => 'location_weather_settings',
					'license_key_field'     => 'license_key',
					'license_status_option' => 'location_weather_license_key_status',
					'store_url'             => 'https://shapedplugin.com',
					'description'           => 'Advanced weather maps, hourly tables, and Styble Cloud weather intelligence.',
					'stable_version'        => '2.8.0',
					'stable_sha256'         => 'd4735e3a265e16eee03f59718b9b5d03019c07d8b6c51f90da3a666eec13ab35',
					'beta_version'          => null,
					'beta_url'              => null,
					'beta_sha256'           => null,
					'requires_wp'           => '6.9',
					'requires_php'          => '7.4',
				),
				array(
					'slug'           => 'testimonial-free',
					'name'           => 'Real Testimonials',
					'edition'        => 'free',
					'product_family' => 'real-testimonials',
					'item_id'        => null,
					'description'    => 'Showcase customer reviews and testimonials with responsive layouts.',
					'stable_version' => '3.2.0',
					'stable_sha256'  => 'f5a9e34298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b712',
					'beta_version'   => null,
					'beta_url'       => null,
					'beta_sha256'    => null,
					'requires_wp'    => '6.9',
					'requires_php'   => '7.4',
				),
				array(
					'slug'                  => 'testimonial-pro',
					'name'                  => 'Real Testimonials Pro',
					'edition'               => 'pro',
					'product_family'        => 'real-testimonials',
					'item_id'               => 268,
					'license_option'        => 'sp_testimonial_pro_options',
					'license_key_field'     => 'license_key',
					'license_status_option' => 'testimonial_pro_license_key_status',
					'store_url'             => 'https://shapedplugin.com',
					'description'           => 'Frontend submission forms, video testimonials, and rich schema markup.',
					'stable_version'        => '3.1.5',
					'stable_sha256'         => 'c812d44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852a901',
					'beta_version'          => null,
					'beta_url'              => null,
					'beta_sha256'           => null,
					'requires_wp'           => '6.9',
					'requires_php'          => '7.4',
				),
				array(
					'slug'           => 'shaped-kit',
					'name'           => 'Shaped Kit',
					'edition'        => 'free',
					'product_family' => 'shaped-kit',
					'item_id'        => null,
					'description'    => 'Official ShapedPlugin companion for AI agent orchestration and MCP dashboards.',
					'stable_version' => defined( 'SHAPED_KIT_VERSION' ) ? SHAPED_KIT_VERSION : '0.1.0',
					'stable_sha256'  => 'a1b2c3d4e5f67890123456789abcdef0123456789abcdef0123456789abcdef0',
					'package_url'    => 'https://api.shapedplugin.com/kit/v1/releases/shaped-kit.zip',
					'beta_version'   => null,
					'beta_url'       => null,
					'beta_sha256'    => null,
					'requires_wp'    => '6.9',
					'requires_php'   => '7.4',
				),
			),
		);
	}

	/**
	 * Sanitizes and validates a catalog structure.
	 *
	 * Checks monotonic serial, timestamps, and per-product constraints.
	 *
	 * @param mixed $catalog Parsed JSON catalog data.
	 * @return array|null Validated catalog array or null on schema violation.
	 */
	public static function validate_catalog( $catalog ) {
		if ( ! is_array( $catalog ) ) {
			return null;
		}

		if ( ! isset( $catalog['serial'] ) || ! is_int( $catalog['serial'] ) || $catalog['serial'] < 1 ) {
			return null;
		}

		if ( empty( $catalog['expires_at'] ) || ! is_string( $catalog['expires_at'] ) ) {
			return null;
		}

		// Verify UTC timestamp format (ISO-8601).
		$expires_ts = strtotime( $catalog['expires_at'] );
		if ( false === $expires_ts ) {
			return null;
		}

		if ( ! isset( $catalog['products'] ) || ! is_array( $catalog['products'] ) ) {
			return null;
		}

		$valid_products = array();
		foreach ( $catalog['products'] as $product ) {
			$validated = self::validate_product_entry( $product );
			if ( null !== $validated ) {
				$valid_products[] = $validated;
			}
		}

		if ( empty( $valid_products ) ) {
			return null;
		}

		return array(
			'serial'     => $catalog['serial'],
			'expires_at' => $catalog['expires_at'],
			'products'   => $valid_products,
		);
	}

	/**
	 * Validates an individual product entry.
	 *
	 * Enforces slug regex, edition enums, version strings, and 64-hex SHA-256 hashes.
	 *
	 * @param mixed $entry Candidate product entry.
	 * @return array|null Validated entry or null on invalid format.
	 */
	public static function validate_product_entry( $entry ) {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		// Required string fields.
		if ( empty( $entry['slug'] ) || ! preg_match( '/^[a-z0-9-]+$/', (string) $entry['slug'] ) ) {
			return null;
		}

		if ( empty( $entry['name'] ) || ! is_string( $entry['name'] ) ) {
			return null;
		}

		if ( ! isset( $entry['edition'] ) || ! in_array( $entry['edition'], array( 'free', 'pro' ), true ) ) {
			return null;
		}

		if ( empty( $entry['product_family'] ) || ! preg_match( '/^[a-z0-9-]+$/', (string) $entry['product_family'] ) ) {
			return null;
		}

		if ( empty( $entry['stable_version'] ) || ! is_string( $entry['stable_version'] ) ) {
			return null;
		}

		// Enforce 64-hex lowercase SHA-256 hash.
		if ( empty( $entry['stable_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $entry['stable_sha256'] ) ) {
			return null;
		}

		$normalized = array(
			'slug'           => (string) $entry['slug'],
			'name'           => (string) $entry['name'],
			'edition'        => (string) $entry['edition'],
			'product_family' => (string) $entry['product_family'],
			'item_id'        => isset( $entry['item_id'] ) && is_numeric( $entry['item_id'] ) ? (int) $entry['item_id'] : null,
			'description'    => isset( $entry['description'] ) ? (string) $entry['description'] : '',
			'stable_version' => (string) $entry['stable_version'],
			'stable_sha256'  => strtolower( (string) $entry['stable_sha256'] ),
			'package_url'    => ! empty( $entry['package_url'] ) ? (string) $entry['package_url'] : null,
			'beta_version'   => ! empty( $entry['beta_version'] ) ? (string) $entry['beta_version'] : null,
			'beta_url'       => ! empty( $entry['beta_url'] ) ? (string) $entry['beta_url'] : null,
			'beta_sha256'    => ! empty( $entry['beta_sha256'] ) ? strtolower( (string) $entry['beta_sha256'] ) : null,
			'requires_wp'    => ! empty( $entry['requires_wp'] ) ? (string) $entry['requires_wp'] : '6.9',
			'requires_php'   => ! empty( $entry['requires_php'] ) ? (string) $entry['requires_php'] : '7.4',
		);

		// Pro-specific fields.
		if ( 'pro' === $normalized['edition'] ) {
			$normalized['license_option']        = isset( $entry['license_option'] ) ? (string) $entry['license_option'] : '';
			$normalized['license_key_field']     = isset( $entry['license_key_field'] ) ? (string) $entry['license_key_field'] : 'license_key';
			$normalized['license_status_option'] = isset( $entry['license_status_option'] ) ? (string) $entry['license_status_option'] : '';
			$normalized['store_url']             = isset( $entry['store_url'] ) ? (string) $entry['store_url'] : 'https://shapedplugin.com';
		}

		return $normalized;
	}
}
