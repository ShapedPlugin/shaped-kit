/**
 * Tests for the connection test.
 *
 * Risks covered: a test that passes because it used the cookie and not the password, a stripped
 * Authorization header explained as a wrong password only, a success reported for something that is not
 * an MCP server, a request that waits forever, and a password sent with nothing typed.
 */
import { interpret, testConnection } from './connectionTest';

const product = {
	slug: 'location-weather',
	name: 'Location Weather',
	endpoint_url: 'https://site.test/wp-json/location-weather/mcp',
};
const login = { username: 'admin', password: 'abcd efgh ijkl mnop qrst uvwx' };

/** A fetch that answers with a status and a JSON body. */
const answer = ( status, body ) =>
	jest.fn( () =>
		Promise.resolve( {
			status,
			json: () =>
				body === undefined
					? Promise.reject( new Error( 'not json' ) )
					: Promise.resolve( body ),
		} )
	);

describe( 'interpret', () => {
	test.each( [
		[ 200, { result: { serverInfo: {} } }, 'ok' ],
		[ 200, { error: { code: -32600 } }, 'unexpected' ],
		[ 200, { jsonrpc: '2.0' }, 'unexpected' ],
		[ 200, null, 'unexpected' ],
		[ 401, null, 'rejected' ],
		[ 403, null, 'forbidden' ],
		[ 404, null, 'missing' ],
		[ 500, null, 'error' ],
		[ 503, null, 'error' ],
		[ 418, null, 'unexpected' ],
	] )( 'status %s with %j is %s', ( status, body, state ) => {
		expect( interpret( status, body ).state ).toBe( state );
	} );

	test( 'a 401 names both a wrong password and a host that strips the header', () => {
		const { message } = interpret( 401, null );

		expect( message ).toMatch( /wrong/ );
		expect( message ).toMatch( /Authorization header/ );
	} );

	test( 'a result next to an error is not a success', () => {
		expect(
			interpret( 200, { result: {}, error: { message: 'x' } } ).state
		).toBe( 'unexpected' );
	} );
} );

describe( 'testConnection', () => {
	test( 'it sends an initialize request with the Basic header and no cookies', async () => {
		const fetchImpl = answer( 200, { result: {} } );

		const result = await testConnection( product, login, { fetchImpl } );

		const [ url, options ] = fetchImpl.mock.calls[ 0 ];
		expect( url ).toBe( product.endpoint_url );
		expect( options.method ).toBe( 'POST' );
		expect( options.credentials ).toBe( 'omit' );
		expect( options.headers.Authorization ).toBe(
			'Basic YWRtaW46YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4'
		);
		expect( JSON.parse( options.body ) ).toMatchObject( {
			jsonrpc: '2.0',
			method: 'initialize',
		} );
		expect( result ).toMatchObject( {
			slug: 'location-weather',
			name: 'Location Weather',
			state: 'ok',
		} );
	} );

	test( 'nothing is sent until both the username and the password are typed', async () => {
		const fetchImpl = answer( 200, { result: {} } );

		const result = await testConnection(
			product,
			{ username: 'admin', password: '' },
			{ fetchImpl }
		);

		expect( fetchImpl ).not.toHaveBeenCalled();
		expect( result.state ).toBe( 'incomplete' );
	} );

	test( 'a body that is not JSON (an HTML error page) is still read by its status', async () => {
		const result = await testConnection( product, login, {
			fetchImpl: answer( 401, undefined ),
		} );

		expect( result.state ).toBe( 'rejected' );
	} );

	test( 'a network failure is reported as unreachable and does not throw', async () => {
		const result = await testConnection( product, login, {
			fetchImpl: jest.fn( () =>
				Promise.reject( new TypeError( 'Failed to fetch' ) )
			),
		} );

		expect( result.state ).toBe( 'unreachable' );
		expect( result.message ).toMatch( /could not reach/ );
	} );

	test( 'a request that never answers is given up on and says so', async () => {
		const fetchImpl = jest.fn(
			( url, { signal } ) =>
				new Promise( ( resolve, reject ) => {
					signal.addEventListener( 'abort', () => {
						const error = new Error( 'aborted' );
						error.name = 'AbortError';
						reject( error );
					} );
				} )
		);

		const result = await testConnection( product, login, {
			fetchImpl,
			timeout: 20,
		} );

		expect( result.state ).toBe( 'unreachable' );
		expect( result.message ).toMatch( /No answer within/ );
	} );

	test( 'the password is never put in the result', async () => {
		const result = await testConnection( product, login, {
			fetchImpl: answer( 401, null ),
		} );

		expect( JSON.stringify( result ) ).not.toContain( 'abcdefgh' );
		expect( JSON.stringify( result ) ).not.toContain( 'YWRtaW46' );
	} );
} );
