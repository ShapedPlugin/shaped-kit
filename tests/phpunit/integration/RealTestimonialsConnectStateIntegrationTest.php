<?php
/**
 * What the "MCP for AI Agents" settings card is told about connecting (PRD E127).
 *
 * The Quick Connect command is built in the browser from this state. The login fills it in, so it must be the
 * signed-in user's own; the password is never part of it. The links must go where the owner can make a password,
 * and the card must be told when the site does not allow application passwords at all. One body for Free and Pro
 * (PRD §20 rule 6).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use WP_UnitTestCase;

/**
 * Class RealTestimonialsConnectStateIntegrationTest
 */
class RealTestimonialsConnectStateIntegrationTest extends WP_UnitTestCase {

	/**
	 * Both editions.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function editions() {
		return array(
			'pro'  => array( 'testimonial-pro' ),
			'free' => array( 'testimonial-free' ),
		);
	}

	/**
	 * Load an edition and return its REST controller class.
	 *
	 * @param string $plugin Plugin folder.
	 * @return string
	 */
	private function controller( $plugin ) {
		$class = 'ShapedPlugin\\' . ( 'testimonial-pro' === $plugin ? 'TestimonialPro' : 'TestimonialFree' ) . '\\Mcp\\Rest_Controller';
		$file  = dirname( __DIR__, 4 ) . '/' . $plugin . '/vendor/autoload.php';

		if ( ! class_exists( $class ) && file_exists( $file ) ) {
			require_once $file;
		}
		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( $plugin . ' is not available next to the Kit.' );
		}

		return $class;
	}

	/**
	 * Clean up the user and the filters.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * The state carries the signed-in user's own login, and the links the owner needs.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_the_state_carries_the_login_and_where_to_make_the_password( $plugin ) {
		$controller = $this->controller( $plugin );
		$user_id    = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'connect_admin',
			)
		);
		wp_set_current_user( $user_id );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$connect = $controller::summary( true )['connect'];

		$this->assertSame( 'connect_admin', $connect['user_login'], 'The command is filled in with the signed-in user, nobody else.' );
		$this->assertTrue( $connect['passwords_available'] );
		$this->assertStringContainsString( 'admin.php?page=shaped-kit&connect=real-testimonials', $connect['kit_url'], 'The Kit link opens the connect panel for this product.' );
		$this->assertStringContainsString( 'profile.php#application-passwords-section', $connect['passwords_url'] );
		$this->assertArrayNotHasKey( 'password', $connect, 'No password is ever part of the state.' );

		$this->assertNull( $controller::summary( false )['connect']['kit_url'], 'Without the Kit there is no Kit link.' );
	}

	/**
	 * A site that switches application passwords off is told so, and so is a signed-out request.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_the_card_is_told_when_application_passwords_are_not_available( $plugin ) {
		$controller = $this->controller( $plugin );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		$this->assertFalse( $controller::summary( true )['connect']['passwords_available'], 'A site that switches them off is told so.' );
		remove_filter( 'wp_is_application_passwords_available', '__return_false' );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		wp_set_current_user( 0 );
		$signed_out = $controller::summary( true )['connect'];
		$this->assertSame( '', $signed_out['user_login'] );
		$this->assertFalse( $signed_out['passwords_available'] );
	}
}
