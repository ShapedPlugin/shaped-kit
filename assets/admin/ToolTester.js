import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	buildArgsTemplate,
	callTool,
	openSession,
	parseArguments,
} from './mcpClient';
import { getProductTools } from './toolsCatalog';

/**
 * Normalise a tool name by replacing slashes with hyphens so that WordPress
 * ability names (e.g. real-testimonials/get-context) and MCP wire names
 * (e.g. real-testimonials-get-context) match identically.
 *
 * @param {string} name Tool or ability name.
 * @return {string} Normalised name.
 */
export const normalizeToolName = ( name ) =>
	( typeof name === 'string' ? name.replace( /\//g, '-' ) : '' );

/**
 * What to show for a tool's answer. A tool replies with `structuredContent` (data) and `content` (the
 * same thing as text); the data is shown when there is any, otherwise the text, otherwise everything.
 *
 * @param {*} result The `result` of a `tools/call` answer.
 * @return {string} Text for the output box.
 */
export const formatResult = ( result ) => {
	if ( result?.structuredContent !== undefined ) {
		return JSON.stringify( result.structuredContent, null, 2 );
	}

	const texts = Array.isArray( result?.content )
		? result.content
				.filter( ( part ) => part?.type === 'text' )
				.map( ( part ) => String( part.text ) )
		: [];

	return texts.length
		? texts.join( '\n' )
		: JSON.stringify( result, null, 2 );
};

/**
 * "Try a tool": sign in to a product the way an AI client does, pick one of its tools, give it arguments,
 * and see the raw answer. It shows what a real agent would receive, so an owner can check a product
 * without setting up a separate client.
 *
 * What was loaded or answered belongs to one product and one set of credentials. When either changes it is
 * thrown away, and an answer still on its way is dropped, so the screen never shows a result for something
 * other than what is selected.
 *
 * @param {Object}  props          Component props.
 * @param {Array}   props.products            Products that are on, each with `slug`, `name` and `endpoint_url`.
 * @param {string}  props.username            The typed username.
 * @param {string}  props.password            The typed application password.
 * @param {string}  [props.selectedProductSlug] Currently active product tab or filter slug.
 */
const ToolTester = ( { products, username, password, selectedProductSlug } ) => {
	const initialSlug =
		selectedProductSlug && products.some( ( p ) => p.slug === selectedProductSlug )
			? selectedProductSlug
			: products[ 0 ]?.slug || '';
	const [ slug, setSlug ] = useState( initialSlug );
	const [ loaded, setLoaded ] = useState( null );
	const [ toolName, setToolName ] = useState( '' );
	const [ argsText, setArgsText ] = useState( '{}' );
	const [ outcome, setOutcome ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ pending, setPending ] = useState( 0 );
	const latest = useRef( 0 );

	const product =
		products.find( ( item ) => item.slug === slug ) || products[ 0 ];
	const login = { username, password };
	const busy = pending > 0;
	const tool = loaded?.tools.find( ( item ) => item.name === toolName );
	const catalogTools = getProductTools( product?.slug || '' );
	const catalogTool = catalogTools.find(
		( item ) => normalizeToolName( item.name ) === normalizeToolName( toolName )
	);

	// Sync slug when selectedProductSlug prop changes from the top tab/selector bar
	useEffect( () => {
		if (
			selectedProductSlug &&
			selectedProductSlug !== slug &&
			products.some( ( p ) => p.slug === selectedProductSlug )
		) {
			setSlug( selectedProductSlug );
		}
	}, [ selectedProductSlug, products ] );

	// Anything loaded or answered is about the old product or credentials, so it goes, and so does any
	// answer still on its way.
	useEffect( () => {
		latest.current += 1;
		setLoaded( null );
		setOutcome( null );
		setError( '' );
	}, [ slug, username, password, products ] );

	useEffect( () => {
		const onTryTool = ( event ) => {
			const { productSlug, toolName: targetName, presetArgs } = event.detail || {};

			if ( productSlug && productSlug !== slug ) {
				setSlug( productSlug );
			}

			const testerElement = document.querySelector( '.shaped-kit-tester' );
			if ( testerElement ) {
				testerElement.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			}

			if ( loaded?.tools?.length ) {
				chooseTool( targetName, loaded.tools );
				if ( presetArgs !== undefined ) {
					setArgsText( JSON.stringify( presetArgs, null, 2 ) );
				}
			} else {
				load( targetName, presetArgs );
			}
		};

		window.addEventListener( 'shaped_kit_try_tool', onTryTool );
		return () => window.removeEventListener( 'shaped_kit_try_tool', onTryTool );
	}, [ slug, loaded, product, login ] );

	const chooseTool = ( name, tools = loaded?.tools || [] ) => {
		const chosen = tools.find( ( item ) => item.name === name );
		const cat = getProductTools( product?.slug || '' ).find(
			( item ) => normalizeToolName( item.name ) === normalizeToolName( name )
		);

		setToolName( name );
		if ( cat && cat.sampleArgs !== undefined ) {
			setArgsText( JSON.stringify( cat.sampleArgs, null, 2 ) );
		} else {
			setArgsText( buildArgsTemplate( chosen?.inputSchema ) );
		}
		setOutcome( null );
		setError( '' );
	};

	const load = ( initialToolName, initialArgs ) => {
		const run = ++latest.current;

		setPending( ( count ) => count + 1 );
		setLoaded( null );
		setOutcome( null );
		setError( '' );

		openSession( product, login )
			.then( ( opened ) => {
				if ( run !== latest.current ) {
					return;
				}

				if ( ! opened.ok ) {
					setError( opened.error.message );
					return;
				}

				setLoaded( opened );
				const target =
					typeof initialToolName === 'string' && initialToolName
						? opened.tools.find(
								( t ) =>
									normalizeToolName( t.name ) ===
									normalizeToolName( initialToolName )
						  )?.name || opened.tools[ 0 ]?.name
						: opened.tools[ 0 ]?.name;

				chooseTool( target || '', opened.tools );
				if ( initialArgs !== undefined ) {
					setArgsText( JSON.stringify( initialArgs, null, 2 ) );
				}
			} )
			.finally( () => setPending( ( count ) => count - 1 ) );
	};

	const run = () => {
		const parsed = parseArguments( argsText );

		if ( ! parsed.ok ) {
			setError( parsed.message );
			setOutcome( null );
			return;
		}

		const token = ++latest.current;

		setPending( ( count ) => count + 1 );
		setOutcome( null );
		setError( '' );

		const isPrompt = tool?.componentType === 'prompt';
		const caller = isPrompt
			? callTool(
					product,
					login,
					loaded.session,
					toolName,
					parsed.value,
					undefined,
					'prompt'
			  )
			: callTool( product, login, loaded.session, toolName, parsed.value );

		caller
			.then( ( called ) => {
				if ( token !== latest.current ) {
					return;
				}

				if ( ! called.ok ) {
					setError( called.error.message );
					return;
				}

				setOutcome( called );
			} )
			.finally( () => setPending( ( count ) => count - 1 ) );
	};

	if ( ! product ) {
		return null;
	}

	return (
		<section className="shaped-kit-card shaped-kit-tester">
			<header className="shaped-kit-tester-head">
				<h2>{ __( 'Try a tool', 'shaped-kit' ) }</h2>
				<p className="shaped-kit-card-note">
					{ __(
						'Calls a tool the way an AI client would and shows the raw answer. Use the username and password above. A tool that changes data will change it.',
						'shaped-kit'
					) }
				</p>
			</header>

			<div className="shaped-kit-tester-row">
				{ products.length > 1 ? (
					<label>
						<span>{ __( 'Product', 'shaped-kit' ) }</span>
						<select
							value={ product.slug }
							onChange={ ( event ) =>
								setSlug( event.target.value )
							}
						>
							{ products.map( ( item ) => (
								<option key={ item.slug } value={ item.slug }>
									{ item.name }
								</option>
							) ) }
						</select>
					</label>
				) : (
					<strong>{ product.name }</strong>
				) }

				<button
					type="button"
					className="button"
					onClick={ load }
					disabled={ busy }
				>
					{ loaded
						? __( 'Reload tools', 'shaped-kit' )
						: __( 'Load tools', 'shaped-kit' ) }
				</button>
			</div>
 
			{ ! loaded && ! error && (
				<p className="shaped-kit-card-note shaped-kit-tester-hint">
					{ sprintf(
						/* translators: %s: product name */
						__( 'Click "Load tools" above to connect and explore live tools & starters for %s.', 'shaped-kit' ),
						product.name
					) }
				</p>
			) }

			{ error && (
				<p className="shaped-kit-connect-error" role="alert">
					{ error }
				</p>
			) }

			{ loaded && loaded.tools.length === 0 && (
				<p className="shaped-kit-card-note">
					{ __( 'This product offers no tools.', 'shaped-kit' ) }
				</p>
			) }

			{ loaded && loaded.tools.length > 0 && (
				<div className="shaped-kit-tester-body">
					<label>
						<span>{ __( 'Tool', 'shaped-kit' ) }</span>
						<select
							value={ toolName }
							onChange={ ( event ) =>
								chooseTool( event.target.value )
							}
						>
							{ loaded.tools.map( ( item ) => (
								<option key={ item.name } value={ item.name }>
									{ item.componentType === 'prompt'
										? sprintf(
												/* translators: %s: prompt name */
												__( '%s (Prompt)', 'shaped-kit' ),
												item.name
										  )
										: item.name }
								</option>
							) ) }
						</select>
					</label>

					{ tool?.description && (
						<p className="shaped-kit-card-note">
							{ tool.description }
						</p>
					) }

					<div className="shaped-kit-tester-args-wrapper">
						<div className="shaped-kit-tester-args-header">
							<div className="shaped-kit-tester-args-title-group">
								<label htmlFor="shaped-kit-tester-args-input">
									<span>{ __( 'Arguments (JSON)', 'shaped-kit' ) }</span>
								</label>
								{ catalogTool?.sampleArgs !== undefined && (
									<span className="shaped-kit-sample-badge">
										{ __( 'Sample JSON auto-filled', 'shaped-kit' ) }
									</span>
								) }
							</div>
							{ catalogTool?.sampleArgs !== undefined && (
								<button
									type="button"
									className="button button-link shaped-kit-reset-sample-btn"
									onClick={ () =>
										setArgsText(
											JSON.stringify( catalogTool.sampleArgs, null, 2 )
										)
									}
								>
									{ __( 'Reset to sample', 'shaped-kit' ) }
								</button>
							) }
						</div>

						{ catalogTool?.samplePresets?.length > 0 && (
							<div className="shaped-kit-tester-presets">
								<span className="shaped-kit-tester-presets-label">
									{ __( 'Try a starter / preset:', 'shaped-kit' ) }
								</span>
								<div className="shaped-kit-tester-presets-list">
									{ catalogTool.samplePresets.map( ( preset, idx ) => (
										<button
											key={ idx }
											type="button"
											className="button button-secondary button-small shaped-kit-preset-btn"
											onClick={ () =>
												setArgsText(
													JSON.stringify( preset.args, null, 2 )
												)
											}
										>
											{ preset.label }
										</button>
									) ) }
								</div>
							</div>
						) }

						<textarea
							id="shaped-kit-tester-args-input"
							rows={ 6 }
							spellCheck={ false }
							value={ argsText }
							onChange={ ( event ) =>
								setArgsText( event.target.value )
							}
						/>

						{ catalogTool?.expectedOutput !== undefined && (
							<details className="shaped-kit-expected-output-details">
								<summary className="shaped-kit-expected-output-summary">
									{ __( 'Expected Success Output (HTTP 200)', 'shaped-kit' ) }
								</summary>
								<pre className="shaped-kit-expected-output-code">
									<code>
										{ JSON.stringify( catalogTool.expectedOutput, null, 2 ) }
									</code>
								</pre>
							</details>
						) }
					</div>

					<div>
						<button
							type="button"
							className="button button-primary"
							onClick={ run }
							disabled={ busy }
						>
							{ busy
								? __( 'Working…', 'shaped-kit' )
								: tool?.componentType === 'prompt'
								? __( 'Run prompt', 'shaped-kit' )
								: __( 'Run tool', 'shaped-kit' ) }
						</button>
					</div>
				</div>
			) }

			{ outcome && (
				<div className="shaped-kit-tester-result">
					<p className="shaped-kit-card-note">
						{ sprintf(
							/* translators: 1: HTTP status code, 2: milliseconds. */
							__( 'HTTP %1$d · %2$d ms', 'shaped-kit' ),
							outcome.status,
							outcome.ms
						) }
					</p>

					{ outcome.isError && (
						<p
							className="shaped-kit-notice shaped-kit-notice-warn"
							role="status"
						>
							{ __(
								'The tool reported that its call failed. Its message is below.',
								'shaped-kit'
							) }
						</p>
					) }

					<pre>
						<code>{ formatResult( outcome.result ) }</code>
					</pre>
				</div>
			) }
		</section>
	);
};

export default ToolTester;
