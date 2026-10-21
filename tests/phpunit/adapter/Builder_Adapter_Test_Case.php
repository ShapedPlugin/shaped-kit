<?php
/**
 * A product's MCP prompt and Builder tools, on the wire, through one real adapter copy (AI builder PRD B7).
 *
 * The unit and integration tests prove the prompt's definition and callback. None of them asks an
 * adapter to serve it, and a prompt that is never served fails silently: the client simply has no
 * "build a block" command and nothing reports why (TESTING.md rule 5). This suite runs
 * `prompts/list` and `prompts/get` through a real adapter, over REST, as an MCP client would.
 *
 * What it pins, for every product (one body for all, PRD §20 rule 6; the product is a profile in
 * `tests/phpunit/adapter/products/`):
 *
 *   - the prompt is served beside the Builder tools when the owner opted in, and never as a tool;
 *   - an adapter older than 0.5.0 (no prompts) still serves the tools and not the prompt;
 *   - the tool names the prompt text tells a client to call are the names the server serves;
 *   - an MCP client sends prompt arguments as strings, so a numeric string `post_id` must work;
 *   - a block outside the enum and a user without the product capability are refused;
 *   - with the Builder toolset off (the default of every site), neither prompt nor builder tool is served.
 *
 * A product adds its own checks in a thin subclass (Real Testimonials: the bulk create tool).
 *
 * Product, edition, adapter copy and toolset state come from the environment (see adapter-support.php);
 * `tests/run-adapter-matrix.sh` runs every combination.
 *
 * Not collected by PHPUnit on its own (the file name does not end in `Test.php`): each product's test class
 * requires it.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Adapter;

use WP_UnitTestCase;

require_once __DIR__ . '/Mcp_Client.php';

/**
 * Class Builder_Adapter_Test_Case
 */
abstract class Builder_Adapter_Test_Case extends WP_UnitTestCase {

	use Mcp_Client;

	/**
	 * First adapter release that serves MCP prompts (`RegisterAbilityAsMcpPrompt`, since 0.5.0).
	 */
	const FIRST_ADAPTER_WITH_PROMPTS = '0.5.0';

	/**
	 * The prompt's name as an MCP client sees it (the ability name with `/` turned into `-`).
	 *
	 * @return string
	 */
	protected function prompt() {
		return shaped_kit_adapter_product()['prompt'];
	}

	/**
	 * The Builder tools as an MCP client sees them.
	 *
	 * @return string[]
	 */
	protected function builder_tools() {
		return shaped_kit_adapter_product()['builder_tools'];
	}

	/**
	 * The edition's Permission_Gate class.
	 *
	 * @return string
	 */
	protected static function gate() {
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
		// "on" switches the opt-in toolsets on (what the profile lists); "off" leaves what a fresh site serves.
		if ( shaped_kit_adapter_builder_on() ) {
			$gate::set_active_toolsets( shaped_kit_adapter_product()['toolsets_on'] );
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
			$edition = shaped_kit_adapter_edition();
			$this->markTestSkipped( ( $edition['plugin'] ?? shaped_kit_adapter_product()['slug'] ) . ' is not available next to the Kit.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Skip a test that belongs to the other Builder state. The server is built once per process, so
	 * the matrix runs each state in its own process (see run-adapter-matrix.sh).
	 *
	 * @param bool $builder_on True for a test that needs the Builder toolset on.
	 */
	protected function needs_builder( $builder_on ) {
		if ( shaped_kit_adapter_builder_on() !== $builder_on ) {
			$this->markTestSkipped( 'This test needs the Builder toolset ' . ( $builder_on ? 'on' : 'off' ) . '.' );
		}
	}

	/**
	 * Whether the loaded adapter copy can serve prompts at all.
	 *
	 * @return bool
	 */
	protected function adapter_serves_prompts() {
		return version_compare( \WP\MCP\Core\McpAdapter::VERSION, self::FIRST_ADAPTER_WITH_PROMPTS, '>=' );
	}

	/**
	 * Whether a response is a refusal or an error, whichever way the adapter reports it.
	 *
	 * @param array $response A response from `mcp()`.
	 *
	 * @return bool
	 */
	protected function refused( $response ) {
		return isset( $response['body']['error'] ) || isset( $response['body']['code'] ) || $response['status'] >= 400;
	}

	/**
	 * The prompt text of a `prompts/get` answer.
	 *
	 * @param array $response A response from `get_prompt()`.
	 *
	 * @return string
	 */
	protected function prompt_text( $response ) {
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
		foreach ( $this->builder_tools() as $tool ) {
			$this->assertContains( $tool, $tools, "{$tool} must be served when the Builder toolset is on." );
		}
		$this->assertNotContains( $this->prompt(), $tools, 'A prompt is not a tool.' );

		if ( ! $this->adapter_serves_prompts() ) {
			$this->assertArrayNotHasKey( $this->prompt(), $prompts, 'An adapter without prompts cannot list one.' );
			return;
		}

		$this->assertArrayHasKey( $this->prompt(), $prompts, 'The adapter must serve the prompt.' );
		// An optional argument has no `required` key, so list the required names.
		$required = array();
		foreach ( $prompts[ $this->prompt() ]['arguments'] ?? array() as $argument ) {
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
		$answer  = $this->get_prompt( $this->prompt(), array( 'request' => 'A dark 3-column carousel' ), $headers );

		if ( ! $this->adapter_serves_prompts() ) {
			$this->assertTrue( $this->refused( $answer ), 'An adapter without prompts cannot serve one.' );
			return;
		}

		$text = $this->prompt_text( $answer );
		$this->assertNotSame( '', $text, 'The prompt must come back with a message.' );
		$this->assertStringContainsString( 'A dark 3-column carousel', $text );

		// Every `tool` the text names must be one this server lists, or the client's next call fails.
		// The pattern also takes a name with a wrong separator, so a typo is reported, not skipped.
		preg_match_all( '/`(' . preg_quote( shaped_kit_adapter_product()['slug'], '/' ) . '[a-z0-9_-]+)`/', $text, $named );
		$named  = array_unique( $named[1] );
		$served = $this->listed_tools( $headers );
		foreach ( $named as $tool ) {
			$this->assertContains( $tool, $served, "The prompt tells the client to call {$tool}, which this server does not serve." );
		}
		// And the text must lead the client through the whole flow, not part of it.
		foreach ( $this->builder_tools() as $tool ) {
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
			$this->prompt(),
			array(
				'request' => 'A grid',
				'post_id' => '42',
			),
			$headers
		);
		$this->assertFalse( $this->refused( $existing ), 'A string post id must reach the prompt.' );
		$this->assertStringContainsString( 'existing post ID 42', $this->prompt_text( $existing ) );
		$this->assertStringContainsString( 'confirmation_token', $this->prompt_text( $existing ), 'A write into an existing post needs the confirmation step.' );

		$fresh = $this->get_prompt( $this->prompt(), array( 'request' => 'A grid' ), $headers );
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
			$this->prompt(),
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

		$answer = $this->get_prompt( $this->prompt(), array( 'request' => 'A grid' ), array() );

		$this->assertTrue( $this->refused( $answer ), 'The transport gate must refuse a subscriber.' );
		$this->assertArrayNotHasKey( 'messages', $answer['body']['result'] ?? array() );
	}

	/**
	 * Every tool the module means to serve reaches the wire. An adapter that cannot read a schema drops the tool
	 * without a word (E124), so a new schema keyword is only safe once every adapter copy lists the tool.
	 */
	public function test_every_tool_the_module_serves_is_listed_by_the_adapter() {
		$registrar = shaped_kit_adapter_edition()['namespace'] . '\\Mcp\\Abilities_Registrar';
		$tools     = $this->listed_tools( $this->open_session() );

		$this->assertNotEmpty( $registrar::names() );
		foreach ( $registrar::names() as $name ) {
			$this->assertContains( str_replace( '/', '-', $name ), $tools, "{$name} is served by the module but missing on the wire." );
		}
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

		$this->assertContains( shaped_kit_adapter_product()['core_tool'], $tools, 'The core tools are served.' );
		foreach ( $this->builder_tools() as $tool ) {
			$this->assertNotContains( $tool, $tools, "{$tool} must stay off until the owner opts in." );
		}
		$this->assertArrayNotHasKey( $this->prompt(), $prompts, 'The prompt follows the Builder toolset.' );
		$this->assertTrue( $this->refused( $this->get_prompt( $this->prompt(), array( 'request' => 'A grid' ), $headers ) ), 'Asking for it by name must fail too.' );
	}
}
