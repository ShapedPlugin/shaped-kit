<?php
/**
 * WP Carousel Free and Pro: the endpoint serves only its own abilities, and `update-carousel-settings` writes only
 * what the carousel already has, in its own shape (EXISTING_PLUGIN_BUGS B21, PRD E162–E165).
 *
 * Risk covered (TESTING.md): another plugin's tool on this endpoint (rule 1), a gallery stored in a shape that
 * makes the front end throw (rule 3), raw input stored as settings (rule 2), a publish without the right (rule 1).
 *
 * Needs the sibling plugins, so it is excluded from the CI suite.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use WP_UnitTestCase;

/**
 * Class WpCarouselSafetyIntegrationTest
 */
class WpCarouselSafetyIntegrationTest extends WP_UnitTestCase {

	/**
	 * Editions: plugin folder, namespace, the source types its settings screen offers.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string[]}>
	 */
	public function editions() {
		$free = array( 'image-carousel', 'post-carousel', 'product-carousel', 'video-carousel' );

		return array(
			'pro'  => array( 'wp-carousel-pro', 'ShapedPlugin\\WPCarouselPro\\Mcp', array_merge( $free, array( 'audio-carousel', 'content-carousel', 'mix-content', 'external-carousel' ) ) ),
			'free' => array( 'wp-carousel-free', 'ShapedPlugin\\WPCarouselFree\\Mcp', $free ),
		);
	}

	/**
	 * Load an edition the way the other WP Carousel suites do.
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $ns     Namespace.
	 * @return string Carousel_Tools class.
	 */
	private function load( $plugin, $ns ) {
		if ( ! class_exists( $ns . '\\Module' ) ) {
			$file = dirname( __DIR__, 4 ) . '/' . $plugin . '/vendor/autoload.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
		if ( ! defined( 'WPCAROUSEL_VERSION' ) ) {
			define( 'WPCAROUSEL_VERSION', '5.1.0' );
		}
		if ( ! class_exists( $ns . '\\Module' ) ) {
			$this->markTestSkipped( "{$plugin} is not available next to the Kit." );
		}

		// As production registers it (Includes/Register_Post_Type.php sets no capabilities, so it maps like posts):
		// a Contributor can edit their own carousel draft. The module's fallback would make every cap manage_options.
		// Another suite may have registered it through that fallback already, so it is registered again here.
		if ( post_type_exists( 'sp_wp_carousel' ) ) {
			unregister_post_type( 'sp_wp_carousel' );
		}
		register_post_type(
			'sp_wp_carousel',
			array(
				'public'       => false,
				'map_meta_cap' => true,
			)
		);

		return $ns . '\\Tools\\Carousel_Tools';
	}

	/**
	 * An administrator; no request leaves the site.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * A carousel holding the options the settings screen would have saved.
	 *
	 * @param int $author Author id.
	 * @return int
	 */
	private function carousel( $author = 0 ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'sp_wp_carousel',
				'post_status' => 'draft',
				'post_title'  => 'Homepage slider',
				'post_author' => $author ? $author : get_current_user_id(),
			)
		);
		update_post_meta(
			$id,
			'sp_wpcp_shortcode_options',
			array(
				'wpcp_carousel_auto_play' => '1',
				'load_more_label'         => 'Our work',
				'wpcp_number_of_columns'  => array(
					'lg_desktop' => '4',
					'tablet'     => '2',
				),
				'wpcp_taxonomy_terms'     => array( 'news' ),
			)
		);
		update_post_meta(
			$id,
			'sp_wpcp_upload_options',
			array(
				'wpcp_carousel_type' => 'image-carousel',
				'wpcp_gallery'       => '',
			)
		);

		return $id;
	}

	/**
	 * An attachment of a given type.
	 *
	 * @param string $file Relative upload path.
	 * @param string $mime Mime type.
	 * @param array  $args Post fields.
	 * @return int
	 */
	private function attachment( $file, $mime, array $args = array() ) {
		return self::factory()->attachment->create_object(
			array_merge(
				array(
					'file'           => $file,
					'post_mime_type' => $mime,
				),
				$args
			)
		);
	}

	/**
	 * Dropped keys of a result, as key => reason.
	 *
	 * @param array $result Tool result.
	 * @return array<int, string>
	 */
	private function dropped( $result ) {
		return array_map(
			static function ( $row ) {
				return $row['key'] . ':' . $row['reason'];
			},
			$result['dropped']
		);
	}

	/**
	 * What every message of every AI client carries by default stays within a chosen size (E126, PRD wp_carousel §7).
	 * Measured 2026-10-21 in characters of name + description + input schema: Pro core 2,453 + management 1,388 =
	 * 3,841 (Free 3,771); builder 3,987 (Free 3,733) and media 683 are opt-in.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $ns     Namespace.
	 */
	public function test_the_default_toolsets_stay_within_their_token_budget( $plugin, $ns ) {
		$this->load( $plugin, $ns );
		$registrar   = $ns . '\\Abilities_Registrar';
		$budget      = $ns . '\\Support\\Token_Budget';
		$definitions = $registrar::definitions();
		$map         = $registrar::ability_toolset_map();

		$default = $budget::total( $definitions, $map, array( 'core', 'management' ) );
		$this->assertLessThanOrEqual( 4200, $default, "The default toolsets are {$default} characters. Raising the limit raises the cost of every message of every site." );
		$this->assertLessThanOrEqual( 800, $budget::total( $definitions, $map, array( 'media' ) ) );
		$this->assertGreaterThan( $default / 2, $budget::total( $definitions, $map, array( 'builder' ) ), 'The Builder is large, which is why it is opt-in.' );
	}

	/**
	 * A name another plugin adds through the names filter is never served (E12).
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $ns     Namespace.
	 */
	public function test_an_ability_another_plugin_adds_through_the_filter_is_never_served( $plugin, $ns ) {
		$this->load( $plugin, $ns );
		$registrar = $ns . '\\Abilities_Registrar';
		add_filter(
			'wpcp/mcp_ability_names',
			static function ( $names ) {
				$names[] = 'another-plugin/steal';
				$names[] = 42;
				$names[] = 'wp-carouselx/lookalike';
				return $names;
			}
		);

		$names = $registrar::names();

		$this->assertNotContains( 'another-plugin/steal', $names );
		$this->assertNotContains( 42, $names );
		$this->assertNotContains( 'wp-carouselx/lookalike', $names, 'A prefix that only starts the same is not ours.' );
		$this->assertContains( 'wp-carousel/get-context', $names );
	}

	/**
	 * The gallery is stored as the front end reads it, with only images the user can read.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $ns     Namespace.
	 */
	public function test_the_gallery_is_stored_as_a_comma_list_of_readable_images_only( $plugin, $ns ) {
		$tools  = $this->load( $plugin, $ns );
		$id     = $this->carousel();
		$image  = $this->attachment( '2026/10/a.jpg', 'image/jpeg' );
		$image2 = $this->attachment( '2026/10/b.jpg', 'image/jpeg' );
		$pdf    = $this->attachment( '2026/10/c.pdf', 'application/pdf' );

		$result = $tools::update_carousel_settings(
			array(
				'id'             => $id,
				'upload_options' => array( 'wpcp_gallery' => array( $image, (string) $image2, $pdf, 999999, array( 1 ) ) ),
			)
		);

		$stored = get_post_meta( $id, 'sp_wpcp_upload_options', true )['wpcp_gallery'];
		$this->assertSame( $image . ',' . $image2, $stored, 'A comma list: the front end explodes it, and an array would throw (PHP 8 TypeError).' );
		$this->assertCount( 3, $result['dropped'], 'The PDF, the missing id and the nested list are reported.' );

		$tools::update_carousel_settings(
			array(
				'id'             => $id,
				'upload_options' => array( 'wpcp_gallery' => (string) $image2 ),
			)
		);
		$this->assertSame( (string) $image2, get_post_meta( $id, 'sp_wpcp_upload_options', true )['wpcp_gallery'], 'A comma string is accepted too.' );

		// An image on someone else's draft is not readable for an Author.
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$draft   = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $editor,
			)
		);
		$private = $this->attachment(
			'2026/10/secret.jpg',
			'image/jpeg',
			array(
				'post_parent' => $draft,
				'post_author' => $editor,
			)
		);
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$own = $this->carousel( $author );

		$result = $tools::update_carousel_settings(
			array(
				'id'             => $own,
				'upload_options' => array( 'wpcp_gallery' => array( $image, $private ) ),
			)
		);
		$this->assertSame( (string) $image, get_post_meta( $own, 'sp_wpcp_upload_options', true )['wpcp_gallery'] );
		$this->assertSame( array( 'upload_options.wpcp_gallery:not_found' ), $this->dropped( $result ) );
	}

	/**
	 * Settings keep the keys and shapes the carousel has; text is cleaned; everything refused is reported.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $ns     Namespace.
	 */
	public function test_settings_change_only_existing_keys_in_their_own_shape( $plugin, $ns ) {
		$tools = $this->load( $plugin, $ns );
		$id    = $this->carousel();

		$result = $tools::update_carousel_settings(
			array(
				'id'       => $id,
				'settings' => array(
					'wpcp_carousel_auto_play' => false,
					'load_more_label'         => 'Work <script>alert(1)</script><strong>2026</strong>',
					'wpcp_number_of_columns'  => array(
						'lg_desktop' => 3,
						'phone'      => '1',
					),
					'wpcp_taxonomy_terms'     => array( 'news', array( 'x' ), 'events' ),
					'wpcp_injected_js'        => 'alert(1)',
					'load_more_label_bad'     => array( 'not', 'a', 'string' ),
				),
			)
		);

		$stored = get_post_meta( $id, 'sp_wpcp_shortcode_options', true );
		$this->assertSame( '0', $stored['wpcp_carousel_auto_play'], 'A switch is stored as the settings screen stores it.' );
		$this->assertSame( 'Work alert(1)<strong>2026</strong>', $stored['load_more_label'] );
		$this->assertSame(
			array(
				'lg_desktop' => 3,
				'tablet'     => '2',
			),
			$stored['wpcp_number_of_columns'],
			'A nested key that exists changes; a new one does not appear; the others stay.'
		);
		$this->assertSame( array( 'news', 'events' ), $stored['wpcp_taxonomy_terms'] );
		$this->assertArrayNotHasKey( 'wpcp_injected_js', $stored );
		$this->assertArrayNotHasKey( 'load_more_label_bad', $stored );
		$this->assertSame(
			array(
				'settings.wpcp_number_of_columns.phone:unknown_key',
				'settings.wpcp_taxonomy_terms.1:invalid_type',
				'settings.wpcp_injected_js:unknown_key',
				'settings.load_more_label_bad:unknown_key',
			),
			$this->dropped( $result )
		);

		$shape = $tools::update_carousel_settings(
			array(
				'id'       => $id,
				'settings' => array(
					'wpcp_number_of_columns' => '5',
					'load_more_label'        => array( 'x' ),
				),
			)
		);
		$this->assertSame( array( 'settings.wpcp_number_of_columns:invalid_type', 'settings.load_more_label:invalid_type' ), $this->dropped( $shape ) );
		$this->assertIsArray( get_post_meta( $id, 'sp_wpcp_shortcode_options', true )['wpcp_number_of_columns'], 'A group never becomes a value.' );
	}

	/**
	 * The source type is one this edition offers.
	 *
	 * @dataProvider editions
	 *
	 * @param string   $plugin Plugin folder.
	 * @param string   $ns     Namespace.
	 * @param string[] $types  Types this edition offers.
	 */
	public function test_the_source_type_is_one_this_edition_offers( $plugin, $ns, $types ) {
		$tools = $this->load( $plugin, $ns );
		$id    = $this->carousel();

		$this->assertSame( $types, $tools::CAROUSEL_TYPES );

		foreach ( array( 'evil', 'audio-carousel' ) as $type ) {
			$result  = $tools::update_carousel_settings(
				array(
					'id'   => $id,
					'type' => $type,
				)
			);
			$allowed = in_array( $type, $types, true );
			$this->assertSame( $allowed ? $type : 'image-carousel', get_post_meta( $id, 'sp_wpcp_upload_options', true )['wpcp_carousel_type'], $type );
			$this->assertSame( $allowed ? array() : array( 'type:invalid_value' ), $this->dropped( $result ), $type );
		}

		$through_options = $tools::update_carousel_settings(
			array(
				'id'             => $id,
				'upload_options' => array( 'wpcp_carousel_type' => 'evil' ),
			)
		);
		$this->assertSame( array( 'type:invalid_value' ), $this->dropped( $through_options ), 'The same rule applies through upload_options.' );
	}

	/**
	 * Editing a carousel is not the right to publish it.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin folder.
	 * @param string $ns     Namespace.
	 */
	public function test_a_user_who_may_edit_but_not_publish_cannot_publish( $plugin, $ns ) {
		$tools       = $this->load( $plugin, $ns );
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $contributor );
		$id = $this->carousel( $contributor );

		$result = $tools::update_carousel_settings(
			array(
				'id'     => $id,
				'status' => 'publish',
				'title'  => 'Renamed',
			)
		);

		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'Renamed', get_the_title( $id ), 'The rest of the update still applies.' );
		$this->assertSame( array( 'status:forbidden' ), $this->dropped( $result ) );
	}
}
