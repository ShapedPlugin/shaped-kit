/**
 * Tests for toolsCatalog.js enforcing FR-K10.
 *
 * Rule FR-K10:
 * Every ability must have:
 * - exact namespaced identifier (name)
 * - human-readable label
 * - concise summary
 * - at least 3 realistic prompts reflecting user instructions
 */
import { productToolsCatalog, getProductTools } from './toolsCatalog';

describe( 'toolsCatalog compliance (FR-K10)', () => {
	const products = [ 'location-weather', 'real-testimonials', 'wp-carousel' ];

	test.each( products )( 'product "%s" tools adhere strictly to FR-K10', ( slug ) => {
		const tools = productToolsCatalog[ slug ];
		expect( Array.isArray( tools ) ).toBe( true );
		expect( tools.length ).toBeGreaterThan( 0 );

		for ( const tool of tools ) {
			expect( typeof tool.name ).toBe( 'string' );
			expect( tool.name.startsWith( slug + '/' ) ).toBe( true );

			expect( typeof tool.label ).toBe( 'string' );
			expect( tool.label.trim().length ).toBeGreaterThan( 0 );

			expect( typeof tool.summary ).toBe( 'string' );
			expect( tool.summary.trim().length ).toBeGreaterThan( 0 );

			expect( Array.isArray( tool.prompts ) ).toBe( true );
			expect( tool.prompts.length ).toBeGreaterThanOrEqual( 3 );

			for ( const prompt of tool.prompts ) {
				expect( typeof prompt ).toBe( 'string' );
				expect( prompt.trim().length ).toBeGreaterThan( 0 );
			}
		}
	} );

	test( 'wp-carousel provides exactly 18 catalog entries (17 tools + 1 prompt)', () => {
		const tools = getProductTools( 'wp-carousel' );
		expect( tools ).toHaveLength( 18 );

		const names = tools.map( ( t ) => t.name );
		expect( names ).toEqual(
			expect.arrayContaining( [
				'wp-carousel/get-context',
				'wp-carousel/list-carousels',
				'wp-carousel/get-carousel',
				'wp-carousel/find-media',
				'wp-carousel/duplicate-carousel',
				'wp-carousel/update-carousel-settings',
				'wp-carousel/import-image',
				'wp-carousel/delete-carousel',
				'wp-carousel/list-templates',
				'wp-carousel/get-template',
				'wp-carousel/find-carousel-blocks',
				'wp-carousel/get-settings',
				'wp-carousel/get-block-catalog',
				'wp-carousel/validate-block-attributes',
				'wp-carousel/insert-block',
				'wp-carousel/list-ready-patterns',
				'wp-carousel/insert-ready-pattern',
				'wp-carousel/build-carousel',
			] )
		);
	} );
} );
