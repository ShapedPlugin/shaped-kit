import { createRoot } from '@wordpress/element';
import { act } from 'react';
import StorePanel from './StorePanel';
import {
	activateProduct,
	fetchStoreCatalog,
	installBetaProduct,
	installFreeProduct,
	installProProduct,
} from './api';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( './api', () => ( {
	describeError: jest.fn( ( error ) => ( error && error.message ) ? error.message : 'Error occurred' ),
	fetchStoreCatalog: jest.fn(),
	installFreeProduct: jest.fn(),
	activateProduct: jest.fn(),
	installProProduct: jest.fn(),
	installBetaProduct: jest.fn(),
} ) );

let container;
let root;

const mount = async () => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
	await act( async () => {
		root.render( <StorePanel /> );
	} );
};

const mockCatalog = {
	version: 1,
	timestamp: 1729000000,
	products: [
		{
			slug: 'location-weather',
			name: 'Location Weather Free',
			edition: 'free',
			description: 'Real-time weather forecasts.',
			stable_version: '2.1.0',
			installed: false,
			active: false,
			active_version: null,
			file: 'location-weather/location-weather.php',
			can_install: true,
			has_license: false,
			beta_version: '2.2.0-beta.1',
		},
		{
			slug: 'testimonial-pro',
			name: 'Real Testimonials Pro',
			edition: 'pro',
			description: 'Showcase customer reviews.',
			stable_version: '3.0.0',
			installed: true,
			active: true,
			active_version: '3.0.0',
			file: 'testimonial-pro/testimonial-pro.php',
			can_install: true,
			has_license: true,
			license_status: 'valid',
			masked_license: '••••••••••••1a2b',
			beta_version: null,
		},
	],
};

beforeEach( () => {
	fetchStoreCatalog.mockReset();
	installFreeProduct.mockReset();
	activateProduct.mockReset();
	installProProduct.mockReset();
	installBetaProduct.mockReset();
} );

afterEach( () => {
	if ( root ) {
		act( () => {
			root.unmount();
		} );
	}
	if ( container && container.parentNode ) {
		container.parentNode.removeChild( container );
	}
} );

describe( 'StorePanel Component', () => {
	test( 'loads and renders products from catalog', async () => {
		fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );
		await mount();

		expect( fetchStoreCatalog ).toHaveBeenCalledWith( false );
		expect( container.textContent ).toContain( 'ShapedPlugin Products & Add-ons' );
		expect( container.textContent ).toContain( 'Location Weather Free' );
		expect( container.textContent ).toContain( 'Real Testimonials Pro' );
		expect( container.textContent ).toContain( '••••••••••••1a2b' );
	} );

	test( 'handles free product installation', async () => {
		fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );
		await mount();

		installFreeProduct.mockResolvedValueOnce( {
			success: true,
			slug: 'location-weather',
		} );
		fetchStoreCatalog.mockResolvedValueOnce( {
			...mockCatalog,
			products: [
				{
					...mockCatalog.products[ 0 ],
					installed: true,
					active: true,
					active_version: '2.1.0',
				},
				mockCatalog.products[ 1 ],
			],
		} );

		const installBtn = Array.from( container.querySelectorAll( 'button' ) ).find(
			( btn ) => btn.textContent === 'Install & Activate'
		);
		expect( installBtn ).toBeTruthy();

		await act( async () => {
			installBtn.click();
		} );

		expect( installFreeProduct ).toHaveBeenCalledWith( 'location-weather' );
	} );

	test( 'opens pro license modal on button click', async () => {
		const uninstalledProCatalog = {
			...mockCatalog,
			products: [
				{
					slug: 'location-weather-pro',
					name: 'Location Weather Pro',
					edition: 'pro',
					description: 'Pro forecasts.',
					stable_version: '2.5.0',
					installed: false,
					active: false,
					active_version: null,
					file: 'location-weather-pro/location-weather-pro.php',
					can_install: true,
					has_license: false,
					beta_version: null,
				},
			],
		};
		fetchStoreCatalog.mockResolvedValueOnce( uninstalledProCatalog );
		await mount();

		const proBtn = Array.from( container.querySelectorAll( 'button' ) ).find(
			( btn ) => btn.textContent === 'Install with License'
		);
		expect( proBtn ).toBeTruthy();

		await act( async () => {
			proBtn.click();
		} );

		// Modal should now be open
		expect( container.textContent ).toContain( 'Install Location Weather Pro' );
		const licenseInput = container.querySelector( '#shaped-kit-license-key-input' );
		expect( licenseInput ).toBeTruthy();
	} );

	test( 'opens beta warning modal on beta button click', async () => {
		fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );
		await mount();

		const betaBtn = Array.from( container.querySelectorAll( 'button' ) ).find(
			( btn ) => btn.textContent.includes( 'Install Beta' )
		);
		expect( betaBtn ).toBeTruthy();

		await act( async () => {
			betaBtn.click();
		} );

		// Beta warning modal should now be open
		expect( container.textContent ).toContain( 'Install Beta: 2.2.0-beta.1' );
		expect( container.textContent ).toContain( 'Beta versions contain early preview features' );
	} );
} );
