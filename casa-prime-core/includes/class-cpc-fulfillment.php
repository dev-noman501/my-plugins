<?php
/**
 * Fulfillment handling — Delivery vs Store Pickup.
 *
 * Every order carries `_cpc_fulfillment` meta: 'delivery' or 'pickup'.
 *  - Pickup orders skip the rider leg: ready → delivered (handed over at counter).
 *  - The type is set automatically from the chosen shipping method at checkout
 *    (local_pickup → pickup, anything else → delivery) and can also be set
 *    explicitly by the app through the REST API (later step).
 */

defined( 'ABSPATH' ) || exit;

class CPC_Fulfillment {

	const META_KEY = '_cpc_fulfillment';

	public static function init() {
		// Set fulfillment type from the shipping method when an order is created at checkout.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'set_type_from_shipping' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'set_type_from_shipping' ), 10, 1 );

		// "Fulfillment" column in the admin orders list (HPOS + legacy).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_admin_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_admin_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_admin_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_admin_column_legacy' ), 10, 2 );
	}

	/**
	 * Get the fulfillment type of an order: 'delivery' (default) or 'pickup'.
	 */
	public static function get_type( $order ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order ) {
			return 'delivery';
		}
		$type = $order->get_meta( self::META_KEY );
		return 'pickup' === $type ? 'pickup' : 'delivery';
	}

	/**
	 * Derive the type from the order's shipping method (local_pickup = pickup).
	 */
	public static function set_type_from_shipping( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$type = 'delivery';
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( 'local_pickup' === $method->get_method_id() ) {
				$type = 'pickup';
				break;
			}
		}
		$order->update_meta_data( self::META_KEY, $type );
	}

	/**
	 * Human label, status-aware — a 'ready' pickup order reads "Ready for Pickup".
	 */
	public static function get_label( $order ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order ) {
			return '';
		}
		if ( 'pickup' === self::get_type( $order ) ) {
			return 'ready' === $order->get_status() ? 'Ready for Pickup' : 'Store Pickup';
		}
		$rider = $order->get_meta( '_cpc_rider_name' );
		return $rider ? 'Delivery — ' . $rider : 'Delivery';
	}

	/* ---------- Admin orders list column ---------- */

	public static function add_admin_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new['cpc_fulfillment'] = 'Fulfillment';
			}
		}
		if ( ! isset( $new['cpc_fulfillment'] ) ) {
			$new['cpc_fulfillment'] = 'Fulfillment';
		}
		return $new;
	}

	public static function render_admin_column( $column, $order ) {
		if ( 'cpc_fulfillment' !== $column ) {
			return;
		}
		$is_pickup = 'pickup' === self::get_type( $order );
		printf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;background:%s;color:%s;">%s</span>',
			$is_pickup ? '#f0e6ff' : '#e6f2ff',
			$is_pickup ? '#5a2ea6' : '#1a5dab',
			esc_html( self::get_label( $order ) )
		);
	}

	public static function render_admin_column_legacy( $column, $post_id ) {
		self::render_admin_column( $column, wc_get_order( $post_id ) );
	}
}
