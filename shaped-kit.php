<?php
/**
 * Plugin Name:       Shaped Kit
 * Description:       Connects AI agents to ShapedPlugin products through the Model Context Protocol (MCP).
 * Version:           0.2.0-beta.4
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            ShapedPlugin LLC
 * Author URI:        https://shapedplugin.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shaped-kit
 *
 * @package ShapedKit
 */

defined( 'ABSPATH' ) || exit;

define( 'SHAPED_KIT_VERSION', '0.2.0-beta.4' );
define( 'SHAPED_KIT_FILE', __FILE__ );
define( 'SHAPED_KIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHAPED_KIT_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'ShapedKit\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = SHAPED_KIT_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// At include time, not on plugins_loaded: the earliest point the Kit can supply the adapter before
// plugins that load after it. Plugins that loaded earlier keep theirs.
\ShapedKit\Mcp\AdapterBootstrap::maybe_load();

// Not behind is_admin(): a REST request is not an admin request, and the route must exist for it.
\ShapedKit\Mcp\TransportGuard::register();
\ShapedKit\Rest\McpController::register();
\ShapedKit\Rest\StoreController::register();
\ShapedKit\Store\SelfUpdater::register();

// Audit logging subsystem: table migration, event listening, and 90-day pruning.
register_activation_hook( SHAPED_KIT_FILE, array( \ShapedKit\Audit\LogTable::class, 'install' ) );
register_deactivation_hook( SHAPED_KIT_FILE, array( \ShapedKit\Audit\LogPruner::class, 'unregister' ) );
add_action( 'plugins_loaded', array( \ShapedKit\Audit\LogTable::class, 'maybe_upgrade' ) );
\ShapedKit\Audit\LogListener::register();
\ShapedKit\Audit\LogPruner::register();

if ( is_admin() ) {
	\ShapedKit\Admin\DashboardPage::register();
	add_action( 'admin_init', array( \ShapedKit\Audit\LogPruner::class, 'maybe_prune_opportunistically' ) );
}
