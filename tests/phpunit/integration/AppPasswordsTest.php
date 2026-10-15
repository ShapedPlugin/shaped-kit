<?php
/**
 * AppPasswords: minting a credential for an AI client.
 *
 * Risk covered: a credential made for the wrong user, a password that does not actually work, a dead
 * button with no reason, and the plain password leaking into an error.
 *
 * @package ShapedKit
 */

use ShapedKit\Mcp\AppPasswords;

/**
 * Real WordPress: the passwords are real, and the test signs in with the one it made.
 */
final class AppPasswordsTest extends WP_UnitTestCase {

	/**
	 * The signed-in administrator.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Sign in as an administrator, with application passwords switched on (a test site is not HTTPS).
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Remove the filters this file added.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'wp_is_application_passwords_available' );
		remove_all_filters( 'wp_is_application_passwords_available_for_user' );

		parent::tear_down();
	}

	public function test_a_password_is_made_for_the_signed_in_user_and_really_works(): void {
		$made = AppPasswords::create();

		$this->assertIsArray( $made );
		$this->assertSame( get_userdata( $this->admin_id )->user_login, $made['username'] );
		$this->assertSame( AppPasswords::DEFAULT_NAME, $made['name'] );
		$this->assertMatchesRegularExpression( '/^(\w{4} ){5}\w{4}$/', $made['password'], 'Six groups of four, as the profile page shows.' );

		// Core only accepts these on API requests, and this is not one.
		add_filter( 'application_password_is_api_request', '__return_true' );
		$user = wp_authenticate_application_password( null, $made['username'], $made['password'] );
		remove_filter( 'application_password_is_api_request', '__return_true' );

		$this->assertInstanceOf( WP_User::class, $user, 'The password signs in.' );
		$this->assertSame( $this->admin_id, $user->ID );
	}

	public function test_only_a_hash_is_stored_never_the_plain_password(): void {
		$made   = AppPasswords::create();
		$stored = WP_Application_Passwords::get_user_application_passwords( $this->admin_id );

		$this->assertStringNotContainsString( str_replace( ' ', '', $made['password'] ), wp_json_encode( $stored ) );
	}

	public function test_a_second_password_gets_its_own_name_instead_of_failing(): void {
		$first  = AppPasswords::create();
		$second = AppPasswords::create();
		$third  = AppPasswords::create( 'Shaped Kit MCP' );

		$this->assertSame( 'Shaped Kit MCP', $first['name'] );
		$this->assertSame( 'Shaped Kit MCP 2', $second['name'] );
		$this->assertSame( 'Shaped Kit MCP 3', $third['name'] );
	}

	public function test_a_name_is_made_plain_text_and_blank_falls_back(): void {
		$this->assertSame( 'Laptop', AppPasswords::create( '<b>Laptop</b>' )['name'] );
		$this->assertSame( AppPasswords::DEFAULT_NAME, AppPasswords::create( "  \n " )['name'], 'A blank name is the default.' );
	}

	public function test_unique_name_skips_every_taken_suffix(): void {
		$this->assertSame( 'A', AppPasswords::unique_name( 'A', array() ) );
		$this->assertSame( 'A 4', AppPasswords::unique_name( 'A', array( 'A', 'A 2', 'A 3' ) ) );
		$this->assertSame( 'A 2', AppPasswords::unique_name( 'A', array( 'A', 'A 3' ) ), 'The first gap is used.' );
	}

	public function test_it_says_why_when_it_cannot(): void {
		remove_all_filters( 'wp_is_application_passwords_available' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		$this->assertSame( 'site_disabled', AppPasswords::support()['reason'] );

		remove_all_filters( 'wp_is_application_passwords_available' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_false' );
		$this->assertSame( 'user_disabled', AppPasswords::support()['reason'] );

		wp_set_current_user( 0 );
		$this->assertSame( 'no_user', AppPasswords::support()['reason'] );
	}

	public function test_a_refusal_creates_nothing_and_names_the_reason(): void {
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_false' );

		$result = AppPasswords::create();

		$this->assertWPError( $result );
		$this->assertSame( 'shaped_kit_app_password_unavailable', $result->get_error_code() );
		$this->assertSame( 'user_disabled', $result->get_error_data()['reason'] );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), WP_Application_Passwords::get_user_application_passwords( $this->admin_id ) );
	}

	/**
	 * The names the signed-in administrator's passwords have now.
	 *
	 * @return string[]
	 */
	private function names(): array {
		return array_values( wp_list_pluck( WP_Application_Passwords::get_user_application_passwords( $this->admin_id ), 'name' ) );
	}

	public function test_replacing_revokes_the_old_kit_password_and_keeps_the_new_one_working(): void {
		$old = AppPasswords::create();
		$new = AppPasswords::create( '', true );

		$this->assertSame( 1, $new['revoked'] );
		$this->assertSame( array( AppPasswords::DEFAULT_NAME ), $this->names(), 'One is left, and the name did not climb to "2".' );
		$this->assertNull( WP_Application_Passwords::get_user_application_password( $this->admin_id, $old['uuid'] ) );
		$this->assertIsArray( WP_Application_Passwords::get_user_application_password( $this->admin_id, $new['uuid'] ) );
	}

	public function test_a_failed_save_leaves_the_old_password_in_place(): void {
		$old = AppPasswords::create();

		// The first save of the user's passwords is the new one. Making it fail must not have revoked the old.
		add_filter(
			'update_user_metadata',
			static function ( $check, $object_id, $meta_key ) {
				return '_application_passwords' === $meta_key ? false : $check;
			},
			10,
			3
		);
		$result = AppPasswords::create( '', true );
		remove_all_filters( 'update_user_metadata' );

		$this->assertWPError( $result );
		$this->assertIsArray( WP_Application_Passwords::get_user_application_password( $this->admin_id, $old['uuid'] ), 'Never left with none.' );
	}

	public function test_replacing_spares_passwords_the_user_made_by_hand(): void {
		WP_Application_Passwords::create_new_application_password( $this->admin_id, array( 'name' => 'Shaped Kit MCP - CI deploy bot' ) );
		WP_Application_Passwords::create_new_application_password( $this->admin_id, array( 'name' => 'Cursor on laptop' ) );
		AppPasswords::create();

		$made = AppPasswords::create( '', true );

		$this->assertSame( 1, $made['revoked'] );
		$this->assertEqualsCanonicalizing(
			array( 'Shaped Kit MCP - CI deploy bot', 'Cursor on laptop', AppPasswords::DEFAULT_NAME ),
			$this->names()
		);
	}

	public function test_a_custom_named_password_made_here_is_still_ours_to_revoke(): void {
		AppPasswords::create( 'My Claude Desktop' );

		$this->assertSame( 1, AppPasswords::revoke_previous_passwords() );
		$this->assertSame( array(), $this->names() );
	}

	public function test_a_password_from_before_app_id_existed_is_matched_by_its_default_name(): void {
		WP_Application_Passwords::create_new_application_password( $this->admin_id, array( 'name' => 'Shaped Kit MCP' ) );
		WP_Application_Passwords::create_new_application_password( $this->admin_id, array( 'name' => 'Shaped Kit MCP 2' ) );

		$this->assertSame( 2, AppPasswords::revoke_previous_passwords() );
	}

	public function test_revoking_touches_only_the_signed_in_users_passwords(): void {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );
		AppPasswords::create();
		wp_set_current_user( $this->admin_id );
		AppPasswords::create();

		$this->assertSame( 1, AppPasswords::revoke_previous_passwords() );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( $other ), 'The other administrator keeps theirs.' );
	}

	public function test_only_a_real_deletion_is_counted(): void {
		AppPasswords::create();
		AppPasswords::create();

		// Core could not save the deletion: nothing was revoked, and the answer must not claim it was.
		add_filter(
			'update_user_metadata',
			static function ( $check, $object_id, $meta_key ) {
				return '_application_passwords' === $meta_key ? false : $check;
			},
			10,
			3
		);
		$count = AppPasswords::revoke_previous_passwords();
		remove_all_filters( 'update_user_metadata' );

		$this->assertSame( 0, $count );
		$this->assertCount( 2, $this->names() );
	}

	public function test_support_reports_the_users_login_and_the_profile_link(): void {
		$support = AppPasswords::support();

		$this->assertTrue( $support['available'] );
		$this->assertSame( get_userdata( $this->admin_id )->user_login, $support['user_login'] );
		$this->assertStringContainsString( 'profile.php#application-passwords-section', $support['manage_url'] );
	}
}
