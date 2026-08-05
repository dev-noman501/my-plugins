<?php
/**
 * Database schema and low-level data access.
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles table creation (dbDelta) and core CRUD operations.
 */
class RTP_Database {

	/**
	 * Campaigns table name.
	 *
	 * @return string
	 */
	public static function campaigns_table() {
		global $wpdb;
		return $wpdb->prefix . 'rtp_campaigns';
	}

	/**
	 * Sessions table name.
	 *
	 * @return string
	 */
	public static function sessions_table() {
		global $wpdb;
		return $wpdb->prefix . 'rtp_sessions';
	}

	/**
	 * Events table name.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'rtp_events';
	}

	/**
	 * Creates / updates all tables via dbDelta.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$campaigns       = self::campaigns_table();
		$sessions        = self::sessions_table();
		$events          = self::events_table();

		$sql_campaigns = "CREATE TABLE {$campaigns} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			code VARCHAR(64) NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'general',
			target_url VARCHAR(512) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY status (status)
		) {$charset_collate};";

		$sql_sessions = "CREATE TABLE {$sessions} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(64) NOT NULL,
			campaign_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			referral_code VARCHAR(64) NOT NULL,
			referral_type VARCHAR(20) NOT NULL DEFAULT 'general',
			landing_page VARCHAR(512) NOT NULL DEFAULT '',
			referrer_url VARCHAR(512) NOT NULL DEFAULT '',
			utm LONGTEXT NULL,
			ip_store VARCHAR(191) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			device VARCHAR(20) NOT NULL DEFAULT '',
			browser VARCHAR(30) NOT NULL DEFAULT '',
			os VARCHAR(30) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY campaign_id (campaign_id),
			KEY referral_code (referral_code),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_events = "CREATE TABLE {$events} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(64) NOT NULL,
			campaign_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			referral_code VARCHAR(64) NOT NULL,
			event_type VARCHAR(20) NOT NULL,
			event_page VARCHAR(512) NOT NULL DEFAULT '',
			landing_page VARCHAR(512) NOT NULL DEFAULT '',
			phone_number VARCHAR(40) NOT NULL DEFAULT '',
			form_id VARCHAR(100) NOT NULL DEFAULT '',
			form_name VARCHAR(191) NOT NULL DEFAULT '',
			form_type VARCHAR(40) NOT NULL DEFAULT '',
			lead_name VARCHAR(191) NOT NULL DEFAULT '',
			lead_email VARCHAR(191) NOT NULL DEFAULT '',
			lead_phone VARCHAR(40) NOT NULL DEFAULT '',
			lead_amount DECIMAL(10,2) NULL DEFAULT NULL,
			device VARCHAR(20) NOT NULL DEFAULT '',
			browser VARCHAR(30) NOT NULL DEFAULT '',
			os VARCHAR(30) NOT NULL DEFAULT '',
			extra LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY campaign_id (campaign_id),
			KEY referral_code (referral_code),
			KEY event_type (event_type),
			KEY created_at (created_at),
			KEY lead_email (lead_email)
		) {$charset_collate};";

		dbDelta( $sql_campaigns );
		dbDelta( $sql_sessions );
		dbDelta( $sql_events );

		// Backfill lead_* columns for older form events captured before v1.1.0.
		self::backfill_lead_columns();

		// v1.2.0+ — dedicated table for CallRail-verified call records.
		if ( class_exists( 'RTP_CallRail_Sync' ) ) {
			RTP_CallRail_Sync::install();
		}

		update_option( 'rtp_db_version', RTP_DB_VERSION );
	}

	/**
	 * One-time backfill: parses `extra` JSON on existing form events and
	 * populates the new lead_name / lead_email / lead_phone / lead_amount
	 * columns. Idempotent (only updates rows where the new columns are blank).
	 *
	 * @return void
	 */
	public static function backfill_lead_columns() {
		global $wpdb;
		$table = self::events_table();

		$rows = $wpdb->get_results(
			"SELECT id, extra FROM {$table}
			WHERE event_type = 'form'
			AND ( lead_email = '' OR lead_email IS NULL )
			AND extra IS NOT NULL AND extra <> ''
			LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$data = json_decode( $row['extra'], true );
			if ( ! is_array( $data ) || empty( $data['fields'] ) ) {
				continue;
			}
			$lead = RTP_Helpers::extract_lead_fields( $data['fields'] );
			$wpdb->update(
				$table,
				array(
					'lead_name'   => $lead['name'],
					'lead_email'  => $lead['email'],
					'lead_phone'  => $lead['phone'],
					'lead_amount' => $lead['amount'],
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s', '%s', '%f' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Runs install() again when the stored DB version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'rtp_db_version' ) !== RTP_DB_VERSION ) {
			self::install();
		}
	}

	/* ---------------------------------------------------------------------
	 * Campaigns
	 * ------------------------------------------------------------------- */

	/**
	 * Fetches an active campaign row by code.
	 *
	 * @param string $code Referral code.
	 * @return object|null
	 */
	public static function get_active_campaign_by_code( $code ) {
		global $wpdb;
		$table = self::campaigns_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE code = %s AND status = 'active' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$code
			)
		);
	}

	/**
	 * Fetches a campaign row by id.
	 *
	 * @param int $id Campaign id.
	 * @return object|null
	 */
	public static function get_campaign( $id ) {
		global $wpdb;
		$table = self::campaigns_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);
	}

	/**
	 * Checks whether a code already exists (optionally excluding an id).
	 *
	 * @param string $code       Referral code.
	 * @param int    $exclude_id Campaign id to ignore.
	 * @return bool
	 */
	public static function code_exists( $code, $exclude_id = 0 ) {
		global $wpdb;
		$table = self::campaigns_table();

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE code = %s AND id != %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$code,
				$exclude_id
			)
		);

		return (int) $found > 0;
	}

	/**
	 * Inserts or updates a campaign.
	 *
	 * @param array $data Sanitized campaign data.
	 * @param int   $id   Existing id (0 to insert).
	 * @return int|false Campaign id on success, false on failure.
	 */
	public static function save_campaign( $data, $id = 0 ) {
		global $wpdb;
		$table = self::campaigns_table();
		$now   = current_time( 'mysql' );

		$row = array(
			'name'       => $data['name'],
			'code'       => $data['code'],
			'type'       => $data['type'],
			'target_url' => $data['target_url'],
			'status'     => $data['status'],
			'notes'      => $data['notes'],
			'updated_at' => $now,
		);

		if ( $id > 0 ) {
			$ok = $wpdb->update( $table, $row, array( 'id' => $id ), null, array( '%d' ) );
			return ( false === $ok ) ? false : $id;
		}

		$row['created_at'] = $now;
		$ok                = $wpdb->insert( $table, $row );

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Deletes a campaign (its events/sessions are retained for history).
	 *
	 * @param int $id Campaign id.
	 * @return bool
	 */
	public static function delete_campaign( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::campaigns_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/* ---------------------------------------------------------------------
	 * Sessions & events
	 * ------------------------------------------------------------------- */

	/**
	 * Returns an existing session row by client session id.
	 *
	 * @param string $session_id Client session id.
	 * @return object|null
	 */
	public static function get_session( $session_id ) {
		global $wpdb;
		$table = self::sessions_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$session_id
			)
		);
	}

	/**
	 * Creates a session if it does not exist yet (first-touch).
	 *
	 * @param array $data Sanitized session data.
	 * @return void
	 */
	public static function ensure_session( $data ) {
		global $wpdb;
		$table   = self::sessions_table();
		$now     = current_time( 'mysql' );
		$existing = self::get_session( $data['session_id'] );

		if ( $existing ) {
			// Keep first-touch data, only refresh last activity.
			$wpdb->update(
				$table,
				array( 'last_seen_at' => $now ),
				array( 'session_id' => $data['session_id'] ),
				array( '%s' ),
				array( '%s' )
			);
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'session_id'    => $data['session_id'],
				'campaign_id'   => $data['campaign_id'],
				'referral_code' => $data['referral_code'],
				'referral_type' => $data['referral_type'],
				'landing_page'  => $data['landing_page'],
				'referrer_url'  => $data['referrer_url'],
				'utm'           => $data['utm'],
				'ip_store'      => $data['ip_store'],
				'user_agent'    => $data['user_agent'],
				'device'        => $data['device'],
				'browser'       => $data['browser'],
				'os'            => $data['os'],
				'created_at'    => $now,
				'last_seen_at'  => $now,
			)
		);
	}

	/**
	 * Checks whether a same-type event for this session already exists
	 * within the given window. Used to de-duplicate the JS + server-side
	 * capture of the same call/form action (keeps analytics trustworthy).
	 *
	 * @param string $session_id Session id.
	 * @param string $type       Event type.
	 * @param int    $seconds    Look-back window in seconds.
	 * @return bool
	 */
	public static function recent_event_exists( $session_id, $type, $seconds = 20 ) {
		global $wpdb;
		$table = self::events_table();

		// Compare against the same clock that wrote created_at (site local),
		// using DATE_SUB so this is timezone-safe.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE session_id = %s AND event_type = %s
				AND created_at >= DATE_SUB(%s, INTERVAL %d SECOND)
				LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$session_id,
				$type,
				current_time( 'mysql' ),
				absint( $seconds )
			)
		);

		return ! empty( $found );
	}

	/**
	 * Inserts a tracking event.
	 *
	 * @param array $data Sanitized event data.
	 * @return int|false Inserted id or false.
	 */
	public static function insert_event( $data ) {
		global $wpdb;
		$table = self::events_table();

		$ok = $wpdb->insert(
			$table,
			array(
				'session_id'    => $data['session_id'],
				'campaign_id'   => $data['campaign_id'],
				'referral_code' => $data['referral_code'],
				'event_type'    => $data['event_type'],
				'event_page'    => $data['event_page'],
				'landing_page'  => $data['landing_page'],
				'phone_number'  => $data['phone_number'],
				'form_id'       => $data['form_id'],
				'form_name'     => $data['form_name'],
				'form_type'     => $data['form_type'],
				'lead_name'     => isset( $data['lead_name'] ) ? $data['lead_name'] : '',
				'lead_email'    => isset( $data['lead_email'] ) ? $data['lead_email'] : '',
				'lead_phone'    => isset( $data['lead_phone'] ) ? $data['lead_phone'] : '',
				'lead_amount'   => isset( $data['lead_amount'] ) ? $data['lead_amount'] : null,
				'device'        => $data['device'],
				'browser'       => $data['browser'],
				'os'            => $data['os'],
				'extra'         => $data['extra'],
				'created_at'    => current_time( 'mysql' ),
			)
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}
}
