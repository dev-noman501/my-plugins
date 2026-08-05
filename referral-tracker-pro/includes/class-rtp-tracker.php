<?php
/**
 * Front-end asset loading + REST tracking endpoint.
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the public tracking pipeline.
 */
class RTP_Tracker {

	/**
	 * Maximum events accepted per session within the rate window.
	 */
	const RATE_LIMIT_MAX = 30;

	/**
	 * Rate window in seconds.
	 */
	const RATE_LIMIT_WINDOW = 60;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Enqueues the lightweight tracker only when relevant.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( RTP_Helpers::should_skip_tracking() ) {
			return; // Excluded users: zero front-end overhead.
		}

		wp_register_script(
			'rtp-tracker',
			RTP_PLUGIN_URL . 'assets/js/rtp-tracker.js',
			array(),
			RTP_VERSION,
			true
		);

		$config = array(
			'restUrl'       => esc_url_raw( rest_url( 'rtp/v1/event' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'cookieName'    => RTP_COOKIE_NAME,
			'cookieDays'    => (int) RTP_Helpers::get_setting( 'cookie_expiry_days', 30 ),
			'callTracking'  => (int) RTP_Helpers::get_setting( 'enable_call_tracking', 1 ),
			'formTracking'  => (int) RTP_Helpers::get_setting( 'enable_form_tracking', 1 ),
			'fieldStorage'  => (int) RTP_Helpers::get_setting( 'enable_field_storage', 0 ),
			'customSel'     => (string) RTP_Helpers::get_setting( 'custom_form_selectors', '' ),
		);

		wp_localize_script( 'rtp-tracker', 'RTP_CONFIG', $config );
		wp_enqueue_script( 'rtp-tracker' );
	}

	/**
	 * Registers the REST route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'rtp/v1',
			'/event',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_event' ),
				'permission_callback' => '__return_true', // Public endpoint; abuse controls applied in handler.
			)
		);
	}

	/**
	 * Handles an incoming tracking event.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_event( WP_REST_Request $request ) {
		$ok_response = new WP_REST_Response( array( 'ok' => true ), 200 );

		// Respect the logged-in exclusion server side too.
		if ( RTP_Helpers::should_skip_tracking() ) {
			return $ok_response;
		}

		// Loose same-origin check (defence in depth, not the only control).
		if ( ! $this->is_same_origin() ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}

		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( ! in_array( $type, array( 'visit', 'call', 'form' ), true ) ) {
			return $ok_response; // Silently ignore unknown types.
		}

		$code = RTP_Helpers::sanitize_code( $request->get_param( 'code' ) );
		if ( '' === $code ) {
			return $ok_response; // No referral attribution = nothing to record.
		}

		$campaign = RTP_Database::get_active_campaign_by_code( $code );
		if ( ! $campaign ) {
			return $ok_response; // Unknown / inactive code.
		}

		$session_id = RTP_Helpers::sanitize_session_id( $request->get_param( 'sid' ) );
		if ( '' === $session_id ) {
			return $ok_response;
		}

		// Per-session rate limiting via transient.
		if ( $this->is_rate_limited( $session_id ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 429 );
		}

		// Feature gates.
		if ( 'call' === $type && ! RTP_Helpers::get_setting( 'enable_call_tracking', 1 ) ) {
			return $ok_response;
		}
		if ( 'form' === $type && ! RTP_Helpers::get_setting( 'enable_form_tracking', 1 ) ) {
			return $ok_response;
		}

		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$uadata = RTP_Helpers::parse_user_agent( $ua );

		$landing_page = RTP_Helpers::sanitize_url_field( $request->get_param( 'landing' ) );
		$current_page = RTP_Helpers::sanitize_url_field( $request->get_param( 'page' ) );

		// First-touch session (created once, never overwritten).
		RTP_Database::ensure_session(
			array(
				'session_id'    => $session_id,
				'campaign_id'   => (int) $campaign->id,
				'referral_code' => $code,
				'referral_type' => sanitize_key( $campaign->type ),
				'landing_page'  => $landing_page,
				'referrer_url'  => RTP_Helpers::sanitize_url_field( $request->get_param( 'docref' ) ),
				'utm'           => $this->sanitize_utm( $request->get_param( 'utm' ) ),
				'ip_store'      => RTP_Helpers::get_ip_for_storage(),
				'user_agent'    => substr( $ua, 0, 255 ),
				'device'        => $uadata['device'],
				'browser'       => $uadata['browser'],
				'os'            => $uadata['os'],
			)
		);

		// Build event-specific fields.
		$phone     = '';
		$form_id   = '';
		$form_name = '';
		$form_type = '';
		$extra     = '';

		if ( 'call' === $type ) {
			$phone = substr( sanitize_text_field( (string) $request->get_param( 'phone' ) ), 0, 40 );
		}

		$lead_name   = '';
		$lead_email  = '';
		$lead_phone  = '';
		$lead_amount = null;

		if ( 'form' === $type ) {
			$form_id   = substr( sanitize_text_field( (string) $request->get_param( 'form_id' ) ), 0, 100 );
			$form_name = substr( sanitize_text_field( (string) $request->get_param( 'form_name' ) ), 0, 191 );
			$form_type = substr( sanitize_key( (string) $request->get_param( 'form_type' ) ), 0, 40 );

			$fields = $request->get_param( 'fields' );
			if ( is_array( $fields ) ) {
				// Lead identity is always captured (lightweight + the whole point
				// of the feature). Full field dump still gated by field_storage.
				$lead = RTP_Helpers::extract_lead_fields( $fields );
				$lead_name   = $lead['name'];
				$lead_email  = $lead['email'];
				$lead_phone  = $lead['phone'];
				$lead_amount = $lead['amount'];

				if ( RTP_Helpers::get_setting( 'enable_field_storage', 0 ) ) {
					$clean = RTP_Helpers::filter_field_data( $fields );
					if ( ! empty( $clean ) ) {
						$extra = RTP_Helpers::json_encode( array( 'fields' => $clean ) );
					}
				}
			}
		}

		// De-duplicate the same call/form action captured by both JS and a
		// server-side form hook within a short window.
		if ( in_array( $type, array( 'call', 'form' ), true )
			&& RTP_Database::recent_event_exists( $session_id, $type ) ) {
			return $ok_response;
		}

		RTP_Database::insert_event(
			array(
				'session_id'    => $session_id,
				'campaign_id'   => (int) $campaign->id,
				'referral_code' => $code,
				'event_type'    => $type,
				'event_page'    => $current_page,
				'landing_page'  => $landing_page,
				'phone_number'  => $phone,
				'form_id'       => $form_id,
				'form_name'     => $form_name,
				'form_type'     => $form_type ? $form_type : ( 'form' === $type ? 'generic' : '' ),
				'lead_name'     => $lead_name,
				'lead_email'    => $lead_email,
				'lead_phone'    => $lead_phone,
				'lead_amount'   => $lead_amount,
				'device'        => $uadata['device'],
				'browser'       => $uadata['browser'],
				'os'            => $uadata['os'],
				'extra'         => $extra,
			)
		);

		return $ok_response;
	}

	/**
	 * Sanitizes the UTM payload into a compact JSON string.
	 *
	 * @param mixed $utm Raw value.
	 * @return string
	 */
	private function sanitize_utm( $utm ) {
		if ( ! is_array( $utm ) ) {
			return '';
		}

		$allowed = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );
		$clean   = array();

		foreach ( $allowed as $key ) {
			if ( ! empty( $utm[ $key ] ) ) {
				$clean[ $key ] = substr( sanitize_text_field( (string) $utm[ $key ] ), 0, 191 );
			}
		}

		return empty( $clean ) ? '' : RTP_Helpers::json_encode( $clean );
	}

	/**
	 * Simple per-session rate limiter.
	 *
	 * @param string $session_id Session id.
	 * @return bool True when over the limit.
	 */
	private function is_rate_limited( $session_id ) {
		$key   = 'rtp_rl_' . md5( $session_id );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return true;
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return false;
	}

	/**
	 * Verifies the request originates from this site (Origin/Referer host match).
	 *
	 * @return bool
	 */
	private function is_same_origin() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		$origin = '';
		if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			$origin = wp_parse_url( wp_unslash( $_SERVER['HTTP_ORIGIN'] ), PHP_URL_HOST );
		} elseif ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$origin = wp_parse_url( wp_unslash( $_SERVER['HTTP_REFERER'] ), PHP_URL_HOST );
		}

		// If neither header is present (some privacy setups) allow it through;
		// the code/campaign validation is the primary integrity gate.
		if ( '' === $origin ) {
			return true;
		}

		return strtolower( (string) $origin ) === strtolower( (string) $host );
	}
}
