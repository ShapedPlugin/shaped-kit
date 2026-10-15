<?php
/**
 * The "ShapedPlugin → AI & MCP" admin page.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Admin;

use ShapedKit\Rest\McpController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Kit's admin page. The page is a React mount point; PHP renders nothing else, except
 * one notice when the dashboard build is missing (see `render()`).
 */
final class DashboardPage {

	const SLUG = 'shaped-kit';

	const CAPABILITY = 'manage_options';

	/**
	 * Handle of the React app's script.
	 */
	const SCRIPT_HANDLE = 'shaped-kit-admin';

	/**
	 * Where the build's asset file is, relative to the plugin folder.
	 */
	const BUILD_ASSET = 'build/admin/index.asset.php';

	/**
	 * Where the built plugin is published.
	 */
	const RELEASES_URL = 'https://github.com/ShapedPlugin/shaped-kit/releases';

	/**
	 * The page's hook name, set when the menu is added. Empty until then, so nothing loads early.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Hook the menu registration.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Load the React app, on this page only.
	 *
	 * Skipped when the build is missing (a fresh checkout that has not run `npm run build`, or GitHub's
	 * "Source code" archive): the PHP heading then stays, with a notice that says why (see `render()`), and a
	 * failed build is not hidden behind a script that 404s.
	 *
	 * @param string      $hook       The admin page being loaded, as WordPress reports it.
	 * @param string|null $asset_file Path of the build's asset file. Only tests pass this.
	 *
	 * @return void
	 */
	public static function enqueue( $hook, $asset_file = null ) {
		if ( '' === self::$hook_suffix || $hook !== self::$hook_suffix || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$asset_file = null === $asset_file ? SHAPED_KIT_DIR . self::BUILD_ASSET : $asset_file;

		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			SHAPED_KIT_URL . 'build/admin/index.js',
			isset( $asset['dependencies'] ) ? (array) $asset['dependencies'] : array(),
			isset( $asset['version'] ) ? (string) $asset['version'] : SHAPED_KIT_VERSION,
			true
		);

		// The stylesheet is optional: it exists only once the build has styles. WordPress swaps in the
		// -rtl copy by itself on right-to-left sites (class-wp-styles.php), so only the one file is named.
		if ( is_readable( dirname( $asset_file ) . '/style-index.css' ) ) {
			wp_enqueue_style(
				self::SCRIPT_HANDLE,
				SHAPED_KIT_URL . 'build/admin/style-index.css',
				array(),
				isset( $asset['version'] ) ? (string) $asset['version'] : SHAPED_KIT_VERSION
			);
			wp_style_add_data( self::SCRIPT_HANDLE, 'rtl', 'replace' );
		}

		// No nonce is handed over. The script depends on `wp-api-fetch`, and WordPress itself gives that
		// the REST nonce for this user and refreshes it when it expires (script-loader.php), so the page
		// holds no credential of its own.
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'shapedKitAdmin',
			array(
				'restUrl'      => esc_url_raw( rest_url( McpController::REST_NAMESPACE . McpController::ROUTE ) ),
				'storeRestUrl' => esc_url_raw( rest_url( McpController::REST_NAMESPACE . '/store' ) ),
				// A string: wp_localize_script() turns every scalar into one (class-wp-scripts.php:651-659), and false would arrive as ''.
				'storeLive'    => \ShapedKit\Store\StoreGate::is_live() ? '1' : '0',
				'version'      => SHAPED_KIT_VERSION,
			)
		);
	}

	/**
	 * Top-level "ShapedPlugin" menu whose first item is "AI & MCP".
	 *
	 * @return void
	 */
	public static function add_menu() {
		self::$hook_suffix = (string) add_menu_page(
			__( 'ShapedPlugin', 'shaped-kit' ),
			__( 'ShapedPlugin', 'shaped-kit' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-rest-api',
			81
		);

		add_submenu_page(
			self::SLUG,
			__( 'AI & MCP', 'shaped-kit' ),
			__( 'AI & MCP', 'shaped-kit' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The mount point. The heading inside it shows until the React app replaces it.
	 *
	 * When the build is missing the app can never load, and a bare heading looks like a broken plugin. This is
	 * the case for a copy installed from GitHub's "Source code" or "Download ZIP" archive, which leaves out the
	 * built dashboard. The notice says so and where the right zip is. It is only printed in that case: with a
	 * build present the app replaces everything inside the mount point.
	 *
	 * @param string|null $asset_file Path of the build's asset file. Only tests pass this.
	 *
	 * @return void
	 */
	public static function render( $asset_file = null ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$asset_file = is_string( $asset_file ) ? $asset_file : SHAPED_KIT_DIR . self::BUILD_ASSET;

		printf(
			'<div class="wrap"><div id="shaped-kit-dashboard"><h1>%s</h1>',
			esc_html__( 'AI & MCP', 'shaped-kit' )
		);

		if ( ! is_readable( $asset_file ) ) {
			// Markup built here is fixed text and two fixed fragments, and wp_admin_notice() runs it through wp_kses_post().
			wp_admin_notice(
				sprintf(
					/* translators: 1: link to the Releases page, 2: a shell command. */
					__( 'The dashboard files are missing from this copy of Shaped Kit. This usually means it was installed from GitHub\'s "Source code" or "Download ZIP" archive, which does not include the built dashboard. Install the zip from the %1$s instead, or run %2$s in the plugin folder.', 'shaped-kit' ),
					'<a href="' . esc_url( self::RELEASES_URL ) . '">' . esc_html__( 'Releases page', 'shaped-kit' ) . '</a>',
					'<code>npm ci &amp;&amp; npm run build</code>'
				),
				array(
					'type'               => 'error',
					'additional_classes' => array( 'inline' ),
				)
			);
		}

		echo '</div></div>';
	}
}
