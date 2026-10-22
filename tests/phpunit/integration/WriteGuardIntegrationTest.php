<?php
/**
 * Integration tests for WriteGuard.
 *
 * Verifies FR-S2 (Level 1 two-step destructive action confirmation),
 * atomic token consumption, state fingerprint validation, canonical argument hash checks,
 * and user isolation against WordPress test database.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use ShapedKit\Mcp\WriteGuard;
use WP_UnitTestCase;

/**
 * Class WriteGuardIntegrationTest
 */
class WriteGuardIntegrationTest extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test fixture.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that preview() returns the required confirmation payload and persists transient.
	 */
	public function test_preview_returns_confirmation_required_payload_and_stores_transient() {
		$ability     = 'real-testimonials/delete-testimonial';
		$entity_key  = 'testimonial:42';
		$fingerprint = 'fp_abc123';
		$preview     = array( 'summary' => 'Will delete testimonial 42' );
		$args        = array( 'id' => 42 );

		$response = WriteGuard::preview( $ability, $entity_key, $fingerprint, $preview, $args );

		$this->assertIsArray( $response );
		$this->assertSame( 'confirmation_required', $response['status'] );
		$this->assertSame( $preview, $response['preview'] );
		$this->assertSame( 300, $response['expires_in'] );
		$this->assertNotEmpty( $response['confirmation_token'] );
		$this->assertSame( 32, strlen( $response['confirmation_token'] ) );

		// Check transient was saved under user-scoped key.
		$trans_key = WriteGuard::confirm_key( $this->user_id, $ability, $entity_key );
		$stored    = get_transient( $trans_key );

		$this->assertIsArray( $stored );
		$this->assertSame( $response['confirmation_token'], $stored['token'] );
		$this->assertSame( $fingerprint, $stored['fingerprint'] );
		$this->assertSame( $this->user_id, $stored['user_id'] );
		$this->assertNotEmpty( $stored['arg_hash'] );
	}

	/**
	 * Test that confirming without a token returns a confirmation_required WP_Error.
	 */
	public function test_confirm_without_token_returns_confirmation_required_error() {
		$result = WriteGuard::confirm( 'test/tool', 'entity:1', 'fp', '', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'confirmation_required', $result->get_error_code() );
	}

	/**
	 * Test that confirming with an expired or non-existent token returns confirmation_expired.
	 */
	public function test_confirm_with_expired_or_missing_transient_returns_confirmation_expired() {
		$result = WriteGuard::confirm( 'test/tool', 'entity:nonexistent', 'fp', 'nonexistent_token_1234567890123456' );

		$this->assertWPError( $result );
		$this->assertSame( 'confirmation_expired', $result->get_error_code() );
	}

	/**
	 * Test that confirming with a mismatched token returns confirmation_invalid.
	 */
	public function test_confirm_with_wrong_token_returns_confirmation_invalid() {
		$ability     = 'real-testimonials/delete-testimonial';
		$entity_key  = 'testimonial:42';
		$fingerprint = 'fp_abc123';
		$args        = array( 'id' => 42 );

		WriteGuard::preview( $ability, $entity_key, $fingerprint, array( 'summary' => 'delete' ), $args );

		$result = WriteGuard::confirm( $ability, $entity_key, $fingerprint, 'wrong_token_1234567890123456789012', $args );

		$this->assertWPError( $result );
		$this->assertSame( 'confirmation_invalid', $result->get_error_code() );
	}

	/**
	 * Test that confirming with tampered arguments returns confirmation_invalid (PRD E42).
	 */
	public function test_confirm_with_tampered_arguments_returns_confirmation_invalid() {
		$ability     = 'real-testimonials/delete-testimonial';
		$entity_key  = 'testimonial:42';
		$fingerprint = 'fp_abc123';
		$args        = array(
			'id'    => 42,
			'force' => true,
		);

		$preview_res = WriteGuard::preview( $ability, $entity_key, $fingerprint, array( 'summary' => 'delete' ), $args );
		$token       = $preview_res['confirmation_token'];

		// Change argument from id: 42 to id: 99.
		$tampered_args = array(
			'id'    => 99,
			'force' => true,
		);

		$result = WriteGuard::confirm( $ability, $entity_key, $fingerprint, $token, $tampered_args );

		$this->assertWPError( $result );
		$this->assertSame( 'confirmation_invalid', $result->get_error_code() );
	}

	/**
	 * Test that confirming with changed state fingerprint aborts and drops token (PRD E38).
	 */
	public function test_confirm_with_changed_state_fingerprint_aborts_and_deletes_token() {
		$ability     = 'real-testimonials/delete-testimonial';
		$entity_key  = 'testimonial:42';
		$fingerprint = 'fp_original_v1';
		$args        = array( 'id' => 42 );

		$preview_res = WriteGuard::preview( $ability, $entity_key, $fingerprint, array( 'summary' => 'delete' ), $args );
		$token       = $preview_res['confirmation_token'];

		// Concurrently modified state fingerprint.
		$new_fingerprint = 'fp_modified_v2';

		$result = WriteGuard::confirm( $ability, $entity_key, $new_fingerprint, $token, $args );

		$this->assertWPError( $result );
		$this->assertSame( 'state_changed', $result->get_error_code() );

		// Token must have been dropped.
		$trans_key = WriteGuard::confirm_key( $this->user_id, $ability, $entity_key );
		$this->assertFalse( get_transient( $trans_key ) );
	}

	/**
	 * Test that confirming succeeds and consumes the token atomically (PRD E40).
	 */
	public function test_confirm_succeeds_and_atomically_consumes_token() {
		$ability     = 'real-testimonials/delete-testimonial';
		$entity_key  = 'testimonial:42';
		$fingerprint = 'fp_current';
		$args        = array( 'id' => 42 );

		$preview_res = WriteGuard::preview( $ability, $entity_key, $fingerprint, array( 'summary' => 'delete' ), $args );
		$token       = $preview_res['confirmation_token'];

		// First confirm succeeds.
		$result = WriteGuard::confirm( $ability, $entity_key, $fingerprint, $token, $args );
		$this->assertTrue( $result );

		// Second immediate confirm with the same token fails (single use).
		$repeat_result = WriteGuard::confirm( $ability, $entity_key, $fingerprint, $token, $args );
		$this->assertWPError( $repeat_result );
		$this->assertSame( 'confirmation_expired', $repeat_result->get_error_code() );
	}

	/**
	 * Test that a token minted by User A cannot be confirmed by User B (PRD E41).
	 */
	public function test_confirm_user_scoping_prevents_cross_user_abuse() {
		$ability     = 'real-testimonials/delete-testimonial';
		$entity_key  = 'testimonial:42';
		$fingerprint = 'fp_current';
		$args        = array( 'id' => 42 );

		// User A (current user) previews.
		$preview_res = WriteGuard::preview( $ability, $entity_key, $fingerprint, array( 'summary' => 'delete' ), $args );
		$token       = $preview_res['confirmation_token'];

		// Switch to User B.
		$user_b_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_b_id );

		// User B attempts to execute with User A's token.
		$result = WriteGuard::confirm( $ability, $entity_key, $fingerprint, $token, $args );

		$this->assertWPError( $result );
		$this->assertSame( 'confirmation_expired', $result->get_error_code() );
	}

	/**
	 * Test that canonical argument hashing ignores order and control keys.
	 */
	public function test_canonical_arg_hash_order_and_control_key_invariance() {
		$args_a = array(
			'title'              => 'Hello',
			'id'                 => 10,
			'confirmation_token' => 'tok123',
			'meta'               => array(
				'b' => 2,
				'a' => 1,
			),
		);

		$args_b = array(
			'meta'            => array(
				'a' => 1,
				'b' => 2,
			),
			'id'              => 10,
			'title'           => 'Hello',
			'idempotency_key' => 'idem_xyz',
			'dry_run'         => true,
		);

		$hash_a = WriteGuard::canonical_arg_hash( $args_a );
		$hash_b = WriteGuard::canonical_arg_hash( $args_b );

		$this->assertSame( $hash_a, $hash_b );
	}

	/**
	 * Test that idempotent() executes once and returns cached response with flag.
	 */
	public function test_idempotent_caches_and_replays() {
		$ability    = 'real-testimonials/update-testimonial';
		$entity_key = 'testimonial:100';
		$idem_key   = 'idem_req_abc';
		$exec_count = 0;

		$callback = function () use ( &$exec_count ) {
			++$exec_count;
			return array(
				'success' => true,
				'count'   => $exec_count,
			);
		};

		// First call executes.
		$first_result = WriteGuard::idempotent( $ability, $entity_key, $idem_key, $callback );
		$this->assertSame( 1, $exec_count );
		$this->assertSame( 1, $first_result['count'] );
		$this->assertArrayNotHasKey( 'idempotent_replay', $first_result );

		// Second call with same idempotency key does not re-execute.
		$second_result = WriteGuard::idempotent( $ability, $entity_key, $idem_key, $callback );
		$this->assertSame( 1, $exec_count );
		$this->assertSame( 1, $second_result['count'] );
		$this->assertTrue( $second_result['idempotent_replay'] );
	}

	/**
	 * Test that fingerprint_post() changes when post is modified.
	 */
	public function test_fingerprint_post_detects_modification() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Original Testimonial',
				'post_status'  => 'publish',
				'post_content' => 'Great product!',
			)
		);

		$post = get_post( $post_id );
		$fp1  = WriteGuard::fingerprint_post( $post );

		// Modify post status.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		// Clean post cache and re-fetch.
		clean_post_cache( $post_id );
		$post_updated = get_post( $post_id );
		$fp2          = WriteGuard::fingerprint_post( $post_updated );

		$this->assertNotSame( $fp1, $fp2 );
	}

	/**
	 * Arguments JSON cannot encode are refused, never hashed alike (PRD E190); plain hashes are unchanged.
	 */
	public function test_unencodable_arguments_are_refused_not_hashed_alike() {
		$this->assertSame(
			hash( 'sha256', '{"a":"x","b":1}' ),
			WriteGuard::canonical_arg_hash(
				array(
					'b' => 1,
					'a' => 'x',
				)
			),
			'Tokens issued before the change still confirm.'
		);
		$this->assertSame( '', WriteGuard::canonical_arg_hash( array( 'ratio' => NAN ) ) );

		$user    = self::factory()->user->create();
		$preview = WriteGuard::preview( 'shaped-kit/test', 'item:1', 'fp', array(), array( 'ratio' => NAN ), $user );
		$this->assertWPError( $preview );
		$this->assertSame( 'invalid_arguments', $preview->get_error_code() );
		$this->assertFalse( get_transient( WriteGuard::confirm_key( $user, 'shaped-kit/test', 'item:1' ) ), 'No token was stored.' );

		$valid   = WriteGuard::preview( 'shaped-kit/test', 'item:1', 'fp', array(), array( 'ratio' => 1 ), $user );
		$refused = WriteGuard::confirm( 'shaped-kit/test', 'item:1', 'fp', $valid['confirmation_token'], array( 'ratio' => INF ), $user );
		$this->assertSame( 'invalid_arguments', $refused->get_error_code() );
		$this->assertTrue( WriteGuard::confirm( 'shaped-kit/test', 'item:1', 'fp', $valid['confirmation_token'], array( 'ratio' => 1 ), $user ), 'The refusal did not spend the token.' );

		$post = self::factory()->post->create();
		$this->assertNotSame( WriteGuard::fingerprint_post( $post, array( 'n' => NAN ) ), WriteGuard::fingerprint_post( $post, array( 'n' => NAN ) ), 'An unencodable fingerprint never matches.' );
	}
}
