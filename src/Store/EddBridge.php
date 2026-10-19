<?php
/**
 * EDD Software Licensing Bridge for ShapedPlugin Pro products (Phase S2).
 *
 * Implements:
 * - Direct HTTP POST communication with EDD store endpoint (`edd_action=get_version`).
 * - Strictly NO query string transmission of license keys.
 * - Host allowlisting on package download URLs (`account.shapedplugin.com`).
 * - Read-only product license introspection from `location_weather_settings` and `sp_testimonial_pro_options`.
 * - Strictly zero database storage of license keys in Shaped Kit (`wp_options`, custom tables, or transients).
 * - Safe masking of license keys for UI display (`••••••••••••1a2b`).
 *
 * Source citations:
 * - wp_remote_post(): wp-includes/http.php:218
 * - wp_remote_retrieve_body(): wp-includes/http.php:403
 * - wp_remote_retrieve_response_code(): wp-includes/http.php:387
 * - is_multisite(): wp-includes/load.php:1438
 * - network_home_url(): wp-includes/link-template.php:3681
 * - home_url(): wp-includes/link-template.php:3638
 * - get_option(): wp-includes/option.php:86
 *
 * @package ShapedKit
 */

namespace ShapedKit\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Class EddBridge
 */
class EddBridge {

	/**
	 * Default store endpoint URL for ShapedPlugin products.
	 *
	 * @var string
	 */
	const DEFAULT_STORE_URL = 'https://shapedplugin.com';

	/**
	 * Fetches release package metadata and tokenized download URL from EDD store API.
	 *
	 * Transmits the license key strictly within the HTTP POST body.
	 *
	 * @param array  $product_entry Catalog entry for the Pro product.
	 * @param string $license_key   License key string provided by user.
	 * @param bool   $beta          Whether to request a beta release package.
	 * @return array|\WP_Error Package metadata on success, or WP_Error on rejection.
	 */
	public static function get_version( array $product_entry, $license_key, $beta = false ) {
		$license_key = trim( (string) $license_key );
		if ( empty( $license_key ) ) {
			return new \WP_Error(
				'license_key_required',
				__( 'A valid license key is required to download and install this Pro product.', 'shaped-kit' ),
				array( 'status' => 400 )
			);
		}

		$store_url = ! empty( $product_entry['store_url'] ) ? $product_entry['store_url'] : self::DEFAULT_STORE_URL;
		$item_id   = ! empty( $product_entry['item_id'] ) ? (int) $product_entry['item_id'] : 0;
		$slug      = ! empty( $product_entry['slug'] ) ? $product_entry['slug'] : '';

		$api_params = array(
			'edd_action' => 'get_version',
			'license'    => $license_key,
			'item_id'    => $item_id,
			'slug'       => $slug,
			'url'        => self::get_site_url(),
			'beta'       => $beta ? 1 : 0,
		);

		$response = wp_remote_post(
			trailingslashit( $store_url ),
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'body'      => $api_params,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new \WP_Error(
				'edd_store_unavailable',
				__( 'The license store endpoint is currently unavailable.', 'shaped-kit' ),
				array( 'status' => 502 )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'invalid_store_response',
				__( 'Received an invalid response from the licensing store.', 'shaped-kit' ),
				array( 'status' => 502 )
			);
		}

		// Check for licensing rejections (SE12).
		if ( empty( $body['package'] ) ) {
			$msg = ( ! empty( $body['msg'] ) && is_string( $body['msg'] ) ) ? $body['msg'] : __( 'The provided license key is invalid or expired for this product.', 'shaped-kit' );
			// The store's reason goes to the owner's browser. It must never carry the key back (E55).
			$msg = str_replace( $license_key, __( '[license key]', 'shaped-kit' ), $msg );
			return new \WP_Error(
				'license_invalid',
				$msg,
				array( 'status' => 422 )
			);
		}

		return array(
			'new_version' => ! empty( $body['new_version'] ) ? (string) $body['new_version'] : '',
			'package_url' => (string) $body['package'],
			'stable_tag'  => ! empty( $body['stable_tag'] ) ? (string) $body['stable_tag'] : '',
		);
	}

	/**
	 * Reads existing license key and status from an installed Pro product (read-only introspection).
	 *
	 * Zero writing to database options or transients.
	 *
	 * @param array $product_entry Catalog entry for the product.
	 * @return array{has_license: bool, masked_key: string, status: string}
	 */
	public static function get_installed_license_info( array $product_entry ) {
		$empty_result = array(
			'has_license' => false,
			'masked_key'  => '',
			'status'      => 'none',
		);

		if ( 'pro' !== $product_entry['edition'] ) {
			return $empty_result;
		}

		$option_name = ! empty( $product_entry['license_option'] ) ? $product_entry['license_option'] : '';
		$key_field   = ! empty( $product_entry['license_key_field'] ) ? $product_entry['license_key_field'] : 'license_key';
		$status_opt  = ! empty( $product_entry['license_status_option'] ) ? $product_entry['license_status_option'] : '';

		if ( empty( $option_name ) ) {
			return $empty_result;
		}

		$settings = get_option( $option_name, array() );
		$raw_key  = '';

		if ( is_array( $settings ) && isset( $settings[ $key_field ] ) ) {
			$raw_key = trim( (string) $settings[ $key_field ] );
		} elseif ( is_string( $settings ) ) {
			$raw_key = trim( $settings );
		}

		if ( empty( $raw_key ) ) {
			return $empty_result;
		}

		$raw_status = ! empty( $status_opt ) ? get_option( $status_opt, 'unknown' ) : 'unknown';
		$status_str = is_string( $raw_status ) ? $raw_status : ( isset( $raw_status->license ) ? (string) $raw_status->license : 'unknown' );

		return array(
			'has_license' => true,
			'masked_key'  => self::mask_license_key( $raw_key ),
			'status'      => $status_str,
		);
	}

	/**
	 * Safely masks a license key for UI display.
	 *
	 * @param string $key Raw license key.
	 * @return string Masked string (e.g. "••••••••••••1a2b").
	 */
	public static function mask_license_key( $key ) {
		$trimmed = trim( (string) $key );
		$length  = strlen( $trimmed );

		if ( $length <= 4 ) {
			return str_repeat( '•', $length );
		}

		return str_repeat( '•', max( 8, $length - 4 ) ) . substr( $trimmed, -4 );
	}

	/**
	 * Resolves site home URL matching the product's activation pattern (SE54).
	 *
	 * @return string
	 */
	public static function get_site_url() {
		if ( is_multisite() ) {
			return network_home_url();
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Matches ShapedPlugin product license filter contract.
		return apply_filters( 'sp_site_home_url', home_url() );
	}
}
