<?php
/**
 * The media tools as WP Carousel Free and Pro serve them (PRD D22). The checks are in ProductMediaCopyTestCase.
 *
 * Needs the sibling plugins, so it is excluded from the CI suite.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

require_once __DIR__ . '/ProductMediaCopyTestCase.php';

/**
 * Class WpCarouselMediaIntegrationTest
 */
class WpCarouselMediaIntegrationTest extends ProductMediaCopyTestCase {

	/**
	 * Editions: plugin folder, namespace part, text domain, package name, ability slug.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	public function editions() {
		return array(
			'pro'  => array( 'wp-carousel-pro', 'WPCarouselPro', 'wp-carousel-pro', 'WP_Carousel_Pro', 'wp-carousel' ),
			'free' => array( 'wp-carousel-free', 'WPCarouselFree', 'wp-carousel-free', 'WP_Carousel_Free', 'wp-carousel' ),
		);
	}

	/**
	 * Load an edition the way the other WP Carousel suites do (WpCarouselReadToolsIntegrationTest::ensure_loaded()).
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $ns     Namespace part.
	 * @return string
	 */
	protected function load( $plugin, $ns ) {
		if ( ! defined( 'WPCAROUSEL_VERSION' ) ) {
			define( 'WPCAROUSEL_VERSION', '5.1.0' );
		}

		return parent::load( $plugin, $ns );
	}
}
