<?php
/**
 * Copies the template's shared files (`templates/product-module/Support/`, `Media/`) into a product's MCP module.
 *
 * Run:  php tools/sync-product-module.php <product> [--write] [--diff] [--plugins-dir=PATH]
 *
 *   <product>  real-testimonials | wp-carousel | location-weather (Free and Pro are always synced together)
 *   --write    write the files; without it nothing is written (dry run)
 *   --diff     show what would change
 *
 * It exists because hand-copied shared code drifts: on 2026-10-22 only `Media/`, the one folder with a drift test,
 * still matched the template, and a page-limit fix had reached one product only (PRD E181,
 * `docs/wp-mcp-engine.md` §9.6). Plumbing and product data are never touched: only the files listed per product.
 *
 * Guardrails (PRD §19.9, E200 to E210):
 * - writes only the listed files, inside the product's module folder, never through a symlink, never creating a folder;
 * - refuses a product repo that is not on `feat/mcp-module` or has uncommitted changes in any target file;
 * - builds and lints every output of both editions first and writes nothing unless all of them pass;
 * - fails on any template name left behind and on a third-party product named in a comment (C8);
 * - copies bytes exactly, and refuses a template file with Windows line endings or a byte-order mark;
 * - runs no shell and calls no network: git and `php -l` are run with argument arrays.
 *
 * The decisions are plain functions that take arrays or strings, so they are tested without a product repo
 * (`tests/phpunit/unit/ProductModuleSyncTest.php`). This file is a developer tool: `tools/` is not in the release zip.
 *
 * @package ShapedKit
 */

/**
 * The branch every product repo keeps its MCP work on (FR-BR1).
 */
const SHAPED_KIT_SYNC_BRANCH = 'feat/mcp-module';

/**
 * Each product family, its editions and the shared files it receives.
 *
 * `Builder/` is not here: WP Carousel's copy mixes engine and product data (PRD E197 note, §9.6 point 4).
 * Location Weather takes only the three helpers its copies already share (decision S6).
 *
 * @return array<string, array{slug: string, prefix: string, files: string[], editions: array<string, array{plugin: string, module: string, namespace: string, domain: string, package: string}>}>
 */
function shaped_kit_sync_products() {
	$media = array(
		'Media/Image_File.php',
		'Media/Media_Abilities.php',
		'Media/Media_Tools.php',
		'Media/Remote_Fetch.php',
		'Media/Remote_Guard.php',
	);

	return array(
		'real-testimonials' => array(
			'slug'     => 'real-testimonials',
			'prefix'   => 'spt',
			'files'    => array_merge(
				array( 'Support/Audit.php', 'Support/Pagination.php', 'Support/Secret_Mask.php', 'Support/Url.php', 'Support/WriteGuard.php' ),
				$media
			),
			'editions' => array(
				'free' => array(
					'plugin'    => 'testimonial-free',
					'module'    => 'src/Mcp',
					'namespace' => 'TestimonialFree',
					'domain'    => 'testimonial-free',
					'package'   => 'Real_Testimonials_Free',
				),
				'pro'  => array(
					'plugin'    => 'testimonial-pro',
					'module'    => 'src/Mcp',
					'namespace' => 'TestimonialPro',
					'domain'    => 'testimonial-pro',
					'package'   => 'Real_Testimonials_Pro',
				),
			),
		),
		'wp-carousel'       => array(
			'slug'     => 'wp-carousel',
			'prefix'   => 'wpcp',
			'files'    => array_merge(
				array( 'Support/Audit.php', 'Support/Pagination.php', 'Support/Secret_Mask.php', 'Support/Token_Budget.php', 'Support/Url.php', 'Support/WriteGuard.php' ),
				$media
			),
			'editions' => array(
				'free' => array(
					'plugin'    => 'wp-carousel-free',
					'module'    => 'src/Mcp',
					'namespace' => 'WPCarouselFree',
					'domain'    => 'wp-carousel-free',
					'package'   => 'WP_Carousel_Free',
				),
				'pro'  => array(
					'plugin'    => 'wp-carousel-pro',
					'module'    => 'src/Mcp',
					'namespace' => 'WPCarouselPro',
					'domain'    => 'wp-carousel-pro',
					'package'   => 'WP_Carousel_Pro',
				),
			),
		),
		'location-weather'  => array(
			'slug'     => 'location-weather',
			'prefix'   => 'splw',
			'files'    => array( 'Support/Pagination.php', 'Support/Secret_Mask.php', 'Support/WriteGuard.php' ),
			// Both editions share one namespace, so whichever edition's autoloader runs first serves both: the copies
			// must stay byte-identical, package line included (E216).
			'editions' => array(
				'free' => array(
					'plugin'    => 'location-weather',
					'module'    => 'includes/Mcp',
					'namespace' => 'Weather',
					'domain'    => 'location-weather',
					'package'   => 'Location_Weather',
				),
				'pro'  => array(
					'plugin'    => 'location-weather-pro',
					'module'    => 'includes/Mcp',
					'namespace' => 'Weather',
					'domain'    => 'location-weather',
					'package'   => 'Location_Weather',
				),
			),
		),
	);
}

/**
 * The template file turned into one edition's copy.
 *
 * Only exact placeholder tokens are replaced, never a bare word such as "template" (E204). The header rules are the
 * ones `ProductMediaCopyTestCase` already applied to `Media/`, so a copy that is in step stays byte-identical.
 *
 * @param string $source  Template file contents.
 * @param string $folder  Folder of the file inside the template, for example `Support`.
 * @param array  $product One entry of `shaped_kit_sync_products()`.
 * @param array  $edition One edition of that entry.
 *
 * @return string
 */
function shaped_kit_sync_transform( $source, $folder, array $product, array $edition ) {
	$output = str_replace(
		array(
			'ShapedPlugin\\Template\\Mcp',
			"'template-domain'",
			'template_prefix_mcp_',
			'"template-slug/',
			'ability definitions helper template.',
			'@package ShapedPlugin',
		),
		array(
			'ShapedPlugin\\' . $edition['namespace'] . '\\Mcp',
			"'" . $edition['domain'] . "'",
			$product['prefix'] . '_mcp_',
			'"' . $product['slug'] . '/',
			'ability definitions.',
			'@package ' . $edition['package'],
		),
		$source
	);

	$output = (string) preg_replace(
		'/^ \* Copy (?:this file|the whole `\w+\/` folder) into a product\'s MCP module\..*$/m',
		' * Copied from the Shaped Kit product-module template (`templates/product-module/' . $folder . '/`): keep the copies in step.',
		$output
	);

	return (string) preg_replace( '/^ \* The (\w+) template file: /m', ' * $1: ', $output );
}

/**
 * Problems in a template file that must be fixed before it is copied (E207).
 *
 * @param string $source Template file contents.
 *
 * @return string[]
 */
function shaped_kit_sync_source_problems( $source ) {
	$problems = array();

	if ( 0 === strpos( $source, "\xEF\xBB\xBF" ) ) {
		$problems[] = 'starts with a byte-order mark';
	}

	if ( false !== strpos( $source, "\r" ) ) {
		$problems[] = 'has Windows line endings';
	}

	return $problems;
}

/**
 * Problems in a finished copy: a template name left behind (E203) or a third-party name in a comment (C8, E210).
 *
 * @param string $output Copy contents.
 *
 * @return string[]
 */
function shaped_kit_sync_output_problems( $output ) {
	$problems = array();

	foreach ( array( 'ShapedPlugin\\Template\\', 'template-domain', 'template_prefix', 'template-slug', 'template file:', '@package ShapedPlugin' . "\n" ) as $token ) {
		if ( false !== strpos( $output, $token ) ) {
			$problems[] = 'still holds the template name ' . trim( $token );
		}
	}

	foreach ( shaped_kit_sync_comments( $output ) as $comment ) {
		if ( preg_match( '/fluent|wpmanageninja|woo\s?commerce|rank\s?math|polylang|wpml|zip\s?ai|yoast/i', $comment, $match ) ) {
			$problems[] = 'names a third-party product in a comment (' . $match[0] . ')';
		}
	}

	return array_values( array_unique( $problems ) );
}

/**
 * The comments of a PHP file.
 *
 * @param string $code PHP source.
 *
 * @return string[]
 */
function shaped_kit_sync_comments( $code ) {
	$comments = array();

	foreach ( token_get_all( $code ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$comments[] = $token[1];
		}
	}

	return $comments;
}

/**
 * Where a copy goes, or why it may not go there (E200).
 *
 * The module folder must exist (the script never creates one), sit inside the plugin folder once symlinks are
 * resolved, and not be a symlink; the same for the file's own folder and the file itself.
 *
 * @param string $plugin_dir Plugin folder.
 * @param string $module     Module folder relative to the plugin, for example `src/Mcp`.
 * @param string $file       File relative to the module, from the product's list.
 *
 * @return array{path: string, problem: string}
 */
function shaped_kit_sync_target( $plugin_dir, $module, $file ) {
	$plugin_real = realpath( $plugin_dir );
	$module_dir  = $plugin_dir . '/' . $module;
	$folder      = dirname( $module_dir . '/' . $file );
	$path        = $module_dir . '/' . $file;

	if ( false === $plugin_real ) {
		return array(
			'path'    => $path,
			'problem' => 'the plugin folder does not exist',
		);
	}

	if ( false !== strpos( $file, '..' ) || false !== strpos( $module, '..' ) ) {
		return array(
			'path'    => $path,
			'problem' => 'the path leaves the module folder',
		);
	}

	foreach ( array( $module_dir, $folder ) as $dir ) {
		if ( is_link( $dir ) ) {
			return array(
				'path'    => $path,
				'problem' => 'a folder on the way is a symlink',
			);
		}

		$real = realpath( $dir );
		if ( false === $real || ! is_dir( $real ) ) {
			return array(
				'path'    => $path,
				'problem' => 'the folder does not exist (the script creates none)',
			);
		}

		if ( 0 !== strpos( $real . '/', $plugin_real . '/' ) ) {
			return array(
				'path'    => $path,
				'problem' => 'the folder resolves outside the plugin',
			);
		}
	}

	if ( is_link( $path ) ) {
		return array(
			'path'    => $path,
			'problem' => 'the file is a symlink',
		);
	}

	return array(
		'path'    => $path,
		'problem' => '',
	);
}

/**
 * Runs a command given as an argument array, without a shell.
 *
 * @param string[] $command Program and arguments.
 * @param string   $cwd     Working folder.
 *
 * @return array{status: int, output: string}
 */
function shaped_kit_sync_run( array $command, $cwd ) {
	$process = proc_open(
		$command,
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$cwd
	);

	if ( ! is_resource( $process ) ) {
		return array(
			'status' => 127,
			'output' => 'could not start ' . $command[0],
		);
	}

	$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	return array(
		'status' => proc_close( $process ),
		'output' => (string) $output,
	);
}

/**
 * Why a product repo may not receive files (E201): wrong branch or uncommitted changes in a target file.
 *
 * @param string   $plugin_dir Plugin folder (the repo root).
 * @param string[] $paths      Target files relative to the repo.
 *
 * @return string[]
 */
function shaped_kit_sync_repo_problems( $plugin_dir, array $paths ) {
	$branch = shaped_kit_sync_run( array( 'git', 'rev-parse', '--abbrev-ref', 'HEAD' ), $plugin_dir );

	if ( 0 !== $branch['status'] ) {
		return array( 'is not a git repository' );
	}

	$problems = array();
	$current  = trim( $branch['output'] );

	if ( SHAPED_KIT_SYNC_BRANCH !== $current ) {
		$problems[] = 'is on branch ' . $current . ', not ' . SHAPED_KIT_SYNC_BRANCH;
	}

	$status = shaped_kit_sync_run( array_merge( array( 'git', 'status', '--porcelain', '--' ), $paths ), $plugin_dir );

	if ( 0 !== $status['status'] ) {
		$problems[] = 'git status failed';
	} elseif ( '' !== trim( $status['output'] ) ) {
		$problems[] = 'has uncommitted changes in a target file: ' . preg_replace( '/\s+/', ' ', trim( $status['output'] ) );
	}

	return $problems;
}

/**
 * Whether PHP parses a copy, checked from a temporary file.
 *
 * @param string $output Copy contents.
 *
 * @return string Empty when it parses, else the reason.
 */
function shaped_kit_sync_lint( $output ) {
	$file = tempnam( sys_get_temp_dir(), 'skpm' );
	file_put_contents( $file, $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Developer CLI tool, no WordPress loaded.

	$result = shaped_kit_sync_run( array( PHP_BINARY, '-l', $file ), sys_get_temp_dir() );
	unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Developer CLI tool, no WordPress loaded.

	return 0 === $result['status'] ? '' : 'does not parse';
}

/**
 * Every copy of one product family, with its status, and every reason not to write. Writes nothing.
 *
 * @param string $template_dir `templates/product-module` of the Kit.
 * @param string $plugins_dir  Folder that holds the product plugins.
 * @param string $slug         Product key of `shaped_kit_sync_products()`.
 * @param bool   $check_repos  Whether to run the git checks (off only for tests of the other rules).
 *
 * @return array{files: array<int, array{edition: string, file: string, path: string, output: string, status: string}>, problems: string[]}
 */
function shaped_kit_sync_plan( $template_dir, $plugins_dir, $slug, $check_repos = true ) {
	$products = shaped_kit_sync_products();

	if ( ! isset( $products[ $slug ] ) ) {
		return array(
			'files'    => array(),
			'problems' => array( 'unknown product ' . $slug . ' (known: ' . implode( ', ', array_keys( $products ) ) . ')' ),
		);
	}

	$product  = $products[ $slug ];
	$files    = array();
	$problems = array();

	foreach ( $product['editions'] as $edition_name => $edition ) {
		$plugin_dir = $plugins_dir . '/' . $edition['plugin'];
		$repo_paths = array();

		foreach ( $product['files'] as $file ) {
			$label  = $edition['plugin'] . '/' . $edition['module'] . '/' . $file;
			$source = file_get_contents( $template_dir . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Developer CLI tool, no WordPress loaded.

			if ( false === $source ) {
				$problems[] = 'template file missing: ' . $file;
				continue;
			}

			foreach ( shaped_kit_sync_source_problems( $source ) as $problem ) {
				$problems[] = 'template ' . $file . ' ' . $problem;
			}

			$target = shaped_kit_sync_target( $plugin_dir, $edition['module'], $file );
			if ( '' !== $target['problem'] ) {
				$problems[] = $label . ': ' . $target['problem'];
				continue;
			}

			$output = shaped_kit_sync_transform( $source, dirname( $file ), $product, $edition );

			foreach ( shaped_kit_sync_output_problems( $output ) as $problem ) {
				$problems[] = $label . ' ' . $problem;
			}

			$lint = shaped_kit_sync_lint( $output );
			if ( '' !== $lint ) {
				$problems[] = $label . ' ' . $lint;
			}

			$current      = is_file( $target['path'] ) ? file_get_contents( $target['path'] ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Developer CLI tool, no WordPress loaded.
			$repo_paths[] = $edition['module'] . '/' . $file;
			$files[]      = array(
				'edition' => $edition_name,
				'file'    => $label,
				'path'    => $target['path'],
				'output'  => $output,
				'status'  => null === $current ? 'new' : ( $current === $output ? 'same' : 'changed' ),
			);
		}

		if ( $check_repos && is_dir( $plugin_dir ) && array() !== $repo_paths ) {
			foreach ( shaped_kit_sync_repo_problems( $plugin_dir, $repo_paths ) as $problem ) {
				$problems[] = $edition['plugin'] . ' ' . $problem;
			}
		}
	}

	return array(
		'files'    => $files,
		'problems' => $problems,
	);
}

/**
 * Writes a plan whose problems list is empty: each file through a temporary file and a rename in its own folder,
 * then read back.
 *
 * @param array $plan Result of `shaped_kit_sync_plan()`.
 *
 * @return string[] Problems; empty when every file was written and reads back as planned.
 */
function shaped_kit_sync_write( array $plan ) {
	if ( array() !== $plan['problems'] ) {
		return array( 'the plan has problems; nothing written' );
	}

	$problems = array();

	foreach ( $plan['files'] as $file ) {
		if ( 'same' === $file['status'] ) {
			continue;
		}

		$temp = $file['path'] . '.sync-' . bin2hex( random_bytes( 4 ) );
		if ( false === file_put_contents( $temp, $file['output'] ) || ! rename( $temp, $file['path'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename -- Developer CLI tool, no WordPress loaded.
			if ( is_file( $temp ) ) {
				unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Developer CLI tool, no WordPress loaded.
			}
			$problems[] = 'could not write ' . $file['file'];
			continue;
		}

		if ( file_get_contents( $file['path'] ) !== $file['output'] ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Developer CLI tool, no WordPress loaded.
			$problems[] = 'reads back differently: ' . $file['file'];
		}
	}

	return $problems;
}

// Run only when called from the command line directly, not when a test includes this file.
if ( PHP_SAPI === 'cli' && isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$options = array(
		'write'       => in_array( '--write', $argv, true ),
		'diff'        => in_array( '--diff', $argv, true ),
		'plugins-dir' => dirname( __DIR__, 2 ),
	);
	$product = '';

	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( 0 === strpos( $arg, '--plugins-dir=' ) ) {
			$options['plugins-dir'] = rtrim( substr( $arg, strlen( '--plugins-dir=' ) ), '/' );
		} elseif ( '--' !== substr( $arg, 0, 2 ) ) {
			$product = $arg;
		}
	}

	if ( '' === $product ) {
		echo "Usage: php tools/sync-product-module.php <product> [--write] [--diff] [--plugins-dir=PATH]\n";
		echo '  products: ' . implode( ', ', array_keys( shaped_kit_sync_products() ) ) . "\n";
		exit( 2 );
	}

	$plan = shaped_kit_sync_plan( dirname( __DIR__ ) . '/templates/product-module', $options['plugins-dir'], $product );

	foreach ( $plan['files'] as $file ) {
		printf( "  %-8s %s\n", $file['status'], $file['file'] );

		if ( $options['diff'] && 'changed' === $file['status'] ) {
			$temp = tempnam( sys_get_temp_dir(), 'skpm' );
			file_put_contents( $temp, $file['output'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Developer CLI tool.
			echo shaped_kit_sync_run( array( 'git', 'diff', '--no-index', '--no-color', '--', $file['path'], $temp ), sys_get_temp_dir() )['output']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line output.
			unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Developer CLI tool.
		}
	}

	if ( array() !== $plan['problems'] ) {
		echo 'REFUSED  ' . count( $plan['problems'] ) . " problem(s), nothing written:\n";
		foreach ( $plan['problems'] as $problem ) {
			echo "  - {$problem}\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line output.
		}
		exit( 1 );
	}

	$changes = count( array_diff( array_column( $plan['files'], 'status' ), array( 'same' ) ) );

	if ( ! $options['write'] ) {
		echo "DRY RUN  {$changes} file(s) would change. Run again with --write to write them.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line output.
		exit( 0 );
	}

	$written = shaped_kit_sync_write( $plan );
	if ( array() !== $written ) {
		echo "FAILED\n  - " . implode( "\n  - ", $written ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line output.
		exit( 1 );
	}

	echo "WROTE  {$changes} file(s). Review with git diff in each product repo; nothing is committed.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Command-line output.
	exit( 0 );
}
