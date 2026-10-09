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
 * Registers the Kit's admin page. The page is a React mount point; PHP renders nothing else.
 */
final class DashboardPage {

	const SLUG = 'shaped-kit';

	const CAPABILITY = 'manage_options';

	/**
	 * Handle of the React app's script.
	 */
	const SCRIPT_HANDLE = 'shaped-kit-admin';

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
	 * Skipped when the build is missing (a fresh checkout that has not run `npm run build`): the PHP
	 * heading then stays, and a failed build is not hidden behind a script that 404s.
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

		$asset_file = null === $asset_file ? SHAPED_KIT_DIR . 'build/admin/index.asset.php' : $asset_file;

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

		// The REST nonce is for this user and this page load. Nothing else the page needs is secret.
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'shapedKitAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( McpController::REST_NAMESPACE . McpController::ROUTE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'version' => SHAPED_KIT_VERSION,
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
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		printf(
			'<div class="wrap"><div id="shaped-kit-dashboard"><h1>%s</h1></div></div>',
			esc_html__( 'AI & MCP', 'shaped-kit' )
		);
	}
}
