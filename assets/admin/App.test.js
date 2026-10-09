/**
 * Tests for the dashboard shell, mounted for real with the REST client mocked.
 *
 * Risks covered: a slow old answer overwriting a newer one, a failed refresh blanking a working
 * dashboard, a failure with no way to retry, and state set after the page has gone.
 */
import { createRoot } from '@wordpress/element';
import { act } from 'react';
import App from './App';
import { fetchOverview, switchProduct } from './api';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( './api', () => ( {
	describeError: jest.fn( ( error ) => `described: ${ error.message }` ),
	fetchOverview: jest.fn(),
	switchProduct: jest.fn(),
} ) );

let container;
let root;

/** A promise settled from outside. */
const deferred = () => {
	const handle = {};
	handle.promise = new Promise( ( resolve, reject ) => {
		handle.resolve = resolve;
		handle.reject = reject;
	} );
	return handle;
};

const mount = async () => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
	await act( async () => {
		root.render( <App /> );
	} );
};

const settle = async () => act( async () => {} );
const refreshButton = () =>
	[ ...container.querySelectorAll( 'button' ) ].find( ( b ) =>
		/Refresh/.test( b.textContent )
	);
const loaded = () => container.querySelector( '[data-loaded="true"]' );

beforeEach( () => {
	fetchOverview.mockReset();
	switchProduct.mockReset();
} );

/** An overview with one product that is on or off, as the server would report it. */
const site = ( on ) => ( {
	wordpress_supported: true,
	abilities_api: true,
	adapter: {
		loaded: true,
		compatible: true,
		version: '0.7.0',
		source: { type: 'shaped-kit' },
	},
	products: [
		{
			slug: 'location-weather',
			name: 'Location Weather',
			enabled: on,
			toggleable: true,
			status: on ? 'ready' : 'disabled',
			tools_count: 5,
			endpoint_url: 'https://site.test/mcp',
		},
	],
} );

const switchInput = () => container.querySelector( 'input[role="switch"]' );

afterEach( () => {
	act( () => root.unmount() );
	container.remove();
} );

test( 'it reads the overview on load and shows it', async () => {
	fetchOverview.mockResolvedValueOnce( { products: [] } );

	await mount();
	await settle();

	expect( fetchOverview ).toHaveBeenCalledTimes( 1 );
	expect( loaded() ).not.toBeNull();
	expect( container.textContent ).not.toContain( 'Loading…' );
	expect( container.querySelector( '[role="alert"]' ) ).toBeNull();
} );

test( 'what the server reported reaches the screen, and a refresh replaces it', async () => {
	const overview = ( on ) => ( {
		wordpress_supported: true,
		abilities_api: true,
		adapter: {
			loaded: true,
			compatible: true,
			version: '0.7.0',
			source: { type: 'shaped-kit' },
		},
		products: [
			{
				slug: 'a',
				enabled: on,
				status: on ? 'ready' : 'disabled',
				tools_count: 5,
			},
		],
	} );
	fetchOverview
		.mockResolvedValueOnce( overview( true ) )
		.mockResolvedValueOnce( overview( false ) );

	await mount();
	await settle();
	expect( container.textContent ).toContain( '1 of 1' );

	await act( async () => refreshButton().click() );
	await settle();
	expect( container.textContent ).toContain( '0 of 1' );
} );

test( 'while the first read is pending it says so, and refresh is locked', async () => {
	const pending = deferred();
	fetchOverview.mockReturnValueOnce( pending.promise );

	await mount();

	expect( container.textContent ).toContain( 'Loading…' );
	expect( refreshButton().disabled ).toBe( true );
	expect( loaded() ).toBeNull();

	await act( async () => pending.resolve( { products: [] } ) );
} );

test( 'a first read that fails shows the sentence and a way to try again', async () => {
	fetchOverview
		.mockRejectedValueOnce( new Error( 'offline' ) )
		.mockResolvedValueOnce( { products: [] } );

	await mount();
	await settle();

	expect( container.querySelector( '[role="alert"]' ).textContent ).toContain(
		'described: offline'
	);
	expect( loaded() ).toBeNull();

	const retry = [ ...container.querySelectorAll( 'button' ) ].find( ( b ) =>
		/Try again/.test( b.textContent )
	);
	await act( async () => retry.click() );
	await settle();

	expect( container.querySelector( '[role="alert"]' ) ).toBeNull();
	expect( loaded() ).not.toBeNull();
} );

test( 'a refresh that fails keeps the last good dashboard and shows the error above it', async () => {
	fetchOverview
		.mockResolvedValueOnce( { products: [] } )
		.mockRejectedValueOnce( new Error( 'timeout' ) );

	await mount();
	await settle();
	await act( async () => refreshButton().click() );
	await settle();

	expect( loaded() ).not.toBeNull();
	expect( container.querySelector( '[role="alert"]' ).textContent ).toContain(
		'described: timeout'
	);
} );

test( 'there is a card for each product', async () => {
	fetchOverview.mockResolvedValueOnce( site( true ) );

	await mount();
	await settle();

	expect(
		container.querySelectorAll( '.shaped-kit-cards .shaped-kit-card' )
	).toHaveLength( 1 );
	expect( container.querySelector( 'h2' ).textContent ).toBe(
		'Location Weather'
	);
} );

test( 'a switch asks for the opposite state, is locked meanwhile, and ends on the answer the server sent', async () => {
	const pending = deferred();
	fetchOverview.mockResolvedValueOnce( site( true ) );
	switchProduct.mockReturnValueOnce( pending.promise );

	await mount();
	await settle();
	await act( async () => switchInput().click() );

	expect( switchProduct ).toHaveBeenCalledWith( 'location-weather', false );
	expect( switchInput().disabled ).toBe( true );
	expect( switchInput().checked ).toBe( true );

	await act( async () => {
		pending.resolve( site( false ) );
	} );
	await settle();

	expect( switchInput().disabled ).toBe( false );
	expect( switchInput().checked ).toBe( false );
	expect( container.textContent ).toContain( '0 of 1' );
} );

test( 'a switch that fails shows the error and reads the site again instead of trusting the card', async () => {
	fetchOverview
		.mockResolvedValueOnce( site( true ) )
		.mockResolvedValueOnce( site( true ) );
	switchProduct.mockRejectedValueOnce( new Error( 'refused' ) );

	await mount();
	await settle();
	await act( async () => switchInput().click() );
	await settle();

	expect( container.querySelector( '[role="alert"]' ).textContent ).toContain(
		'described: refused'
	);
	expect( fetchOverview ).toHaveBeenCalledTimes( 2 );
	expect( switchInput().checked ).toBe( true );
	expect( switchInput().disabled ).toBe( false );
} );

test( 'a refresh that answers after a newer switch is dropped, and Refresh does not stay stuck', async () => {
	const slowRefresh = deferred();
	fetchOverview
		.mockResolvedValueOnce( site( true ) )
		.mockReturnValueOnce( slowRefresh.promise );
	switchProduct.mockResolvedValueOnce( site( false ) );

	await mount();
	await settle();

	await act( async () => refreshButton().click() );
	expect( refreshButton().textContent ).toBe( 'Refreshing…' );

	// The switch starts while the refresh is still out, and finishes first.
	await act( async () => switchInput().click() );
	await settle();
	expect( switchInput().checked ).toBe( false );

	// The old refresh answers last, with the state from before the switch.
	await act( async () => {
		slowRefresh.resolve( site( true ) );
	} );
	await settle();

	expect( switchInput().checked ).toBe( false );
	expect( refreshButton().textContent ).toBe( 'Refresh' );
	expect( refreshButton().disabled ).toBe( false );
} );

test( 'a switch that answers after a newer refresh is dropped', async () => {
	const slowSwitch = deferred();
	fetchOverview
		.mockResolvedValueOnce( site( true ) )
		.mockResolvedValueOnce( site( false ) );
	switchProduct.mockReturnValueOnce( slowSwitch.promise );

	await mount();
	await settle();

	await act( async () => switchInput().click() );
	await act( async () => refreshButton().click() );
	await settle();
	expect( switchInput().checked ).toBe( false );

	// The switch's own, older answer arrives last and must not undo what the refresh found.
	await act( async () => {
		slowSwitch.resolve( site( true ) );
	} );
	await settle();

	expect( switchInput().checked ).toBe( false );
	expect( switchInput().disabled ).toBe( false );
} );

test( 'the connect panel sits under the cards and is built from the same overview', async () => {
	fetchOverview.mockResolvedValueOnce( {
		...site( true ),
		app_password: {
			available: true,
			reason: '',
			user_login: 'admin',
			manage_url: 'https://site.test/wp-admin/profile.php',
		},
	} );

	await mount();
	await settle();

	const panel = container.querySelector( '.shaped-kit-connect' );
	expect( panel ).not.toBeNull();
	expect(
		container
			.querySelector( '.shaped-kit-cards' )
			.compareDocumentPosition( panel ) & Node.DOCUMENT_POSITION_FOLLOWING
	).toBeTruthy();
	expect( panel.querySelector( 'input' ).value ).toBe( 'admin' );
	expect( panel.querySelector( 'pre code' ).textContent ).toContain(
		'https://site.test/mcp'
	);
} );

test( 'switching the last product off takes its text out of the panel', async () => {
	fetchOverview.mockResolvedValueOnce( site( true ) );
	switchProduct.mockResolvedValueOnce( site( false ) );

	await mount();
	await settle();
	expect( container.querySelector( 'pre code' ) ).not.toBeNull();

	await act( async () => switchInput().click() );
	await settle();

	expect( container.querySelector( 'pre code' ) ).toBeNull();
	expect( container.textContent ).toContain( 'Turn on a product above' );
} );
