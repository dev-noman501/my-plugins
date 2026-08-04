<?php
/**
 * Casa Prime Panel — role-based front-end dashboard (the web version of the
 * mobile apps). One URL, `/?cpc_panel=1`, renders a different job-specific
 * screen depending on the logged-in user's role:
 *
 *   customer      → my orders + track + shop link
 *   rider         → my deliveries + availability + live-location ping
 *   manager/admin → prepare orders (accept → prepare → ready), complete pickups,
 *                   assign a rider, rider roster with each rider's current load
 *
 * Self-contained HTML (no theme, no wp-admin) so staff never get bounced to
 * WooCommerce's my-account page. All actions POST back here with a nonce and
 * are capability-checked.
 */

defined( 'ABSPATH' ) || exit;

class CPC_Panel {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'cpc_panel' => 1 ), $args ), home_url( '/' ) );
	}

	public static function maybe_render() {
		if ( ! isset( $_GET['cpc_panel'] ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}

		$flash = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$flash = self::handle_action();
		}

		self::render( $flash );
		exit;
	}

	/* ---------- Role helpers ---------- */

	public static function primary_role( $user = null ) {
		$user = $user ?: wp_get_current_user();
		foreach ( array( 'administrator', 'manager', 'rider', 'customer' ) as $r ) {
			if ( in_array( $r, (array) $user->roles, true ) ) {
				return $r;
			}
		}
		return 'customer';
	}

	/* ---------- Actions ---------- */

	protected static function handle_action() {
		$action = isset( $_POST['cpc_panel_action'] ) ? sanitize_key( $_POST['cpc_panel_action'] ) : '';
		if ( ! $action || ! isset( $_POST['_cpc_nonce'] ) || ! wp_verify_nonce( $_POST['_cpc_nonce'], 'cpc_panel' ) ) {
			return 'error|Invalid request. Please try again.';
		}

		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		$me       = get_current_user_id();

		switch ( $action ) {

			// Step 1 — accepting an order starts preparation immediately.
			case 'accept':   return self::transition( $order, 'processing', 'preparing', 'cpc_accept_orders', 'Accepted, preparing' );
			case 'reject':   return self::transition( $order, 'processing', 'rejected',  'cpc_accept_orders', 'Order rejected' );

			// Step 2a — packed + rider chosen in a single action (delivery orders).
			case 'ready_assign':
				if ( ! current_user_can( 'cpc_assign_riders' ) ) { return 'error|Not allowed.'; }
				if ( ! $order ) { return 'error|Order not found.'; }
				if ( 'pickup' === CPC_Fulfillment::get_type( $order ) ) { return 'error|Pickup orders do not need a rider.'; }
				$rider_id = isset( $_POST['rider_id'] ) ? (int) $_POST['rider_id'] : 0;
				$rider    = $rider_id ? get_userdata( $rider_id ) : null;
				if ( ! $rider || ! in_array( 'rider', (array) $rider->roles, true ) ) { return 'error|Pick a rider first.'; }
				$result = self::transition( $order, 'preparing', 'ready', 'cpc_update_packing_status', 'Ready' );
				if ( 0 === strpos( $result, 'error' ) ) { return $result; }
				$order->update_meta_data( '_cpc_rider_id', $rider_id );
				$order->update_meta_data( '_cpc_rider_name', $rider->display_name );
				$order->add_order_note( sprintf( 'Rider %s assigned by %s.', $rider->display_name, wp_get_current_user()->display_name ) );
				$order->save();
				do_action( 'cpc_rider_assigned', $order, $rider_id );
				return 'ok|#' . $order->get_order_number() . ': ready — ' . $rider->display_name . ' assigned';

			// Step 2b — pickup orders just become ready for the customer.
			case 'ready_pickup':
				if ( ! $order || 'pickup' !== CPC_Fulfillment::get_type( $order ) ) { return 'error|Not a pickup order.'; }
				return self::transition( $order, 'preparing', 'ready', 'cpc_update_packing_status', 'Ready for pickup' );

			case 'handover':
				if ( ! $order || 'pickup' !== CPC_Fulfillment::get_type( $order ) ) { return 'error|Not a pickup order.'; }
				return self::transition( $order, 'ready', 'delivered', 'cpc_update_packing_status', 'Pickup completed' );

			// Manager assigns a rider to a ready delivery order.
			case 'assign_rider':
				if ( ! current_user_can( 'cpc_assign_riders' ) ) { return 'error|Not allowed.'; }
				if ( ! $order || 'ready' !== $order->get_status() ) { return 'error|Order is not ready for assignment.'; }
				$rider_id = isset( $_POST['rider_id'] ) ? (int) $_POST['rider_id'] : 0;
				$rider    = $rider_id ? get_userdata( $rider_id ) : null;
				if ( ! $rider || ! in_array( 'rider', (array) $rider->roles, true ) ) { return 'error|Pick a valid rider.'; }
				$order->update_meta_data( '_cpc_rider_id', $rider_id );
				$order->update_meta_data( '_cpc_rider_name', $rider->display_name );
				$order->add_order_note( sprintf( 'Rider %s assigned by %s.', $rider->display_name, wp_get_current_user()->display_name ) );
				$order->save();
				do_action( 'cpc_rider_assigned', $order, $rider_id );
				return 'ok|' . $rider->display_name . ' assigned to order #' . $order->get_order_number();

			// Rider picks up an assigned, ready delivery order.
			case 'pickup':
				if ( ! current_user_can( 'cpc_update_delivery_status' ) ) { return 'error|Not allowed.'; }
				if ( ! $order || (int) $order->get_meta( '_cpc_rider_id' ) !== $me ) { return 'error|This order is not assigned to you.'; }
				$result = self::transition( $order, 'ready', 'out-for-delivery', 'cpc_update_delivery_status', 'Picked up — out for delivery' );
				// First order off the rack becomes the one they're driving to,
				// so a single-order run needs no extra tap.
				if ( 0 !== strpos( $result, 'error' ) && ! CPC_REST_Rider::get_current_delivery( $me ) ) {
					CPC_REST_Rider::set_current_delivery( $me, $order->get_id() );
				}
				return $result;

			// Rider chooses which order they are driving to right now — only that
			// customer sees the live pin.
			case 'deliver_now':
				if ( ! current_user_can( 'cpc_update_delivery_status' ) ) { return 'error|Not allowed.'; }
				$set = CPC_REST_Rider::set_current_delivery( $me, $order ? $order->get_id() : 0 );
				if ( is_wp_error( $set ) ) { return 'error|' . $set->get_error_message(); }
				return 'ok|Now delivering #' . $order->get_order_number() . ' — customer can see you';

			// Rider completes delivery.
			case 'delivered':
				if ( ! $order || (int) $order->get_meta( '_cpc_rider_id' ) !== $me ) { return 'error|This order is not assigned to you.'; }
				$result = self::transition( $order, 'out-for-delivery', 'delivered', 'cpc_update_delivery_status', 'Delivered' );
				// get_current_delivery() drops a stale pick by itself, but clear it
				// straight away so the next card's button appears immediately.
				if ( 0 !== strpos( $result, 'error' ) && CPC_REST_Rider::get_current_delivery( $me ) === $order->get_id() ) {
					CPC_REST_Rider::set_current_delivery( $me, 0 );
				}
				return $result;

			// Rider confirms COD cash is in hand (same step the app has).
			case 'collect_cash':
				if ( ! current_user_can( 'cpc_update_delivery_status' ) ) { return 'error|Not allowed.'; }
				if ( ! $order || (int) $order->get_meta( '_cpc_rider_id' ) !== $me ) { return 'error|This order is not assigned to you.'; }
				if ( 'out-for-delivery' !== $order->get_status() ) { return 'error|Order is not out for delivery.'; }
				if ( 'cod' !== $order->get_payment_method() ) { return 'error|This order is prepaid — nothing to collect.'; }
				if ( ! $order->get_meta( '_cpc_cash_collected_at' ) ) {
					$order->update_meta_data( '_cpc_cash_collected_at', current_time( 'mysql', true ) );
					$order->add_order_note( sprintf( 'Cash $%s collected by %s.', number_format( (float) $order->get_total(), 2 ), wp_get_current_user()->display_name ) );
					$order->save();
				}
				return 'ok|#' . $order->get_order_number() . ': cash collected — now mark it delivered';

			// Rider reports a failed delivery; the manager's Failed lane takes over.
			case 'fail_delivery':
				if ( ! current_user_can( 'cpc_update_delivery_status' ) ) { return 'error|Not allowed.'; }
				if ( ! $order || (int) $order->get_meta( '_cpc_rider_id' ) !== $me ) { return 'error|This order is not assigned to you.'; }
				if ( 'out-for-delivery' !== $order->get_status() ) { return 'error|Order is not out for delivery.'; }
				$reason = sanitize_text_field( $_POST['reason'] ?? '' );
				if ( '' === $reason ) { return 'error|Give a short reason (e.g. customer unreachable).'; }
				$order->update_meta_data( '_cpc_fail_reason', $reason );
				$order->set_status( 'failed-delivery' );
				$order->add_order_note( 'Delivery failed — ' . $reason . ' (reported by ' . wp_get_current_user()->display_name . ').' );
				$order->save();
				if ( CPC_REST_Rider::get_current_delivery( $me ) === $order->get_id() ) {
					CPC_REST_Rider::set_current_delivery( $me, 0 );
				}
				return 'ok|#' . $order->get_order_number() . ' marked failed — return it to the store';

			// Manager retries a failed delivery with a (possibly different) rider.
			case 'retry_delivery':
				if ( ! current_user_can( 'cpc_assign_riders' ) ) { return 'error|Not allowed.'; }
				if ( ! $order || 'failed-delivery' !== $order->get_status() ) { return 'error|Order is not in the failed lane.'; }
				$rider_id = isset( $_POST['rider_id'] ) ? (int) $_POST['rider_id'] : 0;
				$rider    = $rider_id ? get_userdata( $rider_id ) : null;
				if ( ! $rider || ! in_array( 'rider', (array) $rider->roles, true ) ) { return 'error|Pick a rider first.'; }
				$order->update_meta_data( '_cpc_rider_id', $rider_id );
				$order->update_meta_data( '_cpc_rider_name', $rider->display_name );
				// Fresh attempt: the old arrival stamp belongs to the failed run.
				$order->delete_meta_data( '_cpc_arrived_at' );
				$order->set_status( 'out-for-delivery' );
				$order->add_order_note( sprintf( 'Redelivery: %s assigned by %s.', $rider->display_name, wp_get_current_user()->display_name ) );
				$order->save();
				do_action( 'cpc_rider_assigned', $order, $rider_id );
				return 'ok|#' . $order->get_order_number() . ': redelivery with ' . $rider->display_name;

			// Manager closes a failed delivery. Prepaid orders are refunded
			// through Stripe automatically when it is configured.
			case 'cancel_failed':
				if ( ! current_user_can( 'cpc_assign_riders' ) ) { return 'error|Not allowed.'; }
				if ( ! $order || 'failed-delivery' !== $order->get_status() ) { return 'error|Order is not in the failed lane.'; }
				$refund_note = 'COD — nothing was charged.';
				$flash_note  = '';
				if ( 'cod' !== $order->get_payment_method() ) {
					$refund = class_exists( 'CPC_Stripe' ) ? CPC_Stripe::refund_order( $order ) : new WP_Error( 'off', 'Stripe not available.' );
					if ( is_wp_error( $refund ) ) {
						$refund_note = 'Prepaid — automatic refund not possible (' . $refund->get_error_message() . '). Refund the customer manually.';
						$flash_note  = ' — REFUND MANUALLY (' . $refund->get_error_message() . ')';
					} else {
						$refund_note = 'Prepaid — refunded in full via Stripe (' . $refund['id'] . ').';
						$flash_note  = ' — refunded via Stripe';
					}
				}
				$order->set_status( 'cancelled' );
				$order->add_order_note( 'Failed delivery closed as cancelled by ' . wp_get_current_user()->display_name . '. ' . $refund_note );
				$order->save();
				return 'ok|#' . $order->get_order_number() . ' cancelled' . $flash_note;

			// Manager edits the app's home-screen "Today's Special" banner.
			// The offer lives on the product itself (like Woo's featured star).
			case 'save_offer':
				if ( ! current_user_can( 'cpc_assign_riders' ) ) { return 'error|Not allowed.'; }
				$offer_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
				$on       = ! empty( $_POST['enabled'] ) && $offer_id;

				if ( $offer_id ) {
					CPC_Special_Offer::update_offer( $offer_id, wp_unslash( $_POST ) );
				}
				CPC_Special_Offer::set_special( $on ? $offer_id : 0 );

				if ( ! $on ) { return 'ok|Special offer turned off'; }
				$live = CPC_Special_Offer::get_offer();
				if ( ! $live['active'] ) {
					return 'ok|Saved, but not showing — check the product is in stock and the end time is in the future';
				}
				return 'ok|Special offer is live: ' . $live['headline'];

			// Manager records cash received back from a rider (COD reconciliation).
			case 'settle':
				if ( ! current_user_can( 'cpc_assign_riders' ) ) { return 'error|Not allowed.'; }
				$rider_id = isset( $_POST['rider_id'] ) ? (int) $_POST['rider_id'] : 0;
				$amount   = isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0;
				$rider    = $rider_id ? get_userdata( $rider_id ) : null;
				if ( ! $rider || ! in_array( 'rider', (array) $rider->roles, true ) ) { return 'error|Pick a valid rider.'; }
				if ( $amount <= 0 ) { return 'error|Enter an amount greater than zero.'; }
				CPC_Earnings::add_settlement( $rider_id, $amount, $me, sanitize_text_field( $_POST['note'] ?? '' ) );
				$bal = CPC_Earnings::balance( $rider_id );
				return 'ok|Received $' . number_format( $amount, 2 ) . ' from ' . $rider->display_name . ' — $' . number_format( $bal['cash_pending'], 2 ) . ' still pending';

			// Rider availability.
			case 'set_available':
			case 'set_offline':
				if ( ! current_user_can( 'cpc_set_availability' ) ) { return 'error|Not allowed.'; }
				$status = 'set_available' === $action ? 'available' : 'offline';
				update_user_meta( $me, '_cpc_availability', $status );
				return 'ok|You are now ' . $status;

			// Rider live-location ping (from browser geolocation JS).
			case 'ping':
				if ( ! current_user_can( 'cpc_update_location' ) ) { return 'error|Not allowed.'; }
				update_user_meta( $me, 'cpc_rider_lat', (float) ( $_POST['lat'] ?? 0 ) );
				update_user_meta( $me, 'cpc_rider_lng', (float) ( $_POST['lng'] ?? 0 ) );
				update_user_meta( $me, 'cpc_rider_loc_time', time() );
				return 'ok|location updated';
		}

		return 'error|Unknown action.';
	}

	protected static function transition( $order, $from, $to, $cap, $success ) {
		if ( ! current_user_can( $cap ) ) { return 'error|Not allowed.'; }
		if ( ! $order ) { return 'error|Order not found.'; }
		if ( $order->get_status() !== $from ) { return 'error|That order already moved on.'; }
		if ( ! CPC_Order_Statuses::can_transition( $from, $to ) ) { return 'error|That step is not allowed.'; }
		$order->set_status( $to );
		$order->add_order_note( sprintf( '%s by %s.', $success, wp_get_current_user()->display_name ) );
		$order->save();
		return 'ok|#' . $order->get_order_number() . ': ' . $success;
	}

	/* ---------- Render ---------- */

	protected static function render( $flash ) {
		$user = wp_get_current_user();
		$role = self::primary_role( $user );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$role_labels = array(
			'administrator' => 'Administrator', 'manager' => 'Manager',
			'rider' => 'Rider', 'customer' => 'Customer',
		);
		?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Casa Prime — <?php echo esc_html( $role_labels[ $role ] ?? 'Panel' ); ?></title>
	<style>
		* { box-sizing: border-box; }
		body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:#f4f5f7; color:#1f2430; }
		.topbar { background:#8b1e1e; color:#fff; padding:12px 18px; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:10; }
		.topbar h1 { font-size:17px; margin:0; }
		.topbar .who { font-size:13px; opacity:.9; }
		.topbar a { color:#fff; text-decoration:none; font-size:13px; border:1px solid rgba(255,255,255,.5); padding:5px 10px; border-radius:6px; }
		.wrap { max-width:1000px; margin:0 auto; padding:18px; }
		/* The manager board needs the whole screen. */
		.wrap.wide { max-width:none; padding:16px 20px 24px; }
		.role-badge { display:inline-block; background:#ffe1e1; color:#8b1e1e; font-size:12px; font-weight:600; padding:2px 10px; border-radius:10px; }
		.flash { padding:11px 14px; border-radius:8px; margin-bottom:16px; font-size:14px; }
		.flash.ok { background:#e4f6e6; color:#155724; border:1px solid #b7e0bd; }
		.flash.error { background:#fde7e9; color:#842029; border:1px solid #f2b8bd; }
		.grid { display:flex; flex-wrap:wrap; gap:14px; }
		.card { flex:1 1 300px; max-width:420px; background:#fff; border:1px solid #e3e5e9; border-left:5px solid #999; border-radius:10px; padding:15px; box-shadow:0 1px 3px rgba(0,0,0,.05); }

		/* Kanban board — one column per stage, cards sit in their stage.
		   Columns share the width evenly on big screens and scroll sideways
		   once they hit their minimum, so nothing ever gets squashed. */
		.board { display:flex; gap:12px; align-items:stretch; overflow-x:auto; padding-bottom:10px; scroll-snap-type:x proximity; }
		.col { flex:1 1 0; min-width:265px; background:#e9ebef; border-radius:11px; padding:10px 9px 12px; scroll-snap-align:start; display:flex; flex-direction:column; }
		.col-head { display:flex; justify-content:space-between; align-items:center; padding:2px 5px 10px; font-size:12px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#4b5563; }
		.col-count { background:#fff; color:#374151; border-radius:10px; padding:1px 8px; font-size:11px; }
		.col-body { display:flex; flex-direction:column; gap:9px; max-height:calc(100vh - 250px); overflow-y:auto; overscroll-behavior:contain; padding-right:2px; }
		/* Slim scrollbars so the columns stay tidy. */
		.col-body::-webkit-scrollbar, .board::-webkit-scrollbar { height:8px; width:8px; }
		.col-body::-webkit-scrollbar-thumb, .board::-webkit-scrollbar-thumb { background:#c3c7ce; border-radius:8px; }
		.col .card { flex:none; max-width:none; width:100%; padding:11px 12px; border-radius:9px; box-shadow:0 1px 2px rgba(0,0,0,.06); }
		.col .card h3 { font-size:13.5px; }
		.col .card .meta { font-size:11.5px; margin:2px 0 7px; }
		.col .card .line { font-size:12.5px; padding:4px 0; }
		.col .card .addr, .col .card .note { font-size:11px; padding:6px; }
		.col .card button, .col .card select { font-size:12px; padding:6px 10px; width:100%; }
		.col .card .actions { flex-direction:column; gap:6px; }
		.col-empty { color:#8b909a; font-size:12px; text-align:center; padding:14px 0; }
		/* Live tracking maps (Leaflet + OpenStreetMap). */
		.map { height:360px; border-radius:11px; overflow:hidden; border:1px solid #dfe2e6; background:#e9ebef; }
		.card .map { height:190px; margin-top:9px; border-radius:8px; }
		.map-note { font-size:11.5px; color:#6b7280; margin:6px 2px 0; }
		/* Tablet: keep the board scrollable but give columns a bit more room. */
		@media (max-width:1100px){ .col { min-width:250px; } .col-body { max-height:calc(100vh - 230px); } }
		/* Stage tabs — hidden on desktop, the board shows every column there. */
		.board-tabs { display:none; }
		.board-tabs button { flex:0 0 auto; border:1px solid #d4d8de; background:#fff; color:#374151; border-radius:999px; padding:7px 13px; font-size:12.5px; font-weight:600; white-space:nowrap; }
		.board-tabs button .t-count { background:#eceef1; border-radius:999px; padding:1px 7px; margin-left:5px; font-size:11px; }
		.board-tabs button.active { background:#8b1e1e; border-color:#8b1e1e; color:#fff; }
		.board-tabs button.active .t-count { background:rgba(255,255,255,.25); color:#fff; }

		/* Phone: one stage at a time, chosen from the tab bar. */
		@media (max-width:760px){
			.board-tabs { display:flex; gap:7px; overflow-x:auto; padding:0 0 12px; }
			.board { flex-direction:column; overflow-x:visible; gap:0; }
			.col { flex:1 1 auto; width:100%; min-width:0; display:none; background:transparent; padding:0; }
			.col.active { display:flex; }
			.col-head { display:none; }
			.col-body { max-height:none; }
			.wrap.wide { padding:14px; }
			.topbar { padding:11px 14px; }
			.topbar h1 { font-size:15px; }
			.topbar .who { display:none; }
		}
		.card h3 { margin:0 0 4px; font-size:15px; display:flex; justify-content:space-between; align-items:center; }
		.pill { color:#fff; padding:2px 10px; border-radius:11px; font-size:11px; font-weight:600; }
		.meta { color:#5a6272; font-size:12.5px; margin:3px 0 9px; }
		.line { display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px solid #f0f1f3; font-size:13.5px; }
		.cut { color:#a15c00; font-style:italic; font-size:12px; }
		.instr { color:#0f766e; font-size:12px; }
		.day { font-size:12px; color:#4b5563; margin-top:6px; }
		.day.scheduled { background:#eef2ff; color:#3730a3; font-weight:600; border-radius:6px; padding:5px 8px; display:inline-block; }
		.stat-row { display:flex; flex-wrap:wrap; gap:10px; margin:6px 0 12px; }
		.stat { flex:1 1 130px; background:#fff; border:1px solid #e3e5e9; border-radius:9px; padding:11px 13px; }
		.stat-val { font-size:20px; font-weight:700; line-height:1.2; }
		.stat-label { font-size:11.5px; color:#6b7280; margin-top:2px; }
		.day-group { margin-bottom:12px; }
		.day-head { font-size:12px; font-weight:600; color:#4b5563; background:#eef1f4; padding:6px 10px; border-radius:6px; }
		.earn-row { display:flex; justify-content:space-between; font-size:12.5px; padding:6px 10px; border-bottom:1px solid #f0f1f3; }
		.settle-card { background:#fff; border:1px solid #e3e5e9; border-radius:10px; padding:13px 15px; margin-bottom:10px; }
		.settle-head { display:flex; align-items:center; gap:8px; margin-bottom:5px; }
		.settle-form { display:flex; flex-wrap:wrap; gap:7px; margin-top:9px; }
		.settle-form input[type=number] { width:150px; }
		.settle-form input[type=text] { flex:1 1 140px; }
		.settle-form input { padding:7px 9px; border:1px solid #cfd3da; border-radius:7px; font-size:13px; }
		.settle-log { margin-top:8px; font-size:11.5px; color:#6b7280; }
		.settle-log div { padding:2px 0; }
		.addr { background:#f6f7f9; border-radius:6px; padding:8px; font-size:12px; margin-top:8px; color:#3b4252; }
		.note { background:#fff6da; border-radius:6px; padding:8px; font-size:12px; margin-top:6px; color:#6a5300; }
		.note.rider { background:#e6f4f1; color:#0b5c53; }
		.actions { margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; }
		button, .btn { font-size:13px; padding:8px 14px; border-radius:7px; border:1px solid #c7ccd4; background:#fff; color:#1f2430; cursor:pointer; text-decoration:none; display:inline-block; }
		button.primary { background:#8b1e1e; border-color:#8b1e1e; color:#fff; }
		button.go { background:#1a7d33; border-color:#1a7d33; color:#fff; }
		select { padding:7px; border-radius:6px; border:1px solid #c7ccd4; font-size:13px; }
		.empty { background:#fff; border:1px dashed #cfd3da; border-radius:10px; padding:26px; text-align:center; color:#6b7280; }
		.sec-title { font-size:14px; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; margin:22px 0 10px; }
		.rider-row { display:flex; align-items:center; justify-content:space-between; background:#fff; border:1px solid #e3e5e9; border-radius:9px; padding:11px 14px; margin-bottom:8px; }
		.dot { height:9px; width:9px; border-radius:50%; display:inline-block; margin-right:6px; }
		.offer-form { background:#fff; border:1px solid #e3e5e9; border-radius:9px; padding:14px; max-width:520px; }
		.offer-row { display:block; font-size:12.5px; color:#4b5563; margin-bottom:9px; }
		.offer-row input[type=text], .offer-row input[type=url], .offer-row input[type=datetime-local], .offer-row select {
			display:block; width:100%; box-sizing:border-box; margin-top:3px; padding:7px 9px;
			border:1px solid #cfd3da; border-radius:7px; font-size:13.5px; }
		.offer-row input[type=checkbox] { margin-right:6px; }
	</style>
</head>
<body>
	<div class="topbar">
		<div><h1>🥩 Casa Prime</h1></div>
		<div style="display:flex;align-items:center;gap:12px;">
			<span class="who"><?php echo esc_html( $user->display_name ); ?> · <?php echo esc_html( $role_labels[ $role ] ?? $role ); ?></span>
			<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Log out</a>
		</div>
	</div>
	<div class="wrap<?php echo in_array( $role, array( 'manager', 'administrator' ), true ) ? ' wide' : ''; ?>">
		<?php if ( $flash ) :
			list( $type, $msg ) = array_pad( explode( '|', $flash, 2 ), 2, '' ); ?>
			<div class="flash <?php echo esc_attr( 'ok' === $type ? 'ok' : 'error' ); ?>"><?php echo esc_html( $msg ); ?></div>
		<?php endif; ?>

		<?php
		switch ( $role ) {
			case 'rider':        self::view_rider(); break;
			case 'manager':
			case 'administrator': self::view_manager(); break;
			default:             self::view_customer(); break;
		}
		?>
	</div>
</body>
</html>
		<?php
	}

	protected static function nonce_field() {
		echo '<input type="hidden" name="_cpc_nonce" value="' . esc_attr( wp_create_nonce( 'cpc_panel' ) ) . '" />';
	}

	protected static function status_color( $s ) {
		$c = array(
			'processing' => '#d98800', 'preparing' => '#7a4bd6',
			'ready' => '#1a7d33', 'out-for-delivery' => '#0f766e', 'delivered' => '#4b5563',
			'rejected' => '#b91c1c', 'failed-delivery' => '#b91c1c',
		);
		return $c[ $s ] ?? '#666';
	}

	protected static function order_card_head( $order ) {
		$s = $order->get_status();
		printf(
			'<h3>Order #%s <span class="pill" style="background:%s">%s</span></h3>',
			esc_html( $order->get_order_number() ),
			esc_attr( self::status_color( $s ) ),
			esc_html( wc_get_order_status_name( $s ) )
		);
	}

	/**
	 * The day the customer asked for. A future date is highlighted — packing a
	 * Thursday order on Monday wastes fresh meat, so it has to stand out.
	 */
	protected static function order_day( $order ) {
		$label = CPC_Delivery_Date::label( $order );
		if ( ! $label ) {
			return;
		}
		$scheduled = CPC_Delivery_Date::is_scheduled( $order );
		printf(
			'<div class="day%s">📅 %s%s</div>',
			$scheduled ? ' scheduled' : '',
			esc_html( $label ),
			$scheduled ? ' — scheduled' : ''
		);
	}

	protected static function order_items( $order ) {
		foreach ( $order->get_items() as $item ) {
			$w     = $item->get_meta( 'Weight' );
			$cut   = $item->get_meta( 'Cut preference' );
			$instr = $item->get_meta( 'Instructions' );
			echo '<div class="line"><span>' . esc_html( $item->get_name() );
			if ( $cut ) {
				echo '<br><span class="cut">✂ ' . esc_html( $cut ) . '</span>';
			}
			// How the customer wants this cut prepared — whoever packs the order
			// has to see it, so it belongs on the card, not just in the API.
			if ( $instr ) {
				echo '<br><span class="instr">✎ ' . esc_html( $instr ) . '</span>';
			}
			echo '</span><strong>' . esc_html( $w ? $w : '×' . $item->get_quantity() ) . '</strong></div>';
		}
	}

	protected static function order_addr_note( $order, $show_addr = true ) {
		$is_delivery = 'pickup' !== CPC_Fulfillment::get_type( $order );

		if ( $show_addr && $is_delivery ) {
			$s = $order->get_address( 'shipping' );
			echo '<div class="addr">📍 ' . esc_html( trim( $s['address_1'] . ', ' . $s['city'] . ', ' . $s['state'] . ' ' . $s['postcode'] ) ) . '</div>';
		}

		// Two different notes reach an order and both matter to the rider:
		// the one saved on the address ("gate code", "call me first") and the
		// one typed at checkout for this order only.
		$delivery_notes = $order->get_meta( '_cpc_delivery_notes' );
		if ( $delivery_notes && $is_delivery ) {
			echo '<div class="note rider">🛵 ' . esc_html( $delivery_notes ) . '</div>';
		}
		if ( $order->get_customer_note() ) {
			echo '<div class="note">📝 ' . esc_html( $order->get_customer_note() ) . '</div>';
		}
	}

	protected static function action_form( $action, $order_id, $label, $class = '', $confirm = '', $extra = '' ) {
		$onsubmit = $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"' : '';
		echo '<form method="post" action="' . esc_url( self::url() ) . '"' . $onsubmit . '>';
		self::nonce_field();
		echo '<input type="hidden" name="cpc_panel_action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( $order_id ) . '" />';
		echo $extra;
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Load Leaflet + OpenStreetMap once per page (free, no API key needed).
	 */
	protected static function map_assets() {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;
		echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />';
		echo '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>';
	}

	/** Nonce so the panel's JS can call our REST API with the logged-in cookie. */
	protected static function rest_nonce() {
		return wp_create_nonce( 'wp_rest' );
	}

	/**
	 * Rider <select> showing each rider's availability and current load, so the
	 * manager can spread work sensibly. $selected pre-selects an assigned rider.
	 */
	protected static function rider_select( $riders, $selected = 0 ) {
		// Split by availability so on-duty riders are the obvious choice. Offline
		// riders stay selectable (the manager may have reached one by phone), but
		// picking one asks for confirmation first — see confirm_offline_js().
		$groups = array( 'available' => array(), 'offline' => array() );
		foreach ( $riders as $r ) {
			$av   = 'available' === get_user_meta( $r->ID, '_cpc_availability', true ) ? 'available' : 'offline';
			$load = count( CPC_REST_Rider::get_active_orders( $r->ID ) );
			$groups[ $av ][] = array(
				'id'    => $r->ID,
				'label' => $r->display_name . ' — ' . ( $load ? $load . ' order' . ( $load > 1 ? 's' : '' ) : 'free' ),
				'name'  => $r->display_name,
			);
		}

		$html = '<select name="rider_id" class="rider-pick" data-offline="' . esc_attr( wp_json_encode( wp_list_pluck( $groups['offline'], 'name', 'id' ) ) ) . '">';
		$html .= '<option value="">— choose rider —</option>';

		foreach ( array(
			'available' => '🟢 On duty',
			'offline'   => '🔴 Offline',
		) as $key => $group_label ) {
			if ( ! $groups[ $key ] ) {
				continue;
			}
			$html .= '<optgroup label="' . esc_attr( $group_label ) . '">';
			foreach ( $groups[ $key ] as $r ) {
				$html .= '<option value="' . esc_attr( $r['id'] ) . '"' . selected( $selected, $r['id'], false ) . '>'
					. esc_html( $r['label'] ) . '</option>';
			}
			$html .= '</optgroup>';
		}

		return $html . '</select>';
	}

	/**
	 * Warn — but don't block — when the manager assigns an offline rider.
	 * Printed once per page; the data-offline map on each <select> drives it.
	 */
	protected static function confirm_offline_js() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
<script>
document.addEventListener('submit', function (e) {
	var sel = e.target.querySelector('select.rider-pick');
	if (!sel || !sel.value) { return; }
	var offline = {};
	try { offline = JSON.parse(sel.getAttribute('data-offline') || '{}'); } catch (err) { return; }
	var name = offline[sel.value];
	if (name && !confirm(name + ' is marked OFFLINE.\n\nAssign this order anyway?')) {
		e.preventDefault();
	}
}, true);
</script>
		<?php
	}

	/* ---------- Rider view ---------- */

	protected static function view_rider() {
		$me = wp_get_current_user();
		$availability = get_user_meta( $me->ID, '_cpc_availability', true ) ?: 'offline';
		echo '<span class="role-badge">Your job: pick up &amp; deliver assigned orders</span>';

		// Availability toggle.
		echo '<div style="margin-top:14px;display:flex;align-items:center;gap:12px;">';
		echo '<span>Status: <strong style="color:' . ( 'available' === $availability ? '#1a7d33' : '#b91c1c' ) . '">' . esc_html( ucfirst( $availability ) ) . '</strong></span>';
		echo '<form method="post" action="' . esc_url( self::url() ) . '">';
		self::nonce_field();
		echo '<input type="hidden" name="cpc_panel_action" value="' . ( 'available' === $availability ? 'set_offline' : 'set_available' ) . '" />';
		echo '<button type="submit" class="' . ( 'available' === $availability ? '' : 'go' ) . '">' . ( 'available' === $availability ? 'Go offline' : 'Go available' ) . '</button></form>';
		echo '</div>';

		// Orders assigned to this rider (ready = to pick up, out-for-delivery = in progress).
		$orders = wc_get_orders( array(
			'status'     => array( 'ready', 'out-for-delivery' ),
			'limit'      => 50,
			'meta_key'   => '_cpc_rider_id',
			'meta_value' => $me->ID,
			'orderby'    => 'date',
			'order'      => 'ASC',
		) );

		echo '<div class="sec-title">My deliveries</div>';
		if ( ! $orders ) { echo '<div class="empty">No deliveries assigned to you yet.</div>'; return; }

		// Only the customer of the order the rider picked sees the live pin.
		$current = CPC_REST_Rider::get_current_delivery( $me->ID );

		$has_active = false;
		echo '<div class="grid">';
		foreach ( $orders as $order ) {
			$s         = $order->get_status();
			$is_current = ( $current === $order->get_id() );
			if ( 'out-for-delivery' === $s ) { $has_active = true; }
			echo '<div class="card" style="border-left-color:' . esc_attr( $is_current ? '#0f766e' : self::status_color( $s ) ) . '">';
			if ( $is_current ) {
				echo '<div style="font-size:11.5px;font-weight:600;color:#0f766e;margin-bottom:5px;">📍 DELIVERING NOW — customer sees your location</div>';
			}
			self::order_card_head( $order );
			echo '<div class="meta">' . esc_html( $order->get_formatted_billing_full_name() ) . ' · ' . esc_html( $order->get_billing_phone() ) . '</div>';
			self::order_day( $order );
			self::order_items( $order );
			self::order_addr_note( $order );
			$cod = 'cod' === $order->get_payment_method();
			echo '<div class="meta" style="margin-top:8px;">💵 ' . ( $cod ? 'Collect COD: <strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong>' : 'Prepaid (' . esc_html( $order->get_payment_method_title() ) . ')' ) . '</div>';
			echo '<div class="actions">';
			$maps = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $order->get_shipping_address_1() . ', ' . $order->get_shipping_city() . ', ' . $order->get_shipping_state() );
			echo '<a class="btn" target="_blank" href="' . esc_url( $maps ) . '">🧭 Navigate</a>';
			if ( 'ready' === $s ) {
				self::action_form( 'pickup', $order->get_id(), 'Picked up', 'primary' );
			} elseif ( 'out-for-delivery' === $s ) {
				if ( ! $is_current ) {
					self::action_form( 'deliver_now', $order->get_id(), '📍 Deliver this now', 'primary' );
				}
				// Same flow as the app: COD cash first, then delivered.
				if ( $cod ) {
					if ( $order->get_meta( '_cpc_cash_collected_at' ) ) {
						echo '<div style="font-size:11.5px;color:#1a7d33;align-self:center;">✔ Cash collected</div>';
					} else {
						self::action_form( 'collect_cash', $order->get_id(), '💵 Cash collected ($' . number_format( (float) $order->get_total(), 2 ) . ')', 'primary', 'Confirm you have the cash in hand?' );
					}
				}
				self::action_form( 'delivered', $order->get_id(), 'Mark delivered', 'go', 'Confirm delivery complete?' );
				self::action_form(
					'fail_delivery', $order->get_id(), 'Delivery failed', '', 'Mark this delivery as FAILED and return the order to the store?',
					'<input type="text" name="reason" placeholder="reason — e.g. customer unreachable" required style="width:100%;margin:4px 0;padding:6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;" />'
				);
			}
			echo '</div></div>';
		}
		echo '</div>';

		// Live-location ping while a delivery is active.
		if ( $has_active ) {
			$nonce = wp_create_nonce( 'cpc_panel' );
			$url = esc_url( self::url() );
			echo '<p style="font-size:12px;color:#6b7280;margin-top:14px;">📡 Sharing your live location with the customer while delivering…</p>';
			echo "<script>
			(function(){
				function ping(){
					if(!navigator.geolocation) return;
					navigator.geolocation.getCurrentPosition(function(p){
						var b=new FormData();
						b.append('cpc_panel_action','ping');
						b.append('_cpc_nonce','{$nonce}');
						b.append('lat',p.coords.latitude);
						b.append('lng',p.coords.longitude);
						fetch('{$url}',{method:'POST',body:b,credentials:'same-origin'});
					});
				}
				ping(); setInterval(ping,15000);
			})();
			</script>";
		}

		self::rider_earnings( $me->ID );
	}

	/**
	 * Rider's own money: what they still owe the store (COD), tips earned, and
	 * a record of delivered orders. Read live from orders — always matches.
	 */
	protected static function rider_earnings( $rider_id ) {
		$bal   = CPC_Earnings::balance( $rider_id );
		$today = current_time( 'Y-m-d' );
		$rows  = CPC_Earnings::rider_orders( $rider_id );
		$td    = CPC_Earnings::totals( array_filter( $rows, function ( $r ) use ( $today ) { return $r['date'] === $today; } ) );

		echo '<div class="sec-title">My earnings</div>';

		echo '<div class="stat-row">';
		self::stat( 'Cash to hand over', '$' . number_format( $bal['cash_pending'], 2 ), '#b45309' );
		self::stat( 'Tips earned (all time)', '$' . number_format( $bal['tips_earned'], 2 ), '#1a7d33' );
		self::stat( "Today's deliveries", (string) $td['deliveries'], '#0f766e' );
		self::stat( "Today's tips", '$' . number_format( $td['tips_earned'], 2 ), '#0f766e' );
		echo '</div>';

		echo '<div class="meta" style="margin:4px 0 12px;">💵 COD collected (all time): $' . number_format( $bal['cod_collected'], 2 )
			. ' · handed over: $' . number_format( $bal['cash_received'], 2 ) . '</div>';

		if ( ! $rows ) {
			echo '<div class="empty">No delivered orders yet.</div>';
			return;
		}

		// Group the delivered-order record by day.
		$by_day = array();
		foreach ( $rows as $r ) { $by_day[ $r['date'] ][] = $r; }

		foreach ( $by_day as $day => $day_rows ) {
			$dt = CPC_Earnings::totals( $day_rows );
			echo '<div class="day-group"><div class="day-head">' . esc_html( date_i18n( 'D j M', strtotime( $day ) ) )
				. ' · ' . (int) $dt['deliveries'] . ' deliveries · tips $' . number_format( $dt['tips_earned'], 2 )
				. ( $dt['cod_collected'] > 0 ? ' · COD $' . number_format( $dt['cod_collected'], 2 ) : '' ) . '</div>';
			foreach ( $day_rows as $r ) {
				echo '<div class="earn-row"><span>#' . esc_html( $r['number'] ) . ' · ' . esc_html( ucfirst( $r['payment'] ) ) . '</span><span>';
				if ( $r['cod_collected'] > 0 ) { echo '💵 $' . number_format( $r['cod_collected'], 2 ) . ' &nbsp; '; }
				echo '🎁 $' . number_format( $r['tip'], 2 ) . '</span></div>';
			}
			echo '</div>';
		}
	}

	protected static function stat( $label, $value, $color ) {
		echo '<div class="stat"><div class="stat-val" style="color:' . esc_attr( $color ) . '">' . esc_html( $value )
			. '</div><div class="stat-label">' . esc_html( $label ) . '</div></div>';
	}

	/* ---------- Manager / admin view ---------- */

	protected static function view_manager() {
		echo '<span class="role-badge">Your job: prepare orders, then assign a rider</span>';

		$riders = get_users( array( 'role' => 'rider' ) );
		self::confirm_offline_js();

		// One query per board column — each stage is its own lane.
		$lanes = array(
			'processing'       => array( 'title' => 'New orders',      'color' => '#d98800' ),
			'preparing'        => array( 'title' => 'Preparing',       'color' => '#7a4bd6' ),
			'ready'            => array( 'title' => 'Ready',           'color' => '#1a7d33' ),
			'out-for-delivery' => array( 'title' => 'Out for delivery','color' => '#0f766e' ),
			'failed-delivery'  => array( 'title' => 'Failed',          'color' => '#b91c1c' ),
			'delivered'        => array( 'title' => 'Delivered today', 'color' => '#4b5563' ),
		);

		// Fetch every lane first so the mobile tab bar can show counts.
		$lane_orders = array();
		foreach ( $lanes as $status => $lane ) {
			$args = array( 'status' => array( $status ), 'limit' => 40, 'orderby' => 'date', 'order' => 'ASC' );
			if ( 'delivered' === $status ) {
				// Keep the last lane short — just today's completed work.
				$args['limit']        = 12;
				$args['order']        = 'DESC';
				$args['date_created'] = '>' . ( time() - DAY_IN_SECONDS );
			}
			$lane_orders[ $status ] = wc_get_orders( $args );
		}

		// Mobile-only tab bar: on a phone the board shows one stage at a time.
		echo '<div class="board-tabs">';
		$first = true;
		foreach ( $lanes as $status => $lane ) {
			printf(
				'<button type="button" data-lane="%s" class="%s">%s<span class="t-count">%d</span></button>',
				esc_attr( $status ),
				$first ? 'active' : '',
				esc_html( $lane['title'] ),
				count( $lane_orders[ $status ] )
			);
			$first = false;
		}
		echo '</div>';

		echo '<div class="board">';

		$first = true;
		foreach ( $lanes as $status => $lane ) {
			$orders = $lane_orders[ $status ];

			printf( '<div class="col%s" data-lane="%s">', $first ? ' active' : '', esc_attr( $status ) );
			$first = false;
			printf(
				'<div class="col-head"><span style="color:%s">%s</span><span class="col-count">%d</span></div>',
				esc_attr( $lane['color'] ),
				esc_html( $lane['title'] ),
				count( $orders )
			);
			echo '<div class="col-body">';

			if ( ! $orders ) {
				echo '<div class="col-empty">—</div>';
			}

			foreach ( $orders as $order ) {
				$is_pick  = 'pickup' === CPC_Fulfillment::get_type( $order );
				$assigned = (int) $order->get_meta( '_cpc_rider_id' );

				echo '<div class="card" style="border-left-color:' . esc_attr( $lane['color'] ) . '">';
				printf(
					'<h3>Order #%s <span style="font-size:11px;color:%s">%s</span></h3>',
					esc_html( $order->get_order_number() ),
					$is_pick ? '#5a2ea6' : '#1a5dab',
					$is_pick ? '🏪 PICKUP' : '🚚 DELIVERY'
				);
				echo '<div class="meta">' . esc_html( $order->get_formatted_billing_full_name() ) . ' · ' . esc_html( wc_format_datetime( $order->get_date_created(), 'g:i a' ) ) . '</div>';
				self::order_day( $order );
			self::order_items( $order );
				self::order_addr_note( $order );

				if ( $assigned ) {
					echo '<div class="meta" style="margin-top:7px;">🛵 ' . esc_html( $order->get_meta( '_cpc_rider_name' ) ) . '</div>';
				}

				echo '<div class="actions">';
				switch ( $status ) {
					case 'processing':
						self::action_form( 'accept', $order->get_id(), 'Accept & Start Preparing', 'primary' );
						self::action_form( 'reject', $order->get_id(), 'Reject', '', 'Reject this order?' );
						break;

					case 'preparing':
						if ( $is_pick ) {
							self::action_form( 'ready_pickup', $order->get_id(), 'Ready for Pickup', 'go' );
						} else {
							self::action_form( 'ready_assign', $order->get_id(), 'Ready & Assign Rider', 'go', '', self::rider_select( $riders ) );
						}
						break;

					case 'ready':
						if ( $is_pick ) {
							self::action_form( 'handover', $order->get_id(), 'Complete Pickup', 'go' );
						} else {
							echo '<div style="font-size:11.5px;color:#1a7d33;">✔ Packed — waiting for pickup</div>';
							self::action_form( 'assign_rider', $order->get_id(), $assigned ? 'Change rider' : 'Assign rider', $assigned ? '' : 'primary', '', self::rider_select( $riders, $assigned ) );
						}
						break;

					case 'out-for-delivery':
						echo '<div style="font-size:11.5px;color:#0f766e;">🛵 On the way — the rider will mark it delivered</div>';
						break;

					case 'failed-delivery':
						echo '<div style="font-size:11.5px;color:#b91c1c;">⚠ ' . esc_html( $order->get_meta( '_cpc_fail_reason' ) ?: 'No reason recorded' ) . '</div>';
						echo '<div style="font-size:11px;color:#6b7280;margin:3px 0 4px;">'
							. ( 'cod' === $order->get_payment_method() ? 'COD — nothing charged yet.' : 'Prepaid — cancelling means refunding the customer.' )
							. '</div>';
						self::action_form( 'retry_delivery', $order->get_id(), 'Send again', 'primary', '', self::rider_select( $riders, $assigned ) );
						self::action_form( 'cancel_failed', $order->get_id(), 'Cancel order', '', 'Close this order as cancelled?' . ( 'cod' === $order->get_payment_method() ? '' : ' You will need to refund the customer.' ) );
						break;

					case 'delivered':
						echo '<div style="font-size:11.5px;color:#4b5563;">✅ Completed · ' . wp_kses_post( $order->get_formatted_order_total() ) . '</div>';
						if ( class_exists( 'CPC_REST_Rider' ) && ( $proof = CPC_REST_Rider::proof_payload( $order ) ) && ! empty( $proof['thumbnail'] ) ) {
							echo '<a href="' . esc_url( $proof['image'] ) . '" target="_blank" title="Proof of delivery (' . esc_attr( $proof['method'] ) . ')">'
								. '<img src="' . esc_url( $proof['thumbnail'] ) . '" alt="Proof" style="margin-top:6px;width:54px;height:54px;object-fit:cover;border-radius:7px;border:1px solid #d1d5db;" /></a>';
						}
						break;
				}
				echo '</div></div>';
			}

			echo '</div></div>'; // col-body, col
		}

		echo '</div>'; // board

		// Tab switching (only visible on phones; harmless elsewhere).
		echo "<script>
		(function(){
			var tabs = document.querySelectorAll('.board-tabs button');
			var cols = document.querySelectorAll('.board .col');
			tabs.forEach(function(btn){
				btn.addEventListener('click', function(){
					var lane = btn.getAttribute('data-lane');
					tabs.forEach(function(b){ b.classList.toggle('active', b === btn); });
					cols.forEach(function(c){ c.classList.toggle('active', c.getAttribute('data-lane') === lane); });
					window.scrollTo({ top: 0, behavior: 'smooth' });
				});
			});
		})();
		</script>";

		/* ---- Live rider map ---- */
		$settings  = CPC_Delivery_Settings::get_settings();
		$store_lat = (float) $settings['store_lat'];
		$store_lng = (float) $settings['store_lng'];
		$riders_url = esc_url_raw( rest_url( 'casa-prime/v1/riders' ) );
		$nonce      = self::rest_nonce();

		echo '<div class="sec-title">Live rider map</div>';
		self::map_assets();
		echo '<div id="cpc-rider-map" class="map"></div>';
		echo '<div class="map-note">Updates every 15 seconds · 🏪 store · 🛵 riders</div>';
		echo "<script>
		(function(){
			var map = L.map('cpc-rider-map').setView([{$store_lat}, {$store_lng}], 12);
			L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
				maxZoom: 19, attribution: '&copy; OpenStreetMap'
			}).addTo(map);

			var storeIcon = L.divIcon({ html:'<div style=\"font-size:22px;line-height:22px\">🏪</div>', className:'', iconSize:[22,22], iconAnchor:[11,11] });
			var riderIcon = L.divIcon({ html:'<div style=\"font-size:22px;line-height:22px\">🛵</div>', className:'', iconSize:[22,22], iconAnchor:[11,11] });
			L.marker([{$store_lat}, {$store_lng}], {icon:storeIcon}).addTo(map).bindPopup('Store');

			var markers = {};
			function refresh(){
				fetch('{$riders_url}', { headers:{'X-WP-Nonce':'{$nonce}'}, credentials:'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(res){
						if(!res || !res.data) return;
						var pts = [[{$store_lat}, {$store_lng}]];
						res.data.forEach(function(rd){
							if(!rd.location) return;
							var pos = [rd.location.lat, rd.location.lng];
							pts.push(pos);
							var txt = '<strong>'+rd.name+'</strong><br>'+rd.availability+
								' · '+rd.active_order_count+' order(s)<br><small>'+rd.location.seconds_ago+'s ago</small>';
							if(markers[rd.id]){ markers[rd.id].setLatLng(pos).setPopupContent(txt); }
							else { markers[rd.id] = L.marker(pos, {icon:riderIcon}).addTo(map).bindPopup(txt); }
						});
						if(pts.length > 1){ map.fitBounds(pts, {padding:[45,45], maxZoom:14}); }
					})
					.catch(function(){});
			}
			refresh();
			setInterval(refresh, 15000);
		})();
		</script>";

		/* ---- Riders roster ---- */
		echo '<div class="sec-title">Riders</div><div>';
		foreach ( $riders as $r ) {
			$av     = get_user_meta( $r->ID, '_cpc_availability', true ) ?: 'offline';
			$loc    = CPC_REST_Rider::get_rider_location( $r->ID );
			$active = CPC_REST_Rider::get_active_orders( $r->ID );
			$count  = count( $active );

			echo '<div class="rider-row">';
			echo '<div><span class="dot" style="background:' . ( 'available' === $av ? '#1a7d33' : '#b91c1c' ) . '"></span><strong>' . esc_html( $r->display_name ) . '</strong> <span style="color:#6b7280;font-size:12px;">· ' . esc_html( ucfirst( $av ) ) . '</span>';
			if ( $count ) {
				$nums = array();
				foreach ( $active as $a ) { $nums[] = '#' . $a['number']; }
				echo ' <span class="pill" style="background:#0f766e;margin-left:6px;">' . esc_html( $count ) . ' order' . ( $count > 1 ? 's' : '' ) . '</span>';
				echo ' <span style="color:#6b7280;font-size:12px;">' . esc_html( implode( ', ', $nums ) ) . '</span>';
			} else {
				echo ' <span class="pill" style="background:#9ca3af;margin-left:6px;">free</span>';
			}
			echo '</div><div style="font-size:12px;color:#6b7280;">';
			echo $loc
				? '📍 ' . esc_html( round( $loc['lat'], 4 ) . ', ' . round( $loc['lng'], 4 ) ) . ' (' . esc_html( $loc['seconds_ago'] ) . 's ago)'
				: 'no location yet';
			echo '</div></div>';
		}
		echo '</div>';

		self::rider_settlements( $riders );
		self::special_offer_form();
	}

	/**
	 * Cash reconciliation: what each rider still owes the store from COD, the
	 * tips they've earned, and a form to record cash handed back.
	 */
	protected static function rider_settlements( $riders ) {
		echo '<div class="sec-title">Cash &amp; tips <span style="font-weight:400;font-size:12px;color:#6b7280;">— COD to collect back from riders</span></div>';

		// Store-wide summary line.
		$sum_pending = 0; $sum_cod = 0; $sum_tips = 0; $owing = 0;
		$bals = array();
		foreach ( $riders as $r ) {
			$b = CPC_Earnings::balance( $r->ID );
			$bals[ $r->ID ] = $b;
			$sum_pending += $b['cash_pending'];
			$sum_cod     += $b['cod_collected'];
			$sum_tips    += $b['tips_earned'];
			if ( $b['cash_pending'] > 0.001 ) { $owing++; }
		}
		echo '<div class="stat-row">';
		self::stat( 'COD pending (all riders)', '$' . number_format( $sum_pending, 2 ), '#b45309' );
		self::stat( 'COD collected total', '$' . number_format( $sum_cod, 2 ), '#4b5563' );
		self::stat( 'Tips to pay out', '$' . number_format( $sum_tips, 2 ), '#1a7d33' );
		self::stat( 'Riders owing', (string) $owing, '#0f766e' );
		echo '</div>';

		foreach ( $riders as $r ) {
			$b = $bals[ $r->ID ];
			echo '<div class="settle-card">';
			echo '<div class="settle-head"><strong>' . esc_html( $r->display_name ) . '</strong>';
			echo ' <span class="pill" style="background:' . ( $b['cash_pending'] > 0.001 ? '#b45309' : '#1a7d33' ) . ';">$' . number_format( $b['cash_pending'], 2 ) . ' pending</span></div>';
			echo '<div class="meta">💵 COD collected $' . number_format( $b['cod_collected'], 2 )
				. ' · received $' . number_format( $b['cash_received'], 2 )
				. ' · 🎁 tips $' . number_format( $b['tips_earned'], 2 )
				. ' · ' . (int) $b['deliveries'] . ' deliveries</div>';

			// Record cash received.
			echo '<form method="post" action="' . esc_url( self::url() ) . '" class="settle-form">';
			self::nonce_field();
			echo '<input type="hidden" name="cpc_panel_action" value="settle" />';
			echo '<input type="hidden" name="rider_id" value="' . esc_attr( $r->ID ) . '" />';
			echo '<input type="number" step="0.01" min="0" name="amount" placeholder="Cash received $" />';
			echo '<input type="text" name="note" placeholder="note (optional)" />';
			echo '<button type="submit" class="primary">Record</button>';
			echo '</form>';

			// Recent settlements.
			$settles = array_slice( CPC_Earnings::get_settlements( $r->ID ), 0, 3 );
			if ( $settles ) {
				echo '<div class="settle-log">';
				foreach ( $settles as $sx ) {
					echo '<div>✔ $' . number_format( (float) $sx['amount'], 2 ) . ' · ' . esc_html( $sx['date'] )
						. ' · ' . esc_html( $sx['manager'] ) . ( $sx['note'] ? ' · ' . esc_html( $sx['note'] ) : '' ) . '</div>';
				}
				echo '</div>';
			}
			echo '</div>';
		}
	}

	/**
	 * "Today's Special" editor — the same offer the owner can set in wp-admin,
	 * put here too so the manager can change it mid-shift without leaving the
	 * panel.
	 */
	protected static function special_offer_form() {
		$current = CPC_Special_Offer::get_special_id();
		$offer   = CPC_Special_Offer::get_offer();

		// Fields come off the flagged product, so the panel shows exactly what
		// the product screen shows.
		$val = function ( $meta ) use ( $current ) {
			return $current ? (string) get_post_meta( $current, $meta, true ) : '';
		};

		echo '<div class="sec-title">Today\'s Special <span style="font-weight:400;font-size:12px;color:#6b7280;">— the banner on the app home screen</span></div>';

		if ( $offer['active'] ) {
			$status = '<span style="color:#1a7d33;font-weight:600;">● Showing now</span> — ' . esc_html( $offer['headline'] );
		} elseif ( $current && CPC_Special_Offer::has_expired( $current ) ) {
			$status = '<span style="color:#b45309;font-weight:600;">● Ended</span> — the end time has passed';
		} elseif ( $current ) {
			$status = '<span style="color:#b45309;font-weight:600;">● Hidden</span> — product is out of stock or unpublished';
		} else {
			$status = '<span style="color:#6b7280;font-weight:600;">● Off</span> — nothing showing on the app';
		}
		echo '<div style="margin-bottom:10px;font-size:13px;">' . $status . '</div>';

		echo '<form method="post" action="' . esc_url( self::url() ) . '" class="offer-form">';
		self::nonce_field();
		echo '<input type="hidden" name="cpc_panel_action" value="save_offer" />';

		echo '<label class="offer-row"><input type="checkbox" name="enabled" value="1" ' . checked( (bool) $current, true, false ) . ' /> Show on app</label>';

		echo '<label class="offer-row">Product<select name="product_id"><option value="0">— choose —</option>';
		foreach ( CPC_Special_Offer::product_choices() as $id => $name ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $current, $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></label>';

		foreach ( array(
			'headline'      => array( 'Headline', 'Fresh brisket in', CPC_Special_Offer::META_HEADLINE, 'text' ),
			'price_display' => array( 'Offer price (per lb / each)', '9.49', CPC_Special_Offer::META_PRICE, 'number' ),
			'subtitle'      => array( 'Small line', 'Ends tonight', CPC_Special_Offer::META_SUBTITLE, 'text' ),
		) as $field => $meta ) {
			$attr = 'number' === $meta[3] ? ' step="0.01" min="0"' : '';
			printf(
				'<label class="offer-row">%s<input type="%s"%s name="%s" value="%s" placeholder="%s" /></label>',
				esc_html( $meta[0] ),
				esc_attr( $meta[3] ),
				$attr,
				esc_attr( $field ),
				esc_attr( $val( $meta[2] ) ),
				esc_attr( $meta[1] )
			);
		}

		$ends = $val( CPC_Special_Offer::META_ENDS );
		echo '<label class="offer-row">Ends at<input type="datetime-local" name="ends_at" value="' . esc_attr( $ends ? str_replace( ' ', 'T', $ends ) : '' ) . '" /></label>';
		echo '<label class="offer-row">Image URL<input type="url" name="image" value="' . esc_attr( $val( CPC_Special_Offer::META_IMAGE ) ) . '" placeholder="leave blank to use the product image" /></label>';

		echo '<button type="submit" class="primary">Save offer</button>';
		echo '<div style="font-size:11.5px;color:#6b7280;margin-top:7px;">Blank headline, price or image falls back to the product\'s own. Blank end time means it stays until switched off.</div>';
		echo '</form>';
	}

	/* ---------- Customer view ---------- */

	protected static function view_customer() {
		$me = wp_get_current_user();
		echo '<span class="role-badge">Welcome back, ' . esc_html( $me->first_name ?: $me->display_name ) . '!</span> ';
		echo '<a class="btn" style="margin-left:8px;" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">🛒 Browse shop</a>';

		$orders = wc_get_orders( array( 'customer_id' => $me->ID, 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC' ) );
		echo '<div class="sec-title">My orders</div>';
		if ( ! $orders ) { echo '<div class="empty">You have no orders yet. Browse the shop to place your first order!</div>'; return; }

		echo '<div class="grid">';
		foreach ( $orders as $order ) {
			$s = $order->get_status();
			echo '<div class="card" style="border-left-color:' . esc_attr( self::status_color( $s ) ) . '">';
			self::order_card_head( $order );
			echo '<div class="meta">' . esc_html( wc_format_datetime( $order->get_date_created(), 'M j, Y' ) ) . ' · ' . ( 'pickup' === CPC_Fulfillment::get_type( $order ) ? '🏪 Pickup' : '🚚 Delivery' ) . '</div>';
			self::order_day( $order );
			self::order_items( $order );
			echo '<div class="meta" style="margin-top:8px;">Total: <strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong></div>';

			// Live tracking hint while out for delivery.
			if ( 'out-for-delivery' === $s ) {
				$rider    = $order->get_meta( '_cpc_rider_name' );
				$rider_id = (int) $order->get_meta( '_cpc_rider_id' );

				// A rider carries several orders but drives to one at a time. Only
				// the customer of that one order sees the live pin; everyone else
				// is told to wait. Without this, every out-for-delivery order
				// showed the same rider on the map at once.
				$is_current = ( CPC_REST_Rider::get_current_delivery( $rider_id ) === $order->get_id() );
				$loc        = $is_current ? CPC_REST_Rider::get_rider_location( $rider_id ) : null;

				if ( $is_current ) {
					echo '<div class="note" style="background:#e0f2f1;color:#0f766e;">🛵 ' . esc_html( $rider ?: 'Your rider' ) . ' is on the way!';
					if ( $loc ) { echo ' Last seen ' . esc_html( $loc['seconds_ago'] ) . 's ago.'; }
					echo '</div>';
				} else {
					$others = CPC_REST_Rider::count_other_deliveries( $rider_id, $order->get_id() );
					echo '<div class="note" style="background:#fff6da;color:#6a5300;">📦 ' . esc_html( $rider ?: 'Your rider' ) . ' has your order';
					echo $others > 0
						? ' and is completing another delivery first.'
						: ' and will set off shortly.';
					echo '</div>';
				}

				// Live map: rider marker moves, dashed line to the delivery address.
				if ( $loc ) {
					$dest_lat = (float) ( $order->get_meta( '_cpc_delivery_lat' ) ?: get_user_meta( $me->ID, 'cpc_lat', true ) );
					$dest_lng = (float) ( $order->get_meta( '_cpc_delivery_lng' ) ?: get_user_meta( $me->ID, 'cpc_lng', true ) );
					$map_id    = 'cpc-track-' . $order->get_id();
					$track_url = esc_url_raw( rest_url( 'casa-prime/v1/orders/' . $order->get_id() . '/track' ) );
					$nonce     = self::rest_nonce();
					$rider_js  = esc_js( $rider ?: 'Your rider' );
					$has_dest  = ( $dest_lat && $dest_lng ) ? 'true' : 'false';

					self::map_assets();
					echo '<div id="' . esc_attr( $map_id ) . '" class="map"></div>';
					echo '<div class="map-note">Live · updates every 15 seconds</div>';
					echo "<script>
					(function(){
						var map = L.map('{$map_id}').setView([{$loc['lat']}, {$loc['lng']}], 14);
						L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
							maxZoom: 19, attribution: '&copy; OpenStreetMap'
						}).addTo(map);

						var riderIcon = L.divIcon({ html:'<div style=\"font-size:22px;line-height:22px\">🛵</div>', className:'', iconSize:[22,22], iconAnchor:[11,11] });
						var homeIcon  = L.divIcon({ html:'<div style=\"font-size:20px;line-height:20px\">📍</div>', className:'', iconSize:[20,20], iconAnchor:[10,18] });

						var riderPos = [{$loc['lat']}, {$loc['lng']}];
						var rider = L.marker(riderPos, {icon:riderIcon}).addTo(map).bindPopup('{$rider_js}');
						var hasDest = {$has_dest};
						var dest = hasDest ? [{$dest_lat}, {$dest_lng}] : null;
						var line = null;
						if (hasDest) {
							L.marker(dest, {icon:homeIcon}).addTo(map).bindPopup('Delivery address');
							line = L.polyline([riderPos, dest], {color:'#0f766e', weight:2, dashArray:'5,7'}).addTo(map);
							map.fitBounds([riderPos, dest], {padding:[35,35], maxZoom:15});
						}

						function refresh(){
							fetch('{$track_url}', { headers:{'X-WP-Nonce':'{$nonce}'}, credentials:'same-origin' })
								.then(function(r){ return r.json(); })
								.then(function(res){
									var d = res && res.data ? res.data : null;
									var l = d && d.rider ? d.rider.location : null;
									if(!l){ return; }
									var p = [l.lat, l.lng];
									rider.setLatLng(p);
									if (line) { line.setLatLngs([p, dest]); map.fitBounds([p, dest], {padding:[35,35], maxZoom:15}); }
									else { map.panTo(p); }
								})
								.catch(function(){});
						}
						setInterval(refresh, 15000);
					})();
					</script>";
				}
			} elseif ( 'ready' === $s && 'pickup' === CPC_Fulfillment::get_type( $order ) ) {
				echo '<div class="note" style="background:#e4f6e6;color:#155724;">✅ Ready for pickup at the store!</div>';
			}
			echo '</div>';
		}
		echo '</div>';
	}
}
