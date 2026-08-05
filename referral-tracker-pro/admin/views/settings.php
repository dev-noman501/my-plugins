<?php
/**
 * Settings view.
 *
 * @package ReferralTrackerPro
 *
 * @var array $settings
 * @var bool  $saved
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap rtp-wrap">
	<h1><?php esc_html_e( 'Referral Tracker Settings', 'referral-tracker-pro' ); ?></h1>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'referral-tracker-pro' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rtp-form">
		<input type="hidden" name="action" value="rtp_save_settings" />
		<?php wp_nonce_field( 'rtp_save_settings' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="rtp-cookie-days"><?php esc_html_e( 'Cookie expiry (days)', 'referral-tracker-pro' ); ?></label></th>
				<td>
					<input type="number" min="1" max="365" id="rtp-cookie-days" name="rtp_settings[cookie_expiry_days]" value="<?php echo esc_attr( $settings['cookie_expiry_days'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'How long a referral stays attributed after the first visit (first-touch).', 'referral-tracker-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rtp-retention"><?php esc_html_e( 'Data retention (days)', 'referral-tracker-pro' ); ?></label></th>
				<td>
					<input type="number" min="7" max="3650" id="rtp-retention" name="rtp_settings[retention_days]" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Events older than this are automatically deleted. Referral definitions are always kept.', 'referral-tracker-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Tracking', 'referral-tracker-pro' ); ?></th>
				<td>
					<label><input type="checkbox" name="rtp_settings[enable_call_tracking]" value="1" <?php checked( $settings['enable_call_tracking'], 1 ); ?> /> <?php esc_html_e( 'Enable call (tel: link) tracking', 'referral-tracker-pro' ); ?></label><br />
					<label><input type="checkbox" name="rtp_settings[enable_form_tracking]" value="1" <?php checked( $settings['enable_form_tracking'], 1 ); ?> /> <?php esc_html_e( 'Enable form submission tracking', 'referral-tracker-pro' ); ?></label><br />
					<label><input type="checkbox" name="rtp_settings[enable_field_storage]" value="1" <?php checked( $settings['enable_field_storage'], 1 ); ?> /> <?php esc_html_e( 'Store submitted form field data (sensitive fields are always stripped)', 'referral-tracker-pro' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rtp-custom-selectors"><?php esc_html_e( 'Custom form buttons', 'referral-tracker-pro' ); ?></label></th>
				<td>
					<input type="text" id="rtp-custom-selectors" name="rtp_settings[custom_form_selectors]" value="<?php echo esc_attr( $settings['custom_form_selectors'] ); ?>" class="large-text" />
					<p class="description">
						<?php esc_html_e( 'For JavaScript-driven forms that do not submit normally (e.g. the price calculator). Comma-separated CSS selectors of the submit buttons. A click on any of these is recorded as a form submission. Example: #submit-btn, .calc-submit', 'referral-tracker-pro' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Privacy', 'referral-tracker-pro' ); ?></th>
				<td>
					<label><input type="checkbox" name="rtp_settings[exclude_logged_in]" value="1" <?php checked( $settings['exclude_logged_in'], 1 ); ?> /> <?php esc_html_e( 'Do not track logged-in users (recommended)', 'referral-tracker-pro' ); ?></label><br />
					<label><input type="checkbox" name="rtp_settings[store_ip]" value="1" <?php checked( $settings['store_ip'], 1 ); ?> /> <?php esc_html_e( 'Store raw IP address (off = store a privacy-safe hash instead)', 'referral-tracker-pro' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstall', 'referral-tracker-pro' ); ?></th>
				<td>
					<label><input type="checkbox" name="rtp_settings[delete_on_uninstall]" value="1" <?php checked( $settings['delete_on_uninstall'], 1 ); ?> /> <?php esc_html_e( 'Delete all plugin data when the plugin is uninstalled', 'referral-tracker-pro' ); ?></label>
				</td>
			</tr>
		</table>

		<h2 style="margin-top:32px;"><?php esc_html_e( 'CallRail integration', 'referral-tracker-pro' ); ?></h2>
		<p class="description" style="max-width:780px;">
			<?php esc_html_e( 'Capture the actual phone number of every visitor who calls the website. CallRail provides each referred visitor with a unique tracking number (Dynamic Number Insertion); when the visitor calls it, CallRail forwards the call to your business number and tells us the caller’s real number. Those calls are then attributed to the original referral and shown on the Calls page.', 'referral-tracker-pro' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable CallRail', 'referral-tracker-pro' ); ?></th>
				<td>
					<label><input type="checkbox" name="rtp_settings[callrail_enabled]" value="1" <?php checked( $settings['callrail_enabled'], 1 ); ?> /> <?php esc_html_e( 'Pull calls from CallRail and link them to referrals', 'referral-tracker-pro' ); ?></label>
					<p class="description"><?php esc_html_e( 'When enabled, the webhook endpoint becomes active and the API is polled every 5 minutes as a fallback.', 'referral-tracker-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rtp-cr-api-key"><?php esc_html_e( 'API V3 key', 'referral-tracker-pro' ); ?></label></th>
				<td>
					<input type="text" id="rtp-cr-api-key" name="rtp_settings[callrail_api_key]" value="<?php echo esc_attr( $settings['callrail_api_key'] ); ?>" class="regular-text" autocomplete="off" spellcheck="false" />
					<p class="description"><?php esc_html_e( 'CallRail → Integrations → API keys → Create. Starts with "ctrk_…". Read-only key is enough.', 'referral-tracker-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rtp-cr-account"><?php esc_html_e( 'Account ID', 'referral-tracker-pro' ); ?></label></th>
				<td>
					<input type="text" id="rtp-cr-account" name="rtp_settings[callrail_account_id]" value="<?php echo esc_attr( $settings['callrail_account_id'] ); ?>" class="regular-text" autocomplete="off" spellcheck="false" />
					<p class="description"><?php esc_html_e( 'The numeric id that appears after "/a/" in any CallRail URL — e.g. https://app.callrail.com/settings/a/537407812/', 'referral-tracker-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rtp-cr-company"><?php esc_html_e( 'Company ID (optional)', 'referral-tracker-pro' ); ?></label></th>
				<td>
					<input type="text" id="rtp-cr-company" name="rtp_settings[callrail_company_id]" value="<?php echo esc_attr( $settings['callrail_company_id'] ); ?>" class="regular-text" autocomplete="off" spellcheck="false" />
					<p class="description"><?php esc_html_e( 'Limits polling to one company. Visible as ?company_id=... in CallRail URLs. Leave blank to pull every company on the account.', 'referral-tracker-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rtp-cr-secret"><?php esc_html_e( 'Webhook secret', 'referral-tracker-pro' ); ?></label></th>
				<td>
					<input type="text" id="rtp-cr-secret" name="rtp_settings[callrail_webhook_secret]" value="<?php echo esc_attr( $settings['callrail_webhook_secret'] ); ?>" class="regular-text" autocomplete="off" spellcheck="false" />
					<p class="description"><?php esc_html_e( 'A random string you choose. It is appended to the webhook URL below so the endpoint rejects requests that do not know the secret. Leave blank during testing.', 'referral-tracker-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rtp-cr-tnum"><?php esc_html_e( 'Tracking number filter (optional)', 'referral-tracker-pro' ); ?></label></th>
				<td>
					<input type="text" id="rtp-cr-tnum" name="rtp_settings[callrail_tracking_number]" value="<?php echo esc_attr( $settings['callrail_tracking_number'] ); ?>" class="regular-text" placeholder="+442070461987" autocomplete="off" spellcheck="false" />
					<p class="description"><?php esc_html_e( 'When set, the manual sync button and cron only pull calls placed on this exact tracking number. Leave blank to pull all numbers in the account. Format: E.164 (+44…)', 'referral-tracker-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Webhook URL', 'referral-tracker-pro' ); ?></th>
				<td>
					<?php
					$rtp_webhook_url = class_exists( 'RTP_CallRail' ) ? RTP_CallRail::get_webhook_url() : rest_url( 'rtp/v1/callrail' );
					?>
					<code style="display:inline-block; padding:6px 10px; background:#f6f7f7; border:1px solid #ccd0d4; word-break:break-all;">
						<?php echo esc_html( $rtp_webhook_url ); ?>
					</code>
					<p class="description">
						<?php esc_html_e( 'Paste this into CallRail → Integrations → Webhooks → Post-Call (and click Save). It auto-updates if you change the secret above.', 'referral-tracker-pro' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'referral-tracker-pro' ); ?></button></p>
	</form>
</div>
