import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { createAppPassword, describeError, revokeAppPasswords } from './api';
import { testConnection } from './connectionTest';
import {
	PASSWORD_TTL_MS,
	clearPassword,
	loadPassword,
	savePassword,
	storageKeyFor,
} from './passwordStore';
import { buildSnippet, clients } from './snippets';
import ToolTester from './ToolTester';

/**
 * The tone a test result is drawn in: green for a connection, amber for "type something first",
 * red for every way it can fail.
 *
 * @param {string} state A result's state from `connectionTest.js`.
 * @return {string} good, warn or bad.
 */
const resultTone = ( state ) => {
	if ( state === 'ok' ) {
		return 'good';
	}

	return state === 'incomplete' ? 'warn' : 'bad';
};

/**
 * Whether an address is on a development machine, where a self-signed certificate is normal.
 *
 * @param {string} url An endpoint address.
 * @return {boolean} True for localhost, a loopback address, or a .test, .local or .localhost name.
 */
export const isLocalSite = ( url ) => {
	try {
		const host = new URL( url ).hostname.toLowerCase();

		return (
			[ 'localhost', '127.0.0.1', '[::1]' ].includes( host ) ||
			/\.(test|local|localhost)$/.test( host )
		);
	} catch ( failure ) {
		return false;
	}
};

/**
 * Why WordPress will not make an application password, in a sentence.
 *
 * @param {string} reason Reason code from the overview.
 * @return {string} The sentence.
 */
const unavailableText = ( reason ) => {
	switch ( reason ) {
		case 'site_disabled':
			return __(
				'Application passwords are off on this site. WordPress needs HTTPS for them unless the site runs in a local environment.',
				'shaped-kit'
			);
		case 'user_disabled':
			return __(
				'Application passwords are off for your user account.',
				'shaped-kit'
			);
		case 'unsupported':
			return __(
				'This WordPress version does not support application passwords.',
				'shaped-kit'
			);
		default:
			return __(
				'Application passwords are not available right now.',
				'shaped-kit'
			);
	}
};

/**
 * "Connect a client": the credentials, the client tabs, and the text to paste.
 *
 * Only products that are on go into the text, because the route of a product that is off does not
 * exist and pasting it would only fail. The password lives in this component's memory for as long as the
 * page is open; it is never stored, and it goes nowhere except into the text the owner copies.
 *
 * @param {Object} props                     Component props.
 * @param {Object} props.overview            The overview from the server.
 * @param {string} [props.selectedProductSlug] Currently active product slug from selector.
 */
const ConnectPanel = ( { overview, selectedProductSlug } ) => {
	const support = overview?.app_password || {};
	const allEnabledProducts = useMemo(
		() =>
			( overview?.products || [] ).filter(
				( product ) => product.enabled && product.endpoint_url
			),
		[ overview ]
	);
	const list = clients();

	const storageKey = storageKeyFor( support.user_login );

	const [ scope, setScope ] = useState( 'selected' ); // 'selected' | 'all'
	const [ username, setUsername ] = useState( support.user_login || '' );
	// The password a refresh would lose, kept for a while by `passwordStore`.
	const [ kept ] = useState( () => loadPassword( storageKey ) );
	const [ password, setPassword ] = useState( kept.password );
	const [ expiresAt, setExpiresAt ] = useState( kept.expiresAt );
	const [ showPassword, setShowPassword ] = useState( false );
	const [ client, setClient ] = useState( list[ 0 ].key );
	const [ localDev, setLocalDev ] = useState( () =>
		allEnabledProducts.some( ( product ) =>
			isLocalSite( product.endpoint_url )
		)
	);
	const [ isCreating, setIsCreating ] = useState( false );
	const [ isRevoking, setIsRevoking ] = useState( false );
	const [ created, setCreated ] = useState( null );
	const [ revokedNotice, setRevokedNotice ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ copied, setCopied ] = useState( false );
	const [ results, setResults ] = useState( null );
	const [ pendingTests, setPendingTests ] = useState( 0 );
	const latestTest = useRef( 0 );
	const isTesting = pendingTests > 0;

	const updatePassword = ( val ) => {
		setPassword( val );
		setExpiresAt( savePassword( storageKey, val ) );
	};

	// When the time is up the password goes from the page as well as from storage.
	useEffect( () => {
		if ( ! expiresAt ) {
			return undefined;
		}

		const timer = setTimeout(
			() => {
				clearPassword( storageKey );
				setPassword( '' );
				setExpiresAt( 0 );
			},
			Math.max( expiresAt - Date.now(), 0 )
		);

		return () => clearTimeout( timer );
	}, [ expiresAt, storageKey ] );

	// Determine active products for the snippet based on scope:
	const selectedProduct = useMemo(
		() =>
			( overview?.products || [] ).find(
				( p ) =>
					p.slug === selectedProductSlug &&
					p.enabled &&
					p.endpoint_url
			),
		[ overview, selectedProductSlug ]
	);

	const products = useMemo( () => {
		if ( scope === 'selected' && selectedProduct ) {
			return [ selectedProduct ];
		}
		return allEnabledProducts;
	}, [ scope, selectedProduct, allEnabledProducts ] );

	const snippet = buildSnippet( client, {
		products,
		username,
		password,
		localDev,
	} );
	const current = list.find( ( item ) => item.key === client );

	const create = () => {
		setIsCreating( true );
		setError( '' );
		setCreated( null );
		setRevokedNotice( '' );

		createAppPassword( '', true )
			.then( ( made ) => {
				setUsername( made.username );
				updatePassword( made.password );
				setCreated( made );
			} )
			.catch( ( failure ) => setError( describeError( failure ) ) )
			.finally( () => setIsCreating( false ) );
	};

	const handleRevoke = () => {
		setIsRevoking( true );
		setError( '' );
		setRevokedNotice( '' );

		revokeAppPasswords()
			.then( ( result ) => {
				updatePassword( '' );
				setCreated( null );
				setRevokedNotice(
					result?.message ||
						__(
							'Shaped Kit application passwords revoked.',
							'shaped-kit'
						)
				);
			} )
			.catch( ( failure ) => setError( describeError( failure ) ) )
			.finally( () => setIsRevoking( false ) );
	};

	// What was typed or switched on is what a result was about. When either changes, the result is
	// no longer true of the screen, so it goes, and an answer still on its way is dropped.
	useEffect( () => {
		latestTest.current += 1;
		setResults( null );
	}, [ username, password, products ] );

	const runTest = () => {
		const run = ++latestTest.current;

		setPendingTests( ( count ) => count + 1 );
		setResults( null );

		Promise.all(
			products.map( ( product ) =>
				testConnection( product, { username, password } )
			)
		)
			.then( ( answers ) => {
				if ( run === latestTest.current ) {
					setResults( answers );
				}
			} )
			.finally( () => setPendingTests( ( count ) => count - 1 ) );
	};

	const copy = async () => {
		try {
			await navigator.clipboard.writeText( snippet );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch ( failure ) {
			setCopied( false );
		}
	};

	return (
		<>
			<section className="shaped-kit-card shaped-kit-connect">
				<header className="shaped-kit-connect-head">
					<div className="shaped-kit-connect-intro">
						<h2>{ __( 'Connect a client', 'shaped-kit' ) }</h2>
						<p className="shaped-kit-card-note">
							{ __(
								'Each product runs its own MCP server, so an AI client needs one entry per product. One application password works for all of them. Create one here, or paste one you made on your profile page, and the text below fills itself in. Creating a new one replaces your earlier Shaped Kit passwords.',
								'shaped-kit'
							) }
						</p>
					</div>

					<div className="shaped-kit-create">
						<button
							type="button"
							className="button button-primary"
							onClick={ create }
							disabled={ ! support.available || isCreating }
						>
							{ isCreating
								? __( 'Creating…', 'shaped-kit' )
								: __(
										'Create application password',
										'shaped-kit'
								  ) }
						</button>
						<button
							type="button"
							className="button"
							onClick={ handleRevoke }
							disabled={ ! support.available || isRevoking }
							title={ __(
								'Delete every password Shaped Kit made for your user, including the one saved in this browser. Passwords you made yourself are not touched.',
								'shaped-kit'
							) }
						>
							{ isRevoking
								? __( 'Revoking…', 'shaped-kit' )
								: __(
										'Revoke all Shaped Kit passwords',
										'shaped-kit'
								  ) }
						</button>
						{ support.manage_url && (
							<a
								href={ support.manage_url }
								className="button"
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Manage passwords', 'shaped-kit' ) }
							</a>
						) }
					</div>
				</header>

				{ ! support.available && (
					<p className="shaped-kit-card-note">
						{ unavailableText( support.reason ) }
					</p>
				) }

				{ created && (
					<p className="shaped-kit-created" role="status">
						{ sprintf(
							/* translators: %s: the name the password was given. */
							__(
								'Created "%s". WordPress shows this password only once, so copy the text below now.',
								'shaped-kit'
							),
							created.name
						) }
						{ created.revoked > 0 &&
							' ' +
								sprintf(
									/* translators: %d: how many earlier passwords were revoked. */
									_n(
										'It replaced %d earlier Shaped Kit password. A client still using that one needs this new password.',
										'It replaced %d earlier Shaped Kit passwords. A client still using one of them needs this new password.',
										created.revoked,
										'shaped-kit'
									),
									created.revoked
								) }
					</p>
				) }

				{ revokedNotice && (
					<p className="shaped-kit-created" role="status">
						{ revokedNotice }
					</p>
				) }

				{ error && (
					<p className="shaped-kit-connect-error" role="alert">
						{ error }
					</p>
				) }

				<div className="shaped-kit-credentials">
					<label>
						<span>{ __( 'Username', 'shaped-kit' ) }</span>
						<input
							type="text"
							value={ username }
							autoComplete="off"
							onChange={ ( event ) =>
								setUsername( event.target.value )
							}
						/>
					</label>
					<label>
						<span>
							{ __( 'Application password', 'shaped-kit' ) }
						</span>
						<span className="shaped-kit-password">
							<input
								type={ showPassword ? 'text' : 'password' }
								value={ password }
								// "off" is ignored by password managers. "new-password" is not: it stops
								// a saved login being dropped into the field without React hearing of it,
								// which left the field and the text below it disagreeing.
								autoComplete="new-password"
								onChange={ ( event ) =>
									updatePassword( event.target.value )
								}
							/>
							<button
								type="button"
								className="button"
								aria-pressed={ showPassword }
								onClick={ () =>
									setShowPassword( ! showPassword )
								}
							>
								{ showPassword
									? __( 'Hide', 'shaped-kit' )
									: __( 'Show', 'shaped-kit' ) }
							</button>
							{ password && (
								<button
									type="button"
									className="button"
									title={ __(
										'Remove the password from this page and this browser',
										'shaped-kit'
									) }
									onClick={ () => updatePassword( '' ) }
								>
									{ __( 'Clear', 'shaped-kit' ) }
								</button>
							) }
						</span>
					</label>
				</div>

				{ expiresAt > 0 && (
					<p className="shaped-kit-card-note shaped-kit-password-note">
						{ sprintf(
							/* translators: %d: hours the password stays in this browser. */
							_n(
								'Saved in this browser for up to %d hour, so a refresh keeps it. Clear removes it now.',
								'Saved in this browser for up to %d hours, so a refresh keeps it. Clear removes it now.',
								PASSWORD_TTL_MS / 3600000,
								'shaped-kit'
							),
							PASSWORD_TTL_MS / 3600000
						) }
					</p>
				) }

				<div className="shaped-kit-connect-subnav">
					<div className="shaped-kit-tabs" role="tablist">
						{ list.map( ( item ) => (
							<button
								key={ item.key }
								type="button"
								role="tab"
								aria-selected={ client === item.key }
								className="shaped-kit-tab"
								onClick={ () => {
									setClient( item.key );
									setCopied( false );
								} }
							>
								{ item.label }
							</button>
						) ) }
					</div>

					{ selectedProduct && allEnabledProducts.length > 1 && (
						<div
							className="shaped-kit-scope-selector"
							role="group"
							aria-label={ __(
								'Snippet configuration scope',
								'shaped-kit'
							) }
						>
							<button
								type="button"
								className={ `shaped-kit-scope-btn ${
									scope === 'selected' ? 'is-active' : ''
								}` }
								onClick={ () => setScope( 'selected' ) }
							>
								{ sprintf(
									/* translators: %s: product name */
									__( '%s only', 'shaped-kit' ),
									selectedProduct.name
								) }
							</button>
							<button
								type="button"
								className={ `shaped-kit-scope-btn ${
									scope === 'all' ? 'is-active' : ''
								}` }
								onClick={ () => setScope( 'all' ) }
							>
								{ sprintf(
									/* translators: %d: count of enabled products */
									__( 'All enabled (%d)', 'shaped-kit' ),
									allEnabledProducts.length
								) }
							</button>
						</div>
					) }
				</div>

				{ ( client === 'claude-desktop' ||
					client === 'gemini-cli' ) && (
					<label className="shaped-kit-check">
						<input
							type="checkbox"
							checked={ localDev }
							onChange={ ( event ) =>
								setLocalDev( event.target.checked )
							}
						/>
						<span>
							{ __(
								'Local site with a self-signed certificate (turns off TLS checks for this client)',
								'shaped-kit'
							) }
						</span>
					</label>
				) }

				<p className="shaped-kit-card-note">{ current.note }</p>

				{ snippet ? (
					<div className="shaped-kit-snippet">
						<pre>
							<code>{ snippet }</code>
						</pre>
						<div className="shaped-kit-snippet-actions">
							<button
								type="button"
								className="button"
								onClick={ copy }
							>
								{ copied
									? __( 'Copied', 'shaped-kit' )
									: __( 'Copy snippet', 'shaped-kit' ) }
							</button>
							<button
								type="button"
								className="button"
								onClick={ runTest }
								disabled={ isTesting }
							>
								{ isTesting
									? __( 'Testing…', 'shaped-kit' )
									: __( 'Test connection', 'shaped-kit' ) }
							</button>
						</div>

						{ results && (
							<ul className="shaped-kit-results" role="status">
								{ results.map( ( result ) => (
									<li
										key={ result.slug }
										className={ `shaped-kit-result shaped-kit-result-${ resultTone(
											result.state
										) }` }
									>
										<strong>{ result.name }</strong>
										<span>{ result.message }</span>
									</li>
								) ) }
							</ul>
						) }
					</div>
				) : (
					<p className="shaped-kit-card-note">
						{ __(
							'Turn on a product above to get something to paste.',
							'shaped-kit'
						) }
					</p>
				) }
			</section>

			<ToolTester
				products={ products }
				username={ username }
				password={ password }
				selectedProductSlug={ selectedProductSlug }
			/>
		</>
	);
};

export default ConnectPanel;
