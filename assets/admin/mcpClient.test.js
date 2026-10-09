/**
 * Tests for the browser-side MCP client.
 *
 * Risks covered: a session id not carried to the next request, cookies sent (which makes a good password
 * fail), a request made with half the credentials or with arguments that are not an object, a server
 * refusal shown as success, and a tool's own "I failed" answer lost.
 */
import {
	buildArgsTemplate,
	callTool,
	openSession,
	parseArguments,
} from './mcpClient';

const product = {
	slug: 'location-weather',
	name: 'Location Weather',
	endpoint_url: 'https://site.test/wp-json/location-weather/mcp',
};
const login = { username: 'admin', password: 'abcd efgh ijkl mnop qrst uvwx' };

/** A fake server answering each request from a list, and recording what it was sent. */
const server = ( answers ) => {
	const calls = [];
	const fetchImpl = jest.fn( ( url, options ) => {
		calls.push( { url, options, body: JSON.parse( options.body ) } );
		const next = answers.shift();

		return Promise.resolve( {
			status: next.status,
			headers: {
				get: ( name ) =>
					name === 'Mcp-Session-Id' ? next.session || null : null,
			},
			json: () =>
				next.body === undefined
					? Promise.reject( new Error( 'not json' ) )
					: Promise.resolve( next.body ),
		} );
	} );

	return { fetchImpl, calls };
};

const tool = ( name ) => ( {
	name,
	description: `${ name } description`,
	inputSchema: { type: 'object', properties: {} },
} );

describe( 'openSession', () => {
	test( 'it initialises, keeps the session, and lists the tools, sending the session on the second request', async () => {
		const { fetchImpl, calls } = server( [
			{
				status: 200,
				session: 'sess-1',
				body: { result: { serverInfo: { name: 'X' } } },
			},
			{
				status: 200,
				body: {
					result: {
						tools: [ tool( 'location-weather-get-context' ) ],
					},
				},
			},
		] );

		const opened = await openSession( product, login, { fetchImpl } );

		expect( opened.ok ).toBe( true );
		expect( opened.session ).toBe( 'sess-1' );
		expect( opened.tools.map( ( t ) => t.name ) ).toEqual( [
			'location-weather-get-context',
		] );
		expect( calls.map( ( c ) => c.body.method ) ).toEqual( [
			'initialize',
			'tools/list',
		] );
		expect( calls[ 0 ].options.headers ).not.toHaveProperty(
			'Mcp-Session-Id'
		);
		expect( calls[ 1 ].options.headers[ 'Mcp-Session-Id' ] ).toBe(
			'sess-1'
		);
	} );

	test( 'every request has the Basic header, no cookies, and goes to the product endpoint', async () => {
		const { fetchImpl, calls } = server( [
			{ status: 200, session: 's', body: { result: {} } },
			{ status: 200, body: { result: { tools: [] } } },
		] );

		await openSession( product, login, { fetchImpl } );

		calls.forEach( ( call ) => {
			expect( call.url ).toBe( product.endpoint_url );
			expect( call.options.credentials ).toBe( 'omit' );
			expect( call.options.headers.Authorization ).toBe(
				'Basic YWRtaW46YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4'
			);
		} );
	} );

	test( 'a refusal on sign-in stops there, with the reason, and asks for no tools', async () => {
		const { fetchImpl, calls } = server( [ { status: 401, body: null } ] );

		const opened = await openSession( product, login, { fetchImpl } );

		expect( opened.ok ).toBe( false );
		expect( opened.error.state ).toBe( 'rejected' );
		expect( opened.error.message ).toMatch( /Authorization header/ );
		expect( opened.tools ).toEqual( [] );
		expect( calls ).toHaveLength( 1 );
	} );

	test( 'a server that signs in but then refuses the tool list reports that', async () => {
		const { fetchImpl } = server( [
			{ status: 200, session: 's', body: { result: {} } },
			{ status: 403, body: null },
		] );

		const opened = await openSession( product, login, { fetchImpl } );

		expect( opened.ok ).toBe( false );
		expect( opened.error.state ).toBe( 'forbidden' );
	} );

	test( 'an address that answers 200 but is not an MCP server is not a success', async () => {
		const { fetchImpl } = server( [ { status: 200, body: { hello: 1 } } ] );

		const opened = await openSession( product, login, { fetchImpl } );

		expect( opened.ok ).toBe( false );
		expect( opened.error.state ).toBe( 'unexpected' );
	} );

	test( 'nothing is sent until both credentials are typed', async () => {
		const { fetchImpl } = server( [] );

		const opened = await openSession(
			product,
			{ username: 'admin', password: '' },
			{ fetchImpl }
		);

		expect( fetchImpl ).not.toHaveBeenCalled();
		expect( opened.error.state ).toBe( 'incomplete' );
	} );

	test( 'a network failure comes back as an error, not an exception', async () => {
		const fetchImpl = jest.fn( () =>
			Promise.reject( new TypeError( 'Failed to fetch' ) )
		);

		const opened = await openSession( product, login, { fetchImpl } );

		expect( opened.ok ).toBe( false );
		expect( opened.error.state ).toBe( 'unreachable' );
	} );

	test( 'a server with no session header still lists tools, without sending one', async () => {
		const { fetchImpl, calls } = server( [
			{ status: 200, body: { result: {} } },
			{ status: 200, body: { result: { tools: [ tool( 'a' ) ] } } },
		] );

		const opened = await openSession( product, login, { fetchImpl } );

		expect( opened.ok ).toBe( true );
		expect( opened.session ).toBeNull();
		expect( calls[ 1 ].options.headers ).not.toHaveProperty(
			'Mcp-Session-Id'
		);
	} );
} );

describe( 'callTool', () => {
	test( 'it calls the named tool with its arguments and the session', async () => {
		const { fetchImpl, calls } = server( [
			{
				status: 200,
				body: {
					result: { structuredContent: { total: 1 }, content: [] },
				},
			},
		] );

		const called = await callTool(
			product,
			login,
			'sess-1',
			'location-weather-get-weather-template',
			{ id: 7 },
			{ fetchImpl }
		);

		expect( calls[ 0 ].body ).toMatchObject( {
			method: 'tools/call',
			params: {
				name: 'location-weather-get-weather-template',
				arguments: { id: 7 },
			},
		} );
		expect( calls[ 0 ].options.headers[ 'Mcp-Session-Id' ] ).toBe(
			'sess-1'
		);
		expect( called.ok ).toBe( true );
		expect( called.isError ).toBe( false );
		expect( called.result.structuredContent ).toEqual( { total: 1 } );
		expect( typeof called.ms ).toBe( 'number' );
	} );

	test( 'a tool that says its own call failed is still an answer, marked as an error', async () => {
		const { fetchImpl } = server( [
			{
				status: 200,
				body: {
					result: {
						isError: true,
						content: [ { type: 'text', text: 'Not found' } ],
					},
				},
			},
		] );

		const called = await callTool(
			product,
			login,
			's',
			'x',
			{},
			{ fetchImpl }
		);

		expect( called.ok ).toBe( true );
		expect( called.isError ).toBe( true );
		expect( called.result.content[ 0 ].text ).toBe( 'Not found' );
	} );

	test( 'a protocol error from the server is reported with its own message', async () => {
		const { fetchImpl } = server( [
			{
				status: 200,
				body: {
					error: { code: -32602, message: 'Unknown tool: nope' },
				},
			},
		] );

		const called = await callTool(
			product,
			login,
			's',
			'nope',
			{},
			{ fetchImpl }
		);

		expect( called.ok ).toBe( false );
		expect( called.error ).toEqual( {
			state: 'rpc',
			message: 'Unknown tool: nope',
		} );
	} );

	test( 'an HTTP refusal is not mistaken for a result', async () => {
		const { fetchImpl } = server( [ { status: 403, body: null } ] );

		const called = await callTool(
			product,
			login,
			's',
			'x',
			{},
			{ fetchImpl }
		);

		expect( called.ok ).toBe( false );
		expect( called.error.state ).toBe( 'forbidden' );
		expect( called.result ).toBeNull();
	} );

	test( 'nothing is sent without credentials', async () => {
		const { fetchImpl } = server( [] );

		const called = await callTool(
			product,
			{ username: '', password: '' },
			's',
			'x',
			{},
			{ fetchImpl }
		);

		expect( fetchImpl ).not.toHaveBeenCalled();
		expect( called.error.state ).toBe( 'incomplete' );
	} );
} );

describe( 'buildArgsTemplate', () => {
	test.each( [
		[ undefined, '{}' ],
		[ { type: 'object', properties: {} }, '{}' ],
		[
			{
				properties: {
					id: { type: 'integer' },
					search: { type: 'string' },
				},
				required: [ 'id' ],
			},
			'{\n  "id": 0\n}',
		],
		[
			{
				properties: {
					a: { type: 'string' },
					b: { type: 'boolean' },
					c: { type: 'array' },
					d: { type: 'object' },
					e: { type: 'number' },
				},
				required: [ 'a', 'b', 'c', 'd', 'e' ],
			},
			'{\n  "a": "",\n  "b": false,\n  "c": [],\n  "d": {},\n  "e": 0\n}',
		],
		[
			{ properties: {}, required: [ 'mystery' ] },
			'{\n  "mystery": ""\n}',
		],
	] )( '%j starts as %s', ( schema, expected ) => {
		expect( buildArgsTemplate( schema ) ).toBe( expected );
	} );

	test( 'optional properties are left out', () => {
		expect(
			buildArgsTemplate( {
				properties: { page: { type: 'integer' } },
				required: [],
			} )
		).toBe( '{}' );
	} );
} );

describe( 'parseArguments', () => {
	test( 'an empty box means no arguments', () => {
		expect( parseArguments( '' ) ).toEqual( { ok: true, value: {} } );
		expect( parseArguments( '   \n ' ) ).toEqual( { ok: true, value: {} } );
	} );

	test( 'an object is accepted', () => {
		expect( parseArguments( '{"id": 7, "search": "x"}' ) ).toEqual( {
			ok: true,
			value: { id: 7, search: 'x' },
		} );
	} );

	test.each( [ '{"id": ', 'id: 7', "{'id': 7}", 'undefined' ] )(
		'%s is not JSON',
		( text ) => {
			const result = parseArguments( text );

			expect( result.ok ).toBe( false );
			expect( result.message ).toMatch( /not valid JSON/ );
		}
	);

	test.each( [ '[1,2]', '7', '"text"', 'null', 'true' ] )(
		'%s is JSON but not an object',
		( text ) => {
			const result = parseArguments( text );

			expect( result.ok ).toBe( false );
			expect( result.message ).toMatch( /must be a JSON object/ );
		}
	);
} );
