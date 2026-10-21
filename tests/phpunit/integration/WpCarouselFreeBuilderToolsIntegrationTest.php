<?php
/**
 * Builder tools integration tests for WP Carousel Free (Phase 8.2).
 *
 * The test body lives in WpCarouselBuilderToolsTestCase so both editions
 * run the same checks (PRD §20 rule 6). This class supplies Free's data only.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

require_once __DIR__ . '/WpCarouselBuilderToolsTestCase.php';

/**
 * Class WpCarouselFreeBuilderToolsIntegrationTest
 */
class WpCarouselFreeBuilderToolsIntegrationTest extends WpCarouselBuilderToolsTestCase {

	/**
	 * Free edition data.
	 *
	 * @return array{plugin: string, namespace: string, path_constant: string, unavailable_block: string, pro_patterns: bool}
	 */
	protected static function edition() {
		return array(
			'plugin'            => 'wp-carousel-free',
			'namespace'         => 'ShapedPlugin\\WPCarouselFree',
			'path_constant'     => 'WPCAROUSELF_PATH',
			'unavailable_block' => 'slider',
			'pro_patterns'      => false,
		);
	}
}
