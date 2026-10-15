<?php
/**
 * The Real Testimonials MCP prompt, on the wire, through one real adapter copy (AI builder PRD B7).
 *
 * The unit and integration tests prove the prompt's definition and callback. None of them asks an
 * adapter to serve it, and a prompt that is never served fails silently: the client simply has no
 * "build a testimonial block" command and nothing reports why (TESTING.md rule 5). This suite runs
 * `prompts/list` and `prompts/get` through a real adapter, over REST, as an MCP client would.
 *
 * What it pins:
 *
 *   - the prompt is served beside the Builder tools when the owner opted in, and never as a tool;
 *   - an adapter older than 0.5.0 (no prompts) still serves the tools and not the prompt;
 *   - the tool names the prompt text tells a client to call are the names the server serves;
 *   - an MCP client sends prompt arguments as strings, so a numeric string `post_id` must work;
 *   - a block outside the enum and a user without the product capability are refused;
 *   - with the Builder toolset off (the default of every site), neither prompt nor builder tool is served.
 *
 * It also carries the wire check for the bulk `create-testimonials` tool (E124), served only with the opt-in Bulk
 * Import toolset: its nested item schema
 * (arrays of objects, enums, a cap) must cross the adapter and core intact, or the tool is silently missing.
 *
 * Edition, adapter copy and toolset state come from the environment (see adapter-support.php);
 * `tests/run-adapter-matrix.sh` runs every combination. One body for Free and Pro (PRD §20 rule 6).
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Adapter;

use WP_UnitTestCase;

require_once __DIR__ . '/Mcp_Client.php';

/**
 * Class RealTestimonialsPromptsAdapterTest
 */
class RealTestimonialsPromptsAdapterTest extends WP_UnitTestCase {

	use Mcp_Client;

	/**
	 * The prompt's name as an MCP client sees it (the ability name with `/` turned into `-`).
	 */
	const PROMPT = 'real-testimonials-build-testimonial-block';

	/**
	 * The Builder tools as an MCP client sees them.
	 */
	const BUILDER_TOOLS = array(
		'real-testimonials-get-block-catalog',
		'real-testimonials-validate-block-attributes',
		'real-testimonials-insert-block',
		'real-testimonials-list-ready-patterns',
		'real-testimonials-insert-ready-pattern',
	);

	/**
	 * First adapter release that serves MCP prompts (`RegisterAbilityAsMcpPrompt`, since 0.5.0).
	 */
	const FIRST_ADAPTER_WITH_PROMPTS = '0.5.0';

	/**
	 * The edition's Permission_Gate class.
	 *
	 * @return string
	 */
	private static function gate() {
		return shaped_kit_adapter_edition()['namespace'] . '\\Mcp\\Permission_Gate';
	}

	/**
	 * Switch the module on and let the adapter build the server, once, before any test. The server
	 * reads the kill switch and the toolsets while it is built, so both are set first.
	 */
	public static function wpSetUpBeforeClass() {
		$gate = static::gate();
		if ( ! class_exists( $gate ) ) {
			return;
		}

		update_option( $gate::KILL_SWITCH_OPTION, 'yes' );
		$module = shaped_kit_adapter_edition()['namespace'] . '\\Mcp\\Module';
		$module::ensure_entry_post_type();
		// "on" switches the opt-in toolsets on (Builder and Bulk Import); "off" leaves what a fresh site serves.
		if ( shaped_kit_adapter_builder_on() ) {
			$gate::set_active_toolsets( array( 'core', 'management', 'builder', 'bulk' ) );
		}

		// Firing `rest_api_init` makes the adapter fire `mcp_adapter_init`, which creates the servers.
		rest_get_server();
	}

	/**
	 * Leave the test database as it was found.
	 */
	public static function wpTearDownAfterClass() {
		$gate = static::gate();
		if ( ! class_exists( $gate ) ) {
			return;
		}

		delete_option( $gate::KILL_SWITCH_OPTION );
		delete_option( $gate::TOOLSETS_OPTION );
	}

	/**
	 * Every test is an administrator talking to the endpoint, unless it says otherwise.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( static::gate() ) ) {
			$this->markTestSkipped( shaped_kit_adapter_edition()['plugin'] . ' is not available next to the Kit.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Skip a test that belongs to the other Builder state. The server is built once per process, so
	 * the matrix runs each state in its own process (see run-adapter-matrix.sh).
	 *
	 * @param bool $builder_on True for a test that needs the Builder toolset on.
	 */
	private function needs_builder( $builder_on ) {
		if ( shaped_kit_adapter_builder_on() !== $builder_on ) {
			$this->markTestSkipped( 'This test needs the Builder toolset ' . ( $builder_on ? 'on' : 'off' ) . '.' );
		}
	}

	/**
	 * Whether the loaded adapter copy can serve prompts at all.
	 *
	 * @return bool
	 */
	private function adapter_serves_prompts() {
		return version_compare( \WP\MCP\Core\McpAdapter::VERSION, self::FIRST_ADAPTER_WITH_PROMPTS, '>=' );
	}

	/**
	 * Whether a response is a refusal or an error, whichever way the adapter reports it.
	 *
	 * @param array $response A response from `mcp()`.
	 *
	 * @return bool
	 */
	private function refused( $response ) {
		return isset( $response['body']['error'] ) || isset( $response['body']['code'] ) || $response['status'] >= 400;
	}

	/**
	 * The prompt text of a `prompts/get` answer.
	 *
	 * @param array $response A response from `get_prompt()`.
	 *
	 * @return string
	 */
	private function prompt_text( $response ) {
		return (string) ( $response['body']['result']['messages'][0]['content']['text'] ?? '' );
	}

	/**
	 * The prompt is listed with the Builder tools it belongs to, and is not one of them.
	 *
	 * @group builder-on
	 */
	public function test_prompt_is_served_beside_the_builder_tools_and_never_as_a_tool() {
		$this->needs_builder( true );

		$headers = $this->open_session();
		$tools   = $this->listed_tools( $headers );
		$prompts = $this->listed_prompts( $headers );

		// The server itself is up on every copy, prompts or not.
		foreach ( self::BUILDER_TOOLS as $tool ) {
			$this->assertContains( $tool, $tools, "{$tool} must be served when the Builder toolset is on." );
		}
		$this->assertNotContains( self::PROMPT, $tools, 'A prompt is not a tool.' );

		if ( ! $this->adapter_serves_prompts() ) {
			$this->assertArrayNotHasKey( self::PROMPT, $prompts, 'An adapter without prompts cannot list one.' );
			return;
		}

		$this->assertArrayHasKey( self::PROMPT, $prompts, 'The adapter must serve the prompt.' );
		// An optional argument has no `required` key, so list the required names.
		$required = array();
		foreach ( $prompts[ self::PROMPT ]['arguments'] ?? array() as $argument ) {
			if ( ! empty( $argument['required'] ) ) {
				$required[] = $argument['name'];
			}
		}
		$this->assertSame( array( 'request' ), $required, 'The request is the one required argument.' );
	}

	/**
	 * Every tool the prompt text names is served, and the text names the whole builder flow.
	 *
	 * @group builder-on
	 */
	public function test_prompt_text_tells_a_client_to_call_only_tools_the_server_serves() {
		$this->needs_builder( true );

		$headers = $this->open_session();
		$answer  = $this->get_prompt( self::PROMPT, array( 'request' => 'A dark 3-column carousel' ), $headers );

		if ( ! $this->adapter_serves_prompts() ) {
			$this->assertTrue( $this->refused( $answer ), 'An adapter without prompts cannot serve one.' );
			return;
		}

		$text = $this->prompt_text( $answer );
		$this->assertNotSame( '', $text, 'The prompt must come back with a message.' );
		$this->assertStringContainsString( 'A dark 3-column carousel', $text );

		// Every `tool` the text names must be one this server lists, or the client's next call fails.
		// The pattern also takes a name with a wrong separator, so a typo is reported, not skipped.
		preg_match_all( '/`(real-testimonials[a-z0-9_-]+)`/', $text, $named );
		$named  = array_unique( $named[1] );
		$served = $this->listed_tools( $headers );
		foreach ( $named as $tool ) {
			$this->assertContains( $tool, $served, "The prompt tells the client to call {$tool}, which this server does not serve." );
		}
		// And the text must lead the client through the whole flow, not part of it.
		foreach ( self::BUILDER_TOOLS as $tool ) {
			$this->assertContains( $tool, $named, "The prompt must name {$tool}." );
		}
	}

	/**
	 * MCP prompt arguments are strings, so a post id arrives as "42" and must still work.
	 *
	 * @group builder-on
	 * @group needs-prompts
	 */
	public function test_a_numeric_string_post_id_is_accepted_because_clients_send_prompt_arguments_as_strings() {
		$this->needs_builder( true );
		if ( ! $this->adapter_serves_prompts() ) {
			$this->markTestSkipped( 'The loaded adapter copy has no prompts.' );
		}

		$headers = $this->open_session();

		$existing = $this->get_prompt(
			self::PROMPT,
			array(
				'request' => 'A grid',
				'post_id' => '42',
			),
			$headers
		);
		$this->assertFalse( $this->refused( $existing ), 'A string post id must reach the prompt.' );
		$this->assertStringContainsString( 'existing post ID 42', $this->prompt_text( $existing ) );
		$this->assertStringContainsString( 'confirmation_token', $this->prompt_text( $existing ), 'A write into an existing post needs the confirmation step.' );

		$fresh = $this->get_prompt( self::PROMPT, array( 'request' => 'A grid' ), $headers );
		$this->assertStringContainsString( 'target.new_draft', $this->prompt_text( $fresh ) );
		$this->assertStringNotContainsString( 'confirmation_token', $this->prompt_text( $fresh ) );
	}

	/**
	 * A block the schema does not list is refused before it reaches the prompt text.
	 *
	 * @group builder-on
	 * @group needs-prompts
	 */
	public function test_a_block_outside_the_enum_is_refused() {
		$this->needs_builder( true );
		if ( ! $this->adapter_serves_prompts() ) {
			$this->markTestSkipped( 'The loaded adapter copy has no prompts.' );
		}

		$headers = $this->open_session();
		$answer  = $this->get_prompt(
			self::PROMPT,
			array(
				'request' => 'A grid',
				'block'   => 'not-a-block',
			),
			$headers
		);

		$this->assertTrue( $this->refused( $answer ) );
		$this->assertArrayNotHasKey( 'messages', $answer['body']['result'] ?? array() );
	}

	/**
	 * The transport gate refuses a subscriber, prompts included.
	 *
	 * @group builder-on
	 */
	public function test_a_user_without_the_product_capability_cannot_fetch_the_prompt() {
		$this->needs_builder( true );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$answer = $this->get_prompt( self::PROMPT, array( 'request' => 'A grid' ), array() );

		$this->assertTrue( $this->refused( $answer ), 'The transport gate must refuse a subscriber.' );
		$this->assertArrayNotHasKey( 'messages', $answer['body']['result'] ?? array() );
	}

	/**
	 * Bulk create is served and runs through the real adapter, so the nested item schema (arrays of objects, enums)
	 * is accepted by the adapter and by core, and a bad item is refused before anything is written.
	 *
	 * @group builder-on
	 */
	public function test_bulk_create_is_served_and_runs_through_the_adapter() {
		$this->needs_builder( true );

		$tool    = 'real-testimonials-create-testimonials';
		$headers = $this->open_session();

		$list   = $this->mcp( 'tools/list', null, $headers );
		$served = array();
		foreach ( $list['body']['result']['tools'] ?? array() as $listed ) {
			$served[ $listed['name'] ] = $listed;
		}
		$this->assertArrayHasKey( $tool, $served, 'The Bulk Import toolset serves bulk create when it is on.' );

		// The item schema crosses the adapter intact: a list of objects that require a title.
		$schema = $served[ $tool ]['inputSchema'];
		$this->assertSame( 'array', $schema['properties']['items']['type'] );
		$this->assertSame( array( 'title' ), $schema['properties']['items']['items']['required'] );
		$this->assertArrayHasKey( 'social_profiles', $schema['properties']['items']['items']['properties'] );

		$count = static function () {
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
		};

		$dry = $this->call_tool(
			$tool,
			array(
				'dry_run' => true,
				'items'   => array(
					array(
						'title'  => 'Wire check',
						'rating' => 5,
					),
					array( 'title' => '   ' ),
				),
			),
			$headers
		);
		$this->assertFalse( $this->failed( $dry ) );
		$data = $this->tool_data( $dry );
		$this->assertSame( 1, $data['would_create'] );
		$this->assertSame( 1, $data['failed'] );
		$this->assertSame( 0, $count(), 'A dry run writes nothing.' );

		$real = $this->call_tool(
			$tool,
			array(
				'items' => array(
					array(
						'title'         => 'Created over MCP',
						'rating'        => 4,
						'reviewer_name' => 'Wire Tester',
					),
				),
			),
			$headers
		);
		$this->assertFalse( $this->failed( $real ) );
		$data = $this->tool_data( $real );
		$this->assertSame( 1, $data['created'] );
		$this->assertSame( 'Created over MCP', get_post( $data['results'][0]['id'] )->post_title );
		$this->assertSame( 1, $count() );

		// The single create tool stays small (it is on by default): the major properties only. The extras are refused
		// by its schema, and the answer of a normal call lists what was saved.
		$single_tool = 'real-testimonials-create-testimonial';
		$this->assertArrayHasKey( $single_tool, $served );
		$this->assertArrayNotHasKey( 'social_profiles', $served[ $single_tool ]['inputSchema']['properties'], 'Extras are offered by the Bulk Import tool only.' );
		$this->assertArrayHasKey( 'reviewer_image_id', $served[ $single_tool ]['inputSchema']['properties'] );
		$this->assertSame( array( 'title' ), $served[ $single_tool ]['inputSchema']['required'] );

		$one = $this->call_tool(
			$single_tool,
			array(
				'title'         => 'Single over MCP',
				'reviewer_name' => 'Wire Single',
				'rating'        => 5,
			),
			$headers
		);
		$this->assertFalse( $this->failed( $one ) );
		$data = $this->tool_data( $one );
		$this->assertSame( 'Single over MCP', $data['title'] );
		$this->assertContains( 'reviewer_name', $data['saved'] );
		$this->assertSame( 2, $count(), 'The single tool created one more testimonial.' );

		$extra = $this->call_tool(
			$single_tool,
			array(
				'title'           => 'Needs Bulk Import',
				'social_profiles' => array(
					array(
						'network' => 'github',
						'url'     => 'https://github.com/example',
					),
				),
			),
			$headers
		);
		$this->assertTrue( $this->failed( $extra ), 'The default tool refuses a property only Bulk Import offers.' );
		$this->assertSame( 2, $count() );

		// An unknown key inside an item is refused by the schema, so nothing is written.
		$bad = $this->call_tool(
			$tool,
			array(
				'items' => array(
					array(
						'title'   => 'Unknown key',
						'unknown' => 'x',
					),
				),
			),
			$headers
		);
		$this->assertTrue( $this->failed( $bad ) );
		$this->assertSame( 2, $count() );
	}

	/**
	 * Off is the default of every site: no prompt and no builder tool on the wire.
	 *
	 * @group builder-off
	 */
	public function test_with_the_builder_toolset_off_neither_the_prompt_nor_a_builder_tool_is_served() {
		$this->needs_builder( false );

		$headers = $this->open_session();
		$tools   = $this->listed_tools( $headers );
		$prompts = $this->listed_prompts( $headers );

		$this->assertContains( 'real-testimonials-get-context', $tools, 'The core tools are served.' );
		$this->assertContains( 'real-testimonials-create-testimonial', $tools, 'The single create tool stays in Management.' );
		$this->assertNotContains( 'real-testimonials-create-testimonials', $tools, 'Bulk create is off by default: its schema is a token cost on every message (D18).' );
		foreach ( self::BUILDER_TOOLS as $tool ) {
			$this->assertNotContains( $tool, $tools, "{$tool} must stay off until the owner opts in." );
		}
		$this->assertArrayNotHasKey( self::PROMPT, $prompts, 'The prompt follows the Builder toolset.' );
		$this->assertTrue( $this->refused( $this->get_prompt( self::PROMPT, array( 'request' => 'A grid' ), $headers ) ), 'Asking for it by name must fail too.' );
	}
}
