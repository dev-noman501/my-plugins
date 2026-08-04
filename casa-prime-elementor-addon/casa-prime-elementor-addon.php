<?php
/**
 * Plugin Name:       Casa Prime Elementor Addon
 * Plugin URI:        https://app.loomandlure.com/
 * Description:       Custom UI enhancements for Elementor / WooCommerce widgets on the Casa Prime storefront. Keeping them here means theme, kit and Elementor updates never overwrite this work.
 * Version:           1.1.0
 * Author:            Noman Nadeem
 * Author URI:        https://app.loomandlure.com/
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       casa-prime-elementor-addon
 *
 * @package CasaPrimeElementorAddon
 */

defined( 'ABSPATH' ) || exit;

define( 'CPEA_VERSION', '1.1.0' );
define( 'CPEA_FILE', __FILE__ );
define( 'CPEA_PATH', plugin_dir_path( __FILE__ ) );
define( 'CPEA_URL', plugin_dir_url( __FILE__ ) );

/**
 * Every stylesheet this plugin ships.
 *
 * handle => relative path under assets/css/
 */
function cpea_stylesheets() {
	return array(
		'cpea-widgets' => 'widgets.css',
	);
}

/**
 * Cache-bust on the file's own mtime so edits show up immediately,
 * falling back to the plugin version if the file is unreadable.
 *
 * @param string $rel Path relative to assets/css/.
 * @return string
 */
function cpea_asset_version( $rel ) {
	$path = CPEA_PATH . 'assets/css/' . $rel;
	$time = file_exists( $path ) ? filemtime( $path ) : 0;
	return $time ? CPEA_VERSION . '.' . $time : CPEA_VERSION;
}

/**
 * Register + enqueue on the front end.
 */
function cpea_enqueue_front() {
	foreach ( cpea_stylesheets() as $handle => $rel ) {
		wp_enqueue_style(
			$handle,
			CPEA_URL . 'assets/css/' . $rel,
			array(),
			cpea_asset_version( $rel )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'cpea_enqueue_front', 20 );

/**
 * Same styles inside the Elementor editor preview, so the canvas matches the
 * live page while you are building.
 */
function cpea_enqueue_preview() {
	cpea_enqueue_front();
}
add_action( 'elementor/preview/enqueue_styles', 'cpea_enqueue_preview' );

/**
 * Nudge the admin if Elementor is missing — the styles target its widgets.
 */
function cpea_admin_notice() {
	if ( did_action( 'elementor/loaded' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>Casa Prime Elementor Addon</strong> — Elementor is not active, so these widget styles will not apply.</p></div>';
}
add_action( 'admin_notices', 'cpea_admin_notice' );
