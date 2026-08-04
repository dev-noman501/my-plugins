<?php
/**
 * Rider earnings & COD reconciliation.
 *
 * Money model (as decided with the client):
 *  - The rider KEEPS their tips.
 *  - The rider hands the FULL cash of every COD order to the store; the store
 *    pays the rider's tips separately.
 *  - Delivery fees belong to the store.
 *
 * Nothing is stored twice: earnings are read live from delivered orders, so the
 * numbers can never drift from the orders themselves. The only thing we persist
 * is the list of settlements (cash the manager has received back from a rider).
 *
 *   cod_collected  = sum of delivered COD order totals for the rider
 *   tips_earned    = sum of tips on the rider's delivered orders (any payment)
 *   cash_received  = sum of recorded settlements
 *   cash_pending   = cod_collected - cash_received   (what the rider still owes)
 */

defined( 'ABSPATH' ) || exit;

class CPC_Earnings {

	const REST_NAMESPACE = 'casa-prime/v1';
	const META_TIP        = '_cpc_tip';           // per order: tip amount
	const META_DELIVERED  = '_cpc_delivered_at';  // per order: unix ts of delivery
	const META_FAILED     = '_cpc_failed_at';     // per order: unix ts of the (latest) failed attempt
	const META_SETTLE     = 'cpc_settlements';     // per rider: array of settlements

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Stamp the delivery moment so earnings can be filtered by day.
		add_action( 'woocommerce_order_status_delivered', array( __CLASS__, 'stamp_delivered' ) );
		// Same for failure — the End of Day screen counts by when the rider
		// actually failed the run, not when the order was placed.
		add_action( 'woocommerce_order_status_failed-delivery', array( __CLASS__, 'stamp_failed' ) );
	}

	public static function stamp_delivered( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && ! $order->get_meta( self::META_DELIVERED ) ) {
			$order->update_meta_data( self::META_DELIVERED, time() );
			$order->save();
		}
	}

	/** Overwrites on purpose: after a retry, the LATEST failed attempt counts. */
	public static function stamp_failed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( self::META_FAILED, time() );
			$order->save();
		}
	}

	/* ---------- Core computation ---------- */

	/**
	 * The unix moment an order's outcome happened: delivery stamp for delivered
	 * orders, failure stamp for failed ones. Falls back to the order's last
	 * modification (the status change itself) and finally its creation, for
	 * rows that predate stamping.
	 */
	public static function event_ts( $order ) {
		$ts = 'failed-delivery' === $order->get_status()
			? (int) $order->get_meta( self::META_FAILED )
			: (int) $order->get_meta( self::META_DELIVERED );
		if ( ! $ts && $order->get_date_modified() ) {
			$ts = $order->get_date_modified()->getTimestamp();
		}
		if ( ! $ts && $order->get_date_created() ) {
			$ts = $order->get_date_created()->getTimestamp();
		}
		return $ts;
	}

	/**
	 * The store-local day an order counts towards (see event_ts).
	 */
	public static function order_day( $order ) {
		$ts = self::event_ts( $order );
		return $ts ? wp_date( 'Y-m-d', $ts ) : current_time( 'Y-m-d' );
	}

	/**
	 * Every delivered order for a rider, optionally within [from, to] (Y-m-d),
	 * newest first, with the money broken out.
	 */
	public static function rider_orders( $rider_id, $from = '', $to = '' ) {
		$orders = wc_get_orders( array(
			'status'     => array( 'delivered' ),
			'limit'      => -1,
			'meta_key'   => '_cpc_rider_id',
			'meta_value' => (int) $rider_id,
			'orderby'    => 'date',
			'order'      => 'DESC',
		) );

		$rows = array();
		foreach ( $orders as $o ) {
			$day = self::order_day( $o );
			if ( $from && $day < $from ) { continue; }
			if ( $to && $day > $to ) { continue; }

			$is_cod = 'cod' === $o->get_payment_method();
			$tip    = (float) $o->get_meta( self::META_TIP );
			$total  = (float) $o->get_total();

			$rows[] = array(
				'id'            => $o->get_id(),
				'number'        => $o->get_order_number(),
				'date'          => $day,
				'payment'       => $is_cod ? 'cod' : 'prepaid',
				'order_total'   => $total,
				'tip'           => $tip,
				// Full cash the rider took at the door (COD only).
				'cod_collected' => $is_cod ? $total : 0.0,
				'address'       => trim( $o->get_shipping_address_1() . ', ' . $o->get_shipping_city() ),
			);
		}
		return $rows;
	}

	/** Totals for a set of order rows. */
	public static function totals( $rows ) {
		$cod = 0; $tips = 0;
		foreach ( $rows as $r ) { $cod += $r['cod_collected']; $tips += $r['tip']; }
		return array(
			'deliveries'    => count( $rows ),
			'cod_collected' => round( $cod, 2 ),
			'tips_earned'   => round( $tips, 2 ),
		);
	}

	/* ---------- Settlements (cash the manager received back) ---------- */

	public static function get_settlements( $rider_id ) {
		$list = get_user_meta( $rider_id, self::META_SETTLE, true );
		return is_array( $list ) ? $list : array();
	}

	public static function total_received( $rider_id ) {
		$sum = 0;
		foreach ( self::get_settlements( $rider_id ) as $s ) { $sum += (float) $s['amount']; }
		return round( $sum, 2 );
	}

	public static function add_settlement( $rider_id, $amount, $manager_id, $note = '' ) {
		$list   = self::get_settlements( $rider_id );
		$entry  = array(
			'id'         => uniqid( 's_', false ),
			'ts'         => time(),
			'date'       => current_time( 'Y-m-d H:i' ),
			'amount'     => round( (float) $amount, 2 ),
			'manager_id' => (int) $manager_id,
			'manager'    => ( $m = get_userdata( $manager_id ) ) ? $m->display_name : '',
			'note'       => sanitize_text_field( $note ),
		);
		array_unshift( $list, $entry ); // newest first
		update_user_meta( $rider_id, self::META_SETTLE, $list );
		return $entry;
	}

	/**
	 * The manager-facing balance for one rider (lifetime, not date-filtered:
	 * money owed does not reset each day).
	 */
	public static function balance( $rider_id ) {
		$totals   = self::totals( self::rider_orders( $rider_id ) );
		$received = self::total_received( $rider_id );

		return array(
			'rider_id'      => (int) $rider_id,
			'cod_collected' => $totals['cod_collected'],
			'cash_received' => $received,
			'cash_pending'  => round( $totals['cod_collected'] - $received, 2 ),
			'tips_earned'   => $totals['tips_earned'],
			'deliveries'    => $totals['deliveries'],
		);
	}

	/* ---------- REST ---------- */

	public static function register_routes() {
		$ns = self::REST_NAMESPACE;

		// Rider's own earnings, optional ?from=YYYY-MM-DD&to=YYYY-MM-DD.
		register_rest_route( $ns, '/rider/earnings', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'rest_earnings' ),
			'permission_callback' => function () { return current_user_can( 'cpc_update_delivery_status' ); },
			'args'                => array(
				'from' => array( 'required' => false, 'type' => 'string' ),
				'to'   => array( 'required' => false, 'type' => 'string' ),
			),
		) );

		// Manager: one rider's reconciliation balance + recent orders + settlements.
		register_rest_route( $ns, '/riders/(?P<id>\d+)/balance', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'rest_balance' ),
			'permission_callback' => function () { return current_user_can( 'cpc_assign_riders' ); },
			'args'                => array( 'id' => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ) ),
		) );

		// Manager: record cash received back from a rider.
		register_rest_route( $ns, '/riders/(?P<id>\d+)/settle', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'rest_settle' ),
			'permission_callback' => function () { return current_user_can( 'cpc_assign_riders' ); },
			'args'                => array(
				'id'     => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ),
				'amount' => array( 'required' => true, 'validate_callback' => function ( $v ) { return is_numeric( $v ) && $v > 0; } ),
				'note'   => array( 'required' => false, 'type' => 'string' ),
			),
		) );
	}

	public static function rest_earnings( WP_REST_Request $request ) {
		$rider_id = get_current_user_id();
		$rows     = self::rider_orders( $rider_id, (string) $request['from'], (string) $request['to'] );

		// End-of-day screen also reports failed runs alongside the completed
		// ones — counted by the day the rider FAILED them (stamped meta), not
		// the day the order was placed.
		$from   = (string) $request['from'];
		$to     = (string) $request['to'];
		$failed = 0;
		foreach ( wc_get_orders( array(
			'status'     => array( 'failed-delivery' ),
			'limit'      => -1,
			'meta_key'   => '_cpc_rider_id',
			'meta_value' => $rider_id,
		) ) as $o ) {
			$day = self::order_day( $o );
			if ( ( ! $from || $day >= $from ) && ( ! $to || $day <= $to ) ) {
				$failed++;
			}
		}

		return rest_ensure_response( array(
			'success'           => true,
			'totals'            => self::totals( $rows ),
			'failed_deliveries' => $failed,
			'balance'           => self::balance( $rider_id ), // lifetime pending, ignores the date filter
			'orders'            => $rows,
		) );
	}

	public static function rest_balance( WP_REST_Request $request ) {
		$rider_id = (int) $request['id'];
		$rider    = get_userdata( $rider_id );
		if ( ! $rider || ! in_array( 'rider', (array) $rider->roles, true ) ) {
			return new WP_Error( 'cpc_not_rider', 'Not a rider.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( array(
			'success'     => true,
			'rider'       => array( 'id' => $rider_id, 'name' => $rider->display_name ),
			'balance'     => self::balance( $rider_id ),
			'settlements' => self::get_settlements( $rider_id ),
			'recent'      => array_slice( self::rider_orders( $rider_id ), 0, 20 ),
		) );
	}

	public static function rest_settle( WP_REST_Request $request ) {
		$rider_id = (int) $request['id'];
		$rider    = get_userdata( $rider_id );
		if ( ! $rider || ! in_array( 'rider', (array) $rider->roles, true ) ) {
			return new WP_Error( 'cpc_not_rider', 'Not a rider.', array( 'status' => 404 ) );
		}
		$entry = self::add_settlement( $rider_id, $request['amount'], get_current_user_id(), (string) $request['note'] );
		return rest_ensure_response( array(
			'success'    => true,
			'settlement' => $entry,
			'balance'    => self::balance( $rider_id ),
		) );
	}
}
