/**
 * Keeps the application password across a page refresh, for a limited time.
 *
 * WordPress shows a new password only once, and the Connect panel and Try a Tool need it again after
 * every refresh. So the browser holds it, per user, and drops it when time is up. What is kept is one
 * JSON value, `{ p: password, e: expiry in ms }`, never more than `PASSWORD_TTL_MS` ahead. Anything else
 * under the key (an older plain-text value, a malformed one, a stretched expiry, an expired one) is
 * removed on read, so it cannot outlive its time. If the browser blocks storage, the password just lasts
 * until the page closes.
 *
 * `localStorage` is readable by any script running on this admin origin, which is why the time limit
 * exists and why the panel offers Clear and a revoke.
 */

/** How long a saved password stays in the browser. */
export const PASSWORD_TTL_MS = 8 * 60 * 60 * 1000;

const NOTHING = { password: '', expiresAt: 0 };

/**
 * The storage to use: `localStorage`, or `sessionStorage` where that is blocked. Null when neither works.
 *
 * @return {?Storage} The storage.
 */
const pickStorage = () => {
	for ( const name of [ 'localStorage', 'sessionStorage' ] ) {
		try {
			const store = window[ name ];
			store.setItem( '__shaped_kit_probe', '1' );
			store.removeItem( '__shaped_kit_probe' );
			return store;
		} catch ( e ) {
			// Try the next one.
		}
	}

	return null;
};

/**
 * Where one user's password is kept.
 *
 * @param {string} login The signed-in user's login.
 * @return {string} The storage key.
 */
export const storageKeyFor = ( login ) =>
	login ? `shaped_kit_app_pass_${ login }` : 'shaped_kit_app_pass';

/**
 * The saved password, if it is still good.
 *
 * @param {string} key The storage key.
 * @param {number} now The current time in ms. Passed in by tests.
 * @return {{password: string, expiresAt: number}} The password and when it expires, or empty.
 */
export const loadPassword = ( key, now = Date.now() ) => {
	const store = pickStorage();

	if ( ! store ) {
		return NOTHING;
	}

	try {
		const raw = store.getItem( key );

		if ( ! raw ) {
			return NOTHING;
		}

		const saved = JSON.parse( raw );

		if (
			typeof saved?.p === 'string' &&
			saved.p !== '' &&
			Number.isFinite( saved.e ) &&
			saved.e > now &&
			saved.e - now <= PASSWORD_TTL_MS
		) {
			return { password: saved.p, expiresAt: saved.e };
		}

		store.removeItem( key );
	} catch ( e ) {
		try {
			store.removeItem( key );
		} catch ( failure ) {
			// Nothing more to do.
		}
	}

	return NOTHING;
};

/**
 * Save a password for `PASSWORD_TTL_MS`, or remove it when it is empty.
 *
 * @param {string} key      The storage key.
 * @param {string} password The password.
 * @param {number} now      The current time in ms. Passed in by tests.
 * @return {number} When it expires, in ms. Zero when nothing was kept.
 */
export const savePassword = ( key, password, now = Date.now() ) => {
	if ( ! password ) {
		clearPassword( key );
		return 0;
	}

	const store = pickStorage();
	const expiresAt = now + PASSWORD_TTL_MS;

	try {
		store.setItem( key, JSON.stringify( { p: password, e: expiresAt } ) );
	} catch ( e ) {
		return 0;
	}

	return expiresAt;
};

/**
 * Remove the saved password, wherever it was kept.
 *
 * @param {string} key The storage key.
 */
export const clearPassword = ( key ) => {
	for ( const name of [ 'localStorage', 'sessionStorage' ] ) {
		try {
			window[ name ].removeItem( key );
		} catch ( e ) {
			// Blocked storage has nothing to remove.
		}
	}
};
