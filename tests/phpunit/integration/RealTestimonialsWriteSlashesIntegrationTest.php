<?php
/**
 * Regression test for PRD E120: the Real Testimonials write tools keep backslashes.
 *
 * WordPress unslashes what wp_insert_post(), wp_update_post() and
 * update_post_meta() are given, so a tool that passes raw agent text loses every backslash, and
 * an update silently re-saves stored reviewer meta without its backslashes.
 * Runs in both editions (PRD §20 rule 6).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use WP_UnitTestCase;

/**
 * Class RealTestimonialsWriteSlashesIntegrationTest
 */
class RealTestimonialsWriteSlashesIntegrationTest extends WP_UnitTestCase {

	/**
	 * Text with backslashes an agent could send.
	 */
	const TEXT = 'Path C:\\Users\\me ¯\\_(ツ)_/¯';

	/**
	 * Both editions' Testimonial_Tools classes.
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
	 * Logged-in admin and the testimonial post type.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( ! post_type_exists( 'spt_testimonial' ) ) {
			register_post_type( 'spt_testimonial', array( 'public' => true ) );
		}
	}

	/**
	 * Clean up the user.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Create keeps backslashes in the title, the review text and the reviewer meta.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_create_keeps_backslashes( $plugin ) {
		$tools  = $this->tools( $plugin );
		$result = $tools::create_testimonial(
			array(
				'title'                => self::TEXT,
				'content'              => self::TEXT,
				'reviewer_designation' => self::TEXT,
			)
		);

		$post = get_post( $result['id'] );
		$meta = get_post_meta( $result['id'], 'sp_tpro_meta_options', true );

		$this->assertSame( self::TEXT, $post->post_title );
		$this->assertSame( self::TEXT, $post->post_content );
		$this->assertSame( self::TEXT, $meta['tpro_designation'] );
	}

	/**
	 * An update keeps backslashes it writes, and in reviewer meta it does not touch.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 */
	public function test_update_keeps_backslashes_including_untouched_meta( $plugin ) {
		$tools   = $this->tools( $plugin );
		$created = $tools::create_testimonial(
			array(
				'title'                => 'Plain',
				'reviewer_designation' => self::TEXT,
			)
		);

		$tools::update_testimonial(
			array(
				'id'               => $created['id'],
				'title'            => self::TEXT,
				'reviewer_company' => 'Acme',
			)
		);

		$meta = get_post_meta( $created['id'], 'sp_tpro_meta_options', true );
		$this->assertSame( self::TEXT, get_post( $created['id'] )->post_title );
		$this->assertSame( self::TEXT, $meta['tpro_designation'] );
		$this->assertSame( 'Acme', $meta['tpro_company_name'] );
	}
}
