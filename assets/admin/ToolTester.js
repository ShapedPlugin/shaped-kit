import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	buildArgsTemplate,
	callTool,
	openSession,
	parseArguments,
} from './mcpClient';

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
 * @param {Array}   props.products Products that are on, each with `slug`, `name` and `endpoint_url`.
 * @param {string}  props.username The typed username.
 * @param {string}  props.password The typed application password.
 */
const ToolTester = ( { products, username, password } ) => {
	const [ slug, setSlug ] = useState( products[ 0 ]?.slug || '' );
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

	// Anything loaded or answered is about the old product or credentials, so it goes, and so does any
	// answer still on its way.
	useEffect( () => {
		latest.current += 1;
		setLoaded( null );
		setOutcome( null );
		setError( '' );
	}, [ slug, username, password, products ] );

	const chooseTool = ( name, tools = loaded?.tools || [] ) => {
		const chosen = tools.find( ( item ) => item.name === name );

		setToolName( name );
		setArgsText( buildArgsTemplate( chosen?.inputSchema ) );
		setOutcome( null );
		setError( '' );
	};

	const load = () => {
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
				chooseTool( opened.tools[ 0 ]?.name || '', opened.tools );
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

		callTool( product, login, loaded.session, toolName, parsed.value )
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
									{ item.name }
								</option>
							) ) }
						</select>
					</label>

					{ tool?.description && (
						<p className="shaped-kit-card-note">
							{ tool.description }
						</p>
					) }

					<label>
						<span>{ __( 'Arguments (JSON)', 'shaped-kit' ) }</span>
						<textarea
							rows={ 5 }
							spellCheck={ false }
							value={ argsText }
							onChange={ ( event ) =>
								setArgsText( event.target.value )
							}
						/>
					</label>

					<div>
						<button
							type="button"
							className="button button-primary"
							onClick={ run }
							disabled={ busy }
						>
							{ busy
								? __( 'Working…', 'shaped-kit' )
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
