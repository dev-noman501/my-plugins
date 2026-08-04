<?php
/**
 * REST API — delivery quote endpoint (casa-prime/v1 namespace).
 *
 * GET /wp-json/casa-prime/v1/delivery/quote?lat=..&lng=..&subtotal=..
 *
 * Public: it only prices a hypothetical delivery, exposes no private data.
 * The customer app calls this from the address screen / checkout; the same
 * engine re-validates server-side when the order is actually placed.
 */

defined( 'ABSPATH' ) || exit;

class CPC_REST_Delivery {

	const REST_NAMESPACE = 'casa-prime/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( self::REST_NAMESPACE, '/delivery/quote', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_quote' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'lat' => array(
					'required'          => true,
					'type'              => 'number',
					'validate_callback' => function ( $v ) { return is_numeric( $v ) && $v >= -90 && $v <= 90; },
				),
				'lng' => array(
					'required'          => true,
					'type'              => 'number',
					'validate_callback' => function ( $v ) { return is_numeric( $v ) && $v >= -180 && $v <= 180; },
				),
				'subtotal' => array(
					'required' => false,
					'type'     => 'number',
					'default'  => 0,
				),
			),
		) );

		// The days a customer may choose at checkout. Served from the server so
		// the app never hard-codes how far ahead booking is open.
		register_rest_route( self::REST_NAMESPACE, '/delivery/dates', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_dates' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function get_dates( WP_REST_Request $request ) {
		return rest_ensure_response( array(
			'success' => true,
			'data'    => CPC_Delivery_Date::selectable_days(),
		) );
	}

	public static function get_quote( WP_REST_Request $request ) {
		$quote = CPC_Delivery_Engine::quote(
			(float) $request['lat'],
			(float) $request['lng'],
			(float) $request['subtotal']
		);

		// Clean float precision for JSON output (4.99, not 4.9900000000000002).
		@ini_set( 'serialize_precision', '-1' );
		$quote['distance_miles'] = (float) number_format( $quote['distance_miles'], 2, '.', '' );
		if ( null !== $quote['fee'] ) {
			$quote['fee'] = (float) number_format( $quote['fee'], 2, '.', '' );
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => $quote,
		) );
	}
}
