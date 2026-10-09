import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { describeError, fetchOverview } from './api';

/**
 * The "AI & MCP" dashboard. This step owns getting the overview and its three states (loading, failed,
 * loaded) and the refresh button; what the loaded overview looks like is added by the steps after it.
 *
 * A refresh that fails keeps the last good overview on screen with the error above it, so one bad
 * request never blanks a dashboard that was working.
 */
const App = () => {
	const [ overview, setOverview ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );
	const latest = useRef( 0 );

	/**
	 * Read the overview. Only the newest request is allowed to change the screen, so a slow older answer
	 * can never overwrite a newer one (the dashboard would then show a state the site is no longer in).
	 */
	const load = useCallback( () => {
		const request = ++latest.current;

		setIsLoading( true );
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
			.finally( () => {
				if ( request === latest.current ) {
					setIsLoading( false );
				}
			} );
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
					className="button"
					onClick={ load }
					disabled={ isLoading }
				>
					{ isLoading
						? __( 'Refreshing…', 'shaped-kit' )
						: __( 'Refresh', 'shaped-kit' ) }
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

			{ ! overview && isLoading && (
				<p className="shaped-kit-loading">
					{ __( 'Loading…', 'shaped-kit' ) }
				</p>
			) }

			{ overview && (
				<div className="shaped-kit-body" data-loaded="true" />
			) }
		</div>
	);
};

export default App;
