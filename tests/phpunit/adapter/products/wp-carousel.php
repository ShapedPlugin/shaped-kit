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
			'plugin'           => 'wp-carousel-pro',
			'namespace'        => 'ShapedPlugin\\WPCarouselPro',
			'path_constant'    => 'WPCAROUSEL_PATH',
			'version_constant' => 'WPCAROUSEL_VERSION',
			'version'          => '5.1.0',
		),
		'free' => array(
			'plugin'           => 'wp-carousel-free',
			'namespace'        => 'ShapedPlugin\\WPCarouselFree',
			'path_constant'    => 'WPCAROUSELF_PATH',
			'version_constant' => 'WPCAROUSELF_VERSION',
			'version'          => '4.0.1',
		),
	),
	// Wire names: an ability name with the `/` turned into `-`.
	'core_tool'       => 'wp-carousel-get-context',
	'prompt'          => 'wp-carousel-build-carousel',
	'builder_tools'   => array(
		'wp-carousel-get-block-catalog',
		'wp-carousel-validate-block-attributes',
		'wp-carousel-insert-block',
		'wp-carousel-list-ready-patterns',
		'wp-carousel-insert-ready-pattern',
	),
	'toolsets_on'     => array( 'core', 'management', 'destructive', 'builder', 'bulk', 'media' ),
);
