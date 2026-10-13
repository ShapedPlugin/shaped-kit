import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	activateProduct,
	describeError,
	fetchStoreCatalog,
	installBetaProduct,
	installFreeProduct,
	installProProduct,
} from './api';

/**
 * Clean, modern Products & Store Hub panel (Phase S5).
 *
 * Displays:
 * - Product cards with edition tags, version status, and active states.
 * - One-click Free installs and activation.
 * - License key activation modal for Pro editions with masked preview.
 * - Beta testing warning modal explaining manual opt-in lifecycle.
 */
const StorePanel = () => {
	const [ catalog, setCatalog ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ successMsg, setSuccessMsg ] = useState( '' );
	const [ busySlug, setBusySlug ] = useState( '' );

	// Pro License Modal State
	const [ proModalProduct, setProModalProduct ] = useState( null );
	const [ licenseKeyInput, setLicenseKeyInput ] = useState( '' );

	// Beta Warning Modal State
	const [ betaModalProduct, setBetaModalProduct ] = useState( null );
	const [ betaLicenseInput, setBetaLicenseInput ] = useState( '' );

	const loadCatalog = useCallback( ( refresh = false ) => {
		setLoading( true );
		setError( '' );
		return fetchStoreCatalog( refresh )
			.then( ( data ) => {
				setCatalog( data );
			} )
			.catch( ( err ) => {
				setError( describeError( err ) );
			} )
			.finally( () => setLoading( false ) );
	}, [] );

	useEffect( () => {
		loadCatalog();
	}, [ loadCatalog ] );

	const handleInstallFree = ( slug ) => {
		setBusySlug( slug );
		setError( '' );
		setSuccessMsg( '' );

		installFreeProduct( slug )
			.then( ( res ) => {
				setSuccessMsg(
					sprintf(
						/* translators: %s: product slug */
						__( 'Successfully installed and activated %s.', 'shaped-kit' ),
						res.slug || slug
					)
				);
				return loadCatalog( true );
			} )
			.catch( ( err ) => setError( describeError( err ) ) )
			.finally( () => setBusySlug( '' ) );
	};

	const handleActivate = ( slug ) => {
		setBusySlug( slug );
		setError( '' );
		setSuccessMsg( '' );

		activateProduct( slug )
			.then( () => {
				setSuccessMsg( __( 'Product activated successfully.', 'shaped-kit' ) );
				return loadCatalog( true );
			} )
			.catch( ( err ) => setError( describeError( err ) ) )
			.finally( () => setBusySlug( '' ) );
	};

	const handleConfirmProInstall = () => {
		if ( ! proModalProduct || ! licenseKeyInput.trim() ) {
			return;
		}

		const slug = proModalProduct.slug;
		setBusySlug( slug );
		setError( '' );
		setSuccessMsg( '' );

		installProProduct( slug, licenseKeyInput.trim() )
			.then( ( res ) => {
				setSuccessMsg(
					sprintf(
						/* translators: %s: product slug */
						__( 'Successfully installed and activated %s Pro.', 'shaped-kit' ),
						res.slug || slug
					)
				);
				setProModalProduct( null );
				setLicenseKeyInput( '' );
				return loadCatalog( true );
			} )
			.catch( ( err ) => setError( describeError( err ) ) )
			.finally( () => setBusySlug( '' ) );
	};

	const handleConfirmBetaInstall = () => {
		if ( ! betaModalProduct ) {
			return;
		}

		const slug = betaModalProduct.slug;
		setBusySlug( slug );
		setError( '' );
		setSuccessMsg( '' );

		installBetaProduct( slug, betaLicenseInput.trim() )
			.then( ( res ) => {
				setSuccessMsg(
					sprintf(
						/* translators: %s: beta version */
						__( 'Successfully installed %s beta build.', 'shaped-kit' ),
						res.beta_version || ''
					)
				);
				setBetaModalProduct( null );
				setBetaLicenseInput( '' );
				return loadCatalog( true );
			} )
			.catch( ( err ) => setError( describeError( err ) ) )
			.finally( () => setBusySlug( '' ) );
	};

	if ( loading && ! catalog ) {
		return (
			<div className="shaped-kit-loading" style={ { padding: '40px 0', textAlign: 'center' } }>
				<span className="spinner is-active" style={ { float: 'none', margin: '0 8px 0 0' } } />
				{ __( 'Loading catalog…', 'shaped-kit' ) }
			</div>
		);
	}

	const products = catalog?.products || [];

	return (
		<div className="shaped-kit-store-panel">
			<div className="shaped-kit-store-header" style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' } }>
				<div>
					<h2 style={ { margin: '0 0 4px', fontSize: '18px' } }>{ __( 'ShapedPlugin Products & Add-ons', 'shaped-kit' ) }</h2>
					<p style={ { margin: 0, color: '#646970', fontSize: '13px' } }>
						{ __( 'Install, activate, and manage official ShapedPlugin products with cryptographic SHA-256 package verification.', 'shaped-kit' ) }
					</p>
				</div>
				<button
					type="button"
					className="button button-secondary"
					onClick={ () => loadCatalog( true ) }
					disabled={ loading }
				>
					{ __( 'Refresh Catalog', 'shaped-kit' ) }
				</button>
			</div>

			{ error && (
				<div className="notice notice-error inline" style={ { margin: '0 0 20px', padding: '10px 12px' } }>
					<p style={ { margin: 0 } }>{ error }</p>
				</div>
			) }

			{ successMsg && (
				<div className="notice notice-success inline" style={ { margin: '0 0 20px', padding: '10px 12px' } }>
					<p style={ { margin: 0 } }>{ successMsg }</p>
				</div>
			) }

			<div className="shaped-kit-table-wrapper" style={ { background: '#fff', border: '1px solid #dcdcde', borderRadius: '8px', overflow: 'hidden' } }>
				<table className="widefat striped shaped-kit-store-table" style={ { border: 0, boxShadow: 'none' } }>
					<thead>
						<tr>
							<th style={ { padding: '12px 16px', fontWeight: 600 } }>{ __( 'Product', 'shaped-kit' ) }</th>
							<th style={ { padding: '12px 16px', fontWeight: 600, width: '100px' } }>{ __( 'Edition', 'shaped-kit' ) }</th>
							<th style={ { padding: '12px 16px', fontWeight: 600, width: '180px' } }>{ __( 'Version', 'shaped-kit' ) }</th>
							<th style={ { padding: '12px 16px', fontWeight: 600, width: '180px' } }>{ __( 'License', 'shaped-kit' ) }</th>
							<th style={ { padding: '12px 16px', fontWeight: 600, width: '110px' } }>{ __( 'Status', 'shaped-kit' ) }</th>
							<th style={ { padding: '12px 16px', fontWeight: 600, textAlign: 'right', minWidth: '220px' } }>{ __( 'Actions', 'shaped-kit' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ products.map( ( product ) => {
							const isBusy = busySlug === product.slug;
							const isInstalled = product.installed;
							const isActive = product.active;
							const isPro = product.edition === 'pro';
							const hasBeta = Boolean( product.beta_version );

							return (
								<tr key={ product.slug } style={ { verticalAlign: 'middle' } }>
									<td style={ { padding: '14px 16px' } }>
										<strong style={ { fontSize: '14px', color: '#1d2327', display: 'block', marginBottom: '2px' } }>
											{ product.name }
										</strong>
										<span style={ { fontSize: '12px', color: '#646970', lineHeight: 1.4, display: 'block' } }>
											{ product.description }
										</span>
									</td>
									<td style={ { padding: '14px 16px' } }>
										<span
											className="shaped-kit-badge"
											style={ {
												fontSize: '11px',
												fontWeight: 600,
												textTransform: 'uppercase',
												padding: '2px 8px',
												borderRadius: '4px',
												background: isPro ? '#f0f0ff' : '#f0f6fc',
												color: isPro ? '#4f46e5' : '#0969da',
												border: `1px solid ${ isPro ? '#c7d2fe' : '#c8e1ff' }`,
											} }
										>
											{ product.edition }
										</span>
									</td>
									<td style={ { padding: '14px 16px', fontSize: '13px' } }>
										<div>
											<span style={ { color: '#646970' } }>{ __( 'Stable:', 'shaped-kit' ) } </span>
											<strong>{ product.stable_version }</strong>
										</div>
										{ isInstalled && product.active_version && (
											<div style={ { fontSize: '11px', color: '#135e96', marginTop: '2px' } }>
												{ sprintf( __( 'Installed: %s', 'shaped-kit' ), product.active_version ) }
											</div>
										) }
									</td>
									<td style={ { padding: '14px 16px', fontSize: '12px' } }>
										{ isPro ? (
											product.has_license ? (
												<div>
													<code style={ { fontSize: '11px', padding: '1px 4px', background: '#f0f0f1' } }>{ product.masked_license }</code>
													<div style={ { fontSize: '11px', color: '#008a20', marginTop: '2px', fontWeight: 600 } }>
														({ product.license_status })
													</div>
												</div>
											) : (
												<span style={ { color: '#8c8f94', fontStyle: 'italic' } }>{ __( 'No license', 'shaped-kit' ) }</span>
											)
										) : (
											<span style={ { color: '#8c8f94' } }>—</span>
										)}
									</td>
									<td style={ { padding: '14px 16px' } }>
										{ isInstalled ? (
											isActive ? (
												<span
													style={ {
														color: '#008a20',
														fontWeight: 600,
														fontSize: '13px',
														display: 'inline-flex',
														alignItems: 'center',
														gap: '4px',
													} }
												>
													✓ { __( 'Active', 'shaped-kit' ) }
												</span>
											) : (
												<span style={ { color: '#d63638', fontSize: '13px', fontWeight: 500 } }>
													{ __( 'Inactive', 'shaped-kit' ) }
												</span>
											)
										) : (
											<span style={ { color: '#8c8f94', fontSize: '13px' } }>
												{ __( 'Not Installed', 'shaped-kit' ) }
											</span>
										)}
									</td>
									<td style={ { padding: '14px 16px', textAlign: 'right' } }>
										<div style={ { display: 'inline-flex', gap: '8px', alignItems: 'center', justifyContent: 'flex-end', flexWrap: 'wrap' } }>
											{ ! isInstalled && ! isPro && (
												<button
													type="button"
													className="button button-primary"
													onClick={ () => handleInstallFree( product.slug ) }
													disabled={ isBusy || ! product.can_install }
												>
													{ isBusy ? __( 'Installing…', 'shaped-kit' ) : __( 'Install & Activate', 'shaped-kit' ) }
												</button>
											) }

											{ ! isInstalled && isPro && (
												<button
													type="button"
													className="button button-primary"
													onClick={ () => setProModalProduct( product ) }
													disabled={ isBusy || ! product.can_install }
												>
													{ __( 'Install with License', 'shaped-kit' ) }
												</button>
											) }

											{ isInstalled && ! isActive && (
												<button
													type="button"
													className="button button-secondary"
													onClick={ () => handleActivate( product.slug ) }
													disabled={ isBusy }
												>
													{ isBusy ? __( 'Activating…', 'shaped-kit' ) : __( 'Activate', 'shaped-kit' ) }
												</button>
											) }

											{ hasBeta && (
												<button
													type="button"
													className="button button-secondary"
													style={ { fontSize: '11px', height: '30px', padding: '0 8px' } }
													onClick={ () => setBetaModalProduct( product ) }
													disabled={ isBusy || ! product.can_install }
													title={ sprintf( __( 'Preview and install beta %s', 'shaped-kit' ), product.beta_version ) }
												>
													{ sprintf( __( 'Install Beta (%s)', 'shaped-kit' ), product.beta_version ) }
												</button>
											) }
										</div>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			</div>

			{ /* Pro License Installation Modal */ }
			{ proModalProduct && (
				<div
					className="shaped-kit-modal-backdrop"
					style={ {
						position: 'fixed',
						inset: 0,
						background: 'rgba(0,0,0,0.6)',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						zIndex: 100000,
					} }
				>
					<div
						className="shaped-kit-modal"
						style={ {
							background: '#fff',
							borderRadius: '8px',
							width: '460px',
							maxWidth: '90vw',
							padding: '24px',
							boxShadow: '0 4px 16px rgba(0,0,0,0.2)',
						} }
					>
						<h3 style={ { margin: '0 0 8px', fontSize: '18px' } }>
							{ sprintf( __( 'Install %s', 'shaped-kit' ), proModalProduct.name ) }
						</h3>
						<p style={ { color: '#646970', fontSize: '13px', margin: '0 0 16px' } }>
							{ __( 'Paste your license key to verify and install your Pro plugin over secure HTTPS directly from ShapedPlugin.', 'shaped-kit' ) }
						</p>

						<div style={ { marginBottom: '20px' } }>
							<label htmlFor="shaped-kit-license-key-input" style={ { display: 'block', fontWeight: 600, marginBottom: '6px' } }>
								{ __( 'License Key', 'shaped-kit' ) }
							</label>
							<input
								id="shaped-kit-license-key-input"
								type="password"
								className="regular-text"
								style={ { width: '100%', padding: '8px', fontSize: '14px', fontFamily: 'monospace' } }
								placeholder="••••••••••••••••"
								value={ licenseKeyInput }
								onChange={ ( e ) => setLicenseKeyInput( e.target.value ) }
							/>
						</div>

						<div style={ { display: 'flex', justifyContent: 'flex-end', gap: '10px' } }>
							<button
								type="button"
								className="button"
								onClick={ () => {
									setProModalProduct( null );
									setLicenseKeyInput( '' );
								} }
							>
								{ __( 'Cancel', 'shaped-kit' ) }
							</button>
							<button
								type="button"
								className="button button-primary"
								onClick={ handleConfirmProInstall }
								disabled={ ! licenseKeyInput.trim() || busySlug === proModalProduct.slug }
							>
								{ busySlug === proModalProduct.slug ? __( 'Verifying & Installing…', 'shaped-kit' ) : __( 'Verify & Install', 'shaped-kit' ) }
							</button>
						</div>
					</div>
				</div>
			) }

			{ /* Beta Warning & Installation Modal */ }
			{ betaModalProduct && (
				<div
					className="shaped-kit-modal-backdrop"
					style={ {
						position: 'fixed',
						inset: 0,
						background: 'rgba(0,0,0,0.6)',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						zIndex: 100000,
					} }
				>
					<div
						className="shaped-kit-modal"
						style={ {
							background: '#fff',
							borderRadius: '8px',
							width: '480px',
							maxWidth: '90vw',
							padding: '24px',
							boxShadow: '0 4px 16px rgba(0,0,0,0.2)',
						} }
					>
						<h3 style={ { margin: '0 0 8px', fontSize: '18px', color: '#b32d2e' } }>
							⚠️ { sprintf( __( 'Install Beta: %s', 'shaped-kit' ), betaModalProduct.beta_version ) }
						</h3>
						<p style={ { color: '#50575e', fontSize: '13px', lineHeight: '1.4', margin: '0 0 14px' } }>
							{ __( 'Beta versions contain early preview features. They are strictly excluded from automated WordPress background updates. Note: database downgrades to older versions are not supported.', 'shaped-kit' ) }
						</p>

						{ betaModalProduct.edition === 'pro' && ! betaModalProduct.has_license && (
							<div style={ { marginBottom: '16px' } }>
								<label htmlFor="shaped-kit-beta-license-input" style={ { display: 'block', fontWeight: 600, marginBottom: '6px' } }>
									{ __( 'Pro License Key', 'shaped-kit' ) }
								</label>
								<input
									id="shaped-kit-beta-license-input"
									type="password"
									className="regular-text"
									style={ { width: '100%', padding: '8px', fontFamily: 'monospace' } }
									value={ betaLicenseInput }
									onChange={ ( e ) => setBetaLicenseInput( e.target.value ) }
								/>
							</div>
						) }

						<div style={ { display: 'flex', justifyContent: 'flex-end', gap: '10px' } }>
							<button
								type="button"
								className="button"
								onClick={ () => {
									setBetaModalProduct( null );
									setBetaLicenseInput( '' );
								} }
							>
								{ __( 'Cancel', 'shaped-kit' ) }
							</button>
							<button
								type="button"
								className="button button-primary"
								onClick={ handleConfirmBetaInstall }
								disabled={ busySlug === betaModalProduct.slug }
							>
								{ busySlug === betaModalProduct.slug ? __( 'Installing Beta…', 'shaped-kit' ) : __( 'I Understand, Install Beta', 'shaped-kit' ) }
							</button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
};

export default StorePanel;
