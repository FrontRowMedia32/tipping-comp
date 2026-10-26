<?php
/**
 * Removes data on uninstall, only if the site owner opted in.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$tipc_settings = get_option( 'tipc_settings' );
wp_clear_scheduled_hook( 'tipc_tick' );

if ( is_array( $tipc_settings ) && ! empty( $tipc_settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tipc_tips" ); // phpcs:ignore
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tipc_games" ); // phpcs:ignore
	foreach ( array( 'tipc_settings', 'tipc_db_version', 'tipc_sync_state', 'tipc_sync_log' ) as $tipc_option ) {
		delete_option( $tipc_option );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tipc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_tipc\\_%'" ); // phpcs:ignore
}
