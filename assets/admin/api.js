/**
 * Talks to the Kit's REST routes.
 *
 * The nonce is not handled here. WordPress puts it on every `apiFetch` request, and `apiFetch` itself
 * asks for a fresh one and retries once when the old one has expired.
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * The address of a route under the overview route. WordPress gives the base as a plain address with
 * pretty permalinks (`/wp-json/shaped-kit/v1/mcp`) and as `?rest_route=/shaped-kit/v1/mcp` without them.
 * Adding to the end is right for both; adding a `?` would break the second.
 *
 * @param {string} base   The overview address from the page.
 * @param {string} suffix Path to add, such as `products/location-weather`.
 * @return {string} The full address.
 */
export const routeUrl = ( base, suffix ) =>
	`${ String( base ).replace( /\/+$/, '' ) }/${ suffix }`;

const base = () => window.shapedKitAdmin?.restUrl || '';

/**
 * What the dashboard shows: adapter, products, and whether a password can be made.
 *
 * @return {Promise<Object>} The overview.
 */
export const fetchOverview = () => apiFetch( { url: base() } );

/**
 * Switch one product. Resolves with the overview read again after the switch.
 *
 * @param {string}  slug    Product slug.
 * @param {boolean} enabled The state asked for.
 * @return {Promise<Object>} The new overview.
 */
export const switchProduct = ( slug, enabled ) =>
	apiFetch( {
		url: routeUrl( base(), `products/${ encodeURIComponent( slug ) }` ),
		method: 'POST',
		data: { enabled: Boolean( enabled ) },
	} );

/**
 * Create an Application Password for the signed-in user. The password is in the answer once only.
 *
 * @param {string=} name Name to show in the profile.
 * @return {Promise<{password: string, username: string, name: string, uuid: string}>} The credential.
 */
export const createAppPassword = ( name = '' ) =>
	apiFetch( {
		url: routeUrl( base(), 'app-password' ),
		method: 'POST',
		data: name ? { name } : {},
	} );

/**
 * How a failed request reads to the owner. The server words its own refusals (they are plain text
 * written for this screen), so a message it sent wins, except for two cases WordPress words badly.
 *
 * @param {*} error What `apiFetch` rejected with: an object with `code`, `message` and, for REST errors, `data.status`.
 * @return {string} A sentence for the screen.
 */
export const describeError = ( error ) => {
	const code = error?.code;
	const status = error?.data?.status;
	const message = error?.message;

	if ( code === 'fetch_error' || code === 'invalid_json' ) {
		return 'Could not reach the server. Check your connection and try again.';
	}

	// The nonce is refreshed and retried once by apiFetch; this only shows if that failed too.
	if ( code === 'rest_cookie_invalid_nonce' || status === 401 ) {
		return 'Your session has expired. Reload the page and try again.';
	}

	if ( status === 403 ) {
		return 'Only an administrator can change this.';
	}

	// "No route was found" tells an owner nothing, and the real cause is a version mismatch.
	if ( code === 'rest_no_route' ) {
		return 'This version of Shaped Kit does not support that. Update the plugin and reload.';
	}

	if (
		typeof message === 'string' &&
		message.trim() !== '' &&
		status >= 400
	) {
		return message;
	}

	return 'Something went wrong. Try again.';
};
