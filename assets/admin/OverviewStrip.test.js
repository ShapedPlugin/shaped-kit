/**
 * Tests for the overview strip, mounted for real.
 *
 * Risks covered: a problem the owner must see not being shown, a healthy site shown a warning, and the
 * three numbers not matching what the server reported.
 */
import { createRoot } from '@wordpress/element';
import { act } from 'react';
import OverviewStrip from './OverviewStrip';

global.IS_REACT_ACT_ENVIRONMENT = true;

let container;
let root;

const render = async ( overview ) => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
	await act( async () => {
		root.render( <OverviewStrip overview={ overview } /> );
	} );
};

const healthy = ( overrides = {} ) => ( {
	wordpress_supported: true,
	abilities_api: true,
	adapter: {
		loaded: true,
		compatible: true,
		version: '0.7.0',
		source: { type: 'shaped-kit', name: 'shaped-kit', display_name: '' },
	},
	products: [
		{ slug: 'a', enabled: true, status: 'ready', tools_count: 5 },
		{ slug: 'b', enabled: false, status: 'disabled', tools_count: 9 },
	],
	...overrides,
} );

const tiles = () =>
	Object.fromEntries(
		[ ...container.querySelectorAll( '.shaped-kit-tile' ) ].map(
			( tile ) => [
				tile.querySelector( 'dt' ).textContent,
				tile.querySelector( 'dd' ).textContent,
			]
		)
	);

afterEach( () => {
	act( () => root.unmount() );
	container.remove();
} );

test( 'a healthy site shows three matching numbers and no warning', async () => {
	await render( healthy() );

	expect( tiles() ).toEqual( {
		'MCP adapter': 'Shaped Kit · 0.7.0',
		'Products on': '1 of 2',
		'Tools available': '5',
	} );
	expect( container.querySelector( '[role="status"]' ) ).toBeNull();
} );

test( 'a missing adapter is shown as a warning above the numbers', async () => {
	await render(
		healthy( {
			adapter: { loaded: false, compatible: false, version: null },
		} )
	);

	const notice = container.querySelector( '.shaped-kit-notice' );

	expect( notice.className ).toContain( 'shaped-kit-notice-warn' );
	expect( notice.textContent ).toMatch( /No MCP adapter is loaded/ );
	expect( tiles()[ 'MCP adapter' ] ).toBe( 'Not loaded' );
} );

test( 'a problem and an empty product list are both shown', async () => {
	await render( healthy( { products: [], wordpress_supported: false } ) );

	const texts = [ ...container.querySelectorAll( '.shaped-kit-notice' ) ].map(
		( notice ) => notice.textContent
	);

	expect( texts ).toHaveLength( 2 );
	expect( texts[ 0 ] ).toMatch( /WordPress 6\.9/ );
	expect( texts[ 1 ] ).toMatch( /No ShapedPlugin product/ );
	expect( tiles()[ 'Products on' ] ).toBe( '0 of 0' );
} );

test( 'text from a product is shown as text, never as markup', async () => {
	await render(
		healthy( {
			adapter: {
				loaded: true,
				compatible: true,
				version: '1',
				source: {
					type: 'plugin',
					name: 'x',
					display_name: '<img src=x onerror=alert(1)>',
				},
			},
		} )
	);

	expect( container.querySelector( 'img' ) ).toBeNull();
	expect( tiles()[ 'MCP adapter' ] ).toContain( '<img' );
} );
