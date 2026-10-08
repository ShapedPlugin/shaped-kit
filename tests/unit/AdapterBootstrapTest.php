<?php
/**
 * Unit tests for AdapterBootstrap's pure decisions. No WordPress, no third-party packages.
 *
 * Run: php tests/unit/AdapterBootstrapTest.php
 *
 * @package ShapedKit
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../../src/Mcp/AdapterBootstrap.php';

use ShapedKit\Mcp\AdapterBootstrap;

$failures = 0;
$total    = 0;

$check = function ( $label, $actual, $expected ) use ( &$failures, &$total ) {
	++$total;

	if ( $actual === $expected ) {
		echo "ok    {$label}\n";
		return;
	}

	++$failures;
	echo "FAIL  {$label}: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
};

$plugins = '/srv/wp/wp-content/plugins';
$bundled = '/srv/wp/wp-content/plugins/shaped-kit/libs/mcp-adapter/';
$kit_dir = '/srv/wp/wp-content/plugins/shaped-kit/';
$class   = '/includes/Core/McpAdapter.php';

// A fake filesystem: only these adapter marker files exist.
$exists_in = function ( array $existing ) {
	return function ( $path ) use ( $existing ) {
		return in_array( $path, $existing, true );
	};
};
$standalone_marker = $plugins . '/mcp-adapter/includes/Core/McpAdapter.php';
$renamed_marker    = $plugins . '/wp-mcp-adapter/includes/Core/McpAdapter.php';
$kit_marker        = $kit_dir . 'includes/Core/McpAdapter.php';

echo "-- wordpress_supported\n";
$check( '6.9', AdapterBootstrap::wordpress_supported( '6.9' ), true );
$check( '7.1.3', AdapterBootstrap::wordpress_supported( '7.1.3' ), true );
$check( '6.10 compares above 6.9', AdapterBootstrap::wordpress_supported( '6.10' ), true );
$check( '6.9-beta1 counts as 6.9', AdapterBootstrap::wordpress_supported( '6.9-beta1' ), true );
$check( '6.8.3', AdapterBootstrap::wordpress_supported( '6.8.3' ), false );
$check( 'empty', AdapterBootstrap::wordpress_supported( '' ), false );
$check( 'garbage', AdapterBootstrap::wordpress_supported( 'abc' ), false );

echo "-- find_adapter_plugin\n";
$active = array( $plugins . '/seo-by-rank-math/rank-math.php', $plugins . '/shaped-kit/shaped-kit.php' );
$check( 'no adapter plugin active', AdapterBootstrap::find_adapter_plugin( $active, $kit_dir, $plugins, $exists_in( array( $standalone_marker ) ) ), '' );
$check(
	'standalone mcp-adapter active',
	AdapterBootstrap::find_adapter_plugin( array_merge( $active, array( $plugins . '/mcp-adapter/mcp-adapter.php' ) ), $kit_dir, $plugins, $exists_in( array( $standalone_marker ) ) ),
	$plugins . '/mcp-adapter/'
);
$check(
	'renamed folder sorting after shaped-kit is still found',
	AdapterBootstrap::find_adapter_plugin( array_merge( $active, array( $plugins . '/wp-mcp-adapter/mcp-adapter.php' ) ), $kit_dir, $plugins, $exists_in( array( $renamed_marker ) ) ),
	$plugins . '/wp-mcp-adapter/'
);
$check( 'the Kit itself never counts', AdapterBootstrap::find_adapter_plugin( $active, $kit_dir, $plugins, $exists_in( array( $kit_marker ) ) ), '' );
$check(
	'a single-file plugin in the plugins root is skipped',
	AdapterBootstrap::find_adapter_plugin( array( $plugins . '/hello.php' ), $kit_dir, $plugins, $exists_in( array( $plugins . '/includes/Core/McpAdapter.php' ) ) ),
	''
);

echo "-- is_adapter_activation_request\n";
$adapter_fs = $exists_in( array( $standalone_marker ) );
$check( 'single activation of the adapter', AdapterBootstrap::is_adapter_activation_request( array( 'action' => 'activate', 'plugin' => 'mcp-adapter/mcp-adapter.php' ), $plugins, $adapter_fs ), true );
$check(
	'bulk activation including the adapter (action2)',
	AdapterBootstrap::is_adapter_activation_request( array( 'action2' => 'activate-selected', 'checked' => array( 'akismet/akismet.php', 'mcp-adapter/mcp-adapter.php' ) ), $plugins, $adapter_fs ),
	true
);
$check( 'activating some other plugin', AdapterBootstrap::is_adapter_activation_request( array( 'action' => 'activate', 'plugin' => 'akismet/akismet.php' ), $plugins, $adapter_fs ), false );
$check( 'deactivating the adapter', AdapterBootstrap::is_adapter_activation_request( array( 'action' => 'deactivate', 'plugin' => 'mcp-adapter/mcp-adapter.php' ), $plugins, $adapter_fs ), false );
$check( 'no action at all', AdapterBootstrap::is_adapter_activation_request( array(), $plugins, $adapter_fs ), false );
$check(
	'leading slash and backslashes are normalised',
	AdapterBootstrap::is_adapter_activation_request( array( 'action' => 'activate', 'plugin' => ' /mcp-adapter\\mcp-adapter.php ' ), $plugins, $adapter_fs ),
	true
);
$check(
	'path traversal is ignored, not followed',
	AdapterBootstrap::is_adapter_activation_request( array( 'action' => 'activate', 'plugin' => '../plugins/mcp-adapter/mcp-adapter.php' ), $plugins, $exists_in( array( $plugins . '/../plugins/mcp-adapter/includes/Core/McpAdapter.php' ) ) ),
	false
);
$check( 'non-string values are skipped', AdapterBootstrap::is_adapter_activation_request( array( 'action' => 'activate', 'plugin' => array( 'x' ) ), $plugins, $adapter_fs ), false );

echo "-- is_cli_adapter_activation\n";
$cli = function ( $args, $assoc = array(), $any_installed = false ) use ( $plugins, $adapter_fs ) {
	return AdapterBootstrap::is_cli_adapter_activation( $args, $assoc, $plugins, $adapter_fs, $any_installed );
};
$check( 'wp plugin activate mcp-adapter', $cli( array( 'plugin', 'activate', 'mcp-adapter' ) ), true );
$check( 'wp plugin activate mcp-adapter/mcp-adapter.php', $cli( array( 'plugin', 'activate', 'mcp-adapter/mcp-adapter.php' ) ), true );
$check( 'wp plugin activate akismet mcp-adapter (several)', $cli( array( 'plugin', 'activate', 'akismet', 'mcp-adapter' ) ), true );
$check( 'wp plugin activate mcp-adapter --network', $cli( array( 'plugin', 'activate', 'mcp-adapter' ), array( 'network' => true ) ), true );
$check( 'wp plugin toggle mcp-adapter', $cli( array( 'plugin', 'toggle', 'mcp-adapter' ) ), true );
$check( 'wp plugin activate akismet', $cli( array( 'plugin', 'activate', 'akismet' ) ), false );
$check( 'wp plugin activate --all, an adapter is installed', $cli( array( 'plugin', 'activate' ), array( 'all' => true ), true ), true );
$check( 'wp plugin activate --all, no adapter installed', $cli( array( 'plugin', 'activate' ), array( 'all' => true ), false ), false );
$check( 'wp plugin install mcp-adapter --activate', $cli( array( 'plugin', 'install', 'mcp-adapter' ), array( 'activate' => true ) ), true );
$check( 'wp plugin install from a zip URL --activate-network', $cli( array( 'plugin', 'install', 'https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip' ), array( 'activate-network' => true ) ), true );
$check( 'wp plugin install mcp-adapter (no --activate)', $cli( array( 'plugin', 'install', 'mcp-adapter' ) ), false );
$check( 'wp plugin deactivate mcp-adapter', $cli( array( 'plugin', 'deactivate', 'mcp-adapter' ) ), false );
$check( 'wp plugin list', $cli( array( 'plugin', 'list' ) ), false );
$check( 'wp cache flush', $cli( array( 'cache', 'flush' ) ), false );
$check( 'no command', $cli( array() ), false );
$check( 'path traversal is ignored', $cli( array( 'plugin', 'activate', '../mcp-adapter' ) ), false );

echo "-- classify_source\n";
$check( 'our bundled copy', AdapterBootstrap::classify_source( $bundled . 'includes/Core/McpAdapter.php', $bundled, $plugins ), 'shaped-kit' );
$check( 'Rank Math vendor copy', AdapterBootstrap::classify_source( $plugins . '/seo-by-rank-math/vendor/wordpress/mcp-adapter' . $class, $bundled, $plugins ), 'seo-by-rank-math' );
$check( 'standalone MCP Adapter plugin', AdapterBootstrap::classify_source( $plugins . '/mcp-adapter' . $class, $bundled, $plugins ), 'mcp-adapter' );
$check( 'plugins dir given with a trailing slash', AdapterBootstrap::classify_source( $plugins . '/woocommerce/vendor/wordpress/mcp-adapter' . $class, $bundled, $plugins . '/' ), 'woocommerce' );
$check(
	'Windows paths',
	AdapterBootstrap::classify_source(
		'C:\\wp\\wp-content\\plugins\\seo-by-rank-math\\vendor\\wordpress\\mcp-adapter\\includes\\Core\\McpAdapter.php',
		'C:\\wp\\wp-content\\plugins\\shaped-kit\\libs\\mcp-adapter',
		'C:\\wp\\wp-content\\plugins'
	),
	'seo-by-rank-math'
);
$check( 'a sibling of the plugins dir is not a plugin', AdapterBootstrap::classify_source( '/srv/wp/wp-content/plugins-old/x' . $class, $bundled, $plugins ), 'unknown' );
$check( 'mu-plugins', AdapterBootstrap::classify_source( '/srv/wp/wp-content/mu-plugins/x' . $class, $bundled, $plugins ), 'unknown' );
$check( 'no file (internal class)', AdapterBootstrap::classify_source( '', $bundled, $plugins ), 'unknown' );

echo "\n{$total} cases, {$failures} failed\n";

exit( $failures > 0 ? 1 : 0 );
