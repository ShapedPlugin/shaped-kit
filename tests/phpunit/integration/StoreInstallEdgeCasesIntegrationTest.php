<?php
/**
 * Integration tests for the Store's install and catalog edge cases that the main Store test does not reach.
 *
 * Verifies, with real zips unpacked by core's `Plugin_Upgrader` into the test library's plugins folder:
 * - E47: a Pro package whose hash is not the catalog's, a catalog with no hash, and a package on another host are refused.
 * - E48: an install never replaces a folder that is already there; a beta replaces only the product it belongs to.
 * - E49: a beta waits for the install lock.
 * - E50: a filesystem that wants credentials gives a clear refusal and writes nothing.
 * - E56: no beta reaches WordPress's update list.
 * - E58b: a beta is refused when the product moved ahead while it waited for the lock.
 * - E45 / E60: a catalog that expired is refused beyond the 24 h skew window, and not remembered.
 *
 * Nothing leaves the machine: downloads and the store are answered on `pre_http_request`.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use ShapedKit\Store\BetaInstaller;
use ShapedKit\Store\CatalogClient;
use ShapedKit\Store\CatalogData;
use ShapedKit\Store\Installer;
use WP_UnitTestCase;
use WP_Upgrader;

require_once dirname( __DIR__, 3 ) . '/tools/sign-catalog.php';

/**
 * Class StoreInstallEdgeCasesIntegrationTest
 */
class StoreInstallEdgeCasesIntegrationTest extends WP_UnitTestCase {

	const KEY = 'ABCD1234-LICENSE-KEY-9f8e7d6c';

	/**
	 * Plugin folders and zips a test created, removed in tearDown.
	 *
	 * @var string[]
	 */
	private $paths = array();

	/**
	 * Package downloads the stand-in served (requests that asked for a file).
	 *
	 * @var int
	 */
	private $downloads = 0;

	/**
	 * Set up: an administrator, the Store on, the audit table present.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		\ShapedKit\Audit\LogTable::install();
		add_filter( 'shaped_kit/store_live', '__return_true' );

		$this->downloads = 0;

		/*
		 * The installer creates these folders itself, so a test cannot list them as it makes them. They are cleared
		 * before the test (a crashed run leaves them) and after it (a test that failed to refuse installs one).
		 */
		foreach ( array( 'location-weather', 'location-weather-pro' ) as $slug ) {
			$this->delete_tree( WP_PLUGIN_DIR . '/' . $slug );
			$this->paths[] = WP_PLUGIN_DIR . '/' . $slug;
		}
		wp_clean_plugins_cache( false );
	}

	/**
	 * Tear down: remove every folder and zip, every filter, and the lock.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pre_transient_shaped_kit_catalog_cache' );
		remove_all_filters( 'pre_update_option_' . Installer::LOCK_NAME . '.lock' );
		remove_all_filters( 'filesystem_method' );
		WP_Upgrader::release_lock( Installer::LOCK_NAME );

		foreach ( $this->paths as $path ) {
			$this->delete_tree( $path );
		}

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Delete a file or a folder with everything in it.
	 *
	 * @param string $path File or folder.
	 */
	private function delete_tree( $path ) {
		if ( is_file( $path ) ) {
			unlink( $path );
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $path );
	}

	/**
	 * A plugin folder in the test library's plugins folder, removed in tearDown.
	 *
	 * @param string $slug  Folder name.
	 * @param array  $files File name => contents.
	 * @return string The folder.
	 */
	private function make_plugin_dir( $slug, array $files ) {
		$dir = WP_PLUGIN_DIR . '/' . $slug;
		wp_mkdir_p( $dir );
		foreach ( $files as $name => $body ) {
			file_put_contents( $dir . '/' . $name, $body );
		}
		$this->paths[] = $dir;
		wp_clean_plugins_cache( false );

		return $dir;
	}

	/**
	 * A main plugin file with a name and a version.
	 *
	 * @param string $name    Plugin name.
	 * @param string $version Version.
	 * @return string
	 */
	private function plugin_file( $name, $version ) {
		return "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n */\n";
	}

	/**
	 * A zip holding one plugin folder, removed in tearDown.
	 *
	 * @param string $slug  Folder (and main file) name inside the zip.
	 * @param string $name  Plugin name.
	 * @param string $version Version.
	 * @return array{path: string, sha: string}
	 */
	private function make_zip( $slug, $name, $version ) {
		$path = get_temp_dir() . 'shaped_kit_edge_' . uniqid( '', true ) . '.zip';
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE );
		$zip->addFromString( $slug . '/' . $slug . '.php', $this->plugin_file( $name, $version ) );
		$zip->addFromString( $slug . '/from-the-package.txt', $version );
		$zip->close();
		$this->paths[] = $path;

		return array(
			'path' => $path,
			'sha'  => hash_file( 'sha256', $path ),
		);
	}

	/**
	 * Answer downloads with `$zip` and the store with `$store_answer`; count the downloads.
	 *
	 * @param string|null $zip          Path of the zip to serve, or null to serve none.
	 * @param array|null  $store_answer JSON document the store returns to a POST, or null.
	 */
	private function serve( $zip, $store_answer = null ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $zip, $store_answer ) {
				$ok = array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
				);

				/*
				 * Core's upgrader fires `upgrader_process_complete` after every run, failed or not, and that runs the
				 * core, plugin and theme update checks against api.wordpress.org. A real site answers them; so does this.
				 */
				if ( 'api.wordpress.org' === wp_parse_url( $url, PHP_URL_HOST ) ) {
					return $ok + array(
						'body' => (string) wp_json_encode(
							array(
								'offers'       => array(),
								'plugins'      => array(),
								'themes'       => array(),
								'translations' => array(),
								'no_update'    => array(),
							)
						),
					);
				}

				if ( ! empty( $args['filename'] ) ) {
					++$this->downloads;
					if ( $zip ) {
						copy( $zip, $args['filename'] );
					}
					return $ok + array( 'body' => '' );
				}

				if ( null !== $store_answer ) {
					return $ok + array( 'body' => (string) wp_json_encode( $store_answer ) );
				}

				return new \WP_Error( 'unexpected_request', 'The test did not expect this request.' );
			},
			10,
			3
		);
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
				return array( 'products' => $products );
			}
		);
	}

	/**
	 * A free catalog entry for Location Weather.
	 *
	 * @param array $over Fields to change.
	 * @return array
	 */
	private function free_entry( array $over = array() ) {
		return $over + array(
			'slug'           => 'location-weather',
			'name'           => 'Location Weather',
			'edition'        => 'free',
			'stable_version' => '3.0.7',
			'stable_sha256'  => str_repeat( '0', 64 ),
			'beta_version'   => '3.1.0-beta1',
			'beta_url'       => 'https://api.shapedplugin.com/kit/v1/betas/location-weather/3.1.0-beta1.zip',
			'beta_sha256'    => str_repeat( '0', 64 ),
		);
	}

	/**
	 * A Pro catalog entry for Location Weather Pro.
	 *
	 * @param array $over Fields to change.
	 * @return array
	 */
	private function pro_entry( array $over = array() ) {
		return $over + array(
			'slug'           => 'location-weather-pro',
			'name'           => 'Location Weather Pro',
			'edition'        => 'pro',
			'item_id'        => 481,
			'store_url'      => 'https://shapedplugin.com',
			'stable_version' => '2.8.0',
			'stable_sha256'  => str_repeat( '0', 64 ),
			'beta_version'   => null,
			'beta_url'       => null,
			'beta_sha256'    => null,
		);
	}

	/**
	 * The store's answer for a valid license: a package on the allowed host.
	 *
	 * @param string $host Host the package is served from.
	 * @return array
	 */
	private function store_accepts( $host = 'account.shapedplugin.com' ) {
		return array(
			'new_version' => '9.9.9',
			'package'     => 'https://' . $host . '/edd-sl/package_download/abc123/location-weather-pro.zip',
		);
	}

	/**
	 * E48: a free install never replaces a folder that is already there. The folder and its files survive, the
	 * answer is a 409 that names no server path, and the install lock is free again.
	 */
	public function test_a_free_install_never_replaces_a_folder_that_is_already_there() {
		$dir = $this->make_plugin_dir( 'location-weather', array( 'keep-me.txt' => 'not ours to delete' ) );
		$zip = $this->make_zip( 'location-weather', 'Location Weather', '3.0.7' );
		$this->catalog( array( $this->free_entry( array( 'stable_sha256' => $zip['sha'] ) ) ) );
		$this->serve( $zip['path'] );

		$result = Installer::install_free_plugin( 'location-weather', false );

		$this->assertWPError( $result );
		$this->assertSame( 'destination_exists', $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( WP_PLUGIN_DIR, (string) wp_json_encode( $result->get_error_data() ), 'No server path in the answer.' );
		$this->assertFileExists( $dir . '/keep-me.txt' );
		$this->assertFileDoesNotExist( $dir . '/from-the-package.txt' );
		$this->assertTrue( WP_Upgrader::create_lock( Installer::LOCK_NAME, 60 ), 'The lock was released.' );
	}

	/**
	 * E48: a beta of a product that is not installed does not clear a folder that merely has the same name.
	 */
	public function test_a_beta_never_clears_a_folder_that_is_not_this_product() {
		$dir = $this->make_plugin_dir( 'location-weather', array( 'keep-me.txt' => 'another vendor, same folder name' ) );
		$zip = $this->make_zip( 'location-weather', 'Location Weather', '3.1.0-beta1' );
		$this->catalog( array( $this->free_entry( array( 'beta_sha256' => $zip['sha'] ) ) ) );
		$this->serve( $zip['path'] );

		$result = BetaInstaller::install_beta( 'location-weather', null, false );

		$this->assertWPError( $result );
		$this->assertSame( 'destination_exists', $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertFileExists( $dir . '/keep-me.txt', 'The folder was left alone.' );
		$this->assertFileDoesNotExist( $dir . '/from-the-package.txt' );
	}

	/**
	 * E48: the other half. A beta of the installed product does replace it; the guard must not break the upgrade.
	 */
	public function test_a_beta_replaces_the_product_that_is_installed() {
		$dir = $this->make_plugin_dir(
			'location-weather',
			array(
				'location-weather.php' => $this->plugin_file( 'Location Weather', '3.0.0' ),
				'old-release.txt'      => 'from 3.0.0',
			)
		);
		$zip = $this->make_zip( 'location-weather', 'Location Weather', '3.1.0-beta1' );
		$this->catalog( array( $this->free_entry( array( 'beta_sha256' => $zip['sha'] ) ) ) );
		$this->serve( $zip['path'] );

		$result = BetaInstaller::install_beta( 'location-weather', null, false );

		$this->assertIsArray( $result );
		$this->assertSame( 'installed_beta', $result['status'] );
		$this->assertStringContainsString( '3.1.0-beta1', (string) file_get_contents( $dir . '/location-weather.php' ) );
		$this->assertFileDoesNotExist( $dir . '/old-release.txt', 'The old release was replaced, not merged.' );
		$this->assertFileExists( $dir . '/from-the-package.txt' );
	}

	/**
	 * E58b: the product moves ahead between the first version check and the lock (another admin's install finished).
	 * Under the lock the version is read again; the beta is refused and nothing is downloaded.
	 */
	public function test_a_beta_is_refused_when_the_product_moved_ahead_while_it_waited_for_the_lock() {
		$dir = $this->make_plugin_dir( 'location-weather', array( 'location-weather.php' => $this->plugin_file( 'Location Weather', '3.0.0' ) ) );
		$zip = $this->make_zip( 'location-weather', 'Location Weather', '3.1.0-beta1' );
		$this->catalog( array( $this->free_entry( array( 'beta_sha256' => $zip['sha'] ) ) ) );
		$this->serve( $zip['path'] );

		// The lock is taken with an INSERT, then stamped with update_option(). That stamp is the first thing that runs
		// after the lock is ours, so the other admin's install "finishes" there.
		add_filter(
			'pre_update_option_' . Installer::LOCK_NAME . '.lock',
			function ( $value ) use ( $dir ) {
				file_put_contents( $dir . '/location-weather.php', $this->plugin_file( 'Location Weather', '3.1.0-beta1' ) );
				return $value;
			}
		);

		$result = BetaInstaller::install_beta( 'location-weather', null, false );

		$this->assertWPError( $result );
		$this->assertSame( 'downgrade_not_allowed', $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 0, $this->downloads, 'Nothing was downloaded.' );
		$this->assertStringContainsString( '3.1.0-beta1', (string) file_get_contents( $dir . '/location-weather.php' ) );
		remove_all_filters( 'pre_update_option_' . Installer::LOCK_NAME . '.lock' );
		$this->assertTrue( WP_Upgrader::create_lock( Installer::LOCK_NAME, 60 ), 'The lock was released.' );
	}

	/**
	 * E49: a beta waits for a running install and downloads nothing.
	 */
	public function test_a_beta_waits_for_the_install_lock() {
		$zip = $this->make_zip( 'location-weather', 'Location Weather', '3.1.0-beta1' );
		$this->catalog( array( $this->free_entry( array( 'beta_sha256' => $zip['sha'] ) ) ) );
		$this->serve( $zip['path'] );
		$this->assertTrue( WP_Upgrader::create_lock( Installer::LOCK_NAME, 300 ) );

		$result = BetaInstaller::install_beta( 'location-weather', null, false );

		$this->assertWPError( $result );
		$this->assertSame( 'install_in_progress', $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertSame( 0, $this->downloads );
	}

	/**
	 * E47: the licensing store serves a package that is not the one the catalog hashed (a newer build, or a swapped file). It is
	 * refused, deleted and never unpacked.
	 */
	public function test_a_pro_install_refuses_a_package_whose_hash_is_not_the_catalogs() {
		$served  = $this->make_zip( 'location-weather-pro', 'Location Weather Pro', '9.9.9' );
		$catalog = $this->make_zip( 'location-weather-pro', 'Location Weather Pro', '2.8.0' );
		$this->catalog( array( $this->pro_entry( array( 'stable_sha256' => $catalog['sha'] ) ) ) );
		$this->serve( $served['path'], $this->store_accepts() );

		$result = Installer::install_pro_plugin( 'location-weather-pro', self::KEY, false );

		$this->assertWPError( $result );
		$this->assertSame( 'checksum_mismatch', $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( 1, $this->downloads );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/location-weather-pro', 'Nothing was unpacked.' );
		$this->assertStringNotContainsString( self::KEY, $result->get_error_message() );
		$this->assertTrue( WP_Upgrader::create_lock( Installer::LOCK_NAME, 60 ), 'The lock was released.' );
	}

	/**
	 * E47: a catalog entry with no hash installs nothing, even for a valid license.
	 */
	public function test_a_pro_install_refuses_when_the_catalog_has_no_hash() {
		$served = $this->make_zip( 'location-weather-pro', 'Location Weather Pro', '2.8.0' );
		$this->catalog( array( $this->pro_entry( array( 'stable_sha256' => '' ) ) ) );
		$this->serve( $served['path'], $this->store_accepts() );

		$result = Installer::install_pro_plugin( 'location-weather-pro', self::KEY, false );

		$this->assertWPError( $result );
		$this->assertContains( $result->get_error_code(), array( 'checksum_mismatch', 'unverified_package' ) );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/location-weather-pro' );
	}

	/**
	 * E47 / E55: a package link on another host is refused before anything is downloaded.
	 */
	public function test_a_pro_install_refuses_a_package_on_another_host() {
		$served = $this->make_zip( 'location-weather-pro', 'Location Weather Pro', '2.8.0' );
		$this->catalog( array( $this->pro_entry( array( 'stable_sha256' => $served['sha'] ) ) ) );
		$this->serve( $served['path'], $this->store_accepts( 'evil.example.net' ) );

		$result = Installer::install_pro_plugin( 'location-weather-pro', self::KEY, false );

		$this->assertWPError( $result );
		$this->assertSame( 'unauthorized_download_host', $result->get_error_code(), $result->get_error_message() );
		$this->assertSame( 0, $this->downloads );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/location-weather-pro' );
	}

	/**
	 * E50: a host whose filesystem wants FTP credentials. The install stops with an error, writes nothing, and
	 * leaves the lock free.
	 */
	public function test_a_filesystem_that_needs_credentials_stops_the_install_cleanly() {
		$zip = $this->make_zip( 'location-weather', 'Location Weather', '3.0.7' );
		$this->catalog( array( $this->free_entry( array( 'stable_sha256' => $zip['sha'] ) ) ) );
		$this->serve( $zip['path'] );
		add_filter(
			'filesystem_method',
			function () {
				return 'ftpext';
			}
		);

		$result = Installer::install_free_plugin( 'location-weather', false );

		$this->assertWPError( $result );
		$this->assertSame( 'install_failed', $result->get_error_code(), $result->get_error_message() );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/location-weather' );
		$this->assertTrue( WP_Upgrader::create_lock( Installer::LOCK_NAME, 60 ), 'The lock was released.' );
	}

	/**
	 * E56: a beta in the catalog never becomes an update. The Kit's own entry offers its stable version, and no other
	 * product gets an entry at all, however many betas the catalog lists.
	 */
	public function test_no_beta_ever_reaches_the_update_list() {
		$catalog = CatalogData::get_bundled_catalog();
		foreach ( $catalog['products'] as &$item ) {
			if ( 'shaped-kit' === $item['slug'] ) {
				$item['stable_version'] = '9.9.9';
				$item['package_url']    = 'https://api.shapedplugin.com/kit/v1/releases/shaped-kit-9.9.9.zip';
				$item['beta_version']   = '10.0.0-beta1';
				$item['beta_sha256']    = str_repeat( 'b', 64 );
			}
		}
		unset( $item );

		$betas = array_filter(
			wp_list_pluck( $catalog['products'], 'beta_version', 'slug' )
		);
		$this->assertGreaterThan( 1, count( $betas ), 'The catalog lists betas for several products.' );

		$this->catalog( $catalog['products'] );

		$kit_file = defined( 'SHAPED_KIT_FILE' ) ? plugin_basename( SHAPED_KIT_FILE ) : 'shaped-kit/shaped-kit.php';
		$list     = apply_filters( 'pre_set_site_transient_update_plugins', (object) array( 'response' => array() ) );

		$this->assertSame( array( $kit_file ), array_keys( $list->response ), 'Only the Kit itself is offered.' );
		$this->assertSame( '9.9.9', $list->response[ $kit_file ]->new_version, 'Its stable version, not the beta.' );
		$this->assertStringNotContainsString( 'beta', (string) wp_json_encode( $list ), 'No beta version or beta link anywhere in the list.' );
	}

	/**
	 * A signed catalog that expires `$offset` seconds from now.
	 *
	 * @param int $offset Seconds from now (negative: already expired).
	 * @return string JSON.
	 */
	private function signed_catalog_expiring( $offset ) {
		$catalog               = CatalogData::get_bundled_catalog();
		$catalog['serial']     = CatalogData::BUNDLED_SERIAL + 1;
		$catalog['expires_at'] = gmdate( 'Y-m-d\TH:i:s\Z', time() + $offset );

		return (string) wp_json_encode( $catalog );
	}

	/**
	 * Serve a catalog and its signature to `fetch_remote_catalog()`.
	 *
	 * @param string $json    Catalog body.
	 * @param string $secret_key Base64 secret key to sign with.
	 */
	private function serve_signed_catalog( $json, $secret_key ) {
		$signature = shaped_kit_sign_catalog( $json, $secret_key );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $json, $signature ) {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => CatalogClient::REMOTE_SIGNATURE_URL === $url ? $signature : $json,
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * E60: a server clock an hour ahead of the catalog's expiry does not lock the site out.
	 */
	public function test_a_catalog_that_expired_within_the_skew_window_is_accepted() {
		$keypair = shaped_kit_generate_keypair();
		$this->serve_signed_catalog( $this->signed_catalog_expiring( -HOUR_IN_SECONDS ), $keypair['private'] );

		$result = CatalogClient::fetch_remote_catalog( array( $keypair['public'] ) );

		$this->assertIsArray( $result );
		$this->assertSame( CatalogData::BUNDLED_SERIAL + 1, $result['serial'] );
	}

	/**
	 * E45 / E60: a catalog that expired more than a day ago is refused, and its serial is not remembered, so it
	 * cannot lock out a later, valid catalog.
	 */
	public function test_a_catalog_expired_beyond_the_skew_window_is_refused_and_not_remembered() {
		$keypair = shaped_kit_generate_keypair();
		delete_option( CatalogClient::OPTION_LAST_SERIAL );
		$this->serve_signed_catalog( $this->signed_catalog_expiring( -( DAY_IN_SECONDS + HOUR_IN_SECONDS ) ), $keypair['private'] );

		$result = CatalogClient::fetch_remote_catalog( array( $keypair['public'] ) );

		$this->assertNull( $result );
		$this->assertFalse( get_option( CatalogClient::OPTION_LAST_SERIAL, false ), 'A refused catalog leaves no trace.' );
	}
}
