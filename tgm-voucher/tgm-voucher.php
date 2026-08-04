<?php
/**
 * Plugin Name: TGM Voucher
 * Description: Travel voucher generator — multi-step form, voucher list, QR code, print-ready A4 voucher with Approved/Unapproved watermark.
 * Version: 1.0.0
 * Author: Noman Nadeem
 * Text Domain: tgm-voucher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TGMV_VERSION', '1.0.0' );
define( 'TGMV_FILE', __FILE__ );
define( 'TGMV_DIR', plugin_dir_path( __FILE__ ) );
define( 'TGMV_URL', plugin_dir_url( __FILE__ ) );

require_once TGMV_DIR . 'includes/class-tgmv-data.php';
require_once TGMV_DIR . 'includes/class-tgmv-settings.php';
require_once TGMV_DIR . 'includes/class-tgmv-frontend.php';

if ( is_admin() ) {
	require_once TGMV_DIR . 'includes/class-tgmv-admin.php';
}

/**
 * Register the internal post type + rewrite rule.
 */
function tgmv_register() {
	register_post_type(
		'tgm_voucher',
		array(
			'labels'          => array( 'name' => 'Vouchers', 'singular_name' => 'Voucher' ),
			'public'          => false,
			'show_ui'         => false,
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
		)
	);

	add_rewrite_rule( '^voucher/([0-9a-fA-F\-]{36})/?$', 'index.php?tgmv_uuid=$matches[1]', 'top' );
}
add_action( 'init', 'tgmv_register' );

function tgmv_query_vars( $vars ) {
	$vars[] = 'tgmv_uuid';
	return $vars;
}
add_filter( 'query_vars', 'tgmv_query_vars' );

/**
 * Activation: register + flush rewrites, seed defaults.
 */
function tgmv_activate() {
	tgmv_register();
	flush_rewrite_rules();

	if ( false === get_option( 'tgmv_settings' ) ) {
		add_option( 'tgmv_settings', TGMV_Settings::defaults() );
	}
	if ( false === get_option( 'tgmv_next_number' ) ) {
		add_option( 'tgmv_next_number', 100001 );
	}
	if ( false === get_option( 'tgmv_suggestions' ) ) {
		add_option( 'tgmv_suggestions', array() );
	}
}
register_activation_hook( __FILE__, 'tgmv_activate' );

function tgmv_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'tgmv_deactivate' );
