/**
 * Tests for the "Try a tool" component, mounted for real with the MCP client mocked.
 *
 * Risks covered: a result shown for a product or password that is no longer selected, a call made with
 * arguments that are not an object, a tool's own failure lost, an answer still on its way landing after the
 * screen moved on, a button that can be pressed twice, and a tool's text treated as markup.
 */
import { createRoot } from '@wordpress/element';
import { act } from 'react';
import ToolTester, { formatResult } from './ToolTester';
import { callTool, openSession } from './mcpClient';

global.IS_REACT_ACT_ENVIRONMENT = true;

jest.mock( './mcpClient', () => ( {
	...jest.requireActual( './mcpClient' ),
	openSession: jest.fn(),
	callTool: jest.fn(),
} ) );

let container;
let root;

const products = [
	{
		slug: 'location-weather',
		name: 'Location Weather',
		endpoint_url: 'https://site.test/wp-json/location-weather/mcp',
	},
	{
		slug: 'real-testimonials',
		name: 'Real Testimonials',
		endpoint_url: 'https://site.test/wp-json/real-testimonials/mcp',
	},
];

const tools = [
	{
		name: 'location-weather-get-context',
		description: 'Call first. Who you are and what exists.',
		inputSchema: { type: 'object', properties: {} },
	},
	{
		name: 'location-weather-get-weather-template',
		description: 'One weather entry.',
		inputSchema: {
			type: 'object',
			properties: { id: { type: 'integer' } },
			required: [ 'id' ],
		},
	},
];

const opened = ( overrides = {} ) => ( {
	ok: true,
	session: 'sess-1',
	tools,
	error: null,
	...overrides,
} );

const answered = ( overrides = {} ) => ( {
	ok: true,
	result: { structuredContent: { total: 1 } },
	isError: false,
	status: 200,
	ms: 12,
	error: null,
	...overrides,
} );

const deferred = () => {
	const handle = {};
	handle.promise = new Promise( ( resolve ) => {
		handle.resolve = resolve;
	} );
	return handle;
};

const render = async ( props = {} ) => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
	const all = {
		products,
		username: 'admin',
		password: 'abcd efgh',
		...props,
	};
	const draw = async ( next ) => {
		await act( async () => {
			root.render( <ToolTester { ...all } { ...next } /> );
		} );
	};
	await draw( {} );
	return draw;
};

const settle = async () => act( async () => {} );
const button = ( pattern ) =>
	[ ...container.querySelectorAll( 'button' ) ].find( ( b ) =>
		pattern.test( b.textContent )
	);
const toolSelect = () =>
	container.querySelector( '.shaped-kit-tester-body select' );
const textarea = () => container.querySelector( 'textarea' );
const output = () =>
	container.querySelector( '.shaped-kit-tester-result pre code' )?.textContent;

/** Type into a controlled field. */
const type = async ( element, value ) => {
	const proto =
		element.tagName === 'TEXTAREA'
			? window.HTMLTextAreaElement.prototype
			: window.HTMLInputElement.prototype;
	const setter = Object.getOwnPropertyDescriptor( proto, 'value' ).set;
	await act( async () => {
		setter.call( element, value );
		element.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
};

const choose = async ( element, value ) => {
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLSelectElement.prototype,
		'value'
	).set;
	await act( async () => {
		setter.call( element, value );
		element.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
};

const load = async () => {
	await act( async () => button( /Load tools/ ).click() );
	await settle();
};

beforeEach( () => {
	openSession.mockReset();
	callTool.mockReset();
} );

afterEach( () => {
	if ( root ) {
		act( () => root.unmount() );
		container.remove();
		root = undefined;
	}
} );

describe( 'formatResult', () => {
	test.each( [
		[
			{
				structuredContent: { a: 1 },
				content: [ { type: 'text', text: 'x' } ],
			},
			'{\n  "a": 1\n}',
		],
		[ { structuredContent: [] }, '[]' ],
		[
			{
				content: [
					{ type: 'text', text: 'one' },
					{ type: 'text', text: 'two' },
				],
			},
			'one\ntwo',
		],
		[
			{
				content: [
					{ type: 'image', data: 'x' },
					{ type: 'text', text: 'only' },
				],
			},
			'only',
		],
		[ { content: [] }, '{\n  "content": []\n}' ],
		[ { other: true }, '{\n  "other": true\n}' ],
		[ null, 'null' ],
	] )( '%j is shown as %j', ( result, expected ) => {
		expect( formatResult( result ) ).toBe( expected );
	} );
} );

describe( 'loading tools', () => {
	test( 'it signs in with the typed credentials, lists the tools, and starts on the first one', async () => {
		openSession.mockResolvedValueOnce( opened() );
		await render();

		await load();

		expect( openSession ).toHaveBeenCalledWith( products[ 0 ], {
			username: 'admin',
			password: 'abcd efgh',
		} );
		expect( [ ...toolSelect().options ].map( ( o ) => o.value ) ).toEqual( [
			'location-weather-get-context',
			'location-weather-get-weather-template',
		] );
		expect( container.textContent ).toContain( 'Call first. Who you are' );
		expect( textarea().value ).toBe( '{}' );
	} );

	test( 'a failed sign-in shows the reason and offers no tools', async () => {
		openSession.mockResolvedValueOnce( {
			ok: false,
			session: null,
			tools: [],
			error: { state: 'rejected', message: 'Not accepted.' },
		} );
		await render();

		await load();

		expect( container.querySelector( '[role="alert"]' ).textContent ).toBe(
			'Not accepted.'
		);
		expect( toolSelect() ).toBeNull();
		expect( button( /Run tool/ ) ).toBeUndefined();
	} );

	test( 'a product with no tools says so', async () => {
		openSession.mockResolvedValueOnce( opened( { tools: [] } ) );
		await render();

		await load();

		expect( container.textContent ).toContain( 'offers no tools' );
		expect( button( /Run tool/ ) ).toBeUndefined();
	} );

	test( 'the button is locked while loading', async () => {
		const pending = deferred();
		openSession.mockReturnValueOnce( pending.promise );
		await render();

		await act( async () => button( /Load tools/ ).click() );

		expect( button( /Load tools/ ).disabled ).toBe( true );

		await act( async () => pending.resolve( opened() ) );
		await settle();
		expect( button( /Reload tools/ ).disabled ).toBe( false );
	} );
} );

describe( 'running a tool', () => {
	const ready = async () => {
		openSession.mockResolvedValueOnce( opened() );
		const draw = await render();
		await load();
		return draw;
	};

	test( 'choosing a tool fills its sample arguments from catalog', async () => {
		await ready();

		await choose( toolSelect(), 'location-weather-get-weather-template' );

		expect( textarea().value ).toBe( '{\n  "id": 201\n}' );
	} );

	test( 'choosing an uncataloged tool fills its required arguments as blanks', async () => {
		openSession.mockResolvedValueOnce(
			opened( {
				tools: [
					{
						name: 'unknown-custom-tool',
						description: 'A tool not in catalog.',
						inputSchema: {
							type: 'object',
							properties: { count: { type: 'integer' } },
							required: [ 'count' ],
						},
					},
				],
			} )
		);
		await render();
		await load();

		expect( textarea().value ).toBe( '{\n  "count": 0\n}' );
	} );

	test( 'it calls the chosen tool with the typed arguments and the session, and shows the data and the timing', async () => {
		callTool.mockResolvedValueOnce( answered() );
		await ready();
		await choose( toolSelect(), 'location-weather-get-weather-template' );
		await type( textarea(), '{"id": 7}' );

		await act( async () => button( /Run tool/ ).click() );
		await settle();

		expect( callTool ).toHaveBeenCalledWith(
			products[ 0 ],
			{ username: 'admin', password: 'abcd efgh' },
			'sess-1',
			'location-weather-get-weather-template',
			{ id: 7 }
		);
		expect( output() ).toBe( '{\n  "total": 1\n}' );
		expect( container.textContent ).toContain( 'HTTP 200 · 12 ms' );
	} );

	test( 'arguments that are not a JSON object are refused before any request', async () => {
		await ready();
		await type( textarea(), '[1, 2]' );

		await act( async () => button( /Run tool/ ).click() );

		expect( callTool ).not.toHaveBeenCalled();
		expect(
			container.querySelector( '[role="alert"]' ).textContent
		).toMatch( /must be a JSON object/ );
	} );

	test( 'a tool that reports its own failure is shown with a note, and its message is kept', async () => {
		callTool.mockResolvedValueOnce(
			answered( {
				isError: true,
				result: {
					content: [
						{ type: 'text', text: 'No entry with that id.' },
					],
				},
			} )
		);
		await ready();

		await act( async () => button( /Run tool/ ).click() );
		await settle();

		expect(
			container.querySelector( '.shaped-kit-notice' ).textContent
		).toMatch( /reported that its call failed/ );
		expect( output() ).toBe( 'No entry with that id.' );
	} );

	test( 'a call that gets no answer shows the reason and no result', async () => {
		callTool.mockResolvedValueOnce( {
			ok: false,
			result: null,
			isError: false,
			status: 403,
			ms: 5,
			error: { state: 'forbidden', message: 'Not allowed.' },
		} );
		await ready();

		await act( async () => button( /Run tool/ ).click() );
		await settle();

		expect( container.querySelector( '[role="alert"]' ).textContent ).toBe(
			'Not allowed.'
		);
		expect( output() ).toBeUndefined();
	} );

	test( 'Run is locked while a call is out', async () => {
		const pending = deferred();
		callTool.mockReturnValueOnce( pending.promise );
		await ready();

		await act( async () => button( /Run tool/ ).click() );

		expect( button( /Working/ ).disabled ).toBe( true );

		await act( async () => pending.resolve( answered() ) );
		await settle();
		expect( button( /Run tool/ ).disabled ).toBe( false );
	} );

	test( 'choosing another tool clears the old result', async () => {
		callTool.mockResolvedValueOnce( answered() );
		await ready();
		await act( async () => button( /Run tool/ ).click() );
		await settle();
		expect( output() ).toBeDefined();

		await choose( toolSelect(), 'location-weather-get-weather-template' );

		expect( output() ).toBeUndefined();
	} );

	test( 'text from a tool is shown as text, never as markup', async () => {
		callTool.mockResolvedValueOnce(
			answered( {
				result: {
					structuredContent: { name: '<img src=x onerror=alert(1)>' },
				},
			} )
		);
		await ready();

		await act( async () => button( /Run tool/ ).click() );
		await settle();

		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( output() ).toContain( '<img' );
	} );
} );

describe( 'what was loaded belongs to one product and one login', () => {
	test( 'changing the password throws away the tools and the result', async () => {
		openSession.mockResolvedValueOnce( opened() );
		callTool.mockResolvedValueOnce( answered() );
		const draw = await render();
		await load();
		await act( async () => button( /Run tool/ ).click() );
		await settle();
		expect( output() ).toBeDefined();

		await draw( { password: 'something else' } );

		expect( toolSelect() ).toBeNull();
		expect( output() ).toBeUndefined();
		expect( button( /Load tools/ ) ).toBeDefined();
	} );

	test( 'choosing another product throws them away too, and the next load uses that product', async () => {
		openSession
			.mockResolvedValueOnce( opened() )
			.mockResolvedValueOnce( opened( { session: 'sess-2' } ) );
		await render();
		await load();

		await choose(
			container.querySelector( '.shaped-kit-tester-row select' ),
			'real-testimonials'
		);
		expect( toolSelect() ).toBeNull();

		await load();

		expect( openSession.mock.calls[ 1 ][ 0 ].slug ).toBe(
			'real-testimonials'
		);
	} );

	test( 'a sign-in that answers after the password changed is dropped', async () => {
		const slow = deferred();
		openSession.mockReturnValueOnce( slow.promise );
		const draw = await render();
		await act( async () => button( /Load tools/ ).click() );

		await draw( { password: 'changed meanwhile' } );
		await act( async () => slow.resolve( opened() ) );
		await settle();

		expect( toolSelect() ).toBeNull();
		expect( button( /Load tools/ ).disabled ).toBe( false );
	} );

	test( 'a result that arrives after the tool was changed is dropped', async () => {
		const slow = deferred();
		openSession.mockResolvedValueOnce( opened() );
		callTool.mockReturnValueOnce( slow.promise );
		const draw = await render();
		await load();
		await act( async () => button( /Run tool/ ).click() );

		await draw( { username: 'someone-else' } );
		await act( async () => slow.resolve( answered() ) );
		await settle();

		expect( output() ).toBeUndefined();
	} );
} );

test( 'with one product there is no product picker, with several there is', async () => {
	await render( { products: [ products[ 0 ] ] } );
	expect(
		container.querySelector( '.shaped-kit-tester-row select' )
	).toBeNull();
	expect(
		container.querySelector( '.shaped-kit-tester-row strong' ).textContent
	).toBe( 'Location Weather' );

	act( () => root.unmount() );
	container.remove();

	await render();
	expect(
		container.querySelector( '.shaped-kit-tester-row select' )
	).not.toBeNull();
} );

test( 'the product picker is named by its label, so a screen reader announces it and a click on the label reaches it', async () => {
	await render();
	const select = container.querySelector( '.shaped-kit-tester-row select' );
	const label = container.querySelector(
		`.shaped-kit-tester-row label[for="${ select.id }"]`
	);

	expect( select.id ).not.toBe( '' );
	expect( label.textContent ).toBe( 'Product' );
	expect( select.labels[ 0 ] ).toBe( label );
} );

test( 'with no product on, it draws nothing', async () => {
	await render( { products: [] } );

	expect( container.textContent ).toBe( '' );
} );

test( 'it prefills sampleArgs from catalog, allows switching presets, and shows expected output', async () => {
	const realTestimonialTools = [
		{
			name: 'real-testimonials/insert-block',
			description: 'Save a built block into a new draft page.',
			inputSchema: {
				type: 'object',
				properties: {
					block: { type: 'string' },
					attributes: { type: 'object' },
					target: { type: 'object' },
				},
				required: [ 'block', 'attributes', 'target' ],
			},
		},
	];

	openSession.mockResolvedValueOnce( {
		ok: true,
		session: 'sess-rt',
		tools: realTestimonialTools,
		error: null,
	} );

	await render( {
		products: [
			{
				slug: 'real-testimonials',
				name: 'Real Testimonials',
				endpoint_url: 'https://site.test/wp-json/real-testimonials/mcp',
			},
		],
	} );

	await load();

	// Textarea is prefilled with sampleArgs (with target.new_draft), not empty stubs
	const area = container.querySelector( 'textarea' );
	expect( area.value ).toContain( '"block": "slider"' );
	expect( area.value ).toContain( '"new_draft": "Customer Reviews"' );

	// Presets are rendered
	const presets = container.querySelectorAll( '.shaped-kit-preset-btn' );
	expect( presets.length ).toBeGreaterThan( 0 );

	// Click "With Custom Styling" preset
	const customPreset = Array.from( presets ).find( ( btn ) =>
		btn.textContent.includes( 'With Custom Styling' )
	);
	expect( customPreset ).toBeDefined();
	await act( async () => customPreset.click() );

	expect( area.value ).toContain( '"cardDesign": "design-one"' );

	// Manually change the textarea, then click "Reset to sample"
	await act( async () => {
		area.value = '{"custom": true}';
		area.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );

	const resetBtn = container.querySelector( '.shaped-kit-reset-sample-btn' );
	expect( resetBtn ).not.toBeNull();
	await act( async () => resetBtn.click() );

	expect( area.value ).toContain( '"new_draft": "Customer Reviews"' );

	// Expected output details are present
	const expectedDetails = container.querySelector( '.shaped-kit-expected-output-details' );
	expect( expectedDetails ).not.toBeNull();
	expect( expectedDetails.textContent ).toContain( 'Expected Success Output (HTTP 200)' );
	expect( expectedDetails.textContent ).toContain( '15001' );
} );

test( 'it matches wire names with hyphens to catalog tools and populates sampleArgs and presets', async () => {
	const wireTools = [
		{
			name: 'real-testimonials-insert-block',
			description: 'Save a built block into a new draft page.',
			componentType: 'tool',
			inputSchema: {
				type: 'object',
				properties: {
					block: { type: 'string' },
					attributes: { type: 'object' },
					target: { type: 'object' },
				},
				required: [ 'block', 'attributes', 'target' ],
			},
		},
	];

	openSession.mockResolvedValueOnce( {
		ok: true,
		session: 'sess-hyphen',
		tools: wireTools,
		error: null,
	} );

	await render( {
		products: [
			{
				slug: 'real-testimonials',
				name: 'Real Testimonials',
				endpoint_url: 'https://site.test/wp-json/real-testimonials/mcp',
			},
		],
	} );

	await load();

	// Matches catalog tool despite hyphen vs slash:
	const area = container.querySelector( 'textarea' );
	expect( area.value ).toContain( '"block": "slider"' );
	expect( area.value ).toContain( '"new_draft": "Customer Reviews"' );

	const presets = container.querySelectorAll( '.shaped-kit-preset-btn' );
	expect( presets.length ).toBeGreaterThan( 0 );

	const expectedDetails = container.querySelector( '.shaped-kit-expected-output-details' );
	expect( expectedDetails ).not.toBeNull();
	expect( expectedDetails.textContent ).toContain( '15001' );
} );

test( 'it renders prompt items with (Prompt) label and calls callTool with componentType prompt', async () => {
	const items = [
		{
			name: 'real-testimonials-build-testimonial-block',
			description: 'Build a testimonial block prompt',
			componentType: 'prompt',
			inputSchema: {
				type: 'object',
				properties: {
					request: { type: 'string', description: 'User request' },
				},
				required: [ 'request' ],
			},
		},
	];

	openSession.mockResolvedValueOnce( {
		ok: true,
		session: 'sess-p',
		tools: items,
		error: null,
	} );

	callTool.mockResolvedValueOnce(
		answered( {
			result: {
				description: 'Ready prompt',
				messages: [
					{
						role: 'user',
						content: { type: 'text', text: 'Prompt generated' },
					},
				],
			},
		} )
	);

	await render( {
		products: [
			{
				slug: 'real-testimonials',
				name: 'Real Testimonials',
				endpoint_url: 'https://site.test/wp-json/real-testimonials/mcp',
			},
		],
	} );

	await load();

	// Select option has "(Prompt)" label
	const select = toolSelect();
	expect( select.options[ 0 ].textContent ).toContain( '(Prompt)' );

	// Button text says "Run prompt"
	const runBtn = button( /Run prompt/ );
	expect( runBtn ).toBeDefined();

	// Sample args for build-testimonial-block are prefilled
	const area = container.querySelector( 'textarea' );
	expect( area.value ).toContain(
		'Build a 3-column carousel testimonial block'
	);

	// Running the prompt calls callTool with componentType = 'prompt'
	await act( async () => runBtn.click() );
	await settle();

	expect( callTool ).toHaveBeenCalledWith(
		expect.objectContaining( { slug: 'real-testimonials' } ),
		expect.anything(),
		'sess-p',
		'real-testimonials-build-testimonial-block',
		expect.objectContaining( { request: expect.any( String ) } ),
		undefined,
		'prompt'
	);
	expect( output() ).toContain( 'Prompt generated' );
} );

