<?php
/**
 * Scheduled data retention cleanup.
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes events/sessions older than the configured retention window.
 */
class RTP_Cron {

	/**
	 * Registers the cron callback.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rtp_retention_cleanup', array( $this, 'run_cleanup' ) );
	}

	/**
	 * Removes data older than `retention_days`.
	 *
	 * Campaign definitions are never auto-deleted, only event/session history.
	 *
	 * @return void
	 */
	public function run_cleanup() {
		global $wpdb;

		$days = (int) RTP_Helpers::get_setting( 'retention_days', 365 );
		if ( $days <= 0 ) {
			return;
		}

		$now      = current_time( 'mysql' );
		$events   = RTP_Database::events_table();
		$sessions = RTP_Database::sessions_table();

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$events} WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$days
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$sessions} WHERE last_seen_at < DATE_SUB(%s, INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$days
			)
		);
	}
}
