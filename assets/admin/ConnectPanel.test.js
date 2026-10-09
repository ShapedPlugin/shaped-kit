/**
 * Tests for the connect panel, mounted for real with the REST client mocked.
 *
 * Risks covered: text that does not match what was typed or created, a snippet for a product that is
 * off, a button that tries to create a password where WordPress will not, a failure that looks like
 * success, and a self-signed-certificate setting switched on for a real site.
 */
import { createRoot } from '@wordpress/element';
import { act } from 'react';
import ConnectPanel, { isLocalSite } from './ConnectPanel';
import { createAppPassword } from './api';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( './api', () => ( {
	describeError: jest.fn( ( error ) => `described: ${ error.message }` ),
	createAppPassword: jest.fn(),
} ) );

let container;
let root;

const product = ( overrides = {} ) => ( {
	slug: 'location-weather',
	name: 'Location Weather',
	enabled: true,
	endpoint_url: 'https://site.test/wp-json/location-weather/mcp',
	...overrides,
} );

const overview = ( overrides = {} ) => ( {
	products: [ product() ],
	app_password: {
		available: true,
		reason: '',
		user_login: 'admin',
		manage_url: 'https://site.test/wp-admin/profile.php',
	},
	...overrides,
} );

const render = async ( data ) => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
	await act( async () => {
		root.render( <ConnectPanel overview={ data } /> );
	} );
};

const settle = async () => act( async () => {} );
const button = ( pattern ) =>
	[ ...container.querySelectorAll( 'button' ) ].find( ( b ) =>
		pattern.test( b.textContent )
	);
const snippet = () => container.querySelector( 'pre code' )?.textContent;
const passwordInput = () =>
	container.querySelector( '.shaped-kit-password input' );
const usernameInput = () =>
	container.querySelector( '.shaped-kit-credentials input' );

/** Type into a controlled React input. */
const type = async ( input, value ) => {
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	await act( async () => {
		setter.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
};

beforeEach( () => {
	createAppPassword.mockReset();
} );

afterEach( () => {
	if ( root ) {
		act( () => root.unmount() );
		container.remove();
		root = undefined;
	}
} );

describe( 'isLocalSite', () => {
	test.each( [
		[ 'http://localhost:10003/wp-json/x', true ],
		[ 'https://127.0.0.1/x', true ],
		[ 'https://mysite.test/x', true ],
		[ 'https://shop.local/x', true ],
		[ 'https://example.com/x', false ],
		[ 'https://notlocalhost.com/x', false ],
		[ 'https://test.example.com/x', false ],
		[ 'not a url', false ],
		[ '', false ],
	] )( '%s is local: %s', ( url, expected ) => {
		expect( isLocalSite( url ) ).toBe( expected );
	} );
} );

test( 'it starts with the signed-in username, placeholders for the password, and the Claude Code text', async () => {
	await render( overview() );

	expect( usernameInput().value ).toBe( 'admin' );
	expect( passwordInput().value ).toBe( '' );
	expect( passwordInput().type ).toBe( 'password' );
	expect( snippet() ).toContain( 'claude mcp add' );
	expect( snippet() ).toContain( '<base64(' );
} );

test( 'typing a password fills the text in, and Show reveals the field', async () => {
	await render( overview() );

	await type( passwordInput(), 'abcd efgh ijkl mnop qrst uvwx' );

	expect( snippet() ).not.toContain( '<base64(' );
	expect( snippet() ).toContain( 'YWRtaW46YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4' );

	await act( async () => button( /Show/ ).click() );
	expect( passwordInput().type ).toBe( 'text' );
} );

test( 'creating a password fills both fields, says it is shown once, and the text matches', async () => {
	createAppPassword.mockResolvedValueOnce( {
		username: 'admin',
		password: 'abcd efgh ijkl mnop qrst uvwx',
		name: 'Shaped Kit MCP',
		uuid: 'u',
	} );
	await render( overview() );

	await act( async () => button( /Create application password/ ).click() );
	await settle();

	expect( passwordInput().value ).toBe( 'abcd efgh ijkl mnop qrst uvwx' );
	expect(
		container.querySelector( '[role="status"]' ).textContent
	).toContain( 'only once' );
	expect( snippet() ).toContain( 'YWRtaW46YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4' );
} );

test( 'a failed create shows the sentence and fills nothing', async () => {
	createAppPassword.mockRejectedValueOnce( new Error( 'refused' ) );
	await render( overview() );

	await act( async () => button( /Create application password/ ).click() );
	await settle();

	expect( container.querySelector( '[role="alert"]' ).textContent ).toBe(
		'described: refused'
	);
	expect( passwordInput().value ).toBe( '' );
	expect( container.querySelector( '[role="status"]' ) ).toBeNull();
	expect( button( /Create application password/ ).disabled ).toBe( false );
} );

test( 'where WordPress will not make one, the button is off, the reason is given and nothing is called', async () => {
	await render(
		overview( {
			app_password: {
				available: false,
				reason: 'site_disabled',
				user_login: 'admin',
				manage_url: 'https://site.test/wp-admin/profile.php',
			},
		} )
	);

	const create = button( /Create application password/ );
	expect( create.disabled ).toBe( true );
	expect( container.textContent ).toContain( 'needs HTTPS' );

	await act( async () => create.click() );
	expect( createAppPassword ).not.toHaveBeenCalled();
	expect(
		[ ...container.querySelectorAll( 'a' ) ].find( ( a ) =>
			/Manage passwords/.test( a.textContent )
		).href
	).toBe( 'https://site.test/wp-admin/profile.php' );
} );

test( 'only products that are on go into the text', async () => {
	await render(
		overview( {
			products: [
				product(),
				product( {
					slug: 'real-testimonials',
					name: 'Real Testimonials',
					enabled: false,
					endpoint_url:
						'https://site.test/wp-json/real-testimonials/mcp',
				} ),
			],
		} )
	);

	expect( snippet() ).toContain( 'location-weather' );
	expect( snippet() ).not.toContain( 'real-testimonials' );
} );

test( 'with nothing switched on there is no snippet and no copy button', async () => {
	await render( overview( { products: [ product( { enabled: false } ) ] } ) );

	expect( snippet() ).toBeUndefined();
	expect( button( /Copy snippet/ ) ).toBeUndefined();
	expect( container.textContent ).toContain( 'Turn on a product above' );
} );

test( 'choosing a client changes the text, and Copy puts exactly that text on the clipboard', async () => {
	const writeText = jest.fn( () => Promise.resolve() );
	Object.assign( navigator, { clipboard: { writeText } } );
	await render( overview() );

	await act( async () =>
		container.querySelectorAll( '[role="tab"]' )[ 3 ].click()
	);
	expect( snippet() ).toContain( '"mcpServers"' );
	expect( snippet() ).toContain( '"type": "http"' );

	await act( async () => button( /Copy snippet/ ).click() );

	expect( writeText ).toHaveBeenCalledWith( snippet() );
	expect( container.textContent ).toContain( 'Copied' );
} );

test( 'on a local site the self-signed option is already ticked, on a real site it is not', async () => {
	const desktop = () =>
		[ ...container.querySelectorAll( '[role="tab"]' ) ].find( ( t ) =>
			/Claude Desktop/.test( t.textContent )
		);

	await render(
		overview( {
			products: [
				product( { endpoint_url: 'http://localhost:10003/m' } ),
			],
		} )
	);
	await act( async () => desktop().click() );
	expect( container.querySelector( '.shaped-kit-check input' ).checked ).toBe(
		true
	);
	expect( snippet() ).toContain( 'NODE_TLS_REJECT_UNAUTHORIZED' );

	act( () => root.unmount() );
	container.remove();

	await render(
		overview( {
			products: [
				product( { endpoint_url: 'https://example.com/wp-json/m' } ),
			],
		} )
	);
	await act( async () => desktop().click() );
	expect( container.querySelector( '.shaped-kit-check input' ).checked ).toBe(
		false
	);
	expect( snippet() ).not.toContain( 'NODE_TLS_REJECT_UNAUTHORIZED' );
} );

test( 'the self-signed option is offered only for the client that has it', async () => {
	await render( overview() );

	expect( container.querySelector( '.shaped-kit-check' ) ).toBeNull();
} );

test( 'a second try that fails does not leave the first try\'s "created" notice beside the error', async () => {
	createAppPassword
		.mockResolvedValueOnce( {
			username: 'admin',
			password: 'abcd efgh ijkl mnop qrst uvwx',
			name: 'Shaped Kit MCP',
			uuid: 'u',
		} )
		.mockRejectedValueOnce( new Error( 'refused' ) );
	await render( overview() );

	await act( async () => button( /Create application password/ ).click() );
	await settle();
	expect( container.querySelector( '[role="status"]' ) ).not.toBeNull();

	await act( async () => button( /Create application password/ ).click() );
	await settle();

	expect( container.querySelector( '[role="status"]' ) ).toBeNull();
	expect( container.querySelector( '[role="alert"]' ) ).not.toBeNull();
} );
