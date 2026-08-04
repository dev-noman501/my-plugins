<?php
/**
 * REST API — order tracking endpoint (casa-prime/v1).
 *
 * GET /orders/{id}/track → live order status for the customer app's tracking
 * screen. While the order is Out for Delivery it includes the rider's live
 * position — the app polls this every 10–15 seconds and moves the map marker.
 *
 * Access: the order's customer, or staff (manager/admin/store worker).
 */

defined( 'ABSPATH' ) || exit;

class CPC_REST_Tracking {

	const REST_NAMESPACE = 'casa-prime/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( self::REST_NAMESPACE, '/orders/(?P<id>\d+)/track', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'track_order' ),
			'permission_callback' => array( __CLASS__, 'can_track' ),
			'args'                => array(
				'id' => array(
					'validate_callback' => function ( $v ) { return is_numeric( $v ); },
				),
			),
		) );
	}

	/**
	 * Order owner, or anyone with staff-level order visibility.
	 */
	public static function can_track( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'cpc_not_logged_in', 'Authentication required.', array( 'status' => 401 ) );
		}
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order ) {
			return new WP_Error( 'cpc_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		if ( (int) $order->get_customer_id() === $user_id ) {
			return true;
		}
		if ( current_user_can( 'cpc_view_order_queue' ) || current_user_can( 'cpc_assign_riders' ) ) {
			return true;
		}
		// The assigned rider may also see the tracking payload.
		if ( (int) $order->get_meta( '_cpc_rider_id' ) === $user_id ) {
			return true;
		}
		return new WP_Error( 'cpc_forbidden', 'You cannot track this order.', array( 'status' => 403 ) );
	}

	public static function track_order( WP_REST_Request $request ) {
		$order  = wc_get_order( (int) $request['id'] );
		$status = $order->get_status();

		$data = array(
			'order_id'     => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'status'       => $status,
			'status_label' => wc_get_order_status_name( $status ),
			'fulfillment'  => CPC_Fulfillment::get_type( $order ),
			// The day the customer booked, so the tracking screen can say
			// "arriving Sat 25 Jul" rather than implying it is on its way now.
			'delivery_date'       => CPC_Delivery_Date::get( $order ) ?: null,
			'delivery_date_label' => CPC_Delivery_Date::label( $order ) ?: null,
			'is_scheduled'        => CPC_Delivery_Date::is_scheduled( $order ),
			'placed_at'    => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
			'total'        => $order->get_total(),
			'currency'     => $order->get_currency(),
			'rider'        => null,
		);

		// The fixed drop-off marker + ETA input: the delivery address the
		// customer picked at checkout (rider APIs share the same source).
		// Pickup orders have no drop-off, so no marker.
		$coords = ( 'delivery' === $data['fulfillment'] && class_exists( 'CPC_REST_Rider' ) )
			? CPC_REST_Rider::delivery_coords( $order )
			: array( null, null );
		$data['customer_location'] = ( $coords[0] && $coords[1] )
			? array( 'lat' => $coords[0], 'lng' => $coords[1] )
			: null;

		// When each lifecycle step happened — drives the timeline UI.
		$data['timeline'] = CPC_Order_Statuses::timeline( $order );

		$rider_id = (int) $order->get_meta( '_cpc_rider_id' );
		if ( $rider_id ) {
			$rider = get_userdata( $rider_id );

			// A rider often carries several orders but drives to one at a time,
			// and picks that one themselves. Only that customer gets the live
			// pin; everyone else is told how many drops are still in the van so
			// they understand the wait instead of watching a pin move away.
			$current  = CPC_REST_Rider::get_current_delivery( $rider_id );
			$is_mine  = ( $current === $order->get_id() );
			$moving   = ( 'out-for-delivery' === $status );
			$others   = CPC_REST_Rider::count_other_deliveries( $rider_id, $order->get_id() );

			$data['rider'] = array(
				'id'    => $rider_id,
				'name'  => $rider ? $rider->display_name : $order->get_meta( '_cpc_rider_name' ),
				'phone' => get_user_meta( $rider_id, 'billing_phone', true ),
				'location' => ( $moving && $is_mine ) ? CPC_REST_Rider::get_rider_location( $rider_id ) : null,
				// True once the rider has actually set off towards this customer.
				'heading_to_you'     => ( $moving && $is_mine ),
				'other_deliveries'   => $others,
				'status_text'        => self::rider_status_text( $status, $is_mine, $current, $others ),
			);
		}

		return rest_ensure_response( array( 'success' => true, 'data' => $data ) );
	}

	/**
	 * A ready-to-show sentence for the customer's tracking screen, so every app
	 * words the wait the same way.
	 *
	 * We never claim to know the rider's route order — they choose each drop as
	 * they go — so we only say "another delivery first" when the rider really is
	 * driving to someone else right now.
	 */
	protected static function rider_status_text( $status, $is_mine, $current, $others ) {
		if ( 'out-for-delivery' !== $status ) {
			return 'Your order is packed and waiting for the rider.';
		}
		if ( $is_mine ) {
			return 'Your rider is on the way.';
		}
		if ( $current ) {
			return 1 === $others
				? 'Your rider is completing another delivery first.'
				: sprintf( 'Your rider is completing another delivery first — %d other stops on this run.', $others );
		}
		return $others
			? sprintf( 'Your rider has your order — %d other %s on this run.', $others, 1 === $others ? 'delivery' : 'deliveries' )
			: 'Your rider has your order and will set off shortly.';
	}
}
