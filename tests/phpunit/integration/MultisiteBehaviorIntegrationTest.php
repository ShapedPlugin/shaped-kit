<?php
/**
 * Integration test verifying Multisite isolation, capability enforcement, and lifecycle (PRD §19 E21).
 *
 * Verifies that:
 * - Kill switches, audit log table prefixes, and module states are isolated per-site.
 * - Store installations enforce network admin capabilities on multisite (SE13).
 * - AdapterBootstrap status reporting functions on multisite installations.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use ShapedKit\Audit\LogTable;
use ShapedKit\Mcp\AdapterBootstrap;
use ShapedKit\Rest\StoreController;
use ShapedKit\Store\Installer;
use WP_UnitTestCase;

/**
 * Class MultisiteBehaviorIntegrationTest
 */
class MultisiteBehaviorIntegrationTest extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// No test in this class may reach the network: the store catalog falls back to the bundled copy.
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'shaped_kit_test_no_network', 'Outbound HTTP is blocked in tests.' );
			}
		);

		// These tests are about what the Store does when it is on (it is off by default, see StoreGate).
		add_filter( 'shaped_kit/store_live', '__return_true' );

		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	/**
	 * E21 / SE13: Store installation permission check on single-site vs multisite.
	 */
	public function test_installer_permission_enforcement() {
		if ( is_multisite() ) {
			// Subsite administrator is refused.
			$res = StoreController::can_install_plugins();
			$this->assertWPError( $res );
			$this->assertSame( 'multisite_subsite_forbidden', $res->get_error_code() );
			$this->assertSame( 403, $res->get_error_data()['status'] );

			$can = Installer::can_install();
			$this->assertWPError( $can );
			$this->assertSame( 'multisite_subsite_forbidden', $can->get_error_code() );

			// Super administrator is allowed.
			grant_super_admin( $this->user_id );
			$this->assertTrue( StoreController::can_install_plugins() );
			$this->assertTrue( Installer::can_install() );
		} else {
			// Single-site administrator is allowed.
			$this->assertTrue( StoreController::can_install_plugins() );
			$this->assertTrue( Installer::can_install() );
		}

		// As non-logged in or subscriber, permission fails with 403.
		wp_set_current_user( 0 );
		$res = StoreController::can_install_plugins();
		$this->assertWPError( $res );
		$this->assertSame( is_multisite() ? 'multisite_subsite_forbidden' : 'forbidden', $res->get_error_code() );
	}

	/**
	 * E21: Store catalog reports can_install = false for subsite admins on multisite.
	 */
	public function test_catalog_reports_can_install_correctly_for_current_user() {
		if ( is_multisite() ) {
			// Site admin without super admin cannot install.
			$request  = new \WP_REST_Request( 'GET', '/shaped-kit/v1/store/catalog' );
			$response = rest_do_request( $request );
			$data     = $response->get_data();
			$this->assertFalse( $data['products'][0]['can_install'] );

			// Super admin can install.
			grant_super_admin( $this->user_id );
			$response = rest_do_request( $request );
			$data     = $response->get_data();
			$this->assertTrue( $data['products'][0]['can_install'] );
		} else {
			$request  = new \WP_REST_Request( 'GET', '/shaped-kit/v1/store/catalog' );
			$response = rest_do_request( $request );
			$data     = $response->get_data();
			$this->assertTrue( $data['products'][0]['can_install'] );
		}
	}

	/**
	 * Everything the Kit stores per site that uninstall must remove: [ kind, name ].
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function stored_per_site() {
		return array(
			array( 'option', 'shaped_kit_mcp_log_db_version' ),
			array( 'transient', 'shaped_kit_mcp_log_prune_throttle' ),
			array( 'option', \ShapedKit\Store\CatalogClient::OPTION_LAST_SERIAL ),
			array( 'transient', \ShapedKit\Store\CatalogClient::TRANSIENT_CATALOG_CACHE ),
			array( 'transient', \ShapedKit\Store\CatalogClient::TRANSIENT_THROTTLE ),
			array( 'transient', \ShapedKit\Store\CatalogClient::TRANSIENT_FETCHED_AT ),
		);
	}

	/**
	 * Store a value for every item in stored_per_site() on the current site.
	 */
	private function store_everything() {
		foreach ( $this->stored_per_site() as list( $kind, $name ) ) {
			if ( 'option' === $kind ) {
				update_option( $name, 42 );
			} else {
				set_transient( $name, array( 'dummy' ), 3600 );
			}
		}
	}

	/**
	 * Names of the items from stored_per_site() that still exist on the current site.
	 *
	 * @return string[]
	 */
	private function left_behind() {
		$left = array();
		foreach ( $this->stored_per_site() as list( $kind, $name ) ) {
			$value = 'option' === $kind ? get_option( $name, 'gone' ) : get_transient( $name );
			if ( 'gone' !== $value && false !== $value ) {
				$left[] = $name;
			}
		}
		return $left;
	}

	/**
	 * E21 / FR-K8: Uninstall removes options and transients on every site, including a subsite on multisite.
	 */
	public function test_uninstall_cleans_options_and_transients() {
		$sub_id = is_multisite() ? self::factory()->blog->create() : 0;

		$this->store_everything();
		if ( $sub_id ) {
			switch_to_blog( $sub_id );
			$this->store_everything();
			$this->assertSame( array(), array_diff( array_column( $this->stored_per_site(), 1 ), $this->left_behind() ), 'the subsite really holds every item before uninstall' );
			restore_current_blog();
		}
		$this->assertSame( array(), array_diff( array_column( $this->stored_per_site(), 1 ), $this->left_behind() ), 'the main site really holds every item before uninstall' );

		// Execute uninstall routine.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertSame( array(), $this->left_behind(), 'main site: nothing left behind' );
		if ( $sub_id ) {
			switch_to_blog( $sub_id );
			$left = $this->left_behind();
			restore_current_blog();
			$this->assertSame( array(), $left, 'subsite: nothing left behind' );
		}
	}

	/**
	 * E21: Audit log table uses current site's table prefix.
	 */
	public function test_log_table_prefix_isolation() {
		global $wpdb;

		$table_name = LogTable::table_name();
		$this->assertSame( $wpdb->prefix . LogTable::TABLE_NAME, $table_name );
		$this->assertStringStartsWith( $wpdb->prefix, $table_name );
	}

	/**
	 * E21: AdapterBootstrap status check functions cleanly.
	 */
	public function test_adapter_bootstrap_status_check() {
		$status = AdapterBootstrap::status();
		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'source', $status );
		$this->assertArrayHasKey( 'version', $status );
		$this->assertArrayHasKey( 'compatible', $status );
	}
}
