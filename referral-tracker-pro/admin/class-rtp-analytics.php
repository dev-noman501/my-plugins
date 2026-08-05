<?php
/**
 * Analytics query engine.
 *
 * All methods return already-aggregated, safe-to-render data. Every query
 * is built with $wpdb->prepare(); table names come from trusted constants.
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reporting / analytics queries.
 */
class RTP_Analytics {

	/**
	 * Normalises incoming filter input.
	 *
	 * @param array $raw Raw filter args.
	 * @return array{from:string,to:string,campaign_id:int}
	 */
	public static function normalize_filters( $raw ) {
		$from = isset( $raw['from'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $raw['from'] ) : '';
		$to   = isset( $raw['to'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $raw['to'] ) : '';

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$from = gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$to = gmdate( 'Y-m-d' );
		}

		return array(
			'from'        => $from,
			'to'          => $to,
			'campaign_id' => isset( $raw['campaign_id'] ) ? absint( $raw['campaign_id'] ) : 0,
		);
	}

	/**
	 * Builds a reusable WHERE clause + params for the events table.
	 *
	 * @param array $f Normalised filters.
	 * @return array{0:string,1:array}
	 */
	private static function where( $f ) {
		// Columns are qualified with the `e` alias because several queries
		// JOIN the campaigns table, which also has created_at/campaign_id.
		$clauses = array( 'e.created_at BETWEEN %s AND %s' );
		$params  = array( $f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59' );

		if ( ! empty( $f['campaign_id'] ) ) {
			$clauses[] = 'e.campaign_id = %d';
			$params[]  = $f['campaign_id'];
		}

		return array( implode( ' AND ', $clauses ), $params );
	}

	/**
	 * High-level summary counters.
	 *
	 * @param array $f Normalised filters.
	 * @return array
	 */
	public static function get_summary( $f ) {
		global $wpdb;
		$events = RTP_Database::events_table();
		list( $where, $params ) = self::where( $f );

		$sql = $wpdb->prepare(
			"SELECT
				SUM(CASE WHEN event_type = 'visit' THEN 1 ELSE 0 END) AS visits,
				SUM(CASE WHEN event_type = 'call'  THEN 1 ELSE 0 END) AS calls,
				SUM(CASE WHEN event_type = 'form'  THEN 1 ELSE 0 END) AS forms,
				COUNT(DISTINCT session_id) AS sessions
			FROM {$events} e WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params
		);

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $row ? $row : array();

		$visits = isset( $row['visits'] ) ? (int) $row['visits'] : 0;
		$calls  = isset( $row['calls'] ) ? (int) $row['calls'] : 0;
		$forms  = isset( $row['forms'] ) ? (int) $row['forms'] : 0;

		$conversions = $calls + $forms;
		$rate        = $visits > 0 ? round( ( $conversions / $visits ) * 100, 1 ) : 0.0;

		return array(
			'visits'      => $visits,
			'calls'       => $calls,
			'forms'       => $forms,
			'sessions'    => isset( $row['sessions'] ) ? (int) $row['sessions'] : 0,
			'conversions' => $conversions,
			'rate'        => $rate,
		);
	}

	/**
	 * Top campaigns by visit count, with calls/forms.
	 *
	 * @param array $f     Normalised filters.
	 * @param int   $limit Row limit.
	 * @return array
	 */
	public static function get_top_campaigns( $f, $limit = 10 ) {
		global $wpdb;
		$events    = RTP_Database::events_table();
		$campaigns = RTP_Database::campaigns_table();
		list( $where, $params ) = self::where( $f );
		$params[] = absint( $limit );

		$sql = $wpdb->prepare(
			"SELECT e.campaign_id, e.referral_code,
				COALESCE(c.name, e.referral_code) AS name,
				SUM(CASE WHEN e.event_type='visit' THEN 1 ELSE 0 END) AS visits,
				SUM(CASE WHEN e.event_type='call'  THEN 1 ELSE 0 END) AS calls,
				SUM(CASE WHEN e.event_type='form'  THEN 1 ELSE 0 END) AS forms
			FROM {$events} e
			LEFT JOIN {$campaigns} c ON c.id = e.campaign_id
			WHERE {$where}
			GROUP BY e.campaign_id, e.referral_code, name
			ORDER BY visits DESC, calls DESC, forms DESC
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params
		);

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Top pages for a given event type, grouped by a page column.
	 *
	 * @param array  $f      Normalised filters.
	 * @param string $type   Event type (visit|call|form).
	 * @param string $column Column to group by (event_page|landing_page).
	 * @param int    $limit  Row limit.
	 * @return array
	 */
	public static function get_top_pages( $f, $type, $column, $limit = 10 ) {
		global $wpdb;
		$events = RTP_Database::events_table();

		$column = in_array( $column, array( 'event_page', 'landing_page' ), true ) ? $column : 'event_page';
		$type   = in_array( $type, array( 'visit', 'call', 'form' ), true ) ? $type : 'visit';

		list( $where, $params ) = self::where( $f );
		array_unshift( $params, $type );
		$params[] = absint( $limit );

		$sql = $wpdb->prepare(
			"SELECT {$column} AS page, COUNT(*) AS total
			FROM {$events} e
			WHERE e.event_type = %s AND {$where} AND {$column} <> ''
			GROUP BY page
			ORDER BY total DESC
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params
		);

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Detailed events list with pagination.
	 *
	 * @param array $f        Normalised filters.
	 * @param int   $paged    Current page (1-based).
	 * @param int   $per_page Rows per page.
	 * @param string $type    Optional event type filter.
	 * @return array{rows:array,total:int}
	 */
	public static function get_events( $f, $paged = 1, $per_page = 25, $type = '' ) {
		global $wpdb;
		$events    = RTP_Database::events_table();
		$campaigns = RTP_Database::campaigns_table();

		list( $where, $params ) = self::where( $f );

		if ( in_array( $type, array( 'visit', 'call', 'form' ), true ) ) {
			$where   .= ' AND e.event_type = %s';
			$params[] = $type;
		}

		// Total.
		$count_sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$events} e WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params
		);
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$paged    = max( 1, absint( $paged ) );
		$per_page = max( 1, min( 200, absint( $per_page ) ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$page_params   = $params;
		$page_params[] = $per_page;
		$page_params[] = $offset;

		$sql = $wpdb->prepare(
			"SELECT e.*, COALESCE(c.name, e.referral_code) AS campaign_name
			FROM {$events} e
			LEFT JOIN {$campaigns} c ON c.id = e.campaign_id
			WHERE {$where}
			ORDER BY e.created_at DESC
			LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$page_params
		);

		return array(
			'rows'  => $wpdb->get_results( $sql, ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'total' => $total,
		);
	}

	/**
	 * All rows matching filters, for CSV export (capped for safety).
	 *
	 * @param array $f Normalised filters.
	 * @return array
	 */
	public static function get_export_rows( $f ) {
		global $wpdb;
		$events    = RTP_Database::events_table();
		$campaigns = RTP_Database::campaigns_table();
		list( $where, $params ) = self::where( $f );
		$params[] = 50000; // Hard export cap.

		$sql = $wpdb->prepare(
			"SELECT e.created_at, COALESCE(c.name, e.referral_code) AS campaign_name,
				e.referral_code, e.session_id, e.event_type, e.landing_page, e.event_page,
				e.phone_number, e.form_name, e.form_type,
				e.lead_name, e.lead_email, e.lead_phone, e.lead_amount,
				e.device, e.browser, e.os
			FROM {$events} e
			LEFT JOIN {$campaigns} c ON c.id = e.campaign_id
			WHERE {$where}
			ORDER BY e.created_at DESC
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params
		);

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ---------------------------------------------------------------------
	 * Leads (form submissions with contact identity)
	 * ------------------------------------------------------------------- */

	/**
	 * Paginated leads list (form submissions only).
	 *
	 * @param array  $f        Normalised filters.
	 * @param int    $paged    Current page.
	 * @param int    $per_page Rows per page.
	 * @param string $search   Free-text search across name/email/phone.
	 * @return array{rows:array,total:int}
	 */
	public static function get_leads( $f, $paged = 1, $per_page = 25, $search = '' ) {
		global $wpdb;
		$events    = RTP_Database::events_table();
		$campaigns = RTP_Database::campaigns_table();

		list( $where, $params ) = self::where( $f );
		$where    .= " AND e.event_type = 'form'";
		$search    = trim( (string) $search );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( e.lead_name LIKE %s OR e.lead_email LIKE %s OR e.lead_phone LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$count_sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$events} e WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params
		);
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$paged    = max( 1, absint( $paged ) );
		$per_page = max( 1, min( 200, absint( $per_page ) ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$page_params   = $params;
		$page_params[] = $per_page;
		$page_params[] = $offset;

		$sql = $wpdb->prepare(
			"SELECT e.id, e.created_at, e.referral_code, e.campaign_id,
				e.lead_name, e.lead_email, e.lead_phone, e.lead_amount,
				e.event_page, e.form_id, e.form_name, e.form_type,
				COALESCE(c.name, e.referral_code) AS campaign_name
			FROM {$events} e
			LEFT JOIN {$campaigns} c ON c.id = e.campaign_id
			WHERE {$where}
			ORDER BY e.created_at DESC
			LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$page_params
		);

		return array(
			'rows'  => $wpdb->get_results( $sql, ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'total' => $total,
		);
	}

	/**
	 * Lead rows for CSV export (capped).
	 *
	 * @param array  $f      Normalised filters.
	 * @param string $search Free-text search.
	 * @return array
	 */
	public static function get_leads_export( $f, $search = '' ) {
		global $wpdb;
		$events    = RTP_Database::events_table();
		$campaigns = RTP_Database::campaigns_table();

		list( $where, $params ) = self::where( $f );
		$where .= " AND e.event_type = 'form'";
		$search = trim( (string) $search );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( e.lead_name LIKE %s OR e.lead_email LIKE %s OR e.lead_phone LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$params[] = 50000;

		$sql = $wpdb->prepare(
			"SELECT e.created_at, e.referral_code,
				e.lead_name, e.lead_email, e.lead_phone, e.lead_amount,
				e.event_page, e.form_id, e.form_name,
				COALESCE(c.name, e.referral_code) AS campaign_name
			FROM {$events} e
			LEFT JOIN {$campaigns} c ON c.id = e.campaign_id
			WHERE {$where}
			ORDER BY e.created_at DESC
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params
		);

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ---------------------------------------------------------------------
	 * Calls (CallRail-attributed call events with caller identity)
	 * ------------------------------------------------------------------- */

	/**
	 * Paginated call events with the CallRail metadata decoded for display.
	 *
	 * @param array  $f        Normalised filters.
	 * @param int    $paged    Current page.
	 * @param int    $per_page Rows per page.
	 * @param string $search   Free-text search across caller name/phone.
	 * @return array{rows:array,total:int}
	 */
	public static function get_calls( $f, $paged = 1, $per_page = 25, $search = '' ) {
		global $wpdb;
		$events    = RTP_Database::events_table();
		$campaigns = RTP_Database::campaigns_table();

		list( $where, $params ) = self::where( $f );
		// Hide 0-second tel:click attempts (form_type empty) — only CallRail-verified
		// rows kept here for backward compatibility. New verified data lives in the
		// dedicated wp_rtp_callrail_calls table (Referrals → CallRail submenu).
		$where .= " AND e.event_type = 'call' AND e.form_type = 'callrail'";

		$search = trim( (string) $search );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( e.lead_name LIKE %s OR e.lead_phone LIKE %s OR e.phone_number LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$count_sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$events} e WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params
		);
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$paged    = max( 1, absint( $paged ) );
		$per_page = max( 1, min( 200, absint( $per_page ) ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$page_params   = $params;
		$page_params[] = $per_page;
		$page_params[] = $offset;

		$sql = $wpdb->prepare(
			"SELECT e.id, e.created_at, e.referral_code, e.campaign_id,
				e.lead_name, e.lead_phone, e.phone_number,
				e.event_page, e.form_type, e.extra,
				COALESCE(c.name, e.referral_code) AS campaign_name
			FROM {$events} e
			LEFT JOIN {$campaigns} c ON c.id = e.campaign_id
			WHERE {$where}
			ORDER BY e.created_at DESC
			LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$page_params
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Decode the CallRail blob once so the view stays simple.
		if ( is_array( $rows ) ) {
			foreach ( $rows as &$r ) {
				$r['callrail'] = class_exists( 'RTP_CallRail' )
					? RTP_CallRail::decode_extra( isset( $r['extra'] ) ? $r['extra'] : '' )
					: array();
			}
			unset( $r );
		}

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Call rows for CSV export (capped).
	 *
	 * @param array  $f      Normalised filters.
	 * @param string $search Free-text search.
	 * @return array
	 */
	public static function get_calls_export( $f, $search = '' ) {
		global $wpdb;
		$events    = RTP_Database::events_table();
		$campaigns = RTP_Database::campaigns_table();

		list( $where, $params ) = self::where( $f );
		$where .= " AND e.event_type = 'call' AND e.form_type = 'callrail'";

		$search = trim( (string) $search );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( e.lead_name LIKE %s OR e.lead_phone LIKE %s OR e.phone_number LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$params[] = 50000;

		$sql = $wpdb->prepare(
			"SELECT e.created_at, e.referral_code,
				e.lead_name, e.lead_phone, e.phone_number,
				e.event_page, e.form_type, e.extra,
				COALESCE(c.name, e.referral_code) AS campaign_name
			FROM {$events} e
			LEFT JOIN {$campaigns} c ON c.id = e.campaign_id
			WHERE {$where}
			ORDER BY e.created_at DESC
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params
		);

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Full row for a single event id, joined with campaign name.
	 *
	 * @param int $id Event id.
	 * @return array|null
	 */
	public static function get_event_by_id( $id ) {
		global $wpdb;
		$events    = RTP_Database::events_table();
		$campaigns = RTP_Database::campaigns_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT e.*, COALESCE(c.name, e.referral_code) AS campaign_name
				FROM {$events} e
				LEFT JOIN {$campaigns} c ON c.id = e.campaign_id
				WHERE e.id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $id )
			),
			ARRAY_A
		);
	}

	/* ---------------------------------------------------------------------
	 * Per-campaign detail
	 * ------------------------------------------------------------------- */

	/**
	 * Summary for one campaign (all time).
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array
	 */
	public static function get_campaign_summary( $campaign_id ) {
		$f = array(
			'from'        => '2000-01-01',
			'to'          => gmdate( 'Y-m-d' ),
			'campaign_id' => absint( $campaign_id ),
		);
		return self::get_summary( $f );
	}

	/**
	 * Distinct visitor sessions for a campaign with their journey size.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $limit       Max sessions.
	 * @return array
	 */
	public static function get_campaign_sessions( $campaign_id, $limit = 50 ) {
		global $wpdb;
		$sessions = RTP_Database::sessions_table();
		$events   = RTP_Database::events_table();

		$sql = $wpdb->prepare(
			"SELECT s.session_id, s.landing_page, s.device, s.browser, s.os, s.created_at,
				(SELECT COUNT(*) FROM {$events} e WHERE e.session_id = s.session_id) AS event_count,
				(SELECT COUNT(*) FROM {$events} e WHERE e.session_id = s.session_id AND e.event_type='call') AS calls,
				(SELECT COUNT(*) FROM {$events} e WHERE e.session_id = s.session_id AND e.event_type='form') AS forms
			FROM {$sessions} s
			WHERE s.campaign_id = %d
			ORDER BY s.created_at DESC
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			absint( $campaign_id ),
			absint( $limit )
		);

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Recent event timeline for a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $limit       Max events.
	 * @return array
	 */
	public static function get_campaign_timeline( $campaign_id, $limit = 100 ) {
		global $wpdb;
		$events = RTP_Database::events_table();

		$sql = $wpdb->prepare(
			"SELECT created_at, session_id, event_type, event_page, phone_number, form_name, device, browser
			FROM {$events}
			WHERE campaign_id = %d
			ORDER BY created_at DESC
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			absint( $campaign_id ),
			absint( $limit )
		);

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Full ordered journey (events) for one visitor session.
	 *
	 * @param string $session_id Session id.
	 * @return array
	 */
	public static function get_session_journey( $session_id ) {
		global $wpdb;
		$events = RTP_Database::events_table();

		$sql = $wpdb->prepare(
			"SELECT created_at, event_type, event_page, phone_number, form_name
			FROM {$events}
			WHERE session_id = %s
			ORDER BY created_at ASC
			LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$session_id
		);

		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Lists campaigns for tables / filter dropdowns.
	 *
	 * @return array
	 */
	public static function list_campaigns() {
		global $wpdb;
		$campaigns = RTP_Database::campaigns_table();
		return $wpdb->get_results(
			"SELECT * FROM {$campaigns} ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
	}
}
