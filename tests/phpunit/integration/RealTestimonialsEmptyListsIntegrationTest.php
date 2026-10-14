<?php
/**
 * Regression test: a list tool returns an empty page when its post types are not registered.
 *
 * View_Tools::list_views() (both editions) and Form_Tools::list_forms() (Pro)
 * called Pagination::envelope(), which does not exist; when the post types
 * were missing, the agent got a generic tool failure instead of an empty list.
 * The test environment does not register these post types, so the branch runs.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use WP_UnitTestCase;

/**
 * Class RealTestimonialsEmptyListsIntegrationTest
 */
class RealTestimonialsEmptyListsIntegrationTest extends WP_UnitTestCase {

	/**
	 * List tools per edition: [ plugin folder, tools class, method ].
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function list_tools() {
		return array(
			'pro views'  => array( 'testimonial-pro', 'ShapedPlugin\\TestimonialPro\\Mcp\\Tools\\View_Tools', 'list_views' ),
			'free views' => array( 'testimonial-free', 'ShapedPlugin\\TestimonialFree\\Mcp\\Tools\\View_Tools', 'list_views' ),
			'pro forms'  => array( 'testimonial-pro', 'ShapedPlugin\\TestimonialPro\\Mcp\\Tools\\Form_Tools', 'list_forms' ),
		);
	}

	/**
	 * The tool answers with an empty, well-formed page.
	 *
	 * @dataProvider list_tools
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $tools  Tools class.
	 * @param string $method Tool method.
	 */
	public function test_missing_post_types_give_an_empty_page( $plugin, $tools, $method ) {
		$file = dirname( __DIR__, 4 ) . '/' . $plugin . '/vendor/autoload.php';
		if ( ! class_exists( $tools ) && file_exists( $file ) ) {
			require_once $file;
		}
		if ( ! class_exists( $tools ) || ! method_exists( $tools, $method ) ) {
			$this->markTestSkipped( $plugin . ' is not available next to the Kit.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = call_user_func( array( $tools, $method ), array( 'per_page' => 10 ) );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 0, $result['total'] );
		$this->assertSame( 10, $result['per_page'] );
	}
}
