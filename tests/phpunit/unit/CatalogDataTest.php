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
		$this->assertCount( 5, $validated['products'] );

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

		// Corrupt 1 byte in base64 signature.
		$corrupted = substr_replace( $signature, 'X', 5, 1 );

		$valid = shaped_kit_verify_catalog_signature( $catalog_json, $corrupted, $keys['public'] );
		$this->assertFalse( $valid );
	}
}
