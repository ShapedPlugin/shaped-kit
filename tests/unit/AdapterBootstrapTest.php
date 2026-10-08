<?php
/**
 * Unit tests for AdapterBootstrap::classify_source(). No WordPress, no third-party packages.
 *
 * Run: php tests/unit/AdapterBootstrapTest.php
 *
 * @package ShapedKit
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../../src/Mcp/AdapterBootstrap.php';

use ShapedKit\Mcp\AdapterBootstrap;

$plugins = '/srv/wp/wp-content/plugins';
$bundled = '/srv/wp/wp-content/plugins/shaped-kit/libs/mcp-adapter/';
$class   = '/includes/Core/McpAdapter.php';

$cases = array(
	array( 'our bundled copy', $bundled . 'includes/Core/McpAdapter.php', $bundled, $plugins, 'shaped-kit' ),
	array( 'Rank Math vendor copy', $plugins . '/seo-by-rank-math/vendor/wordpress/mcp-adapter' . $class, $bundled, $plugins, 'seo-by-rank-math' ),
	array( 'standalone MCP Adapter plugin', $plugins . '/mcp-adapter' . $class, $bundled, $plugins, 'mcp-adapter' ),
	array( 'plugins dir given with a trailing slash', $plugins . '/woocommerce/vendor/wordpress/mcp-adapter' . $class, $bundled, $plugins . '/', 'woocommerce' ),
	array(
		'Windows paths',
		'C:\\wp\\wp-content\\plugins\\seo-by-rank-math\\vendor\\wordpress\\mcp-adapter\\includes\\Core\\McpAdapter.php',
		'C:\\wp\\wp-content\\plugins\\shaped-kit\\libs\\mcp-adapter',
		'C:\\wp\\wp-content\\plugins',
		'seo-by-rank-math',
	),
	array( 'a sibling of the plugins dir is not a plugin', '/srv/wp/wp-content/plugins-old/x' . $class, $bundled, $plugins, 'unknown' ),
	array( 'mu-plugins', '/srv/wp/wp-content/mu-plugins/x' . $class, $bundled, $plugins, 'unknown' ),
	array( 'no file (internal class)', '', $bundled, $plugins, 'unknown' ),
);

$failures = 0;

foreach ( $cases as $case ) {
	list( $label, $file, $bundled_dir, $plugins_dir, $expected ) = $case;

	$actual = AdapterBootstrap::classify_source( $file, $bundled_dir, $plugins_dir );

	if ( $actual === $expected ) {
		echo "ok    {$label}\n";
		continue;
	}

	++$failures;
	echo "FAIL  {$label}: expected '{$expected}', got '{$actual}'\n";
}

echo "\n" . count( $cases ) . ' cases, ' . $failures . " failed\n";

exit( $failures > 0 ? 1 : 0 );
