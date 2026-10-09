import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { createAppPassword, describeError } from './api';
import { buildSnippet, clients } from './snippets';

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
 * @param {Object} props          Component props.
 * @param {Object} props.overview The overview from the server.
 */
const ConnectPanel = ( { overview } ) => {
	const support = overview?.app_password || {};
	const products = useMemo(
		() =>
			( overview?.products || [] ).filter(
				( product ) => product.enabled && product.endpoint_url
			),
		[ overview ]
	);
	const list = clients();

	const [ username, setUsername ] = useState( support.user_login || '' );
	const [ password, setPassword ] = useState( '' );
	const [ showPassword, setShowPassword ] = useState( false );
	const [ client, setClient ] = useState( list[ 0 ].key );
	const [ localDev, setLocalDev ] = useState( () =>
		products.some( ( product ) => isLocalSite( product.endpoint_url ) )
	);
	const [ isCreating, setIsCreating ] = useState( false );
	const [ created, setCreated ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ copied, setCopied ] = useState( false );

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

		createAppPassword()
			.then( ( made ) => {
				setUsername( made.username );
				setPassword( made.password );
				setCreated( made );
			} )
			.catch( ( failure ) => setError( describeError( failure ) ) )
			.finally( () => setIsCreating( false ) );
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
		<section className="shaped-kit-card shaped-kit-connect">
			<h2>{ __( 'Connect a client', 'shaped-kit' ) }</h2>
			<p className="shaped-kit-card-note">
				{ __(
					'AI clients sign in with an application password. Create one here, or paste one you made on your profile page, and the text below fills itself in.',
					'shaped-kit'
				) }
			</p>

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
					<span>{ __( 'Application password', 'shaped-kit' ) }</span>
					<span className="shaped-kit-password">
						<input
							type={ showPassword ? 'text' : 'password' }
							value={ password }
							autoComplete="off"
							onChange={ ( event ) =>
								setPassword( event.target.value )
							}
						/>
						<button
							type="button"
							className="button"
							aria-pressed={ showPassword }
							onClick={ () => setShowPassword( ! showPassword ) }
						>
							{ showPassword
								? __( 'Hide', 'shaped-kit' )
								: __( 'Show', 'shaped-kit' ) }
						</button>
					</span>
				</label>
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
						: __( 'Create application password', 'shaped-kit' ) }
				</button>
				{ support.manage_url && (
					<a href={ support.manage_url } className="button">
						{ __( 'Manage passwords', 'shaped-kit' ) }
					</a>
				) }
			</div>

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

			{ error && (
				<p className="shaped-kit-connect-error" role="alert">
					{ error }
				</p>
			) }

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

			{ client === 'claude-desktop' && (
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
					<button type="button" className="button" onClick={ copy }>
						{ copied
							? __( 'Copied', 'shaped-kit' )
							: __( 'Copy snippet', 'shaped-kit' ) }
					</button>
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
	);
};

export default ConnectPanel;
