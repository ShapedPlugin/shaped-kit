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
 * A 401 says what it means in three ways, and each gets its own sentence:
 * - JSON with `host_config_hint`: the server found no credentials in the request (neither an Authorization
 *   header nor PHP_AUTH_USER), so the host removed the header. The browser did send it. See `TransportGuard`.
 * - JSON without it: the header arrived and WordPress refused the username or password.
 * - Not JSON: WordPress always answers in JSON, so something in front of it (HTTP basic auth on the whole
 *   site, a firewall) answered before the request got there.
 *
 * @param {number}  status HTTP status.
 * @param {*}       body   Decoded JSON body, or null when it was not JSON.
 * @return {{state: string, message: string}} `state` is ok, rejected, forbidden, missing, error or unexpected.
 */
/**
 * The tone a test result is drawn in: green for a connection, amber for "type something first",
 * red for every way it can fail.
 *
 * @param {string} state A result's state from this file.
 * @return {string} good, warn or bad.
 */
export const resultTone = ( state ) => {
	if ( state === 'ok' ) {
		return 'good';
	}

	return state === 'incomplete' ? 'warn' : 'bad';
};

/**
 * What a set of test results says about the Authorization header, worst case first: with several products
 * one blocked answer outweighs the others. Both the Connect and the Diagnostics panel read it from here,
 * so they cannot disagree.
 *
 * @param {Object[]|null} results Results from `testConnection()`, or null before any test.
 * @return {'blocked'|'intercepted'|'passed'|'unknown'} The header's state.
 */
export const summariseHeader = ( results ) => {
	const list = Object.values( results || {} );

	if ( list.some( ( r ) => r.headerStatus === 'host_blocked' ) ) {
		return 'blocked';
	}

	if ( list.some( ( r ) => r.headerStatus === 'intercepted' ) ) {
		return 'intercepted';
	}

	if (
		list.some( ( r ) => r.headerStatus === 'passed' || r.state === 'ok' )
	) {
		return 'passed';
	}

	return 'unknown';
};

export const interpret = ( status, body ) => {
	if ( status === 200 && body && body.result && ! body.error ) {
		return {
			state: 'ok',
			title: __( 'Connected', 'shaped-kit' ),
			message: __(
				'Connected. This endpoint accepted the username and password.',
				'shaped-kit'
			),
			actionHint: __(
				'Your credentials are valid and ready to use in your AI client.',
				'shaped-kit'
			),
			headerStatus: 'passed',
		};
	}

	if ( status === 200 ) {
		return {
			state: 'unexpected',
			title: __( 'Non-MCP Response', 'shaped-kit' ),
			message: __(
				'The address answered, but not as an MCP server. Check that it is the right address.',
				'shaped-kit'
			),
			actionHint: __(
				'Verify the product endpoint URL in Settings.',
				'shaped-kit'
			),
			headerStatus: null,
		};
	}

	if ( status === 401 ) {
		let message;
		let title;
		let actionHint;
		let headerStatus;

		if ( body && typeof body === 'object' && body.host_config_hint ) {
			title = __( 'Authorization Header Blocked by Host', 'shaped-kit' );
			message =
				__(
					'Your server is not passing the Authorization header to WordPress, so the username and password never arrived. This is a server setting, not a wrong password.',
					'shaped-kit'
				) +
				' ' +
				__(
					'Fix it in the server settings. Apache: save Settings > Permalinks with a pretty structure (WordPress then writes the rule into .htaccess if it can), or add SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1 to .htaccess. Nginx: add fastcgi_param HTTP_AUTHORIZATION $http_authorization; to the PHP location block and reload.',
					'shaped-kit'
				);
			actionHint = __(
				'Configure your web server (Apache or Nginx) to forward the Authorization header.',
				'shaped-kit'
			);
			headerStatus = 'host_blocked';
		} else if ( body && typeof body === 'object' ) {
			title = __( 'Invalid or Revoked Credentials', 'shaped-kit' );
			message = __(
				'The username or application password was not accepted. Check that the username is the account the password was made for, and that the password was not revoked (the Revoke button here, or deleting it on your profile page, stops it working for good). Spaces in the password are fine.',
				'shaped-kit'
			);
			actionHint = __(
				'Click "Create application password" above to get a fresh password, then copy the updated snippet.',
				'shaped-kit'
			);
			headerStatus = 'passed';
		} else {
			title = __( 'External 401 Interception', 'shaped-kit' );
			message = __(
				'Something in front of WordPress answered 401 before the request reached this plugin, such as password protection on the whole site (HTTP basic auth on a staging site), a firewall or a security plugin. WordPress never saw the credentials.',
				'shaped-kit'
			);
			actionHint = __(
				'Ensure firewalls or server-level HTTP auth do not block WordPress REST API routes.',
				'shaped-kit'
			);
			headerStatus = 'intercepted';
		}

		return {
			state: 'rejected',
			title,
			message,
			actionHint,
			headerStatus,
		};
	}

	if ( status === 403 ) {
		return {
			state: 'forbidden',
			title: __( 'Access Forbidden', 'shaped-kit' ),
			message: __(
				'The password is right, but this account is not allowed to use MCP. Use an administrator account.',
				'shaped-kit'
			),
			actionHint: __(
				'Log in with an administrator account that has manage_options capability.',
				'shaped-kit'
			),
			headerStatus: 'passed',
		};
	}

	if ( status === 404 ) {
		return {
			state: 'missing',
			title: __( 'Endpoint Not Found / Disabled', 'shaped-kit' ),
			message: __(
				'Nothing answers at this address. Check that the product is switched on and an adapter is loaded.',
				'shaped-kit'
			),
			actionHint: __(
				'Turn ON the toggle switch for this product above.',
				'shaped-kit'
			),
			headerStatus: null,
		};
	}

	if ( status >= 500 ) {
		return {
			state: 'error',
			title: __( 'Server Error', 'shaped-kit' ),
			message: __(
				'The server failed while answering. Look at its error log.',
				'shaped-kit'
			),
			actionHint: __(
				'Inspect your PHP error log or WordPress debug.log for details.',
				'shaped-kit'
			),
			headerStatus: null,
		};
	}

	return {
		state: 'unexpected',
		title: __( 'Unexpected Response', 'shaped-kit' ),
		message: __( 'The server gave an unexpected answer.', 'shaped-kit' ),
		actionHint: __(
			'Check the endpoint URL and server status.',
			'shaped-kit'
		),
		headerStatus: null,
	};
};

/**
 * What a request that never got an answer means, in a sentence. Shared with the tool tester.
 *
 * A browser reports a CORS block and a dead network the same way (a rejected `fetch`), so when the endpoint is
 * on another origin than this page the sentence names CORS as a possible cause, never as the cause.
 *
 * @param {*}      failure     What `fetch` rejected with.
 * @param {string} endpointUrl The address that was called, used to tell whether it is on another origin.
 * @return {string} The sentence.
 */
export const describeFailure = ( failure, endpointUrl = '' ) => {
	if ( failure?.name === 'AbortError' ) {
		return __(
			'No answer within 15 seconds. The server may be slow or the address wrong.',
			'shaped-kit'
		);
	}

	if ( typeof window !== 'undefined' && endpointUrl ) {
		try {
			const endpointOrigin = new URL( endpointUrl ).origin;
			if (
				window.location &&
				window.location.origin &&
				window.location.origin !== endpointOrigin
			) {
				return __(
					'The browser could not reach this address. It is on a different origin from this page, so cross-origin (CORS) blocking is one possible cause. That happens when your WordPress site address and home address differ, or on www versus non-www. Check that first, then the address, the certificate and your connection.',
					'shaped-kit'
				);
			}
		} catch ( e ) {
			// Fall through to standard message.
		}
	}

	return __(
		'The browser could not reach this address. Check the address, the certificate and your connection.',
		'shaped-kit'
	);
};

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
			title: __( 'Credentials Required', 'shaped-kit' ),
			message: __(
				'Type the username and application password first.',
				'shaped-kit'
			),
			actionHint: __(
				'Click "Create application password" above to generate a password.',
				'shaped-kit'
			),
			headerStatus: null,
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
			title: __( 'Connection Failed / Unreachable', 'shaped-kit' ),
			message: describeFailure( failure, product.endpoint_url ),
			actionHint: __(
				'Check that your site is running and that the endpoint URL is accessible from your network.',
				'shaped-kit'
			),
			headerStatus: null,
		};
	} finally {
		clearTimeout( timer );
	}
};
