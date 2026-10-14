<?php
/**
 * Builder tools integration tests for Real Testimonials Pro (AI builder PRD B1).
 *
 * The test body lives in RealTestimonialsBuilderToolsTestCase so both editions
 * run the same checks (PRD §20 rule 6). This class supplies Pro's data only.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

require_once __DIR__ . '/RealTestimonialsBuilderToolsTestCase.php';

/**
 * Class RealTestimonialsProBuilderToolsIntegrationTest
 */
class RealTestimonialsProBuilderToolsIntegrationTest extends RealTestimonialsBuilderToolsTestCase {

	/**
	 * Pro edition data. masonry is in Pro's Catalog::BLOCKS but not registered here.
	 *
	 * @return array{plugin: string, namespace: string, path_constant: string, unavailable_block: string, picked_design: string, text_key: string|null, pro_patterns: bool}
	 */
	protected static function edition() {
		return array(
			'plugin'            => 'testimonial-pro',
			'namespace'         => 'ShapedPlugin\\TestimonialPro',
			'path_constant'     => 'SP_TPRO_PATH',
			'unavailable_block' => 'masonry',
			'picked_design'     => 'design-four',
			'text_key'          => 'readMoreLabel',
			'pro_patterns'      => true,
		);
	}
}
