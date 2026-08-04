<?php
/**
 * Email / SMTP configuration.
 *
 * WordPress's default wp_mail() uses PHP mail(), which on many hosts lands in
 * spam or fails. This lets the store configure SMTP (Casa Prime → Email SMTP)
 * so password-reset codes and order emails deliver reliably.
 *
 * Settings can also be defined in wp-config.php via constants (they win over
 * the saved options): CPC_SMTP_HOST, CPC_SMTP_PORT, CPC_SMTP_SECURE,
 * CPC_SMTP_USER, CPC_SMTP_PASS, CPC_SMTP_FROM, CPC_SMTP_FROM_NAME.
 */

defined( 'ABSPATH' ) || exit;

class CPC_Email {

	const OPTION_KEY = 'cpc_smtp';

	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'configure' ) );
		add_filter( 'wp_mail_from', array( __CLASS__, 'from_email' ) );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ) );

		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 11 );
		add_action( 'admin_post_cpc_save_smtp', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_cpc_test_smtp', array( __CLASS__, 'send_test' ) );
	}

	public static function get_settings() {
		$s = wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), array(
			'host' => '', 'port' => 587, 'secure' => 'tls', 'user' => '', 'pass' => '',
			'from_email' => '', 'from_name' => get_bloginfo( 'name' ),
		) );
		// wp-config constants override saved settings.
		foreach ( array(
			'host' => 'CPC_SMTP_HOST', 'port' => 'CPC_SMTP_PORT', 'secure' => 'CPC_SMTP_SECURE',
			'user' => 'CPC_SMTP_USER', 'pass' => 'CPC_SMTP_PASS', 'from_email' => 'CPC_SMTP_FROM', 'from_name' => 'CPC_SMTP_FROM_NAME',
		) as $key => $const ) {
			if ( defined( $const ) && constant( $const ) !== '' ) { $s[ $key ] = constant( $const ); }
		}
		return $s;
	}

	/* ---------- Apply to PHPMailer ---------- */

	public static function configure( $phpmailer ) {
		$s = self::get_settings();
		if ( empty( $s['host'] ) ) {
			return; // no SMTP configured — fall back to default mail()
		}
		$phpmailer->isSMTP();
		$phpmailer->Host       = $s['host'];
		$phpmailer->Port       = (int) $s['port'];
		$phpmailer->SMTPAuth   = ! empty( $s['user'] );
		$phpmailer->Username   = $s['user'];
		$phpmailer->Password   = $s['pass'];
		if ( 'none' === $s['secure'] ) {
			$phpmailer->SMTPSecure = '';
			$phpmailer->SMTPAutoTLS = false;
		} else {
			$phpmailer->SMTPSecure = $s['secure']; // 'tls' or 'ssl'
		}
		if ( ! empty( $s['from_email'] ) ) {
			$phpmailer->setFrom( $s['from_email'], $s['from_name'] );
		}
	}

	public static function from_email( $email ) {
		$s = self::get_settings();
		return ! empty( $s['from_email'] ) ? $s['from_email'] : $email;
	}

	public static function from_name( $name ) {
		$s = self::get_settings();
		return ! empty( $s['from_name'] ) ? $s['from_name'] : $name;
	}

	/* ---------- Admin page ---------- */

	public static function register_menu() {
		add_submenu_page( 'casa-prime', 'Email SMTP', 'Email SMTP', 'manage_options', 'casa-prime-smtp', array( __CLASS__, 'render_page' ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cpc_smtp' ) ) { wp_die( 'Not allowed.' ); }
		$existing = (array) get_option( self::OPTION_KEY, array() );
		update_option( self::OPTION_KEY, array(
			'host'       => sanitize_text_field( wp_unslash( $_POST['host'] ?? '' ) ),
			'port'       => (int) ( $_POST['port'] ?? 587 ),
			'secure'     => in_array( $_POST['secure'] ?? 'tls', array( 'tls', 'ssl', 'none' ), true ) ? $_POST['secure'] : 'tls',
			'user'       => sanitize_text_field( wp_unslash( $_POST['user'] ?? '' ) ),
			// Keep the existing password if the field is left blank.
			'pass'       => ( '' !== ( $_POST['pass'] ?? '' ) ) ? wp_unslash( $_POST['pass'] ) : ( $existing['pass'] ?? '' ),
			'from_email' => sanitize_email( wp_unslash( $_POST['from_email'] ?? '' ) ),
			'from_name'  => sanitize_text_field( wp_unslash( $_POST['from_name'] ?? '' ) ),
		) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'casa-prime-smtp', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function send_test() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cpc_test_smtp' ) ) { wp_die( 'Not allowed.' ); }
		$to = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );
		$ok = $to ? wp_mail( $to, 'Casa Prime SMTP test', 'This is a test email from Casa Prime. If you received it, SMTP works.' ) : false;
		wp_safe_redirect( add_query_arg( array( 'page' => 'casa-prime-smtp', 'test' => $ok ? 'ok' : 'fail' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		$s = self::get_settings();
		?>
		<div class="wrap">
			<h1>📧 Casa Prime — Email (SMTP)</h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p>SMTP settings saved.</p></div><?php endif; ?>
			<?php if ( isset( $_GET['test'] ) ) : ?><div class="notice notice-<?php echo 'ok' === $_GET['test'] ? 'success' : 'error'; ?>"><p><?php echo 'ok' === $_GET['test'] ? 'Test email sent (check the inbox / spam).' : 'Test email failed — check the settings.'; ?></p></div><?php endif; ?>
			<p class="description">Set your SMTP details so password-reset and order emails deliver reliably. Common: host <code>smtp.gmail.com</code> / <code>mail.yourdomain.com</code>, port 587 (TLS) or 465 (SSL).</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cpc_smtp' ); ?>
				<input type="hidden" name="action" value="cpc_save_smtp" />
				<table class="form-table">
					<tr><th>SMTP Host</th><td><input type="text" class="regular-text" name="host" value="<?php echo esc_attr( $s['host'] ); ?>" placeholder="mail.yourdomain.com" /></td></tr>
					<tr><th>Port</th><td><input type="number" name="port" value="<?php echo esc_attr( $s['port'] ); ?>" style="width:100px" /></td></tr>
					<tr><th>Encryption</th><td>
						<select name="secure">
							<option value="tls" <?php selected( $s['secure'], 'tls' ); ?>>TLS (587)</option>
							<option value="ssl" <?php selected( $s['secure'], 'ssl' ); ?>>SSL (465)</option>
							<option value="none" <?php selected( $s['secure'], 'none' ); ?>>None</option>
						</select></td></tr>
					<tr><th>Username</th><td><input type="text" class="regular-text" name="user" value="<?php echo esc_attr( $s['user'] ); ?>" placeholder="you@yourdomain.com" /></td></tr>
					<tr><th>Password</th><td><input type="password" class="regular-text" name="pass" value="" placeholder="<?php echo $s['pass'] ? '•••••• (leave blank to keep)' : ''; ?>" /></td></tr>
					<tr><th>From Email</th><td><input type="email" class="regular-text" name="from_email" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="noreply@yourdomain.com" /></td></tr>
					<tr><th>From Name</th><td><input type="text" class="regular-text" name="from_name" value="<?php echo esc_attr( $s['from_name'] ); ?>" /></td></tr>
				</table>
				<?php submit_button( 'Save SMTP Settings' ); ?>
			</form>

			<hr />
			<h2>Send a test email</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cpc_test_smtp' ); ?>
				<input type="hidden" name="action" value="cpc_test_smtp" />
				<input type="email" name="test_email" class="regular-text" placeholder="send test to..." required />
				<?php submit_button( 'Send Test', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
