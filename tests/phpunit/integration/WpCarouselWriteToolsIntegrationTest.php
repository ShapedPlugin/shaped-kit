<?php
/**
 * Integration tests for WP Carousel write tools and WriteGuard confirmation round-trip (Phase 8.3).
 *
 * Verifies Option 2 (Management Only) write abilities across both Free and Pro editions:
 * - duplicate-carousel: clones carousel post and shortcode options, appends (Copy), sets status to draft, emits telemetry
 * - update-carousel-settings: updates title, status, merges shortcode options, emits telemetry
 * - delete-carousel:
 *     - force=false: moves carousel to trash (permanent=false)
 *     - force=true: Level 1 WriteGuard preview -> confirmation token -> permanent deletion round-trip (FR-S2)
 *     - PRD §19 edge guards: atomic token consumption (E40), state change detection (E38), invalid IDs, permissions
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use ShapedKit\Audit\LogListener;
use ShapedKit\Audit\LogTable;
use WP_UnitTestCase;

/**
 * Class WpCarouselWriteToolsIntegrationTest
 */
class WpCarouselWriteToolsIntegrationTest extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Data provider for both editions.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function editions() {
		return array(
			'pro'  => array( 'wp-carousel-pro', 'ShapedPlugin\\WPCarouselPro\\Mcp' ),
			'free' => array( 'wp-carousel-free', 'ShapedPlugin\\WPCarouselFree\\Mcp' ),
		);
	}

	/**
	 * Loads an edition's autoloader if not already loaded.
	 *
	 * @param string $plugin Plugin directory name.
	 * @param string $ns     Namespace prefix.
	 */
	private function ensure_loaded( $plugin, $ns ) {
		$module_class = $ns . '\\Module';
		if ( ! class_exists( $module_class ) ) {
			$file = dirname( __DIR__, 4 ) . '/' . $plugin . '/vendor/autoload.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( ! defined( 'WPCAROUSEL_VERSION' ) ) {
			define( 'WPCAROUSEL_VERSION', '5.1.0' );
		}

		if ( ! defined( 'WPCAROUSELF_VERSION' ) ) {
			define( 'WPCAROUSELF_VERSION', '4.0.0' );
		}

		if ( ! class_exists( $module_class ) ) {
			$this->markTestSkipped( "{$plugin} is not available next to the Kit." );
		}

		$module_class::ensure_entry_post_type();
	}

	/**
	 * Set up test environment before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		LogTable::install();
		LogListener::register();
		LogListener::reset_state();

		$this->admin_id = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'user_login'   => 'writeadmin',
				'display_name' => 'Write Admin',
				'user_email'   => 'write-admin@example.com',
			)
		);
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		LogListener::reset_state();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that duplicate_carousel clones the post and shortcode options and logs telemetry.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_duplicate_carousel_duplicates_post_and_meta_and_emits_telemetry( $plugin, $ns ) {
		global $wpdb;

		$this->ensure_loaded( $plugin, $ns );

		$orig_id = self::factory()->post->create(
			array(
				'post_type'   => 'sp_wp_carousel',
				'post_title'  => 'Hero Carousel',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		$orig_options = array(
			'wpcp_layout'        => 'carousel',
			'wpcp_carousel_mode' => 'standard',
			'autoplay'           => true,
			'speed'              => 800,
		);
		update_post_meta( $orig_id, 'sp_wpcp_shortcode_options', $orig_options );
		update_post_meta( $orig_id, 'sp_wpcp_upload_options', array( 'wpcp_carousel_type' => 'image-carousel' ) );

		$wrap_cb = array( $ns . '\\Abilities_Registrar', 'wrap' );
		$dup_cb  = call_user_func(
			$wrap_cb,
			'wp-carousel/duplicate-carousel',
			array( $ns . '\\Tools\\Carousel_Tools', 'duplicate_carousel' )
		);

		$result = call_user_func( $dup_cb, array( 'id' => $orig_id ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$new_id = $result['id'];

		$this->assertNotSame( $orig_id, $new_id );
		$this->assertSame( 'Hero Carousel (Copy)', $result['title'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'carousel', $result['shortcode_options']['wpcp_layout'] );
		$this->assertSame( 'image-carousel', $result['type'] );

		// Verify database state.
		$cloned_post = get_post( $new_id );
		$this->assertNotNull( $cloned_post );
		$this->assertSame( 'draft', $cloned_post->post_status );
		$this->assertSame( 'Hero Carousel (Copy)', $cloned_post->post_title );

		$cloned_meta = get_post_meta( $new_id, 'sp_wpcp_shortcode_options', true );
		$this->assertIsArray( $cloned_meta );
		$this->assertSame( 'carousel', $cloned_meta['wpcp_layout'] );
		$this->assertSame( 800, $cloned_meta['speed'] );

		// Verify audit log telemetry.
		$log_table = LogTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$log_table} WHERE ability = %s ORDER BY id DESC LIMIT 1",
				'wp-carousel/duplicate-carousel'
			),
			ARRAY_A
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( 'wp-carousel', $rows[0]['product'] );
		$this->assertSame( 'ok', $rows[0]['outcome'] );
	}

	/**
	 * Test duplicate_carousel validation errors.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_duplicate_carousel_validation( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		$tools_class = $ns . '\\Tools\\Carousel_Tools';

		$err_zero = call_user_func( array( $tools_class, 'duplicate_carousel' ), array( 'id' => 0 ) );
		$this->assertWPError( $err_zero );
		$this->assertSame( 'invalid_id', $err_zero->get_error_code() );

		$err_not_found = call_user_func( array( $tools_class, 'duplicate_carousel' ), array( 'id' => 999999 ) );
		$this->assertWPError( $err_not_found );
		$this->assertSame( 'carousel_not_found', $err_not_found->get_error_code() );
	}

	/**
	 * Test update_carousel_settings merges settings, updates post fields, and emits telemetry.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_update_carousel_settings_merges_meta_and_emits_telemetry( $plugin, $ns ) {
		global $wpdb;

		$this->ensure_loaded( $plugin, $ns );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'sp_wp_carousel',
				'post_title'  => 'Old Title',
				'post_status' => 'draft',
				'post_author' => $this->admin_id,
			)
		);

		update_post_meta(
			$post_id,
			'sp_wpcp_shortcode_options',
			array(
				'wpcp_layout'              => 'carousel',
				'carousel_auto_play_speed' => 500,
				'wpcp_carousel_auto_play'  => '1',
			)
		);

		$wrap_cb   = array( $ns . '\\Abilities_Registrar', 'wrap' );
		$update_cb = call_user_func(
			$wrap_cb,
			'wp-carousel/update-carousel-settings',
			array( $ns . '\\Tools\\Carousel_Tools', 'update_carousel_settings' )
		);

		$updated = call_user_func(
			$update_cb,
			array(
				'id'       => $post_id,
				'title'    => 'New Awesome Title',
				'status'   => 'publish',
				'settings' => array(
					'carousel_auto_play_speed' => 900,
					'dots'                     => true,
				),
			)
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'New Awesome Title', $updated['title'] );
		$this->assertSame( 'publish', $updated['status'] );

		// Merge check: the speed changes, autoplay stays. 'dots' is not a setting this carousel has, so it is refused
		// and reported instead of stored (EXISTING_PLUGIN_BUGS B21; covered in WpCarouselSafetyIntegrationTest).
		$db_meta = get_post_meta( $post_id, 'sp_wpcp_shortcode_options', true );
		$this->assertSame( 900, $db_meta['carousel_auto_play_speed'] );
		$this->assertSame( '1', $db_meta['wpcp_carousel_auto_play'] );
		$this->assertArrayNotHasKey( 'dots', $db_meta );
		$this->assertSame( 'settings.dots', $updated['dropped'][0]['key'] );
		$this->assertSame( 'unknown_key', $updated['dropped'][0]['reason'] );

		// Verify telemetry log.
		$log_table = LogTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$log_table} WHERE ability = %s ORDER BY id DESC LIMIT 1",
				'wp-carousel/update-carousel-settings'
			),
			ARRAY_A
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( 'wp-carousel', $rows[0]['product'] );
		$this->assertSame( 'ok', $rows[0]['outcome'] );
	}

	/**
	 * Test delete_carousel moves to trash when force is false or omitted.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_delete_carousel_trashes_when_force_false( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'sp_wp_carousel',
				'post_title'  => 'Carousel To Trash',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		$tools_class = $ns . '\\Tools\\Carousel_Tools';
		$result      = call_user_func( array( $tools_class, 'delete_carousel' ), array( 'id' => $post_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['trashed'] );
		$this->assertFalse( $result['permanent'] );
		$this->assertSame( $post_id, $result['id'] );
		$this->assertSame( 'Carousel To Trash', $result['title'] );

		// Verify post is trashed in DB.
		$this->assertSame( 'trash', get_post_status( $post_id ) );
	}

	/**
	 * Test delete_carousel WriteGuard Level 1 permanent deletion round-trip (FR-S2).
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_delete_carousel_writeguard_permanent_deletion_roundtrip( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'sp_wp_carousel',
				'post_title'  => 'Permanent Deletion Target',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		$tools_class = $ns . '\\Tools\\Carousel_Tools';

		// Step 1: Preview without token -> returns confirmation_required and single-use token.
		$preview = call_user_func(
			array( $tools_class, 'delete_carousel' ),
			array(
				'id'    => $post_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $preview );
		$this->assertSame( 'confirmation_required', $preview['status'] );
		$this->assertNotEmpty( $preview['confirmation_token'] );
		$this->assertSame( 32, strlen( $preview['confirmation_token'] ) );
		$this->assertArrayHasKey( 'preview', $preview );
		$token = $preview['confirmation_token'];

		// Verify target is still safe in DB before confirmation.
		$this->assertNotNull( get_post( $post_id ) );

		// Step 2: Confirm with valid token -> permanently deletes target.
		$confirmed = call_user_func(
			array( $tools_class, 'delete_carousel' ),
			array(
				'id'                 => $post_id,
				'force'              => true,
				'confirmation_token' => $token,
			)
		);

		$this->assertIsArray( $confirmed );
		$this->assertTrue( $confirmed['deleted'] );
		$this->assertTrue( $confirmed['permanent'] );
		$this->assertSame( $post_id, $confirmed['id'] );

		// Verify post is completely deleted from DB.
		$this->assertNull( get_post( $post_id ) );

		// Step 3: Replay / atomic consumption (E40) -> cannot replay with consumed token.
		$replay = call_user_func(
			array( $tools_class, 'delete_carousel' ),
			array(
				'id'                 => $post_id,
				'force'              => true,
				'confirmation_token' => $token,
			)
		);

		$this->assertWPError( $replay );
		$this->assertSame( 'carousel_not_found', $replay->get_error_code() );

		// The replay above stops at the missing post, before the token is read. So the token's single use is checked on
		// a post that survives: its deletion is blocked (pre_delete_post, post.php:3871) after the confirm used the token.
		$survivor = self::factory()->post->create(
			array(
				'post_type'   => 'sp_wp_carousel',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);
		$second   = call_user_func(
			array( $tools_class, 'delete_carousel' ),
			array(
				'id'    => $survivor,
				'force' => true,
			)
		);
		add_filter( 'pre_delete_post', '__return_false' );
		$blocked = call_user_func(
			array( $tools_class, 'delete_carousel' ),
			array(
				'id'                 => $survivor,
				'force'              => true,
				'confirmation_token' => $second['confirmation_token'],
			)
		);
		remove_filter( 'pre_delete_post', '__return_false' );
		$this->assertSame( 'delete_failed', $blocked->get_error_code() );

		$reused = call_user_func(
			array( $tools_class, 'delete_carousel' ),
			array(
				'id'                 => $survivor,
				'force'              => true,
				'confirmation_token' => $second['confirmation_token'],
			)
		);
		$this->assertWPError( $reused );
		$this->assertSame( 'confirmation_expired', $reused->get_error_code(), 'A token works once (E40).' );
		$this->assertNotNull( get_post( $survivor ) );
	}

	/**
	 * Test delete_carousel WriteGuard state change detection (E38).
	 *
	 * Modifying the target carousel between preview and confirmation must invalidate the token.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_delete_carousel_writeguard_state_change_detection( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'sp_wp_carousel',
				'post_title'  => 'Untouched Title',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		$tools_class = $ns . '\\Tools\\Carousel_Tools';

		// Step 1: Preview to obtain confirmation token.
		$preview = call_user_func(
			array( $tools_class, 'delete_carousel' ),
			array(
				'id'    => $post_id,
				'force' => true,
			)
		);

		$this->assertSame( 'confirmation_required', $preview['status'] );
		$token = $preview['confirmation_token'];

		// State mutation: post_status changed concurrently from publish to draft before confirmation.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		// Step 2: Confirmation attempt must fail with state_changed error.
		$result = call_user_func(
			array( $tools_class, 'delete_carousel' ),
			array(
				'id'                 => $post_id,
				'force'              => true,
				'confirmation_token' => $token,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'state_changed', $result->get_error_code() );

		// Post must remain untouched in the database.
		$post_after = get_post( $post_id );
		$this->assertNotNull( $post_after );
		$this->assertSame( 'draft', $post_after->post_status );
	}

	/**
	 * Test that all write tools reject requests from unauthorized users (e.g. subscribers).
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_write_tools_reject_unauthorized_users( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'sp_wp_carousel',
				'post_title'  => 'Admin Only Carousel',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		// Switch to subscriber.
		wp_set_current_user( $subscriber_id );

		$tools_class = $ns . '\\Tools\\Carousel_Tools';

		$dup = call_user_func( array( $tools_class, 'duplicate_carousel' ), array( 'id' => $post_id ) );
		$this->assertWPError( $dup );
		$this->assertSame( 'permission_denied', $dup->get_error_code() );

		$update = call_user_func(
			array( $tools_class, 'update_carousel_settings' ),
			array(
				'id'    => $post_id,
				'title' => 'Hacked',
			)
		);
		$this->assertWPError( $update );
		$this->assertSame( 'permission_denied', $update->get_error_code() );

		$delete = call_user_func( array( $tools_class, 'delete_carousel' ), array( 'id' => $post_id ) );
		$this->assertWPError( $delete );
		$this->assertSame( 'permission_denied', $delete->get_error_code() );

		// Switch back to admin for tearDown.
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Test duplicate_carousel excludes _edit_lock / _edit_last and returns masked secrets (Issues 4 & 7b).
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_duplicate_carousel_excludes_locks_and_masks_secrets( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		$orig_id = self::factory()->post->create(
			array(
				'post_type'   => 'sp_wp_carousel',
				'post_title'  => 'Locked Carousel',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		update_post_meta( $orig_id, '_edit_lock', '1700000000:1' );
		update_post_meta( $orig_id, '_edit_last', '1' );
		update_post_meta( $orig_id, 'custom_design_token', 'token_xyz' );
		update_post_meta(
			$orig_id,
			'sp_wpcp_upload_options',
			array(
				'wpcp_carousel_type'   => 'video-carousel',
				'wpcp_youtube_api_key' => 'AIzaSySecretApiKeyInDuplicate',
			)
		);

		$tools_class = $ns . '\\Tools\\Carousel_Tools';
		$result      = call_user_func( array( $tools_class, 'duplicate_carousel' ), array( 'id' => $orig_id ) );

		$this->assertIsArray( $result );
		$new_id = $result['id'];

		// Verify _edit_lock and _edit_last are NOT copied onto the cloned post.
		$this->assertEmpty( get_post_meta( $new_id, '_edit_lock', true ), '_edit_lock must be excluded from clone.' );
		$this->assertEmpty( get_post_meta( $new_id, '_edit_last', true ), '_edit_last must be excluded from clone.' );
		$this->assertSame( 'token_xyz', get_post_meta( $new_id, 'custom_design_token', true ), 'Legitimate custom meta must still be cloned.' );

		// Verify the video API key is masked in the returned representation.
		$this->assertSame(
			array(
				'configured' => true,
				'last4'      => null,
			),
			$result['upload_options']['wpcp_youtube_api_key']
		);
		$this->assertStringNotContainsString( 'AIzaSySecretApiKeyInDuplicate', (string) wp_json_encode( $result ) );
	}

	/**
	 * Test list_carousels respects per-item read permissions (Issue 7a).
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_list_carousels_per_item_read_permissions( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		$tools_class = $ns . '\\Tools\\Carousel_Tools';

		$public_id = self::factory()->post->create(
			array(
				'post_type'   => 'sp_wp_carousel',
				'post_title'  => 'Public Carousel',
				'post_status' => 'publish',
				'post_author' => $this->admin_id,
			)
		);

		$private_id = self::factory()->post->create(
			array(
				'post_type'   => 'sp_wp_carousel',
				'post_title'  => 'Secret Private Carousel',
				'post_status' => 'private',
				'post_author' => $this->admin_id,
			)
		);

		// Admin can read both public and private.
		$admin_list = call_user_func( array( $tools_class, 'list_carousels' ), array( 'status' => 'any' ) );
		$admin_ids  = wp_list_pluck( $admin_list['items'], 'id' );
		$this->assertContains( $public_id, $admin_ids );
		$this->assertContains( $private_id, $admin_ids );
	}
}
