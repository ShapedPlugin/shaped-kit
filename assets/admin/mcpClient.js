/**
 * A small MCP client that runs in the browser: open a session, list a product's tools, call one.
 *
 * It does what an AI client does, with the typed application password and no cookies, so the answers are
 * exactly what a real agent would get. Like "Test connection", the password goes only to the product's own
 * endpoint and never through the Kit's server.
 *
 * Every function resolves; none rejects. A problem comes back as `error: { state, message }` in words an
 * owner can act on, so the screen never has to handle an exception.
 */
import { __ } from '@wordpress/i18n';
import { describeFailure, interpret } from './connectionTest';
import { credentials } from './snippets';

const SESSION_HEADER = 'Mcp-Session-Id';
const TIMEOUT_MS = 15000;

/**
 * One JSON-RPC request to a product's endpoint.
 *
 * @param {Object}  product            A product with `endpoint_url`.
 * @param {Object}  login              The typed credentials.
 * @param {string}  login.username     Username.
 * @param {string}  login.password     Application password.
 * @param {?string} session            The session id from `initialize`, or null before one exists.
 * @param {string}  method             JSON-RPC method.
 * @param {Object}  params             JSON-RPC params.
 * @param {Object=} options            Test hooks.
 * @param {Function} options.fetchImpl `fetch` to use.
 * @param {number}  options.timeout    Milliseconds to wait.
 * @return {Promise<{status: number, body: *, session: ?string, error: ?{state: string, message: string}}>} The answer.
 */
const rpc = async (
	product,
	{ username, password },
	session,
	method,
	params,
	{ fetchImpl = window.fetch.bind( window ), timeout = TIMEOUT_MS } = {}
) => {
	const { header, ready } = credentials( username, password );

	if ( ! ready ) {
		return {
			status: 0,
			body: null,
			session,
			error: {
				state: 'incomplete',
				message: __(
					'Type the username and application password first.',
					'shaped-kit'
				),
			},
		};
	}

	const controller = new window.AbortController();
	const timer = setTimeout( () => controller.abort(), timeout );

	try {
		const response = await fetchImpl( product.endpoint_url, {
			method: 'POST',
			credentials: 'omit',
			signal: controller.signal,
			headers: {
				Authorization: `Basic ${ header }`,
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
				...( session ? { [ SESSION_HEADER ]: session } : {} ),
			},
			body: JSON.stringify( {
				jsonrpc: '2.0',
				id: 1,
				method,
				params,
			} ),
		} );

		const body = await response.json().catch( () => null );

		return {
			status: response.status,
			body,
			session: response.headers.get( SESSION_HEADER ) || session,
			error: null,
		};
	} catch ( failure ) {
		return {
			status: 0,
			body: null,
			session,
			error: {
				state: 'unreachable',
				message: describeFailure( failure ),
			},
		};
	} finally {
		clearTimeout( timer );
	}
};

/**
 * A request that came back with something other than a normal answer, as an error. Returns null when it
 * was a normal answer (HTTP 200 with a JSON-RPC `result`).
 *
 * @param {{status: number, body: *, error: ?Object}} answer What `rpc()` returned.
 * @return {?{state: string, message: string}} The error, or null.
 */
const failure = ( answer ) => {
	if ( answer.error ) {
		return answer.error;
	}

	if ( answer.status !== 200 ) {
		const { state, message } = interpret( answer.status, answer.body );

		return { state, message };
	}

	if ( answer.body?.error ) {
		return {
			state: 'rpc',
			message: String( answer.body.error.message || '' ),
		};
	}

	if ( ! answer.body?.result ) {
		return interpret( 200, answer.body );
	}

	return null;
};

/**
 * Sign in to a product and list the tools it offers.
 *
 * @param {Object}  product  A product with `endpoint_url`.
 * @param {Object}  login    The typed credentials.
 * @param {Object=} options  Test hooks, passed to `rpc()`.
 * @return {Promise<{ok: boolean, session: ?string, tools: Array, error: ?Object}>} The session and tools, or the reason not.
 */
export const openSession = async ( product, login, options ) => {
	const init = await rpc(
		product,
		login,
		null,
		'initialize',
		{
			protocolVersion: '2024-11-05',
			capabilities: {},
			clientInfo: { name: 'shaped-kit-tool-tester', version: '1' },
		},
		options
	);
	const initFailure = failure( init );

	if ( initFailure ) {
		return { ok: false, session: null, tools: [], error: initFailure };
	}

	const list = await rpc(
		product,
		login,
		init.session,
		'tools/list',
		{},
		options
	);
	const listFailure = failure( list );

	if ( listFailure ) {
		return {
			ok: false,
			session: init.session,
			tools: [],
			error: listFailure,
		};
	}

	const tools = Array.isArray( list.body.result.tools )
		? list.body.result.tools
		: [];

	return { ok: true, session: init.session, tools, error: null };
};

/**
 * Call one tool.
 *
 * @param {Object}  product   A product with `endpoint_url`.
 * @param {Object}  login     The typed credentials.
 * @param {?string} session   The session from `openSession()`.
 * @param {string}  name      The tool's wire name, such as `location-weather-get-context`.
 * @param {Object}  args      The tool's arguments.
 * @param {Object=} options   Test hooks, passed to `rpc()`.
 * @return {Promise<{ok: boolean, result: *, isError: boolean, status: number, ms: number, error: ?Object}>} The tool's own answer, or the reason there is none. `isError` is the tool saying its call failed, which is still an answer.
 */
export const callTool = async (
	product,
	login,
	session,
	name,
	args,
	options
) => {
	const started = Date.now();
	const answer = await rpc(
		product,
		login,
		session,
		'tools/call',
		{ name, arguments: args },
		options
	);
	const problem = failure( answer );
	const ms = Date.now() - started;

	if ( problem ) {
		return {
			ok: false,
			result: null,
			isError: false,
			status: answer.status,
			ms,
			error: problem,
		};
	}

	return {
		ok: true,
		result: answer.body.result,
		isError: answer.body.result.isError === true,
		status: answer.status,
		ms,
		error: null,
	};
};

/**
 * Starting text for a tool's arguments: only the required properties, each given an empty value of its type,
 * so the owner sees what the tool needs and fills in the blanks.
 *
 * @param {?Object} schema A tool's `inputSchema`.
 * @return {string} Pretty JSON.
 */
export const buildArgsTemplate = ( schema ) => {
	const empty = { string: '', integer: 0, number: 0, boolean: false };
	const starter = {};
	const required = Array.isArray( schema?.required ) ? schema.required : [];

	required.forEach( ( key ) => {
		const type = schema?.properties?.[ key ]?.type;

		if ( type === 'array' ) {
			starter[ key ] = [];
		} else if ( type === 'object' ) {
			starter[ key ] = {};
		} else {
			starter[ key ] = Object.prototype.hasOwnProperty.call( empty, type )
				? empty[ type ]
				: '';
		}
	} );

	return JSON.stringify( starter, null, 2 );
};

/**
 * Read the arguments the owner typed. A tool takes an object, so anything else is refused here, before
 * a request is made.
 *
 * @param {string} text What is in the arguments box.
 * @return {{ok: true, value: Object}|{ok: false, message: string}} The object, or why not.
 */
export const parseArguments = ( text ) => {
	if ( String( text ).trim() === '' ) {
		return { ok: true, value: {} };
	}

	let value;

	try {
		value = JSON.parse( text );
	} catch ( failureToParse ) {
		return {
			ok: false,
			message: __( 'The arguments are not valid JSON.', 'shaped-kit' ),
		};
	}

	if (
		value === null ||
		typeof value !== 'object' ||
		Array.isArray( value )
	) {
		return {
			ok: false,
			message: __(
				'The arguments must be a JSON object, such as {"id": 1}.',
				'shaped-kit'
			),
		};
	}

	return { ok: true, value };
};
