<?php
/**
 * Wire-suite profile: Real Testimonials (Free and Pro).
 *
 * A profile is data, not code (PRD §20): which plugin to load, what its tools and prompt are called on the wire,
 * and which toolsets the "Builder on" state switches on. `tests/phpunit/adapter-support.php` reads it.
 *
 * @package ShapedKit
 */

return array(
	'slug'            => 'real-testimonials',
	'suite'           => 'adapter-real-testimonials',
	'default_edition' => 'pro',
	'editions'        => array(
		'pro'  => array(
			'plugin'        => 'testimonial-pro',
			'namespace'     => 'ShapedPlugin\\TestimonialPro',
			'path_constant' => 'SP_TPRO_PATH',
		),
		'free' => array(
			'plugin'        => 'testimonial-free',
			'namespace'     => 'ShapedPlugin\\TestimonialFree',
			'path_constant' => 'SP_TFREE_PATH',
		),
	),
	// Wire names: an ability name with the `/` turned into `-`.
	'core_tool'       => 'real-testimonials-get-context',
	'prompt'          => 'real-testimonials-build-testimonial-block',
	'builder_tools'   => array(
		'real-testimonials-get-block-catalog',
		'real-testimonials-validate-block-attributes',
		'real-testimonials-insert-block',
		'real-testimonials-list-ready-patterns',
		'real-testimonials-insert-ready-pattern',
	),
	// What "Builder on" switches on: the Builder and Bulk Import opt-in toolsets, beside the defaults.
	'toolsets_on'     => array( 'core', 'management', 'builder', 'bulk', 'media' ),
);
