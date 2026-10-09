<?php
/**
 * PHPUnit bootstrap for Shaped Kit. Modelled on Location Weather Pro's.
 *
 * Two modes, selected by `SHAPED_KIT_PHPUNIT_BOOTSTRAP`:
 *
 *   unit         (default) No WordPress. Registers the same ShapedKit\ => src/
 *                PSR-4 mapping shaped-kit.php does, without including
 *                shaped-kit.php itself (it calls WordPress functions at
 *                include time). ABSPATH is defined only because every file
 *                under src/ opens with `defined( 'ABSPATH' ) || exit;`.
 *   integration  Boots the WordPress test library and loads the plugin on
 *                `muplugins_loaded`. Skips cleanly (rather than fataling)
 *                when that library is not installed.
 *
 * @package ShapedKit
 */

$shaped_kit_root = dirname( __DIR__, 2 );
$shaped_kit_mode = getenv( 'SHAPED_KIT_PHPUNIT_BOOTSTRAP' ) ? getenv( 'SHAPED_KIT_PHPUNIT_BOOTSTRAP' ) : 'unit';

/*
 * Integration mode — real WordPress.
 */

$shaped_kit_wp_tests_dir = '';
if ( 'integration' === $shaped_kit_mode ) {
	$shaped_kit_wp_tests_dir = getenv( 'WP_TESTS_DIR' );
	if ( ! $shaped_kit_wp_tests_dir ) {
		$shaped_kit_default = $shaped_kit_root . '/.wordpress-tests-lib';
		if ( file_exists( $shaped_kit_default . '/includes/functions.php' ) ) {
			$shaped_kit_wp_tests_dir = $shaped_kit_default;
		}
	}
}

if ( $shaped_kit_wp_tests_dir ) {
	$shaped_kit_wp_tests_dir = rtrim( $shaped_kit_wp_tests_dir, '/\\' );

	if ( ! file_exists( $shaped_kit_wp_tests_dir . '/includes/functions.php' ) ) {
		fwrite( STDERR, "WP test suite not found in {$shaped_kit_wp_tests_dir}.\n" );
		exit( 1 );
	}

	require_once $shaped_kit_wp_tests_dir . '/includes/functions.php';

	/*
	 * Normally the plugin under test is this checkout. `composer test:package` points
	 * SHAPED_KIT_PLUGIN_DIR at the unzipped release package instead, so the same tests prove
	 * the package works on its own. A wrong path is an error, never a quiet fall back to the
	 * checkout, which would test the wrong thing and pass.
	 */
	$shaped_kit_plugin_dir = getenv( 'SHAPED_KIT_PLUGIN_DIR' ) ? rtrim( getenv( 'SHAPED_KIT_PLUGIN_DIR' ), '/\\' ) : $shaped_kit_root;

	if ( ! file_exists( $shaped_kit_plugin_dir . '/shaped-kit.php' ) ) {
		fwrite( STDERR, "No shaped-kit.php in {$shaped_kit_plugin_dir}.\n" );
		exit( 1 );
	}

	if ( $shaped_kit_plugin_dir !== $shaped_kit_root ) {
		fwrite( STDERR, "Testing the package at {$shaped_kit_plugin_dir}\n" );
	}

	tests_add_filter(
		'muplugins_loaded',
		static function () use ( $shaped_kit_plugin_dir ) {
			require_once $shaped_kit_plugin_dir . '/shaped-kit.php';
		}
	);

	require_once $shaped_kit_wp_tests_dir . '/includes/bootstrap.php';
	return;
}

/*
 * Unit mode — no WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $shaped_kit_root . '/' );
}

spl_autoload_register(
	static function ( $class_name ) use ( $shaped_kit_root ) {
		$prefix = 'ShapedKit\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = $shaped_kit_root . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	/**
	 * Stand-in so the integration suite is collectible without WordPress.
	 *
	 * Skipping beats failing: `composer test` on a fresh checkout should show
	 * the unit suite passing and the integration suite reporting "not
	 * installed", not a fatal that looks like broken code.
	 */
	abstract class WP_UnitTestCase extends PHPUnit\Framework\TestCase {

		/**
		 * Skip every integration test when WordPress is not installed.
		 *
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();
			$this->markTestSkipped(
				'WordPress integration tests are not installed, so this suite proves nothing. ' .
				'Install .wordpress/ and .wordpress-tests-lib/, then run: composer test:integration'
			);
		}
	}
}
