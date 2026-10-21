<?php
/**
 * Builder tools integration tests for WP Carousel Pro (Phase 8.2).
 *
 * The test body lives in WpCarouselBuilderToolsTestCase so both editions
 * run the same checks (PRD §20 rule 6). This class supplies Pro's data only.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

require_once __DIR__ . '/WpCarouselBuilderToolsTestCase.php';

/**
 * Class WpCarouselProBuilderToolsIntegrationTest
 */
class WpCarouselProBuilderToolsIntegrationTest extends WpCarouselBuilderToolsTestCase {

	/**
	 * Pro edition data.
	 *
	 * @return array{plugin: string, namespace: string, path_constant: string, unavailable_block: string, pro_patterns: bool}
	 */
	protected static function edition() {
		return array(
			'plugin'            => 'wp-carousel-pro',
			'namespace'         => 'ShapedPlugin\\WPCarouselPro',
			'path_constant'     => 'WPCAROUSEL_PATH',
			'unavailable_block' => 'slider',
			'pro_patterns'      => true,
		);
	}
}
