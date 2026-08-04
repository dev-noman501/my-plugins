<?php
/**
 * Login As User — developer/testing tool.
 *
 * Lets an administrator generate a magic login link for any user and open it
 * (ideally in another browser / incognito window) to experience the site as
 * that customer, rider, store worker or manager — without their password and
 * without disturbing the admin's own session.
 *
 * Security:
 *  - Only administrators (manage_options) can generate links.
 *  - Each link carries a random token; only its hash is stored in user meta.
 *  - Links expire (default 12h) and can be regenerated to invalidate old ones.
 *  - Admins cannot mint a link for another administrator.
 *
 * NOTE: This is a testing aid. Disable or remove it before the production
 * launch, or gate it behind an environment check.
 */

defined( 'ABSPATH' ) || exit;

class CPC_Login_As {

	const META_TOKEN   = '_cpc_login_token';
	const META_EXPIRES = '_cpc_login_token_expires';
	const TTL          = 12 * HOUR_IN_SECONDS;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_cpc_gen_login_link', array( __CLASS__, 'generate_link' ) );
		add_action( 'init', array( __CLASS__, 'maybe_login' ) );
	}

	/* ---------- Admin page: list users + magic links ---------- */

	public static function register_menu() {
		add_submenu_page( 'casa-prime', 'Test Logins', 'Test Logins', 'manage_options', 'casa-prime-logins', array( __CLASS__, 'render_page' ) );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		$roles = array( 'customer', 'rider', 'manager' );
		$users = get_users( array( 'role__in' => $roles, 'orderby' => 'display_name', 'order' => 'ASC' ) );

		$fresh_link = ( isset( $_GET['link'], $_GET['for'] ) ) ? esc_url_raw( urldecode( $_GET['link'] ) ) : '';
		$fresh_for  = isset( $_GET['for'] ) ? (int) $_GET['for'] : 0;
		?>
		<div class="wrap">
			<h1>🔐 Casa Prime — Test Logins</h1>
			<p class="description">Generate a one-click login link for any test user. Open it in a <strong>different browser or an incognito window</strong> so your admin session stays logged in here. Links expire after 12 hours.</p>

			<?php if ( $fresh_link ) : ?>
				<div class="notice notice-success">
					<p><strong>Login link for <?php echo esc_html( get_userdata( $fresh_for )->display_name ); ?>:</strong></p>
					<p>
						<input type="text" readonly value="<?php echo esc_attr( $fresh_link ); ?>" style="width:100%;max-width:820px;padding:6px;font-family:monospace;" onclick="this.select();" id="cpc-fresh-link" />
						<button type="button" class="button button-primary" id="cpc-copy-btn">Copy</button>
						<a class="button" href="<?php echo esc_url( $fresh_link ); ?>" target="_blank">Open in new tab</a>
					</p>
					<script>
					document.getElementById('cpc-copy-btn').addEventListener('click', function () {
						var input = document.getElementById('cpc-fresh-link');
						var btn = this;
						var done = function () { btn.textContent = 'Copied!'; setTimeout(function () { btn.textContent = 'Copy'; }, 1500); };
						// Modern API (HTTPS/localhost only) with a fallback for plain HTTP.
						if (navigator.clipboard && window.isSecureContext) {
							navigator.clipboard.writeText(input.value).then(done, function () { fallback(); });
						} else {
							fallback();
						}
						function fallback() {
							input.focus();
							input.select();
							input.setSelectionRange(0, input.value.length);
							try { document.execCommand('copy'); done(); }
							catch (e) { btn.textContent = 'Press Ctrl+C'; }
						}
					});
					</script>
				</div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:760px;margin-top:16px;">
				<thead><tr><th>User</th><th>Role</th><th>Login</th><th>Action</th></tr></thead>
				<tbody>
				<?php foreach ( $users as $user ) :
					$role = ! empty( $user->roles ) ? $user->roles[0] : '—'; ?>
					<tr>
						<td><strong><?php echo esc_html( $user->display_name ); ?></strong></td>
						<td><?php echo esc_html( wp_roles()->role_names[ $role ] ?? $role ); ?></td>
						<td><code><?php echo esc_html( $user->user_login ); ?></code></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( 'cpc_gen_login_link_' . $user->ID ); ?>
								<input type="hidden" name="action" value="cpc_gen_login_link" />
								<input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>" />
								<button type="submit" class="button">Generate login link</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------- Generate a magic link ---------- */

	public static function generate_link() {
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;

		if ( ! current_user_can( 'manage_options' )
			|| ! check_admin_referer( 'cpc_gen_login_link_' . $user_id ) ) {
			wp_die( 'Not allowed.' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_die( 'User not found.' );
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			wp_die( 'Refusing to create a login link for an administrator.' );
		}

		$token = wp_generate_password( 40, false );
		update_user_meta( $user_id, self::META_TOKEN, wp_hash_password( $token ) );
		update_user_meta( $user_id, self::META_EXPIRES, time() + self::TTL );

		$link = add_query_arg( array(
			'cpc_login' => rawurlencode( $token ),
			'uid'       => $user_id,
		), home_url( '/' ) );

		wp_safe_redirect( add_query_arg( array(
			'page' => 'casa-prime-logins',
			'link' => rawurlencode( $link ),
			'for'  => $user_id,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------- Consume a magic link ---------- */

	public static function maybe_login() {
		if ( empty( $_GET['cpc_login'] ) || empty( $_GET['uid'] ) ) {
			return;
		}

		$user_id = (int) $_GET['uid'];
		$token   = (string) wp_unslash( $_GET['cpc_login'] );

		$stored  = get_user_meta( $user_id, self::META_TOKEN, true );
		$expires = (int) get_user_meta( $user_id, self::META_EXPIRES, true );

		$valid = $stored && $expires > time() && wp_check_password( $token, $stored, $user_id );
		if ( ! $valid ) {
			wp_die( 'This login link is invalid or has expired. Ask an administrator to generate a new one.' );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		// Everyone lands on the role-based Casa Prime Panel (never wp-admin).
		wp_safe_redirect( CPC_Panel::url() );
		exit;
	}
}
