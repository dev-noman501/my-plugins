<?php
/**
 * Plugin Name:       Referral Tracker Pro
 * Plugin URI:        https://github.com/dev-noman501/my-plugins
 * Description:       Referral tracking & analytics: visits, tel: call clicks and form submissions attributed to referral links, with a non-technical admin analytics dashboard.
 * Version:           1.3.5
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Noman Nadeem
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       referral-tracker-pro
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/*
 * -------------------------------------------------------------------------
 * Constants
 * -------------------------------------------------------------------------
 */
define( 'RTP_VERSION', '1.3.5' );
define( 'RTP_DB_VERSION', '1.2.0' );
define( 'RTP_PLUGIN_FILE', __FILE__ );
define( 'RTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RTP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'RTP_COOKIE_NAME', 'rtp_ref' );

/*
 * -------------------------------------------------------------------------
 * Includes
 * -------------------------------------------------------------------------
 */
require_once RTP_PLUGIN_DIR . 'includes/class-rtp-helpers.php';
require_once RTP_PLUGIN_DIR . 'includes/class-rtp-database.php';
require_once RTP_PLUGIN_DIR . 'includes/class-rtp-settings.php';
require_once RTP_PLUGIN_DIR . 'includes/class-rtp-activator.php';
require_once RTP_PLUGIN_DIR . 'includes/class-rtp-deactivator.php';
require_once RTP_PLUGIN_DIR . 'includes/class-rtp-tracker.php';
require_once RTP_PLUGIN_DIR . 'includes/class-rtp-form-integrations.php';
require_once RTP_PLUGIN_DIR . 'includes/class-rtp-cron.php';
require_once RTP_PLUGIN_DIR . 'includes/class-rtp-callrail-sync.php';
require_once RTP_PLUGIN_DIR . 'includes/class-rtp-callrail.php';

if ( is_admin() ) {
	require_once RTP_PLUGIN_DIR . 'admin/class-rtp-analytics.php';
	require_once RTP_PLUGIN_DIR . 'admin/class-rtp-admin.php';
}

/*
 * -------------------------------------------------------------------------
 * Activation / Deactivation
 * -------------------------------------------------------------------------
 */
register_activation_hook( __FILE__, array( 'RTP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RTP_Deactivator', 'deactivate' ) );

/**
 * Bootstraps the plugin once all plugins are loaded.
 *
 * @return void
 */
function rtp_bootstrap() {
	load_plugin_textdomain( 'referral-tracker-pro', false, dirname( RTP_PLUGIN_BASENAME ) . '/languages' );

	// Run a lightweight DB upgrade check on load (cheap; option compare only).
	RTP_Database::maybe_upgrade();

	// Front-end + REST tracking.
	$tracker = new RTP_Tracker();
	$tracker->init();

	// Form plugin integrations (server-side, cookie based).
	$forms = new RTP_Form_Integrations();
	$forms->init();

	// Scheduled retention cleanup.
	$cron = new RTP_Cron();
	$cron->init();

	// CallRail integration (webhook + polling).
	$callrail = new RTP_CallRail();
	$callrail->init();

	// Admin UI.
	if ( is_admin() ) {
		$admin = new RTP_Admin();
		$admin->init();
	}
}
add_action( 'plugins_loaded', 'rtp_bootstrap' );
