<?php
/**
 * Deactivation routine.
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on plugin deactivation. Data is preserved; only scheduled work stops.
 */
class RTP_Deactivator {

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'rtp_retention_cleanup' );

		if ( class_exists( 'RTP_CallRail' ) ) {
			RTP_CallRail::unschedule();
		}
	}
}
