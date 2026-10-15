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
use ShapedKit\Store\SelfUpdater;
use ShapedKit\Store\StoreGate;
use ShapedKit\Rest\StoreController;
use WP_REST_Request;
use WP_UnitTestCase;
use WP_Upgrader;

require_once dirname( __DIR__, 3 ) . '/tools/sign-catalog.php';

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

		// The Store is off by default (StoreGate). These tests are about what it does when it is on.
		add_filter( 'shaped_kit/store_live', '__return_true' );

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

	/**
	 * Test SelfUpdater::filter_upgrader_pre_download ignores other plugins.
	 */
	public function test_self_updater_pre_download_ignores_other_plugins() {
		$reply = \ShapedKit\Store\SelfUpdater::filter_upgrader_pre_download(
			false,
			'https://downloads.wordpress.org/plugin/other.zip',
			new \stdClass(),
			array( 'plugin' => 'other/other.php' )
		);
		$this->assertFalse( $reply );
	}

	/**
	 * Test SelfUpdater::filter_upgrader_pre_download fails closed when checksum is missing.
	 */
	public function test_self_updater_pre_download_fails_closed_when_checksum_missing() {
		$kit_file = defined( 'SHAPED_KIT_FILE' ) ? plugin_basename( SHAPED_KIT_FILE ) : 'shaped-kit/shaped-kit.php';

		// Seed catalog entry with missing checksum.
		add_filter(
			'pre_transient_shaped_kit_catalog_cache',
			function () {
				return array(
					'products' => array(
						array(
							'slug'          => 'shaped-kit',
							'stable_sha256' => '',
						),
					),
				);
			}
		);

		$result = \ShapedKit\Store\SelfUpdater::filter_upgrader_pre_download(
			false,
			'https://api.shapedplugin.com/kit/v1/releases/shaped-kit.zip',
			new \stdClass(),
			array( 'plugin' => $kit_file )
		);

		remove_all_filters( 'pre_transient_shaped_kit_catalog_cache' );

		$this->assertWPError( $result );
		$this->assertSame( 'unverified_package', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test SelfUpdater::filter_upgrader_pre_download blocks unauthorized download host.
	 */
	public function test_self_updater_pre_download_blocks_unauthorized_host() {
		$kit_file = defined( 'SHAPED_KIT_FILE' ) ? plugin_basename( SHAPED_KIT_FILE ) : 'shaped-kit/shaped-kit.php';

		add_filter(
			'pre_transient_shaped_kit_catalog_cache',
			function () {
				return array(
					'products' => array(
						array(
							'slug'          => 'shaped-kit',
							'stable_sha256' => str_repeat( 'a', 64 ),
						),
					),
				);
			}
		);

		$result = \ShapedKit\Store\SelfUpdater::filter_upgrader_pre_download(
			false,
			'https://evil-attacker.com/malicious-shaped-kit.zip',
			new \stdClass(),
			array( 'plugin' => $kit_file )
		);

		remove_all_filters( 'pre_transient_shaped_kit_catalog_cache' );

		$this->assertWPError( $result );
		$this->assertSame( 'unauthorized_download_host', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test SelfUpdater::filter_upgrader_pre_download blocks checksum mismatch and unlinks file.
	 */
	public function test_self_updater_pre_download_blocks_checksum_mismatch() {
		$kit_file = defined( 'SHAPED_KIT_FILE' ) ? plugin_basename( SHAPED_KIT_FILE ) : 'shaped-kit/shaped-kit.php';
		$fake_zip = 'PKfake_kit_bytes_tampered';

		add_filter(
			'pre_http_request',
			function () use ( $fake_zip ) {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => $fake_zip,
					'headers'  => array(),
				);
			}
		);

		add_filter(
			'pre_transient_shaped_kit_catalog_cache',
			function () {
				return array(
					'products' => array(
						array(
							'slug'          => 'shaped-kit',
							'stable_sha256' => hash( 'sha256', 'expected_different_content' ),
						),
					),
				);
			}
		);

		$result = \ShapedKit\Store\SelfUpdater::filter_upgrader_pre_download(
			false,
			'https://api.shapedplugin.com/kit/v1/releases/shaped-kit.zip',
			new \stdClass(),
			array( 'plugin' => $kit_file )
		);

		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pre_transient_shaped_kit_catalog_cache' );

		$this->assertWPError( $result );
		$this->assertSame( 'checksum_mismatch', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	/**
	 * Test SelfUpdater::filter_upgrader_pre_download verifies checksum and returns verified temp file.
	 */
	public function test_self_updater_pre_download_verifies_checksum_and_returns_file() {
		$kit_file   = defined( 'SHAPED_KIT_FILE' ) ? plugin_basename( SHAPED_KIT_FILE ) : 'shaped-kit/shaped-kit.php';
		$valid_zip  = 'PKvalid_shaped_kit_binary_package';
		$valid_hash = hash( 'sha256', $valid_zip );

		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $valid_zip ) {
				if ( ! empty( $args['filename'] ) ) {
					file_put_contents( $args['filename'], $valid_zip );
				}
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => $valid_zip,
					'headers'  => array(),
				);
			},
			10,
			2
		);

		add_filter(
			'pre_transient_shaped_kit_catalog_cache',
			function () use ( $valid_hash ) {
				return array(
					'products' => array(
						array(
							'slug'          => 'shaped-kit',
							'stable_sha256' => $valid_hash,
						),
					),
				);
			}
		);

		$result = \ShapedKit\Store\SelfUpdater::filter_upgrader_pre_download(
			false,
			'https://api.shapedplugin.com/kit/v1/releases/shaped-kit.zip',
			new \stdClass(),
			array( 'plugin' => $kit_file )
		);

		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pre_transient_shaped_kit_catalog_cache' );

		$this->assertIsString( $result );
		$this->assertFileExists( $result );
		$this->assertSame( $valid_hash, hash_file( 'sha256', $result ) );

		wp_delete_file( $result );
	}

	/**
	 * Test SelfUpdater::handle_force_check permission checks, nonce, cache flush and redirect.
	 */
	public function test_self_updater_handle_force_check() {
		// 1. Missing query param -> false.
		$this->assertFalse( \ShapedKit\Store\SelfUpdater::handle_force_check( false ) );

		// 2. Subscriber without update_plugins cap -> false.
		wp_set_current_user( $this->subscriber_id );
		$_GET['shaped_kit_check_update']     = '1';
		$_REQUEST['shaped_kit_check_update'] = '1';
		$this->assertFalse( \ShapedKit\Store\SelfUpdater::handle_force_check( false ) );

		// 3. Admin user with valid nonce -> flushes transients and redirects.
		wp_set_current_user( $this->admin_id );
		$nonce                = wp_create_nonce( 'shaped_kit_force_check_update' );
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		set_transient( \ShapedKit\Store\CatalogClient::TRANSIENT_CATALOG_CACHE, array( 'products' => array() ), 3600 );
		set_site_transient( 'update_plugins', (object) array( 'response' => array() ) );

		// The forced check goes out to the catalog host. Stub it, and count the requests.
		$requested = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$requested ) {
				$requested[] = $url;

				return new \WP_Error( 'stubbed', 'No network in tests.' );
			},
			10,
			3
		);

		$redirect_location = null;
		add_filter(
			'wp_redirect',
			function ( $location ) use ( &$redirect_location ) {
				$redirect_location = $location;
				return false;
			}
		);

		$executed = \ShapedKit\Store\SelfUpdater::handle_force_check( false );

		$this->assertTrue( $executed );
		$this->assertSame( array( CatalogClient::REMOTE_CATALOG_URL ), $requested, 'A forced check asks the catalog host again, once.' );
		$this->assertFalse( get_transient( \ShapedKit\Store\CatalogClient::TRANSIENT_CATALOG_CACHE ) );
		$this->assertFalse( get_site_transient( 'update_plugins' ) );
		$this->assertStringContainsString( 'plugins.php', (string) $redirect_location );
		$this->assertStringContainsString( 's=shaped-kit', (string) $redirect_location, 'Back to the list narrowed to the Kit.' );
		$this->assertStringContainsString( 'shaped_kit_checked=1', (string) $redirect_location );

		unset( $_GET['shaped_kit_check_update'], $_GET['_wpnonce'], $_REQUEST['shaped_kit_check_update'], $_REQUEST['_wpnonce'] );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Serve a catalog and its signature to `fetch_remote_catalog()` without any network.
	 *
	 * @param string $catalog_json The body for the catalog URL.
	 * @param string $signature    The body for the signature URL.
	 */
	private function serve_catalog( $catalog_json, $signature ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $catalog_json, $signature ) {
				$body = CatalogClient::REMOTE_SIGNATURE_URL === $url ? $signature : $catalog_json;

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => $body,
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * A catalog the client would accept: the bundled one, newer, not expired.
	 *
	 * @return string JSON.
	 */
	private function signed_catalog_json() {
		$catalog               = CatalogData::get_bundled_catalog();
		$catalog['serial']     = CatalogData::BUNDLED_SERIAL + 1;
		$catalog['expires_at'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + WEEK_IN_SECONDS );

		return (string) wp_json_encode( $catalog );
	}

	/**
	 * Key rotation, end to end: a catalog signed with the next key is accepted, one signed with an unpinned key is not.
	 */
	public function test_remote_catalog_is_accepted_with_the_rotation_key_and_refused_with_a_stranger() {
		$primary  = shaped_kit_generate_keypair();
		$next     = shaped_kit_generate_keypair();
		$stranger = shaped_kit_generate_keypair();
		$json     = $this->signed_catalog_json();
		$keys     = array( $primary['public'], $next['public'] );

		$this->serve_catalog( $json, shaped_kit_sign_catalog( $json, $next['private'] ) );
		$accepted = CatalogClient::fetch_remote_catalog( $keys );
		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $accepted );
		$this->assertSame( CatalogData::BUNDLED_SERIAL + 1, $accepted['serial'] );
		$this->assertSame( CatalogData::BUNDLED_SERIAL + 1, (int) get_option( CatalogClient::OPTION_LAST_SERIAL ), 'The accepted serial is remembered.' );

		delete_option( CatalogClient::OPTION_LAST_SERIAL );
		$this->serve_catalog( $json, shaped_kit_sign_catalog( $json, $stranger['private'] ) );
		$refused = CatalogClient::fetch_remote_catalog( $keys );
		remove_all_filters( 'pre_http_request' );

		$this->assertNull( $refused );
		$this->assertFalse( get_option( CatalogClient::OPTION_LAST_SERIAL, false ), 'A refused catalog leaves no trace.' );
	}

	/**
	 * Replay: after a newer catalog was accepted, an older one signed with a good key is refused.
	 */
	public function test_remote_catalog_with_a_lower_serial_than_the_last_accepted_is_refused() {
		$keypair = shaped_kit_generate_keypair();
		$json    = $this->signed_catalog_json();

		update_option( CatalogClient::OPTION_LAST_SERIAL, CatalogData::BUNDLED_SERIAL + 50, false );
		$this->serve_catalog( $json, shaped_kit_sign_catalog( $json, $keypair['private'] ) );
		$result = CatalogClient::fetch_remote_catalog( array( $keypair['public'] ) );
		remove_all_filters( 'pre_http_request' );

		$this->assertNull( $result );
	}

	/**
	 * Every Store route, as method, path and body.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array}>
	 */
	public function store_routes() {
		return array(
			'catalog'      => array( 'GET', '/shaped-kit/v1/store/catalog', array() ),
			'install'      => array( 'POST', '/shaped-kit/v1/store/install', array( 'slug' => 'location-weather' ) ),
			'install-pro'  => array(
				'POST',
				'/shaped-kit/v1/store/install-pro',
				array(
					'slug'        => 'location-weather-pro',
					'license_key' => 'not-a-real-key',
				),
			),
			'activate'     => array( 'POST', '/shaped-kit/v1/store/activate', array( 'slug' => 'location-weather' ) ),
			'install-beta' => array( 'POST', '/shaped-kit/v1/store/install-beta', array( 'slug' => 'location-weather' ) ),
		);
	}

	/**
	 * Store off (the default): an administrator gets 503 from every Store route; anyone else gets the refusal
	 * they would have had anyway, so a stranger learns nothing about the Store.
	 *
	 * @dataProvider store_routes
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route.
	 * @param array  $body   Parameters.
	 */
	public function test_store_routes_answer_503_while_the_store_is_off( $method, $path, $body ) {
		remove_all_filters( 'shaped_kit/store_live' );
		$GLOBALS['wp_rest_server'] = null;

		$send = function () use ( $method, $path, $body ) {
			$request = new WP_REST_Request( $method, $path );
			$request->set_body_params( $body );

			return rest_do_request( $request );
		};

		$admin = $send();
		$this->assertSame( 503, $admin->get_status(), "$path must be off." );
		$this->assertSame( 'shaped_kit_store_not_live', $admin->as_error()->get_error_code() );

		wp_set_current_user( $this->subscriber_id );
		$this->assertSame( 403, $send()->get_status(), 'A subscriber is refused as before, not told the Store is off.' );

		wp_set_current_user( 0 );
		$this->assertContains( $send()->get_status(), array( 401, 403 ), 'Not signed in: refused as before, never 503 and never allowed.' );
	}

	/**
	 * Store off: no catalog request at all from the update pipeline, no update offered, no plugin details.
	 */
	public function test_self_updater_does_nothing_while_the_store_is_off() {
		remove_all_filters( 'shaped_kit/store_live' );

		$requested = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$requested ) {
				$requested[] = $url;

				return new \WP_Error( 'stubbed', 'No network in tests.' );
			},
			10,
			3
		);
		// A catalog that would offer an update, if anything read it.
		add_filter(
			'pre_transient_shaped_kit_catalog_cache',
			function () {
				$cat = CatalogData::get_bundled_catalog();
				foreach ( $cat['products'] as &$item ) {
					if ( 'shaped-kit' === $item['slug'] ) {
						$item['stable_version'] = '9.9.9';
					}
				}
				return $cat;
			}
		);

		$transient = SelfUpdater::filter_update_plugins( new \stdClass() );
		$info      = SelfUpdater::filter_plugins_api( false, 'plugin_information', (object) array( 'slug' => 'shaped-kit' ) );

		wp_set_current_user( $this->admin_id );
		$_GET['shaped_kit_check_update'] = '1';
		$forced                          = SelfUpdater::handle_force_check( false );
		unset( $_GET['shaped_kit_check_update'] );

		$this->assertObjectNotHasProperty( 'response', $transient, 'No update is offered.' );
		$this->assertFalse( $info, 'The details popup is left to core.' );
		$this->assertFalse( $forced );
		$this->assertSame( array(), $requested, 'Nothing was fetched.' );
	}

	/**
	 * Store on, but the catalog entry names no package: there is nothing to download, so no update is offered,
	 * and a notice left over from an earlier check is removed.
	 */
	public function test_an_update_without_a_package_is_not_offered() {
		$file = defined( 'SHAPED_KIT_FILE' ) ? plugin_basename( SHAPED_KIT_FILE ) : 'shaped-kit/shaped-kit.php';

		add_filter(
			'pre_transient_shaped_kit_catalog_cache',
			function () {
				$cat = CatalogData::get_bundled_catalog();
				foreach ( $cat['products'] as &$item ) {
					if ( 'shaped-kit' === $item['slug'] ) {
						$item['stable_version'] = '9.9.9';
						$item['package_url']    = null;
					}
				}
				return $cat;
			}
		);

		$stale           = new \stdClass();
		$stale->response = array( $file => (object) array( 'new_version' => '0.0.1' ) );
		$filtered        = SelfUpdater::filter_update_plugins( $stale );

		$this->assertArrayNotHasKey( $file, $filtered->response );
	}

	/**
	 * A file some other filter already downloaded for a Kit update.
	 *
	 * @param string $content What is in it.
	 * @return string Path.
	 */
	private function already_downloaded( $content ) {
		$path = wp_tempnam( 'shaped-kit-update.zip' );
		file_put_contents( $path, $content );

		return $path;
	}

	/**
	 * Run the pre-download filter for a Kit update with a catalog hash, and count outbound requests.
	 *
	 * @param mixed       $reply The reply an earlier filter gave.
	 * @param string|null $sha   The catalog's hash for the package.
	 * @param int         $requests Filled with the number of HTTP requests made.
	 * @return mixed
	 */
	private function pre_download( $reply, $sha, &$requests ) {
		$kit_file = defined( 'SHAPED_KIT_FILE' ) ? plugin_basename( SHAPED_KIT_FILE ) : 'shaped-kit/shaped-kit.php';
		$requests = 0;

		add_filter(
			'pre_http_request',
			function () use ( &$requests ) {
				++$requests;

				return new \WP_Error( 'stubbed', 'No network in tests.' );
			}
		);
		add_filter(
			'pre_transient_shaped_kit_catalog_cache',
			function () use ( $sha ) {
				return array(
					'products' => array(
						array(
							'slug'          => 'shaped-kit',
							'stable_sha256' => (string) $sha,
						),
					),
				);
			}
		);

		return SelfUpdater::filter_upgrader_pre_download(
			$reply,
			'https://api.shapedplugin.com/kit/v1/releases/shaped-kit.zip',
			new \stdClass(),
			array( 'plugin' => $kit_file )
		);
	}

	/**
	 * An earlier filter's refusal is kept, and the Kit does not fetch anything itself.
	 */
	public function test_pre_download_keeps_an_earlier_refusal() {
		$refusal = new \WP_Error( 'mirror_down', 'Mirror is down.' );

		$result = $this->pre_download( $refusal, hash( 'sha256', 'x' ), $requests );

		$this->assertSame( $refusal, $result );
		$this->assertSame( 0, $requests );
	}

	/**
	 * A package an earlier filter already downloaded is used if it matches the catalog hash, and not fetched again.
	 */
	public function test_pre_download_accepts_an_earlier_download_that_matches_the_catalog_hash() {
		$path = $this->already_downloaded( 'PKgood' );

		$result = $this->pre_download( $path, hash( 'sha256', 'PKgood' ), $requests );

		$this->assertSame( $path, $result );
		$this->assertFileExists( $path );
		$this->assertSame( 0, $requests, 'Nothing is downloaded twice.' );

		wp_delete_file( $path );
	}

	/**
	 * A package an earlier filter downloaded is not trusted: a wrong hash is refused and the file removed.
	 */
	public function test_pre_download_refuses_an_earlier_download_that_does_not_match() {
		$path = $this->already_downloaded( 'PKtampered' );

		$result = $this->pre_download( $path, hash( 'sha256', 'PKgood' ), $requests );

		$this->assertWPError( $result );
		$this->assertSame( 'checksum_mismatch', $result->get_error_code() );
		$this->assertFileDoesNotExist( $path );
		$this->assertSame( 0, $requests );
	}

	/**
	 * With no catalog hash an earlier download cannot be checked, so it is refused too.
	 */
	public function test_pre_download_refuses_an_earlier_download_when_the_catalog_has_no_hash() {
		$path = $this->already_downloaded( 'PKanything' );

		$result = $this->pre_download( $path, '', $requests );

		$this->assertWPError( $result );
		$this->assertSame( 'unverified_package', $result->get_error_code() );

		wp_delete_file( $path );
	}

	/**
	 * The "Check for update" link as the Plugins screen would build it for the Kit's row.
	 *
	 * @return string[] The row's meta links after the filter.
	 */
	private function row_links() {
		$kit_file = defined( 'SHAPED_KIT_FILE' ) ? plugin_basename( SHAPED_KIT_FILE ) : 'shaped-kit/shaped-kit.php';

		return SelfUpdater::filter_row_meta( array( 'Version 0.1.0' ), $kit_file );
	}

	/**
	 * The link is in the Kit's row, goes to the Plugins screen, and its nonce is the one the handler wants:
	 * following it, as the browser would, runs the check.
	 */
	public function test_the_check_for_update_link_is_in_the_kits_row_and_the_handler_accepts_it() {
		$links = $this->row_links();

		$this->assertArrayHasKey( 'shaped_kit_check_update', $links );
		$this->assertSame( 'Version 0.1.0', $links[0], 'The row keeps its other links.' );
		$this->assertSame( 1, preg_match( '/href="([^"]+)"/', $links['shaped_kit_check_update'], $href ) );

		$url = html_entity_decode( $href[1], ENT_QUOTES, 'UTF-8' );
		$this->assertStringContainsString( 'plugins.php?shaped_kit_check_update=1', $url );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_GET     = $query;
		$_REQUEST = $query;

		add_filter( 'pre_http_request', fn() => new \WP_Error( 'stubbed', 'No network in tests.' ) );
		add_filter( 'wp_redirect', '__return_false' );
		$ran = SelfUpdater::handle_force_check( false );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'pre_http_request' );
		$_GET     = array();
		$_REQUEST = array();

		$this->assertTrue( $ran, 'The link\'s own nonce passes the handler.' );
	}

	/**
	 * Other rows are left alone, and so is the Kit's row when the Store is off or the user cannot update plugins.
	 */
	public function test_the_check_for_update_link_is_only_where_it_would_work() {
		$this->assertSame( array( 'x' ), SelfUpdater::filter_row_meta( array( 'x' ), 'other/other.php' ) );

		wp_set_current_user( $this->subscriber_id );
		$this->assertArrayNotHasKey( 'shaped_kit_check_update', $this->row_links(), 'Not for someone the check would refuse.' );

		wp_set_current_user( $this->admin_id );
		remove_all_filters( 'shaped_kit/store_live' );
		$this->assertArrayNotHasKey( 'shaped_kit_check_update', $this->row_links(), 'Not while the Store is off: it would do nothing.' );
	}

	/**
	 * A forged or stale link is refused before anything is flushed or fetched (CSRF).
	 */
	public function test_the_force_check_refuses_a_wrong_nonce_and_changes_nothing() {
		wp_set_current_user( $this->admin_id );
		set_transient( CatalogClient::TRANSIENT_CATALOG_CACHE, array( 'products' => array() ), 3600 );

		$requests = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$requests ) {
				++$requests;

				return new \WP_Error( 'stubbed', 'No network in tests.' );
			}
		);

		$_GET['shaped_kit_check_update'] = '1';
		$_GET['_wpnonce']                = 'forged';
		$_REQUEST['_wpnonce']            = 'forged';

		try {
			SelfUpdater::handle_force_check( false );
			$this->fail( 'A wrong nonce must stop the request.' );
		} catch ( \WPDieException $e ) {
			$this->assertNotFalse( get_transient( CatalogClient::TRANSIENT_CATALOG_CACHE ), 'The cache was not flushed.' );
			$this->assertSame( 0, $requests, 'Nothing was fetched.' );
		} finally {
			unset( $_GET['shaped_kit_check_update'], $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );
			remove_all_filters( 'pre_http_request' );
		}
	}

	/**
	 * The "checked" notice shows on the Plugins screen for someone who may update plugins, says fixed text only,
	 * and shows nowhere else.
	 */
	public function test_the_checked_notice_shows_only_on_the_plugins_screen_for_the_right_user() {
		$render = function () {
			ob_start();
			SelfUpdater::checked_notice();

			return (string) ob_get_clean();
		};

		$_GET['shaped_kit_checked'] = '<script>alert(1)</script>';

		set_current_screen( 'plugins' );
		$on_plugins = $render();
		$this->assertStringContainsString( 'Checked for a Shaped Kit update', $on_plugins );
		$this->assertStringNotContainsString( 'alert(1)', $on_plugins, 'Nothing from the request is printed.' );

		set_current_screen( 'dashboard' );
		$this->assertSame( '', $render(), 'Not on other screens.' );

		set_current_screen( 'plugins' );
		wp_set_current_user( $this->subscriber_id );
		$this->assertSame( '', $render(), 'Not for someone who cannot update plugins.' );

		wp_set_current_user( $this->admin_id );
		unset( $_GET['shaped_kit_checked'] );
		$this->assertSame( '', $render(), 'Not unless the check just ran.' );

		set_current_screen( 'front' );
	}
}
