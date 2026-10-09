/**
 * Tests for the REST client.
 *
 * Risks covered: a request sent to a broken address when WordPress uses plain permalinks, a switch sent
 * the wrong way round, and an error that gives the owner no usable sentence.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	createAppPassword,
	describeError,
	fetchOverview,
	routeUrl,
	switchProduct,
} from './api';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn( () => Promise.resolve( {} ) ),
} ) );

beforeEach( () => {
	apiFetch.mockClear();
	window.shapedKitAdmin = {
		restUrl: 'https://site.test/wp-json/shaped-kit/v1/mcp',
	};
} );

describe( 'routeUrl', () => {
	test( 'adds to the end for pretty permalinks', () => {
		expect(
			routeUrl(
				'https://site.test/wp-json/shaped-kit/v1/mcp',
				'app-password'
			)
		).toBe( 'https://site.test/wp-json/shaped-kit/v1/mcp/app-password' );
	} );

	test( 'adds to the end for plain permalinks, without a second question mark', () => {
		const url = routeUrl(
			'https://site.test/index.php?rest_route=/shaped-kit/v1/mcp',
			'products/location-weather'
		);

		expect( url ).toBe(
			'https://site.test/index.php?rest_route=/shaped-kit/v1/mcp/products/location-weather'
		);
		expect( url.split( '?' ) ).toHaveLength( 2 );
	} );

	test( 'ignores a trailing slash on the base', () => {
		expect( routeUrl( 'https://site.test/x/mcp/', 'a' ) ).toBe(
			'https://site.test/x/mcp/a'
		);
	} );
} );

describe( 'requests', () => {
	test( 'the overview is a plain read of the page address', async () => {
		await fetchOverview();

		expect( apiFetch ).toHaveBeenCalledWith( {
			url: 'https://site.test/wp-json/shaped-kit/v1/mcp',
		} );
	} );

	test( 'switching sends the asked state as a real boolean, and encodes the slug', async () => {
		await switchProduct( 'location-weather', false );
		await switchProduct( 'a/b', 1 );

		expect( apiFetch ).toHaveBeenNthCalledWith( 1, {
			url: 'https://site.test/wp-json/shaped-kit/v1/mcp/products/location-weather',
			method: 'POST',
			data: { enabled: false },
		} );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].url ).toMatch(
			/products\/a%2Fb$/
		);
		expect( apiFetch.mock.calls[ 1 ][ 0 ].data ).toEqual( {
			enabled: true,
		} );
	} );

	test( 'a password request sends a name only when there is one', async () => {
		await createAppPassword();
		await createAppPassword( 'Laptop' );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {} );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].data ).toEqual( {
			name: 'Laptop',
		} );
	} );
} );

describe( 'describeError', () => {
	test.each( [
		[ { code: 'fetch_error', message: 'x' }, /Could not reach the server/ ],
		[
			{ code: 'rest_cookie_invalid_nonce', message: 'x' },
			/session has expired/,
		],
		[
			{ code: 'rest_forbidden', message: 'x', data: { status: 401 } },
			/session has expired/,
		],
		[
			{ code: 'rest_forbidden', message: 'x', data: { status: 403 } },
			/Only an administrator/,
		],
		[
			{ code: 'rest_no_route', message: 'No route was found' },
			/Update the plugin/,
		],
		[ undefined, /Something went wrong/ ],
		[ new Error( 'boom' ), /Something went wrong/ ],
	] )( '%j reads as %s', ( error, expected ) => {
		expect( describeError( error ) ).toMatch( expected );
	} );

	test( 'a refusal the server worded itself is shown as it came', () => {
		expect(
			describeError( {
				code: 'shaped_kit_not_toggleable',
				message: 'This product has to be switched in its own settings.',
				data: { status: 409 },
			} )
		).toBe( 'This product has to be switched in its own settings.' );
	} );

	test( 'a message without an error status is not trusted to be for the owner', () => {
		expect(
			describeError( { code: 'x', message: 'TypeError: a is undefined' } )
		).toBe( 'Something went wrong. Try again.' );
	} );
} );
