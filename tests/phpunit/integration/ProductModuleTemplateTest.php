<?php
/**
 * The product-module template (`templates/product-module/`), loaded as it is and run in real WordPress.
 *
 * The template is what every next product copies (WP Carousel is next), and until Phase 8.0 nothing tested it:
 * it drifted behind Real Testimonials (no toolsets, no audit telemetry, a wrong text domain) and the next
 * product would have copied every one of those. These tests load it under its placeholder names
 * (`template-slug`, `template_prefix`) and pin the behaviour a product must be able to rely on. They need no
 * sibling plugin, so they run in CI. The same template also runs on the wire, through real adapter copies, in the
 * `adapter-template` suite.
 *
 * Risk covered (TESTING.md): a tool served when its toolset is off (rule 1), a tool left out of the toolset map and
 * so always on (rule 5), tools that never reach the audit log (rule 5), an error that leaks (rule 1).
 *
 * @package ShapedKit
 */

use ShapedPlugin\Template\Mcp\Abilities_Registrar;
use ShapedPlugin\Template\Mcp\Builder\BlockInserter;
use ShapedPlugin\Template\Mcp\Kit_Registration;
use ShapedPlugin\Template\Mcp\Permission_Gate;
use ShapedPlugin\Template\Mcp\Rest_Controller;
use ShapedPlugin\Template\Mcp\Support\Token_Budget;
use ShapedPlugin\Template\Mcp\Support\Url;
use ShapedPlugin\Template\Mcp\Support\WriteGuard;

/**
 * Real WordPress: the template's classes, called directly.
 */
final class ProductModuleTemplateTest extends WP_UnitTestCase {

	/**
	 * The Builder's tools and prompt, by ability name.
	 */
	const BUILDER_TOOLS = array(
		'template-slug/get-block-catalog',
		'template-slug/validate-block-attributes',
		'template-slug/insert-block',
		'template-slug/list-ready-patterns',
		'template-slug/insert-ready-pattern',
	);

	/**
	 * Let the template's classes load from its folder, once.
	 */
	public static function wpSetUpBeforeClass() {
		static $registered = false;

		if ( $registered ) {
			return;
		}

		$registered = true;
		$prefix     = 'ShapedPlugin\\Template\\Mcp\\';
		$root       = dirname( __DIR__, 3 ) . '/templates/product-module/';

		spl_autoload_register(
			static function ( $class_name ) use ( $prefix, $root ) {
				if ( 0 !== strpos( $class_name, $prefix ) ) {
					return;
				}

				$file = $root . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}

	/**
	 * The error log setting before a test silenced it.
	 *
	 * @var string|false
	 */
	private $error_log;

	/**
	 * An administrator, nobody else listening to the audit hook, and a fresh site's options.
	 */
	public function set_up(): void {
		parent::set_up();

		// The wrapper writes a line to the PHP error log when a tool throws or prints, on purpose. Keep it out of the run.
		$this->error_log = ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// The Kit's own audit listener would write rows for the test's calls. The test's own listener replaces it.
		remove_all_actions( 'shaped_kit/mcp_tool_called' );
		delete_option( Permission_Gate::TOOLSETS_OPTION );
		delete_option( Permission_Gate::KILL_SWITCH_OPTION );
	}

	/**
	 * Leave no option or hook behind.
	 */
	public function tear_down(): void {
		ini_set( 'error_log', (string) $this->error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		delete_option( Permission_Gate::TOOLSETS_OPTION );
		delete_option( Permission_Gate::KILL_SWITCH_OPTION );
		remove_all_filters( 'template_prefix/mcp_ability_names' );
		remove_all_filters( 'template_prefix/mcp_toolset_map' );
		remove_all_actions( 'shaped_kit/mcp_tool_called' );

		parent::tear_down();
	}

	public function test_a_fresh_site_serves_the_core_tools_and_none_of_the_opt_in_ones(): void {
		$this->assertSame( array( 'template-slug/get-context', 'template-slug/find-media' ), Abilities_Registrar::names(), 'find-media is read-only, so it is core; import-image is opt-in.' );
		$this->assertSame( array(), Abilities_Registrar::prompt_names(), 'The Builder prompt follows the Builder toolset.' );
		$this->assertSame( array( 'core', 'management' ), Permission_Gate::get_active_toolsets() );
	}

	public function test_switching_the_builder_toolset_on_serves_its_tools_and_prompt(): void {
		Permission_Gate::set_active_toolsets( array( 'builder' ) );

		$names = Abilities_Registrar::names();

		foreach ( self::BUILDER_TOOLS as $tool ) {
			$this->assertContains( $tool, $names, "{$tool} is served once the Builder toolset is on." );
		}
		$this->assertContains( 'template-slug/get-context', $names, 'Core stays on.' );
		$this->assertSame( array( 'template-slug/build-block' ), Abilities_Registrar::prompt_names() );
	}

	public function test_core_cannot_be_switched_off(): void {
		Permission_Gate::set_active_toolsets( array() );

		$this->assertContains( 'core', Permission_Gate::get_active_toolsets() );
		$this->assertContains( 'template-slug/get-context', Abilities_Registrar::names() );

		// A saved list that leaves core out (an older save, a hand edit) still serves it.
		update_option( Permission_Gate::TOOLSETS_OPTION, array( 'builder' ) );
		$this->assertContains( 'core', Permission_Gate::get_active_toolsets() );
	}

	public function test_every_tool_has_a_toolset_in_the_map_so_none_is_on_by_accident(): void {
		$map     = Abilities_Registrar::ability_toolset_map();
		$defined = array_merge( array_keys( Abilities_Registrar::definitions() ), array_keys( Abilities_Registrar::prompt_definitions() ) );

		foreach ( $defined as $name ) {
			$this->assertArrayHasKey( $name, $map, "{$name} has no toolset: an unmapped tool counts as core, which is always on." );
		}

		// And every toolset the map names is one the dashboard can list and the owner can switch.
		Permission_Gate::set_active_toolsets( array( 'management', 'destructive', 'builder', 'bulk' ) );
		$listed = wp_list_pluck( Abilities_Registrar::toolsets(), 'id' );
		foreach ( array_unique( array_values( $map ) ) as $toolset ) {
			$this->assertContains( $toolset, $listed, "The map names the toolset '{$toolset}', which the dashboard would never list." );
		}
	}

	public function test_a_tool_a_pro_edition_adds_follows_the_toolset_it_names(): void {
		add_filter(
			'template_prefix/mcp_ability_names',
			static function ( $names ) {
				$names[] = 'template-slug/pro-delete';
				$names[] = 'another-plugin/steal';

				return $names;
			}
		);
		add_filter(
			'template_prefix/mcp_toolset_map',
			static function ( $map ) {
				$map['template-slug/pro-delete'] = 'destructive';

				return $map;
			}
		);

		$this->assertNotContains( 'template-slug/pro-delete', Abilities_Registrar::names(), 'A destructive tool is off until the owner opts in.' );
		$this->assertNotContains( 'another-plugin/steal', Abilities_Registrar::names(), 'Only this module\'s own names are ever served (E12).' );

		Permission_Gate::set_active_toolsets( array( 'destructive' ) );
		$this->assertContains( 'template-slug/pro-delete', Abilities_Registrar::names() );
		$this->assertNotContains( 'another-plugin/steal', Abilities_Registrar::names() );
	}

	public function test_the_dashboard_lists_only_toolsets_that_have_tools_and_counts_them(): void {
		$default = Abilities_Registrar::toolsets();

		$this->assertSame( array( 'core', 'builder', 'media' ), wp_list_pluck( $default, 'id' ), 'No deletion or bulk tool, so no switch for one.' );
		$this->assertSame( array( 2, 5, 1 ), wp_list_pluck( $default, 'tools_count' ) );
		$this->assertSame( array( true, false, false ), wp_list_pluck( $default, 'enabled' ), 'Core is on, the Builder and Media Import are off.' );
		$this->assertTrue( $default[0]['locked'] );
		$this->assertFalse( $default[1]['locked'] );
		$this->assertFalse( $default[2]['locked'] );

		Permission_Gate::set_active_toolsets( array( 'builder' ) );
		$this->assertSame( array( true, true, false ), wp_list_pluck( Abilities_Registrar::toolsets(), 'enabled' ) );
	}

	public function test_the_kit_card_counts_the_tools_that_are_served(): void {
		$count = static function () {
			return Kit_Registration::add_product( array() )[0]['tools_count'];
		};

		$this->assertSame( 2, $count() );

		Permission_Gate::set_active_toolsets( array( 'builder' ) );
		$this->assertSame( 7, $count() );

		Permission_Gate::set_active_toolsets( array( 'builder', 'media' ) );
		$this->assertSame( 8, $count() );
	}

	/**
	 * Run a tool through the registrar's wrapper and return what the audit hook heard.
	 *
	 * @param callable $callback The tool.
	 * @param mixed    $params   The arguments.
	 * @return array{result: mixed, events: array}
	 */
	private function call_wrapped( callable $callback, $params = array() ): array {
		$events = array();
		add_action(
			'shaped_kit/mcp_tool_called',
			static function ( $payload ) use ( &$events ) {
				$events[] = $payload;
			}
		);

		$result = call_user_func( Abilities_Registrar::wrap( 'template-slug/test-tool', $callback ), $params );

		return array(
			'result' => $result,
			'events' => $events,
		);
	}

	public function test_every_tool_call_reaches_the_audit_log_with_the_product_and_the_ability(): void {
		$call = $this->call_wrapped(
			static function () {
				return array( 'ok' => true );
			},
			array(
				'post_id' => 42,
				'email'   => 'reviewer@example.com',
			)
		);

		$this->assertSame( array( 'ok' => true ), $call['result'] );
		$this->assertCount( 1, $call['events'] );
		$this->assertSame( 'template-slug', $call['events'][0]['product'] );
		$this->assertSame( 'template-slug/test-tool', $call['events'][0]['ability'] );
		$this->assertSame( 'ok', $call['events'][0]['outcome'] );
		$this->assertSame(
			array(
				'post_id' => null,
				'email'   => null,
			),
			$call['events'][0]['arguments'],
			'Any plugin can listen to the audit action, so it carries the argument keys only, never a value.'
		);
	}

	/**
	 * What a tool returned, and the audit outcome it must be recorded as.
	 *
	 * @return array<string, array{0: mixed, 1: array, 2: string}>
	 */
	public function outcomes(): array {
		return array(
			'a plain answer'                       => array( array( 'ok' => true ), array(), 'ok' ),
			'a refusal'                            => array( new WP_Error( 'forbidden', 'No.' ), array(), 'denied' ),
			'an unauthorised caller'               => array( new WP_Error( 'unauthorized', 'No.' ), array(), 'denied' ),
			'another error'                        => array( new WP_Error( 'not_found', 'No.' ), array(), 'error' ),
			'a confirmation asked for (an error)'  => array( new WP_Error( 'confirmation_required', 'Confirm.' ), array(), 'confirmation_required' ),
			'a confirmation asked for (an answer)' => array( array( 'status' => 'confirmation_required' ), array(), 'confirmation_required' ),
			'a confirmed write'                    => array( array( 'ok' => true ), array( 'confirmation_token' => 'abc' ), 'confirmed' ),
		);
	}

	/**
	 * The audit outcome follows what the tool returned.
	 *
	 * @dataProvider outcomes
	 *
	 * @param mixed  $returned What the tool returns.
	 * @param array  $params   What it was called with.
	 * @param string $expected The outcome the audit log must hold.
	 */
	public function test_the_audit_outcome_follows_what_the_tool_returned( $returned, array $params, string $expected ): void {
		$call = $this->call_wrapped(
			static function () use ( $returned ) {
				return $returned;
			},
			$params
		);

		$this->assertSame( $expected, $call['events'][0]['outcome'] );
	}

	public function test_a_tool_that_throws_gives_the_client_a_generic_error_and_the_audit_log_an_error(): void {
		$call = $this->call_wrapped(
			static function () {
				throw new RuntimeException( 'SQLSTATE secret table wp_users at /var/www/private.php' );
			}
		);

		$this->assertWPError( $call['result'] );
		$this->assertSame( 'failed', $call['result']->get_error_code() );
		$this->assertStringNotContainsString( 'SQLSTATE', $call['result']->get_error_message(), 'Nothing about the server crosses the wire (E69).' );
		$this->assertStringNotContainsString( 'private.php', wp_json_encode( $call['result']->get_error_data() ) );
		$this->assertSame( 'error', $call['events'][0]['outcome'] );
		$this->assertSame( $call['result']->get_error_data()['error_id'], $call['events'][0]['error_id'], 'The client quotes the id and finds the entry.' );
	}

	public function test_stray_output_from_a_tool_never_reaches_the_response(): void {
		$level = ob_get_level();
		$call  = $this->call_wrapped(
			static function () {
				echo 'Notice: something printed';

				return array( 'ok' => true );
			}
		);

		$this->assertSame( $level, ob_get_level(), 'The wrapper leaves the output buffers as it found them.' );
		$this->assertSame( array( 'ok' => true ), $call['result'] );
	}

	public function test_the_settings_route_refuses_anyone_but_an_administrator(): void {
		Rest_Controller::register();
		$GLOBALS['wp_rest_server'] = null;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$request = new WP_REST_Request( 'POST', '/template/v1/mcp' );
		$request->set_param( 'enabled', true );
		$request->set_param( 'toolsets', array( 'builder' ) );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$this->assertSame( array( 'core', 'management' ), Permission_Gate::get_active_toolsets(), 'Nothing was saved.' );
	}

	public function test_the_settings_route_saves_the_toolsets_and_reports_them(): void {
		$request = new WP_REST_Request( 'POST', '/template/v1/mcp' );
		$request->set_param( 'enabled', true );
		$request->set_param( 'toolsets', array( 'builder' ) );

		$response = Rest_Controller::set_status( $request );
		$data     = $response->get_data();

		$this->assertContains( 'builder', Permission_Gate::get_active_toolsets() );
		$this->assertSame( array( true, true, false ), wp_list_pluck( $data['toolsets'], 'enabled' ) );
		$this->assertSame( 7, $data['tools_count'] );
		$this->assertTrue( $data['enabled'] );
	}

	public function test_the_build_with_ai_helper_follows_the_toolset_and_the_kit(): void {
		$without_kit = Rest_Controller::builder_client( false );
		$with_kit    = Rest_Controller::builder_client( true );

		$this->assertFalse( $without_kit['builder'] );
		$this->assertFalse( $without_kit['enabled'] );
		$this->assertSame( '', $without_kit['connectUrl'], 'No Kit, no connect panel to link to.' );
		$this->assertStringContainsString( 'page=shaped-kit&connect=template-slug', $with_kit['connectUrl'] );
		$this->assertStringEndsWith( Rest_Controller::SETTINGS_PATH, $without_kit['settingsUrl'] );

		Permission_Gate::set_enabled( true );
		Permission_Gate::set_active_toolsets( array( 'builder' ) );
		$on = Rest_Controller::builder_client( false );
		$this->assertTrue( $on['builder'] );
		$this->assertTrue( $on['enabled'] );
	}

	/**
	 * What an AI client might send as an address.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function addresses(): array {
		return array(
			'an https address'          => array( 'https://example.com/a?b=1', 'https://example.com/a?b=1' ),
			'an http address'           => array( 'http://example.com', 'http://example.com' ),
			'spaces around it'          => array( '  https://example.com/x  ', 'https://example.com/x' ),
			'plain text, which esc_url_raw would turn into http://not a url' => array( 'not a url', '' ),
			'a word'                    => array( 'example.com', '' ),
			'a script address'          => array( 'javascript:alert(1)', '' ),
			'a mail address'            => array( 'mailto:a@example.com', '' ),
			'another scheme'            => array( 'ftp://example.com/file', '' ),
			'a scheme and nothing else' => array( 'http://', '' ),
			'a space inside'            => array( 'http://example.com/a b', '' ),
			'not text'                  => array( array( 'https://example.com' ), '' ),
			'nothing'                   => array( null, '' ),
		);
	}

	/**
	 * An address is kept only when it is a full http or https one.
	 *
	 * @dataProvider addresses
	 *
	 * @param mixed  $value    Candidate.
	 * @param string $expected What is kept.
	 */
	public function test_an_address_is_kept_only_when_it_is_a_full_http_address( $value, string $expected ): void {
		$this->assertSame( $expected, Url::clean( $value ) );
	}

	public function test_the_token_budget_counts_name_description_and_schema_of_the_toolsets_asked_for(): void {
		$definition = array(
			'description'  => 'abcd',
			'input_schema' => array( 'type' => 'object' ),
		);
		$schema     = wp_json_encode( array( 'type' => 'object' ) );

		$this->assertSame( strlen( 'name' ) + 4 + strlen( $schema ), Token_Budget::size( 'name', $definition ) );

		$definitions = array(
			'a/one'   => $definition,
			'a/two'   => $definition,
			'a/three' => $definition,
		);
		$map         = array(
			'a/one' => 'core',
			'a/two' => 'bulk',
		);
		$one         = Token_Budget::size( 'a/one', $definition );

		// 'a/three' is not in the map, so it counts as core, as the registrar treats it.
		$this->assertSame( 2 * $one + 2, Token_Budget::total( $definitions, $map, array( 'core' ) ) - 0 );
		$this->assertSame( 3 * $one + 2, Token_Budget::total( $definitions, $map, array( 'core', 'bulk' ) ) - 0 );
		$this->assertSame( 100, Token_Budget::tokens( 400 ) );
	}

	public function test_what_is_on_by_default_stays_small_and_the_builder_is_not_part_of_it(): void {
		$definitions = Abilities_Registrar::definitions();
		$map         = Abilities_Registrar::ability_toolset_map();

		$default = Token_Budget::total( $definitions, $map, array( 'core', 'management' ) );
		$builder = Token_Budget::total( $definitions, $map, array( 'builder' ) );
		$media   = Token_Budget::total( $definitions, $map, array( 'media' ) );

		// A product pins its own ceiling (Real Testimonials: 5,200 characters for core and management). The template
		// has two read tools on by default (get-context 156, find-media 479 characters on 2026-10-21); the Builder,
		// the larger part, and import-image, which reaches the internet, are opt-in.
		$this->assertLessThanOrEqual( 700, $default, 'The default-on tools of the template cost a few hundred characters.' );
		$this->assertLessThanOrEqual( 800, $media, 'import-image stays small even when it is on.' );
		$this->assertGreaterThan( 5 * $default, $builder, 'The Builder is the large part, which is why it is opt-in.' );
	}

	/**
	 * Replacing a block keeps its full namespaced name and its uniqueId.
	 *
	 * The template once wrote the bare slug (`carousel`) as the block name, so the saved block became
	 * `<!-- wp:carousel -->`, a block nothing renders, and the saved result lost its uniqueId. WP Carousel
	 * copied it as is.
	 */
	public function test_replacing_a_block_keeps_its_namespaced_name_and_unique_id(): void {
		$taken = array();
		$top   = array(
			'blockName'    => 'template-slug/carousel',
			'attrs'        => array(
				'uniqueId' => 'kept-id',
				'layout'   => 'default',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		$updated = BlockInserter::build_update( $top, array( 'layout' => 'wide' ), $taken );

		$this->assertSame( 'template-slug/carousel', $updated['blockName'] );
		$this->assertSame( 'kept-id', $updated['attrs']['uniqueId'] );
		$this->assertSame( 'wide', $updated['attrs']['layout'] );
		$this->assertSame( array( $updated, 'carousel' ), BlockInserter::main_of( $updated ) );
	}

	public function test_replacing_a_block_inside_a_wrapper_updates_the_inner_block_in_place(): void {
		$taken = array();
		$inner = array(
			'blockName'    => 'template-slug/grid',
			'attrs'        => array( 'uniqueId' => 'inner-id' ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		$top   = array(
			'blockName'    => 'core/group',
			'attrs'        => array(),
			'innerBlocks'  => array( $inner ),
			'innerHTML'    => '<div></div>',
			'innerContent' => array( '<div>', null, '</div>' ),
		);

		$updated = BlockInserter::build_update( $top, array( 'layout' => 'wide' ), $taken );

		$this->assertSame( 'core/group', $updated['blockName'] );
		$this->assertCount( 1, $updated['innerBlocks'] );
		$this->assertSame( 'template-slug/grid', $updated['innerBlocks'][0]['blockName'] );
		$this->assertSame( 'inner-id', $updated['innerBlocks'][0]['attrs']['uniqueId'] );
		$this->assertSame( 'wide', $updated['innerBlocks'][0]['attrs']['layout'] );
	}

	public function test_a_block_index_counts_only_real_blocks_not_the_blank_lines_between_them(): void {
		$blocks = parse_blocks( "<!-- wp:paragraph -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:template-slug/carousel {\"uniqueId\":\"x\"} /-->" );
		$new    = array(
			'blockName'    => 'template-slug/carousel',
			'attrs'        => array( 'uniqueId' => 'y' ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		$this->assertSame( 'template-slug/carousel', BlockInserter::real_block( $blocks, 1 )['blockName'] );
		$this->assertNull( BlockInserter::real_block( $blocks, 2 ) );
		$this->assertNull( BlockInserter::real_block( $blocks, '1' ) );

		$replaced = BlockInserter::replace_at( $blocks, 1, $new );
		$this->assertCount( count( $blocks ), $replaced );
		$this->assertSame( 'y', BlockInserter::real_block( $replaced, 1 )['attrs']['uniqueId'] );
		$this->assertNull( BlockInserter::real_block( $replaced, 2 ), 'The old block is replaced, not pushed down.' );
		$this->assertSame( "\n\n", $replaced[1]['innerHTML'], 'The blank line between the blocks stays.' );
		$this->assertSame( $blocks, BlockInserter::replace_at( $blocks, 5, $new ), 'An index past the last real block changes nothing.' );
	}

	public function test_the_template_holds_only_placeholders_so_the_adoption_checklist_replaces_everything(): void {
		$root  = dirname( __DIR__, 3 ) . '/templates/product-module';
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

		$checked = 0;
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			++$checked;
			$name = substr( $file->getPathname(), strlen( $root ) + 1 );
			$code = (string) file_get_contents( $file->getPathname() );

			// Every translatable string uses the one placeholder domain: the checklist replaces it in one step.
			preg_match_all( '/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*\'(?:[^\'\\\\]|\\\\.)*\'\s*,\s*\'([^\']+)\'\s*\)/', $code, $domains );
			foreach ( array_unique( $domains[1] ) as $domain ) {
				$this->assertSame( 'template-domain', $domain, "{$name} uses the text domain '{$domain}', which the checklist does not replace." );
			}

			// No name of a real product, and no Kit-internal key prefix the checklist would miss.
			$this->assertSame( 0, preg_match( '/testimonial|weather|spt_|splw|wpcp|\bsk_mcp/i', $code ), "{$name} names a real product or a Kit-internal prefix." );
		}

		$this->assertGreaterThan( 15, $checked, 'The scan found the template.' );
		$this->assertStringStartsWith( 'template_prefix_mcp_cf_', WriteGuard::confirm_key( 1, 'a', 'b' ) );
		$this->assertStringStartsWith( 'template_prefix_mcp_id_', WriteGuard::idem_key( 1, 'a', 'b', 'c' ) );
	}
}
