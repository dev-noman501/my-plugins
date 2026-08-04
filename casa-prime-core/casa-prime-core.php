<?php
/**
 * Plugin Name:       Casa Prime Core
 * Description:       Core engine for the Casa Prime meat shop platform — roles, order lifecycle, REST APIs, delivery logic and push notifications for the customer, store worker, rider and manager apps.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Casa Prime Dev Team
 * Text Domain:       casa-prime-core
 */

defined( 'ABSPATH' ) || exit;

define( 'CPC_VERSION', '0.1.0' );
define( 'CPC_PLUGIN_FILE', __FILE__ );
define( 'CPC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CPC_PLUGIN_DIR . 'includes/class-cpc-roles.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-order-statuses.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-fulfillment.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-delivery-settings.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-delivery-engine.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-delivery-date.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-login-as.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-order-queue.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-panel.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-jwt.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-cart.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-coupons.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-email.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-special-offer.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-store-contact.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-earnings.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-checkout-tip.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-fcm.php';
require_once CPC_PLUGIN_DIR . 'includes/class-cpc-stripe.php';
require_once CPC_PLUGIN_DIR . 'includes/api/class-cpc-rest-auth.php';
require_once CPC_PLUGIN_DIR . 'includes/api/class-cpc-rest-password.php';
require_once CPC_PLUGIN_DIR . 'includes/api/class-cpc-rest-address.php';
require_once CPC_PLUGIN_DIR . 'includes/api/class-cpc-rest-products.php';
require_once CPC_PLUGIN_DIR . 'includes/api/class-cpc-rest-cart.php';
require_once CPC_PLUGIN_DIR . 'includes/api/class-cpc-rest-checkout.php';
require_once CPC_PLUGIN_DIR . 'includes/api/class-cpc-rest-delivery.php';
require_once CPC_PLUGIN_DIR . 'includes/api/class-cpc-rest-rider.php';
require_once CPC_PLUGIN_DIR . 'includes/api/class-cpc-rest-tracking.php';
require_once CPC_PLUGIN_DIR . 'includes/api/class-cpc-rest-special-offer.php';
require_once CPC_PLUGIN_DIR . 'includes/api/class-cpc-rest-favorites.php';
require_once CPC_PLUGIN_DIR . 'includes/api/class-cpc-rest-device-token.php';

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Clean float precision in all REST JSON output (4.99, not 4.9900000000000002).
 */
add_action( 'rest_api_init', function () {
	@ini_set( 'serialize_precision', '-1' );
} );

/**
 * Authenticate REST requests that carry the app's JWT Bearer token.
 * Priority 20 so it runs after cookie auth but before permission checks.
 */
add_filter( 'determine_current_user', array( 'CPC_JWT', 'authenticate' ), 20 );

/**
 * Boot the plugin once all plugins are loaded (WooCommerce must be active).
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Casa Prime Core:</strong> WooCommerce is required and must be active.</p></div>';
		} );
		return;
	}

	// One-time migrations: retire the store_worker role, merge "confirmed" into "preparing".
	CPC_Roles::maybe_migrate();
	CPC_Order_Statuses::maybe_migrate();

	CPC_Order_Statuses::init();
	CPC_Fulfillment::init();
	CPC_Order_Queue::init();
	CPC_Panel::init();
	CPC_Delivery_Settings::init();
	CPC_Email::init();
	CPC_Special_Offer::init();
	CPC_Store_Contact::init();
	CPC_Earnings::init();
	CPC_Checkout_Tip::init();
	CPC_FCM::init();
	CPC_Stripe::init();
	CPC_Login_As::init();
	CPC_REST_Delivery::init();
	CPC_REST_Rider::init();
	CPC_REST_Tracking::init();
	CPC_REST_Auth::init();
	CPC_REST_Password::init();
	CPC_REST_Address::init();
	CPC_REST_Products::init();
	CPC_REST_Cart::init();
	CPC_REST_Checkout::init();
	CPC_REST_Special_Offer::init();
	CPC_REST_Favorites::init();
	CPC_REST_Device_Token::init();

	// Distance-based delivery as a WooCommerce shipping method.
	add_action( 'woocommerce_shipping_init', function () {
		require_once CPC_PLUGIN_DIR . 'includes/class-cpc-shipping-method.php';
	} );
	add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
		$methods['cpc_delivery'] = 'CPC_Shipping_Method';
		return $methods;
	} );

	// Explain why the delivery option disappeared when the address is out of
	// range. Woo's own "no shipping available" notice never fires here, because
	// Store Pickup is still on offer.
	foreach ( array( 'woocommerce_review_order_after_shipping', 'woocommerce_cart_totals_after_shipping' ) as $hook ) {
		add_action( $hook, function () {
			if ( class_exists( 'CPC_Shipping_Method' ) ) {
				CPC_Shipping_Method::render_notice();
			}
		} );
	}
} );

/**
 * On activation: create the custom roles and capabilities.
 */
register_activation_hook( __FILE__, function () {
	CPC_Roles::add_roles();
} );
