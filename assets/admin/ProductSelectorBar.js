import { __ } from '@wordpress/i18n';
import { statusView } from './overview';

/**
 * Horizontal selector bar for registered products.
 *
 * Scales gracefully whether there are 2 products or 20 products.
 * Shows active dot status, product name, edition tag, and tool counts.
 *
 * @param {Object}   props                  Component props.
 * @param {Array}    props.products         List of products from overview.
 * @param {string}   props.selectedSlug     Currently active/selected product slug.
 * @param {Function} props.onSelectProduct  Callback when user clicks a product tab.
 * @param {Object}   props.busy             Map of product slugs in flight.
 */
const ProductSelectorBar = ( {
	products = [],
	selectedSlug,
	onSelectProduct,
	busy = {},
} ) => {
	if ( ! products || products.length === 0 ) {
		return null;
	}

	return (
		<div className="shaped-kit-product-selector-bar" role="tablist" aria-label={ __( 'Products selector', 'shaped-kit' ) }>
			{ products.map( ( product ) => {
				const isSelected = product.slug === selectedSlug;
				const view = statusView( product.status );
				const isBusy = Boolean( busy[ product.slug ] );

				return (
					<button
						key={ product.slug }
						type="button"
						role="tab"
						aria-selected={ isSelected }
						className={ `shaped-kit-product-tab ${ isSelected ? 'is-selected' : '' }` }
						onClick={ () => onSelectProduct( product.slug ) }
					>
						<span
							className={ `shaped-kit-status-dot shaped-kit-status-dot-${ view.tone }` }
							title={ view.label }
						/>
						<span className="shaped-kit-tab-name">{ product.name }</span>
						{ product.edition && (
							<span
								className={ `shaped-kit-tab-edition ${
									product.edition === 'pro' ? 'is-pro' : 'is-free'
								}` }
							>
								{ product.edition.toUpperCase() }
							</span>
						) }
						<span className="shaped-kit-tab-count">
							{ product.tools_count }
						</span>
						{ isBusy && (
							<span className="spinner is-active" style={ { float: 'none', margin: '0 0 0 4px', width: '14px', height: '14px' } } />
						) }
					</button>
				);
			} ) }
		</div>
	);
};

export default ProductSelectorBar;
