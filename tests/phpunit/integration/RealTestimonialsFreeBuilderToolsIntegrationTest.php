<?php
/**
 * Builder tools integration tests for Real Testimonials Free (AI builder PRD B1).
 *
 * The test body lives in RealTestimonialsBuilderToolsTestCase so both editions
 * run the same checks (PRD §20 rule 6). This class supplies Free's data only.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

require_once __DIR__ . '/RealTestimonialsBuilderToolsTestCase.php';

/**
 * Class RealTestimonialsFreeBuilderToolsIntegrationTest
 */
class RealTestimonialsFreeBuilderToolsIntegrationTest extends RealTestimonialsBuilderToolsTestCase {

	/**
	 * Free edition data. zigzag-list is in Free's Catalog::BLOCKS but the List block is not merged (not registered).
	 *
	 * @return array{plugin: string, namespace: string, path_constant: string, unavailable_block: string, picked_design: string, text_key: string|null, pro_patterns: bool}
	 */
	protected static function edition() {
		return array(
			'plugin'            => 'testimonial-free',
			'namespace'         => 'ShapedPlugin\\TestimonialFree',
			'path_constant'     => 'SP_TFREE_PATH',
			'unavailable_block' => 'zigzag-list',
			'picked_design'     => 'design-two',
			'text_key'          => null,
			'pro_patterns'      => false,
		);
	}
}
