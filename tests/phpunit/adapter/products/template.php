<?php
/**
 * Wire-suite profile: the product-module template itself (`templates/product-module/`).
 *
 * The template runs as a real product under its placeholder names (`template-slug`, `template_prefix`), loaded
 * straight from its folder, so the generic wire tests run on every adapter copy with no sibling plugin
 * installed, and a template change that breaks the wire is caught in CI, not in the next product (Phase 8.0).
 *
 * @package ShapedKit
 */

return array(
	'slug'            => 'template-slug',
	'suite'           => 'adapter-template',
	'default_edition' => 'free',
	'editions'        => array(
		'free' => array(
			// No sibling plugin: the template's classes are autoloaded from its own folder.
			'namespace' => 'ShapedPlugin\\Template',
			'source'    => 'templates/product-module',
		),
	),
	'core_tool'       => 'template-slug-get-context',
	'prompt'          => 'template-slug-build-block',
	'builder_tools'   => array(
		'template-slug-get-block-catalog',
		'template-slug-validate-block-attributes',
		'template-slug-insert-block',
		'template-slug-list-ready-patterns',
		'template-slug-insert-ready-pattern',
	),
	'toolsets_on'     => array( 'core', 'management', 'builder', 'media' ),
);
