<?php
/**
 * Real Testimonials `create-testimonials` (bulk create, opt-in Bulk Import toolset), the default-on single
 * `create-testimonial` that shares its planner and offers only the major properties, and the reviewer-location
 * storage fix (PRD E123, E124, E125, E126).
 *
 * A bulk call writes many posts in one request, so a bug here is a data bug at scale: a wrong storage shape the
 * cards cannot read, a batch that publishes pages nobody confirmed, a retry that doubles the data, a user
 * publishing without the right, a locked Pro field written on Free. One body for Free and Pro (PRD §20 rule 6);
 * the data provider supplies the only difference, the fields Free's own editor locks behind Pro.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use WP_UnitTestCase;

/**
 * Class RealTestimonialsBulkCreateIntegrationTest
 */
class RealTestimonialsBulkCreateIntegrationTest extends WP_UnitTestCase {

	/**
	 * Text with backslashes an agent could send (PRD E120).
	 */
	const TEXT = 'Path C:\\Users\\me ¯\\_(ツ)_/¯';

	/**
	 * Item keys Free's editor locks behind Pro. Written out here on purpose: this is the product rule, not a copy of
	 * the constant under test.
	 */
	const FREE_LOCKED = array(
		'reviewer_email',
		'reviewer_phone',
		'reviewer_website',
		'reviewer_country',
		'video_url',
		'video_attachment_id',
		'company_logo_id',
		'social_profiles',
		'custom_fields',
	);

	/**
	 * Both editions: folder and the item keys the edition locks.
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public function editions() {
		return array(
			'pro'  => array( 'testimonial-pro', array() ),
			'free' => array( 'testimonial-free', self::FREE_LOCKED ),
		);
	}

	/**
	 * Load an edition and return its tools class.
	 *
	 * @param string $plugin Plugin folder.
	 * @return string
	 */
	private function tools( $plugin ) {
		$class = 'ShapedPlugin\\' . ( 'testimonial-pro' === $plugin ? 'TestimonialPro' : 'TestimonialFree' ) . '\\Mcp\\Tools\\Testimonial_Tools';
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
	 * Logged-in admin, the testimonial post type and its group taxonomy.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		if ( ! post_type_exists( 'spt_testimonial' ) ) {
			register_post_type(
				'spt_testimonial',
				array(
					'public'   => true,
					'supports' => array( 'title', 'editor', 'thumbnail' ),
				)
			);
		}
	}

	/**
	 * Clean up the user and the taxonomy.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		if ( taxonomy_exists( 'testimonial_cat' ) ) {
			unregister_taxonomy( 'testimonial_cat' );
		}
		parent::tearDown();
	}

	/**
	 * Register the group taxonomy (Pro registers it; Free does not) and return a term id.
	 *
	 * @return int
	 */
	private function group() {
		if ( ! taxonomy_exists( 'testimonial_cat' ) ) {
			register_taxonomy( 'testimonial_cat', 'spt_testimonial' );
		}

		return (int) self::factory()->term->create( array( 'taxonomy' => 'testimonial_cat' ) );
	}

	/**
	 * A media-library file.
	 *
	 * @param string $mime MIME type.
	 * @param string $file File name.
	 * @return int Attachment id.
	 */
	private function attachment( $mime, $file ) {
		return (int) self::factory()->attachment->create(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'Media ' . $file,
				'file'           => $file,
			)
		);
	}

	/**
	 * Count of testimonials in every status.
	 *
	 * @return int
	 */
	private function testimonial_count() {
		return count(
			get_posts(
				array(
					'post_type'      => 'spt_testimonial',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			)
		);
	}

	/**
	 * The dropped reasons of one result row, as `key => reason`.
	 *
	 * @param array $row One row of `results`.
	 * @return array<string, string>
	 */
	private function dropped( $row ) {
		return wp_list_pluck( $row['dropped'] ?? array(), 'reason', 'key' );
	}

	/**
	 * Every property the editor stores is written exactly as the editor stores it (E123, E124).
	 *
	 * @dataProvider editions
	 *
	 * @param string   $plugin Plugin folder.
	 * @param string[] $locked Item keys the edition locks.
	 */
	public function test_an_item_is_stored_the_way_the_editor_stores_it( $plugin, $locked ) {
		$tools = $this->tools( $plugin );
		$pro   = array() === $locked;
		$group = $pro ? $this->group() : 0;
		$photo = $this->attachment( 'image/jpeg', 'photo.jpg' );
		$logo  = $this->attachment( 'image/png', 'logo.png' );
		$clip  = $this->attachment( 'video/mp4', 'clip.mp4' );

		$result = $tools::create_testimonials(
			array(
				'items' => array(
					array(
						'title'                => 'Great service',
						'content'              => '<p>Fast and friendly.</p>',
						'status'               => 'draft',
						'date'                 => '2026-03-14 09:30:00',
						'rating'               => 4,
						'reviewer_name'        => 'Sarah Jenkins',
						'reviewer_email'       => 'sarah@example.com',
						'reviewer_designation' => 'Director',
						'reviewer_company'     => 'Acme Global',
						'reviewer_location'    => 'San Francisco',
						'reviewer_country'     => 'United States',
						'reviewer_phone'       => '+1 555 0100',
						'reviewer_website'     => 'https://acme.example/about',
						'video_url'            => 'https://www.youtube.com/watch?v=abc123',
						'video_attachment_id'  => $clip,
						'reviewer_image_id'    => $photo,
						'company_logo_id'      => $logo,
						'social_profiles'      => array(
							array(
								'network' => 'linkedin',
								'url'     => 'https://linkedin.com/in/sarah',
							),
						),
						'custom_fields'        => array(
							array(
								'type'  => 'email',
								'value' => 'hello@acme.example',
								'icon'  => 'fa fa-envelope',
							),
						),
						'category_ids'         => $pro ? array( $group ) : array(),
					),
				),
			)
		);

		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 0, $result['failed'] );
		$row = $result['results'][0];
		$this->assertTrue( $row['ok'] );
		$id   = $row['id'];
		$post = get_post( $id );
		$meta = get_post_meta( $id, 'sp_tpro_meta_options', true );

		$this->assertSame( 'spt_testimonial', $post->post_type );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( '2026-03-14 09:30:00', $post->post_date );
		$this->assertSame( '<p>Fast and friendly.</p>', $post->post_content );
		$this->assertSame( $photo, (int) get_post_thumbnail_id( $id ), 'The reviewer photo is the featured image.' );

		// Every key the editor writes is there, so no reader meets a missing one (checked against a real editor-saved row).
		foreach ( array( 'tpro_name', 'tpro_email', 'tpro_designation', 'tpro_company_name', 'tpro_company_logo', 'testimonial_clients_location', 'tpro_phone', 'tpro_website', 'tpro_video_url', 'tpro_rating', 'tpro_client_checkbox', 'tpro_form_id', 'testimonial_extra_fields', 'tpro_social_profiles' ) as $key ) {
			$this->assertArrayHasKey( $key, $meta, "{$key} must be stored." );
		}
		$this->assertSame( 'four_star', $meta['tpro_rating'] );
		$this->assertSame( 'four_star', get_post_meta( $id, 'tpro_rating', true ), 'The front-end form writes the flat rating too.' );
		$this->assertSame( 'Sarah Jenkins', $meta['tpro_name'] );
		$this->assertSame( 'Director', $meta['tpro_designation'] );
		$this->assertSame( 'Acme Global', $meta['tpro_company_name'] );
		$this->assertSame( 'San Francisco', $meta['testimonial_clients_location']['tpro_location'], 'The location lives one level down, where every reader looks (E123).' );
		$this->assertArrayNotHasKey( 'tpro_location', $meta, 'There is no flat location key.' );

		if ( $pro ) {
			$this->assertSame( 'sarah@example.com', $meta['tpro_email'] );
			$this->assertSame( 'United States', $meta['testimonial_clients_location']['tpro_country'] );
			$this->assertSame( '+1 555 0100', $meta['tpro_phone'] );
			$this->assertSame( 'https://acme.example/about', $meta['tpro_website'] );
			$this->assertSame( 'https://www.youtube.com/watch?v=abc123', $meta['tpro_video_url'], 'An address the item gives wins over a library clip.' );
			$this->assertSame( (string) $logo, $meta['tpro_company_logo']['id'], 'The logo is stored as the editor media field stores it.' );
			$this->assertSame( array( 'url', 'id', 'width', 'height', 'thumbnail', 'alt', 'title', 'description' ), array_keys( $meta['tpro_company_logo'] ) );
			$this->assertSame(
				array(
					array(
						'social_name' => 'linkedin',
						'social_url'  => 'https://linkedin.com/in/sarah',
					),
				),
				$meta['tpro_social_profiles']
			);
			$this->assertSame(
				array(
					array(
						'testimonial_extra_fields_icon'  => 'fa fa-envelope',
						'testimonial_extra_fields_type'  => 'email',
						'testimonial_extra_fields_value' => 'hello@acme.example',
					),
				),
				$meta['testimonial_extra_fields']
			);
			$this->assertSame( array( $group ), wp_get_object_terms( $id, 'testimonial_cat', array( 'fields' => 'ids' ) ) );
			$this->assertSame( array(), $row['dropped'] ?? array() );
		} else {
			// Free's editor locks these behind Pro, so they are reported and never written (D17).
			$this->assertSame( '', $meta['tpro_email'] );
			$this->assertSame( '', $meta['tpro_phone'] );
			$this->assertSame( '', $meta['tpro_website'] );
			$this->assertSame( '', $meta['tpro_video_url'] );
			$this->assertSame( array(), $meta['tpro_company_logo'] );
			$this->assertSame( array(), $meta['tpro_social_profiles'] );
			$this->assertSame( array(), $meta['testimonial_extra_fields'] );
			$reasons = $this->dropped( $row );
			foreach ( $locked as $key ) {
				$this->assertSame( 'pro_required', $reasons[ $key ] ?? null, "{$key} must be reported as pro_required." );
			}
		}
	}

	/**
	 * One bad item never stops the others, rows keep the order sent, and each problem is named.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_a_bad_item_is_reported_and_the_rest_are_created( $plugin ) {
		$tools  = $this->tools( $plugin );
		$result = $tools::create_testimonials(
			array(
				'items' => array(
					array( 'title' => 'First' ),
					array( 'title' => '   ' ),
					array(
						'title'             => 'Third',
						'rating'            => 9,
						'reviewer_image_id' => 999999,
					),
					'not an object',
					array( 'title' => 'Fifth' ),
				),
			)
		);

		$this->assertSame( 5, $result['requested'] );
		$this->assertSame( 3, $result['created'] );
		$this->assertSame( 2, $result['failed'] );
		$this->assertSame( array( 0, 1, 2, 3, 4 ), wp_list_pluck( $result['results'], 'index' ) );

		$rows = $result['results'];
		$this->assertTrue( $rows[0]['ok'] );
		$this->assertSame( 'missing_title', $rows[1]['error']['code'] );
		$this->assertTrue( $rows[2]['ok'], 'A dropped optional key does not fail the item.' );
		$this->assertEquals(
			array(
				'rating'            => 'invalid_value',
				'reviewer_image_id' => 'not_found',
			),
			$this->dropped( $rows[2] )
		);
		$this->assertSame( 'invalid_item', $rows[3]['error']['code'] );
		$this->assertTrue( $rows[4]['ok'] );
		$this->assertSame( 3, $this->testimonial_count() );
	}

	/**
	 * Values that would be unsafe or unusable are dropped with a reason, never stored (Pro: all fields are open).
	 */
	public function test_unsafe_and_invalid_values_are_dropped_not_stored() {
		$tools  = $this->tools( 'testimonial-pro' );
		$result = $tools::create_testimonials(
			array(
				'items' => array(
					array(
						'title'            => 'Checks',
						'reviewer_email'   => 'not-an-email',
						'reviewer_website' => 'acme.example',
						'video_url'        => 'ftp://files.example/clip.mp4',
						'social_profiles'  => array(
							array(
								'network' => 'linkedin',
								'url'     => 'javascript:alert(1)',
							),
						),
						'custom_fields'    => array(
							array(
								'type'  => 'email',
								'value' => 'nope',
							),
						),
						'date'             => 'not a date',
						'category_ids'     => array( 987654 ),
					),
				),
			)
		);

		$row    = $result['results'][0];
		$id     = $row['id'];
		$meta   = get_post_meta( $id, 'sp_tpro_meta_options', true );
		$expect = array(
			'reviewer_email'     => 'invalid_value',
			'reviewer_website'   => 'invalid_value',
			'video_url'          => 'invalid_value',
			'social_profiles[0]' => 'invalid_value',
			'custom_fields[0]'   => 'invalid_value',
			'date'               => 'invalid_value',
			'category_ids'       => 'not_available',
		);

		$this->assertEquals( $expect, $this->dropped( $row ), 'Every unusable value is reported with its reason.' );
		$this->assertSame( '', $meta['tpro_email'] );
		$this->assertSame( '', $meta['tpro_website'] );
		$this->assertSame( '', $meta['tpro_video_url'] );
		$this->assertSame( array(), $meta['tpro_social_profiles'] );
		$this->assertSame( array(), $meta['testimonial_extra_fields'] );
	}

	/**
	 * The social network list is not in the schema (it would cost tokens on every message), so an unknown network is
	 * refused by the tool, and the answer carries the valid ones so the agent can correct itself (D18).
	 */
	public function test_an_unknown_social_network_is_reported_with_the_valid_ones() {
		$tools  = $this->tools( 'testimonial-pro' );
		$result = $tools::create_testimonials(
			array(
				'items' => array(
					array(
						'title'           => 'Networks',
						'social_profiles' => array(
							array(
								'network' => 'myspace',
								'url'     => 'https://myspace.example/x',
							),
							array(
								'network' => 'github',
								'url'     => 'https://github.com/x',
							),
							array(
								'network' => 'linkedin',
								'url'     => 'not a url',
							),
						),
					),
				),
			)
		);

		$row  = $result['results'][0];
		$meta = get_post_meta( $row['id'], 'sp_tpro_meta_options', true );

		$this->assertSame( array( 'github' ), wp_list_pluck( $meta['tpro_social_profiles'], 'social_name' ), 'Only the valid row is stored.' );
		$this->assertEquals(
			array(
				'social_profiles[0]' => 'invalid_value',
				'social_profiles[2]' => 'invalid_value',
			),
			$this->dropped( $row )
		);
		$message = $row['dropped'][0]['message'];
		foreach ( array( 'linkedin', 'facebook', 'github' ) as $network ) {
			$this->assertStringContainsString( $network, $message, 'The answer lists the valid networks.' );
		}
	}

	/**
	 * Group ids that do not exist are reported, the ones that do are assigned.
	 */
	public function test_unknown_group_ids_are_reported_and_known_ones_assigned() {
		$tools  = $this->tools( 'testimonial-pro' );
		$group  = $this->group();
		$result = $tools::create_testimonials(
			array(
				'items' => array(
					array(
						'title'        => 'Grouped',
						'category_ids' => array( $group, 987654 ),
					),
				),
			)
		);

		$row = $result['results'][0];
		$this->assertSame( array( $group ), wp_get_object_terms( $row['id'], 'testimonial_cat', array( 'fields' => 'ids' ) ) );
		$this->assertSame( array( 'category_ids' => 'not_found' ), $this->dropped( $row ) );
	}

	/**
	 * Free has no group taxonomy: groups are reported as not available, and the item is still created.
	 */
	public function test_groups_are_not_available_on_a_site_without_the_taxonomy() {
		$tools = $this->tools( 'testimonial-free' );
		$this->assertFalse( taxonomy_exists( 'testimonial_cat' ) );

		$result = $tools::create_testimonials(
			array(
				'items' => array(
					array(
						'title'        => 'Ungrouped',
						'category_ids' => array( 1 ),
					),
				),
			)
		);

		$this->assertTrue( $result['results'][0]['ok'] );
		$this->assertSame( array( 'category_ids' => 'not_available' ), $this->dropped( $result['results'][0] ) );
	}

	/**
	 * More than the cap is refused whole and writes nothing, so an agent splits the batch.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_a_batch_over_the_cap_is_refused_and_writes_nothing( $plugin ) {
		$tools = $this->tools( $plugin );
		$items = array();
		for ( $i = 0; $i <= $tools::MAX_BATCH; $i++ ) {
			$items[] = array( 'title' => 'Item ' . $i );
		}

		$result = $tools::create_testimonials( array( 'items' => $items ) );

		$this->assertWPError( $result );
		$this->assertSame( 'too_many_items', $result->get_error_code() );
		$this->assertSame( 0, $this->testimonial_count() );
		$this->assertSame( 5, $tools::MAX_BATCH, 'The cap is the documented 5: a small batch keeps the answer and the call cheap in tokens (D18).' );
	}

	/**
	 * A dry run reports what would be created and dropped, and writes nothing.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_a_dry_run_writes_nothing( $plugin ) {
		$tools  = $this->tools( $plugin );
		$result = $tools::create_testimonials(
			array(
				'dry_run' => true,
				'items'   => array(
					array(
						'title'  => 'Preview me',
						'rating' => 5,
					),
					array( 'title' => '' ),
				),
			)
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['would_create'] );
		$this->assertArrayNotHasKey( 'created', $result );
		$this->assertSame( 1, $result['failed'] );
		$this->assertArrayNotHasKey( 'id', $result['results'][0] );
		$this->assertSame( 0, $this->testimonial_count() );
	}

	/**
	 * A full batch of drafts, and up to three published, go straight through.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_drafts_and_a_few_published_need_no_confirmation( $plugin ) {
		$tools  = $this->tools( $plugin );
		$drafts = array();
		for ( $i = 0; $i < $tools::MAX_BATCH; $i++ ) {
			$drafts[] = array( 'title' => 'Draft ' . $i );
		}
		$result = $tools::create_testimonials( array( 'items' => $drafts ) );
		$this->assertSame( $tools::MAX_BATCH, $result['created'], 'A full batch of drafts needs no confirmation.' );

		$few = array();
		for ( $i = 0; $i < $tools::PUBLISH_CONFIRM_ABOVE; $i++ ) {
			$few[] = array( 'title' => 'Live ' . $i );
		}
		$result = $tools::create_testimonials(
			array(
				'status' => 'publish',
				'items'  => $few,
			)
		);
		$this->assertSame( 3, $result['created'] );
		$this->assertSame( 'publish', $result['results'][0]['status'] );
		$this->assertSame( 3, $tools::PUBLISH_CONFIRM_ABOVE, 'The threshold is the documented 3.' );
	}

	/**
	 * Publishing more than three at once is previewed first: nothing is written until the token comes back.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_a_large_publishing_batch_needs_the_confirmation_round( $plugin ) {
		$tools = $this->tools( $plugin );
		$args  = array(
			'status' => 'publish',
			'items'  => array(
				array( 'title' => 'One' ),
				array( 'title' => 'Two' ),
				array( 'title' => 'Three' ),
				array( 'title' => 'Four' ),
			),
		);

		$preview = $tools::create_testimonials( $args );

		$this->assertSame( 'confirmation_required', $preview['status'] );
		$this->assertSame( 4, $preview['preview']['publish'] );
		$this->assertSame( 4, $preview['preview']['create'] );
		$this->assertSame( 0, $this->testimonial_count(), 'The preview writes nothing.' );

		// Other arguments with the token are refused (the token is bound to the exact batch).
		$tampered                       = $args;
		$tampered['items']              = array_slice( $args['items'], 0, 3 );
		$tampered['items'][]            = array( 'title' => 'Not previewed' );
		$tampered['confirmation_token'] = $preview['confirmation_token'];
		$refused                        = $tools::create_testimonials( $tampered );
		$this->assertWPError( $refused );
		$this->assertSame( 0, $this->testimonial_count() );

		// The same arguments with the token create all four, published.
		$args['confirmation_token'] = $preview['confirmation_token'];
		$done                       = $tools::create_testimonials( $args );
		$this->assertSame( 4, $done['created'] );
		$this->assertSame( 4, $this->testimonial_count() );
		$this->assertSame( 'publish', get_post( $done['results'][0]['id'] )->post_status );

		// A token works once: a replay is refused and creates nothing more.
		$replay = $tools::create_testimonials( $args );
		$this->assertWPError( $replay );
		$this->assertSame( 4, $this->testimonial_count() );
	}

	/**
	 * A retry with the same idempotency key returns the first result and creates nothing twice.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_a_retry_with_the_same_key_creates_nothing_twice( $plugin ) {
		$tools = $this->tools( $plugin );
		$args  = array(
			'idempotency_key' => 'import-2026-10-15',
			'items'           => array(
				array( 'title' => 'Once' ),
				array( 'title' => 'Only' ),
			),
		);

		$first  = $tools::create_testimonials( $args );
		$second = $tools::create_testimonials( $args );

		$this->assertSame( 2, $first['created'] );
		$this->assertTrue( $second['idempotent_replay'] );
		$this->assertSame( wp_list_pluck( $first['results'], 'id' ), wp_list_pluck( $second['results'], 'id' ) );
		$this->assertSame( 2, $this->testimonial_count() );
	}

	/**
	 * A user who may not publish gets pending testimonials and is told, never a published one.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_a_user_who_may_not_publish_gets_pending_items( $plugin ) {
		$tools = $this->tools( $plugin );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$result = $tools::create_testimonials(
			array(
				'items' => array(
					array(
						'title'  => 'Wants to publish',
						'status' => 'publish',
					),
				),
			)
		);

		$row = $result['results'][0];
		$this->assertTrue( $row['ok'] );
		$this->assertSame( 'pending', get_post( $row['id'] )->post_status );
		$this->assertSame( array( 'status' => 'forbidden' ), $this->dropped( $row ) );
	}

	/**
	 * A user who cannot edit posts creates nothing.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_a_user_who_cannot_edit_posts_is_refused( $plugin ) {
		$tools = $this->tools( $plugin );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $tools::create_testimonials( array( 'items' => array( array( 'title' => 'No' ) ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( 0, $this->testimonial_count() );
	}

	/**
	 * A media id the user cannot read, or of the wrong kind, is never attached.
	 */
	public function test_media_of_the_wrong_kind_is_not_attached() {
		$tools = $this->tools( 'testimonial-pro' );
		$video = $this->attachment( 'video/mp4', 'clip.mp4' );
		$image = $this->attachment( 'image/jpeg', 'face.jpg' );

		$result = $tools::create_testimonials(
			array(
				'items' => array(
					array(
						'title'               => 'Wrong media',
						'reviewer_image_id'   => $video,
						'company_logo_id'     => $video,
						'video_attachment_id' => $image,
					),
					array(
						'title'               => 'Right media',
						'video_attachment_id' => $video,
					),
				),
			)
		);

		$wrong = $result['results'][0];
		$this->assertEquals(
			array(
				'video_attachment_id' => 'not_found',
				'company_logo_id'     => 'not_found',
				'reviewer_image_id'   => 'not_found',
			),
			$this->dropped( $wrong )
		);
		$this->assertSame( 0, (int) get_post_thumbnail_id( $wrong['id'] ) );

		$right = get_post_meta( $result['results'][1]['id'], 'sp_tpro_meta_options', true );
		$this->assertSame( wp_get_attachment_url( $video ), $right['tpro_video_url'], 'A library video fills the video address.' );
	}

	/**
	 * Backslashes survive in every text value, including the nested ones (PRD E120).
	 */
	public function test_backslashes_survive_in_every_text_value() {
		$tools  = $this->tools( 'testimonial-pro' );
		$result = $tools::create_testimonials(
			array(
				'items' => array(
					array(
						'title'                => self::TEXT,
						'content'              => self::TEXT,
						'reviewer_name'        => self::TEXT,
						'reviewer_company'     => self::TEXT,
						'reviewer_location'    => self::TEXT,
						'reviewer_designation' => self::TEXT,
						'custom_fields'        => array(
							array(
								'type'  => 'text',
								'value' => self::TEXT,
							),
						),
					),
				),
			)
		);

		$id   = $result['results'][0]['id'];
		$meta = get_post_meta( $id, 'sp_tpro_meta_options', true );
		$this->assertSame( self::TEXT, get_post( $id )->post_title );
		$this->assertSame( self::TEXT, get_post( $id )->post_content );
		$this->assertSame( self::TEXT, $meta['tpro_name'] );
		$this->assertSame( self::TEXT, $meta['tpro_company_name'] );
		$this->assertSame( self::TEXT, $meta['tpro_designation'] );
		$this->assertSame( self::TEXT, $meta['testimonial_clients_location']['tpro_location'] );
		$this->assertSame( self::TEXT, $meta['testimonial_extra_fields'][0]['testimonial_extra_fields_value'] );
	}

	/**
	 * One item with every property the tools take.
	 *
	 * @param int $photo Reviewer photo id.
	 * @param int $logo  Company logo id.
	 * @param int $group Group term id (0 when the edition has none).
	 * @return array<string, mixed>
	 */
	private function full_item( $photo, $logo, $group ) {
		return array(
			'title'                => 'Same item',
			'content'              => '<p>Fast and friendly.</p>',
			'status'               => 'draft',
			'date'                 => '2026-03-14 09:30:00',
			'rating'               => 4,
			'reviewer_name'        => 'Sarah Jenkins',
			'reviewer_email'       => 'sarah@example.com',
			'reviewer_designation' => 'Director',
			'reviewer_company'     => 'Acme Global',
			'reviewer_location'    => 'San Francisco',
			'reviewer_country'     => 'United States',
			'reviewer_phone'       => '+1 555 0100',
			'reviewer_website'     => 'https://acme.example/about',
			'video_url'            => 'https://www.youtube.com/watch?v=abc123',
			'video_attachment_id'  => $this->attachment( 'video/mp4', 'clip.mp4' ),
			'reviewer_image_id'    => $photo,
			'company_logo_id'      => $logo,
			'social_profiles'      => array(
				array(
					'network' => 'linkedin',
					'url'     => 'https://linkedin.com/in/sarah',
				),
			),
			'custom_fields'        => array(
				array(
					'type'  => 'link',
					'value' => 'https://acme.example/case',
					'icon'  => 'fa fa-link',
				),
			),
			'category_ids'         => $group ? array( $group ) : array(),
		);
	}

	/**
	 * The single create tool offers only the major properties (D18, E126). For those it stores exactly what one bulk
	 * item stores, so the two cannot drift (E125).
	 *
	 * @dataProvider editions
	 *
	 * @param string   $plugin Plugin folder.
	 * @param string[] $locked Item keys the edition locks.
	 */
	public function test_the_single_create_tool_stores_what_one_bulk_item_stores( $plugin, $locked ) {
		$tools = $this->tools( $plugin );
		$group = array() === $locked ? $this->group() : 0;
		$item  = array_intersect_key(
			$this->full_item( $this->attachment( 'image/jpeg', 'photo.jpg' ), $this->attachment( 'image/png', 'logo.png' ), $group ),
			array_flip( $tools::SINGLE_FIELDS )
		);
		$this->assertSame( $tools::SINGLE_FIELDS, array_keys( array_intersect_key( array_flip( $tools::SINGLE_FIELDS ), $item ) ), 'The item uses every property the tool offers.' );

		$single = $tools::create_testimonial( $item );
		$bulk   = $tools::create_testimonials( array( 'items' => array( $item ) ) );
		$bulk   = $bulk['results'][0];

		$this->assertIsArray( $single );
		$this->assertSame(
			get_post_meta( $bulk['id'], 'sp_tpro_meta_options', true ),
			get_post_meta( $single['id'], 'sp_tpro_meta_options', true ),
			'Both tools write the same editor row.'
		);
		$this->assertSame( get_post_meta( $bulk['id'], 'tpro_rating', true ), get_post_meta( $single['id'], 'tpro_rating', true ) );
		$this->assertSame( get_post_thumbnail_id( $bulk['id'] ), get_post_thumbnail_id( $single['id'] ) );
		$this->assertGreaterThan( 0, (int) get_post_thumbnail_id( $single['id'] ), 'The reviewer photo is the featured image.' );
		if ( taxonomy_exists( 'testimonial_cat' ) ) {
			$this->assertSame(
				wp_get_object_terms( $bulk['id'], 'testimonial_cat', array( 'fields' => 'ids' ) ),
				wp_get_object_terms( $single['id'], 'testimonial_cat', array( 'fields' => 'ids' ) )
			);
		}
		$this->assertEquals( $this->dropped( $bulk ), wp_list_pluck( $single['dropped'] ?? array(), 'reason', 'key' ), 'Both tools drop the same keys for the same reasons.' );
	}

	/**
	 * The single create tool answers with the testimonial, the keys it saved and the keys it dropped.
	 *
	 * @dataProvider editions
	 *
	 * @param string   $plugin Plugin folder.
	 * @param string[] $locked Item keys the edition locks.
	 */
	public function test_the_single_create_tool_reports_saved_and_dropped_keys( $plugin, $locked ) {
		$tools  = $this->tools( $plugin );
		$pro    = array() === $locked;
		$item   = array_intersect_key(
			$this->full_item( $this->attachment( 'image/jpeg', 'photo.jpg' ), 0, $pro ? $this->group() : 1 ),
			array_flip( $tools::SINGLE_FIELDS )
		);
		$single = $tools::create_testimonial( $item );

		$this->assertSame( 'Same item', $single['title'] );
		$this->assertSame( 4, $single['rating'] );
		$this->assertSame( 'Sarah Jenkins', $single['reviewer']['name'] );
		$this->assertSame( 'San Francisco', $single['reviewer']['location'] );

		foreach ( array( 'title', 'rating', 'reviewer_name', 'reviewer_company', 'reviewer_location', 'reviewer_image_id' ) as $key ) {
			$this->assertContains( $key, $single['saved'], "{$key} is saved in every edition." );
		}

		if ( $pro ) {
			$this->assertSame( array(), $single['dropped'] ?? array() );
			$this->assertContains( 'category_ids', $single['saved'] );
		} else {
			// Free has no group taxonomy: the groups are reported, the testimonial is still created.
			$this->assertSame( array( 'category_ids' => 'not_available' ), wp_list_pluck( $single['dropped'], 'reason', 'key' ) );
			$this->assertNotContains( 'category_ids', $single['saved'] );
		}
	}

	/**
	 * What the single tool did before still holds: a missing title is an error, a user who cannot edit is refused, a
	 * user who may not publish gets a pending testimonial and is told.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_the_single_create_tool_keeps_its_contract( $plugin ) {
		$tools = $this->tools( $plugin );

		$missing = $tools::create_testimonial( array( 'title' => '   ' ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'missing_title', $missing->get_error_code() );
		$this->assertSame( 0, $this->testimonial_count() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$pending = $tools::create_testimonial(
			array(
				'title'  => 'Wants to publish',
				'status' => 'publish',
			)
		);
		$this->assertSame( 'pending', $pending['status'] );
		$this->assertSame( 'forbidden', $pending['dropped'][0]['reason'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$refused = $tools::create_testimonial( array( 'title' => 'No' ) );
		$this->assertWPError( $refused );
		$this->assertSame( 'forbidden', $refused->get_error_code() );
	}

	/**
	 * A failure while writing removes the half-made post. The bulk tool reports that item and goes on; the single
	 * tool passes the exception on to the ability wrapper (which hides the detail and records an error id).
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_a_failure_while_writing_leaves_no_half_made_post( $plugin ) {
		$tools = $this->tools( $plugin );
		add_filter(
			'update_post_metadata',
			static function ( $check, $post_id, $meta_key ) {
				if ( 'sp_tpro_meta_options' === $meta_key && 'Boom' === get_the_title( $post_id ) ) {
					throw new \RuntimeException( 'meta write failed' );
				}
				return $check;
			},
			10,
			3
		);

		// The tool logs the failure server-side; keep that line out of the test output.
		$log_file = ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- A test, restored below.

		$bulk = $tools::create_testimonials(
			array(
				'items' => array(
					array( 'title' => 'Boom' ),
					array( 'title' => 'Fine' ),
				),
			)
		);
		ini_set( 'error_log', (string) $log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the setting.
		$this->assertSame( 1, $bulk['created'] );
		$this->assertSame( 'item_failed', $bulk['results'][0]['error']['code'] );
		$this->assertStringNotContainsString( 'meta write failed', wp_json_encode( $bulk ), 'The detail stays server-side.' );
		$this->assertSame( 1, $this->testimonial_count(), 'The half-made post is gone, the other item stays.' );

		$this->expectException( \RuntimeException::class );
		try {
			$tools::create_testimonial( array( 'title' => 'Boom' ) );
		} finally {
			$this->assertSame( 1, $this->testimonial_count(), 'The single tool also removes the half-made post.' );
		}
	}

	/**
	 * E123: the single create and update tools keep the location where the editor keeps it, and an update keeps the
	 * country stored beside it.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_single_create_and_update_keep_the_location_where_the_editor_does( $plugin ) {
		$tools   = $this->tools( $plugin );
		$created = $tools::create_testimonial(
			array(
				'title'             => 'Located',
				'reviewer_location' => 'Oslo',
			)
		);
		$meta    = get_post_meta( $created['id'], 'sp_tpro_meta_options', true );
		$this->assertSame( 'Oslo', $meta['testimonial_clients_location']['tpro_location'] );
		$this->assertArrayNotHasKey( 'tpro_location', $meta );
		$this->assertSame( 'Oslo', $created['reviewer']['location'] );

		// The editor stored a country beside it.
		$meta['testimonial_clients_location']['tpro_country'] = 'Norway';
		update_post_meta( $created['id'], 'sp_tpro_meta_options', wp_slash( $meta ) );

		$updated = $tools::update_testimonial(
			array(
				'id'                => $created['id'],
				'reviewer_location' => 'Bergen',
			)
		);
		$meta    = get_post_meta( $created['id'], 'sp_tpro_meta_options', true );
		$this->assertSame( 'Bergen', $meta['testimonial_clients_location']['tpro_location'] );
		$this->assertSame( 'Norway', $meta['testimonial_clients_location']['tpro_country'], 'An update keeps the country.' );
		$this->assertSame( 'Bergen', $updated['reviewer']['location'] );
	}

	/**
	 * E123: a testimonial an older version of the tool wrote (flat key) still shows its location, and the next update
	 * moves it to the editor's place and removes the flat copy.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_a_flat_location_written_by_an_older_version_is_still_read_and_then_moved( $plugin ) {
		$tools = $this->tools( $plugin );
		$id    = self::factory()->post->create(
			array(
				'post_type'   => 'spt_testimonial',
				'post_status' => 'draft',
				'post_title'  => 'Legacy',
			)
		);
		update_post_meta(
			$id,
			'sp_tpro_meta_options',
			array(
				'tpro_name'     => 'Old Row',
				'tpro_location' => 'Lisbon',
			)
		);

		$this->assertSame( 'Lisbon', $tools::get_testimonial( array( 'id' => $id ) )['reviewer']['location'] );

		$tools::update_testimonial(
			array(
				'id'                => $id,
				'reviewer_location' => 'Porto',
			)
		);
		$meta = get_post_meta( $id, 'sp_tpro_meta_options', true );
		$this->assertSame( 'Porto', $meta['testimonial_clients_location']['tpro_location'] );
		$this->assertArrayNotHasKey( 'tpro_location', $meta, 'The flat copy is removed.' );
		$this->assertSame( 'Old Row', $meta['tpro_name'], 'The rest of the row is kept.' );
	}
}
