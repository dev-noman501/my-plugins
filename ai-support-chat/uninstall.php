<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}asc_chunks" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}asc_messages" );

foreach ( get_posts( array( 'post_type' => 'asc_document', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $asc_doc_id ) {
	wp_delete_post( $asc_doc_id, true );
}

foreach ( array( 'asc_provider', 'asc_api_key', 'asc_embed_provider', 'asc_embed_api_key', 'asc_model', 'asc_notify_email', 'asc_notify_emails', 'asc_allowed_origins', 'asc_reindex_queue', 'asc_widget_enabled', 'asc_widget_title', 'asc_greeting', 'asc_color', 'asc_position', 'asc_font', 'asc_display_mode', 'asc_display_ids' ) as $option ) {
	delete_option( $option );
}
