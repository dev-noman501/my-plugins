<?php
/**
 * CLI-only, idempotent test coupon seeder.
 *
 * Run with: php scripts/seed-test-coupons.php
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}

require dirname( __DIR__, 4 ) . '/wp-load.php';

$definitions = array(
	'SAVE10'    => array( 'percent', 10, 0, '10% off the cart.' ),
	'WELCOME15' => array( 'percent', 15, 50, '15% off orders of $50 or more.' ),
	'TAKE5'     => array( 'fixed_cart', 5, 25, '$5 off orders of $25 or more.' ),
	'PRIME20'   => array( 'fixed_cart', 20, 100, '$20 off orders of $100 or more.' ),
);

foreach ( $definitions as $code => $definition ) {
	list( $type, $amount, $minimum, $description ) = $definition;
	$coupon = new WC_Coupon( $code );
	$coupon->set_code( $code );
	$coupon->set_discount_type( $type );
	$coupon->set_amount( $amount );
	$coupon->set_minimum_amount( $minimum );
	$coupon->set_description( $description );
	$coupon->set_status( 'publish' );
	$coupon->set_individual_use( false );
	$coupon->save();
	echo $code . ' (ID ' . $coupon->get_id() . ')' . PHP_EOL;
}
