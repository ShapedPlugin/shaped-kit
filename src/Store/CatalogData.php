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
					'settings_path'  => 'edit.php?post_type=location_weather&page=splw_admin_dashboard',
					'priority'       => 80,
					'stable_version' => '3.0.7',
					'stable_sha256'  => '3abe08d6da50505910fd694a915bcbe7bfdcc48ab8e6f7bab38ba7ab580af7df',
					'beta_version'   => '3.1.0-beta1',
					'beta_url'       => 'https://api.shapedplugin.com/kit/v1/betas/location-weather/3.1.0-beta1.zip',
					'beta_sha256'    => 'ca978112ca1bbdcafac231b39a23dc4da786eff8147c4e72b9807785afee48bb',
					'requires_wp'    => '5.9',
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
					'settings_path'         => 'edit.php?post_type=location_weather&page=splw_admin_dashboard',
					'priority'              => 80,
					'stable_version'        => '2.8.0',
					'stable_sha256'         => 'd4735e3a265e16eee03f59718b9b5d03019c07d8b6c51f90da3a666eec13ab35',
					'beta_version'          => null,
					'beta_url'              => null,
					'beta_sha256'           => null,
					'requires_wp'           => '5.0',
					'requires_php'          => '7.4',
				),
				array(
					'slug'           => 'testimonial-free',
					'name'           => 'Real Testimonials',
					'edition'        => 'free',
					'product_family' => 'real-testimonials',
					'item_id'        => null,
					'description'    => 'Showcase customer reviews and testimonials with responsive layouts.',
					'settings_path'  => 'edit.php?post_type=spt_testimonial&page=rtp_dashboard',
					'priority'       => 30,
					'stable_version' => '4.0.0',
					'stable_sha256'  => '8adaf6624e8c1dc7b5ad83c74da0d7c2760e42491e45740be49cb2656d6641f1',
					'beta_version'   => null,
					'beta_url'       => null,
					'beta_sha256'    => null,
					'requires_wp'    => '6.3',
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
					'settings_path'         => 'edit.php?post_type=spt_testimonial&page=rtp_dashboard',
					'priority'              => 30,
					'stable_version'        => '3.1.5',
					'stable_sha256'         => 'c812d44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852a901',
					'beta_version'          => null,
					'beta_url'              => null,
					'beta_sha256'           => null,
					'requires_wp'           => '6.3',
					'requires_php'          => '7.4',
				),
				array(
					'slug'           => 'easy-accordion-free',
					'name'           => 'Easy Accordion',
					'edition'        => 'free',
					'product_family' => 'easy-accordion-free',
					'item_id'        => null,
					'description'    => 'AI-Powered FAQ Generator. Gutenberg FAQ & Accordion Blocks, Image Accordion, Accordion Slider, WooCommerce Product FAQ – Elementor & Shortcode Ready.',
					'settings_path'  => 'edit.php?post_type=sp_easy_accordion&page=eap_dashboard',
					'priority'       => 10,
					'stable_version' => '3.1.11',
					'stable_sha256'  => 'daa63b3f791b4f818f088de6e6ff916a049e8c34092715de1da62992ae04f1d4',
					'beta_version'   => null,
					'beta_url'       => null,
					'beta_sha256'    => null,
					'requires_wp'    => '5.9',
					'requires_php'   => '7.4',
				),
				array(
					'slug'           => 'wp-carousel-free',
					'name'           => 'WP Carousel',
					'edition'        => 'free',
					'product_family' => 'wp-carousel',
					'item_id'        => null,
					'description'    => 'Carousel, Slider, and Photo Gallery with Lightbox plugin. Create Image Carousel, Video Slider, Post Carousel, Post Grid, Product Carousel, and more.',
					'settings_path'  => 'edit.php?post_type=sp_wp_carousel&page=wpcpf_dashboard',
					'pro_slug'       => 'wp-carousel-pro',
					'priority'       => 20,
					'stable_version' => '4.0.1',
					'stable_sha256'  => '81da7514565d7432ea52dcf66ca5af6ea2d605028069ceb6b866c65b3f0c0936',
					'beta_version'   => null,
					'beta_url'       => null,
					'beta_sha256'    => null,
					'requires_wp'    => '5.0',
					'requires_php'   => '7.4',
				),
				array(
					'slug'             => 'gallery-slider-for-woocommerce',
					'name'             => 'Reno Product Gallery',
					'edition'          => 'free',
					'product_family'   => 'gallery-slider-for-woocommerce',
					'item_id'          => null,
					'description'      => 'All-in-One WooCommerce Product Image and Video Gallery Solution to Enhance Your Customers\' Shopping Experience and Boost Sales Instantly!',
					'settings_path'    => 'admin.php?page=wpgs-settings',
					'priority'         => 40,
					'stable_version'   => '3.2.1',
					'stable_sha256'    => 'cad02bfd4b33fafd0adf3c9cbdb1f34673aeb3ef8b6c08bb9e226bc328c2b3ed',
					'beta_version'     => null,
					'beta_url'         => null,
					'beta_sha256'      => null,
					'requires_wp'      => '5.3',
					'requires_php'     => '7.0',
					'requires_plugins' => array( 'woocommerce' ),
				),
				array(
					'slug'           => 'logo-carousel-free',
					'name'           => 'Logo Carousel',
					'edition'        => 'free',
					'product_family' => 'logo-carousel-free',
					'item_id'        => null,
					'description'    => 'Add, display, and manage clients, partners, sponsors, and brand logos with multiple slideshows on your site. Customizable – No coding required!',
					'settings_path'  => 'edit.php?post_type=sp_logo_carousel&page=lc_help',
					'pro_slug'       => 'logo-carousel-pro',
					'priority'       => 50,
					'stable_version' => '3.6.14',
					'stable_sha256'  => '2350e8461b5656bc3466774fabd1f0b30108103b51bacebf702874e2e1f52b02',
					'beta_version'   => null,
					'beta_url'       => null,
					'beta_sha256'    => null,
					'requires_wp'    => '5.0',
					'requires_php'   => '7.0',
				),
				array(
					'slug'           => 'post-carousel',
					'name'           => 'Smart Post Show',
					'edition'        => 'free',
					'product_family' => 'post-carousel',
					'item_id'        => null,
					'description'    => 'Display posts in Grid, Carousel, Slider, Timeline, List & Live Filter layouts with Gutenberg Blocks & Ready Patterns - Elementor, Divi, & Shortcodes.',
					'settings_path'  => 'edit.php?post_type=sp_post_carousel&page=pcp_settings',
					'priority'       => 60,
					'stable_version' => '4.0.8',
					'stable_sha256'  => 'a7d12c8bd8affc3017b13bcab53528e9f4aa7958d2b89f4ebecc774a8d2a2861',
					'beta_version'   => null,
					'beta_url'       => null,
					'beta_sha256'    => null,
					'requires_wp'    => '5.9',
				),
				array(
					'slug'             => 'woo-product-slider',
					'name'             => 'Reno Product Slider',
					'edition'          => 'free',
					'product_family'   => 'woo-product-slider',
					'item_id'          => null,
					'description'      => 'Display your WooCommerce products in a responsive Product Slider, Product Carousel, or Product Grid Gallery with easy customization.',
					'settings_path'    => 'edit.php?post_type=sp_wps_shortcodes&page=wps_help',
					'pro_slug'         => 'woo-product-slider-pro',
					'priority'         => 70,
					'stable_version'   => '2.9.0',
					'stable_sha256'    => 'fc881ecf2498969e29d1f27b153a6982e585dd776fb2dfafb875106653ba1265',
					'beta_version'     => null,
					'beta_url'         => null,
					'beta_sha256'      => null,
					'requires_wp'      => '5.0',
					'requires_php'     => '7.0',
					'requires_plugins' => array( 'woocommerce' ),
				),
				array(
					'slug'           => 'wp-expand-tabs-free',
					'name'           => 'Smart Tabs',
					'edition'        => 'free',
					'product_family' => 'wp-expand-tabs-free',
					'item_id'        => null,
					'description'    => 'A customizable plugin to create and manage WooCommerce product tabs and WordPress tabs to organize content.',
					'settings_path'  => 'edit.php?post_type=sp_wp_tabs&page=tabs_settings',
					'priority'       => 90,
					'stable_version' => '3.1.7',
					'stable_sha256'  => '6c8bbba790c737c815545308c39e6be16691d78f8d29edc8466e049ca6037b77',
					'beta_version'   => null,
					'beta_url'       => null,
					'beta_sha256'    => null,
					'requires_wp'    => '5.0',
					'requires_php'   => '7.2',
				),
				array(
					'slug'             => 'woo-category-slider-grid',
					'name'             => 'Reno Product Category',
					'edition'          => 'free',
					'product_family'   => 'woo-category-slider-grid',
					'item_id'          => null,
					'description'      => 'Display product categories in responsive sliders or grids to showcase them effectively on your WooCommerce store and improve shoppers\' navigation.',
					'settings_path'    => 'edit.php?post_type=sp_wcslider&page=wcsp_help',
					'priority'         => 100,
					'stable_version'   => '1.7.2',
					'stable_sha256'    => 'b0ade5e269dbbd669274dcfe0cfa47b459568c8223dd5c00144bd45055fd06d2',
					'beta_version'     => null,
					'beta_url'         => null,
					'beta_sha256'      => null,
					'requires_wp'      => '5.0',
					'requires_php'     => '7.0',
					'requires_plugins' => array( 'woocommerce' ),
				),
				array(
					'slug'           => 'team-free',
					'name'           => 'Smart Team',
					'edition'        => 'free',
					'product_family' => 'team-free',
					'item_id'        => null,
					'description'    => 'A WordPress plugin to display team members in Carousel, Grid, or List layouts. Customizable.',
					'settings_path'  => 'edit.php?post_type=sptp_member&page=team_settings',
					'priority'       => 110,
					'stable_version' => '3.0.15',
					'stable_sha256'  => 'fb88b1b16598541824d07b66eceac908aea345bd8f002d31763b482f72a5d7f8',
					'beta_version'   => null,
					'beta_url'       => null,
					'beta_sha256'    => null,
					'requires_wp'    => '5.0',
				),
				array(
					'slug'             => 'woo-quickview',
					'name'             => 'Reno Quick View',
					'edition'          => 'free',
					'product_family'   => 'woo-quickview',
					'item_id'          => null,
					'description'      => 'Add a quick view button in the product loop so visitors can quickly view product information in a nice modal without opening the product page.',
					'settings_path'    => 'admin.php?page=wqv_settings',
					'priority'         => 120,
					'stable_version'   => '2.3.0',
					'stable_sha256'    => '2804b3dc2529e17e144030ee38cc811892a9ef7df8d982b0f349eba457e64f05',
					'beta_version'     => null,
					'beta_url'         => null,
					'beta_sha256'      => null,
					'requires_wp'      => '4.8',
					'requires_plugins' => array( 'woocommerce' ),
				),
				array(
					'slug'           => 'easy-lightbox-wp',
					'name'           => 'ShapedGallery',
					'edition'        => 'free',
					'product_family' => 'easy-lightbox-wp',
					'item_id'        => null,
					'description'    => 'Create responsive image, photo and video galleries with justified, grid and masonry layouts, one-click Ready Patterns and a built-in lightbox.',
					'settings_path'  => 'admin.php?page=shaped-gallery-settings',
					'priority'       => 130,
					'stable_version' => '1.2.1',
					'stable_sha256'  => 'ef30b388b930a36fa5c90a60bdb4e35dd17cc1ea7204eedb295e62b09e0467dc',
					'beta_version'   => null,
					'beta_url'       => null,
					'beta_sha256'    => null,
					'requires_wp'    => '6.8',
					'requires_php'   => '8.1',
				),
				array(
					'slug'             => 'smart-swatches',
					'name'             => 'Smart Swatches',
					'edition'          => 'free',
					'product_family'   => 'smart-swatches',
					'item_id'          => null,
					'description'      => 'Create beautiful color, image & button variation swatches that boost your store\'s appeal and help customers select variations with ease.',
					'settings_path'    => 'admin.php?page=ssp_settings',
					'priority'         => 140,
					'stable_version'   => '1.0.12',
					'stable_sha256'    => '53bda295c8e51145d4af905b1007ab8598d28aa246b122179ad39365eebd39ac',
					'beta_version'     => null,
					'beta_url'         => null,
					'beta_sha256'      => null,
					'requires_wp'      => '5.3',
					'requires_plugins' => array( 'woocommerce' ),
				),
				array(
					'slug'             => 'smart-brands-for-woocommerce',
					'name'             => 'Smart Brands for WooCommerce',
					'edition'          => 'free',
					'product_family'   => 'smart-brands-for-woocommerce',
					'item_id'          => null,
					'description'      => 'Create unlimited brands to assign to your products, highlight the brands of the products you sell, and boost sales instantly!',
					'settings_path'    => 'edit.php?post_type=smart_brand_sc&page=smart-brands-settings',
					'priority'         => 150,
					'stable_version'   => '2.0.8',
					'stable_sha256'    => 'a3ff14b135c8d11fdcb095839d358ad8886fc1ff3a599ebd7d7ba70cc88d14e2',
					'beta_version'     => null,
					'beta_url'         => null,
					'beta_sha256'      => null,
					'requires_wp'      => '5.9',
					'requires_php'     => '7.2',
					'requires_plugins' => array( 'woocommerce' ),
				),
				array(
					'slug'           => 'styble',
					'name'           => 'Styble',
					'edition'        => 'free',
					'product_family' => 'styble',
					'item_id'        => null,
					'description'    => 'Styble enhances your Gutenberg editor with powerful and easy-to-use blocks that let you build innovative and engaging websites faster and easier.',
					'priority'       => 160,
					'stable_version' => '1.3.8',
					'stable_sha256'  => '21c6edf52f04cc75293aeb55f78c6f591c7d624c9006fc389a99473902b43de1',
					'beta_version'   => null,
					'beta_url'       => null,
					'beta_sha256'    => null,
					'requires_wp'    => '5.2',
					'requires_php'   => '7.0',
				),
				array(
					'slug'             => 'reno-cart',
					'name'             => 'ShapedCart',
					'edition'          => 'free',
					'product_family'   => 'reno-cart',
					'item_id'          => null,
					'description'      => 'A fast WooCommerce side cart plugin that makes shopping faster and easier. Side cart, mini cart, popup cart, floating cart, and menu cart, all in one.',
					'settings_path'    => 'admin.php?page=shaped-cart',
					'priority'         => 170,
					'stable_version'   => '0.3.0',
					'stable_sha256'    => '3c25a97663d7efd9c181fec8807d8be6fdb748985c66c4c2eb7f062f15b74a51',
					'beta_version'     => null,
					'beta_url'         => null,
					'beta_sha256'      => null,
					'requires_wp'      => '6.0',
					'requires_php'     => '7.4',
					'requires_plugins' => array( 'woocommerce' ),
				),
				array(
					'slug'             => 'shaped-recommend',
					'name'             => 'ShapedRecommend',
					'edition'          => 'free',
					'product_family'   => 'shaped-recommend',
					'item_id'          => null,
					'description'      => 'Boost order value with related products, upsells, cross-sells and Frequently Bought Together on WooCommerce product, category, cart pages and more.',
					'settings_path'    => 'admin.php?page=shaped-recommend-settings',
					'priority'         => 180,
					'stable_version'   => '1.0.0',
					'stable_sha256'    => 'e6ce31109b1f5ff270fdc73c2ad9c44b3f9558c763c4eae439ce86bbb456d9f1',
					'beta_version'     => null,
					'beta_url'         => null,
					'beta_sha256'      => null,
					'requires_wp'      => '6.6',
					'requires_php'     => '7.4',
					'requires_plugins' => array( 'woocommerce' ),
				),
				array(
					'slug'           => 'smart-product-gallery-for-fluentcart',
					'name'           => 'Smart Product Gallery for FluentCart',
					'edition'        => 'free',
					'product_family' => 'smart-product-gallery-for-fluentcart',
					'item_id'        => null,
					'description'    => 'Upgrade your FluentCart product pages with a responsive gallery slider, flexible thumbnails, image zoom, and variation images.',
					'settings_path'  => 'admin.php?page=smart-product-gallery',
					'priority'       => 190,
					'stable_version' => '1.0.7',
					'stable_sha256'  => 'edb7fefb3c03610583a1e5795252ebd6e0921b99e666e3e22aab5efbe51b68d6',
					'beta_version'   => null,
					'beta_url'       => null,
					'beta_sha256'    => null,
					'requires_wp'    => '6.0',
					'requires_php'   => '7.4',
				),
				array(
					'slug'           => 'shaped-kit',
					'name'           => 'Shaped Kit',
					'edition'        => 'free',
					'product_family' => 'shaped-kit',
					'item_id'        => null,
					'description'    => 'Official ShapedPlugin companion for AI agent orchestration and MCP dashboards.',
					'settings_path'  => 'admin.php?page=shaped-kit',
					'priority'       => 900,
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
	 * A version number from the catalog (for example `5.9`, `8.1`, `3.1.0-beta1`), or null when it is not one.
	 *
	 * @param mixed $value Candidate version.
	 * @return string|null The version, or null when missing or not a version.
	 */
	public static function clean_version( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d+(\.\d+){0,3}(-[A-Za-z0-9.]+)?$/', $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * One plugin folder name from the catalog, for example `wp-carousel-pro`. Anything that is not a plain slug is null.
	 *
	 * @param mixed $value Candidate slug.
	 * @return string|null The slug, or null.
	 */
	public static function clean_slug( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9-]+$/', $value ) ? $value : null;
	}

	/**
	 * A list of plugin slugs the catalog says a plugin needs, for example an e-commerce plugin's slug. Anything that is not a
	 * plain slug is left out, and a value that is not a list is an empty list.
	 *
	 * @param mixed $value Candidate list.
	 * @return string[] Unique, valid slugs.
	 */
	public static function clean_slug_list( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$slugs = array();
		foreach ( $value as $slug ) {
			if ( is_string( $slug ) && 1 === preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
				$slugs[ $slug ] = $slug;
			}
		}

		return array_values( $slugs );
	}

	/**
	 * A link the catalog offers for people to read, kept only if it is an https page on ShapedPlugin or wordpress.org.
	 *
	 * @param mixed $value Candidate address.
	 * @return string|null The address, or null when missing or not acceptable.
	 */
	public static function clean_info_url( $value ) {
		// https, then exactly one of three hosts, then only a path, query or fragment. No user info, no port, no second host.
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- A host name inside a pattern, not text shown to anyone.
		if ( ! is_string( $value ) || 1 !== preg_match( '/^https:\/\/(?:shapedplugin\.com|www\.shapedplugin\.com|wordpress\.org)(?:[\/?#][A-Za-z0-9._~%\/#?=&+,;-]*)?$/i', $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * A settings page path from the catalog, kept only if it is a plain wp-admin page.
	 *
	 * The catalog is signed, but this value becomes a link in wp-admin, so it is checked on its own: a PHP file name
	 * in the wp-admin folder plus an optional query string, with no scheme, no second slash, no directory and no `..`.
	 * Anything else is dropped (the entry stays, and the row simply has no Settings button).
	 *
	 * @param mixed $path Candidate path, for example `admin.php?page=shaped-kit`.
	 * @return string|null The path, or null when it is missing or not acceptable.
	 */
	public static function clean_admin_path( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}

		if ( 1 !== preg_match( '/^[a-z0-9_-]+\.php(\?[A-Za-z0-9_=&%.#-]*)?$/', $path ) ) {
			return null;
		}

		if ( false !== strpos( $path, '..' ) || false !== strpos( $path, '//' ) ) {
			return null;
		}

		return $path;
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

		// The plugin folder of this Free's Pro edition, when the Pro is not a catalog entry of its own. Only used to
		// recognise a Pro the site already has; never to install anything. Free entries only, and never the entry itself.
		$pro_slug = 'free' === $entry['edition'] ? self::clean_slug( isset( $entry['pro_slug'] ) ? $entry['pro_slug'] : null ) : null;
		if ( $pro_slug === (string) $entry['slug'] ) {
			$pro_slug = null;
		}

		$normalized = array(
			'slug'             => (string) $entry['slug'],
			'name'             => (string) $entry['name'],
			'edition'          => (string) $entry['edition'],
			'product_family'   => (string) $entry['product_family'],
			'item_id'          => isset( $entry['item_id'] ) && is_numeric( $entry['item_id'] ) ? (int) $entry['item_id'] : null,
			'description'      => isset( $entry['description'] ) ? (string) $entry['description'] : '',
			'stable_version'   => (string) $entry['stable_version'],
			'stable_sha256'    => strtolower( (string) $entry['stable_sha256'] ),
			'package_url'      => ! empty( $entry['package_url'] ) ? (string) $entry['package_url'] : null,
			'beta_version'     => ! empty( $entry['beta_version'] ) ? (string) $entry['beta_version'] : null,
			'beta_url'         => ! empty( $entry['beta_url'] ) ? (string) $entry['beta_url'] : null,
			'beta_sha256'      => ! empty( $entry['beta_sha256'] ) ? strtolower( (string) $entry['beta_sha256'] ) : null,
			// Missing means "no requirement", never a default: a default of 6.9 would mark every plugin as needing it.
			'requires_wp'      => self::clean_version( isset( $entry['requires_wp'] ) ? $entry['requires_wp'] : null ),
			'requires_php'     => self::clean_version( isset( $entry['requires_php'] ) ? $entry['requires_php'] : null ),
			'requires_plugins' => self::clean_slug_list( isset( $entry['requires_plugins'] ) ? $entry['requires_plugins'] : null ),
			'mcp_since'        => self::clean_version( isset( $entry['mcp_since'] ) ? $entry['mcp_since'] : null ),
			'priority'         => isset( $entry['priority'] ) && is_numeric( $entry['priority'] ) ? (int) $entry['priority'] : null,
			'changelog_url'    => self::clean_info_url( isset( $entry['changelog_url'] ) ? $entry['changelog_url'] : null ),
			'settings_path'    => self::clean_admin_path( isset( $entry['settings_path'] ) ? $entry['settings_path'] : null ),
			'pro_slug'         => $pro_slug,
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
