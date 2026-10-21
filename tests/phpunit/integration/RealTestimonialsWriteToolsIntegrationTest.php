<?php
/**
 * Integration test for Real Testimonials MCP write tools and WriteGuard confirmation round-trip.
 *
 * Verifies FR-S2 (Level 1 two-step destructive action confirmation),
 * CPT post/meta mutations, audit log telemetry integration (FR-S3),
 * and PRD §19 edge guards (E38 state change, E40 atomic consumption, E42 argument tampering).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use ShapedKit\Audit\LogListener;
use ShapedKit\Audit\LogTable;
use ShapedPlugin\TestimonialPro\Mcp\Abilities_Registrar;
use ShapedPlugin\TestimonialPro\Mcp\Tools\Testimonial_Tools;
use WP_UnitTestCase;

/**
 * Class RealTestimonialsWriteToolsIntegrationTest
 */
class RealTestimonialsWriteToolsIntegrationTest extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load Testimonial Pro autoloader if not already loaded.
		$pro_autoload = dirname( __DIR__, 4 ) . '/testimonial-pro/vendor/autoload.php';
		if ( file_exists( $pro_autoload ) && ! class_exists( Testimonial_Tools::class ) ) {
			require_once $pro_autoload;
		}

		LogTable::install();
		LogListener::register();
		LogListener::reset_state();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Register spt_testimonial CPT if not already registered in test environment.
		if ( ! post_type_exists( 'spt_testimonial' ) ) {
			register_post_type(
				'spt_testimonial',
				array(
					'public'       => true,
					'show_ui'      => true,
					'supports'     => array( 'title', 'editor', 'thumbnail' ),
					'map_meta_cap' => true,
				)
			);
		}
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		LogListener::reset_state();
		parent::tearDown();
	}

	/**
	 * Test that create_testimonial creates a CPT draft, saves reviewer metadata, and logs telemetry.
	 */
	public function test_create_testimonial_persists_post_and_reviewer_meta_and_logs() {
		$wrapped_create = Abilities_Registrar::wrap(
			'real-testimonials/create-testimonial',
			array( Testimonial_Tools::class, 'create_testimonial' )
		);

		$input = array(
			'title'                => 'Outstanding Platform Experience',
			'content'              => 'The entire workflow is intuitive and exceptionally fast.',
			'status'               => 'draft',
			'rating'               => 5,
			'reviewer_name'        => 'Sarah Jenkins',
			'reviewer_designation' => 'Director of Product',
			'reviewer_company'     => 'Acme Global',
			'reviewer_location'    => 'San Francisco, CA',
		);

		$result = call_user_func( $wrapped_create, $input );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertSame( 'Outstanding Platform Experience', $result['title'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 5, $result['rating'] );
		$this->assertSame( 'Sarah Jenkins', $result['reviewer']['name'] );
		$this->assertSame( 'Acme Global', $result['reviewer']['company'] );

		// Verify database post state.
		$post = get_post( $result['id'] );
		$this->assertNotNull( $post );
		$this->assertSame( 'spt_testimonial', $post->post_type );
		$this->assertSame( 'draft', $post->post_status );

		// Verify post meta.
		$meta = get_post_meta( $result['id'], 'sp_tpro_meta_options', true );
		$this->assertIsArray( $meta );
		$this->assertSame( 'five_star', $meta['tpro_rating'] );
		$this->assertSame( 'Director of Product', $meta['tpro_designation'] );

		// Verify audit log row in shaped_kit_mcp_log.
		global $wpdb;
		$table_name = $wpdb->prefix . LogTable::TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE ability = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'real-testimonials/create-testimonial' // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'real-testimonials', $row['product'] );
		$this->assertSame( 'ok', $row['outcome'] );
		$this->assertSame( (string) $this->admin_id, (string) $row['user_id'] );
	}

	/**
	 * Test that update_testimonial modifies post title, review content, and reviewer metadata.
	 */
	public function test_update_testimonial_modifies_fields_and_reviewer_meta() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'spt_testimonial',
				'post_title'  => 'Original Title',
				'post_status' => 'draft',
			)
		);

		update_post_meta(
			$post_id,
			'sp_tpro_meta_options',
			array(
				'tpro_name'   => 'Old Name',
				'tpro_rating' => 'three_star',
			)
		);

		$input = array(
			'id'                   => $post_id,
			'title'                => 'Updated Title',
			'rating'               => 4,
			'reviewer_name'        => 'Updated Name',
			'reviewer_designation' => 'CTO',
		);

		$result = Testimonial_Tools::update_testimonial( $input );

		$this->assertIsArray( $result );
		$this->assertSame( 'Updated Title', $result['title'] );
		$this->assertSame( 4, $result['rating'] );
		$this->assertSame( 'Updated Name', $result['reviewer']['name'] );
		$this->assertSame( 'CTO', $result['reviewer']['designation'] );

		$updated_meta = get_post_meta( $post_id, 'sp_tpro_meta_options', true );
		$this->assertSame( 'four_star', $updated_meta['tpro_rating'] );
	}

	/**
	 * Test that delete_testimonial without force trashes the post without requiring a confirmation token.
	 */
	public function test_delete_testimonial_trash_moves_to_trash_without_token() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'spt_testimonial',
				'post_title'  => 'Testimonial to Trash',
				'post_status' => 'publish',
			)
		);

		$result = Testimonial_Tools::delete_testimonial(
			array(
				'id'    => $post_id,
				'force' => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['deleted'] );
		$this->assertTrue( $result['trashed'] );
		$this->assertFalse( $result['permanent'] );

		$post = get_post( $post_id );
		$this->assertSame( 'trash', $post->post_status );
	}

	/**
	 * Test that delete_testimonial with force requires Level 1 confirmation token round-trip.
	 */
	public function test_delete_testimonial_permanent_two_step_roundtrip_with_writeguard() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'spt_testimonial',
				'post_title'  => 'Testimonial for Permanent Deletion',
				'post_status' => 'publish',
			)
		);

		$wrapped_delete = Abilities_Registrar::wrap(
			'real-testimonials/delete-testimonial',
			array( Testimonial_Tools::class, 'delete_testimonial' )
		);

		// Step 1: Call without confirmation_token. Must not delete anything.
		$step1_input = array(
			'id'    => $post_id,
			'force' => true,
		);

		$step1_response = call_user_func( $wrapped_delete, $step1_input );

		$this->assertIsArray( $step1_response );
		$this->assertSame( 'confirmation_required', $step1_response['status'] );
		$this->assertNotEmpty( $step1_response['confirmation_token'] );
		$this->assertSame( 32, strlen( $step1_response['confirmation_token'] ) );

		// Verify post still exists after preview.
		$post_after_step1 = get_post( $post_id );
		$this->assertNotNull( $post_after_step1 );

		// Verify audit log recorded confirmation_required.
		global $wpdb;
		$table_name = $wpdb->prefix . LogTable::TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row_step1 = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE ability = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'real-testimonials/delete-testimonial'
			),
			ARRAY_A
		);
		$this->assertSame( 'confirmation_required', $row_step1['outcome'] );

		// Step 2: Call with the returned confirmation_token.
		$step2_input = array(
			'id'                 => $post_id,
			'force'              => true,
			'confirmation_token' => $step1_response['confirmation_token'],
		);

		$step2_response = call_user_func( $wrapped_delete, $step2_input );

		$this->assertIsArray( $step2_response );
		$this->assertTrue( $step2_response['deleted'] );
		$this->assertTrue( $step2_response['permanent'] );

		// Verify post is permanently deleted from database.
		clean_post_cache( $post_id );
		$post_after_step2 = get_post( $post_id );
		$this->assertNull( $post_after_step2 );

		// Verify audit log recorded confirmed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row_step2 = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE ability = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'real-testimonials/delete-testimonial'
			),
			ARRAY_A
		);
		$this->assertSame( 'confirmed', $row_step2['outcome'] );
	}

	/**
	 * Test that if post changes state between preview and confirm, action aborts with state_changed (PRD E38).
	 */
	public function test_delete_testimonial_aborts_if_record_changed_since_preview() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'spt_testimonial',
				'post_title'  => 'Will Change Title',
				'post_status' => 'publish',
			)
		);

		// Step 1: Preview.
		$step1_response = Testimonial_Tools::delete_testimonial(
			array(
				'id'    => $post_id,
				'force' => true,
			)
		);
		$token          = $step1_response['confirmation_token'];

		// Concurrent edit by another user/admin.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		clean_post_cache( $post_id );

		// Step 2: Try to execute with original token.
		$result = Testimonial_Tools::delete_testimonial(
			array(
				'id'                 => $post_id,
				'force'              => true,
				'confirmation_token' => $token,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'state_changed', $result->get_error_code() );

		// Post must NOT have been deleted.
		$post = get_post( $post_id );
		$this->assertNotNull( $post );
	}

	/**
	 * Test that create_testimonial, get_testimonial, and list_testimonials expose
	 * the reviewer_image_id and reviewer_image_url when a photo is attached.
	 */
	public function test_create_and_get_testimonial_exposes_reviewer_image_id_and_url() {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/10/sarah-jenkins.png',
				'post_mime_type' => 'image/png',
				'post_title'     => 'Sarah Jenkins',
			)
		);

		$result = Testimonial_Tools::create_testimonial(
			array(
				'title'             => 'Great Service',
				'content'           => 'Very satisfied with the platform.',
				'status'            => 'publish',
				'reviewer_name'     => 'Sarah Jenkins',
				'reviewer_image_id' => $attachment_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $attachment_id, $result['reviewer_image_id'] );
		$this->assertNotNull( $result['reviewer_image_url'] );
		$this->assertArrayNotHasKey( 'image_id', $result['reviewer'], 'One copy of the photo per record (E179).' );
		$this->assertArrayNotHasKey( 'image_url', $result['reviewer'] );
		$this->assertStringContainsString( 'sarah-jenkins.png', $result['reviewer_image_url'] );

		// Verify get_testimonial exposes the same reviewer image fields.
		$read = Testimonial_Tools::get_testimonial( array( 'id' => $result['id'] ) );
		$this->assertIsArray( $read );
		$this->assertSame( $attachment_id, $read['reviewer_image_id'] );
		$this->assertSame( $result['reviewer_image_url'], $read['reviewer_image_url'] );

		// Verify list_testimonials also exposes the reviewer image fields.
		$list = Testimonial_Tools::list_testimonials( array( 'per_page' => 10 ) );
		$this->assertIsArray( $list );
		$found = false;
		foreach ( $list['items'] as $item ) {
			if ( $item['id'] === $result['id'] ) {
				$found = true;
				$this->assertSame( $attachment_id, $item['reviewer_image_id'] );
				$this->assertSame( $result['reviewer_image_url'], $item['reviewer_image_url'] );
			}
		}
		$this->assertTrue( $found, 'Created testimonial with image must be found in list_testimonials.' );
	}

	/**
	 * Guardrails: Test that reviewer_image fields return null when no image is set,
	 * or when the thumbnail points to a deleted attachment or a non-image attachment.
	 */
	public function test_reviewer_image_returns_null_when_missing_or_invalid() {
		// 1. Without image:
		$no_img = Testimonial_Tools::create_testimonial(
			array(
				'title'         => 'No Image Testimonial',
				'content'       => 'Review without photo.',
				'reviewer_name' => 'John Doe',
			)
		);
		$this->assertNull( $no_img['reviewer_image_id'] );
		$this->assertNull( $no_img['reviewer_image_url'] );

		// 2. Dangling thumbnail (deleted attachment):
		$fake_thumb_id = 999999;
		set_post_thumbnail( $no_img['id'], $fake_thumb_id );
		clean_post_cache( $no_img['id'] );

		$read_dangling = Testimonial_Tools::get_testimonial( array( 'id' => $no_img['id'] ) );
		$this->assertNull( $read_dangling['reviewer_image_id'], 'Dangling thumbnail ID must return null.' );
		$this->assertNull( $read_dangling['reviewer_image_url'] );

		// 3. Non-image attachment (e.g. video):
		$video_id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/10/clip.mp4',
				'post_mime_type' => 'video/mp4',
				'post_title'     => 'Video Clip',
			)
		);
		set_post_thumbnail( $no_img['id'], $video_id );
		clean_post_cache( $no_img['id'] );

		$read_video = Testimonial_Tools::get_testimonial( array( 'id' => $no_img['id'] ) );
		$this->assertNull( $read_video['reviewer_image_id'], 'Non-image attachment must return null.' );
		$this->assertNull( $read_video['reviewer_image_url'] );

		// 4. Permission check (E156): Attachment on another user's private draft.
		$editor_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$draft_id   = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $editor_id,
			)
		);
		$hidden_img = self::factory()->attachment->create_object(
			array(
				'post_parent'    => $draft_id,
				'post_author'    => $editor_id,
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
				'file'           => '2026/10/hidden.png',
			)
		);

		$author_id      = self::factory()->user->create( array( 'role' => 'author' ) );
		$my_testimonial = $this->factory->post->create(
			array(
				'post_type'   => 'spt_testimonial',
				'post_title'  => 'Author Testimonial',
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);
		set_post_thumbnail( $my_testimonial, $hidden_img );
		clean_post_cache( $my_testimonial );

		wp_set_current_user( $author_id );
		$read_hidden = Testimonial_Tools::get_testimonial( array( 'id' => $my_testimonial ) );
		$this->assertIsArray( $read_hidden );
		$this->assertNull( $read_hidden['reviewer_image_id'], 'Unreadable attachment must return null (E156).' );
		$this->assertNull( $read_hidden['reviewer_image_url'] );

		// Restore admin user.
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * E174, E178: `update-testimonial` takes a photo over MCP (its schema allows the key, so the adapter lets it
	 * through), 0 removes it, and an id that is not a readable image is reported under `dropped`, photo unchanged.
	 * The company logo is not an update property (E177: Free locks it), so the schema refuses it.
	 */
	public function test_update_testimonial_sets_clears_and_reports_the_reviewer_photo_through_its_schema() {
		$img1  = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/10/img1.png',
				'post_mime_type' => 'image/png',
				'post_title'     => 'Image 1',
			)
		);
		$img2  = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/10/img2.png',
				'post_mime_type' => 'image/png',
				'post_title'     => 'Image 2',
			)
		);
		$video = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/10/clip.mp4',
				'post_mime_type' => 'video/mp4',
				'post_title'     => 'Clip',
			)
		);

		$schema = Abilities_Registrar::definitions()['real-testimonials/update-testimonial']['input_schema'];
		$update = static function ( array $input ) use ( $schema ) {
			// What WP_Ability::validate_input() runs before the callback (class-wp-ability.php:536).
			$valid = rest_validate_value_from_schema( $input, $schema, 'input' );
			return is_wp_error( $valid ) ? $valid : Testimonial_Tools::update_testimonial( $input );
		};

		$post = Testimonial_Tools::create_testimonial(
			array(
				'title'             => 'Update Image Testimonial',
				'content'           => 'Initial content',
				'reviewer_name'     => 'Alice',
				'reviewer_image_id' => $img1,
			)
		);
		$this->assertSame( $img1, $post['reviewer_image_id'] );

		$updated = $update(
			array(
				'id'                => $post['id'],
				'reviewer_image_id' => $img2,
			)
		);
		$this->assertIsArray( $updated, 'The schema accepts the photo.' );
		$this->assertSame( $img2, $updated['reviewer_image_id'] );
		$this->assertArrayNotHasKey( 'dropped', $updated );

		foreach ( array( $video, 999999 ) as $bad ) {
			$refused = $update(
				array(
					'id'                => $post['id'],
					'reviewer_image_id' => $bad,
					'reviewer_name'     => 'Alice B',
				)
			);
			$this->assertSame( $img2, $refused['reviewer_image_id'], 'A refused id leaves the photo as it was.' );
			$this->assertSame( 'Alice B', $refused['reviewer']['name'], 'The rest of the update is still written.' );
			$this->assertSame( array( 'reviewer_image_id' ), wp_list_pluck( $refused['dropped'], 'key' ) );
			$this->assertSame( 'not_found', $refused['dropped'][0]['reason'] );
		}

		$cleared = $update(
			array(
				'id'                => $post['id'],
				'reviewer_image_id' => 0,
			)
		);
		$this->assertNull( $cleared['reviewer_image_id'] );
		$this->assertNull( $cleared['reviewer_image_url'] );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $post['id'] ) );

		$this->assertWPError(
			$update(
				array(
					'id'                => $post['id'],
					'reviewer_image_id' => -1,
				)
			)
		);
		$meta = get_post_meta( $post['id'], 'sp_tpro_meta_options', true );
		$this->assertWPError(
			$update(
				array(
					'id'              => $post['id'],
					'company_logo_id' => $img1,
				)
			),
			'Not an update property.'
		);
		$this->assertSame( $meta, get_post_meta( $post['id'], 'sp_tpro_meta_options', true ), 'Nothing written.' );
	}

	/**
	 * E179: a list reads every photo with one primed query, not several per testimonial.
	 */
	public function test_list_testimonials_reads_photos_without_a_query_per_item() {
		$ids = array();
		foreach ( range( 1, 6 ) as $n ) {
			$img   = self::factory()->attachment->create_object(
				array(
					'file'           => "2026/10/p{$n}.png",
					'post_mime_type' => 'image/png',
					'post_title'     => "P{$n}",
				)
			);
			$ids[] = Testimonial_Tools::create_testimonial(
				array(
					'title'             => "Photo {$n}",
					'content'           => 'Text',
					'reviewer_name'     => "R{$n}",
					'reviewer_image_id' => $img,
				)
			)['id'];
		}

		$count = function ( $per_page ) {
			global $wpdb;
			wp_cache_flush();
			$before = $wpdb->num_queries;
			$list   = Testimonial_Tools::list_testimonials( array( 'per_page' => $per_page ) );
			$this->assertCount( $per_page, array_filter( wp_list_pluck( $list['items'], 'reviewer_image_id' ) ) );
			return $wpdb->num_queries - $before;
		};

		$this->assertSame( $count( 2 ), $count( 6 ), 'Three times the items, the same number of queries.' );
	}
}
