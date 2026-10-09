import { createRoot } from '@wordpress/element';
import App from './App';

/**
 * Entry point of the "AI & MCP" dashboard. It mounts into the element `DashboardPage::render()` prints,
 * which holds a plain heading until this replaces it.
 */
const mount = document.getElementById( 'shaped-kit-dashboard' );

if ( mount ) {
	createRoot( mount ).render( <App /> );
}
