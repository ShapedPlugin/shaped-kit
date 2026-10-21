<?php
/**
 * Integration tests for WP Carousel read tools and MCP plumbing (Phase 8.1).
 *
 * Verifies parity across both Free and Pro editions:
 * - Module registration and Ability Registrar definitions
 * - get-context: identity, environment, post counts, callable tools, and PII-safe license status (B12, E79)
 * - list-carousels & get-carousel: pagination, shortcode, layout options, 404 handling
 * - list-templates & get-template: saved templates, content, shortcode
 * - find-carousel-blocks: Gutenberg block and shortcode parsing across posts
 * - get-settings: safe allowlist, masked license key, no PII leakage
 * - Permission_Gate: off by default (FR-S1), toggle handling
 * - Rest_Controller: summary payload and connection info
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Integration;

use WP_UnitTestCase;

/**
 * Class WpCarouselReadToolsIntegrationTest
 */
class WpCarouselReadToolsIntegrationTest extends WP_UnitTestCase {

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

		if ( ! class_exists( $module_class ) ) {
			$this->markTestSkipped( "{$plugin} is not available next to the Kit." );
		}

		$this->register_post_types();
	}

	/**
	 * Registers the custom post types if core test runner hasn't registered them.
	 */
	private function register_post_types() {
		if ( ! post_type_exists( 'sp_wp_carousel' ) ) {
			register_post_type(
				'sp_wp_carousel',
				array(
					'public'       => false,
					'show_ui'      => true,
					'supports'     => array( 'title' ),
					'capabilities' => array(
						'read_post' => 'edit_posts',
					),
				)
			);
		}

		if ( ! post_type_exists( 'sp_wpcp_template' ) ) {
			register_post_type(
				'sp_wpcp_template',
				array(
					'public'       => false,
					'show_ui'      => true,
					'supports'     => array( 'title', 'editor' ),
					'capabilities' => array(
						'read_post' => 'edit_posts',
					),
				)
			);
		}
	}

	/**
	 * Set up test admin user before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$admin_id = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'user_login'   => 'testadmin',
				'display_name' => 'Test Admin',
				'user_email'   => 'secret-admin@example.com',
			)
		);
		wp_set_current_user( $admin_id );
	}

	/**
	 * Clean up user and options after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wpcp_mcp_enabled' );
		delete_option( 'wpcp_mcp_toolsets' );
		delete_option( 'sp_wpcp_settings' );
		delete_option( 'wpcp_modules_options' );
		delete_option( 'wp_carousel_pro_license_key_status' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that all 7 core read abilities are defined and properly annotated.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_abilities_registrar_definitions( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		$registrar = $ns . '\\Abilities_Registrar';
		$defs      = $registrar::core_definitions();

		$expected_tools = array(
			'wp-carousel/get-context',
			'wp-carousel/list-carousels',
			'wp-carousel/get-carousel',
			'wp-carousel/list-templates',
			'wp-carousel/get-template',
			'wp-carousel/find-carousel-blocks',
			'wp-carousel/get-settings',
		);

		foreach ( $expected_tools as $tool_name ) {
			$this->assertArrayHasKey( $tool_name, $defs, "Core tool {$tool_name} must be defined." );
			$def = $defs[ $tool_name ];
			$this->assertNotEmpty( $def['label'] );
			$this->assertNotEmpty( $def['description'] );
			$this->assertTrue( $def['annotations']['readonly'], "Tool {$tool_name} must be marked readonly." );
			$this->assertFalse( $def['annotations']['destructive'], "Tool {$tool_name} must not be destructive." );
		}

		$map = $registrar::core_toolset_map();
		foreach ( $expected_tools as $tool_name ) {
			$this->assertSame( 'core', $map[ $tool_name ] ?? null, "Tool {$tool_name} must belong to 'core' toolset." );
		}
	}

	/**
	 * Test get-context tool: safe identity, environment, and PII-safe license status.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_get_context( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		// Seed a PII-rich license option to ensure B12 guardrail strictly filters it.
		$fake_license_status = (object) array(
			'license'        => 'valid',
			'expires'        => '2027-01-01 00:00:00',
			'customer_name'  => 'John Doe',
			'customer_email' => 'victim@example.com',
			'payment_id'     => '99999',
		);
		update_option( 'wp_carousel_pro_license_key_status', $fake_license_status );

		$tools_class = $ns . '\\Tools\\Context_Tools';
		$context     = $tools_class::get_context();

		$this->assertIsArray( $context );
		$this->assertArrayHasKey( 'you', $context );
		$this->assertArrayHasKey( 'site', $context );
		$this->assertArrayHasKey( 'product', $context );
		$this->assertArrayHasKey( 'overview', $context );
		$this->assertArrayHasKey( 'license', $context );
		$this->assertArrayHasKey( 'tools', $context );

		// E79 Guardrail: Email must NEVER be exposed in you.
		$this->assertArrayNotHasKey( 'user_email', $context['you'], 'Email address must never be in context.' );
		$this->assertArrayNotHasKey( 'email', $context['you'], 'Email address must never be in context.' );
		$this->assertSame( 'Test Admin', $context['you']['name'] );
		$this->assertTrue( $context['you']['is_admin'] );

		// B12 Guardrail: License status must strictly extract status and expiry; never PII.
		$this->assertSame( 'valid', $context['license']['status'] );
		$this->assertSame( '2027-01-01 00:00:00', $context['license']['expires'] );
		$this->assertArrayNotHasKey( 'customer_name', $context['license'] );
		$this->assertArrayNotHasKey( 'customer_email', $context['license'] );
		$this->assertArrayNotHasKey( 'payment_id', $context['license'] );

		// Overview totals.
		$this->assertIsArray( $context['overview']['carousels'] );
		$this->assertIsArray( $context['overview']['templates'] );
		$this->assertIsInt( $context['overview']['carousels']['total'] );
		$this->assertIsInt( $context['overview']['templates']['total'] );
	}

	/**
	 * Test list-carousels and get-carousel tools.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_carousel_tools( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		$carousel_tools = $ns . '\\Tools\\Carousel_Tools';

		// Empty list check.
		$empty = $carousel_tools::list_carousels( array( 'per_page' => 10 ) );
		$this->assertSame( 0, $empty['pagination']['total'] );
		$this->assertSame( array(), $empty['items'] );

		// Create a test carousel.
		$carousel_id = self::factory()->post->create(
			array(
				'post_type'   => 'sp_wp_carousel',
				'post_title'  => 'Hero Slider',
				'post_status' => 'publish',
			)
		);
		update_post_meta(
			$carousel_id,
			'sp_wpcp_upload_options',
			array(
				'wpcp_carousel_type' => 'image-carousel',
				'wpcp_gallery'       => array( 10, 20 ),
			)
		);
		update_post_meta(
			$carousel_id,
			'sp_wpcp_shortcode_options',
			array(
				'wpcp_layout'   => 'carousel',
				'wpcp_autoplay' => true,
			)
		);

		// List should contain the carousel.
		$list = $carousel_tools::list_carousels( array( 'per_page' => 10 ) );
		$this->assertSame( 1, $list['pagination']['total'] );
		$this->assertCount( 1, $list['items'] );
		$this->assertSame( $carousel_id, $list['items'][0]['id'] );
		$this->assertSame( 'Hero Slider', $list['items'][0]['title'] );
		$this->assertSame( 'image-carousel', $list['items'][0]['type'] );
		$this->assertSame( sprintf( '[sp_wpcarousel id="%d"]', $carousel_id ), $list['items'][0]['shortcode'] );

		// Get carousel details.
		$carousel = $carousel_tools::get_carousel( array( 'id' => $carousel_id ) );
		$this->assertIsArray( $carousel );
		$this->assertSame( $carousel_id, $carousel['id'] );
		$this->assertSame( 'image-carousel', $carousel['type'] );
		$this->assertTrue( $carousel['shortcode_options']['wpcp_autoplay'] );
		$this->assertSame( array( 10, 20 ), $carousel['upload_options']['wpcp_gallery'] );

		// Invalid ID check.
		$missing = $carousel_tools::get_carousel( array( 'id' => 999999 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'carousel_not_found', $missing->get_error_code() );

		// ID 0 / invalid format check.
		$bad_id = $carousel_tools::get_carousel( array( 'id' => 0 ) );
		$this->assertWPError( $bad_id );
		$this->assertSame( 'invalid_id', $bad_id->get_error_code() );
	}

	/**
	 * Test list-templates and get-template tools.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_template_tools( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		$carousel_tools = $ns . '\\Tools\\Carousel_Tools';

		// Create a saved template post.
		$template_id = self::factory()->post->create(
			array(
				'post_type'    => 'sp_wpcp_template',
				'post_title'   => 'Product Grid Template',
				'post_status'  => 'publish',
				// The void form, as core serializes an empty block: on multisite an admin lacks unfiltered_html,
				// so kses re-serializes the content (filter_block_content()) and an open/close pair would change.
				'post_content' => '<!-- wp:wp-carousel-pro/carousel {"columns":4} /-->',
			)
		);

		$list = $carousel_tools::list_templates( array( 'per_page' => 10 ) );
		$this->assertSame( 1, $list['pagination']['total'] );
		$this->assertSame( $template_id, $list['items'][0]['id'] );
		$this->assertSame( sprintf( '[sp_wpcp_template id="%d"]', $template_id ), $list['items'][0]['shortcode'] );

		$template = $carousel_tools::get_template( array( 'id' => $template_id ) );
		$this->assertIsArray( $template );
		$this->assertSame( $template_id, $template['id'] );
		$this->assertSame( '<!-- wp:wp-carousel-pro/carousel {"columns":4} /-->', $template['content'] );
		$this->assertSame( get_post( $template_id )->post_content, $template['content'] );

		// Missing template check.
		$missing = $carousel_tools::get_template( array( 'id' => 888888 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'template_not_found', $missing->get_error_code() );
	}

	/**
	 * Test Block_Tools: analyze and find_blocks for block markup and shortcodes.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_block_tools( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		$block_tools = $ns . '\\Tools\\Block_Tools';

		$sample_content = '
			<p>Intro text</p>
			<!-- wp:wp-carousel-pro/carousel {"autoplay":true} -->
			<div class="wpcp-wrapper">Slide</div>
			<!-- /wp:wp-carousel-pro/carousel -->
			[sp_wpcarousel id="45"]
			[sp_wpcp_template id="99"]
		';

		$analysis = $block_tools::analyze( $sample_content );
		$this->assertSame( 1, $analysis['blocks']['wp-carousel-pro/carousel'] ?? 0 );
		$this->assertSame( array( 45 ), $analysis['carousel_ids'] );
		$this->assertSame( array( 99 ), $analysis['template_ids'] );

		// Test finding in posts.
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Carousel Landing Page',
				'post_status'  => 'publish',
				'post_content' => $sample_content,
			)
		);

		$found = $block_tools::find_blocks( array( 'carousel_id' => 45 ) );
		$this->assertGreaterThanOrEqual( 1, $found['pagination']['total'] );
		$matched = false;
		foreach ( $found['items'] as $item ) {
			if ( $item['id'] === $page_id ) {
				$matched = true;
				$this->assertContains( 45, $item['carousel_ids'] );
				$this->assertContains( 99, $item['template_ids'] );
			}
		}
		$this->assertTrue( $matched, 'find_blocks must find the page with carousel ID 45.' );
	}

	/**
	 * Test get-settings tool: safe settings allowlist, secret masking, and no PII leak.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_get_settings( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		update_option(
			'sp_wpcp_settings',
			array(
				'enqueue_swiper_css' => true,
				'enqueue_swiper_js'  => false,
				'enqueue_fa_css'     => true,
				'license_key'        => 'abcd-1234-efgh-5678',
			)
		);

		update_option(
			'wpcp_modules_options',
			array(
				'ai-carousel-builder' => true,
				'lightbox'            => false,
			)
		);

		// PII leak trap: raw object with customer details.
		update_option(
			'wp_carousel_pro_license_key_status',
			(object) array(
				'license'        => 'valid',
				'expires'        => '2028-05-10',
				'customer_email' => 'leak@target.com',
				'customer_name'  => 'Target Customer',
			)
		);

		$settings_class = $ns . '\\Tools\\Settings_Tools';
		$settings       = $settings_class::get_settings();

		$this->assertIsArray( $settings );
		$this->assertTrue( $settings['general']['enqueue_swiper_css'] );
		$this->assertFalse( $settings['general']['enqueue_swiper_js'] );
		$this->assertTrue( $settings['general']['enqueue_fa_css'] );

		$this->assertTrue( $settings['modules']['ai_carousel_builder'] );
		$this->assertFalse( $settings['modules']['lightbox'] );

		// Secret masking guardrail.
		$this->assertStringNotContainsString( 'abcd-1234-efgh-5678', $settings['license']['key_masked'], 'Raw license key must never be exposed.' );
		$this->assertStringContainsString( '••••', $settings['license']['key_masked'] );

		// PII guardrail.
		$this->assertSame( 'valid', $settings['license']['status'] );
		$this->assertSame( '2028-05-10', $settings['license']['expires'] );
		$this->assertArrayNotHasKey( 'customer_email', $settings['license'] );
		$this->assertArrayNotHasKey( 'customer_name', $settings['license'] );
	}

	/**
	 * Test Permission_Gate: off by default (FR-S1), toggle handling, toolsets.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_permission_gate( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		$gate = $ns . '\\Permission_Gate';

		// FR-S1: Must default to OFF.
		delete_option( 'wpcp_mcp_enabled' );
		$this->assertFalse( $gate::is_enabled(), 'MCP must be disabled by default.' );

		// Set to true.
		$updated = $gate::set_enabled( true );
		$this->assertTrue( $updated );
		$this->assertTrue( $gate::is_enabled() );

		// Active toolsets: core must always be present.
		$active = $gate::get_active_toolsets();
		$this->assertContains( 'core', $active );

		// Filter override test.
		add_filter( 'wpcp/mcp_enabled', '__return_false' );
		$this->assertFalse( $gate::is_enabled() );
		remove_filter( 'wpcp/mcp_enabled', '__return_false' );
	}

	/**
	 * Test REST controller summary structure.
	 *
	 * @dataProvider editions
	 *
	 * @param string $plugin Plugin directory.
	 * @param string $ns     Namespace.
	 */
	public function test_rest_controller_summary( $plugin, $ns ) {
		$this->ensure_loaded( $plugin, $ns );

		$controller_class = $ns . '\\Rest_Controller';
		$summary          = $controller_class::summary();

		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'status', $summary );
		$this->assertArrayHasKey( 'enabled', $summary );
		$this->assertArrayHasKey( 'toolsets', $summary );
		$this->assertArrayHasKey( 'adapter', $summary );
		$this->assertArrayHasKey( 'connect', $summary );
		$this->assertArrayHasKey( 'kit', $summary );
		$this->assertSame( 'wp-carousel', $summary['slug'] );
	}
}
