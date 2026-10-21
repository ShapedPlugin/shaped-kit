<?php
/**
 * Wire-suite profile: WP Carousel (Free and Pro).
 *
 * A profile is data, not code (PRD §20): which plugin to load, what its tools and prompt are called on the wire,
 * and which toolsets the "Builder on" state switches on. `tests/phpunit/adapter-support.php` reads it.
 *
 * @package ShapedKit
 */

return array(
	'slug'            => 'wp-carousel',
	'suite'           => 'adapter-wp-carousel',
	'default_edition' => 'pro',
	'editions'        => array(
		'pro'  => array(
			'plugin'        => 'wp-carousel-pro',
			'namespace'     => 'ShapedPlugin\\WPCarouselPro',
			'path_constant' => 'WPCAROUSEL_PATH',
		),
		'free' => array(
			'plugin'        => 'wp-carousel-free',
			'namespace'     => 'ShapedPlugin\\WPCarouselFree',
			'path_constant' => 'WPCAROUSELF_PATH',
		),
	),
	// Wire names: an ability name with the `/` turned into `-`.
	'core_tool'       => 'wp-carousel-get-context',
	'prompt'          => 'wp-carousel-build-block',
	'builder_tools'   => array(
		'wp-carousel-list-carousels',
		'wp-carousel-get-carousel',
		'wp-carousel-list-templates',
		'wp-carousel-get-template',
		'wp-carousel-find-carousel-blocks',
		'wp-carousel-get-settings',
	),
	'toolsets_on'     => array( 'core', 'management', 'builder', 'bulk' ),
);
