<?php
/**
 * Firebase Cloud Messaging — push notifications to the customer and rider apps.
 *
 * Talks to the FCM HTTP v1 API directly (OAuth2 via the Firebase service
 * account, no SDK). The service-account key lives in a PHP file that returns
 * the decoded JSON — a .php file so the web server executes rather than serves
 * it if anyone requests the URL. Default location wp-content/cpc-fcm-key.php,
 * overridable with the CPC_FCM_KEY_FILE constant in wp-config.php.
 *
 * Device tokens are kept per-user in the `cpc_fcm_tokens` user meta (a list —
 * one customer can be signed in on several devices).
 *
 * What triggers a push:
 *   processing → preparing        customer   "order confirmed"
 *   ready → out-for-delivery      customer   "on the way"
 *   out-for-delivery → delivered  customer   "delivered"
 *   cpc_rider_assigned            rider      "new delivery assigned"
 *
 * Every payload carries data {type:"order", order_id:"<id>"} (strings, per the
 * app developer's spec) so a tap opens the right screen.
 */

defined( 'ABSPATH' ) || exit;

class CPC_FCM {

	const META_TOKENS   = 'cpc_fcm_tokens';
	const TOKEN_CACHE   = 'cpc_fcm_access_token';
	const MAX_DEVICES   = 10;

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
		add_action( 'cpc_rider_assigned', array( __CLASS__, 'on_rider_assigned' ), 10, 2 );
	}

	/* ---------- Service-account key ---------- */

	public static function key_file() {
		return defined( 'CPC_FCM_KEY_FILE' ) ? CPC_FCM_KEY_FILE : WP_CONTENT_DIR . '/cpc-fcm-key.php';
	}

	/** The decoded service-account array, or null when not installed. */
	public static function service_account() {
		static $account = false;
		if ( false === $account ) {
			$file    = self::key_file();
			$account = is_readable( $file ) ? include $file : null;
			$account = ( is_array( $account ) && ! empty( $account['client_email'] ) && ! empty( $account['private_key'] ) && ! empty( $account['project_id'] ) )
				? $account : null;
		}
		return $account;
	}

	public static function is_configured() {
		return null !== self::service_account();
	}

	/* ---------- OAuth2 (JWT bearer grant) ---------- */

	/**
	 * A Google access token for the firebase.messaging scope. Cached in a
	 * transient just under its one-hour life so one login serves many pushes.
	 */
	public static function access_token() {
		$cached = get_transient( self::TOKEN_CACHE );
		if ( $cached ) {
			return $cached;
		}

		$sa = self::service_account();
		if ( ! $sa ) {
			return new WP_Error( 'cpc_fcm_no_key', 'Firebase service-account key is not installed.' );
		}

		$now    = time();
		$header = self::b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::b64url( wp_json_encode( array(
			'iss'   => $sa['client_email'],
			'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
			'aud'   => $sa['token_uri'],
			'iat'   => $now,
			'exp'   => $now + 3600,
		) ) );

		$signature = '';
		if ( ! openssl_sign( $header . '.' . $claims, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'cpc_fcm_sign_failed', 'Could not sign the OAuth JWT (check the private key).' );
		}
		$jwt = $header . '.' . $claims . '.' . self::b64url( $signature );

		$response = wp_remote_post( $sa['token_uri'], array(
			'timeout' => 15,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error(
				'cpc_fcm_oauth_failed',
				'Google OAuth rejected the service account: ' . ( $body['error_description'] ?? $body['error'] ?? 'unknown error' )
			);
		}

		$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 300 );
		set_transient( self::TOKEN_CACHE, $body['access_token'], $ttl );
		return $body['access_token'];
	}

	protected static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/* ---------- Device-token storage ---------- */

	/** All of a user's device tokens: array of {token, platform, added_at}. */
	public static function get_tokens( $user_id ) {
		$list = get_user_meta( $user_id, self::META_TOKENS, true );
		return is_array( $list ) ? $list : array();
	}

	/** Save a device token; re-registering an existing one just refreshes it. */
	public static function add_token( $user_id, $token, $platform = '' ) {
		$list = self::get_tokens( $user_id );
		foreach ( $list as $i => $row ) {
			if ( $row['token'] === $token ) {
				$list[ $i ]['platform'] = $platform ?: $row['platform'];
				update_user_meta( $user_id, self::META_TOKENS, array_values( $list ) );
				return count( $list );
			}
		}
		$list[] = array( 'token' => $token, 'platform' => $platform, 'added_at' => current_time( 'mysql', true ) );
		// Oldest devices fall off the end — a token cap, not a device ban.
		if ( count( $list ) > self::MAX_DEVICES ) {
			$list = array_slice( $list, - self::MAX_DEVICES );
		}
		update_user_meta( $user_id, self::META_TOKENS, array_values( $list ) );
		return count( $list );
	}

	/** Remove one token (logout on that device). True when something was removed. */
	public static function remove_token( $user_id, $token ) {
		$list = self::get_tokens( $user_id );
		$kept = array();
		foreach ( $list as $row ) {
			if ( $row['token'] !== $token ) {
				$kept[] = $row;
			}
		}
		if ( count( $kept ) === count( $list ) ) {
			return false;
		}
		if ( $kept ) {
			update_user_meta( $user_id, self::META_TOKENS, $kept );
		} else {
			delete_user_meta( $user_id, self::META_TOKENS );
		}
		return true;
	}

	/* ---------- Sending ---------- */

	/**
	 * Push to every device a user has registered. Returns how many sends FCM
	 * accepted. Dead tokens (uninstalled app) are pruned as FCM reports them.
	 */
	public static function notify_user( $user_id, $title, $body, array $data = array() ) {
		if ( ! self::is_configured() ) {
			return 0;
		}
		$tokens = self::get_tokens( $user_id );
		if ( ! $tokens ) {
			return 0;
		}

		$access = self::access_token();
		if ( is_wp_error( $access ) ) {
			// Log once per incident, never break the order flow over a push.
			error_log( 'CPC_FCM: ' . $access->get_error_message() );
			return 0;
		}

		$sent = 0;
		foreach ( $tokens as $row ) {
			$result = self::send_to_token( $access, $row['token'], $title, $body, $data );
			if ( true === $result ) {
				$sent++;
			} elseif ( 'dead_token' === $result ) {
				self::remove_token( $user_id, $row['token'] );
			}
		}
		return $sent;
	}

	/** true = accepted, 'dead_token' = unregistered device, false = other failure. */
	protected static function send_to_token( $access_token, $device_token, $title, $body, array $data ) {
		$sa      = self::service_account();
		$message = array(
			'message' => array(
				'token'        => $device_token,
				'notification' => array( 'title' => $title, 'body' => $body ),
				// FCM requires every data value to be a string.
				'data'         => array_map( 'strval', $data ),
				'android'      => array( 'priority' => 'high' ),
				'apns'         => array( 'payload' => array( 'aps' => array( 'sound' => 'default' ) ) ),
			),
		);

		$response = wp_remote_post(
			'https://fcm.googleapis.com/v1/projects/' . rawurlencode( $sa['project_id'] ) . '/messages:send',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $message ),
			)
		);
		if ( is_wp_error( $response ) ) {
			error_log( 'CPC_FCM send: ' . $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return true;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ), true );
		$status    = $body_json['error']['details'][0]['errorCode'] ?? ( $body_json['error']['status'] ?? '' );
		if ( 404 === $code || 'UNREGISTERED' === $status ) {
			return 'dead_token';
		}
		error_log( 'CPC_FCM send HTTP ' . $code . ': ' . ( $body_json['error']['message'] ?? 'unknown' ) );
		return false;
	}

	/* ---------- Order-event triggers ---------- */

	public static function on_status_changed( $order_id, $from, $to, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
		}
		$customer_id = (int) $order->get_customer_id();
		if ( ! $customer_id ) {
			return;
		}
		$number = $order->get_order_number();
		$data   = array( 'type' => 'order', 'order_id' => (string) $order->get_id() );

		switch ( $to ) {
			case 'preparing':
				// Only the initial accept — a re-entry from some other status
				// (admin fixing a mistake) should not re-congratulate.
				if ( 'processing' === $from ) {
					self::notify_user( $customer_id, 'Order confirmed! 🎉', 'Order #' . $number . ' is confirmed and being prepared.', $data );
				}
				break;
			case 'out-for-delivery':
				self::notify_user( $customer_id, 'Your order is on the way 🛵', 'Order #' . $number . ' is out for delivery.', $data );
				break;
			case 'delivered':
				self::notify_user( $customer_id, 'Order delivered ✅', 'Order #' . $number . ' has been delivered. Enjoy!', $data );
				break;
		}
	}

	public static function on_rider_assigned( $order, $rider_id ) {
		if ( ! $order instanceof WC_Order || ! $rider_id ) {
			return;
		}
		self::notify_user(
			(int) $rider_id,
			'New delivery assigned 📦',
			'Order #' . $order->get_order_number() . ' is ready for pickup at the store.',
			array( 'type' => 'order', 'order_id' => (string) $order->get_id() )
		);
	}
}
