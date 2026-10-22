<?php
/**
 * Integration tests for what `GET /shaped-kit/v1/store/catalog` tells the Products & Store tab.
 *
 * Verifies (hub PRD H1):
 * - A Pro's license key never leaves the server, not even masked. Only whether a license exists and its status do.
 * - "Update available" is read from WordPress's own `update_plugins` list, by the plugin's real file (HE13: the main
 *   file is often not `{slug}.php`).
 * - The AI tools state comes from the `shaped_kit/products` registry, matched by product family.
 * - A beta is offered only when it is newer than what is installed.
 * - The sync time is when the catalog was really fetched, and a built-in list says it has none.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use ShapedKit\Rest\StoreController;
use ShapedKit\Store\CatalogClient;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Class StoreCatalogResponseIntegrationTest
 */
class StoreCatalogResponseIntegrationTest extends WP_UnitTestCase {

	const KEY = 'SECRET-LICENSE-KEY-0d2b';

	/**
	 * Folders a test made in the test library's plugins folder, removed in tearDown.
	 *
	 * @var string[]
	 */
	private $dirs = array();

	/**
	 * Set up: an administrator, the Store on, the routes registered.
	 */
	public function setUp(): void {
		parent::setUp();

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		add_filter( 'shaped_kit/store_live', '__return_true' );

		if ( ! has_action( 'rest_api_init', array( StoreController::class, 'register_routes' ) ) ) {
			StoreController::register();
		}
		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * Tear down: remove the folders, the options, the filters.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_transient_shaped_kit_catalog_cache' );
		remove_all_filters( 'shaped_kit/products' );
		remove_all_filters( 'pre_http_request' );
		delete_transient( CatalogClient::TRANSIENT_CATALOG_CACHE );
		delete_transient( CatalogClient::TRANSIENT_FETCHED_AT );
		delete_transient( CatalogClient::TRANSIENT_THROTTLE );
		delete_site_transient( 'update_plugins' );
		delete_option( 'location_weather_settings' );
		delete_option( 'location_weather_license_key_status' );

		foreach ( $this->dirs as $dir ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
		wp_clean_plugins_cache( false );

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Make `$products` the catalog the Store reads.
	 *
	 * @param array $products Catalog entries.
	 */
	private function catalog( array $products ) {
		add_filter(
			'pre_transient_shaped_kit_catalog_cache',
			function () use ( $products ) {
				return array(
					'serial'     => 1,
					'expires_at' => '2030-01-01T00:00:00Z',
					'products'   => $products,
				);
			}
		);
	}

	/**
	 * A catalog entry.
	 *
	 * @param string $slug    Plugin folder.
	 * @param string $edition free or pro.
	 * @param array  $over    Fields to change.
	 * @return array
	 */
	private function entry( $slug, $edition, array $over = array() ) {
		return $over + array(
			'slug'           => $slug,
			'name'           => $slug,
			'edition'        => $edition,
			'product_family' => 'location-weather',
			'description'    => '',
			'stable_version' => '3.0.7',
			'stable_sha256'  => str_repeat( '0', 64 ),
			'beta_version'   => null,
			'beta_sha256'    => null,
		);
	}

	/**
	 * Install a stand-in plugin: a folder with one main file.
	 *
	 * @param string $slug      Folder name.
	 * @param string $main_file Main file name inside it (often not `{slug}.php`).
	 * @param string $version   Version.
	 * @param string $name      Plugin name in the header (defaults to the slug).
	 * @return string The plugin file as WordPress knows it, e.g. `location-weather/main.php`.
	 */
	private function install_plugin( $slug, $main_file, $version, $name = '' ) {
		$dir  = WP_PLUGIN_DIR . '/' . $slug;
		$name = '' === $name ? $slug : $name;
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/' . $main_file, "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n */\n" );
		$this->dirs[] = $dir;
		wp_clean_plugins_cache( false );

		return $slug . '/' . $main_file;
	}

	/**
	 * The Store's answer, keyed by plugin slug.
	 *
	 * @return array<string, array>
	 */
	private function answer() {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/shaped-kit/v1/store/catalog' ) );
		$this->assertSame( 200, $response->get_status() );

		$by_slug = array();
		foreach ( $response->get_data()['products'] as $product ) {
			$by_slug[ $product['slug'] ] = $product;
		}

		return $by_slug;
	}

	/**
	 * The license key, masked or not, does not reach the browser. Whether a license exists, and its status, do.
	 */
	public function test_the_license_key_never_leaves_the_server() {
		update_option( 'location_weather_settings', array( 'license_key' => self::KEY ) );
		update_option( 'location_weather_license_key_status', 'valid' );
		$this->catalog(
			array(
				$this->entry( 'location-weather', 'free' ),
				$this->entry(
					'location-weather-pro',
					'pro',
					array(
						'license_option'        => 'location_weather_settings',
						'license_key_field'     => 'license_key',
						'license_status_option' => 'location_weather_license_key_status',
					)
				),
			)
		);

		$response = rest_do_request( new WP_REST_Request( 'GET', '/shaped-kit/v1/store/catalog' ) );
		$json     = (string) wp_json_encode( $response->get_data() );
		$pro      = $this->answer()['location-weather-pro'];

		$this->assertTrue( $pro['has_license'] );
		$this->assertSame( 'valid', $pro['license_status'] );
		$this->assertArrayNotHasKey( 'masked_license', $pro );
		$this->assertStringNotContainsString( self::KEY, $json );
		$this->assertStringNotContainsString( '0d2b', $json, 'Not even the last characters.' );
		$this->assertStringNotContainsString( '••••', $json );
	}

	/**
	 * "Update available" comes from WordPress's own list and is matched by the plugin's real file, so a plugin whose
	 * main file is `main.php` (not `{slug}.php`) is found.
	 */
	public function test_an_update_is_read_from_wordpress_by_the_real_plugin_file() {
		$file = $this->install_plugin( 'location-weather', 'main.php', '3.0.0' );
		$this->install_plugin( 'logo-carousel-free', 'plugin-main.php', '3.6.0' );
		$this->catalog(
			array(
				$this->entry( 'location-weather', 'free' ),
				$this->entry( 'logo-carousel-free', 'free', array( 'product_family' => 'logo-carousel' ) ),
			)
		);

		set_site_transient(
			'update_plugins',
			(object) array(
				'response' => array(
					$file => (object) array( 'new_version' => '3.1.0' ),
				),
			)
		);

		$answer = $this->answer();

		$this->assertSame( 'location-weather/main.php', $file );
		$this->assertTrue( $answer['location-weather']['installed'] );
		$this->assertTrue( $answer['location-weather']['has_update'] );
		$this->assertSame( '3.1.0', $answer['location-weather']['update_version'] );
		$this->assertFalse( $answer['logo-carousel-free']['has_update'], 'A plugin WordPress lists no update for has none.' );
		$this->assertNull( $answer['logo-carousel-free']['update_version'] );
	}

	/**
	 * A plugin that is not installed never shows an update, even if WordPress lists one under its folder name.
	 */
	public function test_a_plugin_that_is_not_installed_shows_no_update() {
		$this->catalog( array( $this->entry( 'location-weather', 'free' ) ) );
		set_site_transient(
			'update_plugins',
			(object) array(
				'response' => array(
					'location-weather/main.php' => (object) array( 'new_version' => '9.9.9' ),
				),
			)
		);

		$product = $this->answer()['location-weather'];

		$this->assertFalse( $product['installed'] );
		$this->assertFalse( $product['has_update'] );
	}

	/**
	 * A Free entry that names its Pro (`pro_slug`) when the catalog has no entry for that Pro.
	 *
	 * @param array $over Fields to change on the Free entry.
	 * @return array
	 */
	private function free_naming_a_pro( array $over = array() ) {
		return $this->entry(
			'wp-carousel-free',
			'free',
			$over + array(
				'product_family' => 'wp-carousel',
				'pro_slug'       => 'wp-carousel-pro',
				'description'    => 'Carousels.',
				'priority'       => 20,
			)
		);
	}

	/**
	 * A Pro that is installed but not in the catalog is added to its Free's family, with its own version, state and
	 * update. Nothing reads a license for it and nothing can be installed or started from it.
	 */
	public function test_an_installed_pro_the_catalog_does_not_list_is_added_to_its_family() {
		$file = $this->install_plugin( 'wp-carousel-pro', 'wp-carousel-pro.php', '5.0.1', 'WP Carousel Pro' );
		update_option( 'active_plugins', array( $file ) );
		set_site_transient(
			'update_plugins',
			(object) array(
				'response' => array(
					$file => (object) array( 'new_version' => '5.0.2' ),
				),
			)
		);
		$this->catalog( array( $this->free_naming_a_pro() ) );

		$pro = $this->answer()['wp-carousel-pro'];

		$this->assertSame( 'pro', $pro['edition'] );
		$this->assertSame( 'wp-carousel', $pro['product_family'] );
		$this->assertSame( 'WP Carousel Pro', $pro['name'] );
		$this->assertSame( 'Carousels.', $pro['description'] );
		$this->assertTrue( $pro['detected'] );
		$this->assertTrue( $pro['installed'] );
		$this->assertTrue( $pro['active'] );
		$this->assertSame( '5.0.1', $pro['active_version'] );
		$this->assertTrue( $pro['has_update'] );
		$this->assertSame( '5.0.2', $pro['update_version'] );
		$this->assertFalse( $pro['can_install'] );
		$this->assertFalse( $pro['beta_newer'] );
		$this->assertArrayNotHasKey( 'has_license', $pro, 'No license is read for a Pro the catalog does not describe.' );
		$this->assertArrayNotHasKey( 'license_status', $pro );
	}

	/**
	 * An inactive Pro shows as inactive, and a Pro that is not installed adds nothing.
	 */
	public function test_a_pro_that_is_inactive_or_absent_is_reported_as_it_is() {
		$this->catalog( array( $this->free_naming_a_pro() ) );
		$this->assertArrayNotHasKey( 'wp-carousel-pro', $this->answer(), 'Not installed: no row.' );

		$this->install_plugin( 'wp-carousel-pro', 'wp-carousel-pro.php', '5.0.1', 'WP Carousel Pro' );
		$pro = $this->answer()['wp-carousel-pro'];

		$this->assertTrue( $pro['installed'] );
		$this->assertFalse( $pro['active'] );
		$this->assertFalse( $pro['has_update'] );
	}

	/**
	 * Only a folder the catalog names is recognised. A plugin that merely looks like a Pro is not claimed.
	 */
	public function test_a_plugin_the_catalog_does_not_name_is_not_taken_for_a_pro() {
		$this->install_plugin( 'wp-carousel-pro', 'wp-carousel-pro.php', '5.0.1' );
		$this->install_plugin( 'logo-carousel-pro', 'logo-carousel-pro.php', '4.1.5' );
		$this->catalog( array( $this->free_naming_a_pro() ) );

		$answer = $this->answer();

		$this->assertArrayHasKey( 'wp-carousel-pro', $answer );
		$this->assertArrayNotHasKey( 'logo-carousel-pro', $answer );
	}

	/**
	 * When the catalog lists the Pro as its own entry, that entry is the row. No second, detected one is added, and the
	 * license is read as usual.
	 */
	public function test_a_pro_with_its_own_catalog_entry_is_not_detected_again() {
		$this->install_plugin( 'wp-carousel-pro', 'wp-carousel-pro.php', '5.0.1' );
		$this->catalog(
			array(
				$this->free_naming_a_pro(),
				$this->entry( 'wp-carousel-pro', 'pro', array( 'product_family' => 'wp-carousel' ) ),
			)
		);

		$response = rest_do_request( new WP_REST_Request( 'GET', '/shaped-kit/v1/store/catalog' ) );
		$rows     = array_values(
			array_filter(
				$response->get_data()['products'],
				function ( $product ) {
					return 'wp-carousel-pro' === $product['slug'];
				}
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertArrayNotHasKey( 'detected', $rows[0] );
		$this->assertArrayHasKey( 'has_license', $rows[0] );
	}

	/**
	 * Two Free entries naming the same Pro add one row, not two.
	 */
	public function test_two_frees_naming_one_pro_add_a_single_row() {
		$this->install_plugin( 'wp-carousel-pro', 'wp-carousel-pro.php', '5.0.1' );
		$this->catalog(
			array(
				$this->free_naming_a_pro(),
				$this->entry(
					'wp-carousel-lite',
					'free',
					array(
						'product_family' => 'wp-carousel-lite',
						'pro_slug'       => 'wp-carousel-pro',
					)
				),
			)
		);

		$response = rest_do_request( new WP_REST_Request( 'GET', '/shaped-kit/v1/store/catalog' ) );
		$slugs    = wp_list_pluck( $response->get_data()['products'], 'slug' );

		$this->assertSame( 1, count( array_keys( $slugs, 'wp-carousel-pro', true ) ) );
	}

	/**
	 * The AI tools state is the registry's, matched by product family: on with a count, off, or absent.
	 */
	public function test_ai_tools_state_comes_from_the_registry_by_product_family() {
		add_filter(
			'shaped_kit/products',
			function ( $products ) {
				$products[] = array(
					'slug'        => 'location-weather',
					'name'        => 'Location Weather',
					'edition'     => 'pro',
					'mcp_enabled' => true,
					'tools_count' => 11,
				);
				$products[] = array(
					'slug'        => 'real-testimonials',
					'name'        => 'Real Testimonials',
					'edition'     => 'free',
					'mcp_enabled' => false,
					'tools_count' => 16,
				);

				return $products;
			}
		);
		$this->catalog(
			array(
				$this->entry( 'location-weather', 'free' ),
				$this->entry( 'testimonial-free', 'free', array( 'product_family' => 'real-testimonials' ) ),
				$this->entry( 'styble', 'free', array( 'product_family' => 'styble' ) ),
			)
		);

		$answer = $this->answer();

		$this->assertTrue( $answer['location-weather']['mcp_active'] );
		$this->assertTrue( $answer['location-weather']['mcp_enabled'] );
		$this->assertSame( 11, $answer['location-weather']['mcp_tools_count'] );

		$this->assertTrue( $answer['testimonial-free']['mcp_active'] );
		$this->assertFalse( $answer['testimonial-free']['mcp_enabled'] );
		$this->assertSame( 16, $answer['testimonial-free']['mcp_tools_count'] );

		$this->assertFalse( $answer['styble']['mcp_active'], 'A plugin with no registry entry has no AI tools.' );
		$this->assertSame( 0, $answer['styble']['mcp_tools_count'] );
	}

	/**
	 * Put products in the MCP registry, as their modules would.
	 *
	 * @param array<string, array{0: bool, 1: int}> $states Registry slug => [ on, tools count ].
	 */
	private function register_mcp( array $states ) {
		add_filter(
			'shaped_kit/products',
			function ( $products ) use ( $states ) {
				foreach ( $states as $slug => $state ) {
					$products[] = array(
						'slug'        => $slug,
						'name'        => $slug,
						'edition'     => 'free',
						'mcp_enabled' => $state[0],
						'tools_count' => $state[1],
					);
				}

				return $products;
			}
		);
	}

	/**
	 * A family named after the Free folder (`logo-carousel-free`) still finds a module that registers the product's
	 * own name (`logo-carousel`), for the Free's row and for a detected Pro's. Without it the row says no AI tools while
	 * the product has them on.
	 */
	public function test_ai_tools_are_found_by_the_product_name_without_its_edition_suffix() {
		$this->register_mcp( array( 'logo-carousel' => array( true, 7 ) ) );
		$this->install_plugin( 'logo-carousel-pro', 'main.php', '4.1.5' );
		$this->catalog(
			array(
				$this->free_naming_a_pro(
					array(
						'slug'           => 'logo-carousel-free',
						'product_family' => 'logo-carousel-free',
						'pro_slug'       => 'logo-carousel-pro',
					)
				),
			)
		);

		$answer = $this->answer();

		foreach ( array( 'logo-carousel-free', 'logo-carousel-pro' ) as $slug ) {
			$this->assertTrue( $answer[ $slug ]['mcp_active'], $slug );
			$this->assertTrue( $answer[ $slug ]['mcp_enabled'], $slug );
			$this->assertSame( 7, $answer[ $slug ]['mcp_tools_count'], $slug );
		}
	}

	/**
	 * A module registered under the catalog row's own slug is found when the family is not registered.
	 */
	public function test_ai_tools_are_found_by_the_rows_own_slug() {
		$this->register_mcp( array( 'team-free' => array( false, 4 ) ) );
		$this->catalog( array( $this->entry( 'team-free', 'free', array( 'product_family' => 'team' ) ) ) );

		$row = $this->answer()['team-free'];

		$this->assertTrue( $row['mcp_active'] );
		$this->assertFalse( $row['mcp_enabled'] );
		$this->assertSame( 4, $row['mcp_tools_count'] );
	}

	/**
	 * The family is matched first: when both the family and the slug are registered, the family's state is shown.
	 * Only a trailing `-free` or `-pro` is dropped, so a product that merely shares a prefix is never matched.
	 */
	public function test_ai_tools_match_the_family_first_and_never_a_mere_prefix() {
		$this->register_mcp(
			array(
				'wp-carousel'      => array( true, 17 ),
				'wp-carousel-free' => array( false, 3 ),
				'logo-x'           => array( true, 9 ),
				'logo-carousel'    => array( true, 9 ),
			)
		);
		$this->catalog(
			array(
				$this->free_naming_a_pro(),
				$this->entry( 'logo-carousel-lite', 'free', array( 'product_family' => 'logo-carousel-lite' ) ),
				$this->entry( 'logo-free-pro-x', 'free', array( 'product_family' => 'logo-free-pro-x' ) ),
			)
		);

		$answer = $this->answer();

		$this->assertTrue( $answer['wp-carousel-free']['mcp_enabled'] );
		$this->assertSame( 17, $answer['wp-carousel-free']['mcp_tools_count'] );
		$this->assertFalse( $answer['logo-carousel-lite']['mcp_active'], 'A "-lite" suffix is not an edition.' );
		$this->assertFalse( $answer['logo-free-pro-x']['mcp_active'], 'An edition word inside the name is not a suffix.' );
	}

	/**
	 * A beta is offered only when it is newer than what is installed (or nothing is). An equal or older target would be
	 * refused by the no-downgrade rule, so the screen must not offer it. A stable release is above its own beta.
	 */
	public function test_a_beta_is_offered_only_when_it_is_newer_than_what_is_installed() {
		$this->install_plugin( 'location-weather', 'main.php', '3.0.7' );
		$this->install_plugin( 'location-weather-pro', 'main.php', '3.5.0' );
		$this->install_plugin( 'testimonial-free', 'main.php', '3.1.0-beta1' );
		$this->install_plugin( 'easy-accordion-free', 'plugin-main.php', '3.1.0' );
		$beta = '3.1.0-beta1';
		$this->catalog(
			array(
				$this->entry( 'location-weather', 'free', array( 'beta_version' => $beta ) ),
				$this->entry( 'location-weather-pro', 'pro', array( 'beta_version' => $beta ) ),
				$this->entry(
					'testimonial-free',
					'free',
					array(
						'product_family' => 'real-testimonials',
						'beta_version'   => $beta,
					)
				),
				$this->entry(
					'easy-accordion-free',
					'free',
					array(
						'product_family' => 'easy-accordion',
						'beta_version'   => $beta,
					)
				),
				$this->entry(
					'woo-quickview',
					'free',
					array(
						'product_family' => 'woo-quickview',
						'beta_version'   => '1.0.0-beta1',
					)
				),
				$this->entry( 'styble', 'free', array( 'product_family' => 'styble' ) ),
			)
		);

		$answer = $this->answer();

		$this->assertTrue( $answer['location-weather']['beta_newer'], 'Installed 3.0.7 is below 3.1.0-beta1.' );
		$this->assertFalse( $answer['location-weather-pro']['beta_newer'], 'Installed 3.5.0 is above 3.1.0-beta1.' );
		$this->assertFalse( $answer['testimonial-free']['beta_newer'], 'The same version is not newer.' );
		$this->assertFalse( $answer['easy-accordion-free']['beta_newer'], '3.1.0 is above 3.1.0-beta1.' );
		$this->assertTrue( $answer['woo-quickview']['beta_newer'], 'Nothing installed: any beta is newer.' );
		$this->assertFalse( $answer['styble']['beta_newer'], 'No beta in the catalog.' );
	}

	/**
	 * The sync time is the time the catalog was fetched, not the time of this request, and it is shown in the site's
	 * own time format.
	 */
	public function test_the_sync_time_is_when_the_catalog_was_fetched() {
		$then = time() - 3 * HOUR_IN_SECONDS;
		CatalogClient::remember_remote(
			array(
				'serial'     => 1,
				'expires_at' => '2030-01-01T00:00:00Z',
				'products'   => array( $this->entry( 'location-weather', 'free' ) ),
			)
		);
		set_transient( CatalogClient::TRANSIENT_FETCHED_AT, $then, HOUR_IN_SECONDS );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/shaped-kit/v1/store/catalog' ) )->get_data();

		$this->assertSame( 'remote', $data['catalog_source'] );
		$this->assertSame( $then, $data['synced_at'], 'The stored fetch time, not now.' );
		$this->assertSame( wp_date( get_option( 'time_format' ), $then ), $data['synced_label'] );
	}

	/**
	 * `remember_remote()` stores the catalog and the moment, together.
	 */
	public function test_a_remembered_catalog_stores_its_fetch_time_with_it() {
		$before = time();
		CatalogClient::remember_remote(
			array(
				'serial'     => 1,
				'expires_at' => '2030-01-01T00:00:00Z',
				'products'   => array( $this->entry( 'location-weather', 'free' ) ),
			)
		);

		$this->assertIsArray( get_transient( CatalogClient::TRANSIENT_CATALOG_CACHE ) );
		$this->assertGreaterThanOrEqual( $before, get_transient( CatalogClient::TRANSIENT_FETCHED_AT ) );
		$this->assertSame( 'remote', CatalogClient::sync_state()['source'] );
	}

	/**
	 * A catalog cached before the fetch time was kept is still a remote one; it just has no time to show.
	 */
	public function test_a_cached_catalog_with_no_stored_fetch_time_shows_no_time() {
		set_transient(
			CatalogClient::TRANSIENT_CATALOG_CACHE,
			array(
				'serial'     => 1,
				'expires_at' => '2030-01-01T00:00:00Z',
				'products'   => array( $this->entry( 'location-weather', 'free' ) ),
			),
			HOUR_IN_SECONDS
		);

		$data = rest_do_request( new WP_REST_Request( 'GET', '/shaped-kit/v1/store/catalog' ) )->get_data();

		$this->assertSame( 'remote', $data['catalog_source'] );
		$this->assertNull( $data['synced_at'] );
		$this->assertSame( '', $data['synced_label'] );
	}

	/**
	 * The built-in list was never fetched: it says so, with no time.
	 */
	public function test_a_built_in_list_has_no_sync_time() {
		add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'offline' );
			}
		);

		$data = rest_do_request( new WP_REST_Request( 'GET', '/shaped-kit/v1/store/catalog' ) )->get_data();

		$this->assertSame( 'builtin', $data['catalog_source'] );
		$this->assertNull( $data['synced_at'] );
		$this->assertSame( '', $data['synced_label'] );
		$this->assertNotEmpty( $data['products'], 'The built-in list is still shown.' );
	}

	/**
	 * A forced refresh that fails, with an older good catalog still cached, serves the built-in list. The answer must
	 * say so and not claim the older catalog's time for data it is not showing.
	 */
	public function test_a_failed_refresh_does_not_claim_the_older_catalogs_time() {
		CatalogClient::remember_remote(
			array(
				'serial'     => 1,
				'expires_at' => '2030-01-01T00:00:00Z',
				'products'   => array( $this->entry( 'location-weather', 'free' ) ),
			)
		);
		add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'offline' );
			}
		);

		$request = new WP_REST_Request( 'GET', '/shaped-kit/v1/store/catalog' );
		$request->set_param( 'refresh', 1 );
		$data = rest_do_request( $request )->get_data();

		$this->assertSame( 'builtin', $data['catalog_source'] );
		$this->assertNull( $data['synced_at'] );
	}

	/**
	 * A plugin that needs another says which one is not active: absent, installed but inactive, or active.
	 */
	public function test_a_missing_or_inactive_dependency_is_reported_by_name() {
		$this->catalog(
			array(
				$this->entry(
					'woo-one',
					'free',
					array(
						'product_family'   => 'woo-one',
						'requires_plugins' => array( 'woocommerce' ),
					)
				),
				$this->entry( 'plain', 'free', array( 'product_family' => 'plain' ) ),
			)
		);

		$this->assertSame( array( 'WooCommerce' ), $this->answer()['woo-one']['unmet_plugins'], 'Absent: the name WordPress would show.' );
		$this->assertSame( array(), $this->answer()['plain']['unmet_plugins'], 'It needs nothing.' );

		$file = $this->install_plugin( 'woocommerce', 'woocommerce.php', '9.0.0', 'WooCommerce' );
		$this->assertSame( array( 'WooCommerce' ), $this->answer()['woo-one']['unmet_plugins'], 'Installed but inactive still counts as missing.' );

		update_option( 'active_plugins', array( $file ) );
		$this->assertSame( array(), $this->answer()['woo-one']['unmet_plugins'], 'Active: nothing is missing.' );
	}

	/**
	 * A dependency active for the whole network counts as active on a multisite. (On a single site the same case is the
	 * ordinary active plugin.)
	 */
	public function test_a_dependency_active_for_the_whole_network_counts_as_active() {
		$this->catalog(
			array(
				$this->entry(
					'woo-one',
					'free',
					array(
						'product_family'   => 'woo-one',
						'requires_plugins' => array( 'woocommerce' ),
					)
				),
			)
		);
		$file = $this->install_plugin( 'woocommerce', 'woocommerce.php', '9.0.0', 'WooCommerce' );

		if ( is_multisite() ) {
			update_site_option( 'active_sitewide_plugins', array( $file => time() ) );
		} else {
			update_option( 'active_plugins', array( $file ) );
		}

		$this->assertSame( array(), $this->answer()['woo-one']['unmet_plugins'] );

		if ( is_multisite() ) {
			delete_site_option( 'active_sitewide_plugins' );
		}
	}

	/**
	 * A pre-release site counts as its release: 6.9-beta2 meets "needs 6.9" and still falls short of "needs 7.0".
	 */
	public function test_a_pre_release_site_counts_as_its_release_version() {
		$this->catalog(
			array(
				$this->entry(
					'needs-six-nine',
					'free',
					array(
						'product_family' => 'needs-six-nine',
						'requires_wp'    => '6.9',
					)
				),
				$this->entry(
					'needs-seven',
					'free',
					array(
						'product_family' => 'needs-seven',
						'requires_wp'    => '7.0',
					)
				),
			)
		);

		$real                  = $GLOBALS['wp_version'];
		$GLOBALS['wp_version'] = '6.9-beta2';
		try {
			$answer = $this->answer();
		} finally {
			$GLOBALS['wp_version'] = $real;
		}

		$this->assertNull( $answer['needs-six-nine']['needs_wp'], '6.9-beta2 is 6.9, not older than it.' );
		$this->assertSame( '7.0', $answer['needs-seven']['needs_wp'] );
	}

	/**
	 * The site's PHP and WordPress are compared with what the plugin asks for. No stated requirement means none.
	 */
	public function test_a_site_that_is_too_old_is_reported_with_the_version_needed() {
		$this->catalog(
			array(
				$this->entry(
					'future',
					'free',
					array(
						'product_family' => 'future',
						'requires_php'   => '99.0',
						'requires_wp'    => '99.0',
					)
				),
				$this->entry(
					'fits',
					'free',
					array(
						'product_family' => 'fits',
						'requires_php'   => '5.6',
						'requires_wp'    => '4.0',
					)
				),
				$this->entry( 'silent', 'free', array( 'product_family' => 'silent' ) ),
			)
		);

		$answer = $this->answer();

		$this->assertSame( '99.0', $answer['future']['needs_php'] );
		$this->assertSame( '99.0', $answer['future']['needs_wp'] );
		$this->assertNull( $answer['fits']['needs_php'] );
		$this->assertNull( $answer['fits']['needs_wp'] );
		$this->assertNull( $answer['silent']['needs_php'], 'No requirement stated.' );
		$this->assertNull( $answer['silent']['needs_wp'] );
	}

	/**
	 * AI tools the catalog promises: for a plugin not installed, and for one older than the first release that has them.
	 * Once the plugin is new enough, the live registry decides and the catalog says nothing more.
	 */
	public function test_ai_tools_the_catalog_promises_are_split_by_what_is_installed() {
		$this->install_plugin( 'old-one', 'old-one.php', '4.0.0' );
		$this->install_plugin( 'new-one', 'new-one.php', '4.1.5' );
		$this->catalog(
			array(
				$this->entry(
					'fresh',
					'free',
					array(
						'product_family' => 'fresh',
						'stable_version' => '4.2.0',
						'mcp_since'      => '4.1.0',
					)
				),
				$this->entry(
					'old-one',
					'free',
					array(
						'product_family' => 'old-one',
						'stable_version' => '4.2.0',
						'mcp_since'      => '4.1.0',
					)
				),
				$this->entry(
					'new-one',
					'free',
					array(
						'product_family' => 'new-one',
						'stable_version' => '4.2.0',
						'mcp_since'      => '4.1.0',
					)
				),
				$this->entry(
					'too-early',
					'free',
					array(
						'product_family' => 'too-early',
						'stable_version' => '4.0.0',
						'mcp_since'      => '4.1.0',
					)
				),
				$this->entry(
					'never',
					'free',
					array(
						'product_family' => 'never',
						'stable_version' => '4.2.0',
					)
				),
			)
		);

		$answer = $this->answer();

		$this->assertTrue( $answer['fresh']['mcp_available'], 'Not installed, and the version offered has AI tools.' );
		$this->assertFalse( $answer['fresh']['mcp_after_update'] );

		$this->assertFalse( $answer['old-one']['mcp_available'] );
		$this->assertTrue( $answer['old-one']['mcp_after_update'], 'Installed 4.0.0, AI tools from 4.1.0, 4.2.0 is offered.' );

		$this->assertFalse( $answer['new-one']['mcp_available'], 'Already new enough: the registry decides.' );
		$this->assertFalse( $answer['new-one']['mcp_after_update'] );

		$this->assertFalse( $answer['too-early']['mcp_available'], 'The version offered has none yet.' );
		$this->assertFalse( $answer['never']['mcp_available'], 'No promise in the catalog.' );
		$this->assertFalse( $answer['never']['mcp_after_update'] );
	}
}
