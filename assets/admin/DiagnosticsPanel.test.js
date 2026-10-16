import { createRoot } from '@wordpress/element';
import { act } from 'react';
import DiagnosticsPanel from './DiagnosticsPanel';
import { testConnection } from './connectionTest';

global.IS_REACT_ACT_ENVIRONMENT = true;

// Only the network call is replaced; the pure helpers (tone, header summary) stay real.
jest.mock( './connectionTest', () => ( {
	...jest.requireActual( './connectionTest' ),
	testConnection: jest.fn(),
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
	environment: {
		type: 'production',
		is_ssl: true,
		app_passwords_available: true,
	},
	app_password: {
		available: true,
		user_login: 'admin',
	},
	products: [ product() ],
	...overrides,
} );

const render = async ( data = overview() ) => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
	await act( async () => {
		root.render( <DiagnosticsPanel overview={ data } /> );
	} );
};

const settle = async () => act( async () => {} );

beforeEach( () => {
	window.localStorage.clear();
	window.sessionStorage.clear();
	testConnection.mockReset();
} );

afterEach( () => {
	if ( root ) {
		act( () => root.unmount() );
		container.remove();
		root = undefined;
	}
} );

describe( 'DiagnosticsPanel', () => {
	test( 'renders header, environment grid, and troubleshooting guide', async () => {
		await render();

		expect( container.textContent ).toContain(
			'System Diagnostics & Connection Doctor'
		);
		expect( container.textContent ).toContain(
			'Environment & Server Diagnostics'
		);
		expect( container.textContent ).toContain(
			'Troubleshooting & Reconnection Guide'
		);
	} );

	test( 'displays environment tiles with live state badges', async () => {
		await render(
			overview( {
				environment: {
					type: 'local',
					is_ssl: false,
					app_passwords_available: false,
				},
			} )
		);

		const envType = container.querySelector(
			'.shaped-kit-env-badge-local'
		);
		expect( envType ).not.toBeNull();
		expect( envType.textContent ).toBe( 'local' );

		const ssl = container.querySelector( '.shaped-kit-env-badge-warn' );
		expect( ssl ).not.toBeNull();
		expect( ssl.textContent ).toBe( 'Inactive (HTTP)' );

		const appPass = container.querySelector( '.shaped-kit-env-badge-bad' );
		expect( appPass ).not.toBeNull();
		expect( appPass.textContent ).toBe( 'Disabled' );

		const authTile = [
			...container.querySelectorAll( '.shaped-kit-env-item' ),
		].find( ( el ) => el.textContent.includes( 'Authorization Header' ) );
		expect( authTile.textContent ).toContain( 'Probe via Doctor' );
	} );

	test( 'includes the complete 4-step troubleshooting and server configuration guide', async () => {
		await render();

		const guide = container.querySelector(
			'.shaped-kit-troubleshooting-section'
		);
		expect( guide ).not.toBeNull();

		// Step 1: WordPress & Application Passwords
		expect( guide.textContent ).toContain(
			'Check WordPress & Application Passwords'
		);
		expect( guide.textContent ).toContain( 'manage_options' );

		// Step 2: AI Client Quirks
		expect( guide.textContent ).toContain(
			'Check AI Client Configuration & CLI Quirks'
		);
		expect( guide.textContent ).toContain( 'Claude Desktop' );
		expect( guide.textContent ).toContain( 'Claude Code CLI' );
		expect( guide.textContent ).toContain( 'Cursor & Codex' );
		expect( guide.textContent ).toContain( 'GitHub Copilot' );

		// Step 3: Connection Doctor
		expect( guide.textContent ).toContain( 'Run the Connection Doctor' );

		// Step 4: Web Server, Docker & Reverse Proxy Setup
		expect( guide.textContent ).toContain(
			'Local, Docker & Reverse Proxy Setup'
		);
		expect( guide.textContent ).toContain( 'WP_ENVIRONMENT_TYPE' );
		expect( guide.textContent ).toContain( 'X-Forwarded-Proto' );
		expect( guide.textContent ).toContain( 'HTTP_X_FORWARDED_PROTO' );
		expect( guide.textContent ).toContain( 'HTTP_AUTHORIZATION' );
	} );

	test( 'running the Connection Doctor updates the Authorization Header tile and renders results', async () => {
		testConnection.mockResolvedValueOnce( {
			slug: 'location-weather',
			name: 'Location Weather',
			state: 'ok',
			title: 'Connected',
			message: 'Ready to use.',
			headerStatus: 'passed',
		} );

		await render();

		const doctorButton = [ ...container.querySelectorAll( 'button' ) ].find(
			( b ) => /Run Connection Doctor/.test( b.textContent )
		);
		expect( doctorButton ).not.toBeNull();

		await act( async () => doctorButton.click() );
		await settle();

		expect( testConnection ).toHaveBeenCalledTimes( 1 );

		const authTile = [
			...container.querySelectorAll( '.shaped-kit-env-item' ),
		].find( ( el ) => el.textContent.includes( 'Authorization Header' ) );
		expect( authTile.textContent ).toContain( 'Passed to PHP' );
		expect(
			authTile.querySelector( '.shaped-kit-env-badge-good' )
		).not.toBeNull();

		const resultsList = container.querySelector( '.shaped-kit-results' );
		expect( resultsList ).not.toBeNull();
		expect( resultsList.textContent ).toContain( 'Location Weather' );
		expect( resultsList.textContent ).toContain( 'Connected' );
	} );

	test( 'renders probe test credential inputs with password toggle', async () => {
		await render();

		const userInput = container.querySelector(
			'#shaped-kit-probe-username'
		);
		const passInput = container.querySelector(
			'#shaped-kit-probe-password'
		);
		const toggleBtn = container.querySelector(
			'.shaped-kit-password-toggle-btn'
		);

		expect( userInput ).not.toBeNull();
		expect( passInput ).not.toBeNull();
		expect( toggleBtn ).not.toBeNull();
		expect( userInput.value ).toBe( 'admin' );
		expect( passInput.type ).toBe( 'password' );
		// "off" is ignored by Chrome, which then drops the saved WordPress login into the field (E108).
		expect( passInput.getAttribute( 'autocomplete' ) ).toBe(
			'new-password'
		);

		// Click toggle to reveal password
		await act( async () => toggleBtn.click() );
		expect( passInput.type ).toBe( 'text' );

		// Click again to hide password
		await act( async () => toggleBtn.click() );
		expect( passInput.type ).toBe( 'password' );
	} );

	test( 'renders copyable code snippets with copy button', async () => {
		const writeTextMock = jest.fn().mockResolvedValue();
		Object.assign( navigator, {
			clipboard: {
				writeText: writeTextMock,
			},
		} );

		await render();

		const snippetCopyBtns = container.querySelectorAll(
			'.shaped-kit-snippet-copy-btn'
		);
		expect( snippetCopyBtns.length ).toBeGreaterThanOrEqual( 3 );

		// Click the first snippet copy button (Claude CLI removal)
		await act( async () => snippetCopyBtns[ 0 ].click() );
		expect( writeTextMock ).toHaveBeenCalledWith(
			'claude mcp remove <slug>'
		);
		expect( snippetCopyBtns[ 0 ].textContent ).toBe( 'Copied!' );
	} );

	test( 'clicking the Step 3 action button triggers Connection Doctor diagnostics', async () => {
		testConnection.mockResolvedValueOnce( {
			slug: 'location-weather',
			name: 'Location Weather',
			state: 'ok',
			title: 'Connected',
			message: 'Ready to use.',
			headerStatus: 'passed',
		} );

		await render();

		const step3Btn = container.querySelector(
			'.shaped-kit-doctor-step-btn'
		);
		expect( step3Btn ).not.toBeNull();
		expect( step3Btn.textContent ).toBe( 'Run Connection Doctor' );

		await act( async () => step3Btn.click() );
		await settle();

		expect( testConnection ).toHaveBeenCalledTimes( 1 );
	} );
} );
