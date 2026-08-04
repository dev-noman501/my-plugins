<?php
/**
 * Delivery settings — store location, distance tiers, free-delivery threshold.
 *
 * Everything is admin-editable (Casa Prime → Delivery Settings) so the client's
 * final pricing plan needs zero code changes: tiers can model flat fees, tiered
 * fees, any free radius, and the max range (beyond the last tier = no delivery).
 */

defined( 'ABSPATH' ) || exit;

class CPC_Delivery_Settings {

	const OPTION_KEY = 'cpc_delivery_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_cpc_save_delivery_settings', array( __CLASS__, 'save' ) );

		// WooCommerce caches calculated shipping rates and has no idea our tiers
		// changed, so a customer would keep seeing the old fee. Hooked on the
		// option itself, so any path that edits the settings clears the cache.
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'flush_shipping_cache' ) );
		add_action( 'add_option_' . self::OPTION_KEY, array( __CLASS__, 'flush_shipping_cache' ) );
	}

	/**
	 * Invalidate WooCommerce's cached shipping rates.
	 */
	public static function flush_shipping_cache() {
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			// Passing true regenerates the version, orphaning every cached rate.
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
		// Drop any "we don't deliver here" message left from the old settings.
		if ( class_exists( 'CPC_Shipping_Method' ) ) {
			CPC_Shipping_Method::set_notice( '' );
		}
	}

	public static function get_defaults() {
		return array(
			'store_address'     => 'Houston, TX (placeholder — set the exact store location)',
			'store_lat'         => 29.7604,
			'store_lng'         => -95.3698,
			'distance_method'   => 'radius', // radius (haversine) | google (Distance Matrix, later)
			'google_api_key'    => '',
			'threshold_enabled' => false,
			'threshold_amount'  => 75,
			// How far ahead a customer may schedule. 0 = today only.
			'schedule_days'     => 7,
			// from_miles / to_miles / fee — beyond the last tier we do not deliver.
			'tiers'             => array(
				array( 'from' => 0, 'to' => 5,  'fee' => 0 ),
				array( 'from' => 5, 'to' => 10, 'fee' => 4.99 ),
			),
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::get_defaults() );
	}

	/**
	 * The maximum deliverable distance = end of the farthest tier.
	 */
	public static function get_max_range( $settings = null ) {
		$settings = $settings ? $settings : self::get_settings();
		$max = 0;
		foreach ( $settings['tiers'] as $tier ) {
			$max = max( $max, (float) $tier['to'] );
		}
		return $max;
	}

	/* ---------- Admin page ---------- */

	public static function register_menu() {
		// Top-level "Casa Prime" menu is created by CPC_Order_Queue (worker-visible).
		// Delivery Settings is an admin-only submenu under it.
		add_submenu_page( 'casa-prime', 'Delivery Settings', 'Delivery Settings', 'manage_options', 'casa-prime-delivery', array( __CLASS__, 'render_page' ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cpc_delivery_settings' ) ) {
			wp_die( 'Not allowed.' );
		}

		$tiers = array();
		if ( isset( $_POST['tier_from'] ) && is_array( $_POST['tier_from'] ) ) {
			foreach ( $_POST['tier_from'] as $i => $from ) {
				$from = (float) $from;
				$to   = isset( $_POST['tier_to'][ $i ] ) ? (float) $_POST['tier_to'][ $i ] : 0;
				$fee  = isset( $_POST['tier_fee'][ $i ] ) ? (float) $_POST['tier_fee'][ $i ] : 0;
				if ( $to > $from ) {
					$tiers[] = array( 'from' => $from, 'to' => $to, 'fee' => max( 0, $fee ) );
				}
			}
		}
		usort( $tiers, function ( $a, $b ) { return $a['from'] <=> $b['from']; } );
		if ( empty( $tiers ) ) {
			$tiers = self::get_defaults()['tiers'];
		}

		update_option( self::OPTION_KEY, array(
			'store_address'     => sanitize_text_field( wp_unslash( $_POST['store_address'] ?? '' ) ),
			'store_lat'         => (float) ( $_POST['store_lat'] ?? 0 ),
			'store_lng'         => (float) ( $_POST['store_lng'] ?? 0 ),
			'distance_method'   => in_array( $_POST['distance_method'] ?? '', array( 'radius', 'google' ), true ) ? $_POST['distance_method'] : 'radius',
			'google_api_key'    => sanitize_text_field( wp_unslash( $_POST['google_api_key'] ?? '' ) ),
			'threshold_enabled' => ! empty( $_POST['threshold_enabled'] ),
			'threshold_amount'  => max( 0, (float) ( $_POST['threshold_amount'] ?? 0 ) ),
			'schedule_days'     => max( 0, min( 60, (int) ( $_POST['schedule_days'] ?? 7 ) ) ),
			'tiers'             => $tiers,
		) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'casa-prime-delivery', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		$s = self::get_settings();
		?>
		<div class="wrap">
			<h1>🥩 Casa Prime — Delivery Settings</h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cpc_delivery_settings' ); ?>
				<input type="hidden" name="action" value="cpc_save_delivery_settings" />

				<h2>Store Location</h2>
				<table class="form-table">
					<tr>
						<th><label for="store_address">Store address</label></th>
						<td><input type="text" class="regular-text" id="store_address" name="store_address" value="<?php echo esc_attr( $s['store_address'] ); ?>" />
						<p class="description">Display only — distance uses the coordinates below.</p></td>
					</tr>
					<tr>
						<th><label for="store_lat">Latitude / Longitude</label></th>
						<td>
							<input type="number" step="0.000001" id="store_lat" name="store_lat" value="<?php echo esc_attr( $s['store_lat'] ); ?>" style="width:140px" />
							<input type="number" step="0.000001" id="store_lng" name="store_lng" value="<?php echo esc_attr( $s['store_lng'] ); ?>" style="width:140px" />
							<p class="description">Google Maps → right-click the store → copy coordinates.</p>
						</td>
					</tr>
					<tr>
						<th><label for="distance_method">Distance method</label></th>
						<td>
							<select id="distance_method" name="distance_method">
								<option value="radius" <?php selected( $s['distance_method'], 'radius' ); ?>>Radius (straight line — free, no API key)</option>
								<option value="google" <?php selected( $s['distance_method'], 'google' ); ?>>Google driving distance (needs API key)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="google_api_key">Google Maps API key</label></th>
						<td><input type="text" class="regular-text" id="google_api_key" name="google_api_key" value="<?php echo esc_attr( $s['google_api_key'] ); ?>" placeholder="Only needed for Google driving distance" /></td>
					</tr>
				</table>

				<h2>Free-Delivery Order Threshold</h2>
				<table class="form-table">
					<tr>
						<th>Enable</th>
						<td><label><input type="checkbox" name="threshold_enabled" value="1" <?php checked( $s['threshold_enabled'] ); ?> />
						Orders at or above <input type="number" step="0.01" name="threshold_amount" value="<?php echo esc_attr( $s['threshold_amount'] ); ?>" style="width:100px" /> USD get free delivery at any deliverable distance.</label></td>
					</tr>
				</table>

				<h2>Delivery Day</h2>
				<table class="form-table">
					<tr>
						<th><label for="schedule_days">Schedule ahead</label></th>
						<td>
							Customers may choose today, or any day up to
							<input type="number" min="0" max="60" id="schedule_days" name="schedule_days" value="<?php echo esc_attr( $s['schedule_days'] ); ?>" style="width:70px" />
							days ahead.
							<p class="description">Set 0 to allow same-day orders only. Applies to both delivery and pickup.</p>
						</td>
					</tr>
				</table>

				<h2>Distance Tiers</h2>
				<p class="description">Fee by distance (miles). Beyond the last tier we do not deliver. Fee 0 = free.</p>
				<table class="widefat striped" style="max-width:520px" id="cpc-tiers">
					<thead><tr><th>From (mi)</th><th>To (mi)</th><th>Fee ($)</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $s['tiers'] as $tier ) : ?>
						<tr>
							<td><input type="number" step="0.1" name="tier_from[]" value="<?php echo esc_attr( $tier['from'] ); ?>" style="width:90px" /></td>
							<td><input type="number" step="0.1" name="tier_to[]" value="<?php echo esc_attr( $tier['to'] ); ?>" style="width:90px" /></td>
							<td><input type="number" step="0.01" name="tier_fee[]" value="<?php echo esc_attr( $tier['fee'] ); ?>" style="width:90px" /></td>
							<td><button type="button" class="button cpc-remove-tier">✕</button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="cpc-add-tier">+ Add tier</button></p>

				<?php submit_button( 'Save Delivery Settings' ); ?>
			</form>
		</div>
		<script>
		document.getElementById('cpc-add-tier').addEventListener('click', function () {
			var tbody = document.querySelector('#cpc-tiers tbody');
			var last = tbody.querySelector('tr:last-child');
			var row = document.createElement('tr');
			var lastTo = last ? parseFloat(last.querySelector('[name="tier_to[]"]').value) || 0 : 0;
			row.innerHTML = '<td><input type="number" step="0.1" name="tier_from[]" value="' + lastTo + '" style="width:90px"/></td>' +
				'<td><input type="number" step="0.1" name="tier_to[]" value="' + (lastTo + 5) + '" style="width:90px"/></td>' +
				'<td><input type="number" step="0.01" name="tier_fee[]" value="0" style="width:90px"/></td>' +
				'<td><button type="button" class="button cpc-remove-tier">✕</button></td>';
			tbody.appendChild(row);
		});
		document.getElementById('cpc-tiers').addEventListener('click', function (e) {
			if (e.target.classList.contains('cpc-remove-tier')) { e.target.closest('tr').remove(); }
		});
		</script>
		<?php
	}
}
