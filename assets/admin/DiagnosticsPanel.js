/**
 * Diagnostics & Troubleshooting panel: environment health checks and server configuration guidance.
 *
 * Provides site owners with live diagnostic probes for WordPress application passwords and HTTP
 * Authorization header transmission, paired with setup recipes for web servers, proxies, and AI clients.
 */
import { useState, useRef, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { resultTone, summariseHeader, testConnection } from './connectionTest';
import { storageKeyFor, loadPassword } from './passwordStore';

/**
 * CodeSnippet component with 1-click copy.
 *
 * @param {Object} props Component props.
 * @param {string} props.code Code string to render and copy.
 * @return {JSX.Element} Rendered snippet block.
 */
const CodeSnippet = ( { code } ) => {
	const [ copied, setCopied ] = useState( false );

	const handleCopy = async () => {
		try {
			await navigator.clipboard.writeText( code );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch ( e ) {
			// Browser clipboard permissions may prevent write; user can manually select text.
		}
	};

	return (
		<div className="shaped-kit-code-snippet">
			<pre>
				<code>{ code }</code>
			</pre>
			<button
				type="button"
				className="button button-secondary shaped-kit-snippet-copy-btn"
				onClick={ handleCopy }
				title={ __( 'Copy code to clipboard', 'shaped-kit' ) }
			>
				{ copied
					? __( 'Copied!', 'shaped-kit' )
					: __( 'Copy', 'shaped-kit' ) }
			</button>
		</div>
	);
};

/**
 * DiagnosticsPanel component.
 *
 * @param {Object} props Component props.
 * @param {Object} props.overview Dashboard overview data.
 * @param {Function} props.onNavigateTab Tab navigation callback.
 * @return {JSX.Element} The rendered diagnostics panel.
 */
const DiagnosticsPanel = ( { overview, onNavigateTab } ) => {
	const support = overview?.app_password || {};
	const storageKey = storageKeyFor( support.user_login || '' );
	const [ savedCreds ] = useState( () => loadPassword( storageKey ) );

	const [ username, setUsername ] = useState( support.user_login || '' );
	const [ password, setPassword ] = useState( savedCreds.password || '' );
	const [ showPassword, setShowPassword ] = useState( false );
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

	const allEnabledProducts = useMemo(
		() =>
			( overview?.products || [] ).filter(
				( p ) => p.enabled && p.endpoint_url
			),
		[ overview ]
	);

	const headerState = summariseHeader( results );
	const authHeaderPassed = headerState === 'passed';
	const authHeaderBlocked = headerState === 'blocked';
	const authHeaderIntercepted = headerState === 'intercepted';

	const runDiagnostics = () => {
		const run = ++latestTest.current;
		setPendingTests( ( count ) => count + 1 );
		setResults( null );

		const targets =
			allEnabledProducts.length > 0
				? allEnabledProducts
				: ( overview?.products || [] ).filter(
						( p ) => p.endpoint_url
				  );

		Promise.all(
			targets.map( ( product ) =>
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

	return (
		<div className="shaped-kit-diagnostics-tab">
			<section className="shaped-kit-card shaped-kit-diagnostics">
				<header className="shaped-kit-diagnostics-head">
					<h2>
						{ __(
							'System Diagnostics & Connection Doctor',
							'shaped-kit'
						) }
					</h2>
					<p className="shaped-kit-card-note">
						{ __(
							'Checks whether your host environment meets core MCP requirements, tests live HTTP Authorization header transmission, and provides server configuration recipes.',
							'shaped-kit'
						) }
					</p>
				</header>

				<div className="shaped-kit-environment-check">
					<div className="shaped-kit-environment-header">
						<div className="shaped-kit-environment-title-group">
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
						<div className="shaped-kit-diagnostics-actions">
							<button
								type="button"
								className="button button-primary"
								onClick={ runDiagnostics }
								disabled={ isTesting }
							>
								{ isTesting
									? __( 'Running Doctor…', 'shaped-kit' )
									: __(
											'Run Connection Doctor',
											'shaped-kit'
									  ) }
							</button>
						</div>
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
									: isSsl
									? __(
											'Non-local environment, served over HTTPS.',
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
											'Click "Run Connection Doctor" to probe live header transmission.',
											'shaped-kit'
									  ) }
							</span>
						</div>
					</div>

					<div className="shaped-kit-diagnostics-probe-config">
						<div className="shaped-kit-probe-config-header">
							<div className="shaped-kit-probe-config-title">
								<span
									className="dashicons dashicons-admin-network"
									aria-hidden="true"
								/>
								<strong>
									{ __(
										'Connection Doctor Probe Credentials',
										'shaped-kit'
									) }
								</strong>
							</div>
							<span className="shaped-kit-probe-config-desc">
								{ __(
									'Used by the diagnostic runner to test real HTTP Authorization and REST endpoint access.',
									'shaped-kit'
								) }
							</span>
						</div>

						<div className="shaped-kit-probe-inputs-grid">
							<div className="shaped-kit-probe-field">
								<label htmlFor="shaped-kit-probe-username">
									{ __( 'WordPress Username', 'shaped-kit' ) }
								</label>
								<div className="shaped-kit-input-with-icon">
									<span
										className="dashicons dashicons-admin-users"
										aria-hidden="true"
									/>
									<input
										id="shaped-kit-probe-username"
										type="text"
										className="regular-text"
										placeholder={ __(
											'e.g. admin',
											'shaped-kit'
										) }
										value={ username }
										onChange={ ( e ) =>
											setUsername( e.target.value )
										}
									/>
								</div>
							</div>

							<div className="shaped-kit-probe-field">
								<label htmlFor="shaped-kit-probe-password">
									{ __(
										'Application Password',
										'shaped-kit'
									) }
								</label>
								<div className="shaped-kit-input-with-toggle">
									<input
										id="shaped-kit-probe-password"
										type={
											showPassword ? 'text' : 'password'
										}
										className="regular-text"
										placeholder={ __(
											'xxxx xxxx xxxx xxxx',
											'shaped-kit'
										) }
										value={ password }
										onChange={ ( e ) =>
											setPassword( e.target.value )
										}
										autoComplete="new-password"
										spellCheck="false"
									/>
									<button
										type="button"
										className="shaped-kit-password-toggle-btn"
										onClick={ () =>
											setShowPassword( ! showPassword )
										}
										title={
											showPassword
												? __(
														'Hide password',
														'shaped-kit'
												  )
												: __(
														'Show password',
														'shaped-kit'
												  )
										}
										aria-label={
											showPassword
												? __(
														'Hide password',
														'shaped-kit'
												  )
												: __(
														'Show password',
														'shaped-kit'
												  )
										}
									>
										<span
											className={ `dashicons ${
												showPassword
													? 'dashicons-hidden'
													: 'dashicons-visibility'
											}` }
											aria-hidden="true"
										/>
									</button>
								</div>
							</div>
						</div>

						<div className="shaped-kit-probe-footer">
							{ savedCreds.password ? (
								<div className="shaped-kit-probe-status shaped-kit-probe-status-active">
									<span
										className="dashicons dashicons-yes-alt"
										aria-hidden="true"
									/>
									<span>
										{ sprintf(
											/* translators: %s: username */
											__(
												'Active session password detected for user "%s".',
												'shaped-kit'
											),
											support.user_login || username
										) }
									</span>
									{ ( username !== support.user_login ||
										password !== savedCreds.password ) && (
										<button
											type="button"
											className="button-link shaped-kit-probe-reset-btn"
											onClick={ () => {
												setUsername(
													support.user_login || ''
												);
												setPassword(
													savedCreds.password || ''
												);
											} }
										>
											{ __(
												'Reset to session credentials',
												'shaped-kit'
											) }
										</button>
									) }
								</div>
							) : (
								<div className="shaped-kit-probe-status shaped-kit-probe-status-empty">
									<span
										className="dashicons dashicons-info-outline"
										aria-hidden="true"
									/>
									<span>
										{ __(
											'Enter your credentials above or generate a new application password in the Connect tab.',
											'shaped-kit'
										) }
									</span>
								</div>
							) }
						</div>
					</div>
				</div>

				{ results && (
					<div className="shaped-kit-diagnostics-results-wrapper">
						<div className="shaped-kit-results-header">
							<h4>
								<span
									className="dashicons dashicons-clipboard"
									aria-hidden="true"
								/>
								<span>
									{ __(
										'Connection Doctor Probe Results',
										'shaped-kit'
									) }
								</span>
							</h4>
							<span className="shaped-kit-results-count">
								{ sprintf(
									/* translators: %d: count of tested products */
									__( '%d endpoints tested', 'shaped-kit' ),
									results.length
								) }
							</span>
						</div>
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
												{ __( 'Fix:', 'shaped-kit' ) }
											</strong>{ ' ' }
											<span>{ result.actionHint }</span>
										</div>
									) }
								</li>
							) ) }
						</ul>
					</div>
				) }

				<div className="shaped-kit-troubleshooting-section">
					<div className="shaped-kit-troubleshooting-header">
						<div className="shaped-kit-troubleshooting-header-title">
							<span
								className="dashicons dashicons-sos"
								aria-hidden="true"
							/>
							<div>
								<h3>
									{ __(
										'Troubleshooting & Reconnection Guide',
										'shaped-kit'
									) }
								</h3>
								<span className="shaped-kit-troubleshooting-subtitle">
									{ __(
										'Step-by-step resolution for authentication, server configurations, and AI client setups',
										'shaped-kit'
									) }
								</span>
							</div>
						</div>
						<span className="shaped-kit-guide-badge">
							{ __( '4 Guided Steps', 'shaped-kit' ) }
						</span>
					</div>

					<div className="shaped-kit-troubleshooting-content">
						{ /* Step 1 */ }
						<div className="shaped-kit-troubleshooting-step">
							<div className="shaped-kit-step-header">
								<span className="shaped-kit-step-badge">1</span>
								<div className="shaped-kit-step-titles">
									<h4>
										{ __(
											'Check WordPress & Application Passwords',
											'shaped-kit'
										) }
									</h4>
									<p className="shaped-kit-step-lead">
										{ __(
											'Verify that WordPress core and user account permissions allow authenticated MCP requests:',
											'shaped-kit'
										) }
									</p>
								</div>
							</div>
							<div className="shaped-kit-step-cards-grid">
								<div className="shaped-kit-step-mini-card">
									<div className="shaped-kit-mini-card-head">
										<span
											className="dashicons dashicons-controls-play"
											aria-hidden="true"
										/>
										<strong>
											{ __(
												'Product Server Enabled',
												'shaped-kit'
											) }
										</strong>
									</div>
									<p>
										{ __(
											'Ensure the product toggle switch is turned ON in the AI & MCP tab. Inactive products refuse client requests.',
											'shaped-kit'
										) }
									</p>
								</div>
								<div className="shaped-kit-step-mini-card">
									<div className="shaped-kit-mini-card-head">
										<span
											className="dashicons dashicons-admin-users"
											aria-hidden="true"
										/>
										<strong>
											{ __(
												'Administrator Permissions',
												'shaped-kit'
											) }
										</strong>
									</div>
									<p>
										{ __(
											'If you get 401 Unauthorized or 403 Forbidden, verify you are logged in as an Administrator with manage_options permissions.',
											'shaped-kit'
										) }
									</p>
								</div>
								<div className="shaped-kit-step-mini-card">
									<div className="shaped-kit-mini-card-head">
										<span
											className="dashicons dashicons-lock"
											aria-hidden="true"
										/>
										<strong>
											{ __(
												'Password Lifecycle',
												'shaped-kit'
											) }
										</strong>
									</div>
									<p>
										{ __(
											'Creating a new password does not revoke existing ones. To revoke, click "Revoke all Shaped Kit passwords" or manage them under Users → Profile.',
											'shaped-kit'
										) }
									</p>
								</div>
							</div>
						</div>

						{ /* Step 2 */ }
						<div className="shaped-kit-troubleshooting-step">
							<div className="shaped-kit-step-header">
								<span className="shaped-kit-step-badge">2</span>
								<div className="shaped-kit-step-titles">
									<h4>
										{ __(
											'Check AI Client Configuration & CLI Quirks',
											'shaped-kit'
										) }
									</h4>
									<p className="shaped-kit-step-lead">
										{ __(
											'Follow client-specific configuration recipes to prevent connection drops or stale configurations:',
											'shaped-kit'
										) }
									</p>
								</div>
							</div>
							<div className="shaped-kit-client-grid">
								<div className="shaped-kit-client-card">
									<div className="shaped-kit-client-card-head">
										<span
											className="dashicons dashicons-desktop"
											aria-hidden="true"
										/>
										<strong>
											{ __(
												'Claude Desktop',
												'shaped-kit'
											) }
										</strong>
									</div>
									<p>
										{ __(
											'Add the snippet into your claude_desktop_config.json under "mcpServers". Ensure no trailing comma on the final entry. If connecting to a local HTTPS dev site with a self-signed certificate, add "NODE_TLS_REJECT_UNAUTHORIZED": "0" (note: this disables certificate checks). Quit Claude Desktop completely (⌘Q / Alt+F4) and reopen it.',
											'shaped-kit'
										) }
									</p>
								</div>

								<div className="shaped-kit-client-card">
									<div className="shaped-kit-client-card-head">
										<span
											className="dashicons dashicons-media-code"
											aria-hidden="true"
										/>
										<strong>
											{ __(
												'Claude Code CLI',
												'shaped-kit'
											) }
										</strong>
									</div>
									<p>
										{ __(
											'If you previously added the server, Claude Code will report that it already exists. Remove the old entry first before adding it again with new credentials:',
											'shaped-kit'
										) }
									</p>
									<CodeSnippet code="claude mcp remove <slug>" />
								</div>

								<div className="shaped-kit-client-card">
									<div className="shaped-kit-client-card-head">
										<span
											className="dashicons dashicons-editor-code"
											aria-hidden="true"
										/>
										<strong>
											{ __(
												'Cursor & Codex',
												'shaped-kit'
											) }
										</strong>
									</div>
									<p>
										{ __(
											'Cursor: paste the JSON into Settings → MCP, then restart Cursor. Codex: fill in the fields shown for Codex in the Connect tab.',
											'shaped-kit'
										) }
									</p>
								</div>

								<div className="shaped-kit-client-card">
									<div className="shaped-kit-client-card-head">
										<span
											className="dashicons dashicons-controls-repeat"
											aria-hidden="true"
										/>
										<strong>
											{ __(
												'GitHub Copilot',
												'shaped-kit'
											) }
										</strong>
									</div>
									<p>
										{ __(
											'In VS Code, paste into .vscode/mcp.json or User settings, then reload the window or restart the editor to apply.',
											'shaped-kit'
										) }
									</p>
								</div>
							</div>
						</div>

						{ /* Step 3 */ }
						<div className="shaped-kit-troubleshooting-step">
							<div className="shaped-kit-step-header">
								<span className="shaped-kit-step-badge">3</span>
								<div className="shaped-kit-step-titles">
									<h4>
										{ __(
											'Run the Connection Doctor',
											'shaped-kit'
										) }
									</h4>
									<p className="shaped-kit-step-lead">
										{ __(
											'The diagnostic runner verifies whether WordPress accepts your credentials and whether your web server is forwarding the HTTP Authorization header.',
											'shaped-kit'
										) }
									</p>
								</div>
							</div>
							<div className="shaped-kit-doctor-callout">
								<div className="shaped-kit-doctor-callout-text">
									<p>
										{ __(
											'Click the "Run Connection Doctor" button above. The diagnostic runner will verify whether WordPress accepts your credentials and whether your web server is forwarding the HTTP Authorization header.',
											'shaped-kit'
										) }
									</p>
									<ul className="shaped-kit-doctor-features">
										<li>
											<span
												className="dashicons dashicons-yes"
												aria-hidden="true"
											/>
											{ __(
												'Verifies HTTP Authorization header transmission to PHP',
												'shaped-kit'
											) }
										</li>
										<li>
											<span
												className="dashicons dashicons-yes"
												aria-hidden="true"
											/>
											{ __(
												'Validates WordPress Core Application Passwords authentication',
												'shaped-kit'
											) }
										</li>
										<li>
											<span
												className="dashicons dashicons-yes"
												aria-hidden="true"
											/>
											{ __(
												'Checks MCP REST endpoint readiness and JSON-RPC response',
												'shaped-kit'
											) }
										</li>
									</ul>
								</div>
								<button
									type="button"
									className="button button-primary shaped-kit-doctor-step-btn"
									onClick={ runDiagnostics }
									disabled={ isTesting }
								>
									{ isTesting
										? __( 'Running Doctor…', 'shaped-kit' )
										: __(
												'Run Connection Doctor',
												'shaped-kit'
										  ) }
								</button>
							</div>
						</div>

						{ /* Step 4 */ }
						<div className="shaped-kit-troubleshooting-step">
							<div className="shaped-kit-step-header">
								<span className="shaped-kit-step-badge">4</span>
								<div className="shaped-kit-step-titles">
									<h4>
										{ __(
											'Local, Docker & Reverse Proxy Setup',
											'shaped-kit'
										) }
									</h4>
									<p className="shaped-kit-step-lead">
										{ __(
											'Running on a local dev server (Local, Docker, XAMPP, MAMP) or behind an SSL reverse proxy? Here is how to configure WordPress:',
											'shaped-kit'
										) }
									</p>
								</div>
							</div>

							<div className="shaped-kit-server-recipes">
								{ /* Recipe 1: Plain HTTP */ }
								<div className="shaped-kit-recipe-card">
									<div className="shaped-kit-recipe-header">
										<span
											className="dashicons dashicons-laptop"
											aria-hidden="true"
										/>
										<strong>
											{ __(
												'Plain HTTP on Local / Docker',
												'shaped-kit'
											) }
										</strong>
									</div>
									<p>
										{ __(
											'WordPress disables Application Passwords on plain HTTP unless WP_ENVIRONMENT_TYPE is set to "local". In wp-config.php add: define( \'WP_ENVIRONMENT_TYPE\', \'local\' ); or set the environment variable "WP_ENVIRONMENT_TYPE=local" in your docker-compose.yml.',
											'shaped-kit'
										) }
									</p>
									<div className="shaped-kit-recipe-code-wrapper">
										<span className="shaped-kit-code-label">
											{ __(
												"In wp-config.php (before /* That's all, stop editing! */):",
												'shaped-kit'
											) }
										</span>
										<CodeSnippet code="define( 'WP_ENVIRONMENT_TYPE', 'local' );" />
									</div>
								</div>

								{ /* Recipe 2: SSL Reverse Proxy */ }
								<div className="shaped-kit-recipe-card">
									<div className="shaped-kit-recipe-header">
										<span
											className="dashicons dashicons-shield"
											aria-hidden="true"
										/>
										<strong>
											{ __(
												'SSL Terminating at a Reverse Proxy',
												'shaped-kit'
											) }
										</strong>
									</div>
									<p>
										{ __(
											"If SSL terminates at a reverse proxy (Nginx, Traefik, or Cloudflare), ensure your proxy forwards X-Forwarded-Proto. Because WordPress does not inspect this header automatically, in wp-config.php before wp-settings.php add: if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) { $_SERVER['HTTPS'] = 'on'; } (Security note: only trust this header behind a trusted reverse proxy).",
											'shaped-kit'
										) }
									</p>
									<div className="shaped-kit-recipe-code-wrapper">
										<span className="shaped-kit-code-label">
											{ __(
												'In wp-config.php (before require_once ABSPATH . "wp-settings.php"):',
												'shaped-kit'
											) }
										</span>
										<CodeSnippet
											code={
												"if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {\n    $_SERVER['HTTPS'] = 'on';\n}"
											}
										/>
										<span className="shaped-kit-security-note">
											{ __(
												'Security note: only trust HTTP_X_FORWARDED_PROTO behind a trusted reverse proxy.',
												'shaped-kit'
											) }
										</span>
									</div>
								</div>

								{ /* Recipe 3: Authorization Header Stripping */ }
								<div className="shaped-kit-recipe-card">
									<div className="shaped-kit-recipe-header">
										<span
											className="dashicons dashicons-admin-network"
											aria-hidden="true"
										/>
										<strong>
											{ __(
												'Server Stripping Authorization Header',
												'shaped-kit'
											) }
										</strong>
									</div>
									<p>
										{ __(
											'Apache: save Settings > Permalinks, or add "SetEnvIf Authorization (.*) HTTP_AUTHORIZATION=$1" to .htaccess. Nginx: ensure "fastcgi_param HTTP_AUTHORIZATION $http_authorization;" is in your PHP location block.',
											'shaped-kit'
										) }
									</p>
									<div className="shaped-kit-recipe-columns">
										<div className="shaped-kit-recipe-col">
											<span className="shaped-kit-code-label">
												{ __(
													'Apache (.htaccess):',
													'shaped-kit'
												) }
											</span>
											<CodeSnippet code='SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1' />
										</div>
										<div className="shaped-kit-recipe-col">
											<span className="shaped-kit-code-label">
												{ __(
													'Nginx (PHP location block):',
													'shaped-kit'
												) }
											</span>
											<CodeSnippet code="fastcgi_param HTTP_AUTHORIZATION $http_authorization;" />
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</section>
		</div>
	);
};

export default DiagnosticsPanel;
