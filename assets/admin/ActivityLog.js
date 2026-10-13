import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { describeError, fetchLogs } from './api';

/**
 * Activity / Audit Log table viewer.
 *
 * Implements clean, accessible WordPress Admin table with filters for:
 * - Product (location-weather, real-testimonials, etc.)
 * - Outcome (ok, error, denied, confirmation_required, confirmed)
 * - Pagination controls (previous, next, page display)
 *
 * @param {Object} props
 * @param {Array}  props.products Available registered products for filter dropdown.
 */
const ActivityLog = ( { products = [] } ) => {
	const [ logs, setLogs ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ page, setPage ] = useState( 1 );
	const [ perPage, setPerPage ] = useState( 15 );
	const [ selectedProduct, setSelectedProduct ] = useState( '' );
	const [ selectedOutcome, setSelectedOutcome ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	const loadLogs = useCallback( () => {
		setLoading( true );
		setError( '' );

		fetchLogs( {
			page,
			per_page: perPage,
			product: selectedProduct,
			outcome: selectedOutcome,
		} )
			.then( ( data ) => {
				setLogs( data.items || [] );
				setTotal( data.total || 0 );
				setTotalPages( data.total_pages || 1 );
			} )
			.catch( ( err ) => {
				setError( describeError( err ) );
			} )
			.finally( () => {
				setLoading( false );
			} );
	}, [ page, perPage, selectedProduct, selectedOutcome ] );

	useEffect( () => {
		loadLogs();
	}, [ loadLogs ] );

	const getOutcomeBadgeClass = ( outcome ) => {
		switch ( outcome ) {
			case 'ok':
			case 'confirmed':
				return 'shaped-kit-badge shaped-kit-badge-good';
			case 'error':
				return 'shaped-kit-badge shaped-kit-badge-bad';
			case 'denied':
				return 'shaped-kit-badge shaped-kit-badge-warn';
			case 'confirmation_required':
				return 'shaped-kit-badge shaped-kit-badge-off';
			default:
				return 'shaped-kit-badge';
		}
	};

	const resetFilters = () => {
		setSelectedProduct( '' );
		setSelectedOutcome( '' );
		setPage( 1 );
	};

	return (
		<div className="shaped-kit-activity-log">
			<div className="shaped-kit-log-toolbar">
				<div className="shaped-kit-log-filters">
					<div className="shaped-kit-filter-item">
						<label htmlFor="sk-filter-product" className="screen-reader-text">
							{ __( 'Filter by product', 'shaped-kit' ) }
						</label>
						<select
							id="sk-filter-product"
							value={ selectedProduct }
							onChange={ ( e ) => {
								setSelectedProduct( e.target.value );
								setPage( 1 );
							} }
						>
							<option value="">{ __( 'All Products', 'shaped-kit' ) }</option>
							{ products.map( ( p ) => (
								<option key={ p.slug } value={ p.slug }>
									{ p.name }
								</option>
							) ) }
						</select>
					</div>

					<div className="shaped-kit-filter-item">
						<label htmlFor="sk-filter-outcome" className="screen-reader-text">
							{ __( 'Filter by outcome', 'shaped-kit' ) }
						</label>
						<select
							id="sk-filter-outcome"
							value={ selectedOutcome }
							onChange={ ( e ) => {
								setSelectedOutcome( e.target.value );
								setPage( 1 );
							} }
						>
							<option value="">{ __( 'All Outcomes', 'shaped-kit' ) }</option>
							<option value="ok">{ __( 'Success (ok)', 'shaped-kit' ) }</option>
							<option value="error">{ __( 'Error', 'shaped-kit' ) }</option>
							<option value="denied">{ __( 'Denied', 'shaped-kit' ) }</option>
							<option value="confirmation_required">
								{ __( 'Confirmation Required', 'shaped-kit' ) }
							</option>
							<option value="confirmed">{ __( 'Confirmed', 'shaped-kit' ) }</option>
						</select>
					</div>

					<div className="shaped-kit-filter-item">
						<select
							id="sk-filter-per-page"
							value={ perPage }
							onChange={ ( e ) => {
								setPerPage( Number( e.target.value ) );
								setPage( 1 );
							} }
						>
							<option value="15">{ __( '15 per page', 'shaped-kit' ) }</option>
							<option value="25">{ __( '25 per page', 'shaped-kit' ) }</option>
							<option value="50">{ __( '50 per page', 'shaped-kit' ) }</option>
						</select>
					</div>

					{ ( selectedProduct || selectedOutcome ) && (
						<button
							type="button"
							className="button button-link"
							onClick={ resetFilters }
							style={ { fontSize: '12px', padding: '0 4px', textDecoration: 'underline' } }
						>
							{ __( 'Clear Filters', 'shaped-kit' ) }
						</button>
					) }
				</div>

				<div className="shaped-kit-log-stats">
					<span>
						{ sprintf(
							/* translators: %d: Total number of logged entries */
							__( '%d events logged', 'shaped-kit' ),
							total
						) }
					</span>
				</div>
			</div>

			{ error && (
				<div className="shaped-kit-error" role="alert">
					<span>{ error }</span>
					<button type="button" className="button" onClick={ loadLogs }>
						{ __( 'Try again', 'shaped-kit' ) }
					</button>
				</div>
			) }

			<div className="shaped-kit-table-wrapper" style={ { background: '#fff', border: '1px solid #dcdcde', borderRadius: '8px', overflow: 'hidden' } }>
				<table className="widefat striped shaped-kit-log-table" style={ { border: 0, boxShadow: 'none' } }>
					<thead>
						<tr>
							<th style={ { padding: '12px 18px', fontWeight: 600, width: '170px' } }>{ __( 'Timestamp (UTC)', 'shaped-kit' ) }</th>
							<th style={ { padding: '12px 16px', fontWeight: 600 } }>{ __( 'Product & Ability', 'shaped-kit' ) }</th>
							<th style={ { padding: '12px 16px', fontWeight: 600, width: '160px' } }>{ __( 'Client / Key', 'shaped-kit' ) }</th>
							<th style={ { padding: '12px 16px', fontWeight: 600, width: '140px' } }>{ __( 'Parameters', 'shaped-kit' ) }</th>
							<th style={ { padding: '12px 16px', fontWeight: 600, width: '90px' } }>{ __( 'Duration', 'shaped-kit' ) }</th>
							<th style={ { padding: '12px 18px', fontWeight: 600, width: '110px', textAlign: 'center' } }>{ __( 'Outcome', 'shaped-kit' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ loading && (
							<tr>
								<td colSpan="6" className="shaped-kit-log-empty">
									{ __( 'Loading activity logs…', 'shaped-kit' ) }
								</td>
							</tr>
						) }

						{ ! loading && logs.length === 0 && (
							<tr>
								<td colSpan="6" className="shaped-kit-log-empty">
									{ __( 'No agent actions recorded yet.', 'shaped-kit' ) }
								</td>
							</tr>
						) }

						{ ! loading &&
							logs.map( ( log ) => (
								<tr key={ log.id } style={ { verticalAlign: 'middle' } }>
									<td className="shaped-kit-log-time" style={ { padding: '14px 18px', fontSize: '13px', color: '#50575e' } }>
										{ log.created_at }
									</td>
									<td style={ { padding: '14px 16px' } }>
										<div className="shaped-kit-log-ability">
											<code style={ { fontSize: '12.5px', fontWeight: 600, color: '#1d2327', background: '#f0f0f1', padding: '2px 6px', borderRadius: '4px' } }>
												{ log.ability }
											</code>
											<span className="shaped-kit-log-product" style={ { display: 'block', marginTop: '4px', fontSize: '11px', color: '#646970', textTransform: 'uppercase', fontWeight: 600 } }>
												{ log.product }
											</span>
										</div>
									</td>
									<td style={ { padding: '14px 16px' } }>
										<span className="shaped-kit-log-client" style={ { fontSize: '13px', color: '#1d2327' } }>
											{ log.app_password_name || __( 'Default Auth', 'shaped-kit' ) }
										</span>
									</td>
									<td style={ { padding: '14px 16px' } }>
										{ log.argument_keys && log.argument_keys.length > 0 ? (
											<code className="shaped-kit-log-keys" style={ { fontSize: '11.5px', background: '#f6f7f7', padding: '2px 5px', borderRadius: '3px' } }>
												{ log.argument_keys.join( ', ' ) }
											</code>
										) : (
											<span className="shaped-kit-log-none" style={ { color: '#8c8f94', fontStyle: 'italic', fontSize: '12px' } }>
												{ __( 'none', 'shaped-kit' ) }
											</span>
										) }
									</td>
									<td className="shaped-kit-log-duration" style={ { padding: '14px 16px', fontSize: '12px', color: '#646970' } }>
										{ log.duration_ms }ms
									</td>
									<td style={ { padding: '14px 18px', textAlign: 'center' } }>
										<span className={ getOutcomeBadgeClass( log.outcome ) }>
											{ log.outcome }
										</span>
										{ log.error_id && (
											<small className="shaped-kit-log-error-id">
												{ log.error_id }
											</small>
										) }
									</td>
								</tr>
							) ) }
					</tbody>
				</table>
			</div>

			<div className="shaped-kit-pagination" style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: '16px' } }>
				<span className="shaped-kit-pagination-info" style={ { fontSize: '13px', color: '#646970' } }>
					{ sprintf(
						/* translators: 1: Current page number, 2: Total page count, 3: Total entries */
						__( 'Page %1$d of %2$d (%3$d total events)', 'shaped-kit' ),
						page,
						totalPages,
						total
					) }
				</span>
				<div style={ { display: 'flex', gap: '8px', alignItems: 'center' } }>
					<button
						type="button"
						className="button"
						disabled={ page <= 1 || loading }
						onClick={ () => setPage( ( prev ) => Math.max( 1, prev - 1 ) ) }
					>
						{ __( 'Previous', 'shaped-kit' ) }
					</button>
					<button
						type="button"
						className="button"
						disabled={ page >= totalPages || loading }
						onClick={ () => setPage( ( prev ) => Math.min( totalPages, prev + 1 ) ) }
					>
						{ __( 'Next', 'shaped-kit' ) }
					</button>
				</div>
			</div>

			<p className="shaped-kit-log-disclaimer" style={ { marginTop: '16px', fontSize: '12px', color: '#646970', lineHeight: 1.5 } }>
				{ __( 'Audit records reflect tool actions reported by connected ShapedPlugin products. To ensure privacy, parameter keys are recorded while parameter values and sensitive payloads are never stored. Records are retained for 90 days.', 'shaped-kit' ) }
			</p>
		</div>
	);
};

export default ActivityLog;
