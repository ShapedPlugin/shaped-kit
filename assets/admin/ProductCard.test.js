/**
 * Tests for one product card, mounted for real.
 *
 * Risks covered: a switch that shows the click instead of the server's answer, a switch that can be
 * used where it cannot work, an endpoint copied wrongly, and a product's text treated as markup.
 */
import { createRoot } from '@wordpress/element';
import { act } from 'react';
import ProductCard from './ProductCard';

global.IS_REACT_ACT_ENVIRONMENT = true;

let container;
let root;

const product = ( overrides = {} ) => ( {
	slug: 'location-weather',
	name: 'Location Weather',
	edition: 'free',
	enabled: true,
	toggleable: true,
	tools_count: 5,
	endpoint_url: 'https://site.test/wp-json/location-weather/mcp',
	status: 'ready',
	message: '',
	adapter_version: '0.7.0',
	...overrides,
} );

const render = async ( props ) => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
	await act( async () => {
		root.render( <ProductCard onSwitch={ jest.fn() } { ...props } /> );
	} );
};

const switchInput = () => container.querySelector( 'input[role="switch"]' );

afterEach( () => {
	act( () => root.unmount() );
	container.remove();
} );

test( 'it shows the product, its edition, its status and its numbers', async () => {
	await render( { product: product() } );

	expect( container.querySelector( 'h2' ).textContent ).toBe(
		'Location Weather'
	);
	expect( container.querySelector( '.shaped-kit-badge' ).textContent ).toBe(
		'Free'
	);
	expect( container.querySelector( '.shaped-kit-pill' ).className ).toContain(
		'shaped-kit-pill-good'
	);
	expect( container.textContent ).toContain( 'Ready' );
	expect( container.querySelector( 'input[readonly]' ).value ).toBe(
		'https://site.test/wp-json/location-weather/mcp'
	);
} );

test( 'the switch shows what the server reported, and a click only asks for the opposite', async () => {
	const onSwitch = jest.fn();
	await render( { product: product( { enabled: true } ), onSwitch } );

	expect( switchInput().checked ).toBe( true );

	await act( async () => switchInput().click() );

	expect( onSwitch ).toHaveBeenCalledWith( 'location-weather', false );
	expect( switchInput().checked ).toBe( true );
} );

test( 'a switch that is off asks for on', async () => {
	const onSwitch = jest.fn();
	await render( {
		product: product( { enabled: false, status: 'disabled' } ),
		onSwitch,
	} );

	await act( async () => switchInput().click() );

	expect( onSwitch ).toHaveBeenCalledWith( 'location-weather', true );
} );

test( 'the switch is locked while a switch is in flight', async () => {
	const onSwitch = jest.fn();
	await render( { product: product(), busy: true, onSwitch } );

	expect( switchInput().disabled ).toBe( true );

	await act( async () => switchInput().click() );
	expect( onSwitch ).not.toHaveBeenCalled();
} );

test( 'a product with no switch of its own is locked and says where to turn it on', async () => {
	const onSwitch = jest.fn();
	await render( { product: product( { toggleable: false } ), onSwitch } );

	expect( switchInput().disabled ).toBe( true );
	expect( container.textContent ).toContain( "product's own settings" );

	await act( async () => switchInput().click() );
	expect( onSwitch ).not.toHaveBeenCalled();
} );

test( 'a product that can be switched has no such note', async () => {
	await render( { product: product() } );

	expect( container.textContent ).not.toContain( "product's own settings" );
} );

test( 'copy puts the endpoint on the clipboard', async () => {
	const writeText = jest.fn( () => Promise.resolve() );
	Object.assign( navigator, { clipboard: { writeText } } );
	await render( { product: product() } );

	const copy = [ ...container.querySelectorAll( 'button' ) ].find( ( b ) =>
		/Copy/.test( b.textContent )
	);
	await act( async () => copy.click() );

	expect( writeText ).toHaveBeenCalledWith(
		'https://site.test/wp-json/location-weather/mcp'
	);
	expect( container.textContent ).toContain( 'Copied' );
} );

test( 'a product without an endpoint has no endpoint row and no copy button', async () => {
	await render( { product: product( { endpoint_url: '' } ) } );

	expect( container.querySelector( 'input[readonly]' ) ).toBeNull();
	expect( container.textContent ).not.toContain( 'Copy' );
} );

test( 'a status the card does not know is shown as unknown, not as ready', async () => {
	await render( { product: product( { status: 'from_the_future' } ) } );

	expect( container.querySelector( '.shaped-kit-pill' ).className ).toContain(
		'shaped-kit-pill-warn'
	);
	expect( container.textContent ).toContain( 'Unknown' );
} );

test( 'text from a product is shown as text, never as markup', async () => {
	await render( {
		product: product( {
			name: '<img src=x onerror=alert(1)>',
			message: '<script>alert(1)</script>',
		} ),
	} );

	expect( container.querySelector( 'img' ) ).toBeNull();
	expect( container.querySelector( 'script' ) ).toBeNull();
	expect( container.querySelector( 'h2' ).textContent ).toContain( '<img' );
} );

test( 'it renders a tools toggle button and expands tools catalog with sample prompts', async () => {
	await render( { product: product() } );

	const toggleBtn = container.querySelector( '.shaped-kit-tools-toggle' );
	expect( toggleBtn ).not.toBeNull();
	expect( toggleBtn.textContent ).toContain( 'View tools & sample prompts' );
	expect( container.querySelector( '.shaped-kit-tools-catalog' ) ).toBeNull();

	await act( async () => toggleBtn.click() );

	expect( toggleBtn.textContent ).toContain( 'Hide tools list' );
	const catalog = container.querySelector( '.shaped-kit-tools-catalog' );
	expect( catalog ).not.toBeNull();
	expect( catalog.querySelectorAll( '.shaped-kit-tool-card' ).length ).toBeGreaterThan( 0 );
	expect( catalog.textContent ).toContain( 'location-weather/get-context' );
	expect( catalog.querySelectorAll( '.shaped-kit-prompt-item' ).length ).toBeGreaterThan( 0 );
} );

test( 'copying a prompt puts the prompt text on the clipboard', async () => {
	const writeText = jest.fn( () => Promise.resolve() );
	Object.assign( navigator, { clipboard: { writeText } } );

	await render( { product: product() } );
	const toggleBtn = container.querySelector( '.shaped-kit-tools-toggle' );
	await act( async () => toggleBtn.click() );

	const promptCopyBtn = container.querySelector( '.shaped-kit-prompt-copy-btn' );
	expect( promptCopyBtn ).not.toBeNull();

	await act( async () => promptCopyBtn.click() );

	expect( writeText ).toHaveBeenCalled();
	expect( promptCopyBtn.textContent ).toContain( 'Copied' );
} );

test( 'it renders sample JSON inputs and expected success outputs in tool cards', async () => {
	const writeText = jest.fn( () => Promise.resolve() );
	Object.assign( navigator, { clipboard: { writeText } } );

	await render( { product: product() } );
	const toggleBtn = container.querySelector( '.shaped-kit-tools-toggle' );
	await act( async () => toggleBtn.click() );

	const sampleBlocks = container.querySelectorAll( '.shaped-kit-tool-sample-block' );
	expect( sampleBlocks.length ).toBeGreaterThan( 0 );

	const copyJsonBtn = container.querySelector( '.shaped-kit-tool-sample-head .shaped-kit-prompt-copy-btn' );
	expect( copyJsonBtn ).not.toBeNull();
	expect( copyJsonBtn.textContent ).toContain( 'Copy JSON' );

	await act( async () => copyJsonBtn.click() );
	expect( writeText ).toHaveBeenCalled();
	expect( copyJsonBtn.textContent ).toContain( 'Copied' );

	const expectedDetails = container.querySelectorAll( '.shaped-kit-tool-expected-details' );
	expect( expectedDetails.length ).toBeGreaterThan( 0 );
	expect( container.textContent ).toContain( 'Expected Success Output (HTTP 200)' );
} );

test( 'it filters out Pro tools when edition is free and displays Pro badge when edition is pro', async () => {
	// 1. Free edition
	await render( { product: product( { edition: 'free', tools_count: 10 } ) } );
	const toggleBtn = container.querySelector( '.shaped-kit-tools-toggle' );
	await act( async () => toggleBtn.click() );

	const freeToolCards = container.querySelectorAll( '.shaped-kit-tool-card' );
	expect( freeToolCards.length ).toBe( 10 );
	expect( container.textContent ).not.toContain( 'location-weather/get-cloud-status' );

	// 2. Pro edition
	await render( { product: product( { edition: 'pro', tools_count: 11 } ) } );
	const toggleBtnPro = container.querySelector( '.shaped-kit-tools-toggle' );
	await act( async () => toggleBtnPro.click() );

	const proToolCards = container.querySelectorAll( '.shaped-kit-tool-card' );
	expect( proToolCards.length ).toBe( 11 );
	expect( container.textContent ).toContain( 'location-weather/get-cloud-status' );
	expect( container.querySelector( '.shaped-kit-badge-pro' ) ).not.toBeNull();
} );

