import { createRoot } from '@wordpress/element';
import { act } from 'react';
import SharedProductSettingsTab from './SharedProductSettingsTab';

global.IS_REACT_ACT_ENVIRONMENT = true;

describe( 'SharedProductSettingsTab Component', () => {
	let container;
	let root;
	const originalFetch = global.fetch;

	const mount = async ( props ) => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
		await act( async () => {
			root.render( <SharedProductSettingsTab { ...props } /> );
		} );
	};

	const settle = async () => act( async () => {} );

	const deferred = () => {
		const handle = {};
		handle.promise = new Promise( ( resolve, reject ) => {
			handle.resolve = resolve;
			handle.reject = reject;
		} );
		return handle;
	};

	beforeEach( () => {
		jest.clearAllMocks();
	} );

	afterEach( () => {
		if ( root ) {
			act( () => root.unmount() );
			container.remove();
		}
		global.fetch = originalFetch;
	} );

	it( 'renders loading state and fetches product configuration', async () => {
		const pending = deferred();
		global.fetch = jest.fn().mockReturnValue( pending.promise );

		await mount( {
			restUrl: '/wp-json/shaped-kit/v1/mcp/location-weather-pro',
			productName: 'Location Weather Pro',
			productSlug: 'location-weather-pro',
		} );

		expect( container.textContent ).toContain( 'Loading MCP configuration…' );

		const mockSummary = {
			slug: 'location-weather-pro',
			name: 'Location Weather Pro',
			enabled: true,
			endpoint_url: 'http://localhost:10003/wp-json/mcp/v1/location-weather-pro',
			tools_count: 6,
			adapter: { version: '0.7.0' },
		};

		await act( async () => {
			pending.resolve( {
				ok: true,
				json: async () => mockSummary,
			} );
		} );
		await settle();

		expect( container.textContent ).toContain( 'MCP for AI Agents (Location Weather Pro)' );
		const toggle = container.querySelector( 'input[role="switch"]' );
		expect( toggle ).not.toBeNull();
		expect( toggle.checked ).toBe( true );
		expect( container.querySelector( '#sk-shared-endpoint-input' ).value ).toBe(
			'http://localhost:10003/wp-json/mcp/v1/location-weather-pro'
		);
		expect( container.textContent ).toContain( '6 abilities' );
		expect( container.textContent ).toContain( 'Open Shaped Kit Hub →' );
	} );

	it( 'handles toggle enable/disable requests', async () => {
		const mockInitial = {
			slug: 'testimonial-pro',
			name: 'Real Testimonials Pro',
			enabled: false,
			endpoint_url: 'http://localhost:10003/wp-json/mcp/v1/testimonial-pro',
			tools_count: 8,
		};

		const mockUpdated = {
			...mockInitial,
			enabled: true,
		};

		global.fetch = jest
			.fn()
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => mockInitial,
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => mockUpdated,
			} );

		await mount( {
			restUrl: '/wp-json/shaped-kit/v1/mcp/testimonial-pro',
			productName: 'Real Testimonials Pro',
			productSlug: 'testimonial-pro',
		} );

		await settle();

		const toggle = container.querySelector( 'input[role="switch"]' );
		expect( toggle.checked ).toBe( false );

		await act( async () => {
			toggle.click();
		} );
		await settle();

		expect( global.fetch ).toHaveBeenCalledWith(
			'/wp-json/shaped-kit/v1/mcp/testimonial-pro',
			expect.objectContaining( {
				method: 'POST',
				body: JSON.stringify( { enabled: true } ),
			} )
		);

		expect( toggle.checked ).toBe( true );
	} );

	it( 'renders error message when initial fetch fails', async () => {
		global.fetch = jest.fn().mockResolvedValue( {
			ok: false,
			status: 500,
			json: async () => ( {
				message: 'Database connection error',
				data: { status: 500 },
			} ),
		} );

		await mount( {
			restUrl: '/wp-json/shaped-kit/v1/mcp/fail',
			productName: 'Broken Plugin',
		} );

		await settle();

		expect( container.querySelector( '.notice-error' ).textContent ).toContain(
			'Database connection error'
		);
	} );

	it( 'handles copy buttons without throwing error', async () => {
		const mockSummary = {
			slug: 'location-weather-pro',
			name: 'Location Weather Pro',
			enabled: true,
			endpoint_url: 'http://localhost:10003/wp-json/mcp/v1/location-weather-pro',
			tools_count: 6,
		};

		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => mockSummary,
		} );

		Object.assign( navigator, {
			clipboard: {
				writeText: jest.fn().mockImplementation( () => Promise.resolve() ),
			},
		} );

		await mount( {
			restUrl: '/wp-json/shaped-kit/v1/mcp/location-weather-pro',
			productName: 'Location Weather Pro',
			productSlug: 'location-weather-pro',
		} );

		await settle();

		const buttons = [ ...container.querySelectorAll( 'button' ) ];
		const copyEndpointBtn = buttons.find( ( b ) => b.textContent.includes( 'Copy Endpoint' ) );
		const copyCommandBtn = buttons.find( ( b ) => b.textContent.includes( 'Copy Command' ) );

		expect( copyEndpointBtn ).toBeDefined();
		expect( copyCommandBtn ).toBeDefined();

		await act( async () => {
			copyEndpointBtn.click();
		} );
		expect( navigator.clipboard.writeText ).toHaveBeenCalledWith(
			'http://localhost:10003/wp-json/mcp/v1/location-weather-pro'
		);

		await act( async () => {
			copyCommandBtn.click();
		} );
		expect( navigator.clipboard.writeText ).toHaveBeenCalledWith(
			expect.stringContaining( 'claude mcp add' )
		);
	} );
} );
