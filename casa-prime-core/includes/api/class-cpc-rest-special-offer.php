<?php
/**
 * REST API — today's special (casa-prime/v1).
 *
 * GET /special-offer  → the single home-screen promo banner (public, no auth).
 *
 * Always returns 200 with an `active` flag rather than 404 when nothing is on,
 * so the app has one code path: read active, show or hide the card.
 */

defined( 'ABSPATH' ) || exit;

class CPC_REST_Special_Offer {

	const REST_NAMESPACE = 'casa-prime/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( self::REST_NAMESPACE, '/special-offer', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_offer' ),
			'permission_callback' => '__return_true', // home screen, shown before login
		) );
	}

	public static function get_offer( WP_REST_Request $request ) {
		return rest_ensure_response( array(
			'success' => true,
			'data'    => CPC_Special_Offer::get_offer(),
		) );
	}
}
