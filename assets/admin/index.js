import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';

import SharedProductSettingsTab from './SharedProductSettingsTab';

/**
 * Entry point of the "AI & MCP" dashboard. It mounts into the element `DashboardPage::render()` prints,
 * which holds a plain heading until this replaces it.
 */
const mount = document.getElementById( 'shaped-kit-dashboard' );

if ( mount ) {
	createRoot( mount ).render( <App /> );
}

/**
 * Global DRY bridge for sibling ShapedPlugin products:
 * Allows Location Weather, Real Testimonials, WP Carousel, etc. to mount
 * the shared MCP settings tab without duplicating React code.
 */
window.ShapedKit = window.ShapedKit || {};
window.ShapedKit.renderProductSettingsTab = ( targetElement, props = {} ) => {
	if ( targetElement ) {
		const root = createRoot( targetElement );
		root.render( <SharedProductSettingsTab { ...props } /> );
		return root;
	}
	return null;
};
window.ShapedKit.SharedProductSettingsTab = SharedProductSettingsTab;
