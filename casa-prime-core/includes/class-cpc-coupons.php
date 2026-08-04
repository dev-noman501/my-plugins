<?php
/**
 * WooCommerce coupons for the app's server-side cart.
 */

defined( 'ABSPATH' ) || exit;

class CPC_Coupons {

	const META_KEY = 'cpc_cart_coupon';

	public static function code( $user_id ) {
		return wc_format_coupon_code( (string) get_user_meta( $user_id, self::META_KEY, true ) );
	}

	public static function apply( $user_id, $code ) {
		$code = wc_format_coupon_code( wp_unslash( $code ) );
		if ( '' === $code ) {
			return new WP_Error( 'cpc_coupon_required', 'Please enter a coupon code.', array( 'status' => 400 ) );
		}

		$result = self::calculate( $user_id, $code );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_user_meta( $user_id, self::META_KEY, $code );
		return $result;
	}

	public static function remove( $user_id ) {
		delete_user_meta( $user_id, self::META_KEY );
	}

	/**
	 * Validate a WooCommerce coupon against the current custom cart and return
	 * the monetary discount. Product/category and customer restrictions are
	 * honoured for the coupon types supported by WooCommerce core.
	 */
	public static function calculate( $user_id, $code = null ) {
		$code = null === $code ? self::code( $user_id ) : wc_format_coupon_code( $code );
		if ( ! $code ) {
			return null;
		}

		$coupon = new WC_Coupon( $code );
		if ( ! $coupon->get_id() || 'publish' !== get_post_status( $coupon->get_id() ) ) {
			return self::error( 'cpc_coupon_invalid', 'Coupon code is invalid.' );
		}

		$expires = $coupon->get_date_expires();
		if ( $expires && $expires->getTimestamp() < time() ) {
			return self::error( 'cpc_coupon_expired', 'This coupon has expired.' );
		}
		if ( $coupon->get_usage_limit() && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
			return self::error( 'cpc_coupon_limit', 'This coupon has reached its usage limit.' );
		}

		$user = get_userdata( $user_id );
		if ( $coupon->get_usage_limit_per_user() && $user ) {
			$used_by = array_map( 'strval', $coupon->get_used_by() );
			$uses    = count( array_intersect( $used_by, array( (string) $user_id, (string) $user->user_email ) ) );
			if ( $uses >= $coupon->get_usage_limit_per_user() ) {
				return self::error( 'cpc_coupon_user_limit', 'You have already used this coupon.' );
			}
		}

		$emails = $coupon->get_email_restrictions();
		if ( $emails && ( ! $user || ! self::email_matches( $user->user_email, $emails ) ) ) {
			return self::error( 'cpc_coupon_email', 'This coupon is not valid for your account.' );
		}

		$cart     = CPC_Cart::format_base( $user_id );
		$subtotal = (float) $cart['subtotal'];
		if ( $coupon->get_minimum_amount() && $subtotal < (float) $coupon->get_minimum_amount() ) {
			return self::error( 'cpc_coupon_minimum', sprintf( 'A minimum spend of %s is required.', wc_price( $coupon->get_minimum_amount() ) ) );
		}
		if ( $coupon->get_maximum_amount() && $subtotal > (float) $coupon->get_maximum_amount() ) {
			return self::error( 'cpc_coupon_maximum', sprintf( 'This coupon is valid up to a spend of %s.', wc_price( $coupon->get_maximum_amount() ) ) );
		}

		$eligible = 0.0;
		$units    = 0.0;
		foreach ( $cart['items'] as $item ) {
			$product = wc_get_product( $item['product_id'] );
			if ( ! $product || ! self::product_is_eligible( $coupon, $product ) ) {
				continue;
			}
			$eligible += (float) $item['line_total'];
			$units    += (float) $item['amount'];
		}
		if ( $eligible <= 0 ) {
			return self::error( 'cpc_coupon_products', 'This coupon is not valid for the products in your cart.' );
		}

		switch ( $coupon->get_discount_type() ) {
			case 'percent':
				$discount = $eligible * min( 100, (float) $coupon->get_amount() ) / 100;
				break;
			case 'fixed_product':
				$discount = (float) $coupon->get_amount() * $units;
				break;
			case 'fixed_cart':
				$discount = (float) $coupon->get_amount();
				break;
			default:
				return self::error( 'cpc_coupon_type', 'This coupon type is not supported in the app.' );
		}

		$discount = round( min( $subtotal, $eligible, max( 0, $discount ) ), wc_get_price_decimals() );
		if ( $discount <= 0 ) {
			return self::error( 'cpc_coupon_zero', 'This coupon does not provide a discount for the current cart.' );
		}

		return array(
			'id'               => $coupon->get_id(),
			'code'             => $coupon->get_code(),
			'type'             => $coupon->get_discount_type(),
			'amount'           => (float) $coupon->get_amount(),
			'description'      => $coupon->get_description(),
			'discount'         => $discount,
			'discount_display' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) . number_format( $discount, 2 ),
		);
	}

	protected static function product_is_eligible( WC_Coupon $coupon, WC_Product $product ) {
		$product_id = $product->get_id();
		$parent_id  = $product->get_parent_id();
		$ids        = array_filter( array( $product_id, $parent_id ) );

		if ( $coupon->get_exclude_sale_items() && $product->is_on_sale() ) {
			return false;
		}
		if ( array_intersect( $ids, $coupon->get_excluded_product_ids() ) ) {
			return false;
		}
		$included_products = $coupon->get_product_ids();
		if ( $included_products && ! array_intersect( $ids, $included_products ) ) {
			return false;
		}

		$categories = wc_get_product_cat_ids( $parent_id ?: $product_id );
		if ( array_intersect( $categories, $coupon->get_excluded_product_categories() ) ) {
			return false;
		}
		$included_categories = $coupon->get_product_categories();
		if ( $included_categories && ! array_intersect( $categories, $included_categories ) ) {
			return false;
		}
		return true;
	}

	protected static function email_matches( $email, array $restrictions ) {
		foreach ( $restrictions as $restriction ) {
			if ( function_exists( 'wc_match_wildcard_pattern' ) && wc_match_wildcard_pattern( $email, $restriction ) ) {
				return true;
			}
			if ( strtolower( $email ) === strtolower( $restriction ) ) {
				return true;
			}
		}
		return false;
	}

	protected static function error( $code, $message ) {
		return new WP_Error( $code, wp_strip_all_tags( $message ), array( 'status' => 400 ) );
	}
}
