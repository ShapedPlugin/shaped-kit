/**
 * @jest-environment node
 *
 * The build must never leave `build/admin` empty or half written: the AI & MCP page needs it on every
 * request, and a broken or interrupted build used to show the owner no dashboard.
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { safeBuild, REQUIRED } = require( './build' );

let root;

const live = () => path.join( root, 'build', 'admin' );
const write = ( dir, tag, skip = [] ) => {
	fs.mkdirSync( dir, { recursive: true } );
	REQUIRED.filter( ( name ) => ! skip.includes( name ) ).forEach( ( name ) =>
		fs.writeFileSync( path.join( dir, name ), `${ tag }:${ name }` )
	);
};
const read = ( name ) => fs.readFileSync( path.join( live(), name ), 'utf8' );
const leftovers = () =>
	fs
		.readdirSync( path.join( root, 'build' ) )
		.filter( ( name ) => name !== 'admin' );

beforeEach( () => {
	root = fs.mkdtempSync( path.join( os.tmpdir(), 'shaped-kit-build-' ) );
} );

afterEach( () => {
	jest.restoreAllMocks();
	fs.rmSync( root, { recursive: true, force: true } );
} );

test( 'a good build replaces the live one and leaves nothing else behind', () => {
	write( live(), 'old' );

	const outcome = safeBuild( {
		root,
		build: ( r, out ) => {
			write( out, 'new' );
			return true;
		},
	} );

	expect( outcome ).toEqual( { ok: true } );
	expect( read( 'index.js' ) ).toBe( 'new:index.js' );
	expect( read( 'style-index.css' ) ).toBe( 'new:style-index.css' );
	expect( leftovers() ).toEqual( [] );
} );

test( 'the old build is still there, whole, while the new one is being made', () => {
	write( live(), 'old' );
	let seen;

	safeBuild( {
		root,
		build: ( r, out ) => {
			seen = REQUIRED.map( ( name ) =>
				fs.existsSync( path.join( live(), name ) )
			);
			write( out, 'new' );
			return true;
		},
	} );

	expect( seen ).toEqual( [ true, true, true ] );
} );

test( 'a failed build leaves the live one exactly as it was', () => {
	write( live(), 'old' );

	const outcome = safeBuild( {
		root,
		build: ( r, out ) => {
			write( out, 'half', [ 'style-index.css' ] );
			return false;
		},
	} );

	expect( outcome ).toEqual( { ok: false, reason: 'build_failed' } );
	expect( read( 'index.js' ) ).toBe( 'old:index.js' );
	expect( leftovers() ).toEqual( [] );
} );

test( 'a build that exits well but is missing a file is not swapped in', () => {
	write( live(), 'old' );

	const outcome = safeBuild( {
		root,
		build: ( r, out ) => {
			write( out, 'new', [ 'index.asset.php' ] );
			return true;
		},
	} );

	expect( outcome ).toEqual( {
		ok: false,
		reason: 'incomplete',
		missing: [ 'index.asset.php' ],
	} );
	expect( read( 'index.js' ) ).toBe( 'old:index.js' );
	expect( leftovers() ).toEqual( [] );
} );

test( 'an empty file counts as missing', () => {
	write( live(), 'old' );

	const outcome = safeBuild( {
		root,
		build: ( r, out ) => {
			write( out, 'new' );
			fs.writeFileSync( path.join( out, 'index.js' ), '' );
			return true;
		},
	} );

	expect( outcome.ok ).toBe( false );
	expect( outcome.missing ).toEqual( [ 'index.js' ] );
	expect( read( 'index.js' ) ).toBe( 'old:index.js' );
} );

test( 'the first build on a fresh checkout works, and leftovers of a killed run are cleared', () => {
	write( path.join( root, 'build', '.admin-next' ), 'stale' );
	write( path.join( root, 'build', '.admin-prev' ), 'stale' );

	const outcome = safeBuild( {
		root,
		build: ( r, out ) => {
			expect( fs.existsSync( out ) ).toBe( false );
			write( out, 'new' );
			return true;
		},
	} );

	expect( outcome.ok ).toBe( true );
	expect( read( 'index.js' ) ).toBe( 'new:index.js' );
	expect( leftovers() ).toEqual( [] );
} );

test( 'if the swap itself fails, the old build is put back', () => {
	write( live(), 'old' );
	const real = fs.renameSync;

	jest.spyOn( fs, 'renameSync' ).mockImplementation( ( from, to ) => {
		if (
			String( to ) === live() &&
			String( from ).endsWith( '.admin-next' )
		) {
			throw new Error( 'disk full' );
		}
		return real( from, to );
	} );

	expect( () =>
		safeBuild( {
			root,
			build: ( r, out ) => {
				write( out, 'new' );
				return true;
			},
		} )
	).toThrow( 'disk full' );

	expect( read( 'index.js' ) ).toBe( 'old:index.js' );
	expect( leftovers() ).toEqual( [] );
} );
