<?php
/**
 * Unit tests for CatalogData and cryptographic signing tooling (Phase S0).
 *
 * Verifies:
 * 1. Bundled catalog schema integrity.
 * 2. Monotonic serial checks and timestamp parsing.
 * 3. Strict 64-hex SHA-256 validation.
 * 4. Ed25519 keypair generation, detached signing, and verification.
 * 5. Tampered payload rejection (1-byte modification / SE03).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShapedKit\Store\CatalogData;

require_once dirname( __DIR__, 3 ) . '/src/Store/CatalogData.php';
require_once dirname( __DIR__, 3 ) . '/tools/sign-catalog.php';

/**
 * Class CatalogDataTest
 */
class CatalogDataTest extends TestCase {

	/**
	 * Test that bundled catalog passes schema validation.
	 */
	public function test_bundled_catalog_passes_schema_validation() {
		$catalog = CatalogData::get_bundled_catalog();
		$this->assertIsArray( $catalog );
		$this->assertSame( 100, $catalog['serial'] );

		$validated = CatalogData::validate_catalog( $catalog );
		$this->assertNotNull( $validated );
		$this->assertCount( 22, $validated['products'] );

		$slugs = array_column( $validated['products'], 'slug' );
		$this->assertContains( 'location-weather', $slugs );
		$this->assertContains( 'location-weather-pro', $slugs );
		$this->assertContains( 'testimonial-free', $slugs );
		$this->assertContains( 'testimonial-pro', $slugs );
		$this->assertContains( 'shaped-kit', $slugs );
	}

	/**
	 * Test that invalid product entry (bad slug or hash) is rejected.
	 */
	public function test_validate_product_entry_rejects_invalid_data() {
		// Invalid slug with uppercase or special characters.
		$bad_slug = array(
			'slug'           => 'Invalid_Slug!',
			'name'           => 'Test',
			'edition'        => 'free',
			'product_family' => 'test',
			'stable_version' => '1.0.0',
			'stable_sha256'  => str_repeat( 'a', 64 ),
		);
		$this->assertNull( CatalogData::validate_product_entry( $bad_slug ) );

		// Invalid SHA-256 length.
		$bad_hash = array(
			'slug'           => 'valid-slug',
			'name'           => 'Test',
			'edition'        => 'free',
			'product_family' => 'test',
			'stable_version' => '1.0.0',
			'stable_sha256'  => 'short_hash',
		);
		$this->assertNull( CatalogData::validate_product_entry( $bad_hash ) );

		// Invalid edition.
		$bad_edition = array(
			'slug'           => 'valid-slug',
			'name'           => 'Test',
			'edition'        => 'enterprise',
			'product_family' => 'test',
			'stable_version' => '1.0.0',
			'stable_sha256'  => str_repeat( 'a', 64 ),
		);
		$this->assertNull( CatalogData::validate_product_entry( $bad_edition ) );
	}

	/**
	 * Test Ed25519 signing and verification cycle.
	 */
	public function test_ed25519_signing_and_verification_cycle() {
		$keys = shaped_kit_generate_keypair();
		$this->assertNotEmpty( $keys['public'] );
		$this->assertNotEmpty( $keys['private'] );

		$catalog_json = json_encode( CatalogData::get_bundled_catalog(), JSON_PRETTY_PRINT );
		$signature    = shaped_kit_sign_catalog( $catalog_json, $keys['private'] );

		$this->assertNotEmpty( $signature );

		// Valid verification.
		$valid = shaped_kit_verify_catalog_signature( $catalog_json, $signature, $keys['public'] );
		$this->assertTrue( $valid, 'Signature must verify against unchanged payload' );

		// Tampered payload verification failure (SE03).
		$tampered = str_replace( '3.0.7', '3.0.8', $catalog_json );
		$invalid  = shaped_kit_verify_catalog_signature( $tampered, $signature, $keys['public'] );
		$this->assertFalse( $invalid, 'Signature verification must fail on tampered payload' );
	}

	/**
	 * Test that corrupted signature fails verification.
	 */
	public function test_corrupted_signature_fails_verification() {
		$keys         = shaped_kit_generate_keypair();
		$catalog_json = json_encode( CatalogData::get_bundled_catalog() );
		$signature    = shaped_kit_sign_catalog( $catalog_json, $keys['private'] );

		// Corrupt 1 byte in base64 signature (guaranteed different character).
		$char      = 'X' === $signature[5] ? 'Y' : 'X';
		$corrupted = substr_replace( $signature, $char, 5, 1 );

		$valid = shaped_kit_verify_catalog_signature( $catalog_json, $corrupted, $keys['public'] );
		$this->assertFalse( $valid );
	}

	/**
	 * Test that validate_product_entry preserves package_url for self-updates (Bug 1).
	 */
	public function test_validate_product_entry_preserves_package_url() {
		$entry = array(
			'slug'           => 'shaped-kit',
			'name'           => 'Shaped Kit',
			'edition'        => 'free',
			'product_family' => 'shaped-kit',
			'stable_version' => '1.0.0',
			'stable_sha256'  => str_repeat( 'a', 64 ),
			'package_url'    => 'https://api.shapedplugin.com/kit/v1/releases/shaped-kit-1.0.0.zip',
		);

		$validated = CatalogData::validate_product_entry( $entry );
		$this->assertNotNull( $validated );
		$this->assertArrayHasKey( 'package_url', $validated );
		$this->assertSame( 'https://api.shapedplugin.com/kit/v1/releases/shaped-kit-1.0.0.zip', $validated['package_url'] );

		// Bundled catalog check: shaped-kit entry retains package_url after catalog validation.
		$catalog = CatalogData::validate_catalog( CatalogData::get_bundled_catalog() );
		$this->assertNotNull( $catalog );
		$kit = null;
		foreach ( $catalog['products'] as $p ) {
			if ( 'shaped-kit' === $p['slug'] ) {
				$kit = $p;
				break;
			}
		}
		$this->assertNotNull( $kit );
		$this->assertSame( 'https://api.shapedplugin.com/kit/v1/releases/shaped-kit.zip', $kit['package_url'] );
	}

	/**
	 * Settings paths that become a link in wp-admin: only a plain wp-admin page is kept.
	 *
	 * @return array<string, array{0: mixed, 1: string|null}>
	 */
	public function settings_paths() {
		return array(
			'a plugin page'               => array( 'admin.php?page=shaped-kit', 'admin.php?page=shaped-kit' ),
			'a custom post type page'     => array( 'edit.php?post_type=location_weather&page=splw_admin_dashboard', 'edit.php?post_type=location_weather&page=splw_admin_dashboard' ),
			'a page with a fragment'      => array( 'edit.php?post_type=sp_wp_tabs&page=tabs_settings#tab=general', 'edit.php?post_type=sp_wp_tabs&page=tabs_settings#tab=general' ),
			'a bare admin file'           => array( 'options-general.php', 'options-general.php' ),
			'a full address'              => array( 'https://evil.example/admin.php', null ),
			'a protocol-relative address' => array( '//evil.example/admin.php', null ),
			'a script address'            => array( 'javascript:alert(1)', null ),
			'a path with a leading slash' => array( '/wp-admin/admin.php?page=x', null ),
			'a file in a folder'          => array( 'plugins/hello.php', null ),
			'a way up'                    => array( '../wp-login.php', null ),
			'a way up inside the query'   => array( 'admin.php?page=..%2F..', null ),
			'a redirect to elsewhere'     => array( 'admin.php?redirect=https://evil.example', null ),
			'a double slash in the query' => array( 'admin.php?page=a//b', null ),
			'a space'                     => array( 'admin.php?page=a b', null ),
			'an angle bracket'            => array( 'admin.php?page=<script>', null ),
			'not php'                     => array( 'readme.txt', null ),
			'empty'                       => array( '', null ),
			'not a string'                => array( array( 'admin.php' ), null ),
			'missing'                     => array( null, null ),
		);
	}

	/**
	 * Only a plain wp-admin page is kept; everything in the list above that is not one comes back null.
	 *
	 * @dataProvider settings_paths
	 *
	 * @param mixed       $path     Candidate path.
	 * @param string|null $expected The path kept, or null.
	 */
	public function test_a_settings_path_is_kept_only_if_it_is_a_plain_admin_page( $path, $expected ) {
		$this->assertSame( $expected, CatalogData::clean_admin_path( $path ) );
	}

	/**
	 * A bad settings path costs the field, never the entry: the row still shows, with no Settings button.
	 */
	public function test_a_bad_settings_path_drops_the_field_and_keeps_the_entry() {
		$base = array(
			'slug'           => 'location-weather',
			'name'           => 'Location Weather',
			'edition'        => 'free',
			'product_family' => 'location-weather',
			'stable_version' => '3.0.7',
			'stable_sha256'  => str_repeat( 'a', 64 ),
		);

		$good = CatalogData::validate_product_entry( $base + array( 'settings_path' => 'admin.php?page=x' ) );
		$bad  = CatalogData::validate_product_entry( $base + array( 'settings_path' => 'https://evil.example/' ) );
		$none = CatalogData::validate_product_entry( $base );

		$this->assertSame( 'admin.php?page=x', $good['settings_path'] );
		$this->assertNotNull( $bad, 'The entry is kept.' );
		$this->assertNull( $bad['settings_path'], 'The field is dropped.' );
		$this->assertNull( $none['settings_path'] );
	}

	/**
	 * The built-in entries keep the settings page they were given.
	 */
	public function test_the_built_in_entries_keep_their_settings_pages() {
		$catalog = CatalogData::validate_catalog( CatalogData::get_bundled_catalog() );
		$paths   = array_column( $catalog['products'], 'settings_path', 'slug' );

		$this->assertSame( 'edit.php?post_type=location_weather&page=splw_admin_dashboard', $paths['location-weather'] );
		$this->assertSame( 'edit.php?post_type=location_weather&page=splw_admin_dashboard', $paths['location-weather-pro'] );
		$this->assertSame( 'edit.php?post_type=spt_testimonial&page=rtp_dashboard', $paths['testimonial-free'] );
		$this->assertSame( 'edit.php?post_type=spt_testimonial&page=rtp_dashboard', $paths['testimonial-pro'] );
		$this->assertSame( 'admin.php?page=shaped-kit', $paths['shaped-kit'] );
	}

	/**
	 * Version numbers a catalog may carry for `requires_wp`, `requires_php` and `mcp_since`.
	 *
	 * @return array<string, array{0: mixed, 1: string|null}>
	 */
	public function versions() {
		return array(
			'two parts'              => array( '8.1', '8.1' ),
			'three parts'            => array( '3.0.7', '3.0.7' ),
			'a pre-release'          => array( '3.1.0-beta1', '3.1.0-beta1' ),
			'four parts'             => array( '1.2.3.4', '1.2.3.4' ),
			'a word'                 => array( 'latest', null ),
			'a range'                => array( '>=5.0', null ),
			'a trailing dot'         => array( '5.', null ),
			'with a space'           => array( '5.0 ', null ),
			'a script'               => array( '<script>', null ),
			'a number, not a string' => array( 8.1, null ),
			'empty'                  => array( '', null ),
			'missing'                => array( null, null ),
		);
	}

	/**
	 * A catalog version is kept only when it is a plain version string.
	 *
	 * @dataProvider versions
	 *
	 * @param mixed       $value    Candidate.
	 * @param string|null $expected The version kept, or null.
	 */
	public function test_a_version_is_kept_only_if_it_looks_like_one( $value, $expected ) {
		$this->assertSame( $expected, CatalogData::clean_version( $value ) );
	}

	/**
	 * Only plain, unique plugin slugs survive in `requires_plugins`.
	 */
	public function test_a_list_of_required_plugins_keeps_only_plain_slugs() {
		$this->assertSame( array( 'woocommerce', 'fluent-cart' ), CatalogData::clean_slug_list( array( 'woocommerce', 'fluent-cart', 'woocommerce' ) ) );
		$this->assertSame( array( 'woocommerce' ), CatalogData::clean_slug_list( array( 'woocommerce', 'Bad Slug', '../x', 'a/b', 5, null, array( 'woocommerce' ) ) ) );
		$this->assertSame( array(), CatalogData::clean_slug_list( 'woocommerce' ) );
		$this->assertSame( array(), CatalogData::clean_slug_list( null ) );
		$this->assertSame( array(), CatalogData::clean_slug_list( array() ) );
	}

	/**
	 * Addresses
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public function info_urls() {
		return array(
			'a ShapedPlugin page'       => array( 'https://shapedplugin.com/plugin/x/changelog/', true ),
			'www ShapedPlugin'          => array( 'https://www.shapedplugin.com/x', true ),
			'a wordpress.org page'      => array( 'https://wordpress.org/plugins/x/#developers', true ),
			'plain http'                => array( 'http://shapedplugin.com/x', false ),
			'another host'              => array( 'https://evil.example/shapedplugin.com', false ),
			'a look-alike host'         => array( 'https://shapedplugin.com.evil.example/x', false ),
			'a subdomain we do not use' => array( 'https://evil.shapedplugin.com/x', false ),
			'user info in front'        => array( 'https://shapedplugin.com@evil.example/x', false ),
			'a port'                    => array( 'https://shapedplugin.com:8443/x', false ),
			'a script address'          => array( 'javascript:alert(1)', false ),
			'a protocol-relative one'   => array( '//shapedplugin.com/x', false ),
			'not a string'              => array( array( 'https://shapedplugin.com' ), false ),
			'empty'                     => array( '', false ),
		);
	}

	/**
	 * A catalog link is kept only on our own https hosts.
	 *
	 * @dataProvider info_urls
	 *
	 * @param mixed $value  Candidate.
	 * @param bool  $kept   Whether it is kept.
	 */
	public function test_a_catalog_link_is_kept_only_on_our_own_https_hosts( $value, $kept ) {
		$this->assertSame( $kept ? $value : null, CatalogData::clean_info_url( $value ) );
	}

	/**
	 * `pro_slug` names the plugin folder of a Free's Pro. It is kept for a Free only, and only as a plain slug other
	 * than the entry's own.
	 */
	public function test_pro_slug_is_kept_for_a_free_entry_with_a_plain_slug_only() {
		$base = array(
			'slug'           => 'wp-carousel-free',
			'name'           => 'WP Carousel',
			'edition'        => 'free',
			'product_family' => 'wp-carousel',
			'stable_version' => '1.0.0',
			'stable_sha256'  => str_repeat( 'a', 64 ),
		);

		$this->assertSame( 'wp-carousel-pro', CatalogData::validate_product_entry( $base + array( 'pro_slug' => 'wp-carousel-pro' ) )['pro_slug'] );
		$this->assertNull( CatalogData::validate_product_entry( $base )['pro_slug'], 'Not stated means none.' );

		foreach ( array( '../wp-carousel-pro', 'WP Carousel Pro', 'a/b', '', 5, array( 'wp-carousel-pro' ) ) as $bad ) {
			$entry = CatalogData::validate_product_entry( $base + array( 'pro_slug' => $bad ) );
			$this->assertNotNull( $entry, 'A bad pro_slug costs the field, not the entry.' );
			$this->assertNull( $entry['pro_slug'] );
		}

		$this->assertNull( CatalogData::validate_product_entry( $base + array( 'pro_slug' => 'wp-carousel-free' ) )['pro_slug'], 'Never the entry itself.' );

		$pro = CatalogData::validate_product_entry(
			array_merge(
				$base,
				array(
					'slug'     => 'wp-carousel-pro',
					'edition'  => 'pro',
					'pro_slug' => 'other-pro',
				)
			)
		);
		$this->assertNull( $pro['pro_slug'], 'A Pro entry has no Pro of its own.' );
	}

	/**
	 * A bad new field costs the field, never the entry, and a missing requirement means "none".
	 */
	public function test_the_new_catalog_fields_are_cleaned_one_by_one() {
		$base = array(
			'slug'           => 'woo-one',
			'name'           => 'Woo One',
			'edition'        => 'free',
			'product_family' => 'woo-one',
			'stable_version' => '1.0.0',
			'stable_sha256'  => str_repeat( 'a', 64 ),
		);

		$good = CatalogData::validate_product_entry(
			$base + array(
				'requires_wp'      => '5.0',
				'requires_php'     => '7.0',
				'requires_plugins' => array( 'woocommerce' ),
				'mcp_since'        => '1.1.0',
				'priority'         => '40',
				'changelog_url'    => 'https://shapedplugin.com/woo-one/changelog/',
			)
		);
		$this->assertSame( '5.0', $good['requires_wp'] );
		$this->assertSame( '7.0', $good['requires_php'] );
		$this->assertSame( array( 'woocommerce' ), $good['requires_plugins'] );
		$this->assertSame( '1.1.0', $good['mcp_since'] );
		$this->assertSame( 40, $good['priority'] );
		$this->assertSame( 'https://shapedplugin.com/woo-one/changelog/', $good['changelog_url'] );

		$bad = CatalogData::validate_product_entry(
			$base + array(
				'requires_wp'      => 'new',
				'requires_php'     => '>=8',
				'requires_plugins' => 'woocommerce',
				'mcp_since'        => 'soon',
				'priority'         => 'high',
				'changelog_url'    => 'https://evil.example/',
			)
		);
		$this->assertNotNull( $bad, 'The entry is kept.' );
		$this->assertNull( $bad['requires_wp'] );
		$this->assertNull( $bad['requires_php'] );
		$this->assertSame( array(), $bad['requires_plugins'] );
		$this->assertNull( $bad['mcp_since'] );
		$this->assertNull( $bad['priority'] );
		$this->assertNull( $bad['changelog_url'] );

		$none = CatalogData::validate_product_entry( $base );
		$this->assertNull( $none['requires_wp'], 'No requirement stated means none, not the Kit\'s own 6.9.' );
		$this->assertNull( $none['requires_php'] );
	}

	/**
	 * The built-in list holds all 19 free plugins of the wordpress.org profile, plus the Pro editions and the Kit.
	 */
	public function test_the_built_in_list_holds_the_nineteen_free_plugins() {
		$catalog = CatalogData::validate_catalog( CatalogData::get_bundled_catalog() );
		$by_slug = array();
		foreach ( $catalog['products'] as $product ) {
			$by_slug[ $product['slug'] ] = $product;
		}

		$free = array(
			'easy-accordion-free',
			'wp-carousel-free',
			'testimonial-free',
			'logo-carousel-free',
			'post-carousel',
			'wp-expand-tabs-free',
			'location-weather',
			'team-free',
			'easy-lightbox-wp',
			'styble',
			'smart-product-gallery-for-fluentcart',
			'woo-product-slider',
			'gallery-slider-for-woocommerce',
			'woo-category-slider-grid',
			'woo-quickview',
			'smart-swatches',
			'smart-brands-for-woocommerce',
			'reno-cart',
			'shaped-recommend',
		);
		foreach ( $free as $slug ) {
			$this->assertArrayHasKey( $slug, $by_slug, "$slug is in the list." );
			$this->assertSame( 'free', $by_slug[ $slug ]['edition'] );
			$this->assertNotNull( $by_slug[ $slug ]['priority'], "$slug has a position." );
			$this->assertNotSame(
				'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
				$by_slug[ $slug ]['stable_sha256'],
				"$slug carries a real hash, not the hash of an empty file."
			);
		}
		$this->assertCount( 19, $free );
		$this->assertCount( 19, array_unique( array_column( array_intersect_key( $by_slug, array_flip( $free ) ), 'stable_sha256' ) ), 'Every hash is different.' );

		// The eight plugins that declare an e-commerce plugin as a requirement on wordpress.org.
		$woo = array( 'woo-product-slider', 'gallery-slider-for-woocommerce', 'woo-category-slider-grid', 'woo-quickview', 'smart-swatches', 'smart-brands-for-woocommerce', 'reno-cart', 'shaped-recommend' );
		foreach ( $free as $slug ) {
			$this->assertSame( in_array( $slug, $woo, true ) ? array( 'woocommerce' ) : array(), $by_slug[ $slug ]['requires_plugins'], $slug );
		}

		$this->assertSame( '8.1', $by_slug['easy-lightbox-wp']['requires_php'] );
		$this->assertSame( '6.8', $by_slug['easy-lightbox-wp']['requires_wp'] );
		$this->assertSame( '5.9', $by_slug['location-weather']['requires_wp'], 'The plugin\'s own requirement, not the Kit\'s 6.9.' );
		$this->assertSame( '6.9', $by_slug['shaped-kit']['requires_wp'], 'The Kit itself does need 6.9.' );
		$this->assertSame( 900, $by_slug['shaped-kit']['priority'] );
	}
}
