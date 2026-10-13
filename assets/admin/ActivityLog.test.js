import { createRoot } from '@wordpress/element';
import { act } from 'react';
import ActivityLog from './ActivityLog';
import { fetchLogs } from './api';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( './api', () => ( {
	describeError: jest.fn( ( err ) => err?.message || 'Error' ),
	fetchLogs: jest.fn(),
} ) );

describe( 'ActivityLog Component', () => {
	let container;
	let root;

	const sampleProducts = [
		{ slug: 'location-weather', name: 'Location Weather' },
		{ slug: 'real-testimonials', name: 'Real Testimonials' },
	];

	const sampleLogsResponse = {
		items: [
			{
				id: 1,
				created_at: '2026-10-13 09:00:00',
				user_id: 1,
				app_password_name: 'Claude Code',
				product: 'location-weather',
				ability: 'location-weather/get-context',
				outcome: 'ok',
				error_id: null,
				duration_ms: 12,
				argument_keys: [ 'id' ],
			},
			{
				id: 2,
				created_at: '2026-10-13 09:05:00',
				user_id: 1,
				app_password_name: 'Cursor AI',
				product: 'real-testimonials',
				ability: 'real-testimonials/get-testimonial',
				outcome: 'error',
				error_id: 'not_found',
				duration_ms: 45,
				argument_keys: [ 'id', 'status' ],
			},
		],
		total: 2,
		page: 1,
		per_page: 15,
		total_pages: 1,
	};

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
		jest.clearAllMocks();
		fetchLogs.mockResolvedValue( sampleLogsResponse );
	} );

	afterEach( async () => {
		if ( root ) {
			await act( async () => {
				root.unmount();
			} );
		}
		if ( container && container.parentNode ) {
			container.parentNode.removeChild( container );
		}
	} );

	it( 'renders table headers and fetched activity records', async () => {
		await act( async () => {
			root.render( <ActivityLog products={ sampleProducts } /> );
		} );

		expect( container.textContent ).toContain( 'location-weather/get-context' );
		expect( container.textContent ).toContain( 'real-testimonials/get-testimonial' );
		expect( container.textContent ).toContain( 'Claude Code' );
		expect( container.textContent ).toContain( 'Cursor AI' );
		expect( container.textContent ).toContain( '2 events logged' );
		expect( container.textContent ).toContain( '12ms' );
		expect( container.textContent ).toContain( '45ms' );
	} );

	it( 'filters logs by product', async () => {
		await act( async () => {
			root.render( <ActivityLog products={ sampleProducts } /> );
		} );

		const productSelect = container.querySelector( '#sk-filter-product' );
		expect( productSelect ).not.toBeNull();

		await act( async () => {
			productSelect.value = 'location-weather';
			productSelect.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );

		expect( fetchLogs ).toHaveBeenCalledWith(
			expect.objectContaining( {
				product: 'location-weather',
				page: 1,
			} )
		);
	} );

	it( 'filters logs by outcome', async () => {
		await act( async () => {
			root.render( <ActivityLog products={ sampleProducts } /> );
		} );

		const outcomeSelect = container.querySelector( '#sk-filter-outcome' );
		expect( outcomeSelect ).not.toBeNull();

		await act( async () => {
			outcomeSelect.value = 'error';
			outcomeSelect.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );

		expect( fetchLogs ).toHaveBeenCalledWith(
			expect.objectContaining( {
				outcome: 'error',
				page: 1,
			} )
		);
	} );

	it( 'displays empty state message when no logs are returned', async () => {
		fetchLogs.mockResolvedValueOnce( {
			items: [],
			total: 0,
			page: 1,
			per_page: 15,
			total_pages: 0,
		} );

		await act( async () => {
			root.render( <ActivityLog products={ sampleProducts } /> );
		} );

		expect( container.textContent ).toContain( 'No agent actions recorded yet.' );
	} );
} );
