<?php
/**
 * Minimal, self-contained JWT (HS256) for the mobile app auth.
 *
 * No external library / plugin: encodes and validates its own tokens, signed
 * with a server secret. The secret is CPC_JWT_SECRET (define in wp-config.php
 * for production); it falls back to the site's auth salt for local dev.
 *
 * Tokens carry: iss, iat, exp, sub (user id) and tv (token version — bumping
 * the user's cpc_token_version meta invalidates all their old tokens, e.g. on
 * password change or forced logout).
 */

defined( 'ABSPATH' ) || exit;

class CPC_JWT {

	const TTL = 7 * DAY_IN_SECONDS; // token lifetime

	protected static function secret() {
		if ( defined( 'CPC_JWT_SECRET' ) && CPC_JWT_SECRET ) {
			return CPC_JWT_SECRET;
		}
		return wp_salt( 'auth' ); // dev fallback
	}

	protected static function b64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	protected static function b64url_decode( $data ) {
		return base64_decode( strtr( $data, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $data ) % 4 ) % 4 ) );
	}

	/**
	 * Issue a signed token for a user.
	 */
	public static function issue( $user_id ) {
		$now = time();
		$header  = array( 'typ' => 'JWT', 'alg' => 'HS256' );
		$payload = array(
			'iss' => home_url(),
			'iat' => $now,
			'exp' => $now + self::TTL,
			'sub' => (int) $user_id,
			'tv'  => (int) get_user_meta( $user_id, 'cpc_token_version', true ),
		);

		$segments = array(
			self::b64url_encode( wp_json_encode( $header ) ),
			self::b64url_encode( wp_json_encode( $payload ) ),
		);
		$signing_input = implode( '.', $segments );
		$signature = hash_hmac( 'sha256', $signing_input, self::secret(), true );
		$segments[] = self::b64url_encode( $signature );

		return array(
			'token'      => implode( '.', $segments ),
			'expires_at' => gmdate( 'c', $payload['exp'] ),
			'expires_in' => self::TTL,
		);
	}

	/**
	 * Validate a token. Returns the user id on success, WP_Error on failure.
	 */
	public static function validate( $token ) {
		$parts = explode( '.', (string) $token );
		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'cpc_jwt_malformed', 'Malformed token.', array( 'status' => 401 ) );
		}
		list( $head64, $body64, $sig64 ) = $parts;

		$expected = hash_hmac( 'sha256', $head64 . '.' . $body64, self::secret(), true );
		if ( ! hash_equals( $expected, self::b64url_decode( $sig64 ) ) ) {
			return new WP_Error( 'cpc_jwt_bad_signature', 'Invalid token signature.', array( 'status' => 401 ) );
		}

		$payload = json_decode( self::b64url_decode( $body64 ), true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'cpc_jwt_bad_payload', 'Invalid token payload.', array( 'status' => 401 ) );
		}
		if ( isset( $payload['exp'] ) && time() >= (int) $payload['exp'] ) {
			return new WP_Error( 'cpc_jwt_expired', 'Token has expired. Please log in again.', array( 'status' => 401 ) );
		}
		$user_id = isset( $payload['sub'] ) ? (int) $payload['sub'] : 0;
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'cpc_jwt_no_user', 'Token user not found.', array( 'status' => 401 ) );
		}
		// Token-version check (invalidate old tokens after password change / logout-all).
		if ( (int) ( $payload['tv'] ?? 0 ) !== (int) get_user_meta( $user_id, 'cpc_token_version', true ) ) {
			return new WP_Error( 'cpc_jwt_revoked', 'Session expired. Please log in again.', array( 'status' => 401 ) );
		}

		return $user_id;
	}

	/**
	 * Pull the bearer token from the Authorization header (or ?access_token=).
	 */
	public static function get_token_from_request() {
		$auth = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		} elseif ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			foreach ( $headers as $k => $v ) {
				if ( 'authorization' === strtolower( $k ) ) { $auth = $v; break; }
			}
		}
		if ( $auth && preg_match( '/Bearer\s+(.+)/i', $auth, $m ) ) {
			return trim( $m[1] );
		}
		if ( ! empty( $_GET['access_token'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['access_token'] ) );
		}
		return '';
	}

	/**
	 * Authenticate REST requests carrying a Bearer token.
	 * Hooked on determine_current_user so every casa-prime endpoint (and the
	 * whole WP/Woo REST API) accepts the app's JWT.
	 */
	public static function authenticate( $user_id ) {
		if ( $user_id ) {
			return $user_id; // already authenticated (e.g. cookie)
		}
		$token = self::get_token_from_request();
		if ( ! $token ) {
			return $user_id;
		}
		$result = self::validate( $token );
		return is_wp_error( $result ) ? $user_id : $result;
	}
}
