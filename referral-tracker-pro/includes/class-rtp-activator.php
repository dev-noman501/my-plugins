<?php
/**
 * Activation routine.
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on plugin activation: tables, default options, cron.
 */
class RTP_Activator {

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		RTP_Database::install();

		if ( false === get_option( 'rtp_settings', false ) ) {
			// Store the documented defaults explicitly on first activation.
			update_option( 'rtp_settings', RTP_Helpers::get_settings() );
		}

		if ( ! wp_next_scheduled( 'rtp_retention_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'rtp_retention_cleanup' );
		}
	}
}
