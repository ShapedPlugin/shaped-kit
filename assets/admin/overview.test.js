/**
 * Tests for the overview wording and totals.
 *
 * Risks covered: a real problem (no adapter, an old adapter, an old WordPress) shown as if all is well,
 * a tool count that includes tools nobody can call, and an unknown status taken for a good one.
 */
import { adapterValue, notices, statusView, totals } from './overview';

const adapter = ( overrides = {} ) => ( {
	loaded: true,
	compatible: true,
	version: '0.7.0',
	source: { type: 'shaped-kit', name: 'shaped-kit', display_name: '' },
	...overrides,
} );

const overview = ( overrides = {} ) => ( {
	wordpress_supported: true,
	abilities_api: true,
	adapter: adapter(),
	products: [ { slug: 'a', enabled: true, status: 'ready', tools_count: 5 } ],
	...overrides,
} );

describe( 'statusView', () => {
	test.each( [
		[ 'ready', 'good' ],
		[ 'disabled', 'off' ],
		[ 'adapter_required', 'warn' ],
		[ 'adapter_outdated', 'bad' ],
		[ 'adapter_unknown', 'bad' ],
		[ 'adapter_unusable', 'bad' ],
		[ 'wordpress_unsupported', 'bad' ],
		[ 'error', 'bad' ],
	] )( '%s has the tone %s', ( status, tone ) => {
		expect( statusView( status ).tone ).toBe( tone );
	} );

	test( 'a status it does not know is a warning, never a good one', () => {
		expect( statusView( 'from_a_newer_product' ) ).toEqual( {
			label: 'Unknown',
			tone: 'warn',
		} );
		expect( statusView( undefined ).tone ).toBe( 'warn' );
	} );
} );

describe( 'adapterValue', () => {
	test.each( [
		[ undefined, 'Not loaded' ],
		[ { loaded: false }, 'Not loaded' ],
		[ adapter(), 'Shaped Kit · 0.7.0' ],
		[ adapter( { version: null } ), 'Shaped Kit' ],
		[
			adapter( {
				version: '0.4.1',
				source: {
					type: 'plugin',
					name: 'seo-by-rank-math',
					display_name: 'Rank Math SEO',
				},
			} ),
			'Rank Math SEO · 0.4.1',
		],
		[
			adapter( {
				source: {
					type: 'plugin',
					name: 'seo-by-rank-math',
					display_name: '',
				},
			} ),
			'seo-by-rank-math · 0.7.0',
		],
		[
			adapter( { source: { type: 'unknown', name: '' } } ),
			'Another plugin · 0.7.0',
		],
	] )( '%j reads as "%s"', ( value, expected ) => {
		expect( adapterValue( value ) ).toBe( expected );
	} );
} );

describe( 'totals', () => {
	test( 'only products that are ready add their tools', () => {
		const result = totals( {
			products: [
				{ enabled: true, status: 'ready', tools_count: 5 },
				{ enabled: false, status: 'disabled', tools_count: 7 },
				{ enabled: true, status: 'adapter_required', tools_count: 3 },
				{ enabled: true, status: 'ready' },
			],
		} );

		expect( result ).toEqual( {
			productsOn: 3,
			productsTotal: 4,
			toolsAvailable: 5,
		} );
	} );

	test( 'no products, or a broken overview, is all zeros', () => {
		const zeros = { productsOn: 0, productsTotal: 0, toolsAvailable: 0 };

		expect( totals( { products: [] } ) ).toEqual( zeros );
		expect( totals( undefined ) ).toEqual( zeros );
		expect( totals( { products: 'x' } ) ).toEqual( zeros );
	} );
} );

describe( 'notices', () => {
	test( 'a healthy site has none', () => {
		expect( notices( overview() ) ).toEqual( [] );
	} );

	test( 'an old WordPress is the one thing said, not a follow-on about the adapter', () => {
		const found = notices(
			overview( {
				wordpress_supported: false,
				adapter: adapter( { loaded: false } ),
			} )
		);

		expect( found ).toHaveLength( 1 );
		expect( found[ 0 ] ).toMatchObject( { tone: 'bad' } );
		expect( found[ 0 ].text ).toMatch( /WordPress 6\.9/ );
	} );

	test( 'a WordPress without the Abilities API is named, not blamed on the adapter', () => {
		const found = notices(
			overview( {
				abilities_api: false,
				adapter: adapter( { loaded: false } ),
			} )
		);

		expect( found ).toHaveLength( 1 );
		expect( found[ 0 ] ).toMatchObject( { tone: 'bad' } );
		expect( found[ 0 ].text ).toMatch( /Abilities API/ );
	} );

	test( 'no adapter is a warning that names the effect', () => {
		const found = notices(
			overview( { adapter: adapter( { loaded: false } ) } )
		);

		expect( found ).toHaveLength( 1 );
		expect( found[ 0 ].tone ).toBe( 'warn' );
		expect( found[ 0 ].text ).toMatch( /No MCP adapter is loaded/ );
	} );

	test( 'an adapter that is too old, or reports no version, is a problem', () => {
		const old = notices(
			overview( {
				adapter: adapter( { compatible: false, version: '0.0.9' } ),
			} )
		);
		const noVersion = notices(
			overview( {
				adapter: adapter( { compatible: false, version: null } ),
			} )
		);

		expect( old[ 0 ] ).toMatchObject( { tone: 'bad' } );
		expect( old[ 0 ].text ).toMatch( /version 0\.0\.9/ );
		expect( noVersion[ 0 ].text ).toMatch( /version unknown/ );
	} );

	test( 'no products adds its own notice next to an adapter problem', () => {
		const found = notices(
			overview( { products: [], adapter: adapter( { loaded: false } ) } )
		);

		expect( found ).toHaveLength( 2 );
		expect( found[ 1 ].text ).toMatch( /No ShapedPlugin product/ );
	} );

	test( 'an overview that has not loaded yet gives no product notice', () => {
		expect(
			notices( undefined ).some( ( n ) =>
				/No ShapedPlugin/.test( n.text )
			)
		).toBe( false );
	} );
} );
