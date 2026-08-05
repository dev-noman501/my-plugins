<?php
/**
 * CallRail Sync — pulls verified call records from the CallRail v3 API into a
 * dedicated table (wp_rtp_callrail_calls), powers the "CallRail" admin page,
 * and provides upsert helpers used by the webhook receiver as well.
 *
 * Design goals:
 *  - Only verified, server-confirmed data lives in this table. Browser tel:
 *    click events are never written here.
 *  - The CallRail call id is the canonical identity (UNIQUE column) so every
 *    code path — manual sync, webhook, cron — upserts safely without races.
 *  - API key never travels to the front-end; all requests are made from PHP.
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync + storage layer for CallRail-verified call records.
 */
class RTP_CallRail_Sync {

	/**
	 * CallRail v3 base URL.
	 */
	const API_BASE = 'https://api.callrail.com/v3';

	/**
	 * Local table slug (without prefix).
	 */
	const TABLE_SLUG = 'rtp_callrail_calls';

	/**
	 * Option that stores the last successful sync timestamp.
	 */
	const OPTION_LAST_SYNC = 'rtp_callrail_last_sync_at';

	/**
	 * Option that stores the last sync result payload (ok/error + counts).
	 */
	const OPTION_LAST_RESULT = 'rtp_callrail_last_sync_result';

	/**
	 * Max API pages per single sync invocation (safety net).
	 */
	const MAX_PAGES_PER_SYNC = 50; // ≈ 5000 calls per click — far above SMB needs.

	/**
	 * Returns the fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SLUG;
	}

	/**
	 * Creates / upgrades the local table via dbDelta.
	 *
	 * Idempotent — safe to call on every plugin load behind a version gate.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			callrail_call_id VARCHAR(64) NOT NULL,
			caller_number VARCHAR(40) NOT NULL DEFAULT '',
			caller_name VARCHAR(191) NOT NULL DEFAULT '',
			tracking_number VARCHAR(40) NOT NULL DEFAULT '',
			tracking_name VARCHAR(191) NOT NULL DEFAULT '',
			source VARCHAR(191) NOT NULL DEFAULT '',
			referral VARCHAR(64) NOT NULL DEFAULT '',
			campaign VARCHAR(191) NOT NULL DEFAULT '',
			medium VARCHAR(100) NOT NULL DEFAULT '',
			keywords VARCHAR(255) NOT NULL DEFAULT '',
			landing_page VARCHAR(512) NOT NULL DEFAULT '',
			call_started_at DATETIME NULL,
			duration INT NOT NULL DEFAULT 0,
			call_status VARCHAR(40) NOT NULL DEFAULT '',
			answered TINYINT(1) NOT NULL DEFAULT 0,
			direction VARCHAR(20) NOT NULL DEFAULT 'inbound',
			recording_url VARCHAR(512) NOT NULL DEFAULT '',
			customer_city VARCHAR(100) NOT NULL DEFAULT '',
			customer_state VARCHAR(100) NOT NULL DEFAULT '',
			customer_country VARCHAR(100) NOT NULL DEFAULT '',
			device VARCHAR(40) NOT NULL DEFAULT '',
			verified_from_callrail TINYINT(1) NOT NULL DEFAULT 1,
			raw_payload LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY callrail_call_id (callrail_call_id),
			KEY tracking_number (tracking_number),
			KEY call_started_at (call_started_at),
			KEY referral (referral)
		) {$charset};";

		dbDelta( $sql );
	}

	/* ---------------------------------------------------------------------
	 * Credential accessors — prefer wp-config constants over DB settings.
	 * ------------------------------------------------------------------- */

	/**
	 * Returns the CallRail API key (constant > setting).
	 *
	 * @return string
	 */
	public static function get_api_key() {
		if ( defined( 'RTP_CALLRAIL_API_KEY' ) && RTP_CALLRAIL_API_KEY ) {
			return (string) RTP_CALLRAIL_API_KEY;
		}
		return (string) RTP_Helpers::get_setting( 'callrail_api_key', '' );
	}

	/**
	 * Returns the CallRail Account id (constant > setting).
	 *
	 * @return string
	 */
	public static function get_account_id() {
		if ( defined( 'RTP_CALLRAIL_ACCOUNT_ID' ) && RTP_CALLRAIL_ACCOUNT_ID ) {
			return (string) RTP_CALLRAIL_ACCOUNT_ID;
		}
		return (string) RTP_Helpers::get_setting( 'callrail_account_id', '' );
	}

	/**
	 * Returns the optional CallRail Company id (constant > setting).
	 *
	 * @return string
	 */
	public static function get_company_id() {
		if ( defined( 'RTP_CALLRAIL_COMPANY_ID' ) && RTP_CALLRAIL_COMPANY_ID ) {
			return (string) RTP_CALLRAIL_COMPANY_ID;
		}
		return (string) RTP_Helpers::get_setting( 'callrail_company_id', '' );
	}

	/**
	 * Optional default tracking number to filter syncs by (constant > setting).
	 * When set, the manual-sync button and cron will scope to this number unless
	 * the admin overrides it from the UI.
	 *
	 * @return string
	 */
	public static function get_default_tracking_number() {
		if ( defined( 'RTP_CALLRAIL_TRACKING_NUMBER' ) && RTP_CALLRAIL_TRACKING_NUMBER ) {
			return (string) RTP_CALLRAIL_TRACKING_NUMBER;
		}
		return (string) RTP_Helpers::get_setting( 'callrail_tracking_number', '' );
	}

	/* ---------------------------------------------------------------------
	 * Sync — main entry point used by the manual button & cron.
	 * ------------------------------------------------------------------- */

	/**
	 * Pulls calls from the CallRail API for the given window and upserts
	 * them into the local table.
	 *
	 * @param array $args { from_date Y-m-d, to_date Y-m-d, tracking_number }.
	 * @return array Sync result ({ ok, inserted, updated, total, error?, synced_at }).
	 */
	public static function sync_calls( $args = array() ) {
		$api_key    = self::get_api_key();
		$account_id = self::get_account_id();

		if ( '' === $api_key || '' === $account_id ) {
			return self::store_result( array(
				'ok'    => false,
				'error' => __( 'CallRail API key or Account ID is not configured. Add them in Settings or in wp-config.php.', 'referral-tracker-pro' ),
			) );
		}

		$args = wp_parse_args( $args, array(
			'from_date'       => gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ),
			'to_date'         => gmdate( 'Y-m-d' ),
			'tracking_number' => self::get_default_tracking_number(),
		) );

		$tracking_filter = self::normalize_tracking_number( $args['tracking_number'] );

		$page        = 1;
		$per_page    = 100;
		$inserted    = 0;
		$updated     = 0;
		$processed   = 0;
		$total_pages = 1;
		$api_total   = null;

		do {
			$response = self::fetch_calls_page( array(
				'start_date'      => $args['from_date'],   // YYYY-MM-DD — CallRail-friendly.
				'end_date'        => $args['to_date'],
				'page'            => $page,
				'per_page'        => $per_page,
				'tracking_number' => $tracking_filter,
			) );

			if ( is_wp_error( $response ) ) {
				return self::store_result( array(
					'ok'    => false,
					'error' => $response->get_error_message(),
				) );
			}

			if ( null === $api_total && isset( $response['total_records'] ) ) {
				$api_total = (int) $response['total_records'];
			}

			if ( empty( $response['calls'] ) || ! is_array( $response['calls'] ) ) {
				break;
			}

			foreach ( $response['calls'] as $call ) {
				if ( ! is_array( $call ) ) {
					continue;
				}
				$status = self::upsert_call( $call, $tracking_filter );
				if ( 'inserted' === $status ) {
					$inserted++;
				} elseif ( 'updated' === $status ) {
					$updated++;
				}
				$processed++;
			}

			$total_pages = isset( $response['total_pages'] ) ? (int) $response['total_pages'] : 1;
			$page++;
		} while ( $page <= $total_pages && $page <= self::MAX_PAGES_PER_SYNC );

		return self::store_result( array(
			'ok'         => true,
			'inserted'   => $inserted,
			'updated'    => $updated,
			'processed'  => $processed,
			'pages'      => $page - 1,
			'api_total'  => $api_total,   // What the API said was available in this window.
		) );
	}

	/**
	 * Stamps the sync timestamp and persists the result for the UI.
	 *
	 * @param array $result Partial result.
	 * @return array Full result with synced_at.
	 */
	private static function store_result( $result ) {
		$result['synced_at'] = current_time( 'mysql' );
		update_option( self::OPTION_LAST_SYNC, $result['synced_at'] );
		update_option( self::OPTION_LAST_RESULT, $result );
		return $result;
	}

	/**
	 * Option that stores the raw API debug trail of the most recent request.
	 */
	const OPTION_LAST_DEBUG = 'rtp_callrail_last_debug';

	/**
	 * Returns the last raw debug snapshot stored by fetch_calls_page.
	 *
	 * @return array|null { url, http_code, body_preview, requested_at, auth_prefix }
	 */
	public static function get_last_debug() {
		$d = get_option( self::OPTION_LAST_DEBUG, null );
		return is_array( $d ) ? $d : null;
	}

	/**
	 * Makes one API request and returns the decoded body or WP_Error.
	 *
	 * @param array $args Query args (start_date, end_date, page, per_page, tracking_number).
	 * @return array|WP_Error
	 */
	private static function fetch_calls_page( $args ) {
		$api_key    = self::get_api_key();
		$account_id = self::get_account_id();
		$company_id = self::get_company_id();

		// CallRail's default response only includes "basic" fields. To get
		// `landing_page_url`, `source`, `keywords`, etc. (which we need for
		// referral attribution) we MUST list them explicitly here. We tested
		// these against CallRail's validator — every name below is allowed.
		// If a future API version rejects any of them, our 400 handler will
		// surface the message in the debug panel.
		$fields = implode( ',', array(
			'id',
			'answered',
			'business_phone_number',
			'customer_phone_number',
			'customer_name',
			'customer_city',
			'customer_state',
			'customer_country',
			'duration',
			'recording',
			'recording_duration',
			'start_time',
			'tracking_phone_number',
			'voicemail',
			'direction',
			'source',
			'source_name',
			'formatted_tracking_source',
			'formatted_customer_location',
			'landing_page_url',
			'keywords',
			'campaign',
			'medium',
			'first_call',
			'lead_status',
			'note',
			'call_type',
		) );

		$query = array(
			'start_date' => $args['start_date'],
			'end_date'   => $args['end_date'],
			'page'       => (int) $args['page'],
			'per_page'   => (int) $args['per_page'],
			'fields'     => $fields,
		);

		if ( ! empty( $args['tracking_number'] ) ) {
			$query['tracking_phone_number'] = $args['tracking_number'];
		}
		if ( '' !== $company_id ) {
			$query['company_id'] = $company_id;
		}

		$url = sprintf(
			'%s/a/%s/calls.json?%s',
			self::API_BASE,
			rawurlencode( $account_id ),
			http_build_query( $query )
		);

		// Bearer-token auth is CallRail v3's current standard. The legacy
		// `Token token="..."` syntax is still documented but newer keys
		// silently 401 against it — so we always send Bearer.
		$auth_header = 'Bearer ' . trim( $api_key );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => $auth_header,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			update_option(
				self::OPTION_LAST_DEBUG,
				array(
					'url'          => $url,
					'auth_prefix'  => substr( $auth_header, 0, 12 ) . '…',
					'http_code'    => 0,
					'body_preview' => 'WP_Error: ' . $response->get_error_message(),
					'requested_at' => current_time( 'mysql' ),
				)
			);
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		// Always snapshot the latest request so the admin can see exactly
		// what we asked CallRail and what it answered.
		update_option(
			self::OPTION_LAST_DEBUG,
			array(
				'url'          => $url,
				'auth_prefix'  => substr( $auth_header, 0, 12 ) . '…',
				'http_code'    => $code,
				'body_preview' => substr( $body, 0, 800 ),
				'requested_at' => current_time( 'mysql' ),
			)
		);

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'callrail_auth',
				sprintf( 'CallRail API auth failed (HTTP %d). Body: %s', $code, substr( $body, 0, 300 ) )
			);
		}
		if ( 200 !== $code ) {
			return new WP_Error(
				'callrail_http',
				sprintf( 'CallRail API returned HTTP %d. Body: %s', $code, substr( $body, 0, 300 ) )
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'callrail_json', 'CallRail API returned non-JSON body.' );
		}

		return $decoded;
	}

	/* ---------------------------------------------------------------------
	 * Upsert — used by sync, webhook, and cron.
	 * ------------------------------------------------------------------- */

	/**
	 * INSERT or UPDATE one call into the table, keyed by callrail_call_id.
	 *
	 * @param array  $call            CallRail call payload (API or webhook shape).
	 * @param string $tracking_filter Optional tracking number — calls outside it are skipped.
	 * @return string 'inserted' | 'updated' | 'skipped'
	 */
	public static function upsert_call( $call, $tracking_filter = '' ) {
		global $wpdb;

		if ( ! is_array( $call ) ) {
			return 'skipped';
		}

		$call_id = isset( $call['id'] ) ? sanitize_text_field( (string) $call['id'] ) : '';
		if ( '' === $call_id ) {
			return 'skipped';
		}

		$caller_number   = self::pick( $call, array( 'customer_phone_number', 'caller_id' ) );
		$tracking_number = self::pick( $call, array( 'tracking_phone_number', 'tracking_number', 'called_number' ) );

		// Honor an explicit tracking-number filter.
		if ( '' !== $tracking_filter ) {
			if ( self::normalize_tracking_number( $tracking_number ) !== self::normalize_tracking_number( $tracking_filter ) ) {
				return 'skipped';
			}
		}

		$landing_page = self::pick( $call, array( 'landing_page_url', 'landing_page' ) );
		$referral     = self::parse_ref_from_url( $landing_page );

		$started_at = '';
		$raw_start  = self::pick( $call, array( 'start_time', 'created_at' ) );
		if ( '' !== $raw_start ) {
			$ts = strtotime( $raw_start );
			if ( $ts ) {
				$started_at = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		$duration = isset( $call['duration'] ) ? max( 0, (int) $call['duration'] ) : 0;
		$answered = ! empty( $call['answered'] ) ? 1 : 0;
		$status   = self::derive_status( $call );

		$table = self::table();
		$now   = current_time( 'mysql' );

		$data = array(
			'callrail_call_id'        => substr( $call_id, 0, 64 ),
			'caller_number'           => substr( sanitize_text_field( $caller_number ), 0, 40 ),
			'caller_name'             => substr( sanitize_text_field( self::pick( $call, array( 'customer_name', 'caller_name' ) ) ), 0, 191 ),
			'tracking_number'         => substr( sanitize_text_field( $tracking_number ), 0, 40 ),
			'tracking_name'           => substr( sanitize_text_field( self::pick( $call, array( 'tracker_name' ) ) ), 0, 191 ),
			// Try the visitor-level source first (e.g. "Google My Business",
			// "Direct"); fall back to the formatted source string CallRail's
			// UI shows; last resort is the tracker name ("Website Pool" etc.).
			'source'                  => substr( sanitize_text_field( self::pick( $call, array( 'source', 'formatted_tracking_source', 'source_name' ) ) ), 0, 191 ),
			'referral'                => $referral,
			'campaign'                => substr( sanitize_text_field( self::pick( $call, array( 'campaign' ) ) ), 0, 191 ),
			'medium'                  => substr( sanitize_text_field( self::pick( $call, array( 'medium' ) ) ), 0, 100 ),
			'keywords'                => substr( sanitize_text_field( self::pick( $call, array( 'keywords' ) ) ), 0, 255 ),
			'landing_page'            => esc_url_raw( $landing_page ),
			'call_started_at'         => '' !== $started_at ? $started_at : null,
			'duration'                => $duration,
			'call_status'             => substr( sanitize_text_field( $status ), 0, 40 ),
			'answered'                => $answered,
			'direction'               => substr( sanitize_text_field( self::pick( $call, array( 'direction' ) ) ), 0, 20 ),
			'recording_url'           => esc_url_raw( self::pick( $call, array( 'recording', 'recording_url' ) ) ),
			'customer_city'           => substr( sanitize_text_field( self::pick( $call, array( 'customer_city' ) ) ), 0, 100 ),
			'customer_state'          => substr( sanitize_text_field( self::pick( $call, array( 'customer_state' ) ) ), 0, 100 ),
			'customer_country'        => substr( sanitize_text_field( self::pick( $call, array( 'customer_country' ) ) ), 0, 100 ),
			'device'                  => substr( sanitize_text_field( self::pick( $call, array( 'device' ) ) ), 0, 40 ),
			'verified_from_callrail'  => 1,
			'raw_payload'             => wp_json_encode( $call ),
			'updated_at'              => $now,
		);

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE callrail_call_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$data['callrail_call_id']
			)
		);

		if ( $existing_id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $existing_id ), null, array( '%d' ) );
			return 'updated';
		}

		$data['created_at'] = $now;
		$ok = $wpdb->insert( $table, $data );
		return $ok ? 'inserted' : 'skipped';
	}

	/* ---------------------------------------------------------------------
	 * Reads — used by the admin page.
	 * ------------------------------------------------------------------- */

	/**
	 * Paginated list of verified CallRail calls.
	 *
	 * @param array $filters  { from_date, to_date, tracking_number, search }.
	 * @param int   $paged    Current page.
	 * @param int   $per_page Rows per page.
	 * @return array { rows, total }
	 */
	public static function get_calls( $filters = array(), $paged = 1, $per_page = 25 ) {
		global $wpdb;
		$table = self::table();

		$filters = wp_parse_args( $filters, array(
			'from_date'       => '',
			'to_date'         => '',
			'tracking_number' => '',
			'search'          => '',
		) );

		$where_parts = array( '1=1' );
		$params      = array();

		if ( '' !== $filters['from_date'] ) {
			$where_parts[] = 'call_started_at >= %s';
			$params[]      = $filters['from_date'] . ' 00:00:00';
		}
		if ( '' !== $filters['to_date'] ) {
			$where_parts[] = 'call_started_at <= %s';
			$params[]      = $filters['to_date'] . ' 23:59:59';
		}
		if ( '' !== $filters['tracking_number'] ) {
			$where_parts[] = 'tracking_number = %s';
			$params[]      = self::normalize_tracking_number( $filters['tracking_number'] );
		}
		if ( '' !== $filters['search'] ) {
			$like          = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where_parts[] = '( caller_number LIKE %s OR caller_name LIKE %s OR callrail_call_id LIKE %s )';
			$params[]      = $like;
			$params[]      = $like;
			$params[]      = $like;
		}

		$where = implode( ' AND ', $where_parts );

		$count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$paged    = max( 1, absint( $paged ) );
		$per_page = max( 1, min( 200, absint( $per_page ) ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$page_params   = $params;
		$page_params[] = $per_page;
		$page_params[] = $offset;

		$sql  = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY call_started_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$page_params
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Distinct tracking numbers known to the local table — for the filter dropdown.
	 *
	 * @return string[]
	 */
	public static function get_distinct_tracking_numbers() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_col( "SELECT DISTINCT tracking_number FROM {$table} WHERE tracking_number <> '' ORDER BY tracking_number ASC LIMIT 50" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Last successful sync timestamp (mysql format) or empty.
	 *
	 * @return string
	 */
	public static function get_last_sync_time() {
		return (string) get_option( self::OPTION_LAST_SYNC, '' );
	}

	/**
	 * Full last-sync result for the UI notice.
	 *
	 * @return array|null
	 */
	public static function get_last_sync_result() {
		$r = get_option( self::OPTION_LAST_RESULT, null );
		return is_array( $r ) ? $r : null;
	}

	/* ---------------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------------- */

	/**
	 * First non-empty value among the keys.
	 *
	 * @param array    $data Source.
	 * @param string[] $keys Priority order.
	 * @return string
	 */
	private static function pick( $data, $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $data[ $k ] ) && '' !== $data[ $k ] && null !== $data[ $k ] ) {
				return (string) $data[ $k ];
			}
		}
		return '';
	}

	/**
	 * Strips everything except digits and a leading +.
	 *
	 * @param string $num Raw number.
	 * @return string
	 */
	private static function normalize_tracking_number( $num ) {
		$num = (string) $num;
		return preg_replace( '/[^0-9+]/', '', $num );
	}

	/**
	 * Pulls ?ref=CODE out of a landing-page URL and returns the sanitized code.
	 *
	 * @param string $url Landing URL.
	 * @return string
	 */
	private static function parse_ref_from_url( $url ) {
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['query'] ) ) {
			return '';
		}
		$query = array();
		parse_str( $parts['query'], $query );
		return RTP_Helpers::sanitize_code( isset( $query['ref'] ) ? $query['ref'] : '' );
	}

	/**
	 * Derives a human-readable call_status from the API payload.
	 *
	 * @param array $call Call payload.
	 * @return string
	 */
	private static function derive_status( $call ) {
		if ( ! empty( $call['voicemail'] ) ) {
			return 'voicemail';
		}
		if ( ! empty( $call['answered'] ) ) {
			return 'answered';
		}
		if ( isset( $call['answered'] ) && false === $call['answered'] ) {
			return 'missed';
		}
		if ( isset( $call['duration'] ) && (int) $call['duration'] > 0 ) {
			return 'answered';
		}
		return '';
	}

	/**
	 * Formats a seconds count for display ("1m 23s" / "45s").
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function format_duration( $seconds ) {
		$s = max( 0, (int) $seconds );
		if ( $s >= 60 ) {
			return sprintf( '%dm %ds', (int) floor( $s / 60 ), $s % 60 );
		}
		return $s . 's';
	}
}
