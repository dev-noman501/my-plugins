<?php
/**
 * REST API — checkout & customer orders (casa-prime/v1). Login required.
 *
 * POST /checkout                 place an order from the cart
 * GET  /orders                   my order history
 * GET  /orders/{id}              my order detail
 * POST /orders/{id}/confirm-payment   mark a card order paid (after the app's
 *                                     payment SDK succeeds; Stripe wiring TBD)
 *
 * Exact-charge model: item total = weight x per-lb price. The only extra is the
 * delivery fee (from the distance engine). No packing adjustment, no estimate.
 */

defined( 'ABSPATH' ) || exit;

class CPC_REST_Checkout {

	const REST_NAMESPACE = 'casa-prime/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Woo's hold-stock cron only auto-cancels unpaid orders born in its own
		// web checkout ('checkout' created_via); app orders must opt in, or a
		// never-paid card order sits in "Pending payment" forever.
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'cancel_unpaid_app_order' ), 10, 2 );
		// Watchdog: the cleanup event was found unscheduled on live, so the
		// hold-stock timer never ran at all. Woo reschedules it after each run;
		// this only revives it if it ever goes missing again.
		add_action( 'init', array( __CLASS__, 'ensure_unpaid_cleanup_scheduled' ) );
	}

	public static function ensure_unpaid_cleanup_scheduled() {
		if ( absint( get_option( 'woocommerce_hold_stock_minutes' ) ) >= 1
			&& ! wp_next_scheduled( 'woocommerce_cancel_unpaid_orders' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'woocommerce_cancel_unpaid_orders' );
		}
	}

	/** Let Woo's unpaid-order cleanup cancel app orders too (old + new). */
	public static function cancel_unpaid_app_order( $cancel, $order ) {
		return $cancel
			|| 'cpc-app' === $order->get_created_via()
			|| '' !== (string) $order->get_meta( '_cpc_fulfillment' ); // orders from before created_via was set
	}

	public static function require_login() {
		return is_user_logged_in() ? true : new WP_Error( 'cpc_not_logged_in', 'Authentication required.', array( 'status' => 401 ) );
	}

	public static function register_routes() {
		$ns   = self::REST_NAMESPACE;
		$auth = array( __CLASS__, 'require_login' );

		register_rest_route( $ns, '/checkout', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'checkout' ),
			'permission_callback' => $auth,
			'args'                => array(
				'fulfillment'    => array( 'required' => true, 'type' => 'string', 'enum' => array( 'delivery', 'pickup' ) ),
				'payment_method' => array( 'required' => true, 'type' => 'string', 'enum' => array( 'cod', 'card' ) ),
				'address_id'     => array( 'required' => false, 'type' => 'string' ),
				// "today" or YYYY-MM-DD. `delivery_time` is the old name, still
				// accepted so orders from older app builds keep working.
				'delivery_date'  => array( 'required' => false, 'type' => 'string' ),
				'delivery_time'  => array( 'required' => false, 'type' => 'string' ),
				'tip'            => array( 'required' => false, 'type' => 'number' ),
				'note'           => array( 'required' => false, 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/orders', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_orders' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( $ns, '/orders/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_order' ),
			'permission_callback' => $auth,
			'args'                => array( 'id' => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ) ),
		) );

		register_rest_route( $ns, '/orders/(?P<id>\d+)/confirm-payment', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'confirm_payment' ),
			'permission_callback' => $auth,
			'args'                => array( 'id' => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ) ),
		) );
	}

	/* ---------- Checkout ---------- */

	public static function checkout( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$items   = CPC_Cart::items_for_order( $user_id );
		if ( empty( $items ) ) {
			return new WP_Error( 'cpc_empty_cart', 'Your cart is empty.', array( 'status' => 400 ) );
		}
		$coupon_data = CPC_Coupons::calculate( $user_id );
		if ( is_wp_error( $coupon_data ) ) {
			return $coupon_data;
		}

		$fulfillment = $request['fulfillment'];
		$payment     = $request['payment_method'];

		// Validate the chosen day before building anything, so a bad date does
		// not leave a half-made order behind.
		$delivery_date = CPC_Delivery_Date::parse(
			! empty( $request['delivery_date'] ) ? $request['delivery_date'] : ( $request['delivery_time'] ?? '' )
		);
		if ( is_wp_error( $delivery_date ) ) {
			return $delivery_date;
		}

		// Resolve delivery address + fee.
		$address = null;
		$fee     = 0;
		if ( 'delivery' === $fulfillment ) {
			$address = self::resolve_address( $user_id, $request );
			if ( is_wp_error( $address ) ) { return $address; }

			$subtotal = 0;
			foreach ( $items as $it ) {
				$p = wc_get_product( $it['product_id'] );
				if ( $p ) { $subtotal += (float) $it['amount'] * self::unit_price( $p ); }
			}
			$quote = CPC_Delivery_Engine::quote( $address['lat'], $address['lng'], $subtotal );
			if ( empty( $quote['deliverable'] ) ) {
				return new WP_Error( 'cpc_not_deliverable', $quote['message'], array( 'status' => 422 ) );
			}
			$fee = (float) $quote['fee'];
		}

		// Build the WooCommerce order.
		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		// Marks the order as app-born: admin "Origin" column reads cpc-app, and
		// the unpaid-order cleanup recognises it (see cancel_unpaid_app_order).
		$order->set_created_via( 'cpc-app' );

		foreach ( $items as $it ) {
			$product = wc_get_product( $it['product_id'] );
			if ( ! $product ) { continue; }
			// Charge the live offer price when the product is the active special.
			$unit = self::unit_price( $product );
			if ( 'weight' === $it['sold_by'] ) {
				$line_total = round( (float) $it['amount'] * $unit, 2 );
				$item_id = $order->add_product( $product, 1, array( 'subtotal' => $line_total, 'total' => $line_total ) );
				wc_add_order_item_meta( $item_id, '_cpc_weight_lb', $it['amount'] );
				wc_add_order_item_meta( $item_id, 'Weight', $it['amount'] . ' lb' );
			} else {
				$qty        = (int) $it['amount'];
				$line_total = round( $qty * $unit, 2 );
				$item_id = $order->add_product( $product, $qty, array( 'subtotal' => $line_total, 'total' => $line_total ) );
			}
			if ( ! empty( $it['cut'] ) )          { wc_add_order_item_meta( $item_id, 'Cut preference', $it['cut'] ); }
			if ( ! empty( $it['instructions'] ) ) { wc_add_order_item_meta( $item_id, 'Instructions', $it['instructions'] ); }
		}

		// Reduce product line totals and attach a genuine WooCommerce coupon
		// item. The order subtotal stays pre-discount while its total reflects
		// the discount, matching native WooCommerce order reporting.
		if ( $coupon_data ) {
			self::apply_coupon_to_order( $order, $coupon_data );
		}

		// Fulfillment + address.
		$order->update_meta_data( '_cpc_fulfillment', $fulfillment );
		if ( 'delivery' === $fulfillment ) {
			$addr = array(
				'first_name' => wp_get_current_user()->first_name, 'last_name' => wp_get_current_user()->last_name,
				'address_1'  => trim( $address['address_1'] . ( ! empty( $address['apt'] ) ? ' ' . $address['apt'] : '' ) ),
				'city'       => $address['city'], 'state' => $address['state'], 'postcode' => $address['postcode'],
				'country'    => $address['country'] ?: 'US', 'email' => wp_get_current_user()->user_email,
				'phone'      => get_user_meta( $user_id, 'billing_phone', true ),
			);
			$order->set_address( $addr, 'billing' );
			$order->set_address( $addr, 'shipping' );
			$order->update_meta_data( '_cpc_delivery_lat', $address['lat'] );
			$order->update_meta_data( '_cpc_delivery_lng', $address['lng'] );
			if ( ! empty( $address['notes'] ) ) { $order->update_meta_data( '_cpc_delivery_notes', $address['notes'] ); }

			$ship = new WC_Order_Item_Shipping();
			$ship->set_method_title( $fee > 0 ? 'Home Delivery' : 'Free Delivery' );
			$ship->set_method_id( 'cpc_delivery' );
			$ship->set_total( $fee );
			$order->add_item( $ship );
		} else {
			$ship = new WC_Order_Item_Shipping();
			$ship->set_method_title( 'Store Pickup' );
			$ship->set_method_id( 'local_pickup' );
			$ship->set_total( 0 );
			$order->add_item( $ship );
		}

		// Optional rider tip — added as a fee line so it lands in the order
		// total, and ALSO stored as its own meta so the rider-earnings ledger can
		// read the tip cleanly without parsing fee lines.
		$tip = isset( $request['tip'] ) ? max( 0, (float) $request['tip'] ) : 0;
		if ( $tip > 0 ) {
			$fee_item = new WC_Order_Item_Fee();
			$fee_item->set_name( 'Rider tip' );
			$fee_item->set_total( $tip );
			$order->add_item( $fee_item );
		}
		$order->update_meta_data( CPC_Earnings::META_TIP, $tip );

		$order->update_meta_data( CPC_Delivery_Date::META_DATE, $delivery_date );
		if ( ! empty( $request['note'] ) ) {
			$order->set_customer_note( sanitize_textarea_field( $request['note'] ) );
		}

		// Payment + status.
		if ( 'cod' === $payment ) {
			$order->set_payment_method( 'cod' );
			$order->set_payment_method_title( 'Cash on Delivery' );
			$needs_payment = false;
		} else {
			$order->set_payment_method( 'card' );
			$order->set_payment_method_title( 'Card' );
			$needs_payment = true; // app completes payment, then calls confirm-payment
		}

		$order->calculate_totals( false );
		// Attach the email hooks before the status change so WooCommerce sends
		// the confirmation to the customer + admin. COD confirms now; card waits
		// for confirm_payment().
		if ( ! $needs_payment ) {
			self::ensure_mailer();
		}
		$order->set_status( $needs_payment ? 'pending' : 'processing' );
		$order->save();
		if ( $coupon_data ) {
			$coupon = new WC_Coupon( $coupon_data['id'] );
			$coupon->increase_usage_count( $user_id, $order );
		}

		// Empty the cart now the order exists.
		CPC_Cart::clear( $user_id );

		return rest_ensure_response( array(
			'success'       => true,
			'needs_payment' => $needs_payment,
			'order'         => self::order_payload( $order, true ),
		) );
	}

	/* ---------- Confirm card payment ---------- */

	public static function confirm_payment( WP_REST_Request $request ) {
		$order = self::owned_order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		if ( 'pending' !== $order->get_status() ) {
			return new WP_Error( 'cpc_not_pending', 'This order is not awaiting payment.', array( 'status' => 409 ) );
		}
		// With Stripe configured this is a HARD check — the money must really
		// have been taken for this exact order. Without keys (dev/testing) we
		// still trust the app, marked as such, so testing continues until the
		// client's keys land in wp-config.
		$transaction_id = '';
		if ( class_exists( 'CPC_Stripe' ) && CPC_Stripe::is_configured() ) {
			$pi = CPC_Stripe::verify_order_paid( $order );
			if ( is_wp_error( $pi ) ) { return $pi; }
			$transaction_id = $pi['id'];
			$order->add_order_note( 'Payment verified with Stripe (' . $transaction_id . ').' );
		} else {
			$order->add_order_note( 'Payment accepted WITHOUT Stripe verification (test mode — no keys configured).' );
		}

		self::ensure_mailer(); // so the confirmation email fires on the transition
		$order->payment_complete( $transaction_id );
		$order->set_status( 'processing' );
		$order->save();
		return rest_ensure_response( array( 'success' => true, 'order' => self::order_payload( $order, true ) ) );
	}

	/* ---------- Orders list / detail ---------- */

	public static function list_orders( WP_REST_Request $request ) {
		$orders = wc_get_orders( array( 'customer_id' => get_current_user_id(), 'limit' => 30, 'orderby' => 'date', 'order' => 'DESC' ) );
		$data = array();
		foreach ( $orders as $o ) { $data[] = self::order_payload( $o, false ); }
		return rest_ensure_response( array( 'success' => true, 'data' => $data ) );
	}

	public static function get_order( WP_REST_Request $request ) {
		$order = self::owned_order( $request );
		if ( is_wp_error( $order ) ) { return $order; }
		$payload = self::order_payload( $order, true );
		// Every other endpoint answers under `data`; this one historically used
		// `order`. Send both so the app can read `data` like everywhere else
		// without breaking builds that already read `order`.
		return rest_ensure_response( array( 'success' => true, 'data' => $payload, 'order' => $payload ) );
	}

	/* ---------- Helpers ---------- */

	public static function owned_order( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order ) {
			return new WP_Error( 'cpc_order_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		if ( (int) $order->get_customer_id() !== get_current_user_id() && ! current_user_can( 'cpc_view_order_queue' ) ) {
			return new WP_Error( 'cpc_forbidden', 'This is not your order.', array( 'status' => 403 ) );
		}
		return $order;
	}

	protected static function resolve_address( $user_id, WP_REST_Request $request ) {
		// Prefer a saved address by id; else fall back to the user's default.
		$list = get_user_meta( $user_id, 'cpc_addresses', true );
		$list = is_array( $list ) ? $list : array();

		$chosen = null;
		if ( ! empty( $request['address_id'] ) ) {
			foreach ( $list as $a ) {
				if ( $a['id'] === $request['address_id'] ) { $chosen = $a; break; }
			}
			if ( ! $chosen ) {
				return new WP_Error( 'cpc_addr_not_found', 'Selected address not found.', array( 'status' => 404 ) );
			}
		} else {
			foreach ( $list as $a ) { if ( ! empty( $a['is_default'] ) ) { $chosen = $a; break; } }
			if ( ! $chosen && $list ) { $chosen = $list[0]; }
		}

		if ( ! $chosen ) {
			return new WP_Error( 'cpc_no_address', 'Please add a delivery address first.', array( 'status' => 400 ) );
		}
		if ( empty( $chosen['lat'] ) || empty( $chosen['lng'] ) ) {
			return new WP_Error( 'cpc_no_pin', 'This address has no map location. Please set it on the map.', array( 'status' => 400 ) );
		}
		return $chosen;
	}

	/**
	 * Make sure WooCommerce's transactional-email hooks are attached before we
	 * change an order's status.
	 *
	 * WooCommerce wires those hooks up only when WC()->mailer() is first called,
	 * which never happens on its own during a REST request — so setting an order
	 * to "processing" via the API sent no email at all. Calling this first lets
	 * WooCommerce fire the New Order (admin) + Customer Processing (customer)
	 * emails itself, exactly once, on the status transition.
	 */
	protected static function ensure_mailer() {
		if ( function_exists( 'WC' ) ) {
			WC()->mailer();
		}
	}

	protected static function apply_coupon_to_order( WC_Order $order, array $coupon_data ) {
		$remaining = (float) $coupon_data['discount'];
		$items     = array_values( $order->get_items( 'line_item' ) );
		$base      = array_sum( array_map( function ( $item ) { return (float) $item->get_total(); }, $items ) );

		foreach ( $items as $index => $item ) {
			$line = (float) $item->get_total();
			$part = ( count( $items ) - 1 === $index )
				? $remaining
				: round( (float) $coupon_data['discount'] * $line / max( 0.01, $base ), wc_get_price_decimals() );
			$part = min( $line, $remaining, $part );
			$item->set_total( max( 0, $line - $part ) );
			$item->save();
			$remaining -= $part;
		}

		$coupon_item = new WC_Order_Item_Coupon();
		$coupon_item->set_code( $coupon_data['code'] );
		$coupon_item->set_discount( $coupon_data['discount'] );
		$coupon_item->set_discount_tax( 0 );
		$order->add_item( $coupon_item );
	}

	/** Per-unit price to charge: offer price when live, else regular. */
	protected static function unit_price( $product ) {
		return class_exists( 'CPC_Special_Offer' )
			? (float) CPC_Special_Offer::effective_price( $product )
			: (float) $product->get_regular_price();
	}

	protected static function order_payload( WC_Order $order, $detail = false ) {
		$symbol = html_entity_decode( get_woocommerce_currency_symbol( $order->get_currency() ), ENT_QUOTES, 'UTF-8' );
		$status = $order->get_status();

		$payload = array(
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $status,
			'status_label'   => wc_get_order_status_name( $status ),
			'fulfillment'    => CPC_Fulfillment::get_type( $order ),
			'payment_method' => $order->get_payment_method_title(),
			'subtotal'       => (float) $order->get_subtotal(),
			'discount'       => (float) $order->get_discount_total(),
			'delivery_fee'   => (float) $order->get_shipping_total(),
			'total'          => (float) $order->get_total(),
			'total_display'  => $symbol . number_format( (float) $order->get_total(), 2 ),
			'currency'       => $order->get_currency(),
			'created_at'     => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
			'item_count'     => $order->get_item_count(),
			// The day the customer asked for. In the base payload, not just the
			// detail one — the order list, the rider's run and the tracking
			// screen all need to show it.
			'delivery_date'       => CPC_Delivery_Date::get( $order ) ?: null,
			'delivery_date_label' => CPC_Delivery_Date::label( $order ) ?: null,
			'is_scheduled'        => CPC_Delivery_Date::is_scheduled( $order ),
		);

		// Items ride along with every order payload, not just the detail one:
		// an order list that shows only a count forces the app to fetch each
		// order separately just to print "Ground Beef, Ribeye Steak × 2".
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'         => $item->get_name(),
				'quantity'     => $item->get_quantity(),
				'weight'       => $item->get_meta( 'Weight' ) ?: null,
				'cut'          => $item->get_meta( 'Cut preference' ) ?: null,
				'instructions' => $item->get_meta( 'Instructions' ) ?: null,
				'total'        => (float) $item->get_total(),
			);
		}
		$payload['items']         = $items;
		$payload['items_summary'] = self::items_summary( $items );

		if ( $detail ) {
			// Old field name, kept so existing app builds do not break.
			$payload['delivery_time']  = CPC_Delivery_Date::label( $order ) ?: null;
			$payload['customer_note']  = $order->get_customer_note() ?: null;
			$payload['tip']            = (float) $order->get_total_fees();
			if ( 'delivery' === $payload['fulfillment'] ) {
				$payload['delivery_address'] = trim( $order->get_shipping_address_1() . ', ' . $order->get_shipping_city() . ', ' . $order->get_shipping_state() . ' ' . $order->get_shipping_postcode() );
			}
			$rider = $order->get_meta( '_cpc_rider_name' );
			if ( $rider ) { $payload['rider'] = $rider; }
			// Proof of delivery (photo/signature) once the rider uploads one.
			$payload['proof_of_delivery'] = class_exists( 'CPC_REST_Rider' ) ? CPC_REST_Rider::proof_payload( $order ) : null;
			// Lifecycle timestamps for the order-detail timeline.
			$payload['timeline'] = CPC_Order_Statuses::timeline( $order );
			if ( 'failed-delivery' === $status ) {
				$payload['fail_reason'] = $order->get_meta( '_cpc_fail_reason' ) ?: null;
			}
		}

		return $payload;
	}

	/**
	 * One printable line for an order row: "Ground Beef 3.5 lb, Ribeye Steak × 2".
	 *
	 * Weight-sold cuts read better with the weight than a quantity of 1, and a
	 * long order is trimmed so it still fits a list row — the full `items` array
	 * is there for anything that needs every line.
	 */
	protected static function items_summary( array $items, $max = 3 ) {
		$parts = array();
		foreach ( array_slice( $items, 0, $max ) as $item ) {
			if ( ! empty( $item['weight'] ) ) {
				$parts[] = $item['name'] . ' ' . $item['weight'];
			} elseif ( (int) $item['quantity'] > 1 ) {
				$parts[] = $item['name'] . ' × ' . (int) $item['quantity'];
			} else {
				$parts[] = $item['name'];
			}
		}

		$summary = implode( ', ', $parts );
		$extra   = count( $items ) - count( $parts );
		if ( $extra > 0 ) {
			$summary .= sprintf( ' +%d more', $extra );
		}
		return $summary;
	}
}
