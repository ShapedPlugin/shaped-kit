import { __, sprintf } from '@wordpress/i18n';
import { adapterValue, notices, totals } from './overview';

/**
 * The top of the dashboard: any problem the owner has to know about, then three numbers (who supplies
 * the adapter, how many products are on, how many tools can be called). What each word and number
 * means is decided in `overview.js`; this only lays it out.
 *
 * @param {Object} props          Component props.
 * @param {Object} props.overview The overview from the server.
 */
const OverviewStrip = ( { overview } ) => {
	const problems = notices( overview );
	const { productsOn, productsTotal, toolsAvailable } = totals( overview );

	const tiles = [
		{
			key: 'adapter',
			label: __( 'MCP adapter', 'shaped-kit' ),
			value: adapterValue( overview?.adapter ),
		},
		{
			key: 'products',
			label: __( 'Products on', 'shaped-kit' ),
			value: sprintf(
				/* translators: 1: how many products are switched on, 2: how many there are. */
				__( '%1$d of %2$d', 'shaped-kit' ),
				productsOn,
				productsTotal
			),
		},
		{
			key: 'tools',
			label: __( 'Tools available', 'shaped-kit' ),
			value: String( toolsAvailable ),
		},
	];

	return (
		<div className="shaped-kit-overview">
			{ problems.map( ( problem ) => (
				<div
					key={ problem.text }
					className={ `shaped-kit-notice shaped-kit-notice-${ problem.tone }` }
					role="status"
				>
					{ problem.text }
				</div>
			) ) }

			<dl className="shaped-kit-tiles">
				{ tiles.map( ( tile ) => (
					<div className="shaped-kit-tile" key={ tile.key }>
						<dt>{ tile.label }</dt>
						<dd>{ tile.value }</dd>
					</div>
				) ) }
			</dl>
		</div>
	);
};

export default OverviewStrip;
