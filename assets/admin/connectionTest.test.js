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
	endpoint_url: 'http://localhost/wp-json/location-weather/mcp',
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

	describe( 'a 401', () => {
		const hint =
			'If you sent credentials, your web server may be stripping the Authorization header.';

		test( 'with the server hint says the host removes the header, not that the password is wrong, and gives the fix', () => {
			const { state, message } = interpret( 401, {
				code: 'rest_forbidden',
				host_config_hint: hint,
			} );

			expect( state ).toBe( 'rejected' );
			expect( message ).toMatch( /not passing the Authorization header/ );
			expect( message ).toMatch( /not a wrong password/ );
			expect( message ).toContain( 'HTTP_AUTHORIZATION' );
			expect( message ).toContain( 'fastcgi_param' );
			expect( message ).not.toMatch( /was not accepted/ );
		} );

		test( 'from WordPress without the hint says the username or password was refused, and does not blame the host', () => {
			const { state, message } = interpret( 401, {
				code: 'rest_forbidden',
			} );

			expect( state ).toBe( 'rejected' );
			expect( message ).toMatch(
				/username or application password was not accepted/
			);
			expect( message ).toMatch( /revoked/ );
			expect( message ).not.toMatch( /Authorization header/ );
		} );

		test.each( [
			[ 'null', null ],
			[ 'undefined', undefined ],
			[ 'a string', '<html>401</html>' ],
		] )(
			'that is not JSON (%s) says something in front of WordPress answered',
			( label, body ) => {
				const { state, message } = interpret( 401, body );

				expect( state ).toBe( 'rejected' );
				expect( message ).toMatch( /in front of WordPress/ );
				expect( message ).not.toMatch(
					/not accepted|Authorization header/
				);
			}
		);

		test( 'an empty hint is no hint', () => {
			expect(
				interpret( 401, { host_config_hint: '' } ).message
			).toMatch( /was not accepted/ );
		} );
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
		// Same origin as the page: CORS cannot be the cause, so it is not mentioned.
		expect( result.message ).not.toMatch( /CORS/ );
	} );

	test( 'a failure on another origin names CORS as a possible cause, not as the cause (E116)', async () => {
		const originalLocation = window.location;
		delete window.location;
		window.location = new URL(
			'https://admin.example.com/wp-admin/admin.php'
		);

		const crossOriginProduct = {
			...product,
			endpoint_url:
				'https://site.example.com/wp-json/location-weather/mcp',
		};

		let result;
		try {
			result = await testConnection( crossOriginProduct, login, {
				fetchImpl: jest.fn( () =>
					Promise.reject( new TypeError( 'Failed to fetch' ) )
				),
			} );
		} finally {
			window.location = originalLocation;
		}

		expect( result.state ).toBe( 'unreachable' );
		expect( result.message ).toMatch( /could not reach/ );
		expect( result.message ).toMatch( /possible cause/ );
		expect( result.message ).toMatch( /CORS/ );
		// A rejected fetch cannot tell a CORS block from a dead network, so it must not claim one.
		expect( result.message ).not.toMatch( /blocked this/ );
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

	test( 'a 401 carrying the server hint comes back as a host problem', async () => {
		const result = await testConnection( product, login, {
			fetchImpl: answer( 401, { host_config_hint: 'x' } ),
		} );

		expect( result.state ).toBe( 'rejected' );
		expect( result.message ).toMatch(
			/not passing the Authorization header/
		);
	} );

	test( 'the password is never put in the result', async () => {
		const result = await testConnection( product, login, {
			fetchImpl: answer( 401, null ),
		} );

		expect( JSON.stringify( result ) ).not.toContain( 'abcdefgh' );
		expect( JSON.stringify( result ) ).not.toContain( 'YWRtaW46' );
	} );
} );
