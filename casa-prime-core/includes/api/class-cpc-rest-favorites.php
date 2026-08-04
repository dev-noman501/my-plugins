<?php
/**
 * REST API — favourites / wishlist (casa-prime/v1).
 *
 * The heart icon in the app. A customer's favourites are a list of product IDs
 * in user meta; the list endpoint returns full product objects (same shape as
 * /products) so the wishlist screen needs no extra calls.
 *
 * GET    /favorites               list favourited products
 * POST   /favorites/{product_id}  add one
 * DELETE /favorites/{product_id}  remove one
 *
 * Adding is idempotent (adding twice is fine); the product must exist and be
 * published. Deleted / unpublished products are dropped from the list on read,
 * so the wishlist never shows a dead item.
 */

defined( 'ABSPATH' ) || exit;

class CPC_REST_Favorites {

	const REST_NAMESPACE = 'casa-prime/v1';
	const META_KEY       = 'cpc_favorites';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$ns   = self::REST_NAMESPACE;
		$auth = function () { return is_user_logged_in(); };

		register_rest_route( $ns, '/favorites', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_favorites' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( $ns, '/favorites/(?P<product_id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'add_favorite' ),
				'permission_callback' => $auth,
				'args'                => array( 'product_id' => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ) ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'remove_favorite' ),
				'permission_callback' => $auth,
				'args'                => array( 'product_id' => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ) ),
			),
		) );
	}

	/* ---------- Storage ---------- */

	/** Raw list of favourited product IDs (ints), most-recent first. */
	public static function get_ids( $user_id ) {
		$ids = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : array();
	}

	protected static function save_ids( $user_id, $ids ) {
		update_user_meta( $user_id, self::META_KEY, array_values( array_unique( array_map( 'intval', $ids ) ) ) );
	}

	/** Whether a product is in the user's favourites (used by the products API). */
	public static function is_favorite( $user_id, $product_id ) {
		return $user_id && in_array( (int) $product_id, self::get_ids( $user_id ), true );
	}

	/* ---------- Endpoints ---------- */

	public static function list_favorites( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$ids     = self::get_ids( $user_id );

		$data  = array();
		$clean = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			// Skip — and forget — products that have gone away, so the wishlist
			// self-heals instead of showing broken tiles.
			if ( ! $product || 'publish' !== $product->get_status() ) {
				continue;
			}
			$clean[] = $id;
			$data[]  = CPC_REST_Products::format_product( $product );
		}

		if ( count( $clean ) !== count( $ids ) ) {
			self::save_ids( $user_id, $clean );
		}

		return rest_ensure_response( array(
			'success' => true,
			'count'   => count( $data ),
			'data'    => $data,
		) );
	}

	public static function add_favorite( WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$product_id = (int) $request['product_id'];

		$product = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_Error( 'cpc_bad_product', 'Product not found.', array( 'status' => 404 ) );
		}

		$ids = self::get_ids( $user_id );
		if ( ! in_array( $product_id, $ids, true ) ) {
			array_unshift( $ids, $product_id ); // newest first
			self::save_ids( $user_id, $ids );
		}

		return rest_ensure_response( array(
			'success'      => true,
			'is_favorite'  => true,
			'product_id'   => $product_id,
			'count'        => count( self::get_ids( $user_id ) ),
		) );
	}

	public static function remove_favorite( WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$product_id = (int) $request['product_id'];

		$ids = array_values( array_diff( self::get_ids( $user_id ), array( $product_id ) ) );
		self::save_ids( $user_id, $ids );

		return rest_ensure_response( array(
			'success'      => true,
			'is_favorite'  => false,
			'product_id'   => $product_id,
			'count'        => count( $ids ),
		) );
	}
}
