<?php
/**
 * Real Testimonials after the shared-code sync (Stage 1 SC5): what an agent reads is exact and holds no secret.
 *
 * Every case was found by reading or probing the product (PRD §19.9): the licence key's last characters in
 * `get-settings` (E183), a rating filter that gave empty pages and the unfiltered total (E192), a total of 0 past the
 * last page (E182), a custom link stored without a full-address check (E197), and the form settings returned whole,
 * notification e-mail addresses included (E225).
 *
 * Needs the sibling plugins, so it is excluded from the CI suite (`integration-ci`).
 *
 * Risk covered (TESTING.md): data exposed (rule 1), a wrong answer that looks right (rule 4), a fix coming back (rule 6).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use WP_UnitTestCase;

/**
 * Class RealTestimonialsSafetyIntegrationTest
 */
class RealTestimonialsSafetyIntegrationTest extends WP_UnitTestCase {

	/**
	 * Editions: plugin folder and namespace.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function editions() {
		return array(
			'pro'  => array( 'testimonial-pro', 'ShapedPlugin\\TestimonialPro\\Mcp' ),
			'free' => array( 'testimonial-free', 'ShapedPlugin\\TestimonialFree\\Mcp' ),
		);
	}

	/**
	 * Load an edition and return its MCP namespace.
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $ns     Namespace.
	 * @return string
	 */
	private function load( $plugin, $ns ) {
		$file = dirname( __DIR__, 4 ) . '/' . $plugin . '/vendor/autoload.php';
		if ( ! class_exists( $ns . '\\Tools\\Testimonial_Tools' ) && file_exists( $file ) ) {
			require_once $file;
		}
		if ( ! class_exists( $ns . '\\Tools\\Testimonial_Tools' ) ) {
			$this->markTestSkipped( $plugin . ' is not available next to the Kit.' );
		}

		return $ns;
	}

	/**
	 * An administrator and the testimonial post type.
	 */
	public function set_up(): void {
		parent::set_up();
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
	 * Leave no option behind.
	 */
	public function tear_down(): void {
		delete_option( 'sp_testimonial_pro_options' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A published testimonial with a stored rating.
	 *
	 * @param string $rating Stored value, for example `five_star`, or '' for none.
	 * @return int
	 */
	private function testimonial( $rating ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'spt_testimonial',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, 'sp_tpro_meta_options', array( 'tpro_rating' => $rating ) );

		return $id;
	}

	/**
	 * The licence key shows no character; an API key keeps its last four (PRD E183, E170, E80).
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $ns     Namespace.
	 */
	public function test_the_licence_key_shows_no_character_and_an_api_key_its_last_four( $plugin, $ns ) {
		$ns = $this->load( $plugin, $ns );
		update_option(
			'sp_testimonial_pro_options',
			array(
				'license_key'  => 'LicenceKeyProbe0000000000ZQ9X',
				'integrations' => array(
					'gemini' => array(
						'is_active' => true,
						'api_key'   => 'GeminiKeyProbe00000000AB7K',
					),
				),
			)
		);

		$settings_class = $ns . '\\Tools\\Settings_Tools';
		$settings       = $settings_class::get_settings();

		$this->assertSame(
			array(
				'configured' => true,
				'last4'      => null,
			),
			$settings['license']['key_masked']
		);
		$this->assertStringNotContainsString( 'ZQ9X', (string) wp_json_encode( $settings ), 'Before the fix the licence key showed its last four characters.' );
		$this->assertSame( 'AB7K', $settings['ai_integration']['api_key']['last4'], 'A provider key of 12+ characters still identifies itself (E80).' );
		$this->assertStringNotContainsString( 'GeminiKeyProbe', (string) wp_json_encode( $settings ) );
	}

	/**
	 * A rating filter gives pages whose items, total and page count agree (PRD E192).
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $ns     Namespace.
	 */
	public function test_a_rating_filter_gives_pages_that_agree_with_their_total( $plugin, $ns ) {
		$tools = $this->load( $plugin, $ns ) . '\\Tools\\Testimonial_Tools';
		$five  = array( $this->testimonial( 'five_star' ), $this->testimonial( 'five_star' ) );
		$this->testimonial( 'four_star' );
		$this->testimonial( 'four_star' );
		$this->testimonial( 'four_star' );
		$this->testimonial( '' );

		$first  = $tools::list_testimonials(
			array(
				'rating'   => 5,
				'per_page' => 1,
				'page'     => 1,
			)
		);
		$second = $tools::list_testimonials(
			array(
				'rating'   => 5,
				'per_page' => 1,
				'page'     => 2,
			)
		);
		$third  = $tools::list_testimonials(
			array(
				'rating'   => 5,
				'per_page' => 1,
				'page'     => 3,
			)
		);

		$this->assertCount( 1, $first['items'], 'Before the fix page 1 could be empty when the match sat on another page.' );
		$this->assertCount( 1, $second['items'] );
		$this->assertSame( array(), $third['items'] );
		$this->assertSame( array( 2, 2, 2 ), array( $first['total'], $second['total'], $third['total'] ), 'The total counts matches, not every testimonial (it said 6).' );
		$this->assertSame( 2, $first['pages'] );
		$found = array( $first['items'][0]['id'], $second['items'][0]['id'] );
		sort( $found );
		sort( $five );
		$this->assertSame( $five, $found );
		$this->assertSame( 5, $first['items'][0]['rating'] );

		$none = $tools::list_testimonials( array( 'rating' => 3 ) );
		$this->assertSame( 0, $none['total'] );
		$this->assertSame( array(), $none['items'], 'An empty match never falls back to every testimonial.' );
	}

	/**
	 * Past the last page the total is the real count; a huge page is clamped (PRD E182, E181).
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $ns     Namespace.
	 */
	public function test_past_the_last_page_the_total_is_real_and_a_huge_page_is_clamped( $plugin, $ns ) {
		$ns = $this->load( $plugin, $ns );
		$this->testimonial( 'five_star' );
		$this->testimonial( 'four_star' );
		$this->testimonial( '' );

		$tools = $ns . '\\Tools\\Testimonial_Tools';
		$past  = $tools::list_testimonials(
			array(
				'per_page' => 2,
				'page'     => 9,
			)
		);
		$this->assertSame( array(), $past['items'] );
		$this->assertSame( 3, $past['total'], 'WordPress counts nothing on an empty page; it said 0.' );
		$this->assertSame( 2, $past['pages'] );

		$huge = $tools::list_testimonials( array( 'page' => PHP_INT_MAX ) );
		$this->assertSame( 10000, $huge['page'] );
		$this->assertSame( array(), $huge['items'] );

		foreach ( array( 'sp_real_template', 'spt_shortcodes' ) as $type ) {
			if ( ! post_type_exists( $type ) ) {
				register_post_type( $type );
			}
		}
		self::factory()->post->create_many(
			3,
			array(
				'post_type'   => 'spt_shortcodes',
				'post_status' => 'publish',
			)
		);
		$views_class = $ns . '\\Tools\\View_Tools';
		$views       = $views_class::list_views(
			array(
				'per_page' => 2,
				'page'     => 9,
			)
		);
		$this->assertSame( 3, $views['total'], 'Views are re-counted past the last page too.' );
	}

	public function test_a_custom_link_is_kept_only_as_a_full_address(): void {
		$tools = $this->load( 'testimonial-pro', 'ShapedPlugin\\TestimonialPro\\Mcp' ) . '\\Tools\\Testimonial_Tools';

		$result = $tools::create_testimonials(
			array(
				'items' => array(
					array(
						'title'         => 'Links',
						'custom_fields' => array(
							array(
								'type'  => 'link',
								'value' => 'acme.example',
							),
							array(
								'type'  => 'link',
								'value' => 'https://acme.example/case-study',
							),
						),
					),
				),
			)
		);

		$row    = $result['results'][0];
		$fields = get_post_meta( $row['id'], 'sp_tpro_meta_options', true )['testimonial_extra_fields'];
		$this->assertSame( array( 'custom_fields[0]' => 'invalid_value' ), wp_list_pluck( $row['dropped'], 'reason', 'key' ), 'Before the fix "acme.example" was stored as http://acme.example (E197).' );
		$this->assertSame( array( 'https://acme.example/case-study' ), array_column( $fields, 'testimonial_extra_fields_value' ) );
	}

	public function test_a_form_answer_withholds_addresses_and_unknown_settings(): void {
		$ns = $this->load( 'testimonial-pro', 'ShapedPlugin\\TestimonialPro\\Mcp' );
		if ( ! post_type_exists( 'spt_testimonial_form' ) ) {
			register_post_type( 'spt_testimonial_form' );
		}

		$id = self::factory()->post->create(
			array(
				'post_type'   => 'spt_testimonial_form',
				'post_status' => 'publish',
			)
		);
		update_post_meta(
			$id,
			'sp_tpro_form_options',
			array(
				'form_fields'                   => array( 'full_name' => array( 'label' => 'Your name' ) ),
				'successful_message'            => 'Thanks!',
				'submission_email_notification' => true,
				'submission_email_to'           => 'owner@shop.example',
				'submission_email_from'         => 'noreply@shop.example',
				'submission_email_subject'      => 'New testimonial',
				'submission_email_body'         => 'Call me on +1 555 0100',
				'approval_email_to'             => 'reviewer@shop.example',
				'a_setting_added_later'         => 'unknown',
			)
		);

		$form_tools = $ns . '\\Tools\\Form_Tools';
		$form       = $form_tools::get_form( array( 'id' => $id ) );
		$json       = (string) wp_json_encode( $form );

		$this->assertSame( array( 'form_fields', 'successful_message', 'submission_email_notification', 'submission_email_subject' ), array_keys( $form['settings'] ) );
		$this->assertStringNotContainsString( '@shop.example', $json, 'Before the fix every notification address was returned (E225).' );
		$this->assertStringNotContainsString( '555 0100', $json );
		$this->assertEqualsCanonicalizing( array( 'submission_email_to', 'submission_email_from', 'submission_email_body', 'approval_email_to', 'a_setting_added_later' ), $form['withheld'], 'A key added later stays hidden until it is allowed on purpose (E16).' );

		$past = $form_tools::list_forms(
			array(
				'per_page' => 1,
				'page'     => 7,
			)
		);
		$this->assertSame( 1, $past['total'], 'Forms are re-counted past the last page too (E182).' );
	}
}
