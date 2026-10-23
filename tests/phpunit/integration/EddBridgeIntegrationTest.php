<?php
/**
 * Integration tests for the licensing store bridge (Store track S2): what it sends to the store and what it lets back out.
 *
 * Verifies:
 * - E55: the license key travels in the POST body only, and no error message ever carries it.
 * - E54: the `url` sent to the store follows the product's own activation rule.
 * - E53: a refused license is a 422 with the store's reason, and a store failure is a 502.
 * - E47: the package comes back as `package_url`; nothing is returned without one.
 *
 * The store is a stand-in on `pre_http_request`: no request leaves the machine.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use ShapedKit\Store\CatalogData;
use ShapedKit\Store\EddBridge;
use WP_UnitTestCase;

/**
 * Class EddBridgeIntegrationTest
 */
class EddBridgeIntegrationTest extends WP_UnitTestCase {

	const KEY = 'ABCD1234-LICENSE-KEY-9f8e7d6c';

	/**
	 * Every request the stand-in store saw: url, parsed arguments.
	 *
	 * @var array<int, array{url: string, args: array}>
	 */
	private $calls = array();

	/**
	 * Remove the stand-in and any filter a test added.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'sp_site_home_url' );
		parent::tearDown();
	}

	/**
	 * The bundled Location Weather Pro entry (item 481, store `https://shapedplugin.com`).
	 *
	 * @return array
	 */
	private function pro_entry() {
		foreach ( CatalogData::get_bundled_catalog()['products'] as $product ) {
			if ( 'location-weather-pro' === $product['slug'] ) {
				return $product;
			}
		}

		$this->fail( 'The bundled catalog has no Location Weather Pro entry.' );
	}

	/**
	 * Answer every request with `$response` and remember what was asked.
	 *
	 * @param array|\WP_Error $response What the store answers.
	 */
	private function stand_in_store( $response ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $response ) {
				$this->calls[] = array(
					'url'  => $url,
					'args' => $args,
				);

				return $response;
			},
			10,
			3
		);
	}

	/**
	 * A 200 answer carrying `$body` as JSON.
	 *
	 * @param array $body The JSON document.
	 * @return array
	 */
	private function json_answer( array $body ) {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => array(),
		);
	}

	/**
	 * The POST body of the one request the store saw, as an array.
	 *
	 * @return array
	 */
	private function posted() {
		$this->assertCount( 1, $this->calls, 'Exactly one request goes to the store.' );
		$body = $this->calls[0]['args']['body'];

		if ( is_string( $body ) ) {
			parse_str( $body, $body );
		}

		return $body;
	}

	/**
	 * A good answer: a license the store accepts.
	 *
	 * @return array
	 */
	private function accepted() {
		return $this->json_answer(
			array(
				'new_version' => '2.8.0',
				'stable_tag'  => '2.8.0',
				'package'     => 'https://account.shapedplugin.com/edd-sl/package_download/abc123/location-weather-pro.zip',
			)
		);
	}

	/**
	 * E55: the key is in the POST body. The URL has no query string, so it cannot reach an access log.
	 */
	public function test_the_license_key_travels_in_the_post_body_and_never_in_the_url() {
		$this->stand_in_store( $this->accepted() );

		$result = EddBridge::get_version( $this->pro_entry(), self::KEY );

		$this->assertIsArray( $result );
		$this->assertSame( 'POST', $this->calls[0]['args']['method'] );
		$this->assertSame( 'https://shapedplugin.com/', $this->calls[0]['url'] );
		$this->assertStringNotContainsString( self::KEY, $this->calls[0]['url'] );
		$this->assertStringNotContainsString( '?', $this->calls[0]['url'] );

		$posted = $this->posted();
		$this->assertSame( self::KEY, $posted['license'] );
		$this->assertSame( 'get_version', $posted['edd_action'] );
		$this->assertSame( 481, (int) $posted['item_id'] );
		$this->assertSame( 'location-weather-pro', $posted['slug'] );
		$this->assertSame( 0, (int) $posted['beta'] );
	}

	/**
	 * A beta is requested with `beta: 1`, and only when asked for.
	 */
	public function test_a_beta_request_sets_the_beta_flag() {
		$this->stand_in_store( $this->accepted() );

		EddBridge::get_version( $this->pro_entry(), self::KEY, true );

		$this->assertSame( 1, (int) $this->posted()['beta'] );
	}

	/**
	 * E54: single site sends the product's own `sp_site_home_url` filter; a network sends the network URL, whatever the filter says.
	 */
	public function test_the_site_url_follows_the_products_own_activation_rule() {
		add_filter(
			'sp_site_home_url',
			function () {
				return 'https://filtered.example.org';
			}
		);
		$this->stand_in_store( $this->accepted() );

		EddBridge::get_version( $this->pro_entry(), self::KEY );

		$expected = is_multisite() ? network_home_url() : 'https://filtered.example.org';
		$this->assertSame( $expected, $this->posted()['url'] );
	}

	/**
	 * E47: the package URL and version come back under the names the installer reads.
	 */
	public function test_a_valid_license_returns_the_package_and_version() {
		$this->stand_in_store( $this->accepted() );

		$result = EddBridge::get_version( $this->pro_entry(), self::KEY );

		$this->assertSame( 'https://account.shapedplugin.com/edd-sl/package_download/abc123/location-weather-pro.zip', $result['package_url'] );
		$this->assertSame( '2.8.0', $result['new_version'] );
	}

	/**
	 * E53: a store that answers without a package has refused the license: 422, never a package.
	 */
	public function test_a_refused_license_is_a_422_with_no_package() {
		$this->stand_in_store( $this->json_answer( array( 'new_version' => '2.8.0' ) ) );

		$result = EddBridge::get_version( $this->pro_entry(), self::KEY );

		$this->assertWPError( $result );
		$this->assertSame( 'license_invalid', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	/**
	 * E55: even when the store's own reason repeats the key back, the key does not reach the message.
	 */
	public function test_a_refusal_that_repeats_the_key_does_not_carry_it() {
		$this->stand_in_store( $this->json_answer( array( 'msg' => 'License ' . self::KEY . ' has expired.' ) ) );

		$result = EddBridge::get_version( $this->pro_entry(), self::KEY );

		$this->assertWPError( $result );
		$this->assertSame( 'license_invalid', $result->get_error_code() );
		$this->assertStringNotContainsString( self::KEY, $result->get_error_message() );
		$this->assertStringNotContainsString( self::KEY, (string) wp_json_encode( $result->get_error_data() ) );
		$this->assertStringContainsString( 'has expired', $result->get_error_message(), 'The reason is kept; only the key is removed.' );
	}

	/**
	 * E53 / E55: a store that is down, and a transport that fails, are errors without the key.
	 */
	public function test_a_store_failure_is_a_502_or_a_transport_error_without_the_key() {
		$this->stand_in_store(
			array(
				'response' => array(
					'code'    => 503,
					'message' => 'Service Unavailable',
				),
				'body'     => 'maintenance ' . self::KEY,
				'headers'  => array(),
			)
		);

		$down = EddBridge::get_version( $this->pro_entry(), self::KEY );

		$this->assertWPError( $down );
		$this->assertSame( 'edd_store_unavailable', $down->get_error_code() );
		$this->assertSame( 502, $down->get_error_data()['status'] );
		$this->assertStringNotContainsString( self::KEY, $down->get_error_message() );

		remove_all_filters( 'pre_http_request' );
		$this->calls = array();
		$this->stand_in_store( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$failed = EddBridge::get_version( $this->pro_entry(), self::KEY );

		$this->assertWPError( $failed );
		$this->assertSame( 'http_request_failed', $failed->get_error_code() );
		$this->assertStringNotContainsString( self::KEY, $failed->get_error_message() );
	}

	/**
	 * A body that is not JSON is refused as an invalid store response.
	 */
	public function test_a_body_that_is_not_json_is_refused() {
		$this->stand_in_store(
			array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => '<html>Not the store you wanted</html>',
				'headers'  => array(),
			)
		);

		$result = EddBridge::get_version( $this->pro_entry(), self::KEY );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_store_response', $result->get_error_code() );
	}

	/**
	 * A blank key never reaches the store.
	 */
	public function test_an_empty_key_makes_no_request() {
		$this->stand_in_store( $this->accepted() );

		$result = EddBridge::get_version( $this->pro_entry(), '   ' );

		$this->assertWPError( $result );
		$this->assertSame( 'license_key_required', $result->get_error_code() );
		$this->assertCount( 0, $this->calls );
	}
}
