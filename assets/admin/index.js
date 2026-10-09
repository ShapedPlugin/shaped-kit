import { createRoot } from '@wordpress/element';

/**
 * Entry point of the "AI & MCP" dashboard. It mounts into the element `DashboardPage::render()` prints.
 * The real screens arrive in later steps; until then this only proves the build and the mount work.
 */
const mount = document.getElementById( 'shaped-kit-dashboard' );

if ( mount ) {
	createRoot( mount ).render(
		<div className="shaped-kit-app">Shaped Kit is loading…</div>
	);
}
