/**
 * The text an owner pastes into an AI client to connect it: one builder per client.
 *
 * Pure functions, so every client's format is tested on its own. The shapes follow what each client
 * documents for adding an MCP server.
 *
 * Nothing here stores or sends the password: it goes into the text the owner copies, and nowhere else.
 */
import { __ } from '@wordpress/i18n';

/**
 * The clients the panel offers, in tab order, with the line shown above each snippet.
 *
 * @return {Array<{key: string, label: string, note: string}>} The clients.
 */
export const clients = () => [
	{
		key: 'claude-code',
		label: __( 'Claude Code', 'shaped-kit' ),
		note: __(
			'Paste into your terminal, then run `claude` and the tools appear under MCP servers.',
			'shaped-kit'
		),
		tip: __(
			'Updating an existing server? Claude Code ignores `claude mcp add` if the server name already exists. Run `claude mcp remove <slug>` first before adding it with a new password.',
			'shaped-kit'
		),
	},
	{
		key: 'codex',
		label: __( 'Codex', 'shaped-kit' ),
		note: __(
			'Open Settings → Connect to a custom MCP, choose the Streamable HTTP tab, and fill in these fields.',
			'shaped-kit'
		),
	},
	{
		key: 'copilot',
		label: __( 'GitHub Copilot', 'shaped-kit' ),
		note: __(
			'Paste this JSON into your VS Code MCP configuration (.vscode/mcp.json or User settings).',
			'shaped-kit'
		),
		tip: __(
			'Restart required: Reload the VS Code window after saving settings.',
			'shaped-kit'
		),
	},
	{
		key: 'cursor',
		label: __( 'Cursor', 'shaped-kit' ),
		note: __(
			'Paste this JSON into Cursor → Settings → MCP, then restart Cursor.',
			'shaped-kit'
		),
		tip: __(
			'Restart required: After pasting into Cursor settings, reload the Cursor window or restart Cursor.',
			'shaped-kit'
		),
	},
	{
		key: 'claude-desktop',
		label: __( 'Claude Desktop', 'shaped-kit' ),
		note: __(
			'Add these entries inside the "mcpServers" object of claude_desktop_config.json. If connecting to a local HTTPS dev site with a self-signed certificate, add NODE_TLS_REJECT_UNAUTHORIZED: 0 (note: disables certificate checks). If the file already has one, merge them into it rather than pasting a second object, and check there is no comma after the last entry. Then restart Claude Desktop. If it cannot find npx, replace "npx" with its full path (run `which npx`).',
			'shaped-kit'
		),
		tip: __(
			'Restart required: Claude Desktop reads configuration only on startup. After saving the file, quit Claude Desktop completely (Quit ⌘Q / Alt+F4) and reopen it.',
			'shaped-kit'
		),
	},
	{
		key: 'gemini-cli',
		label: __( 'Gemini CLI', 'shaped-kit' ),
		note: __(
			'Paste this JSON into your Gemini CLI settings file (~/.gemini/settings.json).',
			'shaped-kit'
		),
		tip: __(
			'Restart required: Reload your terminal or restart Gemini CLI after saving settings.',
			'shaped-kit'
		),
	},
	{
		key: 'generic',
		label: __( 'Other', 'shaped-kit' ),
		note: __(
			'Use the address and the Basic header with any HTTP MCP client. The curl line is a quick test.',
			'shaped-kit'
		),
	},
];

const PLACEHOLDER_USER = '<your-username>';
const PLACEHOLDER_PASSWORD = '<your-application-password>';

/**
 * Base64 of text that may hold any character (`btoa` alone throws on a name such as "José").
 *
 * @param {string} text Text to encode.
 * @return {string} Base64.
 */
const toBase64 = ( text ) =>
	window.btoa(
		encodeURIComponent( text ).replace( /%([0-9A-F]{2})/g, ( match, hex ) =>
			String.fromCharCode( parseInt( hex, 16 ) )
		)
	);

/**
 * A value made safe inside single quotes in a shell command.
 *
 * @param {string} value Any text.
 * @return {string} The text with every single quote closed, escaped and reopened.
 */
export const shellQuote = ( value ) => String( value ).replace( /'/g, "'\\''" );

/**
 * The credentials as the snippets use them. WordPress ignores the spaces in an application password
 * (the profile page shows it in groups of four), so they are dropped. A missing value stays a
 * placeholder, and the encoded header is only built when both are real.
 *
 * @param {string} username Username.
 * @param {string} password Application password.
 * @return {{user: string, password: string, header: string, ready: boolean}} The credentials.
 */
export const credentials = ( username, password ) => {
	const user = String( username || '' ).trim();
	const secret = String( password || '' ).replace( /\s+/g, '' );
	const ready = user !== '' && secret !== '';

	return {
		user: user || PLACEHOLDER_USER,
		password: secret || PLACEHOLDER_PASSWORD,
		header: ready
			? toBase64( `${ user }:${ secret }` )
			: '<base64(your-username:your-application-password)>',
		ready,
	};
};

const entries = ( products ) =>
	( Array.isArray( products ) ? products : [] ).filter(
		( product ) => product?.slug && product?.endpoint_url
	);

const byKey = ( list, build ) =>
	Object.fromEntries(
		list.map( ( product ) => [ product.slug, build( product ) ] )
	);

const json = ( value ) => JSON.stringify( value, null, 2 );

/**
 * Build the text for one client.
 *
 * @param {string}  client                  A key from `clients()`.
 * @param {Object}  options                 What to build from.
 * @param {Array}   options.products        Products with `slug` and `endpoint_url`. One with no endpoint is left out.
 * @param {string}  options.username        Username.
 * @param {string}  options.password        Application password.
 * @param {boolean} options.localDev        Whether to switch off TLS checks (the desktop client only), for a development site with a self-signed certificate.
 * @return {string} The snippet, or an empty string when there is nothing to connect.
 */
export const buildSnippet = (
	client,
	{ products, username, password, localDev = false }
) => {
	const list = entries( products );

	if ( ! list.length ) {
		return '';
	}

	const {
		user,
		password: secret,
		header,
	} = credentials( username, password );
	const basic = `Basic ${ header }`;

	switch ( client ) {
		case 'claude-code':
			return list
				.map( ( product ) => {
					const isHttps = /^https:/i.test( product.endpoint_url );
					const useTlsFlag = localDev && isHttps;
					const envFlags = [
						useTlsFlag
							? '  --env NODE_TLS_REJECT_UNAUTHORIZED=0 \\'
							: null,
						`  --env WP_API_URL=${ product.endpoint_url } \\`,
						`  --env WP_API_USERNAME='${ shellQuote( user ) }' \\`,
						`  --env WP_API_PASSWORD='${ shellQuote(
							secret
						) }' \\`,
						'  --env OAUTH_ENABLED=false \\',
					]
						.filter( Boolean )
						.join( '\n' );

					const tlsWarning = useTlsFlag
						? '# Note: NODE_TLS_REJECT_UNAUTHORIZED=0 disables TLS certificate checks for local self-signed certificates.\n'
						: '';

					return `${ tlsWarning }claude mcp add \\\n  --transport stdio \\\n  ${ product.slug } \\\n${ envFlags }\n  -- npx -y @automattic/mcp-wordpress-remote@latest`;
				} )
				.join( '\n\n' );

		case 'codex':
			return list
				.map( ( product ) =>
					[
						`Name:         ${ product.slug }`,
						'Transport:    Streamable HTTP',
						`URL:          ${ product.endpoint_url }`,
						'Header key:   Authorization',
						`Header value: ${ basic }`,
					].join( '\n' )
				)
				.join( '\n\n' );

		case 'copilot':
			return json( {
				servers: byKey( list, ( product ) => ( {
					type: 'http',
					url: product.endpoint_url,
					headers: { Authorization: basic },
				} ) ),
			} );

		case 'cursor':
			return json( {
				mcpServers: byKey( list, ( product ) => ( {
					url: product.endpoint_url,
					type: 'http',
					headers: { Authorization: basic },
				} ) ),
			} );

		case 'claude-desktop':
		case 'gemini-cli':
			return json( {
				mcpServers: byKey( list, ( product ) => ( {
					command: 'npx',
					args: [ '-y', '@automattic/mcp-wordpress-remote@latest' ],
					env: {
						WP_API_URL: product.endpoint_url,
						WP_API_USERNAME: user,
						WP_API_PASSWORD: secret,
						OAUTH_ENABLED: 'false',
						...( localDev && /^https:/i.test( product.endpoint_url )
							? { NODE_TLS_REJECT_UNAUTHORIZED: '0' }
							: {} ),
					},
				} ) ),
			} );

		case 'generic':
			return list
				.map( ( product ) =>
					[
						`# ${ product.name || product.slug }`,
						`URL:  ${ product.endpoint_url }`,
						`Auth: Authorization: ${ basic }`,
						'',
						'# Quick test',
						`curl -s -u '${ shellQuote( user ) }:${ shellQuote(
							secret
						) }' \\`,
						`  -X POST ${ product.endpoint_url } \\`,
						"  -H 'Content-Type: application/json' \\",
						`  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'`,
					].join( '\n' )
				)
				.join( '\n\n' );

		default:
			return '';
	}
};
