<?php
/**
 * REST API — push-notification device tokens (casa-prime/v1). Login required.
 *
 * GET    /device-token                      list my registered devices (debug/verify)
 * POST   /device-token   {token, platform}  register this device for pushes
 * DELETE /device-token   {token}            unregister (app logout)
 *
 * A user keeps a LIST of tokens (phone + tablet both get the push); saving the
 * same token twice is a no-op, per the app developer's spec.
 */

defined( 'ABSPATH' ) || exit;

class CPC_REST_Device_Token {

	const REST_NAMESPACE = 'casa-prime/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function require_login() {
		return is_user_logged_in() ? true : new WP_Error( 'cpc_not_logged_in', 'Authentication required.', array( 'status' => 401 ) );
	}

	public static function register_routes() {
		register_rest_route( self::REST_NAMESPACE, '/device-token', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_tokens' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_token' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'args'                => array(
					'token'    => array( 'required' => true, 'type' => 'string' ),
					'platform' => array( 'required' => false, 'type' => 'string', 'enum' => array( 'android', 'ios' ) ),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_token' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'args'                => array(
					'token' => array( 'required' => true, 'type' => 'string' ),
				),
			),
		) );
	}

	public static function list_tokens( WP_REST_Request $request ) {
		$tokens = CPC_FCM::get_tokens( get_current_user_id() );
		return rest_ensure_response( array(
			'success'      => true,
			'device_count' => count( $tokens ),
			'data'         => array_values( array_map( function ( $t ) {
				return array(
					'token'      => $t['token'],
					'platform'   => $t['platform'],
					'registered' => $t['added_at'],
				);
			}, $tokens ) ),
		) );
	}

	public static function save_token( WP_REST_Request $request ) {
		$token = trim( (string) $request['token'] );
		if ( '' === $token || strlen( $token ) > 4096 ) {
			return new WP_Error( 'cpc_bad_token', 'Invalid device token.', array( 'status' => 400 ) );
		}
		$count = CPC_FCM::add_token( get_current_user_id(), $token, (string) ( $request['platform'] ?? '' ) );
		return rest_ensure_response( array( 'success' => true, 'device_count' => $count ) );
	}

	public static function delete_token( WP_REST_Request $request ) {
		$removed = CPC_FCM::remove_token( get_current_user_id(), trim( (string) $request['token'] ) );
		// Removing an already-gone token is fine — logout must never error.
		return rest_ensure_response( array( 'success' => true, 'removed' => (bool) $removed ) );
	}
}
