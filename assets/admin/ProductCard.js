import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { statusView } from './overview';

/**
 * One product: its name and edition, how it stands, its endpoint, and the switch.
 *
 * The switch always shows what the server last reported (`product.enabled`), never the click. A click
 * only asks `onSwitch`; the card changes when a new overview arrives. A product with no working
 * switch of its own is shown with the switch locked and a line saying where to turn it on.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.product  One cleaned product from the overview.
 * @param {boolean}  props.busy     Whether a switch for this product is in flight.
 * @param {Function} props.onSwitch Called with the slug and the state asked for.
 */
const ProductCard = ( { product, busy = false, onSwitch } ) => {
	const [ copied, setCopied ] = useState( false );
	const endpointRef = useRef( null );
	const view = statusView( product.status );
	const labelId = `shaped-kit-switch-${ product.slug }`;

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
					<dd>{ product.tools_count }</dd>
				</div>
			</dl>
		</section>
	);
};

export default ProductCard;
