<?php
/**
 * REST API — cart (casa-prime/v1). All endpoints require a logged-in customer.
 *
 * GET    /cart               view cart + totals
 * POST   /cart/items         add item  {product_id, amount, cut?, instructions?}
 * PUT    /cart/items/{key}   update item {amount?, cut?, instructions?}
 * DELETE /cart/items/{key}   remove item
 * DELETE /cart               empty the cart
 */

defined( 'ABSPATH' ) || exit;

class CPC_REST_Cart {

	const REST_NAMESPACE = 'casa-prime/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function require_login() {
		return is_user_logged_in() ? true : new WP_Error( 'cpc_not_logged_in', 'Authentication required.', array( 'status' => 401 ) );
	}

	public static function register_routes() {
		$ns   = self::REST_NAMESPACE;
		$auth = array( __CLASS__, 'require_login' );

		register_rest_route( $ns, '/cart', array(
			array( 'methods' => WP_REST_Server::READABLE,  'callback' => array( __CLASS__, 'get_cart' ),   'permission_callback' => $auth ),
			array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( __CLASS__, 'empty_cart' ), 'permission_callback' => $auth ),
		) );

		register_rest_route( $ns, '/cart/items', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'add_item' ),
			'permission_callback' => $auth,
			'args'                => array(
				'product_id'   => array( 'required' => true, 'type' => 'integer' ),
				'amount'       => array( 'required' => true, 'type' => 'number', 'description' => 'Weight in lb (weight items) or quantity (each items)' ),
				'cut'          => array( 'required' => false, 'type' => 'string' ),
				'instructions' => array( 'required' => false, 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/cart/items/(?P<key>[a-z0-9]+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_item' ),
				'permission_callback' => $auth,
				'args'                => array(
					'amount'       => array( 'required' => false, 'type' => 'number' ),
					'cut'          => array( 'required' => false, 'type' => 'string' ),
					'instructions' => array( 'required' => false, 'type' => 'string' ),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'remove_item' ),
				'permission_callback' => $auth,
			),
		) );

		register_rest_route( $ns, '/cart/coupon', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'apply_coupon' ),
				'permission_callback' => $auth,
				'args'                => array( 'code' => array( 'required' => true, 'type' => 'string' ) ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'remove_coupon' ),
				'permission_callback' => $auth,
			),
		) );
	}

	public static function get_cart() {
		return rest_ensure_response( array( 'success' => true, 'cart' => CPC_Cart::format( get_current_user_id() ) ) );
	}

	public static function add_item( WP_REST_Request $r ) {
		$res = CPC_Cart::add( get_current_user_id(), (int) $r['product_id'], (float) $r['amount'], (string) ( $r['cut'] ?? '' ), (string) ( $r['instructions'] ?? '' ) );
		if ( is_wp_error( $res ) ) { return $res; }
		return self::cart_response( 'Item added to cart.', $res );
	}

	public static function update_item( WP_REST_Request $r ) {
		$res = CPC_Cart::update(
			get_current_user_id(),
			sanitize_key( $r['key'] ),
			isset( $r['amount'] ) ? (float) $r['amount'] : null,
			isset( $r['cut'] ) ? (string) $r['cut'] : null,
			isset( $r['instructions'] ) ? (string) $r['instructions'] : null
		);
		if ( is_wp_error( $res ) ) { return $res; }
		return self::cart_response( 'Cart updated.', $res );
	}

	public static function remove_item( WP_REST_Request $r ) {
		$res = CPC_Cart::remove( get_current_user_id(), sanitize_key( $r['key'] ) );
		if ( is_wp_error( $res ) ) { return $res; }
		return self::cart_response( 'Item removed.' );
	}

	public static function empty_cart() {
		CPC_Cart::clear( get_current_user_id() );
		return self::cart_response( 'Cart emptied.' );
	}

	public static function apply_coupon( WP_REST_Request $r ) {
		$result = CPC_Coupons::apply( get_current_user_id(), (string) $r['code'] );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::cart_response( 'Coupon applied.' );
	}

	public static function remove_coupon() {
		CPC_Coupons::remove( get_current_user_id() );
		return self::cart_response( 'Coupon removed.' );
	}

	protected static function cart_response( $message, $item_key = null ) {
		$data = array( 'success' => true, 'message' => $message, 'cart' => CPC_Cart::format( get_current_user_id() ) );
		if ( $item_key ) { $data['item_key'] = $item_key; }
		return rest_ensure_response( $data );
	}
}
