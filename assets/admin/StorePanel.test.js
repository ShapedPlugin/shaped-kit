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

const setInputValue = ( input, value ) => {
	const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	nativeInputValueSetter.call( input, value );
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
};

const mockCatalog = {
	version: 1,
	timestamp: 1729000000,
	catalog_source: 'remote',
	synced_at: 1729339200,
	synced_label: '2:40 pm',
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
			beta_newer: true,
			mcp_active: false,
			has_update: false,
		},
		{
			slug: 'testimonial-pro',
			name: 'Real Testimonials Pro',
			edition: 'pro',
			product_family: 'real-testimonials',
			settings_path: 'edit.php?post_type=spt_testimonial&page=rtp_dashboard',
			description: 'Showcase customer reviews.',
			stable_version: '3.0.0',
			installed: true,
			active: true,
			active_version: '3.0.0',
			file: 'testimonial-pro/testimonial-pro.php',
			can_install: true,
			has_license: true,
			license_status: 'valid',
			beta_version: null,
			mcp_active: true,
			mcp_enabled: true,
			mcp_tools_count: 14,
			has_update: false,
		},
	],
};

/**
 * One product family of a Free and a Pro entry, for the version and license tests.
 *
 * @param {Object} free Fields to change on the Free entry.
 * @param {Object} pro  Fields to change on the Pro entry.
 * @return {Object} A catalog response.
 */
const familyCatalog = ( free = {}, pro = {} ) => ( {
	synced_at: '12:00',
	products: [
		{
			slug: 'location-weather',
			name: 'Location Weather',
			edition: 'free',
			product_family: 'location-weather',
			description: 'Weather widgets.',
			stable_version: '3.0.7',
			installed: true,
			active: false,
			active_version: '3.0.7',
			can_install: true,
			has_license: false,
			beta_version: null,
			has_update: false,
			...free,
		},
		{
			slug: 'location-weather-pro',
			name: 'Location Weather Pro',
			edition: 'pro',
			product_family: 'location-weather',
			description: 'Pro weather.',
			stable_version: '3.5.0',
			installed: true,
			active: true,
			active_version: '3.5.0',
			can_install: true,
			has_license: true,
			license_status: 'valid',
			beta_version: null,
			has_update: false,
			...pro,
		},
	],
} );

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

describe( 'StorePanel Component (Unified Products Hub)', () => {
	test( 'loads and renders products in row-based table', async () => {
		fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );
		await mount();

		expect( fetchStoreCatalog ).toHaveBeenCalledWith( false );
		expect( container.textContent ).toContain( 'Products & Store' );
		expect( container.textContent ).toContain( 'Manage, install, and update official ShapedPlugin tools' );
		expect( container.textContent ).toContain( 'Location Weather Free' );
		expect( container.textContent ).toContain( 'Real Testimonials Pro' );
		expect( container.textContent ).toContain( 'Not installed' );
		expect( container.textContent ).toContain( 'Active' );
		expect( container.textContent ).toContain( 'License active' );
		expect( container.textContent ).toContain( 'Pro v3.0.0' );
	} );

	test( 'shows one quiet AI tools link per plugin, with its count, and no pills', async () => {
		fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );
		await mount();

		expect( container.querySelectorAll( '.shaped-kit-pill' ).length ).toBe( 0 );

		const links = container.querySelectorAll( 'a.shaped-kit-fact-ai' );
		expect( links.length ).toBe( 1 );
		expect( links[ 0 ].textContent ).toBe( 'AI tools · 14 ›' );
		expect( links[ 0 ].getAttribute( 'href' ) ).toBe( 'admin.php?page=shaped-kit&connect=real-testimonials' );
		expect( links[ 0 ].getAttribute( 'aria-label' ) ).toBe( 'Set up AI tools for Real Testimonials Pro' );
	} );

	test( 'says "off" when the plugin has AI tools but its MCP switch is off', async () => {
		fetchStoreCatalog.mockResolvedValueOnce(
			familyCatalog( {}, { mcp_active: true, mcp_enabled: false, mcp_tools_count: 11 } )
		);
		await mount();

		expect( container.querySelector( 'a.shaped-kit-fact-ai' ).textContent ).toBe( 'AI tools · off ›' );
	} );

	test( 'never prints a license key, even if the server sends one', async () => {
		fetchStoreCatalog.mockResolvedValueOnce(
			familyCatalog( {}, { masked_license: '••••••••••••0d2b', license_key: 'SECRET-KEY-0d2b' } )
		);
		await mount();

		expect( container.textContent ).toContain( 'License active' );
		expect( container.textContent ).not.toContain( '0d2b' );
		expect( container.textContent ).not.toContain( '••••' );
		expect( container.textContent ).not.toContain( 'SECRET' );
	} );

	test( 'states the license as active, expired or not active, and only active is green', async () => {
		const textFor = async ( pro ) => {
			fetchStoreCatalog.mockResolvedValueOnce( familyCatalog( {}, pro ) );
			await mount();
			const span = container.querySelector( '.shaped-kit-fact-license-status' );
			const out = { text: span.textContent, active: span.classList.contains( 'is-active' ) };
			await act( async () => root.unmount() );
			container.remove();
			root = null;
			return out;
		};

		expect( await textFor( {} ) ).toEqual( { text: 'License active', active: true } );
		expect( await textFor( { license_status: 'expired' } ) ).toEqual( { text: 'License expired', active: false } );
		expect( await textFor( { has_license: false, license_status: 'none' } ) ).toEqual( {
			text: 'License not active',
			active: false,
		} );
	} );

	test( 'shows one labelled version: the Pro when it is installed, the Free otherwise', async () => {
		fetchStoreCatalog.mockResolvedValueOnce( familyCatalog() );
		await mount();

		const row = container.querySelector( '.shaped-kit-hub-row' );
		expect( row.querySelector( '.shaped-kit-fact-version' ).textContent ).toBe( 'Pro v3.5.0' );
		expect( row.textContent ).not.toContain( 'v3.0.7' );

		await act( async () => root.unmount() );
		container.remove();

		fetchStoreCatalog.mockResolvedValueOnce( familyCatalog( { active: true }, { installed: false, active: false, active_version: null } ) );
		await mount();
		expect( container.querySelector( '.shaped-kit-fact-version' ).textContent ).toBe( 'v3.0.7' );
	} );

	test( 'shows the version a pending update would move to', async () => {
		fetchStoreCatalog.mockResolvedValueOnce(
			familyCatalog( {}, { has_update: true, update_version: '3.6.0' } )
		);
		await mount();

		expect( container.querySelector( '.shaped-kit-fact-version' ).textContent ).toBe( 'Pro v3.5.0 → v3.6.0' );
		const view = container.querySelector( 'a[href="update-core.php"]' );
		expect( view.textContent ).toBe( 'View updates' );
	} );

	test( 'announces an install error as an alert and a success as a status', async () => {
		fetchStoreCatalog.mockResolvedValue( mockCatalog );
		installFreeProduct.mockRejectedValueOnce( new Error( 'A folder named "location-weather" already exists.' ) );
		await mount();

		const installBtn = Array.from( container.querySelectorAll( 'button' ) ).find(
			( btn ) => btn.textContent.trim() === 'Install'
		);
		await act( async () => {
			installBtn.click();
		} );

		const alert = container.querySelector( '[role="alert"]' );
		expect( alert ).toBeTruthy();
		expect( alert.textContent ).toContain( 'already exists' );
		expect( container.querySelector( '[role="status"]' ) ).toBeNull();

		installFreeProduct.mockResolvedValueOnce( { slug: 'location-weather' } );
		await act( async () => {
			installBtn.click();
		} );

		expect( container.querySelector( '[role="alert"]' ) ).toBeNull();
		expect( container.querySelector( '[role="status"]' ).textContent ).toContain( 'location-weather' );
	} );

	test( 'every row action names its plugin for screen readers', async () => {
		fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );
		await mount();

		const labels = Array.from( container.querySelectorAll( '.shaped-kit-hub-row [aria-label]' ) ).map( ( el ) =>
			el.getAttribute( 'aria-label' )
		);
		expect( labels ).toContain( 'Install Location Weather Free' );
		expect( labels ).toContain( 'Try the beta of Location Weather Free' );
		expect( labels ).toContain( 'Settings for Real Testimonials Pro' );
	} );

	test( 'the license and beta boxes are dialogs named by their headings', async () => {
		fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );
		await mount();

		const betaBtn = Array.from( container.querySelectorAll( 'button' ) ).find(
			( btn ) => btn.textContent.trim() === 'Try beta'
		);
		await act( async () => {
			betaBtn.click();
		} );

		const dialog = container.querySelector( '[role="dialog"]' );
		expect( dialog ).toBeTruthy();
		expect( dialog.getAttribute( 'aria-modal' ) ).toBe( 'true' );
		const title = container.querySelector( `#${ dialog.getAttribute( 'aria-labelledby' ) }` );
		expect( title.textContent ).toContain( 'Install Beta' );
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
			( btn ) => btn.textContent.trim() === 'Install'
		);
		expect( installBtn ).toBeTruthy();

		await act( async () => {
			installBtn.click();
		} );

		expect( installFreeProduct ).toHaveBeenCalledWith( 'location-weather' );
	} );

	test( 'handles product activation for installed inactive product', async () => {
		const inactiveCatalog = {
			...mockCatalog,
			products: [
				{
					...mockCatalog.products[ 0 ],
					installed: true,
					active: false,
					active_version: '2.1.0',
				},
				mockCatalog.products[ 1 ],
			],
		};
		fetchStoreCatalog.mockResolvedValueOnce( inactiveCatalog );
		await mount();

		activateProduct.mockResolvedValueOnce( {
			success: true,
			slug: 'location-weather',
		} );
		fetchStoreCatalog.mockResolvedValueOnce( {
			...inactiveCatalog,
			products: [
				{
					...inactiveCatalog.products[ 0 ],
					active: true,
				},
				inactiveCatalog.products[ 1 ],
			],
		} );

		const activateBtn = Array.from( container.querySelectorAll( 'button' ) ).find(
			( btn ) => btn.textContent.trim() === 'Activate'
		);
		expect( activateBtn ).toBeTruthy();

		await act( async () => {
			activateBtn.click();
		} );

		expect( activateProduct ).toHaveBeenCalledWith( 'location-weather' );
	} );

	test( 'opens pro license modal and handles submission', async () => {
		const uninstalledProCatalog = {
			...mockCatalog,
			products: [
				{
					slug: 'location-weather',
					name: 'Location Weather Free',
					edition: 'free',
					description: 'Real-time weather forecasts.',
					stable_version: '2.1.0',
					installed: true,
					active: true,
					active_version: '2.1.0',
					file: 'location-weather/location-weather.php',
					can_install: true,
					has_license: false,
					beta_version: null,
					has_update: false,
				},
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
					has_update: false,
				},
			],
		};
		fetchStoreCatalog.mockResolvedValueOnce( uninstalledProCatalog );
		await mount();

		const proBtn = Array.from( container.querySelectorAll( 'button' ) ).find(
			( btn ) => btn.textContent.trim() === 'Install Pro'
		);
		expect( proBtn ).toBeTruthy();

		await act( async () => {
			proBtn.click();
		} );

		// Modal should now be open
		expect( container.textContent ).toContain( 'Install Location Weather Pro' );
		const licenseInput = container.querySelector( '#shaped-kit-license-key-input' );
		expect( licenseInput ).toBeTruthy();

		// Submit with license key
		installProProduct.mockResolvedValueOnce( {
			success: true,
			slug: 'location-weather-pro',
		} );
		fetchStoreCatalog.mockResolvedValueOnce( uninstalledProCatalog );

		await act( async () => {
			setInputValue( licenseInput, 'TEST-KEY-12345' );
		} );

		const submitBtn = Array.from( container.querySelectorAll( '.shaped-kit-modal button' ) ).find(
			( btn ) => btn.textContent.trim() === 'Verify & Install'
		);
		expect( submitBtn ).toBeTruthy();

		await act( async () => {
			submitBtn.click();
		} );

		expect( installProProduct ).toHaveBeenCalledWith( 'location-weather-pro', 'TEST-KEY-12345' );
	} );

	test( 'opens beta warning modal on beta button click', async () => {
		fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );
		await mount();

		const betaBtn = Array.from( container.querySelectorAll( 'button' ) ).find(
			( btn ) => btn.textContent.trim() === 'Try beta'
		);
		expect( betaBtn ).toBeTruthy();

		await act( async () => {
			betaBtn.click();
		} );

		// Beta warning modal should now be open
		expect( container.textContent ).toContain( 'Install Beta: 2.2.0-beta.1' );
		expect( container.textContent ).toContain( 'Beta versions contain early preview features' );

		installBetaProduct.mockResolvedValueOnce( {
			success: true,
			slug: 'location-weather',
		} );
		fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );

		const confirmBtn = Array.from( container.querySelectorAll( '.shaped-kit-modal button' ) ).find(
			( btn ) => btn.textContent.trim() === 'I Understand, Install Beta'
		);
		expect( confirmBtn ).toBeTruthy();

		await act( async () => {
			confirmBtn.click();
		} );

		expect( installBetaProduct ).toHaveBeenCalledWith( 'location-weather', '' );
	} );

	test( 'filters products by tab and search input', async () => {
		fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );
		await mount();

		// Initially both products are displayed
		expect( container.textContent ).toContain( 'Location Weather Free' );
		expect( container.textContent ).toContain( 'Real Testimonials Pro' );

		// Click "Installed" tab
		const installedTabBtn = Array.from( container.querySelectorAll( '.shaped-kit-tab-btn' ) ).find(
			( btn ) => btn.textContent.includes( 'Installed' )
		);
		expect( installedTabBtn ).toBeTruthy();

		await act( async () => {
			installedTabBtn.click();
		} );

		// Location Weather (not installed) should be filtered out
		expect( container.textContent ).not.toContain( 'Location Weather Free' );
		expect( container.textContent ).toContain( 'Real Testimonials Pro' );

		// Switch back to "All" tab
		const allTabBtn = Array.from( container.querySelectorAll( '.shaped-kit-tab-btn' ) ).find(
			( btn ) => btn.textContent.includes( 'All' )
		);
		await act( async () => {
			allTabBtn.click();
		} );
		expect( container.textContent ).toContain( 'Location Weather Free' );

		// Test search input
		const searchInput = container.querySelector( '.shaped-kit-search-input' );
		expect( searchInput ).toBeTruthy();

		await act( async () => {
			setInputValue( searchInput, 'forecasts' );
		} );

		expect( container.textContent ).toContain( 'Location Weather Free' );
		expect( container.textContent ).not.toContain( 'Real Testimonials Pro' );
	} );

	test( 'has no developer-preview banner above the list', async () => {
		fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );
		await mount();

		expect( container.textContent ).not.toContain( 'DevOps Release Pipeline' );
		expect( container.querySelector( '.shaped-kit-store-devops-notice' ) ).toBeNull();
		expect( container.querySelector( '.notice-info' ) ).toBeNull();
	} );

	describe( 'the Settings link', () => {
		const settingsLink = () =>
			Array.from( container.querySelectorAll( '.shaped-kit-row-action a' ) ).find( ( a ) => a.textContent.trim() === 'Settings' );

		test( 'goes to the settings page the catalog names', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );
			await mount();

			expect( settingsLink().getAttribute( 'href' ) ).toBe( 'edit.php?post_type=spt_testimonial&page=rtp_dashboard' );
		} );

		test( 'is not shown when the plugin has no settings page', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( familyCatalog( {}, { settings_path: null } ) );
			await mount();

			expect( container.querySelector( '.shaped-kit-status-label' ).textContent ).toBe( 'Active' );
			expect( settingsLink() ).toBeUndefined();
		} );

		test( 'is not shown for a path that is not a plain admin page', async () => {
			for ( const bad of [ 'https://evil.example/admin.php', '//evil.example/x.php', 'javascript:alert(1)', '../wp-login.php', '/wp-admin/admin.php' ] ) {
				fetchStoreCatalog.mockResolvedValueOnce( familyCatalog( {}, { settings_path: bad } ) );
				await mount();
				expect( settingsLink() ).toBeUndefined();
				await act( async () => root.unmount() );
				container.remove();
				root = null;
			}
		} );

		test( 'falls back to the Free entry when the Pro has none', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				familyCatalog( { settings_path: 'admin.php?page=free-settings' }, { settings_path: null } )
			);
			await mount();

			expect( settingsLink().getAttribute( 'href' ) ).toBe( 'admin.php?page=free-settings' );
		} );
	} );

	describe( 'Try beta', () => {
		const tryBeta = () =>
			Array.from( container.querySelectorAll( 'button' ) ).find( ( b ) => b.textContent.trim() === 'Try beta' );

		test( 'shows only when the server says the beta is newer than what is installed', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				familyCatalog( {}, { beta_version: '3.1.0-beta1', beta_newer: false } )
			);
			await mount();
			expect( tryBeta() ).toBeUndefined();
		} );

		test( 'offers the Pro beta when the Pro is installed and it is newer', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				familyCatalog( { beta_version: '3.1.0-beta1', beta_newer: true }, { beta_version: '3.6.0-beta1', beta_newer: true } )
			);
			await mount();
			await act( async () => {
				tryBeta().click();
			} );

			expect( container.textContent ).toContain( 'Install Beta: 3.6.0-beta1' );
		} );

		test( 'never offers the Free beta while the Pro is installed', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				familyCatalog( { beta_version: '3.1.0-beta1', beta_newer: true }, { beta_version: null, beta_newer: false } )
			);
			await mount();
			expect( tryBeta() ).toBeUndefined();
		} );

		test( 'offers the Free beta when the Pro is not installed', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				familyCatalog(
					{ active: true, beta_version: '3.1.0-beta1', beta_newer: true },
					{ installed: false, active: false, active_version: null, beta_version: '3.6.0-beta1', beta_newer: true }
				)
			);
			await mount();
			await act( async () => {
				tryBeta().click();
			} );

			expect( container.textContent ).toContain( 'Install Beta: 3.1.0-beta1' );
		} );
	} );

	describe( 'the sync note', () => {
		test( 'shows the time the catalog was fetched', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );
			await mount();
			expect( container.querySelector( '.shaped-kit-sync-time' ).textContent ).toBe( '· synced 2:40 pm' );
		} );

		test( 'says built-in list, with no time, when the remote catalog was not used', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( { ...mockCatalog, catalog_source: 'builtin', synced_at: null, synced_label: '' } );
			await mount();
			expect( container.querySelector( '.shaped-kit-sync-time' ).textContent ).toBe( '· built-in list' );
		} );

		test( 'shows nothing when a remote catalog has no known fetch time', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( { ...mockCatalog, synced_at: null, synced_label: '' } );
			await mount();
			expect( container.querySelector( '.shaped-kit-sync-time' ) ).toBeNull();
		} );
	} );

	describe( 'the dialogs', () => {
		const press = ( key, extra = {} ) =>
			act( async () => {
				document.dispatchEvent( new KeyboardEvent( 'keydown', { key, bubbles: true, ...extra } ) );
			} );

		const openProDialog = async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				familyCatalog( { active: true }, { installed: false, active: false, active_version: null } )
			);
			await mount();
			const opener = Array.from( container.querySelectorAll( 'button' ) ).find( ( b ) => b.textContent.trim() === 'Install Pro' );
			opener.focus();
			await act( async () => {
				opener.click();
			} );
			return opener;
		};

		test( 'Escape closes the dialog and focus returns to the button that opened it', async () => {
			const opener = await openProDialog();
			expect( container.querySelector( '[role="dialog"]' ) ).toBeTruthy();

			await press( 'Escape' );

			expect( container.querySelector( '[role="dialog"]' ) ).toBeNull();
			expect( document.activeElement ).toBe( opener );
		} );

		test( 'focus moves into the dialog when it opens', async () => {
			await openProDialog();
			expect( document.activeElement ).toBe( container.querySelector( '#shaped-kit-license-key-input' ) );
		} );

		test( 'Tab stays inside the dialog from either end', async () => {
			await openProDialog();
			const input = container.querySelector( '#shaped-kit-license-key-input' );
			const cancel = Array.from( container.querySelectorAll( '.shaped-kit-modal button' ) ).find( ( b ) => b.textContent.trim() === 'Cancel' );

			expect( document.activeElement ).toBe( input );
			await press( 'Tab', { shiftKey: true } );
			expect( document.activeElement ).toBe( cancel );
			await press( 'Tab' );
			expect( document.activeElement ).toBe( input );
		} );

		test( 'Escape does nothing while the install is running', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( mockCatalog );
			await mount();
			installBetaProduct.mockReturnValueOnce( new Promise( () => {} ) );

			const beta = Array.from( container.querySelectorAll( 'button' ) ).find( ( b ) => b.textContent.trim() === 'Try beta' );
			await act( async () => {
				beta.click();
			} );
			const confirm = Array.from( container.querySelectorAll( '.shaped-kit-modal button' ) ).find(
				( b ) => b.textContent.trim() === 'I Understand, Install Beta'
			);
			await act( async () => {
				confirm.click();
			} );

			await press( 'Escape' );

			expect( container.querySelector( '[role="dialog"]' ) ).toBeTruthy();
			expect( container.textContent ).toContain( 'Installing Beta' );
		} );
	} );

	describe( 'groups and "Needs …" states', () => {
		/**
		 * A free catalog entry that is not installed.
		 *
		 * @param {string} slug Plugin slug.
		 * @param {Object} over Fields to change.
		 * @return {Object} The entry.
		 */
		const plugin = ( slug, over = {} ) => ( {
			slug,
			name: slug,
			edition: 'free',
			product_family: slug,
			description: `${ slug } description.`,
			stable_version: '1.0.0',
			installed: false,
			active: false,
			active_version: null,
			can_install: true,
			has_license: false,
			beta_version: null,
			has_update: false,
			priority: 100,
			requires_plugins: [],
			unmet_plugins: [],
			needs_php: null,
			needs_wp: null,
			...over,
		} );

		const rowFor = ( name ) =>
			Array.from( container.querySelectorAll( '.shaped-kit-hub-row' ) ).find(
				( r ) => r.querySelector( '.shaped-kit-row-name' ).textContent === name
			);
		const actionOf = ( row ) => row.querySelector( '.shaped-kit-row-action .button' );
		const statusOf = ( row ) => row.querySelector( '.shaped-kit-status-label' ).textContent;
		const catalogOf = ( ...products ) => ( { catalog_source: 'remote', synced_label: '', products } );

		test( 'shows WordPress and WooCommerce groups, each with its count, when both exist', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				catalogOf(
					plugin( 'wp-one', { name: 'WP One' } ),
					plugin( 'wp-two', { name: 'WP Two' } ),
					plugin( 'woo-one', { name: 'Woo One', requires_plugins: [ 'woocommerce' ] } )
				)
			);
			await mount();

			const titles = Array.from( container.querySelectorAll( '.shaped-kit-group-title' ) ).map( ( h ) => h.textContent );
			expect( titles ).toEqual( [ 'WordPress(2)', 'WooCommerce(1)' ] );

			const wpGroup = container.querySelector( '#shaped-kit-group-wordpress' ).closest( 'section' );
			expect( wpGroup.textContent ).toContain( 'WP One' );
			expect( wpGroup.textContent ).not.toContain( 'Woo One' );
		} );

		test( 'the WooCommerce note shows only while WooCommerce is missing or inactive', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				catalogOf(
					plugin( 'wp-one', { name: 'WP One' } ),
					plugin( 'woo-one', { name: 'Woo One', requires_plugins: [ 'woocommerce' ], unmet_plugins: [ 'WooCommerce' ] } )
				)
			);
			await mount();

			const titles = Array.from( container.querySelectorAll( '.shaped-kit-group-title' ) ).map( ( h ) => h.textContent );
			expect( titles ).toEqual( [ 'WordPress(1)', 'WooCommerce(1)needs the WooCommerce plugin' ] );
		} );

		test( 'shows no group headings when every plugin is in one group', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( catalogOf( plugin( 'a', { name: 'A' } ), plugin( 'b', { name: 'B' } ) ) );
			await mount();

			expect( container.querySelectorAll( '.shaped-kit-group-title' ).length ).toBe( 0 );
			expect( container.querySelectorAll( '.shaped-kit-hub-row' ).length ).toBe( 2 );
		} );

		test( 'a plugin that needs WooCommerce says so and offers no Install', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				catalogOf( plugin( 'woo-one', { name: 'Woo One', requires_plugins: [ 'woocommerce' ], unmet_plugins: [ 'WooCommerce' ] } ) )
			);
			await mount();

			const row = rowFor( 'Woo One' );
			expect( statusOf( row ) ).toBe( 'Needs WooCommerce' );
			expect( actionOf( row ) ).toBeNull();
			expect( row.classList.contains( 'is-blocked' ) ).toBe( true );
		} );

		test( 'a plugin installed but inactive, with its dependency missing, offers no Activate', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				catalogOf(
					plugin( 'woo-one', {
						name: 'Woo One',
						installed: true,
						active_version: '1.0.0',
						requires_plugins: [ 'woocommerce' ],
						unmet_plugins: [ 'WooCommerce' ],
					} )
				)
			);
			await mount();

			const row = rowFor( 'Woo One' );
			expect( statusOf( row ) ).toBe( 'Needs WooCommerce' );
			expect( actionOf( row ) ).toBeNull();
		} );

		test( 'a plugin whose dependency is active installs normally', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				catalogOf( plugin( 'woo-one', { name: 'Woo One', requires_plugins: [ 'woocommerce' ], unmet_plugins: [] } ) )
			);
			await mount();

			const row = rowFor( 'Woo One' );
			expect( statusOf( row ) ).toBe( 'Not installed' );
			expect( actionOf( row ).textContent ).toBe( 'Install' );
		} );

		test( 'a site too old says what it needs, PHP before WordPress before a plugin, and offers no Install', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				catalogOf(
					plugin( 'p', { name: 'P', needs_php: '8.1', needs_wp: '6.8', unmet_plugins: [ 'WooCommerce' ] } ),
					plugin( 'w', { name: 'W', needs_wp: '6.8', unmet_plugins: [ 'WooCommerce' ] } )
				)
			);
			await mount();

			expect( statusOf( rowFor( 'P' ) ) ).toBe( 'Needs PHP 8.1' );
			expect( actionOf( rowFor( 'P' ) ) ).toBeNull();
			expect( statusOf( rowFor( 'W' ) ) ).toBe( 'Needs WordPress 6.8' );
		} );

		test( 'an installed plugin is not held back by the PHP or WordPress it was already installed on', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				catalogOf( plugin( 'old', { name: 'Old', installed: true, active: true, active_version: '1.0.0', needs_php: '8.1' } ) )
			);
			await mount();

			expect( statusOf( rowFor( 'Old' ) ) ).toBe( 'Active' );
		} );

		test( 'an installed plugin that is switched off still offers Activate, whatever PHP or WordPress it needs', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				catalogOf( plugin( 'off', { name: 'Off', installed: true, active_version: '1.0.0', needs_php: '8.1', needs_wp: '6.8' } ) )
			);
			await mount();

			expect( statusOf( rowFor( 'Off' ) ) ).toBe( 'Inactive' );
			expect( actionOf( rowFor( 'Off' ) ).textContent ).toBe( 'Activate' );
		} );

		test( 'Install Pro is not offered while the Free is blocked', async () => {
			fetchStoreCatalog.mockResolvedValueOnce(
				catalogOf(
					plugin( 'woo-one', { name: 'Woo One', requires_plugins: [ 'woocommerce' ], unmet_plugins: [ 'WooCommerce' ] } ),
					plugin( 'woo-one-pro', { name: 'Woo One Pro', edition: 'pro', product_family: 'woo-one' } )
				)
			);
			await mount();

			expect( Array.from( container.querySelectorAll( 'button' ) ).some( ( b ) => b.textContent.trim() === 'Install Pro' ) ).toBe( false );
		} );
	} );

	describe( 'order and AI tools kinds', () => {
		const plugin = ( slug, over = {} ) => ( {
			slug,
			name: slug,
			edition: 'free',
			product_family: slug,
			description: 'd',
			stable_version: '1.0.0',
			installed: false,
			active: false,
			active_version: null,
			can_install: true,
			has_license: false,
			beta_version: null,
			has_update: false,
			priority: 100,
			...over,
		} );
		const names = () =>
			Array.from( container.querySelectorAll( '.shaped-kit-row-name' ) ).map( ( n ) => n.textContent );

		test( 'sorts by the catalog priority, then by name, with AI tools plugins first', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( {
				catalog_source: 'remote',
				products: [
					plugin( 'c', { name: 'Charlie', priority: 10 } ),
					plugin( 'b2', { name: 'Bravo Two', priority: 20 } ),
					plugin( 'b1', { name: 'Bravo One', priority: 20 } ),
					plugin( 'z', { name: 'Zulu', priority: 90, mcp_active: true, mcp_enabled: true, mcp_tools_count: 5 } ),
					plugin( 'a', { name: 'Alpha', priority: 30 } ),
				],
			} );
			await mount();

			// Priority beats the name (Charlie 10 before Alpha 30); the name breaks a tie (the two Bravos at 20).
			expect( names() ).toEqual( [ 'Zulu', 'Charlie', 'Bravo One', 'Bravo Two', 'Alpha' ] );
		} );

		test( 'a plugin that is not installed but ships AI tools says "included", and is not a link', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( {
				catalog_source: 'remote',
				products: [ plugin( 'x', { name: 'X', mcp_available: true } ) ],
			} );
			await mount();

			expect( container.querySelector( '.shaped-kit-fact-ai-note' ).textContent ).toBe( 'AI tools included' );
			expect( container.querySelector( 'a.shaped-kit-fact-ai' ) ).toBeNull();
		} );

		test( 'an installed plugin older than the first release with AI tools says "after update"', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( {
				catalog_source: 'remote',
				products: [ plugin( 'x', { name: 'X', installed: true, active: true, active_version: '1.0.0', mcp_after_update: true } ) ],
			} );
			await mount();

			expect( container.querySelector( '.shaped-kit-fact-ai-note' ).textContent ).toBe( 'AI tools after update' );
		} );

		test( 'the AI tools tab counts and lists all three kinds', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( {
				catalog_source: 'remote',
				products: [
					plugin( 'live', { name: 'Live', mcp_active: true, mcp_enabled: true, mcp_tools_count: 3 } ),
					plugin( 'avail', { name: 'Avail', mcp_available: true } ),
					plugin( 'after', { name: 'After', installed: true, active: true, active_version: '1.0.0', mcp_after_update: true } ),
					plugin( 'none', { name: 'None' } ),
				],
			} );
			await mount();

			const tab = Array.from( container.querySelectorAll( '.shaped-kit-tab-btn' ) ).find( ( b ) => b.textContent.startsWith( 'AI tools' ) );
			expect( tab.textContent ).toBe( 'AI tools 3' );

			await act( async () => {
				tab.click();
			} );
			expect( names().sort() ).toEqual( [ 'After', 'Avail', 'Live' ] );
		} );
	} );

	describe( 'initials', () => {
		const plugin = ( slug, name ) => ( {
			slug,
			name,
			edition: 'free',
			product_family: slug,
			description: 'd',
			stable_version: '1.0.0',
			installed: false,
			active: false,
			active_version: null,
			can_install: true,
			beta_version: null,
			has_update: false,
			priority: 100,
		} );

		test( 'a CamelCase one-word name gives its first two capitals, other names their first two words', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( {
				catalog_source: 'remote',
				products: [
					plugin( 'a', 'ShapedCart' ),
					plugin( 'b', 'ShapedGallery' ),
					plugin( 'c', 'Styble' ),
					plugin( 'd', 'Smart Tabs' ),
					plugin( 'e', 'WP Carousel' ),
				],
			} );
			await mount();

			const initials = {};
			container.querySelectorAll( '.shaped-kit-hub-row' ).forEach( ( row ) => {
				initials[ row.querySelector( '.shaped-kit-row-name' ).textContent ] = row.querySelector( '.shaped-kit-row-icon' ).textContent;
			} );
			expect( initials ).toEqual( {
				ShapedCart: 'SC',
				ShapedGallery: 'SG',
				Styble: 'S',
				'Smart Tabs': 'ST',
				'WP Carousel': 'WC',
			} );
		} );
	} );

	describe( 'a family with a Pro installed', () => {
		const entry = ( slug, over = {} ) => ( {
			slug,
			name: slug,
			edition: 'free',
			product_family: 'wpc',
			description: 'Carousels.',
			stable_version: '4.0.1',
			installed: false,
			active: false,
			active_version: null,
			can_install: true,
			has_license: false,
			beta_version: null,
			has_update: false,
			priority: 100,
			unmet_plugins: [],
			...over,
		} );
		const detectedPro = ( over = {} ) =>
			entry( 'wpc-pro', {
				name: 'WPC Pro',
				edition: 'pro',
				detected: true,
				installed: true,
				active: true,
				active_version: '5.0.1',
				stable_version: '5.0.1',
				can_install: false,
				...over,
			} );
		const row = () => container.querySelector( '.shaped-kit-hub-row' );
		const action = () => row().querySelector( '.shaped-kit-row-action .button' );
		const status = () => row().querySelector( '.shaped-kit-status-label' ).textContent;
		const buttons = () => Array.from( row().querySelectorAll( 'button' ) ).map( ( b ) => b.textContent.trim() );

		test( 'a Pro the catalog does not describe reads as installed, with no license, no changelog and no Install', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( {
				catalog_source: 'remote',
				products: [ entry( 'wpc-free', { name: 'WPC' } ), detectedPro() ],
			} );
			await mount();

			expect( container.querySelectorAll( '.shaped-kit-hub-row' ).length ).toBe( 1 );
			expect( row().querySelector( '.shaped-kit-row-name' ).textContent ).toBe( 'WPC Pro' );
			expect( status() ).toBe( 'Active' );
			expect( row().querySelector( '.shaped-kit-fact-version' ).textContent ).toBe( 'Pro v5.0.1' );
			expect( row().textContent ).not.toMatch( /License/ );
			expect( row().textContent ).not.toMatch( /Changelog/ );
			expect( buttons() ).not.toContain( 'Install' );
			expect( action().textContent ).toBe( 'Plugins' );
			expect( action().getAttribute( 'href' ) ).toBe( 'plugins.php' );
		} );

		test( 'an inactive detected Pro says Inactive and offers no Activate', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( {
				catalog_source: 'remote',
				products: [ entry( 'wpc-free', { name: 'WPC' } ), detectedPro( { active: false } ) ],
			} );
			await mount();

			expect( status() ).toBe( 'Inactive' );
			expect( buttons() ).not.toContain( 'Activate' );
			expect( action().textContent ).toBe( 'Plugins' );
		} );

		test( 'the Free\'s Settings page is never offered for a detected Pro, even with the Free active', async () => {
			// The Free stands down while the Pro runs, and its page then answers 403 (seen on a real site).
			fetchStoreCatalog.mockResolvedValueOnce( {
				catalog_source: 'remote',
				products: [
					entry( 'wpc-free', { name: 'WPC', installed: true, active: true, active_version: '4.0.0', settings_path: 'admin.php?page=wpc' } ),
					detectedPro(),
				],
			} );
			await mount();

			expect( action().textContent ).toBe( 'Plugins' );
			expect( row().querySelector( 'a[href="admin.php?page=wpc"]' ) ).toBeNull();
		} );

		test( 'an update to either edition shows, and only the Pro\'s version is ever written on the row', async () => {
			// The Free alone has an update: the Pro needs it current, so the row says so, without a version arrow.
			fetchStoreCatalog.mockResolvedValueOnce( {
				catalog_source: 'remote',
				products: [
					entry( 'wpc-free', { name: 'WPC', installed: true, active: false, active_version: '4.0.0', has_update: true, update_version: '4.0.1' } ),
					detectedPro(),
				],
			} );
			await mount();
			expect( status() ).toBe( 'Update available' );
			expect( action().textContent ).toBe( 'View updates' );
			expect( row().querySelector( '.shaped-kit-fact-version' ).textContent ).toBe( 'Pro v5.0.1' );

			fetchStoreCatalog.mockResolvedValueOnce( {
				catalog_source: 'remote',
				products: [
					entry( 'wpc-free', { name: 'WPC', installed: true, active_version: '4.0.0' } ),
					detectedPro( { has_update: true, update_version: '5.0.2' } ),
				],
			} );
			await mount();
			expect( status() ).toBe( 'Update available' );
			expect( row().querySelector( '.shaped-kit-fact-version' ).textContent ).toBe( 'Pro v5.0.1 → v5.0.2' );
			expect( action().textContent ).toBe( 'View updates' );

			// The Pro has an update but WordPress named no version: the Free's version is never borrowed.
			fetchStoreCatalog.mockResolvedValueOnce( {
				catalog_source: 'remote',
				products: [
					entry( 'wpc-free', { name: 'WPC', installed: true, active_version: '4.0.0', has_update: true, update_version: '4.0.1' } ),
					detectedPro( { has_update: true, update_version: null } ),
				],
			} );
			await mount();
			expect( status() ).toBe( 'Update available' );
			expect( row().querySelector( '.shaped-kit-fact-version' ).textContent ).toBe( 'Pro v5.0.1' );
		} );
	} );

	describe( 'the changelog link', () => {
		const entry = ( over ) => ( {
			slug: 'x',
			name: 'X',
			edition: 'free',
			product_family: 'x',
			description: 'd',
			stable_version: '1.0.0',
			installed: false,
			can_install: true,
			...over,
		} );
		const link = () =>
			Array.from( container.querySelectorAll( '.shaped-kit-row-facts a' ) ).find( ( a ) => a.textContent.startsWith( 'Changelog' ) );

		test( 'uses the address the catalog names', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( { catalog_source: 'remote', products: [ entry( { changelog_url: 'https://shapedplugin.com/x/changelog/' } ) ] } );
			await mount();
			expect( link().getAttribute( 'href' ) ).toBe( 'https://shapedplugin.com/x/changelog/' );
		} );

		test( 'falls back to the wordpress.org page of the Free plugin', async () => {
			fetchStoreCatalog.mockResolvedValueOnce( { catalog_source: 'remote', products: [ entry( {} ) ] } );
			await mount();
			expect( link().getAttribute( 'href' ) ).toBe( 'https://wordpress.org/plugins/x/#developers' );
		} );

		test( 'ignores an address that is not https', async () => {
			for ( const bad of [ 'http://shapedplugin.com/x', 'javascript:alert(1)' ] ) {
				fetchStoreCatalog.mockResolvedValueOnce( { catalog_source: 'remote', products: [ entry( { changelog_url: bad } ) ] } );
				await mount();
				expect( link().getAttribute( 'href' ) ).toBe( 'https://wordpress.org/plugins/x/#developers' );
				await act( async () => root.unmount() );
				container.remove();
				root = null;
			}
		} );
	} );
} );
