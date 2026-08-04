<?php
/**
 * Store contact details — the phone / WhatsApp / email the app's "Contact us"
 * or "Help" screen shows.
 *
 * Admin edits them at Casa Prime → Store Contact; the app reads them from the
 * public GET /store/contact endpoint. Kept as one option so there is a single
 * source of truth for both.
 */

defined( 'ABSPATH' ) || exit;

class CPC_Store_Contact {

	const OPTION_KEY     = 'cpc_store_contact';
	const REST_NAMESPACE = 'casa-prime/v1';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_cpc_save_store_contact', array( __CLASS__, 'save' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function get_defaults() {
		return array(
			'phone'         => '',
			'whatsapp'      => '',
			'email'         => '',
			'hours'         => '',
			'address'       => '',
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::get_defaults() );
	}

	/* ---------- REST ---------- */

	public static function register_routes() {
		register_rest_route( self::REST_NAMESPACE, '/store/contact', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_contact' ),
			'permission_callback' => '__return_true', // shown on a help screen, no login needed
		) );
	}

	public static function get_contact( $request = null ) {
		$s = self::get_settings();

		// A digits-only version of each number, handy for tel: / wa.me links.
		$phone_digits    = preg_replace( '/[^0-9]/', '', $s['phone'] );
		$whatsapp_digits = preg_replace( '/[^0-9]/', '', $s['whatsapp'] );

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'phone'            => $s['phone'],
				'phone_link'       => $phone_digits ? 'tel:' . $phone_digits : null,
				'whatsapp'         => $s['whatsapp'],
				'whatsapp_link'    => $whatsapp_digits ? 'https://wa.me/' . $whatsapp_digits : null,
				'email'            => $s['email'],
				'email_link'       => $s['email'] ? 'mailto:' . $s['email'] : null,
				'hours'            => $s['hours'],
				'address'          => $s['address'],
			),
		) );
	}

	/* ---------- Admin page ---------- */

	public static function register_menu() {
		add_submenu_page( 'casa-prime', 'Store Contact', 'Store Contact', 'manage_options', 'casa-prime-contact', array( __CLASS__, 'render_page' ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cpc_store_contact' ) ) {
			wp_die( 'Not allowed.' );
		}
		update_option( self::OPTION_KEY, array(
			'phone'    => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'whatsapp' => sanitize_text_field( wp_unslash( $_POST['whatsapp'] ?? '' ) ),
			'email'    => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'hours'    => sanitize_text_field( wp_unslash( $_POST['hours'] ?? '' ) ),
			'address'  => sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) ),
		) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'casa-prime-contact', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		$s = self::get_settings();
		?>
		<div class="wrap">
			<h1>📞 Casa Prime — Store Contact</h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Contact details saved.</p></div>
			<?php endif; ?>
			<p class="description">These show on the app's Contact / Help screen.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cpc_store_contact' ); ?>
				<input type="hidden" name="action" value="cpc_save_store_contact" />
				<table class="form-table">
					<tr>
						<th><label for="phone">Phone number</label></th>
						<td><input type="text" class="regular-text" id="phone" name="phone" value="<?php echo esc_attr( $s['phone'] ); ?>" placeholder="+1 713 555 0100" /></td>
					</tr>
					<tr>
						<th><label for="whatsapp">WhatsApp number</label></th>
						<td><input type="text" class="regular-text" id="whatsapp" name="whatsapp" value="<?php echo esc_attr( $s['whatsapp'] ); ?>" placeholder="+1 713 555 0100" />
						<p class="description">The app opens wa.me/&lt;number&gt;. Include the country code.</p></td>
					</tr>
					<tr>
						<th><label for="email">Email</label></th>
						<td><input type="email" class="regular-text" id="email" name="email" value="<?php echo esc_attr( $s['email'] ); ?>" placeholder="support@casaprime.com" /></td>
					</tr>
					<tr>
						<th><label for="hours">Opening hours</label></th>
						<td><input type="text" class="regular-text" id="hours" name="hours" value="<?php echo esc_attr( $s['hours'] ); ?>" placeholder="Mon–Sat, 9am–9pm" /></td>
					</tr>
					<tr>
						<th><label for="address">Store address</label></th>
						<td><input type="text" class="large-text" id="address" name="address" value="<?php echo esc_attr( $s['address'] ); ?>" placeholder="9430 Fry Road Suite 1000, Cypress, TX 77433" /></td>
					</tr>
				</table>
				<?php submit_button( 'Save contact details' ); ?>
			</form>

			<h2>What the app receives</h2>
			<pre style="background:#fff;padding:12px;border:1px solid #ccd0d4;max-width:640px;overflow:auto;"><?php
				echo esc_html( wp_json_encode( self::get_contact( null )->get_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			?></pre>
		</div>
		<?php
	}
}
