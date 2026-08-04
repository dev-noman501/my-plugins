<?php
/**
 * REST API — customer saved addresses (casa-prime/v1).
 *
 * The app's address screen supports multiple saved addresses (Home, Work…),
 * each with an apartment/unit, delivery notes and a map pin (lat/lng). Stored
 * as a list in user meta `cpc_addresses`.
 *
 * GET    /addresses            list my addresses
 * POST   /addresses            add an address (returns it + delivery quote)
 * PUT    /addresses/{id}       update an address
 * DELETE /addresses/{id}       remove an address
 * POST   /addresses/{id}/default   set as default
 */

defined( 'ABSPATH' ) || exit;

class CPC_REST_Address {

	const REST_NAMESPACE = 'casa-prime/v1';
	const META_KEY       = 'cpc_addresses';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$ns   = self::REST_NAMESPACE;
		$auth = array( __CLASS__, 'require_login' );

		register_rest_route( $ns, '/addresses', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_addresses' ),
				'permission_callback' => $auth,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'add_address' ),
				'permission_callback' => $auth,
				'args'                => self::address_args(),
			),
		) );

		register_rest_route( $ns, '/addresses/(?P<id>[a-z0-9]+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'update_address' ),
				'permission_callback' => $auth,
				'args'                => self::address_args(),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_address' ),
				'permission_callback' => $auth,
			),
		) );

		register_rest_route( $ns, '/addresses/(?P<id>[a-z0-9]+)/default', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'set_default' ),
			'permission_callback' => $auth,
		) );
	}

	public static function require_login() {
		return is_user_logged_in() ? true : new WP_Error( 'cpc_not_logged_in', 'Authentication required.', array( 'status' => 401 ) );
	}

	protected static function address_args() {
		return array(
			'label'      => array( 'type' => 'string', 'required' => false ),  // Home | Work | Other
			'address_1'  => array( 'type' => 'string', 'required' => true ),
			'apt'        => array( 'type' => 'string', 'required' => false ),
			'city'       => array( 'type' => 'string', 'required' => false ),
			'state'      => array( 'type' => 'string', 'required' => false ),
			'postcode'   => array( 'type' => 'string', 'required' => false ),
			'country'    => array( 'type' => 'string', 'required' => false ),
			'notes'      => array( 'type' => 'string', 'required' => false ),
			'lat'        => array( 'type' => 'number', 'required' => false ),
			'lng'        => array( 'type' => 'number', 'required' => false ),
			'is_default' => array( 'type' => 'boolean', 'required' => false ),
		);
	}

	/* ---------- CRUD ---------- */

	public static function list_addresses( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'success' => true, 'data' => self::decorate( self::get_all( get_current_user_id() ) ) ) );
	}

	public static function add_address( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$list    = self::get_all( $user_id );

		$address = self::sanitize_address( $request );
		$address['id'] = self::new_id();
		if ( empty( $list ) ) {
			$address['is_default'] = true; // first one is default
		}
		if ( ! empty( $address['is_default'] ) ) {
			$list = self::clear_defaults( $list );
		}
		$list[] = $address;
		self::save_all( $user_id, $list );

		// Also mirror the default into Woo's shipping fields + the map-pin meta used at checkout.
		if ( ! empty( $address['is_default'] ) ) {
			self::sync_default_to_woo( $user_id, $address );
		}

		// The saved copy stays raw; the response carries the delivery verdict.
		$response = array( 'success' => true, 'data' => self::with_delivery( $address ) );
		// Kept for older clients that read the separate `delivery` object.
		if ( $address['lat'] && $address['lng'] ) {
			$response['delivery'] = self::quote_cached( (float) $address['lat'], (float) $address['lng'] );
		}
		$res = rest_ensure_response( $response );
		$res->set_status( 201 );
		return $res;
	}

	public static function update_address( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$id      = sanitize_key( $request['id'] );
		$list    = self::get_all( $user_id );

		$found = false;
		foreach ( $list as &$addr ) {
			if ( $addr['id'] === $id ) {
				$updated = self::sanitize_address( $request );
				$updated['id'] = $id;
				if ( ! empty( $updated['is_default'] ) ) {
					$list = self::clear_defaults( $list );
				} else {
					$updated['is_default'] = $addr['is_default']; // keep existing flag if not set
				}
				$addr = $updated;
				$found = true;
				break;
			}
		}
		unset( $addr );
		if ( ! $found ) {
			return new WP_Error( 'cpc_addr_not_found', 'Address not found.', array( 'status' => 404 ) );
		}
		self::save_all( $user_id, $list );
		return rest_ensure_response( array( 'success' => true, 'data' => self::decorate( self::get_all( $user_id ) ) ) );
	}

	public static function delete_address( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$id      = sanitize_key( $request['id'] );
		$list    = self::get_all( $user_id );

		$new = array_values( array_filter( $list, function ( $a ) use ( $id ) { return $a['id'] !== $id; } ) );
		if ( count( $new ) === count( $list ) ) {
			return new WP_Error( 'cpc_addr_not_found', 'Address not found.', array( 'status' => 404 ) );
		}
		// If we removed the default, promote the first remaining one.
		if ( $new && ! self::has_default( $new ) ) {
			$new[0]['is_default'] = true;
		}
		self::save_all( $user_id, $new );
		return rest_ensure_response( array( 'success' => true, 'data' => self::decorate( $new ) ) );
	}

	public static function set_default( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$id      = sanitize_key( $request['id'] );
		$list    = self::get_all( $user_id );

		$found = null;
		foreach ( $list as &$addr ) {
			$addr['is_default'] = ( $addr['id'] === $id );
			if ( $addr['is_default'] ) { $found = $addr; }
		}
		unset( $addr );
		if ( ! $found ) {
			return new WP_Error( 'cpc_addr_not_found', 'Address not found.', array( 'status' => 404 ) );
		}
		self::save_all( $user_id, $list );
		self::sync_default_to_woo( $user_id, $found );
		return rest_ensure_response( array( 'success' => true, 'data' => self::decorate( $list ) ) );
	}

	/* ---------- Delivery info on each address ---------- */

	/**
	 * Attach the delivery verdict to an address so the app can show its
	 * "Free delivery ✓" chip straight from the saved-address list, without a
	 * second call per address.
	 *
	 * Computed at output time, never stored: the store pin, the tiers and the
	 * max range can all change in settings, and a saved copy would go stale.
	 */
	protected static function with_delivery( $address ) {
		$address['is_within_range']  = false;
		$address['free_delivery']    = false;
		$address['delivery_fee']     = null;
		$address['distance_miles']   = null;
		$address['delivery_message'] = 'Add a map pin to check delivery for this address.';

		if ( empty( $address['lat'] ) || empty( $address['lng'] ) ) {
			return $address;
		}

		$quote = self::quote_cached( (float) $address['lat'], (float) $address['lng'] );

		$address['is_within_range']  = (bool) $quote['deliverable'];
		$address['free_delivery']    = (bool) $quote['free'];
		$address['delivery_fee']     = $quote['deliverable'] ? (float) $quote['fee'] : null;
		$address['distance_miles']   = $quote['distance_miles'];
		$address['delivery_message'] = $quote['message'];

		return $address;
	}

	protected static function decorate( $list ) {
		return array_map( array( __CLASS__, 'with_delivery' ), $list );
	}

	/**
	 * Memoise per request: a list of addresses often repeats coordinates, and
	 * with the Google distance method each quote is an outbound HTTP call.
	 *
	 * NOTE: quoted at subtotal 0, i.e. "before anything is in the cart". If a
	 * free-delivery-over-$X threshold is ever switched on, `free_delivery` here
	 * means the tier itself is free — the cart total can still make it free
	 * later at checkout.
	 */
	protected static function quote_cached( $lat, $lng ) {
		static $seen = array();
		$key = round( $lat, 5 ) . ',' . round( $lng, 5 );
		if ( ! isset( $seen[ $key ] ) ) {
			$seen[ $key ] = CPC_Delivery_Engine::quote( $lat, $lng, 0 );
		}
		return $seen[ $key ];
	}

	/* ---------- Storage helpers ---------- */

	protected static function get_all( $user_id ) {
		$list = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	protected static function save_all( $user_id, $list ) {
		update_user_meta( $user_id, self::META_KEY, array_values( $list ) );
	}

	protected static function clear_defaults( $list ) {
		foreach ( $list as &$a ) { $a['is_default'] = false; }
		unset( $a );
		return $list;
	}

	protected static function has_default( $list ) {
		foreach ( $list as $a ) { if ( ! empty( $a['is_default'] ) ) { return true; } }
		return false;
	}

	protected static function new_id() {
		return substr( md5( uniqid( 'cpc', true ) ), 0, 10 );
	}

	protected static function sanitize_address( WP_REST_Request $r ) {
		return array(
			'label'      => sanitize_text_field( $r['label'] ?? 'Home' ),
			'address_1'  => sanitize_text_field( $r['address_1'] ?? '' ),
			'apt'        => sanitize_text_field( $r['apt'] ?? '' ),
			'city'       => sanitize_text_field( $r['city'] ?? '' ),
			'state'      => sanitize_text_field( $r['state'] ?? '' ),
			'postcode'   => sanitize_text_field( $r['postcode'] ?? '' ),
			'country'    => sanitize_text_field( $r['country'] ?? 'US' ),
			'notes'      => sanitize_textarea_field( $r['notes'] ?? '' ),
			'lat'        => isset( $r['lat'] ) ? (float) $r['lat'] : null,
			'lng'        => isset( $r['lng'] ) ? (float) $r['lng'] : null,
			'is_default' => ! empty( $r['is_default'] ),
		);
	}

	/**
	 * Mirror the chosen default into WooCommerce shipping fields + the map-pin
	 * user meta the shipping method reads, so checkout prices delivery correctly.
	 */
	protected static function sync_default_to_woo( $user_id, $address ) {
		update_user_meta( $user_id, 'shipping_address_1', trim( $address['address_1'] . ( $address['apt'] ? ' ' . $address['apt'] : '' ) ) );
		update_user_meta( $user_id, 'shipping_city', $address['city'] );
		update_user_meta( $user_id, 'shipping_state', $address['state'] );
		update_user_meta( $user_id, 'shipping_postcode', $address['postcode'] );
		update_user_meta( $user_id, 'shipping_country', $address['country'] ?: 'US' );
		if ( $address['lat'] && $address['lng'] ) {
			update_user_meta( $user_id, 'cpc_lat', $address['lat'] );
			update_user_meta( $user_id, 'cpc_lng', $address['lng'] );
		}
	}
}
