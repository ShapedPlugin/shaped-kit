import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { createAppPassword, describeError, revokeAppPasswords } from './api';
import { resultTone, summariseHeader, testConnection } from './connectionTest';
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
 * Whether an address is on a development machine using HTTPS, where a self-signed certificate is normal.
 *
 * @param {string} url An endpoint address.
 * @return {boolean} True for HTTPS with localhost, a loopback address, or a .test, .local or .localhost name.
 */
export const isLocalSite = ( url ) => {
	try {
		const parsed = new URL( url );
		if ( parsed.protocol !== 'https:' ) {
			return false;
		}

		const host = parsed.hostname.toLowerCase();

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
				'Application passwords are off on this site. WordPress needs HTTPS for them, or on a dev machine set WP_ENVIRONMENT_TYPE=local in wp-config.php or environment variables. If behind a reverse proxy terminating SSL, forward X-Forwarded-Proto and configure wp-config.php.',
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
const ConnectPanel = ( { overview, selectedProductSlug, onNavigateTab } ) => {
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

	const env = overview?.environment || {};
	const envType = env.type || 'production';
	const isSsl = Boolean( env.is_ssl );
	const appPasswordsAvailable = Boolean(
		env.app_passwords_available ?? support?.available
	);

	const headerState = summariseHeader( results );
	const authHeaderPassed = headerState === 'passed';
	const authHeaderBlocked = headerState === 'blocked';
	const authHeaderIntercepted = headerState === 'intercepted';

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

		// One password per client, and making another never revokes the ones already in use: a client that
		// is set up keeps working. Revoking is its own button.
		createAppPassword(
			sprintf(
				/* translators: %s: the AI client the password is for, for example Claude Desktop. */
				__( 'Shaped Kit MCP - %s', 'shaped-kit' ),
				current?.label || ''
			)
		)
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
								'Each product runs its own MCP server, so an AI client needs one entry per product. One application password works for all of them. Create one here, or paste one you made on your profile page, and the text below fills itself in. Each password you create stays valid until you revoke it, so clients you already set up keep working. Make one per client.',
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

				{ current.tip && (
					<div className="shaped-kit-client-tip" role="note">
						<span
							className="dashicons dashicons-info"
							aria-hidden="true"
						/>
						<span>{ current.tip }</span>
					</div>
				) }

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
										<div className="shaped-kit-result-header">
											<strong>{ result.name }</strong>
											{ result.title && (
												<span className="shaped-kit-result-badge">
													{ result.title }
												</span>
											) }
										</div>
										<span className="shaped-kit-result-message">
											{ result.message }
										</span>
										{ result.actionHint && (
											<div className="shaped-kit-result-action">
												<strong>
													{ __(
														'Fix:',
														'shaped-kit'
													) }
												</strong>{ ' ' }
												<span>
													{ result.actionHint }
												</span>
											</div>
										) }
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

				<div className="shaped-kit-environment-check">
					<div className="shaped-kit-environment-header">
						<h4>
							<span
								className="dashicons dashicons-admin-generic"
								aria-hidden="true"
							/>
							<span>
								{ __(
									'Environment & Server Diagnostics',
									'shaped-kit'
								) }
							</span>
						</h4>
						<span className="shaped-kit-environment-subtitle">
							{ __(
								'Checks whether your host environment meets core MCP requirements',
								'shaped-kit'
							) }
						</span>
					</div>

					<div className="shaped-kit-environment-grid">
						<div className="shaped-kit-env-item">
							<span className="shaped-kit-env-label">
								{ __( 'Environment Type', 'shaped-kit' ) }
							</span>
							<span
								className={ `shaped-kit-env-badge ${
									envType === 'local'
										? 'shaped-kit-env-badge-local'
										: 'shaped-kit-env-badge-neutral'
								}` }
							>
								{ envType }
							</span>
							<span className="shaped-kit-env-desc">
								{ envType === 'local'
									? __(
											'Local environment active (WP_ENVIRONMENT_TYPE=local).',
											'shaped-kit'
									  )
									: __(
											'Non-local environment. Plain HTTP disables app passwords.',
											'shaped-kit'
									  ) }
							</span>
						</div>

						<div className="shaped-kit-env-item">
							<span className="shaped-kit-env-label">
								{ __( 'SSL / HTTPS', 'shaped-kit' ) }
							</span>
							<span
								className={ `shaped-kit-env-badge ${
									isSsl
										? 'shaped-kit-env-badge-good'
										: 'shaped-kit-env-badge-warn'
								}` }
							>
								{ isSsl
									? __( 'Active (HTTPS)', 'shaped-kit' )
									: __( 'Inactive (HTTP)', 'shaped-kit' ) }
							</span>
							<span className="shaped-kit-env-desc">
								{ isSsl
									? __(
											'HTTPS is enabled for secure communication.',
											'shaped-kit'
									  )
									: __(
											'Plain HTTP. If behind an SSL proxy, forward X-Forwarded-Proto and configure wp-config.php.',
											'shaped-kit'
									  ) }
							</span>
						</div>

						<div className="shaped-kit-env-item">
							<span className="shaped-kit-env-label">
								{ __( 'Application Passwords', 'shaped-kit' ) }
							</span>
							<span
								className={ `shaped-kit-env-badge ${
									appPasswordsAvailable
										? 'shaped-kit-env-badge-good'
										: 'shaped-kit-env-badge-bad'
								}` }
							>
								{ appPasswordsAvailable
									? __( 'Available', 'shaped-kit' )
									: __( 'Disabled', 'shaped-kit' ) }
							</span>
							<span className="shaped-kit-env-desc">
								{ appPasswordsAvailable
									? __(
											'Core application passwords are ready.',
											'shaped-kit'
									  )
									: __(
											'Disabled by WordPress. Requires HTTPS or WP_ENVIRONMENT_TYPE=local.',
											'shaped-kit'
									  ) }
							</span>
						</div>

						<div className="shaped-kit-env-item">
							<span className="shaped-kit-env-label">
								{ __( 'Authorization Header', 'shaped-kit' ) }
							</span>
							<span
								className={ `shaped-kit-env-badge ${
									authHeaderPassed
										? 'shaped-kit-env-badge-good'
										: authHeaderBlocked
										? 'shaped-kit-env-badge-bad'
										: authHeaderIntercepted
										? 'shaped-kit-env-badge-warn'
										: 'shaped-kit-env-badge-neutral'
								}` }
							>
								{ authHeaderPassed
									? __( 'Passed to PHP', 'shaped-kit' )
									: authHeaderBlocked
									? __( 'Blocked by Host', 'shaped-kit' )
									: authHeaderIntercepted
									? __( 'Blocked Upstream', 'shaped-kit' )
									: __( 'Probe via Doctor', 'shaped-kit' ) }
							</span>
							<span className="shaped-kit-env-desc">
								{ authHeaderPassed
									? __(
											'Web server forwarded credentials to PHP successfully.',
											'shaped-kit'
									  )
									: authHeaderBlocked
									? __(
											'Host stripped the header. Fix via .htaccess or nginx config.',
											'shaped-kit'
									  )
									: authHeaderIntercepted
									? __(
											'Blocked before reaching WordPress (e.g. firewall or site-wide basic auth).',
											'shaped-kit'
									  )
									: __(
											'Click "Test connection" to probe live header transmission.',
											'shaped-kit'
									  ) }
							</span>
						</div>
					</div>
				</div>

				<div className="shaped-kit-diagnostics-banner">
					<div className="shaped-kit-diagnostics-banner-text">
						<span
							className="dashicons dashicons-sos"
							aria-hidden="true"
						/>
						<div>
							<strong>
								{ __(
									'Trouble connecting or got a 401 Unauthorized error?',
									'shaped-kit'
								) }
							</strong>
							<p>
								{ __(
									'Review web server configuration recipes, client quirks, and reverse proxy setup in the Diagnostics tab.',
									'shaped-kit'
								) }
							</p>
						</div>
					</div>
					{ onNavigateTab && (
						<button
							type="button"
							className="button"
							onClick={ () => onNavigateTab( 'diagnostics' ) }
						>
							{ __(
								'View Diagnostics & Troubleshooting',
								'shaped-kit'
							) }
						</button>
					) }
				</div>
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
