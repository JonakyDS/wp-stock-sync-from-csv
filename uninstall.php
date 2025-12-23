<?php
/**
 * Uninstall WP Stock Sync From CSV
 *
 * @package WP_Stock_Sync_From_CSV
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Delete options
delete_option( 'wssfc_settings' );
delete_option( 'wssfc_last_run' );

// Clear scheduled cron events
wp_clear_scheduled_hook( 'wssfc_sync_event' );

// Drop custom table
global $wpdb;
$table_name = $wpdb->prefix . 'wssfc_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// Delete any transients
delete_transient( 'wssfc_sync_running' );
