import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { describeError } from './api';
import { shellQuote } from './snippets';

/**
 * Reusable, DRY in-plugin MCP settings tab component for ShapedPlugin products.
 *
 * Implements the shared UI pattern:
 * - On/Off toggle.
 * - Endpoint display with 1-click copy.
 * - Quick CLI connect command for instant terminal connection (the command-line AI client).
 * - "Open Shaped Kit" button for centralized multi-server management & Application Passwords.
 *
 * @param {Object} props Component props.
 * @param {string} props.restUrl Product REST endpoint (e.g. /wp-json/shaped-kit/v1/mcp or product settings REST).
 * @param {string} [props.nonce] Optional WP REST nonce.
 * @param {string} [props.productName] Display name of the product.
 * @param {string} [props.productSlug] Canonical slug of the product.
 * @param {string} [props.hubUrl] Direct URL to Shaped Kit dashboard with product preselected.
 */
const SharedProductSettingsTab = ( {
	restUrl,
	nonce,
	productName,
	productSlug,
	hubUrl = 'admin.php?page=shaped-kit',
} ) => {
	const [ summary, setSummary ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isSavingToolsets, setIsSavingToolsets ] = useState( false );
	const [ toolsetsSavedMessage, setToolsetsSavedMessage ] = useState( false );
	const [ activeToolsets, setActiveToolsets ] = useState( [] );
	const [ copiedEndpoint, setCopiedEndpoint ] = useState( false );
	const [ copiedCommand, setCopiedCommand ] = useState( false );
	const endpointInputRef = useRef( null );

	const request = async ( method, body ) => {
		const headers = {
			...( nonce ? { 'X-WP-Nonce': nonce } : {} ),
			...( body ? { 'Content-Type': 'application/json' } : {} ),
		};

		const response = await fetch( restUrl, {
			method,
			credentials: 'same-origin',
			headers,
			body: body ? JSON.stringify( body ) : undefined,
		} );

		const data = await response.json().catch( () => ( {} ) );
		if ( ! response.ok ) {
			throw new Error( describeError( data ) );
		}
		return data;
	};

	useEffect( () => {
		if ( ! restUrl ) {
			return;
		}

		request( 'GET' )
			.then( ( data ) => {
				setSummary( data );
				if ( Array.isArray( data?.toolsets ) ) {
					setActiveToolsets(
						data.toolsets
							.filter( ( t ) => t.enabled || t.locked )
							.map( ( t ) => t.id )
					);
				}
			} )
			.catch( ( err ) => setError( err.message ) );
	}, [ restUrl ] );

	const handleToggle = () => {
		if ( ! summary ) {
			return;
		}

		setIsSaving( true );
		setError( '' );

		request( 'POST', { enabled: ! summary.enabled } )
			.then( ( updated ) => {
				setSummary( updated );
				if ( Array.isArray( updated?.toolsets ) ) {
					setActiveToolsets(
						updated.toolsets
							.filter( ( t ) => t.enabled || t.locked )
							.map( ( t ) => t.id )
					);
				}
			} )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setIsSaving( false ) );
	};

	const handleToolsetChange = ( toolsetId ) => {
		setActiveToolsets( ( prev ) => {
			if ( prev.includes( toolsetId ) ) {
				return prev.filter( ( id ) => id !== toolsetId );
			}
			return [ ...prev, toolsetId ];
		} );
	};

	const handleSaveToolsets = () => {
		if ( ! summary ) {
			return;
		}

		setIsSavingToolsets( true );
		setError( '' );
		setToolsetsSavedMessage( false );

		request( 'POST', { enabled: summary.enabled, toolsets: activeToolsets } )
			.then( ( updated ) => {
				setSummary( updated );
				setToolsetsSavedMessage( true );
				setTimeout( () => setToolsetsSavedMessage( false ), 3000 );
			} )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setIsSavingToolsets( false ) );
	};

	const copyEndpoint = async () => {
		if ( ! summary?.endpoint_url ) {
			return;
		}
		try {
			await navigator.clipboard.writeText( summary.endpoint_url );
			setCopiedEndpoint( true );
			setTimeout( () => setCopiedEndpoint( false ), 2000 );
		} catch ( err ) {
			endpointInputRef.current?.select();
		}
	};

	// The signed-in user's login fills the command in (not a secret); the password is always the owner's to paste.
	const loginName = summary?.connect?.user_login || '';
	const passwordsUnavailable = false === summary?.connect?.passwords_available;
	const connectHref = productSlug && ! hubUrl.includes( 'connect=' )
		? `${ hubUrl }${ hubUrl.includes( '?' ) ? '&' : '?' }connect=${ encodeURIComponent( productSlug ) }`
		: hubUrl;

	const cliCommand = summary?.endpoint_url
		? [
				'claude mcp add \\',
				'  --transport stdio \\',
				`  ${ productSlug || summary.slug || 'product' } \\`,
				`  --env WP_API_URL=${ summary.endpoint_url } \\`,
				`  --env WP_API_USERNAME='${ shellQuote( loginName || '<your-username>' ) }' \\`,
				"  --env WP_API_PASSWORD='<your-application-password>' \\",
				'  --env OAUTH_ENABLED=false \\',
				'  -- npx -y @automattic/mcp-wordpress-remote@latest',
		  ].join( '\n' )
		: '';

	const copyCliCommand = async () => {
		if ( ! cliCommand ) {
			return;
		}
		try {
			await navigator.clipboard.writeText( cliCommand );
			setCopiedCommand( true );
			setTimeout( () => setCopiedCommand( false ), 2000 );
		} catch ( err ) {
			// fallback
		}
	};

	if ( ! summary && ! error ) {
		return (
			<div className="shaped-kit-loading" style={ { padding: '30px 0', textAlign: 'center' } }>
				<span className="spinner is-active" style={ { float: 'none', margin: '0 8px 0 0' } } />
				{ __( 'Loading MCP configuration…', 'shaped-kit' ) }
			</div>
		);
	}

	const name = productName || summary?.name || __( 'This Product', 'shaped-kit' );

	return (
		<div className="shaped-kit-shared-settings-tab" style={ { maxWidth: '840px', margin: '0' } }>
			<div style={ { marginBottom: '24px' } }>
				<h3 style={ { margin: '0 0 6px', fontSize: '18px', fontWeight: 600 } }>
					{ sprintf( __( 'MCP for AI Agents (%s)', 'shaped-kit' ), name ) }
				</h3>
				<p style={ { margin: 0, color: '#646970', fontSize: '13px', lineHeight: 1.5 } }>
					{ sprintf(
						/* translators: %s: product name */
						__( 'Let AI assistants such as Claude, Cursor, or Copilot read and manage %s through the official WordPress MCP Adapter.', 'shaped-kit' ),
						name
					) }
				</p>
			</div>

			{ error && (
				<div className="notice notice-error inline" style={ { margin: '0 0 20px', padding: '10px 14px' } }>
					<p style={ { margin: 0 } }>{ error }</p>
				</div>
			) }

			{ /* Toggle Section */ }
			<div
				style={ {
					background: '#fff',
					border: '1px solid #dcdcde',
					borderRadius: '8px',
					padding: '20px 24px',
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
					marginBottom: '20px',
				} }
			>
				<div>
					<strong style={ { fontSize: '15px', color: '#1d2327', display: 'block', marginBottom: '4px' } }>
						{ __( 'Enable MCP for AI Agents', 'shaped-kit' ) }
					</strong>
					<span style={ { fontSize: '13px', color: '#646970' } }>
						{ __( 'Off by default. When off, the endpoint is completely unreachable to external clients.', 'shaped-kit' ) }
					</span>
				</div>
				<label className="shaped-kit-switch" style={ { margin: 0 } }>
					<input
						type="checkbox"
						role="switch"
						checked={ Boolean( summary?.enabled ) }
						disabled={ isSaving }
						onChange={ handleToggle }
					/>
					<span className="shaped-kit-switch-track" aria-hidden="true" />
				</label>
			</div>

			{ /* Status & Endpoint Box */ }
			{ summary?.enabled && (
				<div
					style={ {
						background: '#fff',
						border: '1px solid #dcdcde',
						borderRadius: '8px',
						padding: '24px',
						marginBottom: '20px',
					} }
				>
					<div style={ { display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '16px' } }>
						<span
							style={ {
								fontSize: '12px',
								fontWeight: 600,
								padding: '2px 10px',
								borderRadius: '999px',
								background: '#e6f4ea',
								color: '#14532d',
								border: '1px solid #14532d',
							} }
						>
							✓ { __( 'Ready', 'shaped-kit' ) }
						</span>
						<span style={ { fontSize: '13px', color: '#646970' } }>
							{ __( 'AI agents can connect to this endpoint.', 'shaped-kit' ) }
						</span>
					</div>

					<div style={ { marginBottom: '20px' } }>
						<label htmlFor="sk-shared-endpoint-input" style={ { display: 'block', fontWeight: 600, fontSize: '13px', marginBottom: '6px' } }>
							{ __( 'Endpoint URL', 'shaped-kit' ) }
						</label>
						<div style={ { display: 'flex', gap: '8px' } }>
							<input
								id="sk-shared-endpoint-input"
								ref={ endpointInputRef }
								type="text"
								readOnly
								value={ summary.endpoint_url || '' }
								style={ { flex: 1, height: '36px', padding: '0 12px', fontFamily: 'monospace', fontSize: '13px' } }
								onFocus={ ( e ) => e.target.select() }
							/>
							<button
								type="button"
								className="button"
								style={ { height: '36px', padding: '0 16px' } }
								onClick={ copyEndpoint }
							>
								{ copiedEndpoint ? __( 'Copied', 'shaped-kit' ) : __( 'Copy Endpoint', 'shaped-kit' ) }
							</button>
						</div>
					</div>

					<div style={ { display: 'flex', gap: '24px', fontSize: '13px', color: '#646970', borderTop: '1px solid #f0f0f1', paddingTop: '16px' } }>
						<div>
							<strong>{ __( 'Available Tools:', 'shaped-kit' ) }</strong>{' '}
							<span>{ summary.tools_count || 0 } { __( 'abilities', 'shaped-kit' ) }</span>
						</div>
						<div>
							<strong>{ __( 'MCP Adapter:', 'shaped-kit' ) }</strong>{' '}
							<span>{ summary.adapter?.version ? `v${ summary.adapter.version }` : __( 'Active', 'shaped-kit' ) }</span>
						</div>
					</div>
				</div>
			) }

			{ /* Opt-In Toolsets & Context Budgeting Section (FR-K9 §6, D14) */ }
			{ summary?.enabled && Array.isArray( summary?.toolsets ) && summary.toolsets.length > 0 && (
				<div
					style={ {
						background: '#fff',
						border: '1px solid #dcdcde',
						borderRadius: '8px',
						padding: '24px',
						marginBottom: '20px',
					} }
				>
					<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '14px' } }>
						<div>
							<h4 style={ { margin: '0 0 6px', fontSize: '15px', fontWeight: 600 } }>
								{ __( 'Ability Toolsets & Token Economy', 'shaped-kit' ) }
							</h4>
							<p style={ { margin: 0, color: '#646970', fontSize: '13px' } }>
								{ __( 'Control which tool groups are exposed to AI models. Disabling write or setup operations reduces prompt token consumption and prevents unauthorized modifications.', 'shaped-kit' ) }
							</p>
						</div>
					</div>

					<div style={ { display: 'flex', flexDirection: 'column', gap: '12px', marginTop: '16px', marginBottom: '20px' } }>
						{ summary.toolsets.map( ( toolset ) => {
							const isChecked = activeToolsets.includes( toolset.id ) || Boolean( toolset.locked );
							return (
								<label
									key={ toolset.id }
									style={ {
										display: 'flex',
										alignItems: 'flex-start',
										gap: '12px',
										padding: '14px 16px',
										borderRadius: '6px',
										border: '1px solid #e0e0e0',
										background: isChecked ? '#fbfcfe' : '#fafafa',
										cursor: toolset.locked ? 'default' : 'pointer',
									} }
								>
									<input
										type="checkbox"
										disabled={ Boolean( toolset.locked ) || isSavingToolsets }
										checked={ isChecked }
										onChange={ () => handleToolsetChange( toolset.id ) }
										style={ { marginTop: '2px' } }
									/>
									<div style={ { flex: 1 } }>
										<div style={ { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '4px' } }>
											<strong style={ { fontSize: '14px', color: '#1d2327' } }>
												{ toolset.label }
											</strong>
											{ toolset.locked && (
												<span
													style={ {
														fontSize: '11px',
														background: '#f0f0f1',
														color: '#50575e',
														padding: '1px 6px',
														borderRadius: '3px',
														fontWeight: 500,
													} }
												>
													{ __( 'Always On', 'shaped-kit' ) }
												</span>
											) }
											{ toolset.tools_count > 0 && (
												<span
													style={ {
														fontSize: '11px',
														background: '#e7f3ff',
														color: '#0969da',
														padding: '1px 6px',
														borderRadius: '3px',
														fontWeight: 600,
													} }
												>
													{ sprintf(
														/* translators: %d: number of tools in this toolset */
														__( '%d tools', 'shaped-kit' ),
														toolset.tools_count
													) }
												</span>
											) }
										</div>
										<p style={ { margin: 0, fontSize: '12.5px', color: '#646970', lineHeight: 1.4 } }>
											{ toolset.description }
										</p>
									</div>
								</label>
							);
						} ) }
					</div>

					<div style={ { display: 'flex', alignItems: 'center', gap: '12px' } }>
						<button
							type="button"
							className="button button-primary"
							disabled={ isSavingToolsets }
							onClick={ handleSaveToolsets }
						>
							{ isSavingToolsets ? __( 'Saving…', 'shaped-kit' ) : __( 'Save Toolsets', 'shaped-kit' ) }
						</button>
						{ toolsetsSavedMessage && (
							<span style={ { fontSize: '13px', color: '#14532d', fontWeight: 500 } }>
								✓ { __( 'Settings saved successfully.', 'shaped-kit' ) }
							</span>
						) }
					</div>
				</div>
			) }

			{ /* Quick Connect Box & Hub Link */ }
			{ summary?.enabled && (
				<div
					style={ {
						background: '#fff',
						border: '1px solid #dcdcde',
						borderRadius: '8px',
						padding: '24px',
					} }
				>
					<h4 style={ { margin: '0 0 6px', fontSize: '15px', fontWeight: 600 } }>
						{ __( 'Quick Connect (Claude Code CLI)', 'shaped-kit' ) }
					</h4>
					{ passwordsUnavailable ? (
						<p style={ { margin: '0 0 20px', color: '#646970', fontSize: '13px' } }>
							{ __( 'Application passwords are not available on this site, so an AI client cannot sign in yet. They need HTTPS (or a local site) and must not be switched off by a plugin.', 'shaped-kit' ) }
						</p>
					) : (
						<>
							<ol style={ { margin: '0 0 14px', paddingLeft: '18px', color: '#646970', fontSize: '13px', lineHeight: 1.5 } }>
								<li>
									{ __( 'Create an application password. Shaped Kit does it in one click and fills in this command for you.', 'shaped-kit' ) }{ ' ' }
									<a href={ connectHref } className="button button-small" style={ { marginLeft: '4px' } }>
										{ __( 'Create password in Shaped Kit →', 'shaped-kit' ) }
									</a>
								</li>
								<li>
									{ loginName
										? __( 'Replace <your-application-password> in the command below with it. Your username is already filled in.', 'shaped-kit' )
										: __( 'Replace <your-username> and <your-application-password> in the command below.', 'shaped-kit' ) }
								</li>
								<li>{ __( 'Run the command in the terminal where Claude Code is installed.', 'shaped-kit' ) }</li>
							</ol>

							<div style={ { display: 'flex', gap: '8px', marginBottom: '20px' } }>
								<input
									type="text"
									readOnly
									value={ cliCommand }
									style={ { flex: 1, height: '36px', padding: '0 12px', fontFamily: 'monospace', fontSize: '12.5px', background: '#f6f7f7' } }
									onFocus={ ( e ) => e.target.select() }
								/>
								<button
									type="button"
									className="button"
									style={ { height: '36px', padding: '0 16px' } }
									onClick={ copyCliCommand }
								>
									{ copiedCommand ? __( 'Copied', 'shaped-kit' ) : __( 'Copy Command', 'shaped-kit' ) }
								</button>
							</div>
						</>
					) }

					<div style={ { borderTop: '1px solid #f0f0f1', paddingTop: '16px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '12px' } }>
						<div style={ { fontSize: '13px', color: '#646970' } }>
							{ __( 'Need to configure Cursor, Desktop, Copilot, or manage Application Passwords across all products?', 'shaped-kit' ) }
						</div>
						<a
							href={ hubUrl }
							className="button button-primary"
							style={ { height: '32px', lineHeight: '30px' } }
						>
							{ __( 'Open Shaped Kit Hub →', 'shaped-kit' ) }
						</a>
					</div>
				</div>
			) }
		</div>
	);
};

export default SharedProductSettingsTab;
