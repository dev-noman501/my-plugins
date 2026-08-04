<?php
/**
 * REST API — rider endpoints (casa-prime/v1).
 *
 * POST /rider/location      → rider app pings its live position every 10–15s
 * POST /rider/availability  → rider toggles Available / Offline
 * GET  /riders              → manager: all riders with live location + active order
 *
 * Delivery-run flow (per order, in order):
 * POST /rider/orders/{id}/pickup        ready → out-for-delivery
 * POST /rider/orders/{id}/arrived       at the door (+ customer push)
 * POST /rider/orders/{id}/collect-cash  COD only — cash in hand
 * POST /rider/orders/{id}/proof         photo/signature upload
 * POST /rider/orders/{id}/delivered     done — money lands in earnings
 * POST /rider/orders/{id}/failed        {reason} → failed-delivery (manager decides next)
 * GET  /rider/history                   delivered + failed record
 *
 * Rider location lives in user meta (cpc_rider_lat/lng/loc_time). The customer
 * reads it through GET /orders/{id}/track (see CPC_REST_Tracking).
 */

defined( 'ABSPATH' ) || exit;

class CPC_REST_Rider {

	const REST_NAMESPACE = 'casa-prime/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {

		register_rest_route( self::REST_NAMESPACE, '/rider/location', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'update_location' ),
			'permission_callback' => function () {
				return current_user_can( 'cpc_update_location' );
			},
			'args' => array(
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
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/rider/availability', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_availability' ),
				'permission_callback' => function () {
					return current_user_can( 'cpc_set_availability' );
				},
				'args' => array(
					'status' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'available', 'offline' ),
					),
				),
			),
			// The app's toggle needs to know where it stands on reopen.
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_availability' ),
				'permission_callback' => function () {
					return current_user_can( 'cpc_set_availability' );
				},
			),
		) );

		$rider_only = function () { return current_user_can( 'cpc_update_delivery_status' ); };

		// The rider app's own order list (assigned: ready + out-for-delivery).
		register_rest_route( self::REST_NAMESPACE, '/rider/orders', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'my_orders' ),
			'permission_callback' => $rider_only,
		) );

		// Pickup tab — orders waiting at the store to be collected.
		register_rest_route( self::REST_NAMESPACE, '/rider/orders/pickup', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'pickup_orders' ),
			'permission_callback' => $rider_only,
		) );

		// Deliver tab — orders the rider is already out delivering.
		register_rest_route( self::REST_NAMESPACE, '/rider/orders/deliver', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'deliver_orders' ),
			'permission_callback' => $rider_only,
		) );

		// Rider marks an order picked up (ready → out-for-delivery).
		register_rest_route( self::REST_NAMESPACE, '/rider/orders/(?P<id>\d+)/pickup', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'pickup' ),
			'permission_callback' => $rider_only,
			'args'                => array( 'id' => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ) ),
		) );

		// Rider marks an order delivered (out-for-delivery → delivered).
		// This is what makes the tip + COD land in the rider's earnings.
		register_rest_route( self::REST_NAMESPACE, '/rider/orders/(?P<id>\d+)/delivered', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'delivered' ),
			'permission_callback' => $rider_only,
			'args'                => array( 'id' => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ) ),
		) );

		// Rider is at the door — stamps arrival, tells the customer.
		register_rest_route( self::REST_NAMESPACE, '/rider/orders/(?P<id>\d+)/arrived', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'arrived' ),
			'permission_callback' => $rider_only,
			'args'                => array( 'id' => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ) ),
		) );

		// COD screen — rider confirms the cash is in hand before marking delivered.
		register_rest_route( self::REST_NAMESPACE, '/rider/orders/(?P<id>\d+)/collect-cash', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'collect_cash' ),
			'permission_callback' => $rider_only,
			'args'                => array( 'id' => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ) ),
		) );

		// Proof of delivery — a photo at the door or a signature image.
		// Accepts multipart `file` OR base64 `image`, plus `method` (photo|signature).
		register_rest_route( self::REST_NAMESPACE, '/rider/orders/(?P<id>\d+)/proof', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'upload_proof' ),
			'permission_callback' => $rider_only,
			'args'                => array(
				'id'     => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ),
				'method' => array( 'required' => false, 'type' => 'string', 'enum' => array( 'photo', 'signature' ) ),
			),
		) );

		// Delivery failed — customer unreachable etc. Order returns to the store
		// and lands in the manager's Failed lane with the reason.
		register_rest_route( self::REST_NAMESPACE, '/rider/orders/(?P<id>\d+)/failed', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'failed' ),
			'permission_callback' => $rider_only,
			'args'                => array(
				'id'     => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ),
				'reason' => array( 'required' => true, 'type' => 'string' ),
			),
		) );

		// Delivery history — delivered + failed runs, newest first.
		register_rest_route( self::REST_NAMESPACE, '/rider/history', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'history' ),
			'permission_callback' => $rider_only,
			'args'                => array(
				'from' => array( 'required' => false, 'type' => 'string' ),
				'to'   => array( 'required' => false, 'type' => 'string' ),
			),
		) );

		// Rider tells us which of their orders they are driving to right now.
		register_rest_route( self::REST_NAMESPACE, '/rider/current-delivery', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'set_current' ),
				'permission_callback' => function () {
					return current_user_can( 'cpc_update_delivery_status' );
				},
				'args' => array(
					'order_id' => array(
						'required'          => true,
						'type'              => 'integer',
						// 0 clears the selection (rider is between drops).
						'validate_callback' => function ( $v ) { return is_numeric( $v ) && $v >= 0; },
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_current' ),
				'permission_callback' => function () {
					return current_user_can( 'cpc_update_delivery_status' );
				},
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/riders', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_riders' ),
			'permission_callback' => function () {
				return current_user_can( 'cpc_manage_riders' );
			},
		) );
	}

	/**
	 * Rider app pings its current position.
	 */
	public static function update_location( WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		update_user_meta( $user_id, 'cpc_rider_lat', (float) $request['lat'] );
		update_user_meta( $user_id, 'cpc_rider_lng', (float) $request['lng'] );
		update_user_meta( $user_id, 'cpc_rider_loc_time', time() );

		return rest_ensure_response( array(
			'success'    => true,
			'updated_at' => gmdate( 'c' ),
		) );
	}

	/**
	 * Rider toggles Available / Offline.
	 */
	public static function update_availability( WP_REST_Request $request ) {
		update_user_meta( get_current_user_id(), '_cpc_availability', $request['status'] );

		return rest_ensure_response( array(
			'success' => true,
			'status'  => $request['status'],
		) );
	}

	/**
	 * Rider app: my current availability (toggle state on app reopen).
	 */
	public static function get_availability( WP_REST_Request $request ) {
		$me = get_current_user_id();
		return rest_ensure_response( array(
			'success'       => true,
			'status'        => get_user_meta( $me, '_cpc_availability', true ) ?: 'offline',
			'active_orders' => count( self::get_active_orders( $me ) ),
		) );
	}

	/**
	 * Rider app: all my assigned orders (to pick up + out for delivery).
	 */
	public static function my_orders( WP_REST_Request $request ) {
		return self::orders_response( array( 'ready', 'out-for-delivery' ) );
	}

	/** Rider app — Pickup tab: orders ready to collect from the store. */
	public static function pickup_orders( WP_REST_Request $request ) {
		return self::orders_response( array( 'ready' ) );
	}

	/** Rider app — Deliver tab: orders already out for delivery. */
	public static function deliver_orders( WP_REST_Request $request ) {
		return self::orders_response( array( 'out-for-delivery' ) );
	}

	/**
	 * Build the rider's order list for the given statuses, with everything the
	 * app card shows (money, distance, item/bag counts, notes).
	 */
	protected static function orders_response( $statuses ) {
		$rider_id = get_current_user_id();
		$current  = self::get_current_delivery( $rider_id );
		$settings = CPC_Delivery_Settings::get_settings();

		$orders = wc_get_orders( array(
			'status'     => $statuses,
			'limit'      => -1,
			'meta_key'   => '_cpc_rider_id',
			'meta_value' => $rider_id,
			'orderby'    => 'date',
			'order'      => 'ASC',
		) );

		$data = array();
		foreach ( $orders as $o ) {
			$is_cod = 'cod' === $o->get_payment_method();
			list( $lat, $lng ) = self::delivery_coords( $o );

			// Distance from the store to the drop, for the "· 3.2 mi" line.
			$distance = null;
			if ( $lat && $lng ) {
				$distance = round( CPC_Delivery_Engine::haversine(
					(float) $settings['store_lat'], (float) $settings['store_lng'], $lat, $lng
				), 1 );
			}

			// Full item lines — the Deliver card shows what is in the bag
			// (name, weight/qty, cut), not just a count.
			$items = array();
			foreach ( $o->get_items() as $item ) {
				$items[] = array(
					'name'         => $item->get_name(),
					'quantity'     => $item->get_quantity(),
					'weight'       => $item->get_meta( 'Weight' ) ?: null,
					'cut'          => $item->get_meta( 'Cut preference' ) ?: null,
					'instructions' => $item->get_meta( 'Instructions' ) ?: null,
				);
			}

			$data[] = array(
				'id'             => $o->get_id(),
				'number'         => $o->get_order_number(),
				'status'         => $o->get_status(),
				'customer'       => $o->get_formatted_shipping_full_name() ?: $o->get_formatted_billing_full_name(),
				'phone'          => $o->get_billing_phone(),
				'address'        => trim( $o->get_shipping_address_1() . ', ' . $o->get_shipping_city() . ', ' . $o->get_shipping_state() . ' ' . $o->get_shipping_postcode() ),
				'lat'            => $lat,
				'lng'            => $lng,
				'distance_miles' => $distance,
				'item_count'     => $o->get_item_count(),
				'items'          => $items,
				'payment'        => $is_cod ? 'cod' : 'prepaid',
				'collect_cod'    => $is_cod ? (float) $o->get_total() : 0.0,
				'tip'            => (float) $o->get_meta( CPC_Earnings::META_TIP ),
				'total'          => (float) $o->get_total(),
				'delivery_date'  => CPC_Delivery_Date::label( $o ),
				'delivery_notes' => $o->get_meta( '_cpc_delivery_notes' ) ?: null,
				'customer_note'  => $o->get_customer_note() ?: null,
				'is_current'     => ( $current === $o->get_id() ),
				// Delivery-flow state, so the app restores mid-run after a reopen.
				'arrived_at'     => $o->get_meta( '_cpc_arrived_at' ) ? mysql2date( 'c', $o->get_meta( '_cpc_arrived_at' ) ) : null,
				'cash_collected' => (bool) $o->get_meta( '_cpc_cash_collected_at' ),
				'proof'          => self::proof_payload( $o ),
			);
		}

		return rest_ensure_response( array(
			'success'          => true,
			'count'            => count( $data ),
			'current_delivery' => $current ?: null,
			'data'             => $data,
		) );
	}

	/**
	 * Drop-off coordinates for an order. Checkout stamps them onto the order
	 * (_cpc_delivery_lat/lng); orders created before that existed fall back to
	 * the customer's saved address book — matched by street, else the default
	 * pin. Returns array(null, null) when nothing is known, never 0,0.
	 */
	public static function delivery_coords( $order ) {
		$lat = (float) $order->get_meta( '_cpc_delivery_lat' );
		$lng = (float) $order->get_meta( '_cpc_delivery_lng' );
		if ( $lat && $lng ) {
			return array( $lat, $lng );
		}

		$list = get_user_meta( $order->get_customer_id(), 'cpc_addresses', true );
		if ( is_array( $list ) && $list ) {
			$ship    = strtolower( trim( $order->get_shipping_address_1() ) );
			$default = null;
			$any     = null;
			foreach ( $list as $a ) {
				if ( empty( $a['lat'] ) || empty( $a['lng'] ) ) {
					continue;
				}
				$street = strtolower( trim( $a['address_1'] ?? '' ) );
				// The order's shipping line may carry the apt appended, so
				// match in both directions.
				if ( $ship && $street && ( false !== strpos( $ship, $street ) || false !== strpos( $street, $ship ) ) ) {
					return array( (float) $a['lat'], (float) $a['lng'] );
				}
				if ( ! $default && ! empty( $a['is_default'] ) ) { $default = $a; }
				if ( ! $any ) { $any = $a; }
			}
			$pick = $default ?: $any;
			if ( $pick ) {
				return array( (float) $pick['lat'], (float) $pick['lng'] );
			}
		}

		return array( null, null );
	}

	/**
	 * Shared guard for a rider acting on one of their orders.
	 */
	protected static function owned_active_order( $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order ) {
			return new WP_Error( 'cpc_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		if ( (int) $order->get_meta( '_cpc_rider_id' ) !== get_current_user_id() ) {
			return new WP_Error( 'cpc_not_yours', 'This order is not assigned to you.', array( 'status' => 403 ) );
		}
		return $order;
	}

	public static function pickup( WP_REST_Request $request ) {
		$order = self::owned_active_order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'ready' !== $order->get_status() ) {
			return new WP_Error( 'cpc_bad_status', 'Order is not ready for pickup.', array( 'status' => 409 ) );
		}
		$order->set_status( 'out-for-delivery' );
		$order->add_order_note( 'Picked up by ' . wp_get_current_user()->display_name . ' (app).' );
		$order->save();

		// First pickup with nothing selected becomes the active delivery.
		if ( ! self::get_current_delivery( get_current_user_id() ) ) {
			self::set_current_delivery( get_current_user_id(), $order->get_id() );
		}
		return self::current_payload( get_current_user_id() );
	}

	public static function delivered( WP_REST_Request $request ) {
		$order = self::owned_active_order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'out-for-delivery' !== $order->get_status() ) {
			return new WP_Error( 'cpc_bad_status', 'Order is not out for delivery.', array( 'status' => 409 ) );
		}
		$order->set_status( 'delivered' ); // fires the delivered hook → stamps time, tip + COD land in earnings
		$order->add_order_note( 'Delivered by ' . wp_get_current_user()->display_name . ' (app).' );
		$order->save();

		if ( self::get_current_delivery( get_current_user_id() ) === $order->get_id() ) {
			self::set_current_delivery( get_current_user_id(), 0 );
		}

		return rest_ensure_response( array(
			'success'  => true,
			'order_id' => $order->get_id(),
			'status'   => 'delivered',
			'balance'  => CPC_Earnings::balance( get_current_user_id() ),
		) );
	}

	/**
	 * Rider is at the customer's door. Stamps the moment, notes the order and
	 * tells the customer their food has arrived. Safe to call once per order.
	 */
	public static function arrived( WP_REST_Request $request ) {
		$order = self::owned_active_order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'out-for-delivery' !== $order->get_status() ) {
			return new WP_Error( 'cpc_bad_status', 'Order is not out for delivery.', array( 'status' => 409 ) );
		}

		if ( ! $order->get_meta( '_cpc_arrived_at' ) ) {
			$order->update_meta_data( '_cpc_arrived_at', current_time( 'mysql', true ) );
			$order->add_order_note( wp_get_current_user()->display_name . ' arrived at the drop-off (app).' );
			$order->save();

			if ( class_exists( 'CPC_FCM' ) ) {
				CPC_FCM::notify_user(
					(int) $order->get_customer_id(),
					'Your rider has arrived 🚪',
					'Order #' . $order->get_order_number() . ' is at your door.',
					array( 'type' => 'order', 'order_id' => (string) $order->get_id() )
				);
			}
		}

		return rest_ensure_response( array(
			'success'    => true,
			'order_id'   => $order->get_id(),
			'arrived_at' => mysql2date( 'c', $order->get_meta( '_cpc_arrived_at' ) ),
		) );
	}

	/**
	 * COD screen — rider confirms the exact cash is in hand. Recorded on the
	 * order so the app, web panel and manager all see the same fact.
	 */
	public static function collect_cash( WP_REST_Request $request ) {
		$order = self::owned_active_order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'out-for-delivery' !== $order->get_status() ) {
			return new WP_Error( 'cpc_bad_status', 'Order is not out for delivery.', array( 'status' => 409 ) );
		}
		if ( 'cod' !== $order->get_payment_method() ) {
			return new WP_Error( 'cpc_not_cod', 'This order is prepaid — nothing to collect.', array( 'status' => 400 ) );
		}

		if ( ! $order->get_meta( '_cpc_cash_collected_at' ) ) {
			$order->update_meta_data( '_cpc_cash_collected_at', current_time( 'mysql', true ) );
			$order->add_order_note( sprintf(
				'Cash $%s collected by %s (app).',
				number_format( (float) $order->get_total(), 2 ),
				wp_get_current_user()->display_name
			) );
			$order->save();
		}

		return rest_ensure_response( array(
			'success'        => true,
			'order_id'       => $order->get_id(),
			'amount'         => (float) $order->get_total(),
			'cash_collected' => true,
		) );
	}

	/**
	 * Proof of delivery: a door photo or signature image. Multipart `file` or a
	 * base64 `image` string both work (Flutter sends whichever is easier). The
	 * image becomes a media attachment linked from the order, so the customer's
	 * order detail and the manager panel can both show it.
	 */
	public static function upload_proof( WP_REST_Request $request ) {
		$order = self::owned_active_order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( ! in_array( $order->get_status(), array( 'out-for-delivery', 'delivered' ), true ) ) {
			return new WP_Error( 'cpc_bad_status', 'Order is not being delivered.', array( 'status' => 409 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$files      = $request->get_file_params();
		$file_array = null;

		if ( ! empty( $files['file']['tmp_name'] ) ) {
			$file_array = $files['file'];
		} elseif ( ! empty( $request['image'] ) ) {
			// data:image/...;base64,xxxx or bare base64.
			$raw = (string) $request['image'];
			$ext = 'jpg';
			if ( preg_match( '#^data:image/(png|jpe?g);base64,#i', $raw, $m ) ) {
				$ext = 'png' === strtolower( $m[1] ) ? 'png' : 'jpg';
				$raw = substr( $raw, strpos( $raw, ',' ) + 1 );
			}
			$binary = base64_decode( $raw, true );
			if ( ! $binary || strlen( $binary ) > 8 * MB_IN_BYTES ) {
				return new WP_Error( 'cpc_bad_image', 'Image data is invalid or too large (8 MB max).', array( 'status' => 400 ) );
			}
			$tmp = wp_tempnam( 'cpc-pod.' . $ext );
			file_put_contents( $tmp, $binary );
			$file_array = array(
				'name'     => 'pod-order-' . $order->get_id() . '.' . $ext,
				'tmp_name' => $tmp,
			);
		}

		if ( ! $file_array ) {
			return new WP_Error( 'cpc_no_image', 'Send the image as multipart "file" or base64 "image".', array( 'status' => 400 ) );
		}

		$attachment_id = media_handle_sideload( $file_array, 0, 'Proof of delivery — order #' . $order->get_order_number() );
		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'cpc_upload_failed', $attachment_id->get_error_message(), array( 'status' => 400 ) );
		}

		$method = in_array( $request['method'], array( 'photo', 'signature' ), true ) ? $request['method'] : 'photo';
		$order->update_meta_data( '_cpc_pod_attachment', $attachment_id );
		$order->update_meta_data( '_cpc_pod_method', $method );
		$order->update_meta_data( '_cpc_pod_at', current_time( 'mysql', true ) );
		$order->add_order_note( 'Proof of delivery (' . $method . ') uploaded by ' . wp_get_current_user()->display_name . ' (app).' );
		$order->save();

		return rest_ensure_response( array(
			'success' => true,
			'proof'   => self::proof_payload( $order ),
		) );
	}

	/**
	 * Delivery failed — the order returns to the store with the reason recorded.
	 * The manager's Failed lane picks it up from there (retry or cancel).
	 */
	public static function failed( WP_REST_Request $request ) {
		$order = self::owned_active_order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'out-for-delivery' !== $order->get_status() ) {
			return new WP_Error( 'cpc_bad_status', 'Order is not out for delivery.', array( 'status' => 409 ) );
		}
		$reason = sanitize_text_field( (string) $request['reason'] );
		if ( '' === $reason ) {
			return new WP_Error( 'cpc_no_reason', 'A reason is required.', array( 'status' => 400 ) );
		}

		$order->update_meta_data( '_cpc_fail_reason', $reason );
		$order->set_status( 'failed-delivery' );
		$order->add_order_note( 'Delivery failed — ' . $reason . ' (reported by ' . wp_get_current_user()->display_name . ', app).' );
		$order->save();

		if ( self::get_current_delivery( get_current_user_id() ) === $order->get_id() ) {
			self::set_current_delivery( get_current_user_id(), 0 );
		}

		return rest_ensure_response( array(
			'success'  => true,
			'order_id' => $order->get_id(),
			'status'   => 'failed-delivery',
			'reason'   => $reason,
		) );
	}

	/**
	 * Delivery history: delivered + failed runs for this rider, newest first.
	 * Optional ?from=YYYY-MM-DD&to=YYYY-MM-DD.
	 */
	public static function history( WP_REST_Request $request ) {
		// No date args in the query: the range applies to when the rider
		// actually delivered/failed the run (stamped meta), not when the
		// customer placed the order — those can be days apart.
		$orders = wc_get_orders( array(
			'status'     => array( 'delivered', 'failed-delivery' ),
			'limit'      => 200,
			'meta_key'   => '_cpc_rider_id',
			'meta_value' => get_current_user_id(),
			'orderby'    => 'date',
			'order'      => 'DESC',
		) );
		$from = (string) $request['from'];
		$to   = (string) $request['to'];

		$data = array();
		foreach ( $orders as $o ) {
			$day = CPC_Earnings::order_day( $o );
			if ( ( $from && $day < $from ) || ( $to && $day > $to ) ) {
				continue;
			}
			$is_cod    = 'cod' === $o->get_payment_method();
			$is_failed = 'failed-delivery' === $o->get_status();
			$event_ts  = CPC_Earnings::event_ts( $o );
			$event_iso = $event_ts ? wp_date( 'c', $event_ts ) : null;

			$data[] = array(
				'id'            => $o->get_id(),
				'number'        => $o->get_order_number(),
				'status'        => $o->get_status(), // delivered | failed-delivery
				// The day the rider completed/failed the run — End of Day day.
				'date'          => $day,
				'delivered_at'  => $is_failed ? null : $event_iso,
				'failed_at'     => $is_failed ? $event_iso : null,
				'ordered_at'    => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d' ) : null,
				'customer'      => $o->get_formatted_shipping_full_name() ?: $o->get_formatted_billing_full_name(),
				'payment'       => $is_cod ? 'cod' : 'prepaid',
				'cod_collected' => ( $is_cod && ! $is_failed ) ? (float) $o->get_total() : 0.0,
				'tip'           => (float) $o->get_meta( CPC_Earnings::META_TIP ),
				'total'         => (float) $o->get_total(),
				'proof'         => self::proof_payload( $o ),
				'fail_reason'   => $o->get_meta( '_cpc_fail_reason' ) ?: null,
			);
		}

		// Newest run first, by the actual event day rather than order age.
		usort( $data, function ( $a, $b ) {
			return strcmp( $b['date'], $a['date'] );
		} );

		return rest_ensure_response( array( 'success' => true, 'count' => count( $data ), 'data' => $data ) );
	}

	/** Proof-of-delivery block for any payload, or null when none uploaded. */
	public static function proof_payload( $order ) {
		$attachment_id = (int) $order->get_meta( '_cpc_pod_attachment' );
		if ( ! $attachment_id ) {
			return null;
		}
		return array(
			'method'      => $order->get_meta( '_cpc_pod_method' ) ?: 'photo',
			'image'       => wp_get_attachment_image_url( $attachment_id, 'large' ) ?: null,
			'thumbnail'   => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) ?: null,
			'uploaded_at' => $order->get_meta( '_cpc_pod_at' ) ? mysql2date( 'c', $order->get_meta( '_cpc_pod_at' ) ) : null,
		);
	}

	/**
	 * Rider: set the order they are delivering right now (0 = none).
	 */
	public static function set_current( WP_REST_Request $request ) {
		$rider_id = get_current_user_id();
		$result   = self::set_current_delivery( $rider_id, $request['order_id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::current_payload( $rider_id );
	}

	/**
	 * Rider: what am I delivering right now, and what else am I carrying?
	 */
	public static function get_current( WP_REST_Request $request ) {
		return self::current_payload( get_current_user_id() );
	}

	protected static function current_payload( $rider_id ) {
		$current = self::get_current_delivery( $rider_id );

		return rest_ensure_response( array(
			'success'             => true,
			'current_delivery'    => $current ? $current : null,
			'remaining_deliveries' => self::count_other_deliveries( $rider_id, $current ),
			'active_orders'       => self::get_active_orders( $rider_id ),
		) );
	}

	/**
	 * Manager: every rider with availability, live location and active delivery.
	 */
	public static function list_riders( WP_REST_Request $request ) {
		$riders = array();

		foreach ( get_users( array( 'role' => 'rider' ) ) as $rider ) {
			$active = self::get_active_orders( $rider->ID );
			$riders[] = array(
				'id'                 => $rider->ID,
				'name'               => $rider->display_name,
				'phone'              => get_user_meta( $rider->ID, 'billing_phone', true ),
				'availability'       => get_user_meta( $rider->ID, '_cpc_availability', true ) ?: 'offline',
				'location'           => self::get_rider_location( $rider->ID ),
				// A rider may carry several orders at once — the manager decides.
				'active_orders'      => $active,
				'active_order_count' => count( $active ),
				// Kept for backward compatibility with earlier clients.
				'active_order'       => $active ? $active[0]['id'] : null,
			);
		}

		return rest_ensure_response( array( 'success' => true, 'data' => $riders ) );
	}

	/**
	 * Latest known location of a rider (null if never reported).
	 */
	public static function get_rider_location( $rider_id ) {
		$lat  = get_user_meta( $rider_id, 'cpc_rider_lat', true );
		$lng  = get_user_meta( $rider_id, 'cpc_rider_lng', true );
		$time = (int) get_user_meta( $rider_id, 'cpc_rider_loc_time', true );

		if ( '' === $lat || '' === $lng ) {
			return null;
		}
		return array(
			'lat'         => (float) $lat,
			'lng'         => (float) $lng,
			'updated_at'  => $time ? gmdate( 'c', $time ) : null,
			'seconds_ago' => $time ? max( 0, time() - $time ) : null,
		);
	}

	/**
	 * All orders currently on this rider's plate — assigned & waiting for pickup
	 * ("ready") plus those already out for delivery. A rider can carry several
	 * at once; the manager decides how many.
	 */
	public static function get_active_orders( $rider_id ) {
		$orders = wc_get_orders( array(
			'status'     => array( 'ready', 'out-for-delivery' ),
			'limit'      => -1,
			'meta_key'   => '_cpc_rider_id',
			'meta_value' => $rider_id,
			'orderby'    => 'date',
			'order'      => 'ASC',
		) );

		$out = array();
		foreach ( $orders as $o ) {
			$out[] = array(
				'id'      => $o->get_id(),
				'number'  => $o->get_order_number(),
				'status'  => $o->get_status(),
				'address' => trim( $o->get_shipping_address_1() . ', ' . $o->get_shipping_city() ),
				// The rider needs the booked day as much as the manager does —
				// a run can hold today's drops and a scheduled one at once.
				'delivery_date'       => CPC_Delivery_Date::get( $o ) ?: null,
				'delivery_date_label' => CPC_Delivery_Date::label( $o ) ?: null,
				'is_scheduled'        => CPC_Delivery_Date::is_scheduled( $o ),
				'delivery_notes'      => $o->get_meta( '_cpc_delivery_notes' ) ?: null,
				'customer_note'       => $o->get_customer_note() ?: null,
			);
		}
		return $out;
	}

	/**
	 * First active order id (kept for older callers).
	 */
	public static function get_active_order_id( $rider_id ) {
		$active = self::get_active_orders( $rider_id );
		return $active ? $active[0]['id'] : null;
	}

	/* ---------- Current delivery ---------- */

	const META_CURRENT = '_cpc_current_delivery';

	/**
	 * The one order the rider says they are driving to right now.
	 *
	 * A rider often carries several orders, but only ever drives to one at a
	 * time — and only that customer should see the live pin. The rider picks it
	 * themselves (they know the route better than any pre-set sequence would).
	 *
	 * Returns 0 when nothing is selected, or when the selection has gone stale
	 * (order delivered, cancelled or handed to another rider).
	 */
	public static function get_current_delivery( $rider_id ) {
		$order_id = (int) get_user_meta( $rider_id, self::META_CURRENT, true );
		if ( ! $order_id ) {
			return 0;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order
			|| (int) $order->get_meta( '_cpc_rider_id' ) !== (int) $rider_id
			|| ! in_array( $order->get_status(), array( 'ready', 'out-for-delivery' ), true ) ) {
			delete_user_meta( $rider_id, self::META_CURRENT );
			return 0;
		}
		return $order_id;
	}

	/**
	 * Rider marks which order they are delivering now. Pass 0 to clear.
	 * Returns true, or a WP_Error explaining why not.
	 */
	public static function set_current_delivery( $rider_id, $order_id ) {
		$order_id = (int) $order_id;

		if ( ! $order_id ) {
			delete_user_meta( $rider_id, self::META_CURRENT );
			return true;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'cpc_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		if ( (int) $order->get_meta( '_cpc_rider_id' ) !== (int) $rider_id ) {
			return new WP_Error( 'cpc_not_yours', 'That order is not assigned to you.', array( 'status' => 403 ) );
		}
		if ( ! in_array( $order->get_status(), array( 'ready', 'out-for-delivery' ), true ) ) {
			return new WP_Error( 'cpc_not_active', 'That order is not out for delivery.', array( 'status' => 400 ) );
		}

		update_user_meta( $rider_id, self::META_CURRENT, $order_id );
		return true;
	}

	/**
	 * How many OTHER orders this rider is still carrying, so a waiting customer
	 * can be told why their pin has not appeared yet.
	 */
	public static function count_other_deliveries( $rider_id, $exclude_order_id ) {
		$count = 0;
		foreach ( self::get_active_orders( $rider_id ) as $o ) {
			if ( (int) $o['id'] !== (int) $exclude_order_id ) {
				$count++;
			}
		}
		return $count;
	}
}
