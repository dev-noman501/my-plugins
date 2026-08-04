<?php
/**
 * Stripe payments — exact-charge card flow for the customer app.
 *
 * Talks to Stripe's REST API directly (wp_remote_*, no SDK — same approach as
 * FCM). Keys live in wp-config.php, never the DB:
 *
 *   define( 'CPC_STRIPE_SECRET_KEY',      'sk_test_...' );
 *   define( 'CPC_STRIPE_PUBLISHABLE_KEY', 'pk_test_...' );
 *   define( 'CPC_STRIPE_WEBHOOK_SECRET',  'whsec_...' );   // optional but recommended
 *
 * Flow (client's exact-charge decision — no auth+buffer, no adjustment):
 *   POST /checkout {card}                    → order pending, needs_payment
 *   POST /orders/{id}/create-payment-intent  → client_secret for the app's PaymentSheet
 *   (payment happens on the device via Stripe — cards never touch this server)
 *   POST /orders/{id}/confirm-payment        → server VERIFIES with Stripe, then processing
 *   POST /stripe/webhook                     → safety net if the app dies mid-flow
 *
 * GET /config is public and tells the app whether card payment is on and which
 * publishable key to boot the Stripe SDK with.
 *
 * Until the keys are defined, is_configured() is false and confirm-payment
 * falls back to the old trust-the-app stub so testing continues — the moment
 * keys land in wp-config, verification turns strict automatically.
 */

defined( 'ABSPATH' ) || exit;

class CPC_Stripe {

	const REST_NAMESPACE = 'casa-prime/v1';
	const API            = 'https://api.stripe.com/v1/';
	const META_INTENT    = '_cpc_stripe_pi_id';
	const META_REFUND    = '_cpc_stripe_refund_id';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/* ---------- Keys ---------- */

	public static function secret_key() {
		return defined( 'CPC_STRIPE_SECRET_KEY' ) ? CPC_STRIPE_SECRET_KEY : '';
	}

	public static function publishable_key() {
		return defined( 'CPC_STRIPE_PUBLISHABLE_KEY' ) ? CPC_STRIPE_PUBLISHABLE_KEY : '';
	}

	public static function webhook_secret() {
		return defined( 'CPC_STRIPE_WEBHOOK_SECRET' ) ? CPC_STRIPE_WEBHOOK_SECRET : '';
	}

	public static function is_configured() {
		return '' !== self::secret_key() && '' !== self::publishable_key();
	}

	/* ---------- Stripe API ---------- */

	/**
	 * One call to Stripe. $body is form-encoded the way Stripe expects
	 * (nested arrays become metadata[order_id]=... style keys).
	 */
	protected static function api( $method, $path, array $body = array() ) {
		$response = wp_remote_request( self::API . $path, array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . self::secret_key(),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => $body ? http_build_query( $body ) : null,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || isset( $data['error'] ) ) {
			return new WP_Error(
				'cpc_stripe_api',
				$data['error']['message'] ?? ( 'Stripe error (HTTP ' . $code . ')' ),
				array( 'status' => 502 )
			);
		}
		return $data;
	}

	/** Order total in the smallest currency unit, exactly as shown at checkout. */
	public static function order_amount( $order ) {
		return (int) round( (float) $order->get_total() * 100 );
	}

	/* ---------- Routes ---------- */

	public static function register_routes() {
		$ns = self::REST_NAMESPACE;

		// Public app bootstrap: is card payment on, and with which key?
		register_rest_route( $ns, '/config', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_config' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/orders/(?P<id>\d+)/create-payment-intent', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'create_payment_intent' ),
			'permission_callback' => function () {
				return is_user_logged_in() ? true : new WP_Error( 'cpc_not_logged_in', 'Authentication required.', array( 'status' => 401 ) );
			},
			'args'                => array( 'id' => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ) ),
		) );

		// Stripe calls this; auth is the signature check, not a login.
		register_rest_route( $ns, '/stripe/webhook', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function get_config( WP_REST_Request $request ) {
		return rest_ensure_response( array(
			'success'         => true,
			'currency'        => get_woocommerce_currency(),
			'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'exact_charge'    => true,
			'payments'        => array(
				'cod'  => true,
				'card' => self::is_configured(),
			),
			'stripe'          => array(
				'enabled'         => self::is_configured(),
				'publishable_key' => self::publishable_key() ?: null,
			),
		) );
	}

	/**
	 * A PaymentIntent for a pending card order. Re-calling returns the same
	 * intent while the amount still matches, so a retried payment screen never
	 * double-creates.
	 */
	public static function create_payment_intent( WP_REST_Request $request ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'cpc_stripe_off', 'Card payments are not configured yet.', array( 'status' => 501 ) );
		}

		$order = CPC_REST_Checkout::owned_order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'pending' !== $order->get_status() ) {
			return new WP_Error( 'cpc_not_pending', 'This order is not awaiting payment.', array( 'status' => 409 ) );
		}

		$amount   = self::order_amount( $order );
		$currency = strtolower( $order->get_currency() );

		// Reuse the intent the order already has, while it still fits.
		$pi_id = $order->get_meta( self::META_INTENT );
		if ( $pi_id ) {
			$pi = self::api( 'GET', 'payment_intents/' . rawurlencode( $pi_id ) );
			if ( ! is_wp_error( $pi )
				&& (int) $pi['amount'] === $amount
				&& ! in_array( $pi['status'], array( 'canceled', 'succeeded' ), true ) ) {
				return self::intent_response( $order, $pi );
			}
		}

		$pi = self::api( 'POST', 'payment_intents', array(
			'amount'   => $amount,
			'currency' => $currency,
			'metadata' => array(
				'order_id'     => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'site'         => home_url(),
			),
			'automatic_payment_methods' => array( 'enabled' => 'true' ),
			'description' => 'Casa Prime order #' . $order->get_order_number(),
		) );
		if ( is_wp_error( $pi ) ) { return $pi; }

		$order->update_meta_data( self::META_INTENT, $pi['id'] );
		$order->save();

		return self::intent_response( $order, $pi );
	}

	protected static function intent_response( $order, $pi ) {
		return rest_ensure_response( array(
			'success'         => true,
			'order_id'        => $order->get_id(),
			'payment_intent'  => $pi['id'],
			'client_secret'   => $pi['client_secret'],
			'amount'          => (int) $pi['amount'],
			'currency'        => $pi['currency'],
			'publishable_key' => self::publishable_key(),
		) );
	}

	/**
	 * The hard check confirm-payment relies on: did Stripe really take the
	 * money for THIS order? Returns the intent array or a WP_Error.
	 */
	public static function verify_order_paid( $order ) {
		$pi_id = $order->get_meta( self::META_INTENT );
		if ( ! $pi_id ) {
			return new WP_Error( 'cpc_no_intent', 'No payment was started for this order — call create-payment-intent first.', array( 'status' => 402 ) );
		}

		$pi = self::api( 'GET', 'payment_intents/' . rawurlencode( $pi_id ) );
		if ( is_wp_error( $pi ) ) { return $pi; }

		if ( 'succeeded' !== $pi['status'] ) {
			return new WP_Error( 'cpc_not_paid', 'Payment has not completed (status: ' . $pi['status'] . ').', array( 'status' => 402 ) );
		}
		if ( (int) $pi['amount_received'] < self::order_amount( $order ) ) {
			return new WP_Error( 'cpc_amount_mismatch', 'Paid amount does not match the order total.', array( 'status' => 402 ) );
		}
		return $pi;
	}

	/**
	 * Refund a paid card order in full (used when a failed delivery is
	 * cancelled). Returns the refund array or a WP_Error.
	 */
	public static function refund_order( $order ) {
		$pi_id = $order->get_meta( self::META_INTENT );
		if ( ! self::is_configured() || ! $pi_id ) {
			return new WP_Error( 'cpc_no_intent', 'No Stripe payment on this order.' );
		}
		$refund = self::api( 'POST', 'refunds', array(
			'payment_intent' => $pi_id,
			'metadata'       => array( 'order_id' => $order->get_id() ),
		) );
		if ( is_wp_error( $refund ) ) { return $refund; }

		$order->update_meta_data( self::META_REFUND, $refund['id'] );
		$order->save();
		return $refund;
	}

	/* ---------- Webhook (safety net) ---------- */

	/**
	 * Stripe posts events here. Only payment_intent.succeeded is acted on: if
	 * the app crashed before calling confirm-payment, the order still gets
	 * marked paid. Everything else is acknowledged and ignored.
	 */
	public static function webhook( WP_REST_Request $request ) {
		$secret = self::webhook_secret();
		if ( ! $secret ) {
			return new WP_Error( 'cpc_webhook_off', 'Webhook secret not configured.', array( 'status' => 501 ) );
		}

		$payload   = $request->get_body();
		$signature = (string) $request->get_header( 'stripe_signature' );
		if ( ! self::valid_signature( $payload, $signature, $secret ) ) {
			return new WP_Error( 'cpc_bad_signature', 'Invalid webhook signature.', array( 'status' => 401 ) );
		}

		$event = json_decode( $payload, true );
		if ( 'payment_intent.succeeded' !== ( $event['type'] ?? '' ) ) {
			return rest_ensure_response( array( 'received' => true ) ); // not ours, all good
		}

		$pi       = $event['data']['object'] ?? array();
		$order_id = (int) ( $pi['metadata']['order_id'] ?? 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( $order && 'pending' === $order->get_status()
			&& $order->get_meta( self::META_INTENT ) === ( $pi['id'] ?? '' )
			&& (int) $pi['amount_received'] >= self::order_amount( $order ) ) {

			if ( function_exists( 'WC' ) ) { WC()->mailer(); } // confirmation email fires on the transition
			$order->payment_complete( $pi['id'] );
			$order->set_status( 'processing' );
			$order->add_order_note( 'Payment confirmed by Stripe webhook (' . $pi['id'] . ').' );
			$order->save();
		}

		return rest_ensure_response( array( 'received' => true ) );
	}

	/**
	 * Stripe-Signature: t=timestamp,v1=hmac[,v1=...]. HMAC-SHA256 of
	 * "{t}.{payload}" with the webhook secret; 5-minute replay window.
	 */
	protected static function valid_signature( $payload, $header, $secret ) {
		$timestamp = null;
		$hashes    = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) { continue; }
			if ( 't' === $kv[0] )  { $timestamp = (int) $kv[1]; }
			if ( 'v1' === $kv[0] ) { $hashes[] = $kv[1]; }
		}
		if ( ! $timestamp || ! $hashes || abs( time() - $timestamp ) > 5 * MINUTE_IN_SECONDS ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $hashes as $hash ) {
			if ( hash_equals( $expected, $hash ) ) {
				return true;
			}
		}
		return false;
	}
}
