<?php
/**
 * The media tools as Real Testimonials Free and Pro serve them (PRD D22). The checks are in ProductMediaCopyTestCase.
 *
 * Needs the sibling plugins, so it is excluded from the CI suite.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

require_once __DIR__ . '/ProductMediaCopyTestCase.php';

/**
 * Class RealTestimonialsMediaIntegrationTest
 */
class RealTestimonialsMediaIntegrationTest extends ProductMediaCopyTestCase {

	/**
	 * Editions: plugin folder, namespace part, text domain, package name, ability slug.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	public function editions() {
		return array(
			'pro'  => array( 'testimonial-pro', 'TestimonialPro', 'testimonial-pro', 'Real_Testimonials_Pro', 'real-testimonials' ),
			'free' => array( 'testimonial-free', 'TestimonialFree', 'testimonial-free', 'Real_Testimonials_Free', 'real-testimonials' ),
		);
	}
}
