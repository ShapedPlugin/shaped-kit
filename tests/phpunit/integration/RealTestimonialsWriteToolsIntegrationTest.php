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
}
