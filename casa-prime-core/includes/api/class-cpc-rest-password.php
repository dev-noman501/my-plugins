<?php
/**
 * REST API — password reset & change (casa-prime/v1).
 *
 * App-friendly flow: a 6-digit code is emailed (not a web link), so the user
 * enters it straight in the app.
 *
 * POST /auth/forgot-password    {login}                    → email a reset code
 * POST /auth/verify-reset-code  {login, code}              → check the code only
 * POST /auth/reset-password     {login, code, password}    → set new password
 * POST /auth/change-password    {current_password, password} [Bearer]
 */

defined( 'ABSPATH' ) || exit;

class CPC_REST_Password {

	const REST_NAMESPACE = 'casa-prime/v1';
	const CODE_META      = 'cpc_pwreset_code';
	const EXP_META       = 'cpc_pwreset_expires';
	const TTL            = 15 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$ns = self::REST_NAMESPACE;

		register_rest_route( $ns, '/auth/forgot-password', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'forgot' ),
			'permission_callback' => '__return_true',
			'args'                => array( 'login' => array( 'required' => true, 'type' => 'string' ) ),
		) );

		register_rest_route( $ns, '/auth/verify-reset-code', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'verify' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'login' => array( 'required' => true, 'type' => 'string' ),
				'code'  => array( 'required' => true, 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/auth/reset-password', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'reset' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'login'    => array( 'required' => true, 'type' => 'string' ),
				'code'     => array( 'required' => true, 'type' => 'string' ),
				'password' => array( 'required' => true, 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/auth/change-password', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'change' ),
			'permission_callback' => function () { return is_user_logged_in() ? true : new WP_Error( 'cpc_not_logged_in', 'Authentication required.', array( 'status' => 401 ) ); },
			'args'                => array(
				'current_password' => array( 'required' => true, 'type' => 'string' ),
				'password'         => array( 'required' => true, 'type' => 'string' ),
			),
		) );
	}

	/* ---------- Forgot: email a code ---------- */

	public static function forgot( WP_REST_Request $request ) {
		$user = CPC_REST_Auth::resolve_login( (string) $request['login'] );

		// Always return the same success message — never reveal whether an
		// account exists (prevents email/phone enumeration).
		$generic = array( 'success' => true, 'message' => 'If an account matches, a reset code has been sent to its email.' );

		if ( ! $user ) {
			return rest_ensure_response( $generic );
		}

		$code = (string) wp_rand( 100000, 999999 );
		update_user_meta( $user->ID, self::CODE_META, wp_hash_password( $code ) );
		update_user_meta( $user->ID, self::EXP_META, time() + self::TTL );

		self::send_code_email( $user, $code );

		$response = $generic;
		// Debug aid on non-production only (WP_DEBUG) so the flow can be tested
		// without an inbox. NEVER expose on live (WP_DEBUG must be false there).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$response['debug_code'] = $code;
		}
		return rest_ensure_response( $response );
	}

	/* ---------- Verify the code only (step 2) ---------- */

	public static function verify( WP_REST_Request $request ) {
		$user = CPC_REST_Auth::resolve_login( (string) $request['login'] );
		$code = trim( (string) $request['code'] );

		// Checks the code but does NOT consume it, so the app can move to the
		// new-password screen and submit the same code to /auth/reset-password.
		if ( ! self::code_is_valid( $user, $code ) ) {
			return new WP_Error( 'cpc_reset_invalid', 'Invalid or expired reset code.', array( 'status' => 400 ) );
		}

		return rest_ensure_response( array(
			'success'  => true,
			'verified' => true,
			'message'  => 'Code verified. You can now set a new password.',
		) );
	}

	/* ---------- Reset with the code (step 3) ---------- */

	public static function reset( WP_REST_Request $request ) {
		$user = CPC_REST_Auth::resolve_login( (string) $request['login'] );
		$code = trim( (string) $request['code'] );
		$new  = (string) $request['password'];

		if ( strlen( $new ) < 6 ) {
			return new WP_Error( 'cpc_weak_password', 'Password must be at least 6 characters.', array( 'status' => 400 ) );
		}
		if ( ! self::code_is_valid( $user, $code ) ) {
			return new WP_Error( 'cpc_reset_invalid', 'Invalid or expired reset code.', array( 'status' => 400 ) );
		}

		// Set the new password, clear the code, and invalidate all old tokens.
		wp_set_password( $new, $user->ID );
		delete_user_meta( $user->ID, self::CODE_META );
		delete_user_meta( $user->ID, self::EXP_META );
		self::bump_tokens( $user->ID );

		// Issue a fresh token so the app can log the user straight in.
		$jwt = CPC_JWT::issue( $user->ID );
		return rest_ensure_response( array(
			'success' => true,
			'message' => 'Password updated. You are now signed in.',
			'token'   => $jwt['token'],
			'user'    => CPC_REST_Auth::user_payload( $user->ID ),
		) );
	}

	/* ---------- Change (logged in) ---------- */

	public static function change( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		$current = (string) $request['current_password'];
		$new     = (string) $request['password'];

		if ( ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
			return new WP_Error( 'cpc_bad_current', 'Your current password is incorrect.', array( 'status' => 400 ) );
		}
		if ( strlen( $new ) < 6 ) {
			return new WP_Error( 'cpc_weak_password', 'Password must be at least 6 characters.', array( 'status' => 400 ) );
		}

		wp_set_password( $new, $user_id );
		self::bump_tokens( $user_id );

		// wp_set_password logs the user out of cookie sessions — give a fresh token.
		$jwt = CPC_JWT::issue( $user_id );
		return rest_ensure_response( array(
			'success' => true,
			'message' => 'Password changed successfully.',
			'token'   => $jwt['token'],
		) );
	}

	/* ---------- Helpers ---------- */

	/**
	 * Is this the right, unexpired reset code for this user? Read-only — never
	 * clears the code, so verify and reset can both call it with the same code.
	 */
	protected static function code_is_valid( $user, $code ) {
		if ( ! $user || '' === $code ) {
			return false;
		}
		$stored  = get_user_meta( $user->ID, self::CODE_META, true );
		$expires = (int) get_user_meta( $user->ID, self::EXP_META, true );

		return $stored && $expires >= time() && wp_check_password( $code, $stored, $user->ID );
	}

	protected static function bump_tokens( $user_id ) {
		$v = (int) get_user_meta( $user_id, 'cpc_token_version', true );
		update_user_meta( $user_id, 'cpc_token_version', $v + 1 );
	}

	protected static function send_code_email( $user, $code ) {
		$site = get_bloginfo( 'name' ) ?: 'Casa Prime';
		$subject = $site . ' — Password reset code';
		$message =
			"Hi " . ( $user->first_name ?: $user->display_name ) . ",\n\n" .
			"Your password reset code is:\n\n" .
			"    " . $code . "\n\n" .
			"Enter this code in the app to set a new password. It expires in 15 minutes.\n\n" .
			"If you didn't request this, you can safely ignore this email.\n\n" .
			"— " . $site;

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		wp_mail( $user->user_email, $subject, $message, $headers );
	}
}
