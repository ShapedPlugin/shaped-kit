<?php
/**
 * Shared integration tests for the WP Carousel builder tools (Phase 8.2).
 *
 * Runs `get-block-catalog`, `validate-block-attributes`, `insert-block`,
 * `list-ready-patterns`, `insert-ready-pattern`, and `build-carousel` prompt
 * against a real block registry holding the carousel block with the edition's
 * own registered schema attributes.
 * One body for Free and Pro (PRD §20 rule 6); each edition's `*Test.php` supplies
 * only its data through edition().
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * Class WpCarouselBuilderToolsTestCase
 */
abstract class WpCarouselBuilderToolsTestCase extends WP_UnitTestCase {

	/**
	 * Block registered for the test (both editions use the Pro prefix).
	 */
	const CAROUSEL = 'wp-carousel-pro/carousel';

	/**
	 * Registered carousel attributes, per test class.
	 *
	 * @var array<string, array>
	 */
	private static $attributes = array();

	/**
	 * Edition data.
	 *
	 * @return array{plugin: string, namespace: string, path_constant: string, unavailable_block: string, pro_patterns: bool}
	 */
	abstract protected static function edition();

	/**
	 * Fully qualified class name in the edition's namespace.
	 *
	 * @param string $relative Class name below the edition namespace.
	 * @return string
	 */
	protected static function edition_class( $relative ) {
		return static::edition()['namespace'] . '\\' . $relative;
	}

	/**
	 * The edition's Builder_Tools class.
	 *
	 * @return string
	 */
	protected function tools() {
		return static::edition_class( 'Mcp\\Builder\\Builder_Tools' );
	}

	/**
	 * The edition's Pattern_Tools class.
	 *
	 * @return string
	 */
	protected function pattern_tools() {
		return static::edition_class( 'Mcp\\Builder\\Pattern_Tools' );
	}

	/**
	 * The edition's AIPromptBuilder class.
	 *
	 * @return string
	 */
	protected function prompts() {
		$class_pro = static::edition_class( 'Includes\\AI\\AIPromptBuilder' );
		if ( class_exists( $class_pro ) ) {
			return $class_pro;
		}
		return static::edition_class( 'Blocks\\AI\\AIPromptBuilder' );
	}

	/**
	 * The edition's Abilities_Registrar class.
	 *
	 * @return string
	 */
	protected function registrar() {
		return static::edition_class( 'Mcp\\Abilities_Registrar' );
	}

	/**
	 * The edition's Permission_Gate class.
	 *
	 * @return string
	 */
	protected function gate() {
		return static::edition_class( 'Mcp\\Permission_Gate' );
	}

	/**
	 * The edition's ValueValidator class.
	 *
	 * @return string
	 */
	protected function validator() {
		return static::edition_class( 'Mcp\\Builder\\ValueValidator' );
	}

	/**
	 * The edition's AttributePatch class.
	 *
	 * @return string
	 */
	protected function patcher() {
		return static::edition_class( 'Mcp\\Builder\\AttributePatch' );
	}

	/**
	 * Load the edition's classes and its real carousel attribute set once.
	 */
	public static function wpSetUpBeforeClass() {
		$edition = static::edition();
		$dir     = dirname( __DIR__, 4 ) . '/' . $edition['plugin'];

		if ( ! file_exists( $dir . '/vendor/autoload.php' ) ) {
			return;
		}

		if ( ! class_exists( static::edition_class( 'Mcp\\Builder\\Builder_Tools' ) ) ) {
			require_once $dir . '/vendor/autoload.php';
		}

		if ( ! defined( 'WPCAROUSEL_VERSION' ) ) {
			define( 'WPCAROUSEL_VERSION', '5.1.0' );
		}

		if ( ! defined( 'WPCAROUSEL_PRO_FILE' ) ) {
			define( 'WPCAROUSEL_PRO_FILE', $dir . '/wp-carousel-pro.php' );
		}

		if ( ! defined( 'WPCAROUSEL_ITEM_NAME' ) ) {
			define( 'WPCAROUSEL_ITEM_NAME', 'WP Carousel Pro' );
		}

		if ( ! defined( 'WPCAROUSEL_ITEM_SLUG' ) ) {
			define( 'WPCAROUSEL_ITEM_SLUG', 'wp-carousel-pro' );
		}

		if ( ! defined( 'WPCAROUSEL_ITEM_ID' ) ) {
			define( 'WPCAROUSEL_ITEM_ID', 411 );
		}

		if ( ! defined( 'WPCAROUSEL_STORE_URL' ) ) {
			define( 'WPCAROUSEL_STORE_URL', 'https://account.shapedplugin.com' );
		}

		if ( ! defined( 'WPCAROUSEL_PRODUCT_URL' ) ) {
			define( 'WPCAROUSEL_PRODUCT_URL', 'https://wpcarousel.io/' );
		}

		if ( ! defined( $edition['path_constant'] ) ) {
			define( $edition['path_constant'], $dir . '/src/' );
		}

		$schema_class = static::edition_class( 'Blocks\\Schema\\CarouselSchema' );
		if ( class_exists( $schema_class ) ) {
			$schema                            = new $schema_class();
			self::$attributes[ static::class ] = $schema->get_attributes();
		}
	}

	/**
	 * Register the carousel block, as the plugin does for an active block.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! isset( self::$attributes[ static::class ] ) || ! class_exists( $this->tools() ) ) {
			$this->markTestSkipped( static::edition()['plugin'] . ' is not available next to the Kit.' );
		}

		update_option( 'wpcp_mcp_enabled', true );
		$this->register_carousel( self::$attributes[ static::class ] );
	}

	/**
	 * Remove the test block and clean state.
	 */
	public function tearDown(): void {
		$this->unregister_carousel();
		delete_option( 'wpcp_mcp_enabled' );
		delete_transient( 'wpcp_ready_patterns_manifest' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Answer the pattern server's single-pattern request with given markup.
	 *
	 * Goes through the edition's real fetch path instead of seeding its payload
	 * transient: Free keys that transient with a source fingerprint and Pro does not
	 * (each edition's PatternRepository::payload_cache_key()). Every other outbound
	 * request is refused, so a test never reaches the live pattern server.
	 *
	 * @param int    $pattern_id Pattern ID.
	 * @param string $markup     Pattern markup.
	 */
	private function serve_pattern( $pattern_id, $markup ) {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $pattern_id, $markup ) {
				if ( preg_match( '#/single-pattern/' . (int) $pattern_id . '$#', (string) $url ) ) {
					return array(
						'headers'  => array(),
						'body'     => wp_json_encode( array( 'content' => $markup ) ),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}
				return new \WP_Error( 'http_blocked', 'Outbound HTTP is blocked in this test.' );
			},
			10,
			3
		);
	}

	/**
	 * Register the carousel with a given attribute set.
	 *
	 * @param array $attributes Attribute definitions.
	 */
	private function register_carousel( $attributes ) {
		$this->unregister_carousel();
		register_block_type( self::CAROUSEL, array( 'attributes' => $attributes ) );
	}

	/**
	 * Unregister the carousel if present.
	 */
	private function unregister_carousel() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::CAROUSEL ) ) {
			unregister_block_type( self::CAROUSEL );
		}
	}

	/**
	 * Reason reported for a dropped key, or null when it was not dropped.
	 *
	 * @param array  $result Tool result.
	 * @param string $key    Attribute name.
	 * @return string|null
	 */
	private function reason_for( $result, $key ) {
		if ( empty( $result['dropped'] ) || ! is_array( $result['dropped'] ) ) {
			return null;
		}
		foreach ( $result['dropped'] as $entry ) {
			if ( $key === $entry['key'] ) {
				return $entry['reason'];
			}
		}

		return null;
	}

	/**
	 * Create and log in an administrator.
	 *
	 * @return int User ID.
	 */
	private function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * A page holding given content.
	 *
	 * @param string $content Post content.
	 * @param string $status  Post status.
	 * @return int Post ID.
	 */
	private function post_with( $content, $status = 'publish' ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => $status,
				'post_title'   => 'Carousel Showcase',
				'post_content' => wp_slash( $content ),
			)
		);
	}

	/**
	 * Real (named) top-level blocks of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function named_blocks( $post_id ) {
		return array_values(
			array_filter(
				parse_blocks( get_post( $post_id )->post_content ),
				static function ( $block ) {
					return ! empty( $block['blockName'] );
				}
			)
		);
	}

	/**
	 * Custom code and IDs never reach clean attributes; dropped as denied_key.
	 */
	public function test_custom_code_and_ids_are_dropped_as_denied_keys() {
		$result = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(
					'customCSS' => 'body{display:none}',
					'customJS'  => 'alert(1)',
					'uniqueId'  => 'hijack',
				),
			)
		);

		$clean = (array) $result['attributes'];
		foreach ( array( 'customCSS', 'customJS', 'uniqueId' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $clean );
			$this->assertSame( 'denied_key', $this->reason_for( $result, $key ) );
		}
	}

	/**
	 * Unknown keys and invalid values are dropped with distinct reasons; valid values pass.
	 */
	public function test_unknown_and_invalid_values_are_dropped_with_reasons() {
		$result = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(
					'nonExistentKey' => true,
					'sliderOptions'  => array(
						'autoplay' => true,
					),
				),
			)
		);

		$clean = (array) $result['attributes'];
		$this->assertSame( 'unknown_key', $this->reason_for( $result, 'nonExistentKey' ) );
		$this->assertNull( $this->reason_for( $result, 'sliderOptions.autoplay' ) );
		$this->assertTrue( $clean['sliderOptions']['autoplay'] );
	}

	/**
	 * Bad values inside objects are reported by path.
	 */
	public function test_bad_values_inside_objects_are_reported_by_path() {
		$result = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(
					'layoutOptions' => array(
						'unknownOption' => 'value',
					),
				),
			)
		);

		$this->assertSame( 'unknown_key', $this->reason_for( $result, 'layoutOptions.unknownOption' ) );
	}

	/**
	 * Single-layer gradients pass; multi-layer or url() gradients are dropped.
	 */
	public function test_gradient_with_an_extra_layer_is_dropped() {
		$attack = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(
					'styleOptions' => array(
						'gradientBackground' => 'linear-gradient(red,red),url(//evil.example/t.png)',
					),
				),
			)
		);

		$this->assertStringNotContainsString( 'evil.example', (string) wp_json_encode( $attack['attributes'] ) );

		$valid_gradient = 'linear-gradient(135deg,rgba(6,147,227,1) 0%,rgb(155,81,224) 100%)';
		$clean_gradient = $this->validator()::sanitize_gradient( $valid_gradient );
		$this->assertSame( $valid_gradient, $clean_gradient );

		$bad_gradient = 'linear-gradient(red,red);alert(1)';
		$this->assertNull( $this->validator()::sanitize_gradient( $bad_gradient ) );
	}

	/**
	 * Layout defaults apply only to fresh blocks.
	 */
	public function test_layout_defaults_apply_only_to_a_fresh_block() {
		$fresh = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(
					'sliderOptions' => array( 'autoplay' => true ),
				),
			)
		);

		$this->assertTrue( $fresh['is_fresh'] );
		$this->assertSame( 'image', ( (array) $fresh['attributes'] )['sourceType'] );

		$configured = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(
					'sliderOptions' => array( 'autoplay' => true ),
				),
				'existing'   => array( 'sourceType' => 'post' ),
			)
		);

		$this->assertFalse( $configured['is_fresh'] );
		$this->assertSame( array(), $configured['defaults_applied'] );
	}

	/**
	 * Catalog matches AIPromptBuilder system prompt and omits denied keys.
	 */
	public function test_catalog_matches_the_build_with_ai_system_prompt() {
		$result = $this->tools()::get_block_catalog( array( 'block' => 'carousel' ) );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( self::CAROUSEL, $result['block_name'] );
		$this->assertSame( 'carousel', $result['block'] );
		$this->assertStringContainsString( 'WP Carousel', $result['instructions'] );
		$this->assertStringNotContainsString( '"customCSS"', $result['instructions'] );
		$this->assertStringNotContainsString( '"customJS"', $result['instructions'] );
	}

	/**
	 * Catalog schema defaults follow the block registered live on this request.
	 */
	public function test_catalog_is_read_live_from_the_registered_block() {
		$probe = array(
			'type'    => 'boolean',
			'default' => true,
		);

		$catalog_class = static::edition_class( 'Mcp\\Builder\\Catalog' );

		$this->register_carousel( array_merge( self::$attributes[ static::class ], array( 'probeLiveSetting' => $probe ) ) );
		$defaults_with = $catalog_class::defaults( 'carousel' );

		$this->register_carousel( self::$attributes[ static::class ] );
		$defaults_without = $catalog_class::defaults( 'carousel' );

		$this->assertArrayHasKey( 'probeLiveSetting', $defaults_with );
		$this->assertTrue( $defaults_with['probeLiveSetting'] );
		$this->assertArrayNotHasKey( 'probeLiveSetting', $defaults_without );
	}

	/**
	 * A block in Catalog::BLOCKS that is not registered is refused with block_unavailable.
	 */
	public function test_block_not_registered_on_this_site_is_refused() {
		$unavailable = static::edition()['unavailable_block'];
		$result      = $this->tools()::get_block_catalog( array( 'block' => $unavailable ) );

		$this->assertWPError( $result );
		$this->assertSame( 'block_unavailable', $result->get_error_code() );

		$this->unregister_carousel();
		$gone = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(),
			)
		);
		$this->assertSame( 'block_unavailable', $gone->get_error_code() );
	}

	/**
	 * Unknown block slug is refused with invalid_block.
	 */
	public function test_unknown_block_is_refused() {
		$result = $this->tools()::get_block_catalog( array( 'block' => 'non-existent-block' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_block', $result->get_error_code() );
	}

	/**
	 * Builder tools enforce user capability permissions.
	 */
	public function test_builder_tools_refuse_users_without_the_base_capability() {
		$defs       = $this->registrar()::definitions();
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );

		foreach ( array( 'wp-carousel/get-block-catalog', 'wp-carousel/validate-block-attributes', 'wp-carousel/insert-block' ) as $name ) {
			if ( ! isset( $defs[ $name ] ) ) {
				continue;
			}
			$check = $defs[ $name ]['permission_callback'];

			wp_set_current_user( $subscriber );
			$this->assertFalse( (bool) call_user_func( $check ), $name . ' allowed a subscriber' );

			wp_set_current_user( $editor );
			$this->assertFalse( (bool) call_user_func( $check ), $name . ' allowed an editor' );

			wp_set_current_user( $admin );
			$this->assertTrue( (bool) call_user_func( $check ), $name . ' refused an administrator' );
		}
	}

	/**
	 * Column count specified in the prompt is applied to layoutOptions.columns.
	 */
	public function test_column_count_in_the_prompt_is_applied() {
		$result = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(
					'sliderOptions' => array( 'autoplay' => true ),
				),
				'prompt'     => 'A responsive 4-column carousel with autoplay',
			)
		);

		$patch = (array) $result['patch'];
		$this->assertSame( 4, $patch['layoutOptions']['columns'] );
	}

	/**
	 * New draft creates a draft page in one call with uniqueId.
	 */
	public function test_new_draft_creates_a_draft_page_in_one_call() {
		$this->admin();
		$result = $this->tools()::insert_block(
			array(
				'block'      => 'carousel',
				'attributes' => array(
					'sliderOptions' => array( 'autoplay' => true ),
				),
				'target'     => array( 'new_draft' => 'Featured Carousels' ),
			)
		);

		$this->assertSame( 'saved', $result['status'] );
		$this->assertSame( 'new_draft', $result['action'] );
		$this->assertSame( 'draft', get_post_status( $result['post_id'] ) );
		$this->assertSame( 'page', get_post_type( $result['post_id'] ) );
		$this->assertMatchesRegularExpression( '/^wpcp-carousel-[0-9a-f]{12}$/', $result['unique_id'] );
		$this->assertNotEmpty( $result['edit_url'] );

		$blocks = $this->named_blocks( $result['post_id'] );
		$this->assertSame( self::CAROUSEL, $blocks[0]['blockName'] );
	}

	/**
	 * Modifying an existing post requires WriteGuard Level 1 confirmation token.
	 */
	public function test_existing_post_changes_only_after_confirmation() {
		$this->admin();
		$before  = "<!-- wp:paragraph -->\n<p>Before text</p>\n<!-- /wp:paragraph -->";
		$post_id = $this->post_with( $before );
		$args    = array(
			'block'      => 'carousel',
			'attributes' => array(
				'sliderOptions' => array( 'autoplay' => true ),
			),
			'target'     => array( 'post_id' => $post_id ),
		);

		$preview = $this->tools()::insert_block( $args );
		$this->assertSame( 'confirmation_required', $preview['status'] );
		$this->assertNotEmpty( $preview['confirmation_token'] );
		$this->assertSame( $before, get_post( $post_id )->post_content );

		$saved = $this->tools()::insert_block( $args + array( 'confirmation_token' => $preview['confirmation_token'] ) );
		$this->assertSame( 'saved', $saved['status'] );
		$this->assertStringStartsWith( $before, get_post( $post_id )->post_content );
		$this->assertStringContainsString( 'wp:wp-carousel-pro/carousel', get_post( $post_id )->post_content );

		// Replay with the same token is refused.
		$replay = $this->tools()::insert_block( $args + array( 'confirmation_token' => $preview['confirmation_token'] ) );
		$this->assertWPError( $replay );
	}

	/**
	 * Changing the page between preview and confirmation is refused with state_changed.
	 */
	public function test_page_changed_after_preview_is_refused() {
		global $wpdb;
		$this->admin();
		$post_id  = $this->post_with( "<!-- wp:paragraph -->\n<p>Original</p>\n<!-- /wp:paragraph -->" );
		$args     = array(
			'block'      => 'carousel',
			'attributes' => array(),
			'target'     => array( 'post_id' => $post_id ),
		);
		$preview  = $this->tools()::insert_block( $args );
		$modified = get_post( $post_id );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => "<!-- wp:paragraph -->\n<p>Interfering change</p>\n<!-- /wp:paragraph -->",
			)
		);
		// Pin timestamps to same second to verify hash-based state protection.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $modified->post_modified,
				'post_modified_gmt' => $modified->post_modified_gmt,
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );

		$result = $this->tools()::insert_block( $args + array( 'confirmation_token' => $preview['confirmation_token'] ) );
		$this->assertWPError( $result );
		$this->assertSame( 'state_changed', $result->get_error_code() );
	}

	/**
	 * User without permission on target post is refused.
	 */
	public function test_user_who_cannot_edit_the_target_is_refused() {
		$this->admin();
		$post_id = $this->post_with( '' );

		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author_id );

		$result = $this->tools()::insert_block(
			array(
				'block'      => 'carousel',
				'attributes' => array(),
				'target'     => array( 'post_id' => $post_id ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Only a WP Carousel block can be replaced in place.
	 */
	public function test_core_block_cannot_be_replaced() {
		$this->admin();
		$post_id = $this->post_with( "<!-- wp:paragraph -->\n<p>Core block</p>\n<!-- /wp:paragraph -->" );

		$result = $this->tools()::insert_block(
			array(
				'block'         => 'carousel',
				'attributes'    => array(),
				'target'        => array( 'post_id' => $post_id ),
				'replace_block' => 0,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_target_block', $result->get_error_code() );
	}

	/**
	 * Replacing a block keeps its uniqueId and surrounding blocks.
	 */
	public function test_replace_keeps_unique_id_and_neighbours() {
		$this->admin();
		$before  = "<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:wp-carousel-pro/carousel {\"uniqueId\":\"wpcp-carousel-aaaaaaaaaaaa\"} /-->\n\n<!-- wp:paragraph -->\n<p>B</p>\n<!-- /wp:paragraph -->";
		$post_id = $this->post_with( $before );
		$args    = array(
			'block'         => 'carousel',
			'attributes'    => array(
				'sliderOptions' => array( 'autoplay' => true ),
			),
			'target'        => array( 'post_id' => $post_id ),
			'replace_block' => 1,
		);

		$preview = $this->tools()::insert_block( $args );
		$saved   = $this->tools()::insert_block( $args + array( 'confirmation_token' => $preview['confirmation_token'] ) );

		$this->assertSame( 'replace', $saved['action'] );
		$this->assertSame( 'wpcp-carousel-aaaaaaaaaaaa', $saved['unique_id'] );
		$this->assertStringContainsString( 'wpcp-carousel-aaaaaaaaaaaa', get_post( $post_id )->post_content );
		$this->assertStringContainsString( '<p>A</p>', get_post( $post_id )->post_content );
		$this->assertStringContainsString( '<p>B</p>', get_post( $post_id )->post_content );
	}

	/**
	 * A stored credential never changes through insert-block and is never returned (PRD E223, E224, decision S12).
	 *
	 * Both editions run the same builder code, so the Pro-only attributes are registered here for Free too.
	 */
	public function test_a_stored_credential_never_changes_through_insert_block_and_is_never_returned() {
		$this->admin();
		$this->register_carousel(
			array_merge(
				self::$attributes[ static::class ],
				array(
					'externalFeed' => array(
						'type'    => 'object',
						'default' => array(
							'feedUrl'     => '',
							'apiKey'      => '',
							'accessToken' => '',
						),
					),
					'scheduling'   => array(
						'type'    => 'object',
						'default' => array(
							'visibility'        => 'public',
							'password'          => '',
							'passwordFormTitle' => '',
						),
					),
				)
			)
		);

		$stored  = array(
			'uniqueId'     => 'wpcp-carousel-aaaaaaaaaaaa',
			'externalFeed' => array(
				'feedUrl'     => 'https://a.example/feed',
				'apiKey'      => 'RealFeedApiKey000000000ZQ9X',
				'accessToken' => 'RealFeedToken000000000AB7K',
			),
			'scheduling'   => array(
				'visibility' => 'password',
				'password'   => 'Visitor-Pass-9',
			),
		);
		$post_id = $this->post_with(
			serialize_block(
				array(
					'blockName'    => self::CAROUSEL,
					'attrs'        => $stored,
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			)
		);
		$replace = function ( array $attributes ) use ( $post_id ) {
			$args    = array(
				'block'         => 'carousel',
				'attributes'    => $attributes,
				'target'        => array( 'post_id' => $post_id ),
				'replace_block' => 0,
			);
			$preview = $this->tools()::insert_block( $args );
			$this->assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_code() . ': ' . $preview->get_error_message() . ' ' . wp_json_encode( $preview->get_error_data() ) : '' );

			return $this->tools()::insert_block( $args + array( 'confirmation_token' => $preview['confirmation_token'] ) );
		};
		$saved   = function () use ( $post_id ) {
			return $this->named_blocks( $post_id )[0]['attrs'];
		};

		// The agent echoes the masked values back and tries a new password.
		$echo = $replace(
			array(
				'externalFeed' => array(
					'apiKey'      => '••••••••',
					'accessToken' => array(
						'configured' => true,
						'last4'      => null,
					),
				),
				'scheduling'   => array( 'password' => 'agent-chosen' ),
				'apiKey'       => 'TopLevelGuess',
			)
		);
		$this->assertSame( 'secret_read_only', $this->reason_for( $echo, 'apiKey' ), 'A top-level credential key is refused as such, not as unknown.' );
		$this->assertSame( 'secret_read_only', $this->reason_for( $echo, 'externalFeed.apiKey' ) );
		$this->assertSame( 'secret_read_only', $this->reason_for( $echo, 'externalFeed.accessToken' ) );
		$this->assertSame( 'secret_read_only', $this->reason_for( $echo, 'scheduling.password' ) );
		$this->assertSame( 'RealFeedApiKey000000000ZQ9X', $saved()['externalFeed']['apiKey'] );
		$this->assertSame( 'RealFeedToken000000000AB7K', $saved()['externalFeed']['accessToken'] );
		$this->assertSame( 'Visitor-Pass-9', $saved()['scheduling']['password'] );

		// The agent changes one other field of each object: the merge is shallow, so the stored secrets must be kept.
		$one_field = $replace(
			array(
				'externalFeed' => array( 'feedUrl' => 'https://b.example/feed' ),
				'scheduling'   => array( 'passwordFormTitle' => 'Members only' ),
			)
		);
		$this->assertSame( 'RealFeedApiKey000000000ZQ9X', $saved()['externalFeed']['apiKey'], 'A one-field change of the object keeps the stored key.' );
		$this->assertSame( 'RealFeedToken000000000AB7K', $saved()['externalFeed']['accessToken'] );
		$this->assertSame( 'Visitor-Pass-9', $saved()['scheduling']['password'] );

		// No answer carries a secret or its last characters; the password is described.
		$answers = (string) wp_json_encode( array( $echo, $one_field ) );
		foreach ( array( 'RealFeedApiKey', 'ZQ9X', 'RealFeedToken', 'AB7K', 'Visitor-Pass-9' ) as $part ) {
			$this->assertStringNotContainsString( $part, $answers, "An insert-block answer carried {$part}." );
		}
		$this->assertSame(
			array(
				'configured' => true,
				'last4'      => null,
			),
			( (array) $one_field['attributes'] )['scheduling']['password']
		);
	}

	/**
	 * Post locked by another user is refused with post_locked.
	 */
	public function test_post_locked_by_another_user_is_refused() {
		$this->admin();
		$post_id = $this->post_with( '' );
		$other   = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_post_meta( $post_id, '_edit_lock', time() . ':' . $other );

		$result = $this->tools()::insert_block(
			array(
				'block'      => 'carousel',
				'attributes' => array(),
				'target'     => array( 'post_id' => $post_id ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'post_locked', $result->get_error_code() );
	}

	/**
	 * Retry with the same idempotency key returns the same draft without duplicating.
	 */
	public function test_retry_with_same_idempotency_key_makes_one_draft() {
		$this->admin();
		$key  = 'test-idemp-' . wp_generate_uuid4();
		$args = array(
			'block'           => 'carousel',
			'attributes'      => array(),
			'target'          => array( 'new_draft' => 'Idempotent Draft' ),
			'idempotency_key' => $key,
		);

		$first  = $this->tools()::insert_block( $args );
		$second = $this->tools()::insert_block( $args );

		$this->assertSame( 'saved', $first['status'] );
		$this->assertSame( $first['post_id'], $second['post_id'] );
	}

	/**
	 * List ready patterns respects search, block and pro filters.
	 */
	public function test_list_ready_patterns() {
		set_transient(
			'wpcp_ready_patterns_manifest',
			array(
				'source'     => 'https://demo.wpcarousel.io/wp-json/wp-carousel/v1/pattern-list/',
				'expires'    => time() + 3600,
				'normalized' => array(
					array(
						'id'         => 101,
						'name'       => 'Free Carousel Hero',
						'blockName'  => 'wp-carousel-pro/carousel',
						'category'   => 'Hero',
						'categories' => array( 'Hero' ),
						'pro'        => false,
					),
					array(
						'id'         => 102,
						'name'       => 'Pro 3D Showcase',
						'blockName'  => 'wp-carousel-pro/carousel-3d',
						'category'   => 'Showcase',
						'categories' => array( 'Showcase' ),
						'pro'        => true,
					),
				),
			)
		);

		$all = $this->pattern_tools()::list_ready_patterns( array() );
		$this->assertSame( 2, $all['total'] );

		$free_only = $this->pattern_tools()::list_ready_patterns( array( 'pro' => 'exclude' ) );
		$this->assertSame( 1, $free_only['total'] );
		$this->assertSame( 101, $free_only['items'][0]['id'] );

		$search = $this->pattern_tools()::list_ready_patterns( array( 'search' => 'Hero' ) );
		$this->assertSame( 1, $search['total'] );
		$this->assertSame( 101, $search['items'][0]['id'] );
	}

	/**
	 * Inserting a pro pattern is refused on Free edition, permitted on Pro.
	 */
	public function test_pro_pattern_guard_by_edition() {
		$this->admin();
		set_transient(
			'wpcp_ready_patterns_manifest',
			array(
				'source'     => 'https://demo.wpcarousel.io/wp-json/wp-carousel/v1/pattern-list/',
				'expires'    => time() + 3600,
				'normalized' => array(
					array(
						'id'         => 102,
						'name'       => 'Pro Carousel Showcase',
						'blockName'  => 'wp-carousel-pro/carousel',
						'category'   => 'Showcase',
						'categories' => array( 'Showcase' ),
						'pro'        => true,
					),
				),
			)
		);

		$this->serve_pattern( 102, '<!-- wp:wp-carousel-pro/carousel {"uniqueId":"wpcp-carousel-222222222222"} /-->' );

		$result = $this->pattern_tools()::insert_ready_pattern(
			array(
				'pattern_id' => 102,
				'target'     => array( 'new_draft' => 'Pro Pattern Page' ),
			)
		);

		if ( static::edition()['pro_patterns'] ) {
			$this->assertSame( 'saved', $result['status'] );
			$this->assertSame( 102, $result['pattern']['id'] );
		} else {
			$this->assertWPError( $result );
			$this->assertSame( 'pro_required', $result->get_error_code() );
		}
	}

	/**
	 * Insert ready pattern creates new draft with fresh uniqueId.
	 */
	public function test_insert_ready_pattern_into_new_draft() {
		$this->admin();
		set_transient(
			'wpcp_ready_patterns_manifest',
			array(
				'source'     => 'https://demo.wpcarousel.io/wp-json/wp-carousel/v1/pattern-list/',
				'expires'    => time() + 3600,
				'normalized' => array(
					array(
						'id'         => 101,
						'name'       => 'Free Carousel Hero',
						'blockName'  => 'wp-carousel-pro/carousel',
						'category'   => 'Hero',
						'categories' => array( 'Hero' ),
						'pro'        => false,
					),
				),
			)
		);

		$this->serve_pattern( 101, '<!-- wp:wp-carousel-pro/carousel {"uniqueId":"wpcp-carousel-111111111111"} /-->' );

		$result = $this->pattern_tools()::insert_ready_pattern(
			array(
				'pattern_id' => 101,
				'target'     => array( 'new_draft' => 'Pattern Draft Page' ),
			)
		);

		$this->assertSame( 'saved', $result['status'] );
		$this->assertSame( 101, $result['pattern']['id'] );
		$this->assertSame( 'draft', get_post_status( $result['post_id'] ) );
		$blocks = $this->named_blocks( $result['post_id'] );
		$this->assertSame( self::CAROUSEL, $blocks[0]['blockName'] );
	}

	/**
	 * When the AI Builder module is disabled, builder tools are omitted from served() and return module_disabled.
	 */
	public function test_builder_tools_omitted_when_ai_module_disabled() {
		add_filter( 'wpcp_ai_builder_enabled', '__return_false' );

		$catalog  = $this->tools()::get_block_catalog( array( 'block' => 'carousel' ) );
		$validate = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(),
			)
		);
		$insert   = $this->tools()::insert_block(
			array(
				'block'  => 'carousel',
				'target' => array( 'new_draft' => 'T' ),
			)
		);

		$this->assertSame( 'module_disabled', $catalog->get_error_code() );
		$this->assertSame( 'module_disabled', $validate->get_error_code() );
		$this->assertSame( 'module_disabled', $insert->get_error_code() );

		$served = $this->registrar()::names();
		$this->assertNotContains( 'wp-carousel/get-block-catalog', $served );
		$this->assertNotContains( 'wp-carousel/validate-block-attributes', $served );
		$this->assertNotContains( 'wp-carousel/insert-block', $served );

		remove_filter( 'wpcp_ai_builder_enabled', '__return_false' );
	}

	/**
	 * Build carousel guided prompt returns wire-compatible tool references.
	 */
	public function test_build_carousel_prompt() {
		$result = $this->tools()::build_carousel_prompt(
			array(
				'request' => 'Build a responsive 3-column carousel with autoplay and light cards.',
			)
		);

		$this->assertArrayHasKey( 'description', $result );
		$this->assertArrayHasKey( 'messages', $result );
		$text = $result['messages'][0]['content']['text'];

		$this->assertStringContainsString( 'wp-carousel-get-block-catalog', $text );
		$this->assertStringContainsString( 'wp-carousel-validate-block-attributes', $text );
		$this->assertStringContainsString( 'wp-carousel-insert-block', $text );
		$this->assertStringContainsString( 'wp-carousel-list-ready-patterns', $text );
		$this->assertStringContainsString( 'wp-carousel-insert-ready-pattern', $text );
	}
}
