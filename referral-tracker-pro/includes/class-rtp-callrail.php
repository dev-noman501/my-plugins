<?php
/**
 * CallRail integration: webhook receiver, API polling and referral linking.
 *
 * Caller's actual phone number is captured via CallRail's Dynamic Number
 * Insertion (DNI) — the visitor sees a rotating tracking number, calls it,
 * CallRail forwards the call to the real business number and exposes the
 * caller's caller-id back to us through:
 *
 *   1) Post-Call Webhook  — real-time push to /wp-json/rtp/v1/callrail
 *   2) API polling fallback — pulls calls every 5 minutes for resilience
 *
 * Each call is attributed to a referral by parsing the `?ref=CODE` param
 * from the visitor's landing page URL (recorded by CallRail). Calls without
 * an attributable referral are intentionally ignored — this plugin only
 * tracks referred traffic.
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures CallRail call events and persists them as RTP "call" events.
 */
class RTP_CallRail {

	/**
	 * CallRail API v3 base URL.
	 */
	const API_BASE = 'https://api.callrail.com/v3';

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'rtp_callrail_poll';

	/**
	 * Custom cron interval slug.
	 */
	const CRON_INTERVAL = 'rtp_five_minutes';

	/**
	 * Transient key remembering the last successful polling cursor.
	 */
	const TRANSIENT_LAST = 'rtp_callrail_last_poll';

	/**
	 * Registers all hooks. Routes/cron are always wired up so they can be
	 * toggled at runtime via the settings checkbox; the actual processing
	 * is short-circuited inside the callbacks when CallRail is disabled.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::CRON_HOOK, array( $this, 'poll_recent_calls' ) );

		if ( $this->is_enabled() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * Adds a five-minute schedule used by the polling cron.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public function register_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes (CallRail polling)', 'referral-tracker-pro' ),
			);
		}
		return $schedules;
	}

	/**
	 * Registers the public webhook route. Token is checked inside the handler.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'rtp/v1',
			'/callrail',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Webhook callback. Verifies the shared secret then processes one call.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		if ( ! $this->is_enabled() ) {
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'disabled' ), 200 );
		}

		if ( ! $this->verify_token( $request ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'forbidden' ), 403 );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			$payload = $request->get_params();
		}

		$saved = $this->process_call( $payload );

		return new WP_REST_Response(
			array(
				'ok'    => true,
				'saved' => (bool) $saved,
			),
			200
		);
	}

	/**
	 * Cron callback — pulls calls from the API since the last cursor.
	 *
	 * Acts as a self-healing fallback: even if webhooks are misconfigured
	 * or briefly down, calls get captured within ~5 minutes.
	 *
	 * @return void
	 */
	public function poll_recent_calls() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$api_key    = (string) RTP_Helpers::get_setting( 'callrail_api_key', '' );
		$account_id = (string) RTP_Helpers::get_setting( 'callrail_account_id', '' );

		if ( '' === $api_key || '' === $account_id ) {
			return;
		}

		// Look back further than the poll interval so we never miss a call
		// because of clock drift between WP and CallRail.
		$since = (string) get_transient( self::TRANSIENT_LAST );
		if ( '' === $since ) {
			$since = gmdate( 'Y-m-d\TH:i:s\Z', time() - 30 * MINUTE_IN_SECONDS );
		}

		// Same curated field list as the manual sync — needed because
		// CallRail's default response omits landing_page_url, source, etc.
		$args = array(
			'start_date' => $since,
			'per_page'   => 100,
			'fields'     => 'id,answered,business_phone_number,customer_phone_number,customer_name,customer_city,customer_state,customer_country,duration,recording,recording_duration,start_time,tracking_phone_number,voicemail,direction,source,source_name,formatted_tracking_source,formatted_customer_location,landing_page_url,keywords,campaign,medium,first_call,lead_status,note,call_type',
		);

		$company_id = (string) RTP_Helpers::get_setting( 'callrail_company_id', '' );
		if ( '' !== $company_id ) {
			$args['company_id'] = $company_id;
		}

		$url = sprintf(
			'%s/a/%s/calls.json?%s',
			self::API_BASE,
			rawurlencode( $account_id ),
			http_build_query( $args )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return;
		}

		// Advance the cursor regardless of whether any calls came back, so we
		// don't keep refetching the same empty window.
		set_transient( self::TRANSIENT_LAST, gmdate( 'Y-m-d\TH:i:s\Z' ), DAY_IN_SECONDS );

		if ( empty( $body['calls'] ) || ! is_array( $body['calls'] ) ) {
			return;
		}

		foreach ( $body['calls'] as $call ) {
			if ( is_array( $call ) ) {
				$this->process_call( $call );
			}
		}
	}

	/**
	 * Normalises a CallRail call payload and persists it to the dedicated
	 * CallRail table (wp_rtp_callrail_calls) via RTP_CallRail_Sync.
	 *
	 * As of v1.3.0 we no longer write CallRail data into the generic events
	 * table — it lived alongside browser tel:click attempts and produced
	 * confusing 0-second rows on the legacy "Calls" page.
	 *
	 * @param array $call CallRail call payload (webhook or API shape).
	 * @return bool True when a row was inserted or updated, false otherwise.
	 */
	private function process_call( $call ) {
		if ( ! is_array( $call ) ) {
			return false;
		}

		if ( ! class_exists( 'RTP_CallRail_Sync' ) ) {
			return false;
		}

		$status = RTP_CallRail_Sync::upsert_call( $call );
		return in_array( $status, array( 'inserted', 'updated' ), true );
	}

	/**
	 * Legacy method retained for backward compatibility — no longer used.
	 *
	 * @param array $call Old CallRail payload.
	 * @return bool Always false.
	 */
	private function process_call_legacy( $call ) {
		if ( ! is_array( $call ) ) {
			return false;
		}

		$call_id = isset( $call['id'] ) ? sanitize_text_field( (string) $call['id'] ) : '';
		if ( '' === $call_id ) {
			return false;
		}

		if ( $this->call_already_recorded( $call_id ) ) {
			return false; // De-dupe across webhook + polling.
		}

		// CallRail's customer_phone_number is the CALLER's real number;
		// tracking_phone_number is the rotated DNI number that was dialed.
		$caller_number   = $this->pick( $call, array( 'customer_phone_number', 'caller_id' ) );
		$tracking_number = $this->pick( $call, array( 'tracking_phone_number', 'tracking_number' ) );
		$landing_url     = $this->pick( $call, array( 'landing_page_url', 'landing_page' ) );
		$referrer_url    = $this->pick( $call, array( 'referrer_url', 'referrer' ) );
		$recording_url   = $this->pick( $call, array( 'recording', 'recording_url' ) );
		$duration        = isset( $call['duration'] ) ? (int) $call['duration'] : 0;
		$source          = $this->pick( $call, array( 'source_name', 'source' ) );
		$caller_name     = $this->pick( $call, array( 'customer_name', 'caller_name' ) );
		$caller_city     = $this->pick( $call, array( 'customer_city' ) );
		$caller_state    = $this->pick( $call, array( 'customer_state' ) );
		$caller_country  = $this->pick( $call, array( 'customer_country' ) );
		$started_at      = $this->pick( $call, array( 'start_time', 'created_at' ) );

		$referral = $this->resolve_referral_from_url( $landing_url );
		if ( null === $referral ) {
			return false; // Not a referred caller — out of scope for this plugin.
		}

		$session_id = $this->session_id_for_call( $call_id );

		RTP_Database::ensure_session(
			array(
				'session_id'    => $session_id,
				'campaign_id'   => (int) $referral['campaign']->id,
				'referral_code' => $referral['code'],
				'referral_type' => sanitize_key( $referral['campaign']->type ),
				'landing_page'  => RTP_Helpers::sanitize_url_field( $landing_url ),
				'referrer_url'  => RTP_Helpers::sanitize_url_field( $referrer_url ),
				'utm'           => '',
				'ip_store'      => '',
				'user_agent'    => '',
				'device'        => '',
				'browser'       => '',
				'os'            => '',
			)
		);

		$extra = RTP_Helpers::json_encode(
			array(
				'callrail' => array(
					'call_id'        => $call_id,
					'recording_url'  => esc_url_raw( $recording_url ),
					'duration'       => $duration,
					'source'         => substr( sanitize_text_field( $source ), 0, 191 ),
					'caller_city'    => substr( sanitize_text_field( $caller_city ), 0, 100 ),
					'caller_state'   => substr( sanitize_text_field( $caller_state ), 0, 100 ),
					'caller_country' => substr( sanitize_text_field( $caller_country ), 0, 100 ),
					'started_at'     => substr( sanitize_text_field( $started_at ), 0, 40 ),
				),
			)
		);

		$id = RTP_Database::insert_event(
			array(
				'session_id'    => $session_id,
				'campaign_id'   => (int) $referral['campaign']->id,
				'referral_code' => $referral['code'],
				'event_type'    => 'call',
				'event_page'    => RTP_Helpers::sanitize_url_field( $landing_url ),
				'landing_page'  => RTP_Helpers::sanitize_url_field( $landing_url ),
				'phone_number'  => substr( sanitize_text_field( $tracking_number ), 0, 40 ),
				'form_id'       => '',
				'form_name'     => '',
				'form_type'     => 'callrail',
				'lead_name'     => substr( sanitize_text_field( $caller_name ), 0, 191 ),
				'lead_email'    => '',
				'lead_phone'    => substr( sanitize_text_field( $caller_number ), 0, 40 ),
				'lead_amount'   => null,
				'device'        => '',
				'browser'       => '',
				'os'            => '',
				'extra'         => $extra,
			)
		);

		return (bool) $id;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Returns true when the integration is switched on AND minimally configured.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		if ( ! RTP_Helpers::get_setting( 'callrail_enabled', 0 ) ) {
			return false;
		}
		// Allow webhook-only setups (no API key) — we just won't poll.
		return true;
	}

	/**
	 * Verifies the shared-secret token CallRail must append to the webhook URL
	 * as ?token=SECRET (or send via the X-RTP-Token header).
	 *
	 * If no secret is configured we accept the request — useful while the
	 * admin is still wiring things up.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool
	 */
	private function verify_token( WP_REST_Request $request ) {
		$expected = (string) RTP_Helpers::get_setting( 'callrail_webhook_secret', '' );
		if ( '' === $expected ) {
			return true;
		}

		$provided = (string) $request->get_param( 'token' );
		if ( '' === $provided && isset( $_SERVER['HTTP_X_RTP_TOKEN'] ) ) {
			$provided = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_RTP_TOKEN'] ) );
		}

		return hash_equals( $expected, $provided );
	}

	/**
	 * Returns the first non-empty value found among the candidate keys.
	 *
	 * @param array    $data Source array.
	 * @param string[] $keys Candidate keys (priority order).
	 * @return string
	 */
	private function pick( $data, $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $data[ $k ] ) && '' !== $data[ $k ] && null !== $data[ $k ] ) {
				return (string) $data[ $k ];
			}
		}
		return '';
	}

	/**
	 * Extracts ?ref=CODE from the landing page URL and returns the matching
	 * active campaign.
	 *
	 * @param string $url Landing page URL.
	 * @return array|null { campaign, code } or null when not attributable.
	 */
	private function resolve_referral_from_url( $url ) {
		if ( '' === $url ) {
			return null;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['query'] ) ) {
			return null;
		}

		$query = array();
		parse_str( $parts['query'], $query );

		$code = RTP_Helpers::sanitize_code( isset( $query['ref'] ) ? $query['ref'] : '' );
		if ( '' === $code ) {
			return null;
		}

		$campaign = RTP_Database::get_active_campaign_by_code( $code );
		if ( ! $campaign ) {
			return null;
		}

		return array(
			'campaign' => $campaign,
			'code'     => $code,
		);
	}

	/**
	 * Derives a stable session id for a CallRail call so multiple events
	 * about the same call (modifications, transcriptions later) can be
	 * grouped under one session row.
	 *
	 * @param string $call_id CallRail call id.
	 * @return string
	 */
	private function session_id_for_call( $call_id ) {
		return 'cr-' . substr( hash( 'sha1', 'callrail|' . $call_id ), 0, 32 );
	}

	/**
	 * Checks whether a CallRail call has already been stored, by searching
	 * the extra JSON blob for the call id.
	 *
	 * For the volumes involved (a single SMB) the LIKE scan is fine; if the
	 * dataset ever grows large this can be replaced with a dedicated column.
	 *
	 * @param string $call_id CallRail call id.
	 * @return bool
	 */
	private function call_already_recorded( $call_id ) {
		global $wpdb;
		$events = RTP_Database::events_table();

		$needle = '"call_id":"' . str_replace( array( '"', '\\' ), '', $call_id ) . '"';
		$like   = '%' . $wpdb->esc_like( $needle ) . '%';

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$events} WHERE event_type = 'call' AND extra LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$like
			)
		);

		return ! empty( $found );
	}

	/**
	 * Returns the webhook URL that the admin needs to paste into CallRail's
	 * Post-Call webhook field. If a secret is configured the token is
	 * appended so CallRail can authenticate.
	 *
	 * @return string
	 */
	public static function get_webhook_url() {
		$url    = rtrim( rest_url( 'rtp/v1/callrail' ), '/' );
		$secret = (string) RTP_Helpers::get_setting( 'callrail_webhook_secret', '' );

		if ( '' !== $secret ) {
			$url = add_query_arg( 'token', rawurlencode( $secret ), $url );
		}

		return $url;
	}

	/**
	 * Decodes the `extra` JSON for a call event and returns the callrail
	 * sub-array (empty array when missing/invalid). Used by views.
	 *
	 * @param string $extra Raw extra JSON.
	 * @return array
	 */
	public static function decode_extra( $extra ) {
		if ( '' === $extra || null === $extra ) {
			return array();
		}
		$data = json_decode( (string) $extra, true );
		if ( ! is_array( $data ) || empty( $data['callrail'] ) || ! is_array( $data['callrail'] ) ) {
			return array();
		}
		return $data['callrail'];
	}

	/**
	 * Formats an integer second count as "Mm Ss".
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string
	 */
	public static function format_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		$m       = (int) floor( $seconds / 60 );
		$s       = $seconds % 60;
		if ( $m > 0 ) {
			return sprintf( '%dm %ds', $m, $s );
		}
		return sprintf( '%ds', $s );
	}

	/**
	 * Unschedules the polling cron — called from the plugin deactivator.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}
}
