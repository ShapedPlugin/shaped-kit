<?php
/**
 * AdapterBootstrap::wordpress_supported(), the 6.9 gate the product modules copy (PRD FR-P1).
 *
 * @package ShapedKit
 */

use PHPUnit\Framework\TestCase;
use ShapedKit\Mcp\AdapterBootstrap;

/**
 * The gate reads a raw $wp_version string, including pre-release suffixes.
 */
final class WordPressSupportedTest extends TestCase {

	/**
	 * Version strings and whether the bundled adapter can run on them.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function versions(): array {
		return array(
			'exactly the floor'            => array( '6.9', true ),
			'floor with a patch digit'     => array( '6.9.0', true ),
			'the local site today'         => array( '7.1.3', true ),
			'6.10 is above 6.9, not below' => array( '6.10', true ),
			'beta of the floor counts'     => array( '6.9-beta1', true ),
			'release candidate build'      => array( '7.0-RC1-61000', true ),
			'just below the floor'         => array( '6.8.3', false ),
			'empty'                        => array( '', false ),
			'not a version'                => array( 'abc', false ),
		);
	}

	/**
	 * Each version string gets the expected verdict.
	 *
	 * @dataProvider versions
	 *
	 * @param string $version  A $wp_version value.
	 * @param bool   $expected Whether it is supported.
	 * @return void
	 */
	public function test_wordpress_supported( string $version, bool $expected ): void {
		$this->assertSame( $expected, AdapterBootstrap::wordpress_supported( $version ) );
	}
}
