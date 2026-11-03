<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package KSTNews_AI_Autopilot
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete options
delete_option( 'kstnews_ai_settings' );
delete_option( 'kstnews_ai_state' );

// Delete transients
delete_transient( 'kstnews_ai_run_lock' );

// Clear any scheduled crons
wp_clear_scheduled_hook( 'kstnews_ai_cron_event' );

// Drop custom activity log table
$table_name = $wpdb->prefix . 'kstnews_ai_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
