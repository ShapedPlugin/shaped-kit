import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { statusView } from './overview';
import { getProductTools } from './toolsCatalog';

/**
 * One product: its name and edition, how it stands, its endpoint, tools list with prompts, and the switch.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.product  One cleaned product from the overview.
 * @param {boolean}  props.busy     Whether a switch for this product is in flight.
 * @param {Function} props.onSwitch Called with the slug and the state asked for.
 */
const ProductCard = ( { product, busy = false, onSwitch } ) => {
	const [ copied, setCopied ] = useState( false );
	const [ showTools, setShowTools ] = useState( false );
	const [ copiedPrompt, setCopiedPrompt ] = useState( '' );
	const endpointRef = useRef( null );
	const view = statusView( product.status );
	const labelId = `shaped-kit-switch-${ product.slug }`;
	const tools = getProductTools( product.slug );

	const copyEndpoint = async () => {
		try {
			await navigator.clipboard.writeText( product.endpoint_url );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch ( failure ) {
			// Some browsers refuse the clipboard: select the address so it can be copied by hand.
			endpointRef.current?.select();
		}
	};

	const copyPrompt = async ( promptText ) => {
		try {
			await navigator.clipboard.writeText( promptText );
			setCopiedPrompt( promptText );
			setTimeout( () => setCopiedPrompt( '' ), 2000 );
		} catch ( e ) {
			// ignore
		}
	};

	return (
		<section className="shaped-kit-card">
			<div className="shaped-kit-card-head">
				<div className="shaped-kit-card-title">
					<h2>{ product.name }</h2>
					{ product.edition && (
						<span className="shaped-kit-badge">
							{ product.edition === 'pro'
								? __( 'Pro', 'shaped-kit' )
								: __( 'Free', 'shaped-kit' ) }
						</span>
					) }
					<span
						className={ `shaped-kit-pill shaped-kit-pill-${ view.tone }` }
					>
						{ view.label }
					</span>
				</div>

				<label className="shaped-kit-switch" htmlFor={ labelId }>
					<span className="screen-reader-text">
						{ sprintf(
							/* translators: %s: product name. */
							__( 'Let AI agents use %s', 'shaped-kit' ),
							product.name
						) }
					</span>
					<input
						id={ labelId }
						type="checkbox"
						role="switch"
						checked={ product.enabled }
						disabled={ busy || ! product.toggleable }
						onChange={ () =>
							onSwitch( product.slug, ! product.enabled )
						}
					/>
					<span
						className="shaped-kit-switch-track"
						aria-hidden="true"
					/>
				</label>
			</div>

			{ ! product.toggleable && (
				<p className="shaped-kit-card-note">
					{ __(
						"Turn this on in the product's own settings.",
						'shaped-kit'
					) }
				</p>
			) }

			{ product.message && (
				<p className="shaped-kit-card-note">{ product.message }</p>
			) }

			<dl className="shaped-kit-card-details">
				{ product.endpoint_url && (
					<div className="shaped-kit-detail">
						<dt>{ __( 'Endpoint', 'shaped-kit' ) }</dt>
						<dd className="shaped-kit-endpoint">
							<input
								ref={ endpointRef }
								type="text"
								readOnly
								value={ product.endpoint_url }
								aria-label={ __(
									'MCP endpoint address',
									'shaped-kit'
								) }
								onFocus={ ( event ) => event.target.select() }
							/>
							<button
								type="button"
								className="button"
								onClick={ copyEndpoint }
							>
								{ copied
									? __( 'Copied', 'shaped-kit' )
									: __( 'Copy', 'shaped-kit' ) }
							</button>
						</dd>
					</div>
				) }
				<div className="shaped-kit-detail">
					<dt>{ __( 'Tools', 'shaped-kit' ) }</dt>
					<dd className="shaped-kit-tools-count-wrapper">
						<span className="shaped-kit-tools-number">{ product.tools_count }</span>
						{ tools.length > 0 && (
							<button
								type="button"
								className="shaped-kit-tools-toggle"
								onClick={ () => setShowTools( ! showTools ) }
								aria-expanded={ showTools }
							>
								{ showTools
									? __( 'Hide tools list', 'shaped-kit' )
									: __( 'View tools & sample prompts', 'shaped-kit' ) }
							</button>
						) }
					</dd>
				</div>
			</dl>

			{ showTools && tools.length > 0 && (
				<div className="shaped-kit-tools-catalog">
					<h3 className="shaped-kit-tools-catalog-title">
						{ sprintf(
							/* translators: %s: product name */
							__( 'Available tools for %s', 'shaped-kit' ),
							product.name
						) }
					</h3>
					<div className="shaped-kit-tools-grid">
						{ tools.map( ( tool ) => (
							<div key={ tool.name } className="shaped-kit-tool-card">
								<div className="shaped-kit-tool-header">
									<strong className="shaped-kit-tool-label">{ tool.label }</strong>
									<code className="shaped-kit-tool-name">{ tool.name }</code>
								</div>
								<p className="shaped-kit-tool-desc">{ tool.summary }</p>
								<div className="shaped-kit-tool-prompts">
									<span className="shaped-kit-tool-prompts-title">
										{ __( 'Example prompts for AI:', 'shaped-kit' ) }
									</span>
									<ul className="shaped-kit-prompts-list">
										{ tool.prompts.map( ( prompt, idx ) => (
											<li key={ idx } className="shaped-kit-prompt-item">
												<span className="shaped-kit-prompt-quote">“{ prompt }”</span>
												<button
													type="button"
													className="shaped-kit-prompt-copy-btn"
													title={ __( 'Copy prompt', 'shaped-kit' ) }
													onClick={ () => copyPrompt( prompt ) }
												>
													{ copiedPrompt === prompt
														? __( 'Copied', 'shaped-kit' )
														: __( 'Copy', 'shaped-kit' ) }
												</button>
											</li>
										) ) }
									</ul>
								</div>
							</div>
						) ) }
					</div>
				</div>
			) }
		</section>
	);
};

export default ProductCard;
