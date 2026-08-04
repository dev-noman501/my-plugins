<?php
/**
 * REST API — authentication (casa-prime/v1).
 *
 * POST /auth/register        name, email, phone, password → JWT + user
 * POST /auth/login           login (email OR phone) + password → JWT + user
 * GET  /auth/me              current user profile (Bearer token)
 * POST /auth/phone-verified  app confirms phone verified via Firebase → set flag
 * POST /auth/logout-all      bump token version → invalidate all this user's tokens
 *
 * Phone verification SMS is handled by the app (Firebase Phone Auth); the
 * backend only records the verified flag.
 */

defined( 'ABSPATH' ) || exit;

class CPC_REST_Auth {

	const REST_NAMESPACE = 'casa-prime/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$ns = self::REST_NAMESPACE;

		register_rest_route( $ns, '/auth/register', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'register_user' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'name'     => array( 'required' => true, 'type' => 'string' ),
				'email'    => array( 'required' => true, 'type' => 'string' ),
				'phone'    => array( 'required' => true, 'type' => 'string' ),
				'password' => array( 'required' => true, 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/auth/login', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'login' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'login'    => array( 'required' => true, 'type' => 'string', 'description' => 'Email or mobile number' ),
				'password' => array( 'required' => true, 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/auth/me', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'me' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		register_rest_route( $ns, '/auth/phone-verified', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'set_phone_verified' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
			'args'                => array(
				// Optional Firebase ID token — if provided we could verify it server-side later.
				'firebase_token' => array( 'required' => false, 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/auth/logout-all', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'logout_all' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );
	}

	public static function require_login() {
		return is_user_logged_in() ? true : new WP_Error( 'cpc_not_logged_in', 'Authentication required.', array( 'status' => 401 ) );
	}

	/* ---------- Register ---------- */

	public static function register_user( WP_REST_Request $request ) {
		$name  = sanitize_text_field( $request['name'] );
		$email = sanitize_email( $request['email'] );
		$phone = self::normalize_phone( $request['phone'] );
		$pass  = (string) $request['password'];

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'cpc_bad_email', 'Please enter a valid email address.', array( 'status' => 400 ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'cpc_email_taken', 'An account with this email already exists.', array( 'status' => 409 ) );
		}
		if ( strlen( $pass ) < 6 ) {
			return new WP_Error( 'cpc_weak_password', 'Password must be at least 6 characters.', array( 'status' => 400 ) );
		}
		if ( $phone && self::find_user_by_phone( $phone ) ) {
			return new WP_Error( 'cpc_phone_taken', 'An account with this mobile number already exists.', array( 'status' => 409 ) );
		}

		$user_id = wp_insert_user( array(
			'user_login'   => self::unique_login_from_email( $email ),
			'user_email'   => $email,
			'user_pass'    => $pass,
			'display_name' => $name,
			'first_name'   => self::first_name( $name ),
			'last_name'    => self::last_name( $name ),
			'role'         => 'customer',
		) );
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'cpc_register_failed', $user_id->get_error_message(), array( 'status' => 400 ) );
		}

		update_user_meta( $user_id, 'billing_phone', $phone );
		update_user_meta( $user_id, 'cpc_phone', $phone );
		update_user_meta( $user_id, 'cpc_phone_verified', 0 );
		update_user_meta( $user_id, 'billing_first_name', self::first_name( $name ) );
		update_user_meta( $user_id, 'billing_last_name', self::last_name( $name ) );
		update_user_meta( $user_id, 'billing_email', $email );

		return self::auth_response( $user_id, 201 );
	}

	/* ---------- Login (email or phone) ---------- */

	public static function login( WP_REST_Request $request ) {
		$user     = self::resolve_login( trim( (string) $request['login'] ) );
		$password = (string) $request['password'];

		if ( ! $user || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return new WP_Error( 'cpc_bad_credentials', 'Wrong email/mobile or password.', array( 'status' => 401 ) );
		}

		return self::auth_response( $user->ID, 200 );
	}

	/**
	 * Resolve an email OR mobile number (OR username) to a WP_User. Public so
	 * the password-reset endpoints can reuse the same lookup. Returns false if
	 * not found.
	 */
	public static function resolve_login( $identifier ) {
		$identifier = trim( (string) $identifier );
		if ( is_email( $identifier ) ) {
			return get_user_by( 'email', $identifier ) ?: false;
		}
		$user = self::find_user_by_phone( self::normalize_phone( $identifier ) );
		if ( $user ) {
			return $user;
		}
		return get_user_by( 'login', $identifier ) ?: false;
	}

	/* ---------- Current user ---------- */

	public static function me( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'success' => true, 'user' => self::user_payload( get_current_user_id() ) ) );
	}

	/* ---------- Phone verified (app calls after Firebase) ---------- */

	public static function set_phone_verified( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		// NOTE: if a firebase_token is sent we can verify it against Firebase here later.
		update_user_meta( $user_id, 'cpc_phone_verified', 1 );
		return rest_ensure_response( array( 'success' => true, 'phone_verified' => true ) );
	}

	/* ---------- Logout everywhere ---------- */

	public static function logout_all( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$version = (int) get_user_meta( $user_id, 'cpc_token_version', true );
		update_user_meta( $user_id, 'cpc_token_version', $version + 1 );
		return rest_ensure_response( array( 'success' => true, 'message' => 'All sessions signed out.' ) );
	}

	/* ---------- Helpers ---------- */

	protected static function auth_response( $user_id, $status ) {
		$jwt = CPC_JWT::issue( $user_id );
		$response = rest_ensure_response( array(
			'success'    => true,
			'token'      => $jwt['token'],
			'expires_at' => $jwt['expires_at'],
			'expires_in' => $jwt['expires_in'],
			'user'       => self::user_payload( $user_id ),
		) );
		$response->set_status( $status );
		return $response;
	}

	public static function user_payload( $user_id ) {
		$u = get_userdata( $user_id );

		// Saved-address count → the app makes address mandatory: if a customer has
		// none, the app routes them (back) to the address screen after login.
		$addresses = get_user_meta( $u->ID, 'cpc_addresses', true );
		$address_count = is_array( $addresses ) ? count( $addresses ) : 0;

		return array(
			'id'                 => $u->ID,
			'name'               => $u->display_name,
			'first_name'         => $u->first_name,
			'last_name'          => $u->last_name,
			'email'              => $u->user_email,
			'phone'              => get_user_meta( $u->ID, 'cpc_phone', true ),
			'phone_verified'     => (bool) get_user_meta( $u->ID, 'cpc_phone_verified', true ),
			'role'               => ! empty( $u->roles ) ? $u->roles[0] : 'customer',
			'has_address'        => $address_count > 0,
			'address_count'      => $address_count,
			'onboarding_complete'=> ( (bool) get_user_meta( $u->ID, 'cpc_phone_verified', true ) ) && $address_count > 0,
		);
	}

	/**
	 * Canonical phone form for storage & matching: digits only.
	 * "+1-713-555-0102" and "(713) 555 0102" both become comparable.
	 */
	protected static function normalize_phone( $phone ) {
		return preg_replace( '/[^0-9]/', '', (string) $phone );
	}

	protected static function find_user_by_phone( $phone ) {
		$digits = self::normalize_phone( $phone );
		if ( strlen( $digits ) < 7 ) {
			return false;
		}

		// Exact match on the canonical digits-only meta.
		$users = get_users( array( 'meta_key' => 'cpc_phone', 'meta_value' => $digits, 'number' => 1, 'fields' => 'all' ) );
		if ( ! empty( $users ) ) {
			return $users[0];
		}

		// Fallback: match the last 10 digits against billing_phone (handles seeded/legacy
		// numbers stored with dashes and/or a country code).
		$last10 = substr( $digits, -10 );
		$candidates = get_users( array(
			'meta_query' => array(
				array( 'key' => 'billing_phone', 'value' => $last10, 'compare' => 'LIKE' ),
			),
			'number' => 5,
			'fields' => 'all',
		) );
		foreach ( $candidates as $u ) {
			$stored = self::normalize_phone( get_user_meta( $u->ID, 'billing_phone', true ) );
			if ( $stored && substr( $stored, -10 ) === $last10 ) {
				return $u;
			}
		}
		return false;
	}

	protected static function unique_login_from_email( $email ) {
		$base = sanitize_user( current( explode( '@', $email ) ), true );
		$login = $base;
		$i = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			$i++;
		}
		return $login;
	}

	protected static function first_name( $name ) {
		$parts = preg_split( '/\s+/', trim( $name ) );
		return $parts[0] ?? $name;
	}

	protected static function last_name( $name ) {
		$parts = preg_split( '/\s+/', trim( $name ) );
		return count( $parts ) > 1 ? implode( ' ', array_slice( $parts, 1 ) ) : '';
	}
}
