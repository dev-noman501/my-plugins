<?php
/**
 * "Casa Prime Delivery" WooCommerce shipping method.
 *
 * Prices delivery at checkout straight from the Delivery Fee Engine:
 *   customer coordinates → distance → tier fee (+ free-delivery threshold).
 *
 * Coordinate sources, in order:
 *   1. The shipping address being checked out, geocoded (Google if a key is
 *      set, else OpenStreetMap)
 *   2. `cpc_lat` / `cpc_lng` user meta — the app's last map pin, used only when
 *      the address is incomplete or cannot be geocoded
 *
 * Out of range → no rate is offered (delivery simply not available);
 * geocoding failure → highest tier fee as a safe fallback so checkout
 * never breaks.
 */

defined( 'ABSPATH' ) || exit;

class CPC_Shipping_Method extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'cpc_delivery';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = 'Casa Prime Delivery';
		$this->method_description = 'Distance-based delivery fee, configured under Casa Prime → Delivery Settings.';
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

		$this->instance_form_fields = array(
			'title' => array(
				'title'   => 'Method title',
				'type'    => 'text',
				'default' => 'Home Delivery',
			),
		);
		$this->title = $this->get_option( 'title', 'Home Delivery' );
	}

	/** Session key holding the "why is there no delivery option" explanation. */
	const NOTICE_KEY = 'cpc_delivery_notice';

	public function calculate_shipping( $package = array() ) {
		$coords = $this->get_customer_coords( $package );

		// Subtotal of the items in this package (for the free-delivery threshold).
		$subtotal = isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : 0;

		if ( ! $coords ) {
			// Cannot locate the customer — charge the highest tier as a safe fallback.
			$fallback = $this->get_highest_tier_fee();
			self::set_notice( '' );
			$this->add_rate( array(
				'id'      => $this->get_rate_id(),
				'label'   => $this->title,
				'cost'    => $fallback,
				'package' => $package,
			) );
			return;
		}

		$quote = CPC_Delivery_Engine::quote( $coords['lat'], $coords['lng'], $subtotal );

		if ( empty( $quote['deliverable'] ) ) {
			// Out of range: no rate is offered, so the delivery option simply
			// vanishes from the list. Leave a message behind or the customer is
			// left wondering where it went.
			self::set_notice( sprintf(
				"Sorry, we don't deliver to this address — it's %.1f miles away and we deliver within %s miles. You can still choose Store Pickup.",
				$quote['distance_miles'],
				rtrim( rtrim( number_format( CPC_Delivery_Settings::get_max_range(), 1 ), '0' ), '.' )
			) );
			return;
		}

		self::set_notice( '' );

		$label = $quote['free']
			? sprintf( 'Free Delivery (%.1f mi)', $quote['distance_miles'] )
			: sprintf( '%s (%.1f mi)', $this->title, $quote['distance_miles'] );

		$this->add_rate( array(
			'id'      => $this->get_rate_id(),
			'label'   => $label,
			'cost'    => (float) $quote['fee'],
			'package' => $package,
		) );
	}

	/* ---------- Out-of-range notice ---------- */

	public static function set_notice( $message ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		if ( '' === $message ) {
			WC()->session->__unset( self::NOTICE_KEY );
		} else {
			WC()->session->set( self::NOTICE_KEY, $message );
		}
	}

	public static function get_notice() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}
		return (string) WC()->session->get( self::NOTICE_KEY, '' );
	}

	/**
	 * Show the notice as an extra row under Shipment, on both cart and checkout.
	 * Hooked from the plugin bootstrap (see casa-prime-core.php).
	 */
	public static function render_notice() {
		$message = self::get_notice();
		if ( ! $message ) {
			return;
		}
		printf(
			'<tr class="cpc-no-delivery"><td colspan="2" style="padding:10px 12px;background:#fcf0f0;border-left:3px solid #b32d2e;color:#8a1f1f;font-size:.9em;line-height:1.5;">%s</td></tr>',
			esc_html( $message )
		);
	}

	/**
	 * Customer coordinates for the address being checked out.
	 *
	 * The address in the checkout form wins. The saved map pin is only a
	 * fallback, because it belongs to whichever address the customer pinned
	 * last — preferring it meant someone could type a Houston address 24 miles
	 * away and still be quoted 0.8 miles from an old pin.
	 */
	protected function get_customer_coords( $package ) {
		$dest  = isset( $package['destination'] ) ? $package['destination'] : array();
		$parts = array_filter( array(
			$dest['address_1'] ?? '',
			$dest['city'] ?? '',
			$dest['state'] ?? '',
			$dest['postcode'] ?? '',
			$dest['country'] ?? '',
		) );

		if ( count( $parts ) >= 2 ) {
			$geo = CPC_Delivery_Engine::geocode( implode( ', ', $parts ) );
			if ( $geo ) {
				return $geo;
			}
			// Geocoding failed (the free service misses some house numbers) —
			// drop through to the pin rather than guessing.
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			$lat = get_user_meta( $user_id, 'cpc_lat', true );
			$lng = get_user_meta( $user_id, 'cpc_lng', true );
			if ( '' !== $lat && '' !== $lng ) {
				return array( 'lat' => (float) $lat, 'lng' => (float) $lng );
			}
		}

		return null;
	}

	protected function get_highest_tier_fee() {
		$fee = 0;
		foreach ( CPC_Delivery_Settings::get_settings()['tiers'] as $tier ) {
			$fee = max( $fee, (float) $tier['fee'] );
		}
		return $fee;
	}
}
