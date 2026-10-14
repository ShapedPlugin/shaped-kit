<?php
/**
 * Integration tests for Store Track Phase S1 (CatalogClient, PackageVerifier, Installer, and StoreController).
 *
 * Verifies:
 * - SE01: DISALLOW_FILE_MODS barrier blocks install/update.
 * - SE05: Pre-unpack SHA-256 checksum mismatch aborts installation and unlinks file.
 * - SE06: Unauthorized download hosts blocked (SSRF guard).
 * - SE08: Mutex locking prevents concurrent installations.
 * - GET /shaped-kit/v1/store/catalog endpoint permissions and enriched response data.
 * - POST /shaped-kit/v1/store/activate capability requirements and execution.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use ShapedKit\Store\CatalogClient;
use ShapedKit\Store\CatalogData;
use ShapedKit\Store\Installer;
use ShapedKit\Store\PackageVerifier;
use ShapedKit\Rest\StoreController;
use WP_REST_Request;
use WP_UnitTestCase;
use WP_Upgrader;

/**
 * Class StoreTrackIntegrationTest
 */
class StoreTrackIntegrationTest extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		if ( is_multisite() ) {
			grant_super_admin( $this->admin_id );
		}

		wp_set_current_user( $this->admin_id );

		\ShapedKit\Audit\LogTable::install();

		if ( ! has_action( 'rest_api_init', array( StoreController::class, 'register_routes' ) ) ) {
			StoreController::register();
		}

		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * Tear down after tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test SE06: Host allowlist only accepts HTTPS requests to allowlisted domains.
	 */
	public function test_package_verifier_enforces_host_allowlist() {
		// Valid allowlisted hosts.
		$this->assertTrue( PackageVerifier::is_allowed_host( 'https://downloads.wordpress.org/plugin/location-weather.3.0.7.zip' ) );
		$this->assertTrue( PackageVerifier::is_allowed_host( 'https://account.shapedplugin.com/edd-sl/package.zip' ) );
		$this->assertTrue( PackageVerifier::is_allowed_host( 'https://api.shapedplugin.com/kit/v1/betas/package.zip' ) );

		// Non-HTTPS schemes rejected.
		$this->assertFalse( PackageVerifier::is_allowed_host( 'http://downloads.wordpress.org/plugin/test.zip' ) );

		// Third-party or rogue domains rejected.
		$this->assertFalse( PackageVerifier::is_allowed_host( 'https://evil-attacker.com/malicious.zip' ) );
		$this->assertFalse( PackageVerifier::is_allowed_host( 'https://github.com/downloads/package.zip' ) );
		$this->assertFalse( PackageVerifier::is_allowed_host( 'https://sub.downloads.wordpress.org.evil.com/fake.zip' ) );
	}

	/**
	 * Test SE05: Pre-unpack checksum mismatch aborts unpack and unlinks file.
	 */
	public function test_checksum_mismatch_unlinks_file_and_returns_error() {
		$temp_dir = get_temp_dir();
		$fake_zip = $temp_dir . 'shaped_kit_test_corrupt_' . uniqid() . '.zip';
		file_put_contents( $fake_zip, 'PKfake_corrupted_payload_data' );

		$this->assertFileExists( $fake_zip );

		$expected_hash = hash( 'sha256', 'completely_different_content' );

		$result = PackageVerifier::verify_checksum( $fake_zip, $expected_hash );

		$this->assertWPError( $result );
		$this->assertSame( 'checksum_mismatch', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );

		// Verify file was unlinked immediately (SE05).
		$this->assertFileDoesNotExist( $fake_zip );
	}

	/**
	 * Test SE08: Mutex lock prevents concurrent install execution.
	 */
	public function test_concurrent_install_lock_rejection() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Simulate another process holding the lock.
		$locked = WP_Upgrader::create_lock( Installer::LOCK_NAME, 300 );
		$this->assertTrue( $locked );

		// Attempting to install while locked must fail with install_in_progress (429).
		$result = Installer::install_free_plugin( 'location-weather' );

		$this->assertWPError( $result );
		$this->assertSame( 'install_in_progress', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );

		// Release lock.
		WP_Upgrader::release_lock( Installer::LOCK_NAME );
	}

	/**
	 * Test SE01: DISALLOW_FILE_MODS barrier completely blocks installation.
	 */
	public function test_disallow_file_mods_blocks_installation() {
		add_filter( 'file_mod_allowed', '__return_false' );

		$can = Installer::can_install();
		$this->assertWPError( $can );
		$this->assertSame( 'file_mods_disabled', $can->get_error_code() );
		$this->assertSame( 403, $can->get_error_data()['status'] );

		remove_filter( 'file_mod_allowed', '__return_false' );
	}

	/**
	 * Test GET /store/catalog endpoint permission checks and enriched output.
	 */
	public function test_get_catalog_rest_endpoint() {
		// Unauthorized user rejected.
		wp_set_current_user( $this->subscriber_id );
		$request  = new WP_REST_Request( 'GET', '/shaped-kit/v1/store/catalog' );
		$response = rest_do_request( $request );
		$this->assertSame( 403, $response->get_status() );

		// Admin authorized.
		wp_set_current_user( $this->admin_id );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'serial', $data );
		$this->assertArrayHasKey( 'products', $data );
		$this->assertNotEmpty( $data['products'] );

		$first = $data['products'][0];
		$this->assertArrayHasKey( 'slug', $first );
		$this->assertArrayHasKey( 'installed', $first );
		$this->assertArrayHasKey( 'active', $first );
		$this->assertArrayHasKey( 'can_install', $first );
	}

	/**
	 * Test POST /store/activate permissions.
	 */
	public function test_activate_endpoint_permissions() {
		wp_set_current_user( $this->subscriber_id );
		$request = new WP_REST_Request( 'POST', '/shaped-kit/v1/store/activate' );
		$request->set_param( 'slug', 'location-weather' );

		$response = rest_do_request( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test EddBridge::mask_license_key preserves only last 4 characters.
	 */
	public function test_edd_bridge_masks_license_keys_safely() {
		$this->assertSame( '••••', \ShapedKit\Store\EddBridge::mask_license_key( 'abcd' ) );
		$this->assertSame( '•••••••••••••••5678', \ShapedKit\Store\EddBridge::mask_license_key( '1234-abcd-efgh-5678' ) );
		$this->assertSame( '', \ShapedKit\Store\EddBridge::mask_license_key( '' ) );
	}

	/**
	 * Test read-only license introspection for Location Weather Pro without modifying options.
	 */
	public function test_license_introspection_reads_option_safely() {
		// Mock existing Location Weather Pro license settings.
		update_option(
			'location_weather_settings',
			array(
				'license_key' => 'mock-license-key-for-weather-pro-9988',
			)
		);
		update_option( 'location_weather_license_key_status', 'valid' );

		$entry = array(
			'slug'                  => 'location-weather-pro',
			'edition'               => 'pro',
			'license_option'        => 'location_weather_settings',
			'license_key_field'     => 'license_key',
			'license_status_option' => 'location_weather_license_key_status',
		);

		$info = \ShapedKit\Store\EddBridge::get_installed_license_info( $entry );

		$this->assertTrue( $info['has_license'] );
		$this->assertSame( 'valid', $info['status'] );
		$this->assertStringEndsWith( '9988', $info['masked_key'] );
		$this->assertStringNotContainsString( 'mock-license-key', $info['masked_key'] );

		// Clean up.
		delete_option( 'location_weather_settings' );
		delete_option( 'location_weather_license_key_status' );
	}

	/**
	 * Test POST /store/install-pro permissions and empty license rejection.
	 */
	public function test_install_pro_endpoint_permissions_and_validation() {
		// Subscriber rejected.
		wp_set_current_user( $this->subscriber_id );
		$request = new WP_REST_Request( 'POST', '/shaped-kit/v1/store/install-pro' );
		$request->set_param( 'slug', 'location-weather-pro' );
		$request->set_param( 'license_key', 'valid-key-1234' );

		$response = rest_do_request( $request );
		$this->assertSame( 403, $response->get_status() );

		// Admin authorized with empty key rejected with 400.
		wp_set_current_user( $this->admin_id );
		$request->set_param( 'license_key', '' );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test SE15: Strict No-Downgrade barrier blocks installation if installed version >= beta version.
	 */
	public function test_no_downgrade_barrier_blocks_install_if_newer_or_equal() {
		// Create mock location-weather plugin folder and main file so find_plugin_file succeeds.
		$lw_dir  = WP_PLUGIN_DIR . '/location-weather';
		$lw_file = $lw_dir . '/location-weather.php';
		if ( ! is_dir( $lw_dir ) ) {
			wp_mkdir_p( $lw_dir );
		}
		file_put_contents(
			$lw_file,
			"<?php\n/**\n * Plugin Name: Location Weather\n * Version: 4.0.0\n */\n"
		);

		// Seed catalog cache with bundled fallback so tests never attempt external network calls.
		set_transient( \ShapedKit\Store\CatalogClient::TRANSIENT_CATALOG_CACHE, \ShapedKit\Store\CatalogData::get_bundled_catalog(), 3600 );

		$result = \ShapedKit\Store\BetaInstaller::install_beta( 'location-weather' );

		// Clean up dummy plugin files.
		wp_delete_file( $lw_file );
		if ( is_dir( $lw_dir ) ) {
			rmdir( $lw_dir );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'downgrade_not_allowed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Test POST /store/install-beta endpoint permission checks.
	 */
	public function test_install_beta_endpoint_permissions() {
		wp_set_current_user( $this->subscriber_id );
		$request = new WP_REST_Request( 'POST', '/shaped-kit/v1/store/install-beta' );
		$request->set_param( 'slug', 'location-weather' );

		$response = rest_do_request( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test SelfUpdater::filter_update_plugins injects update when higher version exists.
	 */
	public function test_self_updater_injects_update_when_higher_version_in_catalog() {
		// Mock catalog entry for shaped-kit with higher version.
		add_filter(
			'pre_transient_shaped_kit_catalog_cache',
			function () {
				$cat = \ShapedKit\Store\CatalogData::get_bundled_catalog();
				foreach ( $cat['products'] as &$item ) {
					if ( 'shaped-kit' === $item['slug'] ) {
						$item['stable_version'] = '9.9.9';
						$item['package_url']    = 'https://api.shapedplugin.com/kit/v1/releases/shaped-kit-9.9.9.zip';
					}
				}
				return $cat;
			}
		);

		$transient = new \stdClass();
		$filtered  = \ShapedKit\Store\SelfUpdater::filter_update_plugins( $transient );

		$this->assertObjectHasProperty( 'response', $filtered );
		$this->assertNotEmpty( $filtered->response );

		$file = defined( 'SHAPED_KIT_FILE' ) ? plugin_basename( SHAPED_KIT_FILE ) : 'shaped-kit/shaped-kit.php';
		$this->assertArrayHasKey( $file, $filtered->response );
		$this->assertSame( '9.9.9', $filtered->response[ $file ]->new_version );
		$this->assertSame( 'https://api.shapedplugin.com/kit/v1/releases/shaped-kit-9.9.9.zip', $filtered->response[ $file ]->package );

		remove_all_filters( 'pre_transient_shaped_kit_catalog_cache' );
	}

	/**
	 * Test SelfUpdater::filter_plugins_api returns plugin information.
	 */
	public function test_self_updater_plugins_api_returns_information() {
		$args       = (object) array( 'slug' => 'shaped-kit' );
		$plugin_inf = \ShapedKit\Store\SelfUpdater::filter_plugins_api( false, 'plugin_information', $args );

		$this->assertIsObject( $plugin_inf );
		$this->assertSame( 'Shaped Kit', $plugin_inf->name );
		$this->assertSame( 'shaped-kit', $plugin_inf->slug );
	}
}
