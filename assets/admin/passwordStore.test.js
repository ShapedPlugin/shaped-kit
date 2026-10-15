import {
	PASSWORD_TTL_MS,
	clearPassword,
	loadPassword,
	savePassword,
	storageKeyFor,
} from './passwordStore';

const KEY = 'shaped_kit_app_pass_admin';

beforeEach( () => {
	window.localStorage.clear();
	window.sessionStorage.clear();
} );

test( 'the key is per user', () => {
	expect( storageKeyFor( 'admin' ) ).toBe( KEY );
	expect( storageKeyFor( 'editor' ) ).not.toBe( KEY );
	expect( storageKeyFor( '' ) ).toBe( 'shaped_kit_app_pass' );
} );

test( 'a saved password comes back until its time is up, then it is gone and removed', () => {
	const now = 1_000_000;
	const expiresAt = savePassword( KEY, 'abcd efgh', now );

	expect( expiresAt ).toBe( now + PASSWORD_TTL_MS );
	expect( loadPassword( KEY, now + 1 ) ).toEqual( {
		password: 'abcd efgh',
		expiresAt,
	} );

	expect( loadPassword( KEY, expiresAt ).password ).toBe( '' );
	expect( window.localStorage.getItem( KEY ) ).toBeNull();
} );

test( 'an expiry stretched beyond the limit is refused and removed', () => {
	const now = 1_000_000;
	window.localStorage.setItem(
		KEY,
		JSON.stringify( { p: 'x', e: now + PASSWORD_TTL_MS + 1 } )
	);

	expect( loadPassword( KEY, now ).password ).toBe( '' );
	expect( window.localStorage.getItem( KEY ) ).toBeNull();
} );

test.each( [
	[ 'plain text', 'just-a-password' ],
	[ 'broken json', '{"p":' ],
	[ 'no password', JSON.stringify( { p: '', e: Date.now() + 1000 } ) ],
	[ 'no expiry', JSON.stringify( { p: 'x' } ) ],
	[ 'a non-number expiry', JSON.stringify( { p: 'x', e: 'soon' } ) ],
] )( '%s is not trusted and is removed', ( label, raw ) => {
	window.localStorage.setItem( KEY, raw );

	expect( loadPassword( KEY ).password ).toBe( '' );
	expect( window.localStorage.getItem( KEY ) ).toBeNull();
} );

test( 'saving an empty password removes the saved one', () => {
	savePassword( KEY, 'x' );

	expect( savePassword( KEY, '' ) ).toBe( 0 );
	expect( window.localStorage.getItem( KEY ) ).toBeNull();
} );

test( 'clear removes it from both kinds of storage', () => {
	window.localStorage.setItem( KEY, 'a' );
	window.sessionStorage.setItem( KEY, 'b' );

	clearPassword( KEY );

	expect( window.localStorage.getItem( KEY ) ).toBeNull();
	expect( window.sessionStorage.getItem( KEY ) ).toBeNull();
} );

test( 'where localStorage is blocked it falls back to sessionStorage', () => {
	const original = Storage.prototype.setItem;
	const spy = jest
		.spyOn( Storage.prototype, 'setItem' )
		.mockImplementation( function ( key, value ) {
			if ( this === window.localStorage ) {
				throw new Error( 'blocked' );
			}
			return original.call( this, key, value );
		} );

	try {
		const expiresAt = savePassword( KEY, 'x', 1_000 );

		expect( expiresAt ).toBeGreaterThan( 0 );
		expect( window.localStorage.getItem( KEY ) ).toBeNull();
		expect( loadPassword( KEY, 1_001 ).password ).toBe( 'x' );
	} finally {
		spy.mockRestore();
	}
} );

test( 'where both are blocked nothing is kept and nothing throws', () => {
	const spy = jest
		.spyOn( Storage.prototype, 'setItem' )
		.mockImplementation( () => {
			throw new Error( 'blocked' );
		} );

	try {
		expect( savePassword( KEY, 'x' ) ).toBe( 0 );
		expect( loadPassword( KEY ).password ).toBe( '' );
	} finally {
		spy.mockRestore();
	}
} );
