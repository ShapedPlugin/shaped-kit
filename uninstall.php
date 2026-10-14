<?php
/**
 * Fired when Shaped Kit is deleted via the WordPress admin.
 *
 * Implements PRD FR-K8 & §19 E37 (multisite clean uninstall):
 * - Drops {$wpdb->prefix}shaped_kit_mcp_log across all network sites on multisite installations.
 * - Deletes Shaped Kit internal options and schema version records.
 * - Clears scheduled cron events.
 * - NEVER deletes or alters product-owned options or third-party settings (C5, FR-K8).
 *
 * Verified sources:
 * - WP_UNINSTALL_PLUGIN in wp-admin/includes/plugin.php:1274
 * - is_multisite() in wp-includes/load.php:1189
 * - get_sites() in wp-includes/ms-site.php:445
 * - switch_to_blog() / restore_current_blog() in wp-includes/ms-blogs.php:479
 * - LogTable::drop() in src/Audit/LogTable.php:148
 * - LogPruner::unregister() in src/Audit/LogPruner.php:70
 *
 * @package ShapedKit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Autoload Shaped Kit classes.
require_once __DIR__ . '/shaped-kit.php';

// 1. Unschedule cron event.
\ShapedKit\Audit\LogPruner::unregister();

// 2. Multisite vs Single-Site clean removal.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );

	if ( is_array( $site_ids ) ) {
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			\ShapedKit\Audit\LogTable::drop();
			delete_option( 'shaped_kit_mcp_log_db_version' );
			delete_transient( 'shaped_kit_mcp_log_prune_throttle' );
			delete_option( \ShapedKit\Store\CatalogClient::OPTION_LAST_SERIAL );
			delete_transient( \ShapedKit\Store\CatalogClient::TRANSIENT_CATALOG_CACHE );
			delete_transient( \ShapedKit\Store\CatalogClient::TRANSIENT_THROTTLE );
			restore_current_blog();
		}
	}
	delete_site_transient( \ShapedKit\Store\CatalogClient::TRANSIENT_CATALOG_CACHE );
	delete_site_transient( \ShapedKit\Store\CatalogClient::TRANSIENT_THROTTLE );
} else {
	\ShapedKit\Audit\LogTable::drop();
	delete_option( 'shaped_kit_mcp_log_db_version' );
	delete_transient( 'shaped_kit_mcp_log_prune_throttle' );
	delete_option( \ShapedKit\Store\CatalogClient::OPTION_LAST_SERIAL );
	delete_transient( \ShapedKit\Store\CatalogClient::TRANSIENT_CATALOG_CACHE );
	delete_transient( \ShapedKit\Store\CatalogClient::TRANSIENT_THROTTLE );
}
