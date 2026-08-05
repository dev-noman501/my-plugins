<?php
/**
 * Uninstall handler.
 *
 * Removes plugin data only when the admin explicitly enabled
 * "Delete data on uninstall" in the settings.
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$rtp_settings = get_option( 'rtp_settings', array() );

if ( empty( $rtp_settings['delete_on_uninstall'] ) ) {
	// Admin chose to keep data. Do nothing.
	return;
}

global $wpdb;

$rtp_tables = array(
	$wpdb->prefix . 'rtp_events',
	$wpdb->prefix . 'rtp_sessions',
	$wpdb->prefix . 'rtp_campaigns',
);

foreach ( $rtp_tables as $rtp_table ) {
	// Table names cannot be parameterised; they are built from a trusted prefix + constant suffix.
	$wpdb->query( "DROP TABLE IF EXISTS {$rtp_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

delete_option( 'rtp_settings' );
delete_option( 'rtp_db_version' );

wp_clear_scheduled_hook( 'rtp_retention_cleanup' );
