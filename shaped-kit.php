<?php
/**
 * Plugin Name:       Shaped Kit
 * Description:       Connects AI agents to ShapedPlugin products through the Model Context Protocol (MCP).
 * Version:           0.1.0
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

define( 'SHAPED_KIT_VERSION', '0.1.0' );
define( 'SHAPED_KIT_FILE', __FILE__ );
define( 'SHAPED_KIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHAPED_KIT_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'ShapedKit\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$file = SHAPED_KIT_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// At include time, not on plugins_loaded: the earliest point the Kit can supply the adapter before
// plugins that load after it. Plugins that loaded earlier keep theirs.
\ShapedKit\Mcp\AdapterBootstrap::maybe_load();

if ( is_admin() ) {
	\ShapedKit\Admin\DashboardPage::register();
}
