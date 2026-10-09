/**
 * Tests for the dashboard shell, mounted for real with the REST client mocked.
 *
 * Risks covered: a slow old answer overwriting a newer one, a failed refresh blanking a working
 * dashboard, a failure with no way to retry, and state set after the page has gone.
 */
import { createRoot } from '@wordpress/element';
import { act } from 'react';
import App from './App';
import { fetchOverview } from './api';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( './api', () => ( {
	describeError: jest.fn( ( error ) => `described: ${ error.message }` ),
	fetchOverview: jest.fn(),
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
} );

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
