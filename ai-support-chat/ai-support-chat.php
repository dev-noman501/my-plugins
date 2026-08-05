<?php
/**
 * Plugin Name: AI Support Chat
 * Description: AI chatbot trained on your own site content (RAG) with human handoff — escalates to support tickets when the AI can't help.
 * Version: 1.3.1
 * Author: Noman Nadeem
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ASC_VERSION', '1.3.1' );
define( 'ASC_PATH', plugin_dir_path( __FILE__ ) );
define( 'ASC_URL', plugin_dir_url( __FILE__ ) );

require_once ASC_PATH . 'includes/class-asc-openai.php';
require_once ASC_PATH . 'includes/class-asc-indexer.php';
require_once ASC_PATH . 'includes/class-asc-rag.php';
require_once ASC_PATH . 'includes/class-asc-rest.php';
require_once ASC_PATH . 'includes/class-asc-tickets.php';
require_once ASC_PATH . 'includes/class-asc-documents.php';
require_once ASC_PATH . 'admin/class-asc-admin.php';

register_activation_hook( __FILE__, 'asc_activate' );
function asc_activate() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE {$wpdb->prefix}asc_chunks (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		post_id BIGINT UNSIGNED NOT NULL,
		chunk_index INT NOT NULL DEFAULT 0,
		content TEXT NOT NULL,
		embedding LONGTEXT NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY post_id (post_id)
	) $charset;" );

	dbDelta( "CREATE TABLE {$wpdb->prefix}asc_messages (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		session_id VARCHAR(64) NOT NULL,
		role VARCHAR(16) NOT NULL,
		message TEXT NOT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY session_id (session_id)
	) $charset;" );
}

add_action( 'plugins_loaded', function () {
	ASC_Indexer::instance();
	new ASC_REST();
	new ASC_Tickets();
	new ASC_Documents();
	if ( is_admin() ) {
		new ASC_Admin();
	}
} );

/**
 * Widget appearance/behaviour config — used by wp_localize_script on the WP
 * site and served over GET /asc/v1/config for Next.js subdomains, so the
 * dashboard settings apply everywhere.
 */
function asc_widget_config() {
	$title = get_option( 'asc_widget_title' );
	$greet = get_option( 'asc_greeting' );
	return array(
		'apiBase'  => esc_url_raw( rest_url( 'asc/v1' ) ),
		'title'    => $title ? $title : get_bloginfo( 'name' ),
		'greeting' => $greet ? $greet : 'Hi! Ask me anything about ' . get_bloginfo( 'name' ) . " — I'll connect you to our team if I can't help.",
		'color'    => get_option( 'asc_color', '#2271b1' ),
		'position' => get_option( 'asc_position', 'right' ),
		'font'     => get_option( 'asc_font', '' ),
	);
}

/**
 * Placement rules from the settings page: entire site, only listed
 * page/post IDs, or everywhere except them.
 */
function asc_should_display() {
	if ( '' === ASC_OpenAI::key( 'ASC_API_KEY', 'asc_api_key' ) ) return false;
	if ( ! get_option( 'asc_widget_enabled', '1' ) ) return false;

	$mode = get_option( 'asc_display_mode', 'all' );
	if ( 'all' === $mode ) return true;

	$raw = (string) get_option( 'asc_display_ids' );
	$ids = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $raw ) ) );

	// Empty list with a specific-pages mode is a misconfiguration — fail open
	// (show everywhere) instead of silently hiding the widget.
	if ( ! $ids && false === stripos( $raw, 'home' ) ) return true;

	$match = in_array( get_queried_object_id(), $ids, true );
	// The blog homepage has no page ID — the keyword "home" targets it.
	if ( ( is_front_page() || is_home() ) && false !== stripos( $raw, 'home' ) ) {
		$match = true;
	}
	return 'include' === $mode ? $match : ! $match;
}

// Widget on the WordPress site itself. Next.js subdomains load the same
// chat.js via a <Script> tag with a data-api attribute instead and fetch
// this same config from GET /asc/v1/config.
add_action( 'wp_enqueue_scripts', function () {
	if ( ! asc_should_display() ) return;
	wp_enqueue_script( 'asc-chat', ASC_URL . 'widget/chat.js', array(), ASC_VERSION, true );
	wp_localize_script( 'asc-chat', 'ASC_CONFIG', asc_widget_config() );
} );
