<?php
/**
 * Custom WooCommerce order statuses — the Casa Prime order lifecycle.
 *
 * Main chain (exact-charge model, no weight adjustment). Two manager actions:
 *   pending → processing (= Placed, paid)
 *           → [Accept & Start Preparing]  → preparing
 *           → [Ready & Assign Rider]      → ready
 *           → out-for-delivery → delivered
 *
 * Side exits:
 *   rejected (store rejects before/at confirmation → refund/void)
 *   cancelled (customer, allowed until preparing — Woo built-in)
 *   failed-delivery (customer unreachable → back to store)
 *
 * Pickup orders skip the rider leg: ready → delivered (handed to customer),
 * with the fulfillment type stored as order meta (_cpc_fulfillment = delivery|pickup).
 */

defined( 'ABSPATH' ) || exit;

class CPC_Order_Statuses {

	/**
	 * status-key (without wc- prefix) => label
	 */
	const STATUSES = array(
		'preparing'        => 'Preparing / Packing',
		'ready'            => 'Ready',
		'out-for-delivery' => 'Out for Delivery',
		'delivered'        => 'Delivered',
		'failed-delivery'  => 'Failed Delivery',
		'rejected'         => 'Rejected',
	);

	/**
	 * Allowed transitions map (from => [to, ...]) — enforced later by the API layer.
	 * Woo built-in statuses appear without the wc- prefix.
	 */
	const TRANSITIONS = array(
		'pending'          => array( 'processing', 'cancelled', 'failed' ),
		// Accepting an order starts preparation in one step (no separate "confirmed").
		'processing'       => array( 'preparing', 'rejected', 'cancelled' ),
		'preparing'        => array( 'ready' ),
		'ready'            => array( 'out-for-delivery', 'delivered' ), // delivered directly = pickup handover
		'out-for-delivery' => array( 'delivered', 'failed-delivery' ),
		'failed-delivery'  => array( 'out-for-delivery', 'refunded', 'cancelled' ), // retry or resolve
		'rejected'         => array(),
		'delivered'        => array( 'refunded' ), // post-delivery dispute only
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_to_order_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'mark_paid_statuses' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_cancel', array( __CLASS__, 'customer_cancellable_statuses' ), 10, 2 );
		// Stamp every transition so the app's tracking timeline can show when
		// each step happened (Placed 5:02 → Preparing 5:11 → …).
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'stamp_transition' ), 5, 4 );
	}

	/**
	 * Record the moment an order entered a status (order meta `_cpc_ts_<status>`).
	 * Overwrites on purpose: after a failed-delivery retry, the LATEST run is
	 * what the customer's timeline should show.
	 */
	public static function stamp_transition( $order_id, $from, $to, $order ) {
		$tracked = array( 'preparing', 'ready', 'out-for-delivery', 'delivered', 'failed-delivery', 'cancelled', 'rejected' );
		if ( ! in_array( $to, $tracked, true ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) { return; }
		}
		$order->update_meta_data( '_cpc_ts_' . $to, time() );
		$order->save();
	}

	/**
	 * The tracking-screen timeline: when each step of the lifecycle happened,
	 * null for steps not reached yet. `confirmed_at` deliberately mirrors
	 * `preparing_at` — the old separate "confirmed" status was merged into
	 * preparing (Accept starts preparation in one action).
	 */
	public static function timeline( $order ) {
		$iso   = function ( $ts ) { return $ts ? wp_date( 'c', (int) $ts ) : null; };
		$stamp = function ( $status ) use ( $order, $iso ) { return $iso( $order->get_meta( '_cpc_ts_' . $status ) ); };

		$preparing = $stamp( 'preparing' );
		// Delivered has an older, dedicated stamp (earnings) — prefer it.
		$delivered_ts = (int) $order->get_meta( '_cpc_delivered_at' );

		return array(
			'placed_at'           => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
			'confirmed_at'        => $preparing, // merged status: same moment as preparing
			'preparing_at'        => $preparing,
			'ready_at'            => $stamp( 'ready' ),
			'out_for_delivery_at' => $stamp( 'out-for-delivery' ),
			'delivered_at'        => $delivered_ts ? $iso( $delivered_ts ) : $stamp( 'delivered' ),
			'failed_at'           => 'failed-delivery' === $order->get_status() ? $iso( $order->get_meta( '_cpc_failed_at' ) ) : null,
			'cancelled_at'        => $stamp( 'cancelled' ),
		);
	}

	/**
	 * Register each custom status as a post status.
	 */
	public static function register_statuses() {
		foreach ( self::STATUSES as $key => $label ) {
			register_post_status( 'wc-' . $key, array(
				'label'                     => $label,
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop(
					$label . ' <span class="count">(%s)</span>',
					$label . ' <span class="count">(%s)</span>',
					'casa-prime-core'
				),
			) );
		}
	}

	/**
	 * Insert the custom statuses into WooCommerce's status list, right after "processing".
	 */
	public static function add_to_order_statuses( $statuses ) {
		$new = array();
		foreach ( $statuses as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				foreach ( self::STATUSES as $cpc_key => $cpc_label ) {
					$new[ 'wc-' . $cpc_key ] = $cpc_label;
				}
			}
		}
		// Fallback: if processing was not in the list, append at the end.
		foreach ( self::STATUSES as $cpc_key => $cpc_label ) {
			if ( ! isset( $new[ 'wc-' . $cpc_key ] ) ) {
				$new[ 'wc-' . $cpc_key ] = $cpc_label;
			}
		}
		return $new;
	}

	/**
	 * Orders in these statuses have already been paid (prepaid flow) —
	 * keeps stock, reports and payment-status checks correct.
	 */
	public static function mark_paid_statuses( $statuses ) {
		return array_unique( array_merge( $statuses, array(
			'preparing',
			'ready',
			'out-for-delivery',
			'delivered',
		) ) );
	}

	/**
	 * Customers may cancel until the store accepts and starts preparing.
	 */
	public static function customer_cancellable_statuses( $statuses, $order = null ) {
		return array_unique( array_merge( $statuses, array( 'processing' ) ) );
	}

	/**
	 * One-time migration: the "confirmed" step was merged into "preparing", so
	 * any order still sitting in wc-confirmed is moved forward.
	 */
	public static function maybe_migrate() {
		if ( 'merged' === get_option( 'cpc_status_flow_version' ) ) {
			return;
		}
		global $wpdb;

		// Direct SQL: wc-confirmed is no longer a registered status, so a normal
		// order query can't reliably find those rows. Covers both the legacy
		// posts table and WooCommerce's HPOS orders table.
		$moved = (int) $wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'wc-preparing' WHERE post_status = 'wc-confirmed'" );

		$hpos = $wpdb->prefix . 'wc_orders';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos ) ) === $hpos ) {
			$moved += (int) $wpdb->query( "UPDATE `{$hpos}` SET status = 'wc-preparing' WHERE status = 'wc-confirmed'" );
		}

		if ( $moved ) {
			wp_cache_flush();
		}
		update_option( 'cpc_status_flow_version', 'merged' );
	}

	/**
	 * Whether a transition is allowed (used by the REST API layer).
	 */
	public static function can_transition( $from, $to ) {
		$from = str_replace( 'wc-', '', $from );
		$to   = str_replace( 'wc-', '', $to );
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}
}
