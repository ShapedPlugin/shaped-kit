<?php
/**
 * Test helper: talk to a product's MCP endpoint the way an MCP client does.
 *
 * Requests go through WordPress's REST dispatch in-process, and then through the
 * `rest_post_dispatch` filter, which `WP_REST_Server::serve_request()` applies after dispatching
 * and `rest_do_request()` does not. Older adapter copies add their session header in that filter,
 * so leaving it out would make them look broken. (Same helper as Location Weather's adapter suite.)
 *
 * @package ShapedKit
 */

namespace ShapedKit\Tests\Adapter;

use WP_REST_Request;

/**
 * Use inside a WP_UnitTestCase of the `adapter` suite.
 */
trait Mcp_Client {

	/**
	 * Route of the module's endpoint.
	 *
	 * @var string
	 */
	private $endpoint = '/real-testimonials/mcp';

	/**
	 * Send one JSON-RPC request.
	 *
	 * @param string     $method  JSON-RPC method, e.g. "prompts/list".
	 * @param array|null $params  Method parameters.
	 * @param array      $headers Extra request headers, such as the session id.
	 *
	 * @return array{status: int, raw: string, body: array, headers: array, output: string} The body is the decoded JSON.
	 */
	private function mcp( $method, $params = null, $headers = array() ) {
		static $id = 0;

		$payload = array(
			'jsonrpc' => '2.0',
			'id'      => ++$id,
			'method'  => $method,
		);
		if ( null !== $params ) {
			$payload['params'] = $params;
		}

		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( wp_json_encode( $payload ) );

		// Anything a callback prints is caught here: in real HTTP it would land in front of the JSON.
		ob_start();
		$response = rest_do_request( $request );
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), rest_get_server(), $request );
		$output   = (string) ob_get_clean();

		$raw = (string) wp_json_encode( $response->get_data() );

		return array(
			'status'  => $response->get_status(),
			// What goes on the wire. `body` is decoded from it, so an empty JSON object has become [] there.
			'raw'     => $raw,
			'body'    => json_decode( $raw, true ),
			'headers' => $response->get_headers(),
			'output'  => $output,
		);
	}

	/**
	 * Open a session and return the headers every later request must carry.
	 *
	 * @return array<string, string>
	 */
	private function open_session() {
		$init = $this->mcp(
			'initialize',
			array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => new \stdClass(),
				'clientInfo'      => array(
					'name'    => 'adapter-suite',
					'version' => '1',
				),
			)
		);

		$headers = array();
		foreach ( $init['headers'] as $name => $value ) {
			if ( 0 === strcasecmp( $name, 'Mcp-Session-Id' ) ) {
				$headers['Mcp-Session-Id'] = $value;
			}
		}
		if ( isset( $init['body']['result']['protocolVersion'] ) ) {
			$headers['MCP-Protocol-Version'] = $init['body']['result']['protocolVersion'];
		}

		return $headers;
	}

	/**
	 * The names in a `tools/list` result.
	 *
	 * @param array $headers Session headers.
	 *
	 * @return string[]
	 */
	private function listed_tools( $headers ) {
		$list = $this->mcp( 'tools/list', null, $headers );

		return wp_list_pluck( $list['body']['result']['tools'] ?? array(), 'name' );
	}

	/**
	 * The prompts in a `prompts/list` result, keyed by name.
	 *
	 * @param array $headers Session headers.
	 *
	 * @return array<string, array> Prompt records. Empty when the copy has no prompts.
	 */
	private function listed_prompts( $headers ) {
		$list = $this->mcp( 'prompts/list', null, $headers );

		$prompts = array();
		foreach ( $list['body']['result']['prompts'] ?? array() as $prompt ) {
			$prompts[ $prompt['name'] ] = $prompt;
		}

		return $prompts;
	}

	/**
	 * Fetch a prompt.
	 *
	 * @param string $name      The prompt's name as the client sees it.
	 * @param array  $arguments Prompt arguments. Omitted entirely when empty.
	 * @param array  $headers   Session headers from `open_session()`.
	 *
	 * @return array The response, as `mcp()` returns it.
	 */
	private function get_prompt( $name, $arguments, $headers ) {
		$params = array( 'name' => $name );
		if ( $arguments ) {
			$params['arguments'] = $arguments;
		}

		return $this->mcp( 'prompts/get', $params, $headers );
	}

	/**
	 * Call a tool.
	 *
	 * @param string $tool    The tool's name as the client sees it, e.g. "real-testimonials-get-context".
	 * @param array  $args    Tool arguments. Omitted entirely when empty.
	 * @param array  $headers Session headers from `open_session()`.
	 *
	 * @return array The response, as `mcp()` returns it.
	 */
	private function call_tool( $tool, $args, $headers ) {
		$params = array( 'name' => $tool );
		if ( $args ) {
			$params['arguments'] = $args;
		}

		return $this->mcp( 'tools/call', $params, $headers );
	}

	/**
	 * The text a tool returned, decoded from JSON. Null when the call failed or returned no JSON.
	 *
	 * @param array $response A response from `call_tool()`.
	 *
	 * @return array|null
	 */
	private function tool_data( $response ) {
		$text = $response['body']['result']['content'][0]['text'] ?? null;

		return is_string( $text ) ? json_decode( $text, true ) : null;
	}

	/**
	 * Whether a tool call ended in an error, whichever way the adapter reports it: a JSON-RPC error, a tool result
	 * flagged `isError`, or an HTTP error.
	 *
	 * @param array $response A response from `call_tool()`.
	 *
	 * @return bool
	 */
	private function failed( $response ) {
		return isset( $response['body']['error'] ) || ! empty( $response['body']['result']['isError'] ) || $response['status'] >= 400 || isset( $response['body']['code'] );
	}
}
