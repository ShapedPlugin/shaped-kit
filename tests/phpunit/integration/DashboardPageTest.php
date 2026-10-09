<?php
/**
 * DashboardPage: loading the React app, and only where and for whom it belongs.
 *
 * Risk covered: the dashboard script (and the REST nonce handed to it) loading on every admin page or
 * for a user who may not use it, and a missing build being hidden behind a script that 404s.
 *
 * @package ShapedKit
 */

use ShapedKit\Admin\DashboardPage;

/**
 * Real WordPress: the menu is registered and the script queue is read back.
 */
final class DashboardPageTest extends WP_UnitTestCase {

	/**
	 * A stand-in for the build's asset file, so the test needs no `npm run build`.
	 *
	 * @var string
	 */
	private $asset_file;

	/**
	 * The folder that holds the stand-in build.
	 *
	 * @var string
	 */
	private $build_dir;

	/**
	 * Register the menu as an administrator and write a stand-in asset file.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		DashboardPage::add_menu();

		// A folder of its own, so a stylesheet written next to the asset file is found only by the test that wants it.
		$this->build_dir = trailingslashit( get_temp_dir() ) . 'shaped-kit-build-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->build_dir );
		$this->asset_file = $this->build_dir . '/index.asset.php';
		file_put_contents( $this->asset_file, "<?php return array( 'dependencies' => array( 'wp-element' ), 'version' => 'abc123' );" );
	}

	/**
	 * Remove the script and the temp file.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_dequeue_script( DashboardPage::SCRIPT_HANDLE );
		wp_deregister_script( DashboardPage::SCRIPT_HANDLE );
		wp_dequeue_style( DashboardPage::SCRIPT_HANDLE );
		wp_deregister_style( DashboardPage::SCRIPT_HANDLE );

		foreach ( glob( $this->build_dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->build_dir );

		parent::tear_down();
	}

	public function test_the_stylesheet_loads_with_the_script_when_the_build_has_one(): void {
		file_put_contents( $this->build_dir . '/style-index.css', '.x{}' );

		DashboardPage::enqueue( $this->page_hook(), $this->asset_file );

		$this->assertTrue( wp_style_is( DashboardPage::SCRIPT_HANDLE, 'enqueued' ) );
		$style = wp_styles()->registered[ DashboardPage::SCRIPT_HANDLE ];
		$this->assertStringEndsWith( 'build/admin/style-index.css', $style->src );
		$this->assertSame( 'abc123', $style->ver );
		$this->assertSame( 'replace', $style->extra['rtl'], 'Right-to-left sites get the -rtl file.' );
	}

	public function test_a_build_without_styles_loads_the_script_and_no_stylesheet(): void {
		DashboardPage::enqueue( $this->page_hook(), $this->asset_file );

		$this->assertTrue( wp_script_is( DashboardPage::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( DashboardPage::SCRIPT_HANDLE, 'enqueued' ), 'No link to a file that is not there.' );
	}

	public function test_the_stylesheet_follows_the_same_page_and_user_rules_as_the_script(): void {
		file_put_contents( $this->build_dir . '/style-index.css', '.x{}' );

		DashboardPage::enqueue( 'index.php', $this->asset_file );
		$this->assertFalse( wp_style_is( DashboardPage::SCRIPT_HANDLE, 'enqueued' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		DashboardPage::enqueue( $this->page_hook(), $this->asset_file );
		$this->assertFalse( wp_style_is( DashboardPage::SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * The hook name WordPress reports for this page.
	 *
	 * @return string
	 */
	private function page_hook(): string {
		return get_plugin_page_hookname( DashboardPage::SLUG, '' );
	}

	public function test_the_app_loads_on_its_own_page_with_the_rest_address_and_a_real_nonce(): void {
		DashboardPage::enqueue( $this->page_hook(), $this->asset_file );

		$this->assertTrue( wp_script_is( DashboardPage::SCRIPT_HANDLE, 'enqueued' ) );

		$data = wp_scripts()->get_data( DashboardPage::SCRIPT_HANDLE, 'data' );

		$this->assertStringContainsString( 'shapedKitAdmin', $data );
		$this->assertMatchesRegularExpression( '#"restUrl":"[^"]*shaped-kit(\\\\/|/)v1(\\\\/|/)mcp"#', $data, 'Pretty or plain permalinks, the address ends in the route.' );
		$this->assertSame( 1, preg_match( '/"nonce":"([^"]+)"/', $data, $match ) );
		$this->assertNotFalse( wp_verify_nonce( $match[1], 'wp_rest' ), 'The nonce is the REST one, so the routes accept it.' );
		$this->assertSame( array( 'wp-element' ), wp_scripts()->registered[ DashboardPage::SCRIPT_HANDLE ]->deps );
		$this->assertSame( 'abc123', wp_scripts()->registered[ DashboardPage::SCRIPT_HANDLE ]->ver );
	}

	public function test_nothing_loads_on_any_other_admin_page(): void {
		foreach ( array( 'index.php', 'plugins.php', 'toplevel_page_somebody-else', '' ) as $hook ) {
			DashboardPage::enqueue( $hook, $this->asset_file );
		}

		$this->assertFalse( wp_script_is( DashboardPage::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_a_user_who_cannot_manage_options_gets_neither_the_script_nor_the_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		DashboardPage::enqueue( $this->page_hook(), $this->asset_file );

		$this->assertFalse( wp_script_is( DashboardPage::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_a_missing_build_loads_nothing_instead_of_a_script_that_404s(): void {
		DashboardPage::enqueue( $this->page_hook(), '/no/such/index.asset.php' );

		$this->assertFalse( wp_script_is( DashboardPage::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_the_page_hands_over_only_what_it_needs(): void {
		DashboardPage::enqueue( $this->page_hook(), $this->asset_file );

		$data = wp_scripts()->get_data( DashboardPage::SCRIPT_HANDLE, 'data' );

		$this->assertSame( 1, preg_match( '/=\s*(\{.*\});/s', $data, $match ) );
		$this->assertSame( array( 'restUrl', 'nonce', 'version' ), array_keys( json_decode( $match[1], true ) ) );
	}
}
