/**
 * "Test connection": sign in to a product's MCP endpoint the way an AI client would, and say what
 * happened in words an owner can act on.
 *
 * It runs in the browser, with no cookies (`credentials: 'omit'`). WordPress takes the logged-in cookie
 * first and only then looks at an application password, and a cookie without a REST nonce is then
 * refused. Measured on this site: the right password with cookies gets a 401, and without them a 200.
 * So sending cookies would report a good password as failed. It also means the password goes only to
 * the product's own endpoint, never through the Kit's server.
 */
import { __ } from '@wordpress/i18n';
import { credentials } from './snippets';

const TIMEOUT_MS = 15000;

/**
 * Turn what came back into a result.
 *
 * A 401 is ambiguous on purpose: WordPress reads the password from PHP_AUTH_USER and PHP_AUTH_PW, which
 * PHP fills only from the Authorization header, and some hosts remove that header. The answer then looks
 * exactly like a wrong password, so the message names both.
 *
 * @param {number}  status HTTP status.
 * @param {*}       body   Decoded JSON body, or null when it was not JSON.
 * @return {{state: string, message: string}} `state` is ok, rejected, forbidden, missing, error or unexpected.
 */
export const interpret = ( status, body ) => {
	if ( status === 200 && body && body.result && ! body.error ) {
		return {
			state: 'ok',
			message: __(
				'Connected. This endpoint accepted the username and password.',
				'shaped-kit'
			),
		};
	}

	if ( status === 200 ) {
		return {
			state: 'unexpected',
			message: __(
				'The address answered, but not as an MCP server. Check that it is the right address.',
				'shaped-kit'
			),
		};
	}

	if ( status === 401 ) {
		return {
			state: 'rejected',
			message: __(
				'Not accepted. Either the username or application password is wrong, or your host removes the Authorization header before WordPress sees it (some FastCGI setups do).',
				'shaped-kit'
			),
		};
	}

	if ( status === 403 ) {
		return {
			state: 'forbidden',
			message: __(
				'The password is right, but this account is not allowed to use MCP. Use an administrator account.',
				'shaped-kit'
			),
		};
	}

	if ( status === 404 ) {
		return {
			state: 'missing',
			message: __(
				'Nothing answers at this address. Check that the product is switched on and an adapter is loaded.',
				'shaped-kit'
			),
		};
	}

	if ( status >= 500 ) {
		return {
			state: 'error',
			message: __(
				'The server failed while answering. Look at its error log.',
				'shaped-kit'
			),
		};
	}

	return {
		state: 'unexpected',
		message: __( 'The server gave an unexpected answer.', 'shaped-kit' ),
	};
};

/**
 * What a request that never got an answer means, in a sentence. Shared with the tool tester.
 *
 * @param {*} failure What `fetch` rejected with.
 * @return {string} The sentence.
 */
export const describeFailure = ( failure ) =>
	failure?.name === 'AbortError'
		? __(
				'No answer within 15 seconds. The server may be slow or the address wrong.',
				'shaped-kit'
		  )
		: __(
				'The browser could not reach this address. Check the address, the certificate and your connection.',
				'shaped-kit'
		  );

/**
 * Try one product.
 *
 * @param {Object}   product            A product with `slug`, `name` and `endpoint_url`.
 * @param {Object}   login              The typed credentials.
 * @param {string}   login.username     Username.
 * @param {string}   login.password     Application password.
 * @param {Object=}  options            Test hooks.
 * @param {Function} options.fetchImpl  `fetch` to use. Defaults to the browser's.
 * @param {number}   options.timeout    Milliseconds to wait. Defaults to 15 seconds.
 * @return {Promise<{slug: string, name: string, state: string, message: string}>} The result. Never rejects.
 */
export const testConnection = async (
	product,
	{ username, password },
	{ fetchImpl = window.fetch.bind( window ), timeout = TIMEOUT_MS } = {}
) => {
	const identity = { slug: product.slug, name: product.name || product.slug };
	const { header, ready } = credentials( username, password );

	if ( ! ready ) {
		return {
			...identity,
			state: 'incomplete',
			message: __(
				'Type the username and application password first.',
				'shaped-kit'
			),
		};
	}

	const controller = new window.AbortController();
	const timer = setTimeout( () => controller.abort(), timeout );

	try {
		const response = await fetchImpl( product.endpoint_url, {
			method: 'POST',
			credentials: 'omit',
			signal: controller.signal,
			headers: {
				Authorization: `Basic ${ header }`,
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
			},
			body: JSON.stringify( {
				jsonrpc: '2.0',
				id: 1,
				method: 'initialize',
				params: {
					protocolVersion: '2024-11-05',
					capabilities: {},
					clientInfo: { name: 'shaped-kit-test', version: '1' },
				},
			} ),
		} );

		const body = await response.json().catch( () => null );

		return { ...identity, ...interpret( response.status, body ) };
	} catch ( failure ) {
		return {
			...identity,
			state: 'unreachable',
			message: describeFailure( failure ),
		};
	} finally {
		clearTimeout( timer );
	}
};
