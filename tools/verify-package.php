<?php
/**
 * Opens the plugin zip and fails if it is not something a stranger could install.
 *
 * Run:  php tools/verify-package.php [path/to/shaped-kit.zip]      (default: ./shaped-kit.zip)
 *
 * It exists because a package can be wrong in ways nothing else notices: the dashboard build is
 * git-ignored, so a zip made without it installs fine and shows no dashboard. It checks that
 *
 * - everything needed is there (the main file, every PHP class in src/, the dashboard build, the whole
 *   bundled adapter) and the build still depends on `wp-api-fetch`, which is what gives the page its REST
 *   nonce;
 * - nothing else is (docs, tests, tools, node_modules, JS sources, editor and OS files);
 * - the version is the same in the plugin header, the constant and package.json;
 * - every PHP file parses.
 *
 * The decisions are in `shaped_kit_package_problems()`, which takes plain arrays so it can be tested
 * without a zip. Only `shaped_kit_verify_zip()` touches the disk.
 *
 * @package ShapedKit
 */

/**
 * Files the plugin cannot work without, relative to the plugin folder.
 *
 * @return string[]
 */
function shaped_kit_required_files() {
	return array(
		'shaped-kit.php',
		'src/Admin/DashboardPage.php',
		'src/Mcp/AdapterBootstrap.php',
		'src/Mcp/ProductRegistry.php',
		'src/Rest/McpController.php',
		'build/admin/index.js',
		'build/admin/index.asset.php',
		'build/admin/style-index.css',
		'libs/mcp-adapter/mcp-adapter.php',
		'libs/mcp-adapter/includes/Core/McpAdapter.php',
		'libs/mcp-adapter/vendor/autoload.php',
	);
}

/**
 * What may sit at the top of the plugin folder. `package.json` and `README.md` are added by npm itself.
 *
 * @return string[]
 */
function shaped_kit_allowed_top_level() {
	return array( 'shaped-kit.php', 'src', 'libs', 'build', 'package.json', 'README.md' );
}

/**
 * Everything wrong with a package, as sentences. Empty means it is fine.
 *
 * @param string[] $entries File paths inside the zip, as the zip names them (folders left out).
 * @param array    $expect  {
 *     What the package is checked against.
 *
 *     @type string   $root     The folder every file must sit in, e.g. `shaped-kit`.
 *     @type string[] $src      Files under `src/` on disk, relative to the plugin folder.
 *     @type string[] $libs     Files under `libs/` on disk, relative to the plugin folder.
 *     @type string   $asset    Contents of `build/admin/index.asset.php` from the zip.
 *     @type array    $versions `header`, `constant` and `package` versions found in the zip.
 * }
 * @return string[]
 */
function shaped_kit_package_problems( array $entries, array $expect ) {
	$problems = array();
	$root     = rtrim( $expect['root'], '/' ) . '/';

	if ( array() === $entries ) {
		return array( 'The zip is empty.' );
	}

	$files = array();

	foreach ( $entries as $entry ) {
		if ( 0 !== strpos( $entry, $root ) ) {
			$problems[] = "Outside the {$root} folder: {$entry}";
			continue;
		}

		$files[] = substr( $entry, strlen( $root ) );
	}

	foreach ( shaped_kit_required_files() as $required ) {
		if ( ! in_array( $required, $files, true ) ) {
			$problems[] = "Missing: {$required}";
		}
	}

	$allowed = shaped_kit_allowed_top_level();
	$flagged = array();

	foreach ( $files as $file ) {
		$top = explode( '/', $file )[0];

		if ( ! in_array( $top, $allowed, true ) && ! isset( $flagged[ $top ] ) ) {
			$flagged[ $top ] = true;
			$problems[]      = "Does not belong in a release: {$top}";
		}

		// The bundled adapter is upstream's and is shipped as it is; everything else is ours to keep clean.
		if ( 0 !== strpos( $file, 'libs/' ) && 1 === preg_match( '#(^|/)(\.DS_Store|Thumbs\.db|\.env|\.phpunit\.result\.cache|[^/]+\.log|[^/]+\.map|\.git[^/]*)$#', $file ) ) {
			$problems[] = "Editor, OS or log file: {$file}";
		}
	}

	foreach ( $expect['src'] as $file ) {
		if ( ! in_array( $file, $files, true ) ) {
			$problems[] = "In src/ on disk but not in the zip: {$file}";
		}
	}

	$zip_libs = array_values(
		array_filter(
			$files,
			function ( $file ) {
				return 0 === strpos( $file, 'libs/' );
			}
		)
	);

	foreach ( array_diff( $expect['libs'], $zip_libs ) as $file ) {
		$problems[] = "The bundled adapter is missing a file: {$file}";
	}

	foreach ( array_diff( $zip_libs, $expect['libs'] ) as $file ) {
		$problems[] = "The bundled adapter has a file the source does not: {$file}";
	}

	// The page gets its REST nonce from `wp-api-fetch`. Without that dependency every dashboard call is a 401.
	foreach ( array( 'wp-api-fetch', 'wp-element' ) as $handle ) {
		if ( false === strpos( $expect['asset'], "'{$handle}'" ) ) {
			$problems[] = "The dashboard build no longer depends on {$handle} (see build/admin/index.asset.php).";
		}
	}

	$versions = $expect['versions'];

	if ( '' === (string) $versions['header'] || 1 !== count( array_unique( array( $versions['header'], $versions['constant'], $versions['package'] ) ) ) ) {
		$problems[] = sprintf(
			'Versions disagree: plugin header "%s", SHAPED_KIT_VERSION "%s", package.json "%s".',
			$versions['header'],
			$versions['constant'],
			$versions['package']
		);
	}

	return $problems;
}

/**
 * Every file under a folder, relative to the plugin folder, sorted.
 *
 * @param string $base   Plugin folder.
 * @param string $folder Folder inside it, e.g. `src`.
 * @return string[]
 */
function shaped_kit_list_files( $base, $folder ) {
	$found    = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base . '/' . $folder, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$found[] = substr( $file->getPathname(), strlen( $base ) + 1 );
		}
	}

	sort( $found );

	return $found;
}

/**
 * Check a real zip: read it, compare it with the folder it came from, and parse every PHP file in it.
 *
 * @param string $zip_path Path of the zip.
 * @param string $base     Plugin folder the zip was made from.
 * @return string[] Problems; empty when fine.
 */
function shaped_kit_verify_zip( $zip_path, $base ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return array( 'PHP has no zip extension, so the zip cannot be read.' );
	}

	if ( ! is_readable( $zip_path ) ) {
		return array( "No zip at {$zip_path}. Run `npm run package` first." );
	}

	$zip = new ZipArchive();

	if ( true !== $zip->open( $zip_path ) ) {
		return array( "{$zip_path} is not a readable zip." );
	}

	$entries = array();

	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = $zip->getNameIndex( $i );

		if ( '/' !== substr( $name, -1 ) ) {
			$entries[] = $name;
		}
	}

	$main    = (string) $zip->getFromName( 'shaped-kit/shaped-kit.php' );
	$package = json_decode( (string) $zip->getFromName( 'shaped-kit/package.json' ), true );

	preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $header );
	preg_match( "/define\(\s*'SHAPED_KIT_VERSION'\s*,\s*'([^']+)'/", $main, $constant );

	$problems = shaped_kit_package_problems(
		$entries,
		array(
			'root'     => 'shaped-kit',
			'src'      => shaped_kit_list_files( $base, 'src' ),
			'libs'     => shaped_kit_list_files( $base, 'libs' ),
			'asset'    => (string) $zip->getFromName( 'shaped-kit/build/admin/index.asset.php' ),
			'versions' => array(
				'header'   => isset( $header[1] ) ? $header[1] : '',
				'constant' => isset( $constant[1] ) ? $constant[1] : '',
				'package'  => isset( $package['version'] ) ? (string) $package['version'] : '',
			),
		)
	);

	// Parse every PHP file as it sits in the zip, not as it sits in the repo.
	$tmp = sys_get_temp_dir() . '/shaped-kit-verify-' . getmypid();
	$zip->extractTo( $tmp );
	$zip->close();

	foreach ( $entries as $entry ) {
		if ( '.php' !== substr( $entry, -4 ) ) {
			continue;
		}

		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $tmp . '/' . $entry ) . ' 2>&1', $output, $status );

		if ( 0 !== $status ) {
			$problems[] = "Does not parse: {$entry}";
		}

		$output = array();
	}

	exec( 'rm -rf ' . escapeshellarg( $tmp ) );

	return $problems;
}

// Run only when called from the command line directly, not when a test includes this file.
if ( PHP_SAPI === 'cli' && isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$base     = dirname( __DIR__ );
	$zip_path = isset( $argv[1] ) ? $argv[1] : $base . '/shaped-kit.zip';
	$problems = shaped_kit_verify_zip( $zip_path, $base );

	if ( array() === $problems ) {
		echo "OK  {$zip_path} is a complete, clean package.\n";
		exit( 0 );
	}

	echo 'FAIL  ' . count( $problems ) . " problem(s) in {$zip_path}:\n";

	foreach ( $problems as $problem ) {
		echo "  - {$problem}\n";
	}

	exit( 1 );
}
