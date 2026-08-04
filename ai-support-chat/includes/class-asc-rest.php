<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Public REST endpoints for the chat widget, with rate limiting and
 * CORS support for the Next.js subdomains.
 */
class ASC_REST {

	const RATE_LIMIT = 15; // messages per minute per IP

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'cors' ), 20, 4 );
	}

	public function routes() {
		register_rest_route( 'asc/v1', '/config', array(
			'methods'             => 'GET',
			'callback'            => function () {
				return rest_ensure_response( asc_widget_config() );
			},
			'permission_callback' => '__return_true',
		) );

		register_rest_route( 'asc/v1', '/message', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_message' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'session_id' => array( 'required' => true, 'type' => 'string' ),
				'message'    => array( 'required' => true, 'type' => 'string' ),
			),
		) );

		register_rest_route( 'asc/v1', '/handoff', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_handoff' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'session_id' => array( 'required' => true, 'type' => 'string' ),
				'name'       => array( 'required' => true, 'type' => 'string' ),
				'email'      => array( 'required' => true, 'type' => 'string' ),
				'message'    => array( 'required' => true, 'type' => 'string' ),
			),
		) );
	}

	public function handle_message( WP_REST_Request $req ) {
		if ( $this->rate_limited() ) {
			return new WP_Error( 'asc_rate_limited', 'Too many messages. Please wait a minute.', array( 'status' => 429 ) );
		}

		$session = substr( sanitize_text_field( $req['session_id'] ), 0, 64 );
		$message = substr( sanitize_textarea_field( $req['message'] ), 0, 1000 );
		if ( '' === $session || '' === trim( $message ) ) {
			return new WP_Error( 'asc_bad_request', 'session_id and message are required.', array( 'status' => 400 ) );
		}

		$rag    = new ASC_RAG();
		$result = $rag->answer( $session, $message );

		if ( is_wp_error( $result ) ) {
			error_log( 'ASC chat error: ' . $result->get_error_message() );
			return rest_ensure_response( array(
				'reply'   => 'Sorry, something went wrong on our side. You can leave a message for our team instead.',
				'handoff' => true,
			) );
		}
		return rest_ensure_response( $result );
	}

	public function handle_handoff( WP_REST_Request $req ) {
		if ( $this->rate_limited() ) {
			return new WP_Error( 'asc_rate_limited', 'Too many requests. Please wait a minute.', array( 'status' => 429 ) );
		}

		$email = sanitize_email( $req['email'] );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'asc_bad_email', 'Please provide a valid email address.', array( 'status' => 400 ) );
		}

		$ticket_id = ASC_Tickets::create_ticket(
			substr( sanitize_text_field( $req['name'] ), 0, 100 ),
			$email,
			substr( sanitize_textarea_field( $req['message'] ), 0, 2000 ),
			substr( sanitize_text_field( $req['session_id'] ), 0, 64 )
		);

		if ( is_wp_error( $ticket_id ) ) {
			return new WP_Error( 'asc_ticket_failed', 'Could not create the ticket. Please try again.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'ticket_id' => $ticket_id ) );
	}

	private function rate_limited() {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key   = 'asc_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Allow the widget on Next.js subdomains to call these endpoints.
	 * Origins are configured on the settings page, one per line.
	 */
	public function cors( $served, $result, $request, $server ) {
		if ( 0 !== strpos( $request->get_route(), '/asc/v1' ) ) {
			return $served;
		}
		$origin = get_http_origin();
		if ( ! $origin ) {
			return $served;
		}

		$allowed   = array_filter( array_map( 'trim', explode( "\n", (string) get_option( 'asc_allowed_origins' ) ) ) );
		$allowed[] = home_url();

		foreach ( $allowed as $candidate ) {
			if ( untrailingslashit( $candidate ) === untrailingslashit( $origin ) ) {
				header_remove( 'Access-Control-Allow-Origin' );
				header( 'Access-Control-Allow-Origin: ' . $origin );
				header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
				header( 'Access-Control-Allow-Headers: Content-Type' );
				header( 'Vary: Origin' );
				break;
			}
		}
		return $served;
	}
}
