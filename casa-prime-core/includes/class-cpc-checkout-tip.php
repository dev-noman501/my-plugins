<?php
/**
 * Rider-tip selector on the WooCommerce checkout page.
 *
 * The mobile app sends `tip` to the REST /checkout endpoint; the website's
 * default Woo checkout had no way to add one, which made browser testing of the
 * tip → earnings flow awkward. This adds the same $2/$4/$6/Other selector under
 * the shipment row and carries the choice through to the order exactly like the
 * app does (a "Rider tip" fee line + the `_cpc_tip` meta the ledger reads).
 *
 * Web-only convenience; the app path is unaffected.
 */

defined( 'ABSPATH' ) || exit;

class CPC_Checkout_Tip {

	const SESSION_KEY = 'cpc_tip';
	const PRESETS     = array( 2, 4, 6 );

	public static function init() {
		// Show the selector under the shipment row on the checkout review.
		add_action( 'woocommerce_review_order_after_shipping', array( __CLASS__, 'render' ), 20 );
		// Add the tip to the cart total as a fee.
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_fee' ) );
		// Save the tip onto the order + clear the session.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_to_order' ), 20, 2 );
		// AJAX: store the chosen tip, then the page triggers update_checkout.
		add_action( 'wp_ajax_cpc_set_tip', array( __CLASS__, 'ajax_set_tip' ) );
		add_action( 'wp_ajax_nopriv_cpc_set_tip', array( __CLASS__, 'ajax_set_tip' ) );
	}

	protected static function current_tip() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return 0.0;
		}
		return (float) WC()->session->get( self::SESSION_KEY, 0 );
	}

	public static function render() {
		$current = self::current_tip();
		$nonce   = wp_create_nonce( 'cpc_tip' );
		$ajax    = admin_url( 'admin-ajax.php' );

		echo '<tr class="cpc-tip-row"><th>Tip your rider</th><td>';
		echo '<div class="cpc-tip-btns">';
		foreach ( self::PRESETS as $amt ) {
			$active = ( abs( $current - $amt ) < 0.01 ) ? ' active' : '';
			echo '<button type="button" class="cpc-tip-btn' . $active . '" data-tip="' . esc_attr( $amt ) . '">$' . esc_html( $amt ) . '</button>';
		}
		// "Other" — a preset value that isn't one of the buttons counts as custom.
		$is_other = ( $current > 0 && ! in_array( (int) $current, self::PRESETS, true ) );
		echo '<button type="button" class="cpc-tip-btn' . ( $is_other ? ' active' : '' ) . '" data-tip="other">Other</button>';
		echo '<button type="button" class="cpc-tip-btn' . ( 0.0 === $current ? ' active' : '' ) . '" data-tip="0">No tip</button>';
		echo '</div>';
		echo '<input type="number" step="0.01" min="0" class="cpc-tip-other" placeholder="Custom amount $" value="' . ( $is_other ? esc_attr( $current ) : '' ) . '" style="display:' . ( $is_other ? 'block' : 'none' ) . ';margin-top:8px;max-width:180px;" />';
		echo '</td></tr>';

		?>
<style>
.cpc-tip-btns{display:flex;flex-wrap:wrap;gap:8px}
.cpc-tip-btn{border:1px solid #cfd3da;background:#fff;border-radius:8px;padding:7px 14px;cursor:pointer;font-weight:600;font-size:14px}
.cpc-tip-btn.active{background:#1a1a1a;color:#fff;border-color:#1a1a1a}
</style>
<script>
(function(){
	function post(v){
		jQuery.post('<?php echo esc_js( $ajax ); ?>', { action:'cpc_set_tip', tip:v, _wpnonce:'<?php echo esc_js( $nonce ); ?>' }, function(){
			jQuery('body').trigger('update_checkout');
		});
	}
	jQuery(document).on('click','.cpc-tip-btn',function(){
		var v = jQuery(this).data('tip');
		jQuery('.cpc-tip-btn').removeClass('active'); jQuery(this).addClass('active');
		var other = jQuery('.cpc-tip-other');
		if(v==='other'){ other.show().focus(); return; }
		other.hide().val('');
		post(v==='0'?0:v);
	});
	jQuery(document).on('change','.cpc-tip-other',function(){
		var v = parseFloat(jQuery(this).val())||0; post(v);
	});
})();
</script>
		<?php
	}

	public static function add_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		$tip = self::current_tip();
		if ( $tip > 0 ) {
			$cart->add_fee( 'Rider tip', $tip );
		}
	}

	public static function save_to_order( $order, $data ) {
		$tip = self::current_tip();
		$order->update_meta_data( CPC_Earnings::META_TIP, $tip );
		if ( WC()->session ) {
			WC()->session->__unset( self::SESSION_KEY );
		}
	}

	public static function ajax_set_tip() {
		check_ajax_referer( 'cpc_tip', '_wpnonce' );
		$tip = isset( $_POST['tip'] ) ? max( 0, (float) $_POST['tip'] ) : 0;
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $tip );
		}
		wp_send_json_success( array( 'tip' => $tip ) );
	}
}
