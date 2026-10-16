/**
 * Builds the admin dashboard without ever leaving `build/admin` empty.
 *
 * `wp-scripts build` empties its output folder before it writes (`output.clean` in
 * `@wordpress/scripts/config/webpack.config.js`). Anyone who opens the AI & MCP page while a build runs then
 * gets the "dashboard is missing" notice, or a script that 404s. So this builds into a temporary folder and
 * only when that folder holds a complete build does it replace `build/admin`:
 *
 *   build/.admin-next   the new build, written here
 *   build/admin         the live build, replaced by one rename at the end
 *   build/.admin-prev   the old build for a moment, then deleted
 *
 * A failed or incomplete build leaves `build/admin` exactly as it was. `npm run build` runs this file, and so
 * does `npm run package`, whose `package:verify` step is still the gate on what goes into the zip.
 * `npm start` (watch mode) still writes to `build/admin` directly: it is for development, not for a release.
 *
 * @package ShapedKit
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

/**
 * Files the dashboard cannot load without. Same three that `tools/verify-package.php` requires from `build/admin`.
 */
const REQUIRED = [ 'index.js', 'index.asset.php', 'style-index.css' ];

/**
 * Run `wp-scripts build` into a folder.
 *
 * @param {string} root      Plugin folder.
 * @param {string} outputDir Folder the build is written to.
 * @return {boolean} Whether the build exited with status 0.
 */
const wpScriptsBuild = ( root, outputDir ) => {
	const bin = require.resolve( '@wordpress/scripts/bin/wp-scripts.js', {
		paths: [ root ],
	} );
	const result = spawnSync(
		process.execPath,
		[
			bin,
			'build',
			'assets/admin/index.js',
			`--output-path=${ path.relative( root, outputDir ) }`,
		],
		{ cwd: root, stdio: 'inherit' }
	);

	return result.status === 0;
};

/**
 * Required files that are missing or empty in a folder.
 *
 * @param {string} dir Folder to look in.
 * @return {string[]} File names.
 */
const missingFiles = ( dir ) =>
	REQUIRED.filter( ( name ) => {
		try {
			return fs.statSync( path.join( dir, name ) ).size === 0;
		} catch ( failure ) {
			return true;
		}
	} );

const remove = ( dir ) => fs.rmSync( dir, { recursive: true, force: true } );

/**
 * Build, check, then swap.
 *
 * @param {Object}   options
 * @param {string}   options.root  Plugin folder.
 * @param {Function} options.build `( root, outputDir ) => boolean`. Replaced only by tests.
 * @return {{ok: boolean, reason?: string, missing?: string[]}} The outcome.
 */
const safeBuild = ( {
	root = path.resolve( __dirname, '..' ),
	build = wpScriptsBuild,
} = {} ) => {
	const buildDir = path.join( root, 'build' );
	const live = path.join( buildDir, 'admin' );
	const next = path.join( buildDir, '.admin-next' );
	const prev = path.join( buildDir, '.admin-prev' );

	fs.mkdirSync( buildDir, { recursive: true } );

	// Leftovers of a run that was killed half way.
	remove( next );
	remove( prev );

	if ( ! build( root, next ) ) {
		remove( next );
		return { ok: false, reason: 'build_failed' };
	}

	const missing = missingFiles( next );
	if ( missing.length > 0 ) {
		remove( next );
		return { ok: false, reason: 'incomplete', missing };
	}

	const hadLive = fs.existsSync( live );

	if ( hadLive ) {
		fs.renameSync( live, prev );
	}

	try {
		fs.renameSync( next, live );
	} catch ( failure ) {
		// Put the old build back: the page must never be left without one.
		if ( hadLive ) {
			fs.renameSync( prev, live );
		}
		remove( next );
		throw failure;
	}

	remove( prev );

	return { ok: true };
};

if ( require.main === module ) {
	let outcome;

	try {
		outcome = safeBuild();
	} catch ( failure ) {
		console.error(
			`Could not put the new build in place: ${ failure.message }`
		); // eslint-disable-line no-console
		process.exit( 1 );
	}

	if ( ! outcome.ok ) {
		console.error(
			// eslint-disable-line no-console
			outcome.reason === 'incomplete'
				? `The build is incomplete (missing or empty: ${ outcome.missing.join(
						', '
				  ) }). build/admin was left as it was.`
				: 'The build failed. build/admin was left as it was.'
		);
		process.exit( 1 );
	}
}

module.exports = { safeBuild, missingFiles, REQUIRED };
