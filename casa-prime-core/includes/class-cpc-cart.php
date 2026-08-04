<?php
/**
 * Server-side cart (per logged-in customer, stored in user meta `cpc_cart`).
 *
 * Exact-charge model: line total = weight x per-lb price (or qty x each price).
 * Prices are ALWAYS computed server-side from the product — the client never
 * sends a price. Weight is validated against the product's step / min / max.
 *
 * A cart item is keyed by product + cut + instructions so identical lines merge.
 */

defined( 'ABSPATH' ) || exit;

class CPC_Cart {




	const META_KEY = 'cpc_cart';

	/* ---------- Raw storage ---------- */

	protected static function raw( $user_id ) {
		$cart = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $cart ) ? $cart : array();
	}

	protected static function save( $user_id, $cart ) {
		update_user_meta( $user_id, self::META_KEY, $cart );
	}

	protected static function key( $product_id, $cut, $instructions ) {
		return substr( md5( $product_id . '|' . $cut . '|' . $instructions ), 0, 12 );
	}

	/* ---------- Mutations ---------- */

	/**
	 * Add (or merge) an item. Returns WP_Error on invalid input.
	 */
	public static function add( $user_id, $product_id, $amount, $cut = '', $instructions = '' ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_Error( 'cpc_bad_product', 'Product not found.', array( 'status' => 404 ) );
		}
		if ( ! $product->is_in_stock() ) {
			return new WP_Error( 'cpc_out_of_stock', $product->get_name() . ' is out of stock.', array( 'status' => 409 ) );
		}

		$sold_by = get_post_meta( $product->get_id(), '_cpc_sold_by', true ) ?: 'each';
		$amount  = self::normalize_amount( $product, $sold_by, (float) $amount );
		if ( is_wp_error( $amount ) ) {
			return $amount;
		}

		$cut          = sanitize_text_field( $cut );
		$instructions = sanitize_textarea_field( $instructions );
		$key          = self::key( $product->get_id(), $cut, $instructions );

		$cart = self::raw( $user_id );
		if ( isset( $cart[ $key ] ) ) {
			$amount = self::normalize_amount( $product, $sold_by, $cart[ $key ]['amount'] + $amount );
			if ( is_wp_error( $amount ) ) { return $amount; }
		}
		$cart[ $key ] = array(
			'product_id'   => $product->get_id(),
			'sold_by'      => $sold_by,
			'amount'       => $amount,
			'cut'          => $cut,
			'instructions' => $instructions,
		);
		self::save( $user_id, $cart );
		return $key;
	}

	/**
	 * Replace an item's amount / cut / instructions.
	 */
	public static function update( $user_id, $key, $amount = null, $cut = null, $instructions = null ) {
		$cart = self::raw( $user_id );
		if ( ! isset( $cart[ $key ] ) ) {
			return new WP_Error( 'cpc_item_not_found', 'Cart item not found.', array( 'status' => 404 ) );
		}
		$item    = $cart[ $key ];
		$product = wc_get_product( $item['product_id'] );
		if ( ! $product ) {
			unset( $cart[ $key ] );
			self::save( $user_id, $cart );
			return new WP_Error( 'cpc_bad_product', 'Product no longer available.', array( 'status' => 404 ) );
		}

		if ( null !== $amount ) {
			$norm = self::normalize_amount( $product, $item['sold_by'], (float) $amount );
			if ( is_wp_error( $norm ) ) { return $norm; }
			$item['amount'] = $norm;
		}
		if ( null !== $cut )          { $item['cut'] = sanitize_text_field( $cut ); }
		if ( null !== $instructions ) { $item['instructions'] = sanitize_textarea_field( $instructions ); }

		// Re-key in case cut/instructions changed (keeps merge behaviour consistent).
		unset( $cart[ $key ] );
		$new_key = self::key( $item['product_id'], $item['cut'], $item['instructions'] );
		$cart[ $new_key ] = $item;
		self::save( $user_id, $cart );
		return $new_key;
	}

	public static function remove( $user_id, $key ) {
		$cart = self::raw( $user_id );
		if ( ! isset( $cart[ $key ] ) ) {
			return new WP_Error( 'cpc_item_not_found', 'Cart item not found.', array( 'status' => 404 ) );
		}
		unset( $cart[ $key ] );
		self::save( $user_id, $cart );
		return true;
	}

	public static function clear( $user_id ) {
		delete_user_meta( $user_id, self::META_KEY );
		if ( class_exists( 'CPC_Coupons' ) ) {
			CPC_Coupons::remove( $user_id );
		}
	}

	/* ---------- Read / format ---------- */

	/**
	 * Full cart with computed prices and totals.
	 */
	public static function format( $user_id ) {
		$cart   = self::format_base( $user_id );
		$coupon = class_exists( 'CPC_Coupons' ) ? CPC_Coupons::calculate( $user_id ) : null;
		if ( is_wp_error( $coupon ) ) {
			CPC_Coupons::remove( $user_id );
			$coupon = null;
		}

		$discount = $coupon ? (float) $coupon['discount'] : 0.0;
		$total    = max( 0, (float) $cart['subtotal'] - $discount );
		$symbol   = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );

		$cart['coupon']           = $coupon;
		$cart['discount']         = $discount;
		$cart['discount_display'] = $symbol . number_format( $discount, 2 );
		$cart['total']            = round( $total, 2 );
		$cart['total_display']    = $symbol . number_format( $total, 2 );
		return $cart;
	}

	/**
	 * Cart before coupon calculation. Kept separate to avoid recursion while a
	 * coupon is being validated.
	 */
	public static function format_base( $user_id ) {
		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$items  = array();
		$subtotal = 0;
		$count = 0;

		foreach ( self::raw( $user_id ) as $key => $item ) {
			$product = wc_get_product( $item['product_id'] );
			if ( ! $product ) { continue; }
			$regular    = (float) $product->get_regular_price();
			// Charge the live offer price when this product is the active special.
			$price      = class_exists( 'CPC_Special_Offer' ) ? (float) CPC_Special_Offer::effective_price( $product ) : $regular;
			$on_offer   = $price < $regular;
			$is_weight  = 'weight' === $item['sold_by'];
			$line_total = round( $item['amount'] * $price, 2 );
			$subtotal  += $line_total;
			$count++;

			// Same limits the server enforces, so the app's +/- stepper can move
			// in legal amounts instead of guessing and being corrected.
			$limits = self::amount_limits( $product, $item['sold_by'] );

			$items[] = array(
				'key'          => $key,
				'product_id'   => $product->get_id(),
				'name'         => $product->get_name(),
				'image'        => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: wc_placeholder_img_src(),
				'sold_by'      => $item['sold_by'],
				'amount'       => (float) $item['amount'],
				'unit'         => $is_weight ? 'lb' : 'each',
				'amount_label' => $is_weight ? $item['amount'] . ' lb' : '×' . (int) $item['amount'],
				'weight_step'  => $limits['step'],
				'min_weight'   => $limits['min'],
				'max_weight'   => $limits['max'],
				'unit_price'   => $price,
				'regular_unit_price' => $regular,
				'on_offer'     => $on_offer,
				'line_total'   => $line_total,
				'line_display' => $symbol . number_format( $line_total, 2 ),
				'cut'          => $item['cut'],
				'instructions' => $item['instructions'],
				'in_stock'     => $product->is_in_stock(),
			);
		}

		return array(
			'item_count'       => $count,
			'items'            => $items,
			'subtotal'         => round( $subtotal, 2 ),
			'subtotal_display' => $symbol . number_format( $subtotal, 2 ),
			'currency'         => get_woocommerce_currency(),
			// Exact-charge model: this subtotal is final for the items; only a
			// delivery fee may be added at checkout. No packing adjustment.
			'exact_charge'     => true,
		);
	}

	/** Raw items for building an order at checkout. */
	public static function items_for_order( $user_id ) {
		return self::raw( $user_id );
	}

	/* ---------- Validation ---------- */

	/**
	 * The step / min / max a product's amount must obey.
	 *
	 * Read from one place so the cart payload advertises exactly the limits the
	 * server enforces — an app stepper built on different numbers would let the
	 * customer pick a weight that then silently snaps to something else.
	 *
	 * Per-each products step by whole units, hence 1 / 1.
	 */
	public static function amount_limits( $product, $sold_by ) {
		if ( 'weight' !== $sold_by ) {
			return array( 'step' => 1.0, 'min' => 1.0, 'max' => null );
		}
		$step = (float) ( get_post_meta( $product->get_id(), '_cpc_weight_step', true ) ?: 0.5 );
		return array(
			'step' => $step,
			'min'  => (float) ( get_post_meta( $product->get_id(), '_cpc_min_weight', true ) ?: $step ),
			'max'  => (float) ( get_post_meta( $product->get_id(), '_cpc_max_weight', true ) ?: 100 ),
		);
	}

	protected static function normalize_amount( $product, $sold_by, $amount ) {
		$limits = self::amount_limits( $product, $sold_by );

		if ( 'weight' === $sold_by ) {
			$step = $limits['step'];
			$min  = $limits['min'];
			$max  = $limits['max'];
			if ( $amount <= 0 ) {
				return new WP_Error( 'cpc_bad_amount', 'Weight must be greater than zero.', array( 'status' => 400 ) );
			}
			// Snap to the nearest step, then clamp into [min, max].
			$amount = round( $amount / $step ) * $step;
			$amount = max( $min, min( $max, $amount ) );
			return round( $amount, 3 );
		}
		$qty = (int) round( $amount );
		if ( $qty < 1 ) {
			return new WP_Error( 'cpc_bad_amount', 'Quantity must be at least 1.', array( 'status' => 400 ) );
		}
		return $qty;
	}
}
