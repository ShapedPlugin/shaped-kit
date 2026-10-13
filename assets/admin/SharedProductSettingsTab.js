import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { describeError } from './api';

/**
 * Reusable, DRY in-plugin MCP settings tab component for ShapedPlugin products.
 *
 * Implements the shared UI pattern:
 * - On/Off toggle.
 * - Endpoint display with 1-click copy.
 * - Quick CLI connect command for instant terminal connection (Claude Code).
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
			.then( ( data ) => setSummary( data ) )
			.catch( ( err ) => setError( err.message ) );
	}, [ restUrl ] );

	const handleToggle = () => {
		if ( ! summary ) {
			return;
		}

		setIsSaving( true );
		setError( '' );

		request( 'POST', { enabled: ! summary.enabled } )
			.then( ( updated ) => setSummary( updated ) )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setIsSaving( false ) );
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

	const cliCommand = summary?.endpoint_url
		? [
				'claude mcp add \\',
				'  --transport stdio \\',
				`  ${ productSlug || summary.slug || 'product' } \\`,
				`  --env WP_API_URL=${ summary.endpoint_url } \\`,
				"  --env WP_API_USERNAME='<your-username>' \\",
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
					<p style={ { margin: '0 0 14px', color: '#646970', fontSize: '13px' } }>
						{ __( 'Run this command in your terminal where Claude Code is installed to instantly register this product:', 'shaped-kit' ) }
					</p>

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
