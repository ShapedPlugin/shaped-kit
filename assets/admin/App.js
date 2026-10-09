import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { describeError, fetchOverview, switchProduct } from './api';
import ConnectPanel from './ConnectPanel';
import OverviewStrip from './OverviewStrip';
import ProductCard from './ProductCard';

/**
 * The "AI & MCP" dashboard: the overview strip, a card per product, and the refresh button.
 *
 * Two rules keep the screen true to the site:
 *
 * - Only the newest request may change what is shown. A slow older answer, whether from a refresh or a
 *   switch, is dropped, so the dashboard never goes back to a state the site has left.
 * - A switch changes the screen only by the overview the server sends back. A switch that fails shows
 *   the error, then reads the site again, so a card never claims a state nobody confirmed.
 *
 * A failed read keeps the last good overview on screen with the error above it.
 */
const App = () => {
	const [ overview, setOverview ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ pendingLoads, setPendingLoads ] = useState( 0 );
	const [ busy, setBusy ] = useState( {} );
	const latest = useRef( 0 );

	// Counted, not flagged: a read whose answer is dropped as old must still end its own "loading".
	const isLoading = pendingLoads > 0;

	const load = useCallback( () => {
		const request = ++latest.current;

		setPendingLoads( ( count ) => count + 1 );
		setError( '' );

		return fetchOverview()
			.then( ( data ) => {
				if ( request === latest.current ) {
					setOverview( data );
				}
			} )
			.catch( ( failure ) => {
				if ( request === latest.current ) {
					setError( describeError( failure ) );
				}
			} )
			.finally( () => setPendingLoads( ( count ) => count - 1 ) );
	}, [] );

	const handleSwitch = useCallback( ( slug, enabled ) => {
		const request = ++latest.current;

		setError( '' );
		setBusy( ( current ) => ( { ...current, [ slug ]: true } ) );

		return switchProduct( slug, enabled )
			.then( ( data ) => {
				if ( request === latest.current ) {
					setOverview( data );
				}
			} )
			.catch( ( failure ) => {
				if ( request !== latest.current ) {
					return undefined;
				}

				setError( describeError( failure ) );

				// Whatever happened, show the site as it is now, not as the card was.
				return fetchOverview()
					.then( ( data ) => {
						if ( request === latest.current ) {
							setOverview( data );
						}
					} )
					.catch( () => {} );
			} )
			.finally( () =>
				setBusy( ( current ) => {
					const { [ slug ]: finished, ...rest } = current;
					return rest;
				} )
			);
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	return (
		<div className="shaped-kit-app">
			<div className="shaped-kit-header">
				<h1>{ __( 'AI & MCP', 'shaped-kit' ) }</h1>
				<button
					type="button"
					className="shaped-kit-iconbtn"
					onClick={ load }
					disabled={ isLoading }
					aria-label={
						isLoading
							? __( 'Refreshing…', 'shaped-kit' )
							: __( 'Refresh', 'shaped-kit' )
					}
					title={ __( 'Refresh', 'shaped-kit' ) }
				>
					<svg
						className={ isLoading ? 'is-spinning' : undefined }
						width="16"
						height="16"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						strokeWidth="2"
						strokeLinecap="round"
						strokeLinejoin="round"
						aria-hidden="true"
						focusable="false"
					>
						<path d="M3 12a9 9 0 0 1 15-6.7L21 8" />
						<path d="M21 3v5h-5" />
						<path d="M21 12a9 9 0 0 1-15 6.7L3 16" />
						<path d="M3 21v-5h5" />
					</svg>
				</button>
			</div>

			{ error && (
				<div className="shaped-kit-error" role="alert">
					<span>{ error }</span>
					<button type="button" className="button" onClick={ load }>
						{ __( 'Try again', 'shaped-kit' ) }
					</button>
				</div>
			) }

			{ ! overview && ! error && (
				<p className="shaped-kit-loading">
					{ __( 'Loading…', 'shaped-kit' ) }
				</p>
			) }

			{ overview && (
				<div className="shaped-kit-body" data-loaded="true">
					<OverviewStrip overview={ overview } />

					<div className="shaped-kit-cards">
						{ ( overview.products || [] ).map( ( product ) => (
							<ProductCard
								key={ product.slug }
								product={ product }
								busy={ Boolean( busy[ product.slug ] ) }
								onSwitch={ handleSwitch }
							/>
						) ) }
					</div>

					<ConnectPanel overview={ overview } />
				</div>
			) }
		</div>
	);
};

export default App;
