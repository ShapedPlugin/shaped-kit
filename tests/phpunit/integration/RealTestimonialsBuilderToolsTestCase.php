<?php
/**
 * Shared integration tests for the Real Testimonials builder tools (AI builder PRD B1).
 *
 * Runs `get-block-catalog` and `validate-block-attributes` against a real block
 * registry holding the carousel block with the edition's own registered
 * attributes. One body for Free and Pro (PRD §20 rule 6); each edition's
 * `*Test.php` supplies only its data through edition().
 * Covers BL2 (custom code and ids never pass), BL9 (catalog read live from the
 * registered block), BL16 (every dropped key has a reason), BL17 (a block that
 * is not registered is refused), BL18 (drops inside objects reported by path)
 * and BL19 (single-layer gradients only).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * Class RealTestimonialsBuilderToolsTestCase
 */
abstract class RealTestimonialsBuilderToolsTestCase extends WP_UnitTestCase {

	/**
	 * Block registered for the test (both editions use the Pro prefix).
	 */
	const CAROUSEL = 'sp-testimonial-pro/carousel';

	/**
	 * Registered carousel attributes, per test class.
	 *
	 * @var array<string, array>
	 */
	private static $attributes = array();

	/**
	 * Every block's registered attributes, per test class.
	 *
	 * @var array<string, array>
	 */
	private static $all_attributes = array();

	/**
	 * Edition data.
	 *
	 * @return array{plugin: string, namespace: string, path_constant: string, unavailable_block: string, picked_design: string, text_key: string|null, pro_patterns: bool}
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
		return static::edition_class( 'Mcp\\Tools\\Builder_Tools' );
	}

	/**
	 * The edition's PromptBuilder class.
	 *
	 * @return string
	 */
	protected function prompts() {
		return static::edition_class( 'Blocks\\Includes\\AIBuilder\\PromptBuilder' );
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
	 * The edition's AttributePatch class.
	 *
	 * @return string
	 */
	protected function patcher() {
		return static::edition_class( 'Blocks\\Includes\\AIBuilder\\AttributePatch' );
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

		if ( ! class_exists( static::edition_class( 'Mcp\\Tools\\Builder_Tools' ) ) ) {
			require_once $dir . '/vendor/autoload.php';
		}

		if ( ! defined( $edition['path_constant'] ) ) {
			define( $edition['path_constant'], $dir . '/src/' );
		}

		$all                                   = require $dir . '/src/Blocks/Includes/block-attributes.php';
		self::$attributes[ static::class ]     = $all['carousel'];
		self::$all_attributes[ static::class ] = $all;
	}

	/**
	 * Register the carousel block, as the plugin does for an active block.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! isset( self::$attributes[ static::class ] ) || ! class_exists( $this->tools() ) ) {
			$this->markTestSkipped( static::edition()['plugin'] . ' is not available next to the Kit.' );
		}

		$this->register_carousel( self::$attributes[ static::class ] );
	}

	/**
	 * Blocks registered by a test, removed afterwards.
	 *
	 * @var string[]
	 */
	private $registered = array();

	/**
	 * Register another of the edition's blocks with its real attributes, for one test.
	 *
	 * @param string $slug Bare slug.
	 */
	private function register_block( $slug ) {
		$name = 'sp-testimonial-pro/' . $slug;
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			register_block_type( $name, array( 'attributes' => self::$all_attributes[ static::class ][ $slug ] ) );
			$this->registered[] = $name;
		}
	}

	/**
	 * Remove the test block.
	 */
	public function tearDown(): void {
		foreach ( $this->registered as $name ) {
			unregister_block_type( $name );
		}
		$this->registered = array();
		$this->unregister_carousel();
		wp_set_current_user( 0 );
		parent::tearDown();
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
		foreach ( $result['dropped'] as $entry ) {
			if ( $key === $entry['key'] ) {
				return $entry['reason'];
			}
		}

		return null;
	}

	/**
	 * BL2: custom code and ids never reach the clean attributes, and the model is told why.
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
	 * BL16: unknown keys and invalid values are dropped with distinct reasons; valid values pass.
	 */
	public function test_unknown_and_invalid_values_are_dropped_with_reasons() {
		$result = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(
					'madeUpKey'      => true,
					'cardDesign'     => 'design-ninety',
					'sliderAutoPlay' => true,
				),
			)
		);

		$clean = (array) $result['attributes'];
		$this->assertSame( 'unknown_key', $this->reason_for( $result, 'madeUpKey' ) );
		$this->assertSame( 'invalid_value', $this->reason_for( $result, 'cardDesign' ) );
		$this->assertNull( $this->reason_for( $result, 'sliderAutoPlay' ) );
		$this->assertTrue( $clean['sliderAutoPlay'] );
	}

	/**
	 * BL18: a bad value inside a settings object is reported by its path, and never reaches the clean
	 * attributes; the valid values beside it still apply.
	 */
	public function test_bad_values_inside_objects_are_reported_by_path() {
		$result = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(
					'titleColors'     => array(
						'normal' => 'javascript:alert(1)',
						'hover'  => '#112233',
						'bogus'  => '#000000',
					),
					'titleTypography' => array(
						'family'     => 'Comic Sans',
						'fontWeight' => '950',
					),
				),
			)
		);

		$this->assertSame( 'invalid_value', $this->reason_for( $result, 'titleColors.normal' ) );
		$this->assertSame( 'unknown_key', $this->reason_for( $result, 'titleColors.bogus' ) );
		$this->assertSame( 'not_editable', $this->reason_for( $result, 'titleTypography.family' ) );
		$this->assertSame( 'invalid_value', $this->reason_for( $result, 'titleTypography.fontWeight' ) );
		$this->assertNull( $this->reason_for( $result, 'titleColors.hover' ) );

		$colors = ( (array) $result['attributes'] )['titleColors'];
		$this->assertSame( '#112233', $colors['hover'] );
		$this->assertSame( self::$attributes[ static::class ]['titleColors']['default']['normal'], $colors['normal'] );
		$this->assertArrayNotHasKey( 'bogus', $colors );
	}

	/**
	 * BL19 / EXISTING_PLUGIN_BUGS B8: a gradient carrying a second layer (an outside url()) never reaches
	 * the clean attributes, which the front end prints into the page CSS; a normal gradient still does.
	 */
	public function test_gradient_with_an_extra_layer_is_dropped() {
		$attack = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(
					'cardBackground' => array(
						'normal' => array(
							'style'    => 'gradient',
							'gradient' => 'linear-gradient(red,red),url(//evil.example/t.png)',
						),
					),
				),
			)
		);

		$this->assertSame( 'invalid_value', $this->reason_for( $attack, 'cardBackground.normal.gradient' ) );
		$this->assertStringNotContainsString( 'evil.example', (string) wp_json_encode( $attack['attributes'] ) );

		$gradient = 'linear-gradient(135deg,rgba(6,147,227,1) 0%,rgb(155,81,224) 100%)';
		$fine     = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(
					'cardBackground' => array(
						'normal' => array(
							'style'    => 'gradient',
							'gradient' => $gradient,
						),
					),
				),
			)
		);

		$this->assertSame( $gradient, ( (array) $fine['attributes'] )['cardBackground']['normal']['gradient'] );
		$this->assertNull( $this->reason_for( $fine, 'cardBackground.normal.gradient' ) );
	}

	/**
	 * A fresh block gets its layout defaults; a configured block (with `existing`) does not.
	 */
	public function test_layout_defaults_apply_only_to_a_fresh_block() {
		$fresh = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array( 'sliderAutoPlay' => true ),
			)
		);

		$this->assertTrue( $fresh['is_fresh'] );
		$this->assertSame( 'design-one', ( (array) $fresh['attributes'] )['cardDesign'] );
		$this->assertSame( array( 'cardDesign' ), $fresh['defaults_applied'] );

		$configured = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array( 'sliderAutoPlay' => true ),
				'existing'   => array( 'cardDesign' => 'design-three' ),
			)
		);

		$this->assertFalse( $configured['is_fresh'] );
		$this->assertArrayNotHasKey( 'cardDesign', (array) $configured['attributes'] );
		$this->assertSame( array(), $configured['defaults_applied'] );
	}

	/**
	 * The catalog is the exact text the Build with AI modal sends, so both surfaces offer the same values.
	 */
	public function test_catalog_matches_the_build_with_ai_system_prompt() {
		$result = $this->tools()::get_block_catalog( array( 'block' => 'carousel' ) );

		$this->assertSame( $this->prompts()::system( 'carousel' ), $result['instructions'] );
		$this->assertSame( self::CAROUSEL, $result['block_name'] );
		$this->assertStringNotContainsString( '"customCSS"', $result['instructions'] );
	}

	/**
	 * BL9: the catalog follows the block registered on this request, never a cached copy.
	 */
	public function test_catalog_is_read_live_from_the_registered_block() {
		$probe = array(
			'type'    => 'boolean',
			'default' => false,
		);

		$this->register_carousel( array_merge( self::$attributes[ static::class ], array( 'probeLiveSetting' => $probe ) ) );
		$with = $this->tools()::get_block_catalog( array( 'block' => 'carousel' ) );

		$this->register_carousel( self::$attributes[ static::class ] );
		$without = $this->tools()::get_block_catalog( array( 'block' => 'carousel' ) );

		$this->assertStringContainsString( '"probeLiveSetting"', $with['instructions'] );
		$this->assertStringNotContainsString( '"probeLiveSetting"', $without['instructions'] );
	}

	/**
	 * BL17: a block this edition lists but this site has not registered is refused, with what is available.
	 */
	public function test_block_not_registered_on_this_site_is_refused() {
		$result = $this->tools()::get_block_catalog( array( 'block' => static::edition()['unavailable_block'] ) );

		$this->assertWPError( $result );
		$this->assertSame( 'block_unavailable', $result->get_error_code() );
		$this->assertSame( array( 'carousel' ), $result->get_error_data()['available'] );

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
	 * A block name outside the edition's list is refused.
	 */
	public function test_unknown_block_is_refused() {
		$result = $this->tools()::get_block_catalog( array( 'block' => 'core/html' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_block', $result->get_error_code() );
	}

	/**
	 * Builder tools answer only users who hold the product's base capability.
	 */
	public function test_builder_tools_refuse_users_without_the_base_capability() {
		$defs       = $this->registrar()::definitions();
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );

		foreach ( array( 'real-testimonials/get-block-catalog', 'real-testimonials/validate-block-attributes' ) as $name ) {
			$check = $defs[ $name ]['permission_callback'];

			wp_set_current_user( $subscriber );
			$this->assertFalse( call_user_func( $check ), $name . ' allowed a subscriber' );

			wp_set_current_user( $editor );
			$this->assertFalse( call_user_func( $check ), $name . ' allowed an editor' );

			wp_set_current_user( $admin );
			$this->assertTrue( call_user_func( $check ), $name . ' refused an administrator' );
		}
	}

	/**
	 * BL13: the MCP tool returns exactly the patch the editor builds — the preset for the picked
	 * design, the answer on top — through the same BuildPipeline the Build with AI modal uses.
	 */
	public function test_validate_returns_the_patch_the_editor_would_write() {
		$result = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array(
					'cardDesign'     => static::edition()['picked_design'],
					'sliderAutoPlay' => true,
				),
			)
		);

		$defaults = array();
		foreach ( self::$attributes[ static::class ] as $key => $definition ) {
			if ( array_key_exists( 'default', $definition ) ) {
				$defaults[ $key ] = $definition['default'];
			}
		}

		$patch    = (array) $result['patch'];
		$expected = $this->patcher()::build( 'carousel', (array) $result['attributes'], $defaults, '' );

		$this->assertSame( $expected, $patch );
		$order = constant( $this->patcher() . '::CARD_ITEMS_POSITIONS' )[ static::edition()['picked_design'] ];
		$this->assertSame( static::edition()['picked_design'], $patch['cardDesign'] );
		$this->assertTrue( $patch['sliderAutoPlay'] );
		$this->assertArrayHasKey( 'excerptMargin', $patch );
		$this->assertSame( array_slice( $order, 0, 4 ), array_slice( array_column( $patch['cardContents'], 'id' ), 0, 4 ) );
	}

	/**
	 * A saved block keeps only its non-default values (parse_blocks); the pipeline completes it from
	 * the registered defaults, so the design preset still has the card items to reorder.
	 */
	public function test_sparse_saved_block_is_completed_from_defaults() {
		$result = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array( 'cardDesign' => static::edition()['picked_design'] ),
				'existing'   => array( 'cardDesign' => 'design-one' ),
			)
		);

		$patch = (array) $result['patch'];
		$this->assertFalse( $result['is_fresh'] );
		$this->assertArrayHasKey( 'cardContents', $patch );
		$order = constant( $this->patcher() . '::CARD_ITEMS_POSITIONS' )[ static::edition()['picked_design'] ];
		$this->assertSame( $order[0], $patch['cardContents'][0]['id'] );
	}

	/**
	 * An explicit column count in the request is applied, as the modal's column override does.
	 */
	public function test_column_count_in_the_prompt_is_applied() {
		$result = $this->tools()::validate_block_attributes(
			array(
				'block'      => 'carousel',
				'attributes' => array( 'sliderAutoPlay' => true ),
				'prompt'     => 'A dark carousel, 5 columns, autoplay',
			)
		);

		$this->assertSame( 5, ( (array) $result['patch'] )['columns']['device']['Desktop'] );
		$this->assertArrayNotHasKey( 'columns', (array) $result['attributes'] );
	}

	/**
	 * A user able to manage testimonials, logged in.
	 *
	 * @return int
	 */
	private function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * A page holding exactly this content (slashed, as wp_insert_post expects).
	 *
	 * @param string $content Post content.
	 * @param string $status  Post status.
	 * @return int
	 */
	private function post_with( $content, $status = 'publish' ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => $status,
				'post_title'   => 'Testimonials',
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
	 * A new draft is one call (D16): a draft page holding the block, with its own uniqueId.
	 */
	public function test_new_draft_creates_a_draft_page_in_one_call() {
		$this->admin();
		$result = $this->tools()::insert_block(
			array(
				'block'      => 'carousel',
				'attributes' => array( 'sliderAutoPlay' => true ),
				'target'     => array( 'new_draft' => 'Customer love' ),
			)
		);

		$this->assertSame( 'saved', $result['status'] );
		$this->assertSame( 'draft', get_post_status( $result['post_id'] ) );
		$this->assertSame( 'page', get_post_type( $result['post_id'] ) );
		$this->assertSame( array(), $result['altered'] );
		$this->assertMatchesRegularExpression( '/^sp-real-[0-9a-f]{12}$/', $result['unique_id'] );
		$this->assertNotEmpty( $result['edit_url'] );
		$blocks = $this->named_blocks( $result['post_id'] );
		$this->assertSame( self::CAROUSEL, $blocks[0]['blockName'] );
		$this->assertTrue( $blocks[0]['attrs']['sliderAutoPlay'] );
	}

	/**
	 * A block whose toggles need add-ons is saved wrapped, as the editor leaves it.
	 */
	public function test_grid_is_saved_inside_its_group_with_pagination() {
		$this->register_block( 'grid' );
		$this->register_block( 'ajax-pagination' );
		$this->admin();

		$result = $this->tools()::insert_block(
			array(
				'block'      => 'grid',
				'attributes' => array( 'cardDesign' => static::edition()['picked_design'] ),
				'target'     => array( 'new_draft' => 'Grid page' ),
			)
		);

		$top = $this->named_blocks( $result['post_id'] )[0];
		$this->assertSame( 'sp-testimonial-pro/testimonial-group', $top['blockName'] );
		$this->assertSame(
			array( 'sp-testimonial-pro/grid', 'sp-testimonial-pro/ajax-pagination' ),
			array_column( $top['innerBlocks'], 'blockName' )
		);
		$this->assertStringContainsString( 'id="' . $top['attrs']['uniqueId'] . '" class="sp-real-testimonial-group"', $top['innerHTML'] );
		$ids = array( $top['attrs']['uniqueId'], $top['innerBlocks'][0]['attrs']['uniqueId'], $top['innerBlocks'][1]['attrs']['uniqueId'] );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	/**
	 * D16 / FR-S2: an existing published page changes only after preview and confirmation.
	 */
	public function test_existing_post_changes_only_after_confirmation() {
		$this->admin();
		$before  = "<!-- wp:paragraph -->\n<p>Intro</p>\n<!-- /wp:paragraph -->";
		$post_id = $this->post_with( $before );
		$args    = array(
			'block'      => 'carousel',
			'attributes' => array( 'sliderAutoPlay' => true ),
			'target'     => array( 'post_id' => $post_id ),
		);

		$preview = $this->tools()::insert_block( $args );
		$this->assertSame( 'confirmation_required', $preview['status'] );
		$this->assertSame( $before, get_post( $post_id )->post_content );

		$saved = $this->tools()::insert_block( $args + array( 'confirmation_token' => $preview['confirmation_token'] ) );
		$this->assertSame( 'saved', $saved['status'] );
		$this->assertStringStartsWith( $before . "\n\n<!-- wp:sp-testimonial-pro/carousel ", get_post( $post_id )->post_content );

		$replay = $this->tools()::insert_block( $args + array( 'confirmation_token' => $preview['confirmation_token'] ) );
		$this->assertWPError( $replay );
	}

	/**
	 * BL4: the page changed between preview and confirmation, even within the same second.
	 */
	public function test_page_changed_after_preview_is_refused() {
		global $wpdb;
		$this->admin();
		$post_id  = $this->post_with( "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->" );
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
				'post_content' => "<!-- wp:paragraph -->\n<p>Two</p>\n<!-- /wp:paragraph -->",
			)
		);
		// Same second as the preview: post_modified alone cannot tell.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture: pin the modified time.
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
		$this->assertStringNotContainsString( 'sp-testimonial-pro/carousel', get_post( $post_id )->post_content );
	}

	/**
	 * BL3: a user who passes the tool gate but cannot edit the target page is refused.
	 */
	public function test_user_who_cannot_edit_the_target_is_refused() {
		$this->admin();
		$post_id = $this->post_with( '' );

		add_filter( 'sp_testimonial_mcp_access_capability', array( $this, 'access_for_authors' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$gate   = call_user_func( $this->registrar()::definitions()['real-testimonials/insert-block']['permission_callback'] );
		$result = $this->tools()::insert_block(
			array(
				'block'      => 'carousel',
				'attributes' => array(),
				'target'     => array( 'post_id' => $post_id ),
			)
		);
		remove_filter( 'sp_testimonial_mcp_access_capability', array( $this, 'access_for_authors' ) );

		$this->assertTrue( $gate );
		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Lowers the MCP base capability to authors.
	 *
	 * @return string
	 */
	public function access_for_authors() {
		return 'edit_posts';
	}

	/**
	 * BL5: only a Real Testimonials block can be replaced, never a core or foreign one.
	 */
	public function test_core_block_cannot_be_replaced() {
		$this->admin();
		$post_id = $this->post_with( "<!-- wp:html -->\n<script>x</script>\n<!-- /wp:html -->" );

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
	 * Replacing updates the block in place: its uniqueId stays, the blocks around it are untouched.
	 */
	public function test_replace_keeps_unique_id_and_neighbours() {
		$this->admin();
		$before  = "<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:sp-testimonial-pro/carousel {\"uniqueId\":\"sp-real-aaaaaaaaaaaa\"} /-->\n\n<!-- wp:paragraph -->\n<p>B</p>\n<!-- /wp:paragraph -->";
		$post_id = $this->post_with( $before );
		$args    = array(
			'block'         => 'carousel',
			'attributes'    => array( 'sliderAutoPlay' => true ),
			'target'        => array( 'post_id' => $post_id ),
			'replace_block' => 1,
		);

		$preview = $this->tools()::insert_block( $args );
		$saved   = $this->tools()::insert_block( $args + array( 'confirmation_token' => $preview['confirmation_token'] ) );

		$this->assertSame( 'replace', $saved['action'] );
		$this->assertSame( 'sp-real-aaaaaaaaaaaa', $saved['unique_id'] );
		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:sp-testimonial-pro/carousel {\"uniqueId\":\"sp-real-aaaaaaaaaaaa\",\"sliderAutoPlay\":true} /-->\n\n<!-- wp:paragraph -->\n<p>B</p>\n<!-- /wp:paragraph -->",
			get_post( $post_id )->post_content
		);
	}

	/**
	 * Backslashes survive: the post is slashed for wp_insert_post(), which unslashes it. The title
	 * carries one in every edition; a text setting does where the edition lets the AI set one.
	 */
	public function test_backslashes_in_values_survive_saving() {
		$this->admin();
		$text   = static::edition()['text_key'];
		$result = $this->tools()::insert_block(
			array(
				'block'      => 'carousel',
				'attributes' => $text ? array( $text => 'Read \\ more -- now' ) : array(),
				'target'     => array( 'new_draft' => 'Back \\ slash' ),
			)
		);

		$this->assertSame( array(), $result['altered'] );
		$this->assertSame( 'Back \\ slash', get_post( $result['post_id'] )->post_title );
		if ( $text ) {
			$this->assertSame( 'Read \\ more -- now', $this->named_blocks( $result['post_id'] )[0]['attrs'][ $text ] );
		}
	}

	/**
	 * A page someone else is editing right now is not changed under them.
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
	 * A retried new-draft call with the same idempotency key makes one draft, not two.
	 */
	public function test_retry_with_same_idempotency_key_makes_one_draft() {
		$this->admin();
		$args = array(
			'block'           => 'carousel',
			'attributes'      => array(),
			'target'          => array( 'new_draft' => 'Once' ),
			'idempotency_key' => 'key-123',
		);

		$first  = $this->tools()::insert_block( $args );
		$second = $this->tools()::insert_block( $args );

		$this->assertSame( $first['post_id'], $second['post_id'] );
		$this->assertTrue( $second['idempotent_replay'] );
	}

	/**
	 * Requests the code under test tried to make.
	 *
	 * @var array<int, array{url: string, args: array}>
	 */
	private $http = array();

	/**
	 * Body the fake library server returns for the pattern list.
	 *
	 * @var string
	 */
	private $list_body = '';

	/**
	 * Markup the fake library server returns per pattern id.
	 *
	 * @var array<int, string>
	 */
	private $markups = array();

	/**
	 * The edition's Pattern_Tools class.
	 *
	 * @return string
	 */
	private function patterns() {
		return static::edition_class( 'Mcp\\Tools\\Pattern_Tools' );
	}

	/**
	 * The edition's PatternLibrary class.
	 *
	 * @return string
	 */
	private function library() {
		return static::edition_class( 'Blocks\\Includes\\AIBuilder\\PatternLibrary' );
	}

	/**
	 * A list in the library's real shape (records and categories from the live cache file).
	 *
	 * @return string
	 */
	private function list_json() {
		return wp_json_encode(
			array(
				'meta_data' => array(
					'categories' => array(
						array(
							'label' => 'Nonprofits & Causes',
							'value' => 3,
							'count' => 15,
						),
						array(
							'label' => 'B2B & Manufacturing',
							'value' => 4,
							'count' => 15,
						),
						array(
							'label' => 'Real Estate & Property',
							'value' => 7,
							'count' => 15,
						),
					),
				),
				'patterns'  => array(
					'carousel' => array(
						array(
							'ID'       => 4299,
							'name'     => 'Carousel &#8211; Design 01',
							'image'    => 'https://demo.realtestimonials.io/a.webp',
							'url'      => 'https://demo.realtestimonials.io/rt-pattern/a/',
							'pro'      => true,
							'hit'      => 9,
							'type'     => 'pattern',
							'category' => array( 3 ),
						),
						array(
							'ID'       => 4300,
							'name'     => 'Carousel &#8211; Design 02',
							'image'    => 'https://demo.realtestimonials.io/b.webp',
							'url'      => 'https://demo.realtestimonials.io/rt-pattern/b/',
							'pro'      => false,
							'hit'      => 4,
							'type'     => 'pattern',
							'category' => array( 4 ),
						),
					),
					'grid'     => array(
						array(
							'ID'       => 4311,
							'name'     => 'Grid &#8211; Design 01',
							'image'    => 'https://demo.realtestimonials.io/c.webp',
							'url'      => 'https://demo.realtestimonials.io/rt-pattern/c/',
							'pro'      => false,
							'hit'      => 2,
							'type'     => 'pattern',
							'category' => array( 7 ),
						),
						array(
							'ID'       => 4312,
							'name'     => 'Grid &#8211; Design 02',
							'image'    => 'https://demo.realtestimonials.io/d.webp',
							'url'      => 'https://demo.realtestimonials.io/rt-pattern/d/',
							'pro'      => true,
							'hit'      => 1,
							'type'     => 'pattern',
							'category' => array( 7 ),
						),
					),
					'masonry'  => array(
						array(
							'ID'       => 4317,
							'name'     => 'Masonry &#8211; Design 01',
							'image'    => 'https://demo.realtestimonials.io/e.webp',
							'url'      => 'https://demo.realtestimonials.io/rt-pattern/e/',
							'pro'      => true,
							'hit'      => 3,
							'type'     => 'pattern',
							'category' => array( 7 ),
						),
					),
				),
			)
		);
	}

	/**
	 * A grid pattern shaped like the editor's own (page 14986), with the demo's query selection.
	 *
	 * @return string
	 */
	private function grid_markup() {
		return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">What clients say</h2>\n<!-- /wp:heading -->\n\n"
			. "<!-- wp:sp-testimonial-pro/testimonial-group {\"uniqueId\":\"sp-real-demo0group01\",\"align\":\"wide\"} -->\n"
			. '<div class="sp-real-testimonial-block alignwide"><div id="sp-real-demo0group01" class="sp-real-testimonial-group">'
			. '<!-- wp:sp-testimonial-pro/grid {"uniqueId":"sp-real-demo0grid001","cardDesign":"' . static::edition()['picked_design'] . '","includesItems":[12,13],"limit":"12","filterBy":"star-rating"} /-->'
			. "\n\n" . '<!-- wp:sp-testimonial-pro/ajax-pagination {"uniqueId":"sp-real-demo0pag0001"} /--></div></div>' . "\n"
			. '<!-- /wp:sp-testimonial-pro/testimonial-group -->';
	}

	/**
	 * The fake library server.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request arguments.
	 * @param string $url  URL.
	 * @return array|\WP_Error
	 */
	public function fake_library( $pre, $args, $url ) {
		$this->http[] = array(
			'url'  => $url,
			'args' => $args,
		);
		$library      = $this->library();

		if ( constant( $library . '::LIST_URL' ) === $url ) {
			$body = $this->list_body;
		} elseif ( constant( $library . '::SINGLE_URL' ) === $url ) {
			$id   = (int) ( $args['body']['template_id'] ?? 0 );
			$body = isset( $this->markups[ $id ] ) ? wp_json_encode(
				array(
					'success' => true,
					'rawData' => $this->markups[ $id ],
				)
			) : wp_json_encode( array( 'success' => false ) );
		} else {
			return new \WP_Error( 'blocked', 'Unexpected request in a test.' );
		}

		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Fake library with a cached list and the grid pattern; grid and pagination registered.
	 */
	private function with_library() {
		$this->register_block( 'grid' );
		$this->register_block( 'ajax-pagination' );
		$this->list_body = $this->list_json();
		$this->markups   = array( 4311 => $this->grid_markup() );
		add_filter( 'pre_http_request', array( $this, 'fake_library' ), 10, 3 );

		$path = $this->library()::cache_path();
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $this->list_body ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
	}

	/**
	 * Remove the fake library and its cache file.
	 */
	private function without_library() {
		remove_filter( 'pre_http_request', array( $this, 'fake_library' ), 10 );
		$path = $this->library()::cache_path();
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * D17: on Free a Pro pattern is listed in short form after the usable ones, with the upgrade
	 * link; on Pro every pattern is listed in full.
	 */
	public function test_list_marks_pro_patterns_by_edition() {
		$this->admin();
		$this->with_library();
		$result = $this->patterns()::list_ready_patterns( array( 'per_page' => 100 ) );
		$this->without_library();

		$by_id = array_column( $result['items'], null, 'id' );
		$this->assertSame( 5, $result['total'] );
		$this->assertSame( 'Carousel – Design 02', $by_id[4300]['name'] );
		$this->assertSame( array( 'B2B & Manufacturing' ), $by_id[4300]['categories'] );
		$this->assertTrue( $by_id[4311]['insertable'] );

		if ( static::edition()['pro_patterns'] ) {
			$this->assertArrayHasKey( 'image', $by_id[4299] );
			$this->assertArrayNotHasKey( 'note', $result );
		} else {
			$this->assertSame( array( 'id', 'name', 'block', 'pro', 'insertable', 'upgrade_url' ), array_keys( $by_id[4299] ) );
			$this->assertFalse( $by_id[4299]['insertable'] );
			$this->assertSame( array( 4300, 4311 ), array_slice( array_column( $result['items'], 'id' ), 0, 2 ) );
			$this->assertArrayHasKey( 'note', $result );
		}
	}

	/**
	 * Filters: by block, by words, and Free-only.
	 */
	public function test_list_filters_by_block_words_and_free_only() {
		$this->admin();
		$this->with_library();
		$grid = $this->patterns()::list_ready_patterns( array( 'block' => 'grid' ) );
		$word = $this->patterns()::list_ready_patterns( array( 'search' => 'real estate' ) );
		$free = $this->patterns()::list_ready_patterns( array( 'pro' => 'exclude' ) );
		$this->without_library();

		$this->assertSame( array( 4311, 4312 ), array_column( $grid['items'], 'id' ) );
		$this->assertSame( 3, $word['total'] );
		$this->assertSame( array( 4300, 4311 ), array_column( $free['items'], 'id' ) );
	}

	/**
	 * BL10: on Free, a Pro pattern is refused on the server before any request leaves the site.
	 */
	public function test_pro_pattern_on_free_is_refused_before_any_request() {
		if ( static::edition()['pro_patterns'] ) {
			$this->assertTrue( true ); // Pro may insert Pro patterns; covered by the insert tests.
			return;
		}

		$this->admin();
		$this->with_library();
		$result   = $this->patterns()::insert_ready_pattern(
			array(
				'pattern_id' => 4312,
				'target'     => array( 'new_draft' => 'Nope' ),
			)
		);
		$requests = $this->http;
		$this->without_library();

		$this->assertWPError( $result );
		$this->assertSame( 'pro_required', $result->get_error_code() );
		$this->assertSame( 'https://realtestimonials.io/pricing/', $result->get_error_data()['upgrade_url'] );
		$this->assertSame( array(), $requests );
	}

	/**
	 * A pattern lands as the editor leaves it: the demo's query selection reset, only non-default
	 * attributes kept, its own blocks and ids, and only template_id sent to the library.
	 */
	public function test_pattern_into_new_draft_is_saved_as_the_editor_would() {
		$this->admin();
		$this->with_library();
		$result   = $this->patterns()::insert_ready_pattern(
			array(
				'pattern_id' => 4311,
				'target'     => array( 'new_draft' => 'Grid page' ),
			)
		);
		$requests = $this->http;
		$this->without_library();

		$this->assertSame( 'saved', $result['status'] );
		$this->assertFalse( $result['altered'] );
		$this->assertSame( array( 'core/heading', 'sp-testimonial-pro/testimonial-group', 'sp-testimonial-pro/grid', 'sp-testimonial-pro/ajax-pagination' ), $result['blocks'] );

		$grid = $this->named_blocks( $result['post_id'] )[1]['innerBlocks'][0];
		$this->assertSame( 'sp-real-demo0grid001', $grid['attrs']['uniqueId'] );
		$this->assertArrayNotHasKey( 'includesItems', $grid['attrs'] );
		$this->assertArrayNotHasKey( 'filterBy', $grid['attrs'] );

		$this->assertCount( 1, $requests );
		$this->assertSame( array( 'license', 'template_id' ), array_keys( $requests[0]['args']['body'] ) );
		$this->assertSame( 4311, $requests[0]['args']['body']['template_id'] );
	}

	/**
	 * A pattern block whose uniqueId is already on the page gets a new one; the group keeps its own.
	 */
	public function test_clashing_unique_id_is_renewed_on_an_existing_page() {
		$this->admin();
		$post_id = $this->post_with( '<!-- wp:sp-testimonial-pro/carousel {"uniqueId":"sp-real-demo0grid001"} /-->' );
		$this->with_library();
		$args    = array(
			'pattern_id' => 4311,
			'target'     => array( 'post_id' => $post_id ),
		);
		$preview = $this->patterns()::insert_ready_pattern( $args );
		$saved   = $this->patterns()::insert_ready_pattern( $args + array( 'confirmation_token' => $preview['confirmation_token'] ) );
		$this->without_library();

		$this->assertSame( 'confirmation_required', $preview['status'] );
		$this->assertSame( 'saved', $saved['status'] );
		$group = $this->named_blocks( $post_id )[2];
		$this->assertSame( 'sp-real-demo0group01', $group['attrs']['uniqueId'] );
		$this->assertNotSame( 'sp-real-demo0grid001', $group['innerBlocks'][0]['attrs']['uniqueId'] );
		$this->assertMatchesRegularExpression( '/^sp-real-[0-9a-f]{12}$/', $group['innerBlocks'][0]['attrs']['uniqueId'] );
	}

	/**
	 * BL6: a pattern carrying raw HTML (or any denied block) is refused, nothing is saved.
	 */
	public function test_pattern_with_html_block_is_refused() {
		$this->admin();
		$this->with_library();
		$this->markups[4311] = "<!-- wp:html -->\n<script>alert(1)</script>\n<!-- /wp:html -->";
		$result              = $this->patterns()::insert_ready_pattern(
			array(
				'pattern_id' => 4311,
				'target'     => array( 'new_draft' => 'Bad' ),
			)
		);
		$this->without_library();

		$this->assertWPError( $result );
		$this->assertSame( 'pattern_not_allowed', $result->get_error_code() );
		$this->assertSame( array( 'core/html' ), $result->get_error_data()['blocks'] );
	}

	/**
	 * BL11: a pattern that uses a block not active here is refused with the missing names.
	 */
	public function test_pattern_with_inactive_block_is_refused() {
		$this->admin();
		$this->with_library();
		$this->markups[4311] = '<!-- wp:sp-testimonial-pro/record-video {"uniqueId":"sp-real-demo0rec0001"} /-->';
		$result              = $this->patterns()::insert_ready_pattern(
			array(
				'pattern_id' => 4311,
				'target'     => array( 'new_draft' => 'Missing' ),
			)
		);
		$this->without_library();

		$this->assertWPError( $result );
		$this->assertSame( 'block_unavailable', $result->get_error_code() );
		$this->assertSame( array( 'sp-testimonial-pro/record-video' ), $result->get_error_data()['missing'] );
	}

	/**
	 * BL6: for a user without unfiltered_html, markup kses would change is refused, not quietly saved.
	 */
	public function test_markup_kses_would_change_is_refused_without_unfiltered_html() {
		$this->admin();
		$this->with_library();
		$this->markups[4311] = "<!-- wp:paragraph -->\n<p onclick=\"steal()\">Hi</p>\n<!-- /wp:paragraph -->";
		add_filter( 'map_meta_cap', array( $this, 'no_unfiltered_html' ), 10, 2 );
		$result = $this->patterns()::insert_ready_pattern(
			array(
				'pattern_id' => 4311,
				'target'     => array( 'new_draft' => 'Kses' ),
			)
		);
		remove_filter( 'map_meta_cap', array( $this, 'no_unfiltered_html' ), 10 );
		$this->without_library();

		$this->assertWPError( $result );
		$this->assertSame( 'pattern_content_filtered', $result->get_error_code() );
	}

	/**
	 * Removes unfiltered_html, as on a multisite network or a hardened site.
	 *
	 * @param array  $caps Primitive caps.
	 * @param string $cap  Meta cap.
	 * @return array
	 */
	public function no_unfiltered_html( $caps, $cap ) {
		return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
	}

	/**
	 * BL7: with no cache file the list is fetched once, quickly, and cached; an invalid answer is
	 * never cached and gives patterns_not_loaded.
	 */
	public function test_missing_cache_is_fetched_once_and_bad_answers_are_not_cached() {
		$this->admin();
		$this->with_library();
		wp_delete_file( $this->library()::cache_path() );

		$this->list_body = '<html>502 Bad Gateway</html>';
		$bad             = $this->patterns()::list_ready_patterns( array() );
		$cached_bad      = file_exists( $this->library()::cache_path() );

		$this->list_body = $this->list_json();
		$this->http      = array();
		$this->patterns()::list_ready_patterns( array() );
		$this->patterns()::list_ready_patterns( array() );
		$requests = $this->http;
		$cached   = file_exists( $this->library()::cache_path() );
		$this->without_library();

		$this->assertWPError( $bad );
		$this->assertSame( 'patterns_not_loaded', $bad->get_error_code() );
		$this->assertFalse( $cached_bad );
		$this->assertCount( 1, $requests );
		$this->assertSame( 5, $requests[0]['args']['timeout'] );
		$this->assertTrue( $cached );
	}
}
