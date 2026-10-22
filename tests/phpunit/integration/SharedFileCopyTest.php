<?php
/**
 * Every product's copy of a shared template file is the template's, byte for byte, once the names are mapped.
 *
 * Hand-copied shared code drifts: on 2026-10-22 only `Media/`, the one folder with a test like this, still matched
 * the template, and a page-limit fix had reached one product only (PRD E181). The expected copy comes from the
 * sync script's own mapping (`shaped_kit_sync_transform()`), so the test and `tools/sync-product-module.php` can
 * never disagree about what a copy should be. A failure names the file and the command that fixes it.
 *
 * Needs the sibling product repos next to the Kit, so it is excluded from the CI suite (`integration-ci`) rather
 * than skipped there (PRD E211). Run it on a clean product tree right before a product commit (E212).
 *
 * Risk covered (TESTING.md): a fix that comes back or never arrives (rule 6), a failure that stays silent (rule 4).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/tools/sync-product-module.php';

/**
 * Class SharedFileCopyTest
 */
class SharedFileCopyTest extends TestCase {

	/**
	 * Every copy the sync script manages: product, edition and file.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function copies(): array {
		$rows = array();

		foreach ( shaped_kit_sync_products() as $slug => $product ) {
			foreach ( $product['editions'] as $edition_name => $edition ) {
				foreach ( $product['files'] as $file ) {
					$rows[ $edition['plugin'] . ' ' . $file ] = array( $slug, $edition_name, $file );
				}
			}
		}

		return $rows;
	}

	/**
	 * The copy equals the template with the product's names.
	 *
	 * @dataProvider copies
	 *
	 * @param string $slug         Product key.
	 * @param string $edition_name Edition key.
	 * @param string $file         File inside the module.
	 */
	public function test_the_copy_is_the_template_with_the_products_names( string $slug, string $edition_name, string $file ): void {
		$product    = shaped_kit_sync_products()[ $slug ];
		$edition    = $product['editions'][ $edition_name ];
		$plugin_dir = dirname( __DIR__, 4 ) . '/' . $edition['plugin'];

		if ( ! is_dir( $plugin_dir ) ) {
			$this->markTestSkipped( $edition['plugin'] . ' is not available next to the Kit (this test runs locally only).' );
		}

		$template = (string) file_get_contents( dirname( __DIR__, 3 ) . '/templates/product-module/' . $file );
		$expected = shaped_kit_sync_transform( $template, dirname( $file ), $product, $edition );
		$path     = $plugin_dir . '/' . $edition['module'] . '/' . $file;
		$label    = $edition['plugin'] . '/' . $edition['module'] . '/' . $file;
		$fix      = 'php tools/sync-product-module.php ' . $slug . ' --diff';

		$this->assertFileExists( $path, "{$label} is missing. Run: {$fix}" );
		$this->assertSame( $expected, file_get_contents( $path ), "{$label} has drifted from the template. Run: {$fix}" );
	}

	public function test_every_product_family_is_covered(): void {
		$this->assertSame( array( 'real-testimonials', 'wp-carousel', 'location-weather' ), array_keys( shaped_kit_sync_products() ) );
		$this->assertCount( 48, $this->copies(), '10 Real Testimonials, 11 WP Carousel and 3 Location Weather files, in two editions each.' );
	}
}
