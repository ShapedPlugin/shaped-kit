<?php
/**
 * The "ShapedPlugin → AI & MCP" admin page.
 *
 * @package ShapedKit
 */

namespace ShapedKit\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Kit's admin page. The page is a React mount point; PHP renders nothing else.
 */
final class DashboardPage {

	const SLUG = 'shaped-kit';

	const CAPABILITY = 'manage_options';

	/**
	 * Hook the menu registration.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
	}

	/**
	 * Top-level "ShapedPlugin" menu whose first item is "AI & MCP".
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_menu_page(
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
