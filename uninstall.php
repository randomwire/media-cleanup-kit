<?php
/**
 * Image Kit — Uninstall handler.
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Cleans up all database tables, options, transients, user meta, and cron events.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}image_kit_upgrader_run_items" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}image_kit_upgrader_runs" );

// Delete all options with our prefix.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'image\_kit\_%'"
);

// Delete all transients with our prefix.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_image\_kit\_%' OR option_name LIKE '_transient_timeout_image\_kit\_%'"
);

// Delete user meta with our prefix.
$wpdb->query(
	"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'image\_kit\_%'"
);

// Unschedule any cron events.
$cron_hooks = array(
	'image_kit_upgrader_cleanup',
);
foreach ( $cron_hooks as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
	}
}
