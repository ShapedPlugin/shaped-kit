/**
 * Tests for the connect snippets.
 *
 * Risks covered: a snippet that does not authenticate (a wrong header, a name that breaks the encoding,
 * spaces left in the password), a shell line broken or made to run something by a quote in a value, the
 * TLS check switched off when nobody asked, and a snippet written for a product that has no endpoint.
 */
import { buildSnippet, clients, credentials } from './snippets';

const weather = {
	slug: 'location-weather',
	name: 'Location Weather',
	endpoint_url: 'https://site.test/wp-json/location-weather/mcp',
};
const testimonials = {
	slug: 'real-testimonials',
	name: 'Real Testimonials',
	endpoint_url: 'https://site.test/wp-json/real-testimonials/mcp',
};

const login = { username: 'admin', password: 'abcd efgh ijkl mnop qrst uvwx' };
// base64 of "admin:abcdefghijklmnopqrstuvwx"
const expected = 'YWRtaW46YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4';

describe( 'credentials', () => {
	test( 'the header is base64 of user:password with the password spaces removed', () => {
		const result = credentials( 'admin', 'abcd efgh ijkl mnop qrst uvwx' );

		expect( result.header ).toBe( expected );
		expect( result.password ).toBe( 'abcdefghijklmnopqrstuvwx' );
		expect( result.ready ).toBe( true );
	} );

	test( 'a name with accents or other scripts is encoded as UTF-8, not rejected', () => {
		const result = credentials( 'José', 'abcdefgh' );

		expect( result.ready ).toBe( true );
		expect( window.atob( result.header ) ).toBe( 'JosÃ©:abcdefgh' );
	} );

	test.each( [
		[ '', 'abcd' ],
		[ 'admin', '' ],
		[ '  ', 'abcd' ],
		[ 'admin', '   ' ],
		[ undefined, undefined ],
	] )(
		'user %j and password %j is not ready and shows placeholders',
		( user, password ) => {
			const result = credentials( user, password );

			expect( result.ready ).toBe( false );
			expect( result.header ).toMatch( /^<base64\(/ );
		}
	);
} );

describe( 'buildSnippet', () => {
	const options = ( overrides = {} ) => ( {
		products: [ weather ],
		...login,
		...overrides,
	} );

	test( 'Claude Code is one claude mcp add command with the header', () => {
		const text = buildSnippet( 'claude-code', options() );

		expect( text ).toContain( 'claude mcp add' );
		expect( text ).toContain( '--transport http' );
		expect( text ).toContain(
			`location-weather ${ weather.endpoint_url }`
		);
		expect( text ).toContain(
			`--header "Authorization: Basic ${ expected }"`
		);
	} );

	test( 'Codex lists the fields to fill in', () => {
		const text = buildSnippet( 'codex', options() );

		expect( text ).toContain( 'Name:         location-weather' );
		expect( text ).toContain( 'Transport:    Streamable HTTP' );
		expect( text ).toContain( `URL:          ${ weather.endpoint_url }` );
		expect( text ).toContain( `Header value: Basic ${ expected }` );
	} );

	test( 'Copilot and Cursor are JSON keyed by product, each with the endpoint and the header', () => {
		const copilot = JSON.parse( buildSnippet( 'copilot', options() ) );
		const cursor = JSON.parse( buildSnippet( 'cursor', options() ) );

		expect( copilot.servers[ 'location-weather' ] ).toEqual( {
			type: 'http',
			url: weather.endpoint_url,
			headers: { Authorization: `Basic ${ expected }` },
		} );
		expect( cursor.mcpServers[ 'location-weather' ] ).toEqual( {
			url: weather.endpoint_url,
			type: 'http',
			headers: { Authorization: `Basic ${ expected }` },
		} );
	} );

	test( 'Claude Desktop runs the remote proxy with the credentials in its environment', () => {
		const server = JSON.parse( buildSnippet( 'claude-desktop', options() ) )
			.mcpServers[ 'location-weather' ];

		expect( server.command ).toBe( 'npx' );
		expect( server.args ).toEqual( [
			'-y',
			'@automattic/mcp-wordpress-remote@latest',
		] );
		expect( server.env ).toEqual( {
			WP_API_URL: weather.endpoint_url,
			WP_API_USERNAME: 'admin',
			WP_API_PASSWORD: 'abcdefghijklmnopqrstuvwx',
			OAUTH_ENABLED: 'false',
		} );
	} );

	test( 'TLS checks are switched off only when the owner asked for a local site', () => {
		const off = JSON.parse(
			buildSnippet( 'claude-desktop', options( { localDev: true } ) )
		).mcpServers[ 'location-weather' ].env;
		const normal = JSON.parse( buildSnippet( 'claude-desktop', options() ) )
			.mcpServers[ 'location-weather' ].env;

		expect( off.NODE_TLS_REJECT_UNAUTHORIZED ).toBe( '0' );
		expect( normal ).not.toHaveProperty( 'NODE_TLS_REJECT_UNAUTHORIZED' );
		expect(
			buildSnippet( 'cursor', options( { localDev: true } ) )
		).not.toContain( 'TLS' );
	} );

	test( 'the generic snippet carries the header and a curl test', () => {
		const text = buildSnippet( 'generic', options() );

		expect( text ).toContain( `Auth: Authorization: Basic ${ expected }` );
		expect( text ).toContain(
			"curl -s -u 'admin:abcdefghijklmnopqrstuvwx'"
		);
		expect( text ).toContain( `-X POST ${ weather.endpoint_url }` );
		expect( text ).toContain( '"method":"tools/list"' );
	} );

	test( 'a quote in a value cannot break out of the curl line', () => {
		const text = buildSnippet(
			'generic',
			options( { username: "o'neil", password: "ab'cd" } )
		);

		expect( text ).toContain( "-u 'o'\\''neil:ab'\\''cd'" );
	} );

	test( 'a quote in a value stays valid JSON for the JSON clients', () => {
		const text = buildSnippet(
			'claude-desktop',
			options( { username: 'a"b', password: 'c\\d' } )
		);

		expect(
			JSON.parse( text ).mcpServers[ 'location-weather' ].env
				.WP_API_USERNAME
		).toBe( 'a"b' );
	} );

	test( 'several products give one entry each in the JSON, and one block each in the text', () => {
		const both = options( { products: [ weather, testimonials ] } );

		expect(
			Object.keys(
				JSON.parse( buildSnippet( 'cursor', both ) ).mcpServers
			)
		).toEqual( [ 'location-weather', 'real-testimonials' ] );
		expect(
			buildSnippet( 'claude-code', both ).match( /claude mcp add/g )
		).toHaveLength( 2 );
		expect( buildSnippet( 'codex', both ).match( /Name:/g ) ).toHaveLength(
			2
		);
		expect(
			buildSnippet( 'generic', both ).match( /curl -s/g )
		).toHaveLength( 2 );
	} );

	test( 'a product with no endpoint is left out, and nothing to connect gives an empty snippet', () => {
		const text = buildSnippet(
			'cursor',
			options( {
				products: [
					weather,
					{ slug: 'x', name: 'X', endpoint_url: '' },
				],
			} )
		);

		expect( Object.keys( JSON.parse( text ).mcpServers ) ).toEqual( [
			'location-weather',
		] );
		expect( buildSnippet( 'cursor', options( { products: [] } ) ) ).toBe(
			''
		);
		expect(
			buildSnippet( 'cursor', options( { products: undefined } ) )
		).toBe( '' );
	} );

	test( 'before the credentials are typed, placeholders show and no half-made header does', () => {
		const text = buildSnippet( 'cursor', {
			products: [ weather ],
			username: 'admin',
			password: '',
		} );

		expect( text ).toContain( '<base64(' );
		expect( text ).not.toContain( 'YWRtaW4' );
	} );

	test( 'an unknown client gives nothing', () => {
		expect( buildSnippet( 'nope', options() ) ).toBe( '' );
	} );

	test( 'every client in the tab list has a builder', () => {
		clients().forEach( ( { key } ) => {
			expect( buildSnippet( key, options() ) ).not.toBe( '' );
		} );
	} );
} );
