import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
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
 * Get two-letter initials from a product name.
 *
 * @param {string} name Product name.
 * @return {string} 2-letter uppercase initials.
 */
const getInitials = ( name ) => {
	if ( ! name || typeof name !== 'string' ) {
		return 'SP';
	}
	const words = name.replace( /[^a-zA-Z0-9\s]/g, '' ).split( /\s+/ ).filter( Boolean );

	// One CamelCase word gives its first two capitals: ShapedCart → SC. A one-word name with one capital (Styble) gives S.
	if ( words.length === 1 ) {
		const capitals = words[ 0 ].match( /[A-Z]/g );
		if ( capitals && capitals.length > 1 ) {
			return capitals.slice( 0, 2 ).join( '' );
		}
	}

	return (
		words
			.map( ( word ) => word[ 0 ] )
			.slice( 0, 2 )
			.join( '' )
			.toUpperCase() || 'SP'
	);
};

/**
 * What the facts line says about an installed Pro's license. It states the status and never the key:
 * the server does not send the key, not even masked (PRD §4.1, E55).
 *
 * @param {Object} pro The Pro catalog entry, with `has_license` and `license_status` from the server.
 * @return {string} "License active", "License expired" or "License not active".
 */
const licenseIsActive = ( pro ) =>
	Boolean( pro?.has_license && pro.license_status === 'valid' );

const licenseLabel = ( pro ) => {
	if ( licenseIsActive( pro ) ) {
		return __( 'License active', 'shaped-kit' );
	}
	if ( pro?.has_license && pro.license_status === 'expired' ) {
		return __( 'License expired', 'shaped-kit' );
	}
	return __( 'License not active', 'shaped-kit' );
};

/**
 * The one version shown on a row. The Pro's version when the Pro is installed (the Free is inert then),
 * otherwise the installed version, otherwise the version a click would install. A pending update adds the
 * version it would move to.
 *
 * @param {Object} item A product family as built in StorePanel.
 * @return {string} For example "Pro v3.5.0", "v3.0.7" or "v3.0.0 → v3.1.0".
 */
const versionLabel = ( item ) => {
	const rep = item.representative;

	if ( ! item.isInstalled ) {
		return sprintf( __( 'v%s', 'shaped-kit' ), rep.stable_version );
	}

	const installed = item.isProInstalled
		? item.pro.active_version
		: item.free?.active_version || rep.active_version;
	const current = installed || rep.stable_version;
	const base = item.isProInstalled
		? sprintf( __( 'Pro v%s', 'shaped-kit' ), current )
		: sprintf( __( 'v%s', 'shaped-kit' ), current );

	if ( item.hasUpdate && item.updateVersion && item.updateVersion !== current ) {
		return sprintf( __( '%1$s → v%2$s', 'shaped-kit' ), base, item.updateVersion );
	}

	return base;
};

/**
 * The text of the single "AI tools" link on a row: the live tool count, or "off" when the product's
 * MCP switch is off.
 *
 * @param {Object} item A product family as built in StorePanel.
 * @return {string} For example "AI tools · 10 ›" or "AI tools · off ›".
 */
const aiToolsLabel = ( item ) =>
	item.mcpEnabled
		? sprintf( __( 'AI tools · %d ›', 'shaped-kit' ), item.mcpCount )
		: __( 'AI tools · off ›', 'shaped-kit' );

/**
 * A settings page path from the catalog, accepted only if it is a plain wp-admin page: a PHP file name plus an
 * optional query string. The server checks it too (`CatalogData::clean_admin_path()`); this is the second check, so
 * a link is never built from anything else.
 *
 * @param {*} path Candidate path, for example `admin.php?page=shaped-kit`.
 * @return {string} The path, or an empty string.
 */
const safeAdminPath = ( path ) =>
	typeof path === 'string' &&
	/^[a-z0-9_-]+\.php(\?[A-Za-z0-9_=&%.#-]*)?$/.test( path ) &&
	! path.includes( '..' ) &&
	! path.includes( '//' )
		? path
		: '';

/**
 * Why a plugin cannot be installed or activated here, as the status word. The site's PHP or WordPress is too old
 * (only matters before the plugin is installed), or a plugin it depends on is not active. Empty when nothing blocks it.
 *
 * @param {Object}  rep         The catalog entry the row shows.
 * @param {boolean} isInstalled Whether the plugin is installed.
 * @return {string} For example "Needs PHP 8.1", "Needs WordPress 6.8" or "Needs" and the required plugin's name.
 */
const blockerLabel = ( rep, isInstalled ) => {
	if ( ! isInstalled && rep.needs_php ) {
		return sprintf( __( 'Needs PHP %s', 'shaped-kit' ), rep.needs_php );
	}
	if ( ! isInstalled && rep.needs_wp ) {
		return sprintf( __( 'Needs WordPress %s', 'shaped-kit' ), rep.needs_wp );
	}
	if ( Array.isArray( rep.unmet_plugins ) && rep.unmet_plugins.length ) {
		return sprintf( __( 'Needs %s', 'shaped-kit' ), rep.unmet_plugins.join( ', ' ) );
	}
	return '';
};

/**
 * The two groups of the list. A plugin is in the e-commerce group when the catalog says it needs the e-commerce plugin.
 */
const GROUPS = [
	{ key: 'wordpress', title: __( 'WordPress', 'shaped-kit' ), note: '' },
	{ key: 'woocommerce', title: __( 'WooCommerce', 'shaped-kit' ), note: __( 'needs the WooCommerce plugin', 'shaped-kit' ) },
];

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

/**
 * A dialog over the page. It moves focus in when it opens, keeps Tab inside it, closes on Escape (unless
 * `onClose` is null, for example while an install runs), and puts focus back where it was when it closes.
 *
 * @param {Object}      props
 * @param {string}      props.titleId  Id of the heading that names the dialog.
 * @param {string}      props.width    CSS width of the dialog.
 * @param {Function|null} props.onClose Called on Escape, or null to ignore Escape.
 * @param {*}           props.children The dialog's content, starting with the heading.
 */
const Modal = ( { titleId, width, onClose, children } ) => {
	const dialogRef = useRef( null );
	const onCloseRef = useRef( onClose );
	onCloseRef.current = onClose;

	useEffect( () => {
		const opener = document.activeElement;
		const dialog = dialogRef.current;

		const first = dialog ? dialog.querySelector( FOCUSABLE ) : null;
		if ( first ) {
			first.focus();
		}

		const onKeyDown = ( event ) => {
			if ( event.key === 'Escape' ) {
				if ( onCloseRef.current ) {
					event.stopPropagation();
					onCloseRef.current();
				}
				return;
			}

			if ( event.key !== 'Tab' || ! dialog ) {
				return;
			}

			const items = Array.from( dialog.querySelectorAll( FOCUSABLE ) );
			if ( ! items.length ) {
				return;
			}
			const firstItem = items[ 0 ];
			const lastItem = items[ items.length - 1 ];

			if ( event.shiftKey && document.activeElement === firstItem ) {
				event.preventDefault();
				lastItem.focus();
			} else if ( ! event.shiftKey && document.activeElement === lastItem ) {
				event.preventDefault();
				firstItem.focus();
			}
		};

		document.addEventListener( 'keydown', onKeyDown );
		return () => {
			document.removeEventListener( 'keydown', onKeyDown );
			if ( opener && document.contains( opener ) ) {
				opener.focus();
			}
		};
	}, [] );

	return (
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
				ref={ dialogRef }
				className="shaped-kit-modal"
				role="dialog"
				aria-modal="true"
				aria-labelledby={ titleId }
				style={ {
					background: '#fff',
					borderRadius: '8px',
					width,
					maxWidth: '90vw',
					padding: '24px',
					boxShadow: '0 4px 16px rgba(0,0,0,0.2)',
				} }
			>
				{ children }
			</div>
		</div>
	);
};

/**
 * Products & Store Hub panel (Phase H1).
 *
 * Features:
 * - Clean row-based layout (divided by hairline borders, no heavy cards).
 * - Unified single row per product family (Free and Pro consolidated).
 * - Filter tabs: All, Installed, Updates, AI tools.
 * - Search query filtering across name, description, and slug.
 * - Live status indicators with status dots (Active, Inactive, Not installed, Update available).
 * - Action buttons: Install, Activate, Settings, View updates.
 * - Modal workflows for Pro license install and Beta opt-in testing.
 */
const StorePanel = () => {
	const [ catalog, setCatalog ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ successMsg, setSuccessMsg ] = useState( '' );
	const [ busySlug, setBusySlug ] = useState( '' );

	// Active tab: 'all' | 'installed' | 'updates' | 'ai_tools'
	const [ activeTab, setActiveTab ] = useState( 'all' );
	const [ searchQuery, setSearchQuery ] = useState( '' );

	// Modals
	const [ proModalProduct, setProModalProduct ] = useState( null );
	const [ licenseKeyInput, setLicenseKeyInput ] = useState( '' );
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

	const closeProModal = () => {
		setProModalProduct( null );
		setLicenseKeyInput( '' );
	};

	const closeBetaModal = () => {
		setBetaModalProduct( null );
		setBetaLicenseInput( '' );
	};

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
						__( 'Successfully installed and activated %s.', 'shaped-kit' ),
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

	const allRawProducts = catalog?.products || [];

	// Group raw products by product_family
	const familyMap = {};
	allRawProducts.forEach( ( p ) => {
		const familyKey = p.product_family || p.slug;
		if ( ! familyMap[ familyKey ] ) {
			familyMap[ familyKey ] = {
				family: familyKey,
				free: null,
				pro: null,
			};
		}
		if ( p.edition === 'pro' ) {
			familyMap[ familyKey ].pro = p;
		} else {
			familyMap[ familyKey ].free = p;
		}
	} );

	const families = Object.values( familyMap ).map( ( group ) => {
		const free = group.free;
		const pro = group.pro;

		const isProInstalled = Boolean( pro && pro.installed );
		const isProActive = Boolean( pro && pro.active );
		const isFreeInstalled = Boolean( free && free.installed );
		const isFreeActive = Boolean( free && free.active );

		// The representative entry reflects Pro when Pro is installed; otherwise Free
		const representative = ( isProInstalled && pro ) ? pro : ( free || pro );
		const isInstalled = isProInstalled || isFreeInstalled;
		const isActive = isProActive || isFreeActive;

		// An update to either edition counts (a Pro needs its Free installed and current). The version shown is the one
		// of the edition the row shows: the Pro's when a Pro is installed, never the Free's.
		const hasUpdate = Boolean( ( isProInstalled && pro?.has_update ) || ( isFreeInstalled && free?.has_update ) );
		const updateVersion = ( isProInstalled ? pro?.update_version : isFreeInstalled && free?.update_version ) || representative.stable_version;

		const hasMcp = Boolean( ( free && free.mcp_active ) || ( pro && pro.mcp_active ) );
		const mcpEnabled = Boolean( ( free && free.mcp_enabled ) || ( pro && pro.mcp_enabled ) );
		const mcpCount = ( pro && pro.mcp_tools_count ) || ( free && free.mcp_tools_count ) || 0;

		// A beta is offered for the edition that is in use: the Pro when it is installed (the Free is inert then),
		// otherwise the Free. Only when the server says it is newer than what is installed (`beta_newer`).
		// A Pro the site has but the catalog does not describe: shown as installed, with no license, link or action
		// of its own (the server adds it, flagged `detected`).
		const isDetectedPro = Boolean( isProInstalled && pro.detected );
		const betaCandidate = isProInstalled ? pro : ( free || pro );
		const betaProduct = betaCandidate && betaCandidate.beta_newer ? betaCandidate : null;

		// What blocks installing or activating it, and which group it belongs to.
		const blocker = blockerLabel( representative, isInstalled );
		const requires = [ ...( free?.requires_plugins || [] ), ...( pro?.requires_plugins || [] ) ];
		const listGroup = requires.includes( 'woocommerce' ) ? 'woocommerce' : 'wordpress';
		const priority = [ free?.priority, pro?.priority ].find( ( n ) => Number.isFinite( n ) ) ?? 1000;

		// AI tools: live from the registry, promised by the catalog for a plugin not installed, or only after an update.
		let aiKind = '';
		if ( hasMcp ) {
			aiKind = 'live';
		} else if ( ( free && free.mcp_available ) || ( pro && pro.mcp_available ) ) {
			aiKind = 'available';
		} else if ( ( free && free.mcp_after_update ) || ( pro && pro.mcp_after_update ) ) {
			aiKind = 'after_update';
		}

		// The changelog the catalog names, else the wordpress.org page of the Free plugin. https only.
		const named = pro?.changelog_url || free?.changelog_url || '';
		const changelogUrl = isDetectedPro
			? ''
			: ( /^https:\/\//.test( named )
				? named
				: ( free?.slug ? `https://wordpress.org/plugins/${ free.slug }/#developers` : '' ) );

		// The settings page, if any entry of the family has a valid one.
		// A detected Pro has none of its own, and the Free's page is not its page: the Free stands down while the Pro
		// runs, and its page then answers 403. So none is offered.
		const settingsPath = isDetectedPro
			? ''
			: safeAdminPath( representative.settings_path ) || safeAdminPath( free?.settings_path ) || safeAdminPath( pro?.settings_path );

		return {
			family: group.family,
			free,
			pro,
			representative,
			isInstalled,
			isActive,
			isProInstalled,
			isDetectedPro,
			hasUpdate,
			updateVersion,
			hasMcp,
			mcpEnabled,
			mcpCount,
			betaProduct,
			settingsPath,
			blocker,
			group: listGroup,
			priority,
			aiKind,
			changelogUrl,
		};
	} );

	// Filter tabs count calculations
	const totalCount = families.length;
	const installedCount = families.filter( ( f ) => f.isInstalled ).length;
	const updatesCount = families.filter( ( f ) => f.hasUpdate ).length;
	const aiToolsCount = families.filter( ( f ) => f.aiKind ).length;

	// Filter & Search computation
	const filteredFamilies = families.filter( ( f ) => {
		const rep = f.representative;
		if ( searchQuery ) {
			const query = searchQuery.toLowerCase();
			const matchName = rep.name && rep.name.toLowerCase().includes( query );
			const matchDesc = rep.description && rep.description.toLowerCase().includes( query );
			const matchSlug = rep.slug && rep.slug.toLowerCase().includes( query );
			if ( ! matchName && ! matchDesc && ! matchSlug ) {
				return false;
			}
		}

		if ( activeTab === 'installed' ) {
			return f.isInstalled;
		}
		if ( activeTab === 'updates' ) {
			return f.hasUpdate;
		}
		if ( activeTab === 'ai_tools' ) {
			return Boolean( f.aiKind );
		}

		return true;
	} );

	// Inside a group: plugins with AI tools first, then the catalog's priority, then the name.
	const byRank = ( a, b ) =>
		( a.aiKind ? 0 : 1 ) - ( b.aiKind ? 0 : 1 ) ||
		a.priority - b.priority ||
		( a.representative.name || '' ).localeCompare( b.representative.name || '' );

	const showGroupTitles = new Set( families.map( ( f ) => f.group ) ).size > 1;
	const groupedRows = GROUPS.map( ( g ) => ( {
		...g,
		rows: filteredFamilies.filter( ( f ) => f.group === g.key ).sort( byRank ),
	} ) ).filter( ( g ) => g.rows.length > 0 );

	return (
		<div className="shaped-kit-plugins-hub">
			{ /* Header and Sync Line */ }
			<div className="shaped-kit-plugins-header">
				<div>
					<h2 className="shaped-kit-plugins-title">
						{ __( 'Products & Store', 'shaped-kit' ) }
					</h2>
					<p className="shaped-kit-plugins-subtitle">
						{ __( 'Manage, install, and update official ShapedPlugin tools with cryptographic verification.', 'shaped-kit' ) }
					</p>
				</div>
				<div className="shaped-kit-plugins-sync">
					<button
						type="button"
						className="button button-link shaped-kit-sync-btn"
						onClick={ () => loadCatalog( true ) }
						disabled={ loading }
					>
						⟳ { __( 'Refresh', 'shaped-kit' ) }
					</button>
					{ catalog?.catalog_source === 'builtin' && (
						<span className="shaped-kit-sync-time">
							{ __( '· built-in list', 'shaped-kit' ) }
						</span>
					) }
					{ catalog?.catalog_source !== 'builtin' && catalog?.synced_label && (
						<span className="shaped-kit-sync-time">
							{ sprintf( __( '· synced %s', 'shaped-kit' ), catalog.synced_label ) }
						</span>
					) }
				</div>
			</div>

			{ error && (
				<div role="alert" className="notice notice-error inline" style={ { margin: '0 0 16px', padding: '10px 14px' } }>
					<p style={ { margin: 0 } }>{ error }</p>
				</div>
			) }

			{ successMsg && (
				<div role="status" className="notice notice-success inline" style={ { margin: '0 0 16px', padding: '10px 14px' } }>
					<p style={ { margin: 0 } }>{ successMsg }</p>
				</div>
			) }

			{ /* Toolbar: Filter Tabs + Search */ }
			<div className="shaped-kit-hub-toolbar">
				<div className="shaped-kit-hub-tabs" role="tablist">
					<button
						type="button"
						role="tab"
						aria-selected={ activeTab === 'all' }
						className={ `shaped-kit-tab-btn ${ activeTab === 'all' ? 'is-active' : '' }` }
						onClick={ () => setActiveTab( 'all' ) }
					>
						{ sprintf( __( 'All %d', 'shaped-kit' ), totalCount ) }
					</button>
					<button
						type="button"
						role="tab"
						aria-selected={ activeTab === 'installed' }
						className={ `shaped-kit-tab-btn ${ activeTab === 'installed' ? 'is-active' : '' }` }
						onClick={ () => setActiveTab( 'installed' ) }
					>
						{ sprintf( __( 'Installed %d', 'shaped-kit' ), installedCount ) }
					</button>
					<button
						type="button"
						role="tab"
						aria-selected={ activeTab === 'updates' }
						className={ `shaped-kit-tab-btn ${ activeTab === 'updates' ? 'is-active' : '' }` }
						onClick={ () => setActiveTab( 'updates' ) }
					>
						{ sprintf( __( 'Updates %d', 'shaped-kit' ), updatesCount ) }
					</button>
					<button
						type="button"
						role="tab"
						aria-selected={ activeTab === 'ai_tools' }
						className={ `shaped-kit-tab-btn ${ activeTab === 'ai_tools' ? 'is-active' : '' }` }
						onClick={ () => setActiveTab( 'ai_tools' ) }
					>
						{ sprintf( __( 'AI tools %d', 'shaped-kit' ), aiToolsCount ) }
					</button>
				</div>

				<div className="shaped-kit-hub-search">
					<input
						type="search"
						value={ searchQuery }
						placeholder={ __( 'Search plugins…', 'shaped-kit' ) }
						onChange={ ( e ) => setSearchQuery( e.target.value ) }
						className="shaped-kit-search-input"
						aria-label={ __( 'Search plugins', 'shaped-kit' ) }
					/>
				</div>
			</div>

			{ /* Row-based Product Table */ }
			<div className="shaped-kit-hub-list" role="region" aria-label={ __( 'Products list', 'shaped-kit' ) }>
				{ filteredFamilies.length === 0 ? (
					<div className="shaped-kit-empty-row">
						{ __( 'No products found matching your filter.', 'shaped-kit' ) }
					</div>
				) : (
						groupedRows.map( ( group ) => (
						<section key={ group.key } className="shaped-kit-hub-group" aria-labelledby={ showGroupTitles ? `shaped-kit-group-${ group.key }` : undefined }>
							{ showGroupTitles && (
								<h3 id={ `shaped-kit-group-${ group.key }` } className="shaped-kit-group-title">
									{ group.title }
									<span className="shaped-kit-group-count">{ `(${ group.rows.length })` }</span>
									{ group.note && group.rows.some( ( row ) => row.representative.unmet_plugins?.length > 0 ) && (
										<span className="shaped-kit-group-note">{ group.note }</span>
									) }
								</h3>
							) }
							{ group.rows.map( ( item ) => {
						const rep = item.representative;
						const isBusy = busySlug === rep.slug || ( item.free && busySlug === item.free.slug ) || ( item.pro && busySlug === item.pro.slug );

						// Status configuration
						let statusDotClass = 'dot-not-installed';
						let statusText = __( 'Not installed', 'shaped-kit' );

						if ( item.hasUpdate ) {
							statusDotClass = 'dot-update';
							statusText = __( 'Update available', 'shaped-kit' );
							} else if ( item.isActive ) {
								statusDotClass = 'dot-active';
								statusText = __( 'Active', 'shaped-kit' );
							} else if ( item.isInstalled ) {
								statusDotClass = item.blocker ? 'dot-needs' : 'dot-inactive';
								statusText = item.blocker || __( 'Inactive', 'shaped-kit' );
							} else if ( item.blocker ) {
								statusDotClass = 'dot-needs';
								statusText = item.blocker;
							}

						// Facts line elements
						const versionStr = versionLabel( item );

						return (
								<div key={ item.family } className={ `shaped-kit-hub-row ${ item.blocker && ! item.isActive ? 'is-blocked' : '' }` }>
								{ /* Left: Icon + Content */ }
								<div className="shaped-kit-row-left">
									<div className="shaped-kit-row-icon" aria-hidden="true">
										{ getInitials( rep.name ) }
									</div>
									<div className="shaped-kit-row-content">
										<div className="shaped-kit-row-heading">
											<span className="shaped-kit-row-name">
												{ rep.name }
											</span>
										</div>

										<p className="shaped-kit-row-desc" title={ rep.description }>
											{ rep.description }
										</p>

										<div className="shaped-kit-row-facts">
											<span className="shaped-kit-fact-version">
												{ versionStr }
											</span>

											{ item.isProInstalled && ! item.isDetectedPro && (
												<>
													<span className="shaped-kit-fact-sep">·</span>
													<span className={ `shaped-kit-fact-license-status ${ licenseIsActive( item.pro ) ? 'is-active' : '' }` }>
														{ licenseLabel( item.pro ) }
													</span>
												</>
											) }

											{ item.changelogUrl && (
												<>
													<span className="shaped-kit-fact-sep">·</span>
													<a
														href={ item.changelogUrl }
														target="_blank"
														rel="noopener noreferrer"
														className="shaped-kit-fact-link"
													>
														{ __( 'Changelog', 'shaped-kit' ) } ↗
													</a>
												</>
											) }

											{ item.pro && ! item.isProInstalled && ! item.blocker && (
												<>
													<span className="shaped-kit-fact-sep">·</span>
													<button
														type="button"
														className="shaped-kit-fact-action-link"
														aria-label={ sprintf( __( 'Install Pro for %s', 'shaped-kit' ), rep.name ) }
														onClick={ () => setProModalProduct( item.pro ) }
													>
														{ __( 'Install Pro', 'shaped-kit' ) }
													</button>
												</>
											) }

											{ item.betaProduct && (
												<>
													<span className="shaped-kit-fact-sep">·</span>
													<button
														type="button"
														className="shaped-kit-fact-action-link"
														aria-label={ sprintf( __( 'Try the beta of %s', 'shaped-kit' ), rep.name ) }
														onClick={ () => setBetaModalProduct( item.betaProduct ) }
													>
														{ __( 'Try beta', 'shaped-kit' ) }
													</button>
												</>
											) }

											{ item.aiKind === 'live' && (
												<>
													<span className="shaped-kit-fact-sep">·</span>
													<a
														className="shaped-kit-fact-ai"
														href={ `admin.php?page=shaped-kit&connect=${ encodeURIComponent( item.family ) }` }
														aria-label={ sprintf( __( 'Set up AI tools for %s', 'shaped-kit' ), rep.name ) }
													>
														{ aiToolsLabel( item ) }
													</a>
												</>
											) }

											{ item.aiKind === 'available' && (
												<>
													<span className="shaped-kit-fact-sep">·</span>
													<span className="shaped-kit-fact-ai-note">{ __( 'AI tools included', 'shaped-kit' ) }</span>
												</>
											) }

											{ item.aiKind === 'after_update' && (
												<>
													<span className="shaped-kit-fact-sep">·</span>
													<span className="shaped-kit-fact-ai-note">{ __( 'AI tools after update', 'shaped-kit' ) }</span>
												</>
											) }
										</div>
									</div>
								</div>

								{ /* Right: Status + Action */ }
								<div className="shaped-kit-row-right">
									<div className="shaped-kit-row-status">
										<span className={ `shaped-kit-status-dot ${ statusDotClass }` } aria-hidden="true" />
										<span className="shaped-kit-status-label">{ statusText }</span>
									</div>

									<div className="shaped-kit-row-action">
										{ item.hasUpdate && (
											<a
												href="update-core.php"
												className="button button-secondary is-update"
												aria-label={ sprintf( __( 'View updates for %s', 'shaped-kit' ), rep.name ) }
											>
												{ __( 'View updates', 'shaped-kit' ) }
											</a>
										) }

											{ ! item.hasUpdate && ! item.isInstalled && ! item.blocker && (
											<button
												type="button"
												className="button button-secondary"
												aria-label={ sprintf( __( 'Install %s', 'shaped-kit' ), rep.name ) }
												onClick={ () => handleInstallFree( item.free?.slug || rep.slug ) }
												disabled={ isBusy || ! rep.can_install }
											>
												{ isBusy ? __( 'Installing…', 'shaped-kit' ) : __( 'Install', 'shaped-kit' ) }
											</button>
										) }

											{ ! item.hasUpdate && item.isInstalled && ! item.isActive && ! item.blocker && ! item.isDetectedPro && (
											<button
												type="button"
												className="button button-secondary"
												aria-label={ sprintf( __( 'Activate %s', 'shaped-kit' ), rep.name ) }
												onClick={ () => handleActivate( rep.slug ) }
												disabled={ isBusy }
											>
												{ isBusy ? __( 'Activating…', 'shaped-kit' ) : __( 'Activate', 'shaped-kit' ) }
											</button>
										) }

										{ ! item.hasUpdate && item.isDetectedPro && ! item.settingsPath && (
											<a
												href="plugins.php"
												className="button button-secondary"
												aria-label={ sprintf( __( 'Manage %s on the Plugins screen', 'shaped-kit' ), rep.name ) }
											>
												{ __( 'Plugins', 'shaped-kit' ) }
											</a>
										) }

										{ ! item.hasUpdate && item.isActive && item.settingsPath && (
											<a
												href={ item.settingsPath }
												className="button button-secondary"
												aria-label={ sprintf( __( 'Settings for %s', 'shaped-kit' ), rep.name ) }
											>
												{ __( 'Settings', 'shaped-kit' ) }
											</a>
										) }
									</div>
								</div>
							</div>
						);
							} ) }
						</section>
					) )
				) }
			</div>

			{ /* Pro License Installation Modal */ }
			{ proModalProduct && (
				<Modal
					titleId="shaped-kit-pro-modal-title"
					width="460px"
					onClose={ busySlug === proModalProduct.slug ? null : closeProModal }
				>
					<h3 id="shaped-kit-pro-modal-title" style={ { margin: '0 0 8px', fontSize: '18px' } }>
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
							onClick={ closeProModal }
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
				</Modal>
			) }

			{ /* Beta Warning & Installation Modal */ }
			{ betaModalProduct && (
				<Modal
					titleId="shaped-kit-beta-modal-title"
					width="480px"
					onClose={ busySlug === betaModalProduct.slug ? null : closeBetaModal }
				>
					<h3 id="shaped-kit-beta-modal-title" style={ { margin: '0 0 8px', fontSize: '18px', color: '#b32d2e' } }>
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
							onClick={ closeBetaModal }
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
				</Modal>
			) }
		</div>
	);
};

export default StorePanel;
