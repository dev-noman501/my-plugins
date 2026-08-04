<?php
/**
 * Store Worker Order Queue — the limited WP-dashboard fulfillment screen.
 *
 * Store workers (and managers/admins) see incoming & in-progress orders as
 * cards showing exactly what to pack: each line with qty/weight, cut
 * preference, the customer note, fulfillment type (delivery/pickup) and
 * address. Workers move an order through Accept → Prepare → Ready, all
 * validated against the order-status transition map.
 *
 * This is the top-level "Casa Prime" menu's default page (visible to anyone
 * with cpc_view_order_queue). Delivery Settings / Test Logins remain admin-only
 * submenus.
 */

defined( 'ABSPATH' ) || exit;

class CPC_Order_Queue {

	// Statuses that belong in the active fulfillment queue.
	const QUEUE_STATUSES = array( 'processing', 'preparing', 'ready' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 9 ); // before other submenus
		add_action( 'admin_post_cpc_queue_action', array( __CLASS__, 'handle_action' ) );
	}

	public static function register_menu() {
		add_menu_page( 'Casa Prime', 'Casa Prime', 'cpc_view_order_queue', 'casa-prime', array( __CLASS__, 'render_queue' ), 'dashicons-food', 56 );
		add_submenu_page( 'casa-prime', 'Order Queue', 'Order Queue', 'cpc_view_order_queue', 'casa-prime', array( __CLASS__, 'render_queue' ) );
	}

	/* ---------- Actions ---------- */

	// action key => [from-status, to-status, button label, needs cap]
	public static function get_actions() {
		return array(
			// Accepting an order starts preparation in the same step.
			'accept'   => array( 'processing', 'preparing', 'Accept & Start Preparing', 'cpc_accept_orders' ),
			'reject'   => array( 'processing', 'rejected',  'Reject',                   'cpc_accept_orders' ),
			'ready'    => array( 'preparing',  'ready',     'Mark ready',               'cpc_update_packing_status' ),
			// Pickup handover: a ready pickup order is completed at the counter.
			'handover' => array( 'ready',      'delivered', 'Complete pickup',          'cpc_update_packing_status' ),
		);
	}

	public static function handle_action() {
		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$action   = isset( $_POST['cpc_action'] ) ? sanitize_key( $_POST['cpc_action'] ) : '';

		if ( ! check_admin_referer( 'cpc_queue_' . $action . '_' . $order_id ) ) {
			wp_die( 'Invalid request.' );
		}

		$actions = self::get_actions();
		if ( ! isset( $actions[ $action ] ) ) {
			wp_die( 'Unknown action.' );
		}
		list( $from, $to, $label, $cap ) = $actions[ $action ];

		if ( ! current_user_can( $cap ) ) {
			wp_die( 'You are not allowed to do that.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( 'Order not found.' );
		}

		// The order must currently be in the expected "from" status.
		if ( $order->get_status() !== $from ) {
			self::redirect( 'stale' );
		}
		// And the transition must be allowed by the lifecycle map.
		if ( ! CPC_Order_Statuses::can_transition( $from, $to ) ) {
			self::redirect( 'blocked' );
		}
		// "handover" only applies to pickup orders.
		if ( 'handover' === $action && 'pickup' !== CPC_Fulfillment::get_type( $order ) ) {
			self::redirect( 'blocked' );
		}

		$who = wp_get_current_user()->display_name;
		if ( 'reject' === $action ) {
			$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
			$order->add_order_note( sprintf( 'Rejected by %s. Reason: %s', $who, $reason ?: 'not given' ) );
		} else {
			$order->add_order_note( sprintf( '%s by %s.', $label, $who ) );
		}
		$order->set_status( $to );
		$order->save();

		self::redirect( 'done', $order->get_order_number() . '|' . $label );
	}

	protected static function redirect( $result, $extra = '' ) {
		wp_safe_redirect( add_query_arg( array_filter( array(
			'page'       => 'casa-prime',
			'cpc_result' => $result,
			'cpc_info'   => $extra ? rawurlencode( $extra ) : '',
		) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------- Queue page ---------- */

	public static function render_queue() {
		if ( ! current_user_can( 'cpc_view_order_queue' ) ) {
			wp_die( 'Not allowed.' );
		}

		$orders = wc_get_orders( array(
			'status'  => self::QUEUE_STATUSES,
			'limit'   => 50,
			'orderby' => 'date',
			'order'   => 'ASC', // oldest first — FIFO fulfillment
		) );

		$actions = self::get_actions();
		?>
		<div class="wrap">
			<h1>🧾 Order Queue</h1>
			<p class="description">Incoming and in-progress orders. Oldest first. Pack exactly what each line says.</p>

			<?php self::render_notice(); ?>

			<?php if ( empty( $orders ) ) : ?>
				<div class="notice notice-info"><p>No active orders right now. 🎉</p></div>
			<?php endif; ?>

			<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:16px;">
			<?php foreach ( $orders as $order ) :
				$status  = $order->get_status();
				$type    = CPC_Fulfillment::get_type( $order );
				$is_pick = 'pickup' === $type;
				$sb = $order->get_address( 'shipping' );
				?>
				<div style="flex:1 1 340px;max-width:420px;border:1px solid #dcdcde;border-left:5px solid <?php echo esc_attr( self::status_color( $status ) ); ?>;border-radius:8px;background:#fff;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.05);">
					<div style="display:flex;justify-content:space-between;align-items:center;">
						<strong style="font-size:15px;">Order #<?php echo esc_html( $order->get_order_number() ); ?></strong>
						<span style="background:<?php echo esc_attr( self::status_color( $status ) ); ?>;color:#fff;padding:2px 10px;border-radius:12px;font-size:12px;"><?php echo esc_html( wc_get_order_status_name( $status ) ); ?></span>
					</div>
					<div style="margin:6px 0;color:#555;font-size:13px;">
						<?php echo esc_html( $order->get_formatted_billing_full_name() ); ?> ·
						<span style="font-weight:600;color:<?php echo $is_pick ? '#5a2ea6' : '#1a5dab'; ?>;"><?php echo $is_pick ? '🏪 STORE PICKUP' : '🚚 DELIVERY'; ?></span>
						· <?php echo esc_html( wc_format_datetime( $order->get_date_created(), 'M j, g:i a' ) ); ?>
					</div>

					<table style="width:100%;border-collapse:collapse;margin:10px 0;font-size:13px;">
						<?php foreach ( $order->get_items() as $item ) :
							$weight = $item->get_meta( 'Weight' );
							$cut    = $item->get_meta( 'Cut preference' );
							?>
							<tr style="border-bottom:1px solid #f0f0f1;">
								<td style="padding:5px 0;">
									<strong><?php echo esc_html( $item->get_name() ); ?></strong>
									<?php if ( $cut ) : ?><br><em style="color:#b26a00;">✂ <?php echo esc_html( $cut ); ?></em><?php endif; ?>
								</td>
								<td style="padding:5px 0;text-align:right;white-space:nowrap;font-weight:600;">
									<?php echo $weight ? esc_html( $weight ) : '×' . esc_html( $item->get_quantity() ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>

					<?php if ( ! $is_pick ) : ?>
						<div style="font-size:12px;color:#444;background:#f6f7f7;padding:8px;border-radius:5px;">
							📍 <?php echo esc_html( trim( $sb['address_1'] . ', ' . $sb['city'] . ', ' . $sb['state'] . ' ' . $sb['postcode'] ) ); ?>
						</div>
					<?php endif; ?>

					<?php if ( $order->get_customer_note() ) : ?>
						<div style="font-size:12px;color:#664d03;background:#fff3cd;padding:8px;border-radius:5px;margin-top:6px;">
							📝 <?php echo esc_html( $order->get_customer_note() ); ?>
						</div>
					<?php endif; ?>

					<div style="margin-top:10px;font-size:13px;color:#555;">Total: <strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong> · <?php echo esc_html( $order->get_payment_method_title() ); ?></div>

					<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
						<?php
						foreach ( $actions as $key => $def ) {
							list( $from, $to, $btn, $cap ) = $def;
							if ( $from !== $status || ! current_user_can( $cap ) ) {
								continue;
							}
							if ( 'handover' === $key && ! $is_pick ) {
								continue;
							}
							$primary = in_array( $key, array( 'accept', 'prepare', 'ready', 'handover' ), true );
							?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" <?php echo 'reject' === $key ? 'onsubmit="var r=prompt(\'Reason for rejection?\');if(r===null)return false;this.reason.value=r;"' : ''; ?>>
								<?php wp_nonce_field( 'cpc_queue_' . $key . '_' . $order->get_id() ); ?>
								<input type="hidden" name="action" value="cpc_queue_action" />
								<input type="hidden" name="cpc_action" value="<?php echo esc_attr( $key ); ?>" />
								<input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>" />
								<?php if ( 'reject' === $key ) : ?><input type="hidden" name="reason" value="" /><?php endif; ?>
								<button type="submit" class="button <?php echo $primary ? 'button-primary' : ''; ?>"><?php echo esc_html( $btn ); ?></button>
							</form>
						<?php } ?>

						<?php if ( 'ready' === $status && ! $is_pick ) : ?>
							<span style="font-size:12px;color:#1a7d33;align-self:center;">✔ Packed — waiting for rider assignment (manager)</span>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	protected static function render_notice() {
		if ( empty( $_GET['cpc_result'] ) ) {
			return;
		}
		$result = sanitize_key( $_GET['cpc_result'] );
		$info   = isset( $_GET['cpc_info'] ) ? explode( '|', urldecode( wp_unslash( $_GET['cpc_info'] ) ) ) : array();
		$map = array(
			'done'    => array( 'success', isset( $info[1] ) ? sprintf( 'Order #%s: %s ✓', $info[0], $info[1] ) : 'Done.' ),
			'stale'   => array( 'warning', 'That order already moved on — the queue was refreshed.' ),
			'blocked' => array( 'error', 'That action is not allowed for this order.' ),
		);
		if ( isset( $map[ $result ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $result ][0] ), esc_html( $map[ $result ][1] ) );
		}
	}

	protected static function status_color( $status ) {
		$colors = array(
			'processing' => '#d98800', // needs accepting
			'preparing'  => '#7a4bd6',
			'ready'      => '#1a7d33',
		);
		return $colors[ $status ] ?? '#666';
	}
}
