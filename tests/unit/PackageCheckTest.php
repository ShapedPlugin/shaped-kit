<?php
/**
 * Unit tests for the package verifier's decisions. No zip, no WordPress, no third-party packages.
 *
 * Run: php tests/unit/PackageCheckTest.php
 *
 * Risk covered: a release that installs but does not work (no dashboard build, an adapter file missing,
 * a class left out), one that ships what it should not (docs, tests, the JS sources, node_modules), or
 * one whose build quietly lost the dependency that carries the REST nonce.
 *
 * @package ShapedKit
 */

require __DIR__ . '/../../tools/verify-package.php';

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

$has = function ( array $problems, $needle ) {
	foreach ( $problems as $problem ) {
		if ( false !== strpos( $problem, $needle ) ) {
			return true;
		}
	}

	return false;
};

$libs = array(
	'libs/mcp-adapter/mcp-adapter.php',
	'libs/mcp-adapter/includes/Core/McpAdapter.php',
	'libs/mcp-adapter/vendor/autoload.php',
	'libs/mcp-adapter/README.md',
);
$src  = array(
	'src/Admin/DashboardPage.php',
	'src/Mcp/AdapterBootstrap.php',
	'src/Mcp/ProductRegistry.php',
	'src/Rest/McpController.php',
);

// A complete, clean package, as the zip would list it.
$good_files = array_merge(
	array(
		'shaped-kit.php',
		'package.json',
		'README.md',
		'build/admin/index.js',
		'build/admin/index.asset.php',
		'build/admin/style-index.css',
	),
	$src,
	$libs
);
$prefix     = function ( array $files ) {
	return array_map(
		function ( $file ) {
			return 'shaped-kit/' . $file;
		},
		$files
	);
};
$expect     = array(
	'root'     => 'shaped-kit',
	'src'      => $src,
	'libs'     => $libs,
	'asset'    => "<?php return array('dependencies' => array('react-jsx-runtime','wp-api-fetch','wp-element','wp-i18n'),'version' => 'x');",
	'versions' => array(
		'header'   => '0.1.0',
		'constant' => '0.1.0',
		'package'  => '0.1.0',
	),
);

$problems = function ( array $files, array $changes = array() ) use ( $prefix, $expect ) {
	return shaped_kit_package_problems( $prefix( $files ), array_merge( $expect, $changes ) );
};

$check( 'a complete, clean package has no problems', $problems( $good_files ), array() );
$check( 'an empty zip', shaped_kit_package_problems( array(), $expect ), array( 'The zip is empty.' ) );

// What must be there.
$no_build = array_values( array_diff( $good_files, array( 'build/admin/index.js', 'build/admin/index.asset.php', 'build/admin/style-index.css' ) ) );
$found    = $problems( $no_build );
$check( 'no dashboard build is reported', $has( $found, 'Missing: build/admin/index.js' ), true );
$check( 'no build stylesheet is reported', $has( $found, 'Missing: build/admin/style-index.css' ), true );

$no_adapter = array_values( array_diff( $good_files, array( 'libs/mcp-adapter/includes/Core/McpAdapter.php' ) ) );
$found      = $problems( $no_adapter );
$check( 'a missing adapter class is reported as missing', $has( $found, 'Missing: libs/mcp-adapter/includes/Core/McpAdapter.php' ), true );
$check( 'and as a gap in the bundled adapter', $has( $found, 'The bundled adapter is missing a file' ), true );

$check( 'a class in src/ left out of the zip', $has( $problems( array_values( array_diff( $good_files, array( 'src/Mcp/ProductRegistry.php' ) ) ) ), 'In src/ on disk but not in the zip: src/Mcp/ProductRegistry.php' ), true );
$check( 'an adapter file left out (not a required one)', $has( $problems( array_values( array_diff( $good_files, array( 'libs/mcp-adapter/README.md' ) ) ) ), 'The bundled adapter is missing a file: libs/mcp-adapter/README.md' ), true );
$check( 'an extra file in the adapter folder', $has( $problems( array_merge( $good_files, array( 'libs/mcp-adapter/patched.php' ) ) ), 'has a file the source does not' ), true );

// What must not be there.
foreach ( array( 'docs/PRD/x.md', 'tests/unit/x.php', 'tools/phpunit/x', 'node_modules/x/index.js', 'assets/admin/App.js', 'composer.json', 'CLAUDE.md', '.wordpress/x' ) as $stray ) {
	$check( "{$stray} does not belong", $has( $problems( array_merge( $good_files, array( $stray ) ) ), 'Does not belong in a release: ' . explode( '/', $stray )[0] ), true );
}

$check( 'a stray .DS_Store in our code', $has( $problems( array_merge( $good_files, array( 'src/.DS_Store' ) ) ), 'Editor, OS or log file: src/.DS_Store' ), true );
$check( 'a source map in the build', $has( $problems( array_merge( $good_files, array( 'build/admin/index.js.map' ) ) ), 'Editor, OS or log file' ), true );
$check( 'upstream files inside the adapter are left alone', $problems( array_merge( $good_files, array( 'libs/mcp-adapter/.gitignore' ) ), array( 'libs' => array_merge( $libs, array( 'libs/mcp-adapter/.gitignore' ) ) ) ), array() );

// The folder.
$found = shaped_kit_package_problems( array_merge( $prefix( $good_files ), array( 'other-plugin/x.php' ) ), $expect );
$check( 'a file outside the plugin folder', $has( $found, 'Outside the shaped-kit/ folder: other-plugin/x.php' ), true );
$check( 'no root folder at all is reported', $has( shaped_kit_package_problems( $good_files, $expect ), 'Outside the shaped-kit/ folder' ), true );

// The build's dependencies.
$check( 'a build that lost wp-api-fetch', $has( $problems( $good_files, array( 'asset' => "array('dependencies' => array('wp-element'))" ) ), 'no longer depends on wp-api-fetch' ), true );
$check( 'a build that lost wp-element', $has( $problems( $good_files, array( 'asset' => "array('dependencies' => array('wp-api-fetch'))" ) ), 'no longer depends on wp-element' ), true );
$check( 'an asset file that is empty', $has( $problems( $good_files, array( 'asset' => '' ) ), 'no longer depends on wp-api-fetch' ), true );

// Versions.
$drift = function ( array $versions ) use ( $problems, $good_files ) {
	return $problems( $good_files, array( 'versions' => $versions ) );
};
$check( 'the header and the constant disagree', $has( $drift( array( 'header' => '0.2.0', 'constant' => '0.1.0', 'package' => '0.2.0' ) ), 'Versions disagree' ), true );
$check( 'package.json is behind', $has( $drift( array( 'header' => '0.2.0', 'constant' => '0.2.0', 'package' => '0.1.0' ) ), 'Versions disagree' ), true );
$check( 'no version found in the header', $has( $drift( array( 'header' => '', 'constant' => '', 'package' => '' ) ), 'Versions disagree' ), true );
$check( 'all three agree', $drift( array( 'header' => '1.4.0', 'constant' => '1.4.0', 'package' => '1.4.0' ) ), array() );

echo "\n{$total} cases, {$failures} failed\n";

exit( $failures > 0 ? 1 : 0 );
