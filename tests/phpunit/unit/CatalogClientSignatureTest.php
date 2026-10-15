<?php
/**
 * Which keys the catalog signature is checked against (FR-ST11, key rotation).
 *
 * Risk covered: a catalog signed with the rotation key being refused (rotation silently broken), and, the
 * other way, a catalog signed with a key that is not pinned being accepted.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShapedKit\Store\CatalogClient;
use ShapedKit\Store\CatalogData;

require_once dirname( __DIR__, 3 ) . '/src/Store/CatalogData.php';
require_once dirname( __DIR__, 3 ) . '/src/Store/CatalogClient.php';
require_once dirname( __DIR__, 3 ) . '/tools/sign-catalog.php';

/**
 * Class CatalogClientSignatureTest
 */
class CatalogClientSignatureTest extends TestCase {

	public function test_both_the_pinned_key_and_the_rotation_key_are_trusted(): void {
		$this->assertSame(
			array( CatalogData::PUBLIC_KEY_BASE64, CatalogData::NEXT_PUBLIC_KEY_BASE64 ),
			CatalogClient::trusted_public_keys()
		);
	}

	public function test_a_signature_from_the_rotation_key_is_accepted_and_a_stranger_is_not(): void {
		$primary  = shaped_kit_generate_keypair();
		$next     = shaped_kit_generate_keypair();
		$stranger = shaped_kit_generate_keypair();
		$payload  = '{"serial":1,"products":[]}';
		$keys     = array( $primary['public'], $next['public'] );

		$this->assertTrue( CatalogClient::signature_is_trusted( $payload, shaped_kit_sign_catalog( $payload, $primary['private'] ), $keys ) );
		$this->assertTrue( CatalogClient::signature_is_trusted( $payload, shaped_kit_sign_catalog( $payload, $next['private'] ), $keys ), 'Rotation: the next key signs.' );
		$this->assertFalse( CatalogClient::signature_is_trusted( $payload, shaped_kit_sign_catalog( $payload, $stranger['private'] ), $keys ) );
		$this->assertFalse( CatalogClient::signature_is_trusted( $payload . ' ', shaped_kit_sign_catalog( $payload, $next['private'] ), $keys ), 'A changed payload is refused under either key.' );
	}

	public function test_with_no_rotation_key_only_the_pinned_one_counts(): void {
		$primary = shaped_kit_generate_keypair();
		$next    = shaped_kit_generate_keypair();
		$payload = '{"serial":1}';

		$this->assertFalse( CatalogClient::signature_is_trusted( $payload, shaped_kit_sign_catalog( $payload, $next['private'] ), array( $primary['public'] ) ) );
		$this->assertFalse( CatalogClient::signature_is_trusted( $payload, shaped_kit_sign_catalog( $payload, $next['private'] ), array() ), 'No keys, no trust.' );
	}
}
