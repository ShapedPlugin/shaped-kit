/**
 * What the overview screen says, decided from what the server reports.
 *
 * Kept free of React so the decisions can be tested on their own: the word and tone each status gets,
 * how the adapter's supplier reads, the totals, and which problems need a notice.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * The word and tone a status gets. The words match the ones each product's own settings card uses.
 *
 * @param {string} status A product's status from the server.
 * @return {{label: string, tone: string}} `tone` is one of good, off, warn, bad.
 */
export const statusView = ( status ) => {
	switch ( status ) {
		case 'ready':
			return { label: __( 'Ready', 'shaped-kit' ), tone: 'good' };
		case 'disabled':
			return { label: __( 'Off', 'shaped-kit' ), tone: 'off' };
		case 'adapter_required':
			return {
				label: __( 'Adapter required', 'shaped-kit' ),
				tone: 'warn',
			};
		case 'adapter_outdated':
			return {
				label: __( 'Adapter too old', 'shaped-kit' ),
				tone: 'bad',
			};
		case 'adapter_unknown':
			return {
				label: __( 'Adapter not recognised', 'shaped-kit' ),
				tone: 'bad',
			};
		case 'adapter_unusable':
			return {
				label: __( 'Adapter not usable', 'shaped-kit' ),
				tone: 'bad',
			};
		case 'wordpress_unsupported':
			return {
				label: __( 'Needs a newer WordPress', 'shaped-kit' ),
				tone: 'bad',
			};
		case 'error':
			return { label: __( 'Server error', 'shaped-kit' ), tone: 'bad' };
		default:
			// A status this version of the screen does not know, from a newer or older product.
			return { label: __( 'Unknown', 'shaped-kit' ), tone: 'warn' };
	}
};

/**
 * Who supplies the adapter, in a few words for a metric tile.
 *
 * @param {Object} adapter The adapter block of the overview.
 * @return {string} For example "Shaped Kit · 0.7.0", "Rank Math SEO · 0.4.1" or "Not loaded".
 */
export const adapterValue = ( adapter ) => {
	if ( ! adapter?.loaded ) {
		return __( 'Not loaded', 'shaped-kit' );
	}

	const source = adapter.source || {};
	let who;

	if ( source.type === 'shaped-kit' ) {
		who = __( 'Shaped Kit', 'shaped-kit' );
	} else if ( source.type === 'plugin' ) {
		who = source.display_name || source.name;
	} else {
		who = __( 'Another plugin', 'shaped-kit' );
	}

	return adapter.version ? `${ who } · ${ adapter.version }` : who;
};

/**
 * The numbers on the metric row.
 *
 * "Tools available" counts only products that are ready: a tool of a product that is off, or has no
 * adapter, cannot be called.
 *
 * @param {Object} overview The overview from the server.
 * @return {{productsOn: number, productsTotal: number, toolsAvailable: number}} The totals.
 */
export const totals = ( overview ) => {
	const products = Array.isArray( overview?.products )
		? overview.products
		: [];

	return {
		productsOn: products.filter( ( product ) => product.enabled ).length,
		productsTotal: products.length,
		toolsAvailable: products
			.filter( ( product ) => product.status === 'ready' )
			.reduce(
				( sum, product ) => sum + ( product.tools_count || 0 ),
				0
			),
	};
};

/**
 * Problems the owner has to know about, most basic first. Each has a tone and a sentence.
 *
 * @param {Object} overview The overview from the server.
 * @return {Array<{tone: string, text: string}>} Empty when nothing is wrong.
 */
export const notices = ( overview ) => {
	const found = [];
	const adapter = overview?.adapter;

	if ( overview?.wordpress_supported === false ) {
		found.push( {
			tone: 'bad',
			text: __(
				'MCP needs WordPress 6.9 or newer. Update WordPress to use it.',
				'shaped-kit'
			),
		} );
	} else if ( overview?.abilities_api === false ) {
		found.push( {
			tone: 'bad',
			text: __(
				'This WordPress has no Abilities API, which MCP needs.',
				'shaped-kit'
			),
		} );
	} else if ( ! adapter?.loaded ) {
		found.push( {
			tone: 'warn',
			text: __(
				'No MCP adapter is loaded, so AI clients cannot connect to any product.',
				'shaped-kit'
			),
		} );
	} else if ( adapter.compatible === false ) {
		found.push( {
			tone: 'bad',
			text: sprintf(
				/* translators: %s: the adapter's version, or "unknown". */
				__(
					'The loaded MCP adapter (version %s) is too old or does not report a version. Update the plugin that supplies it.',
					'shaped-kit'
				),
				adapter.version || __( 'unknown', 'shaped-kit' )
			),
		} );
	}

	if (
		Array.isArray( overview?.products ) &&
		overview.products.length === 0
	) {
		found.push( {
			tone: 'warn',
			text: __(
				'No ShapedPlugin product is exposing MCP yet. Install or update a product that supports it, and it will appear here.',
				'shaped-kit'
			),
		} );
	}

	return found;
};
