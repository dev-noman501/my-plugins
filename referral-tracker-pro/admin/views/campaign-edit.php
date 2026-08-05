<?php
/**
 * Add / edit referral form.
 *
 * @package ReferralTrackerPro
 *
 * @var object|null $campaign
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rtp_is_edit = ( $campaign && isset( $campaign->id ) );
$rtp_id      = $rtp_is_edit ? (int) $campaign->id : 0;
$rtp_name    = $rtp_is_edit ? $campaign->name : '';
$rtp_code    = $rtp_is_edit ? $campaign->code : '';
$rtp_type    = $rtp_is_edit ? $campaign->type : 'general';
$rtp_target  = $rtp_is_edit ? $campaign->target_url : '';
$rtp_status  = $rtp_is_edit ? $campaign->status : 'active';
$rtp_notes   = $rtp_is_edit ? $campaign->notes : '';

$rtp_back = add_query_arg( 'page', 'rtp-campaigns', admin_url( 'admin.php' ) );
?>
<div class="wrap rtp-wrap">
	<h1><?php echo esc_html( $rtp_is_edit ? __( 'Edit Referral', 'referral-tracker-pro' ) : __( 'Add Referral', 'referral-tracker-pro' ) ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rtp-form">
		<input type="hidden" name="action" value="rtp_save_campaign" />
		<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $rtp_id ); ?>" />
		<?php wp_nonce_field( 'rtp_save_campaign' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="rtp-name"><?php esc_html_e( 'Referral name', 'referral-tracker-pro' ); ?></label></th>
				<td>
					<input name="name" id="rtp-name" type="text" class="regular-text" required value="<?php echo esc_attr( $rtp_name ); ?>" />
					<p class="description"><?php esc_html_e( 'A label you recognise, e.g. the customer’s name.', 'referral-tracker-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rtp-code"><?php esc_html_e( 'Referral code', 'referral-tracker-pro' ); ?></label></th>
				<td>
					<input name="code" id="rtp-code" type="text" class="regular-text" required value="<?php echo esc_attr( $rtp_code ); ?>" pattern="[A-Za-z0-9_\-]+" />
					<button type="button" class="button" id="rtp-gen-code"><?php esc_html_e( 'Generate', 'referral-tracker-pro' ); ?></button>
					<p class="description"><?php esc_html_e( 'Letters, numbers, dash, underscore only. Must be unique.', 'referral-tracker-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Referral type', 'referral-tracker-pro' ); ?></th>
				<td>
					<label><input type="radio" name="type" value="general" <?php checked( $rtp_type, 'general' ); ?> /> <?php esc_html_e( 'General website referral', 'referral-tracker-pro' ); ?></label><br />
					<label><input type="radio" name="type" value="page" <?php checked( $rtp_type, 'page' ); ?> /> <?php esc_html_e( 'Specific page referral', 'referral-tracker-pro' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rtp-target"><?php esc_html_e( 'Target page (optional)', 'referral-tracker-pro' ); ?></label></th>
				<td>
					<input name="target_url" id="rtp-target" type="url" class="regular-text" value="<?php echo esc_attr( $rtp_target ); ?>" placeholder="<?php echo esc_attr( home_url( '/services/' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'For a specific-page referral, the full URL the link should point to.', 'referral-tracker-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'referral-tracker-pro' ); ?></th>
				<td>
					<label><input type="radio" name="status" value="active" <?php checked( $rtp_status, 'active' ); ?> /> <?php esc_html_e( 'Active', 'referral-tracker-pro' ); ?></label>
					&nbsp;
					<label><input type="radio" name="status" value="inactive" <?php checked( $rtp_status, 'inactive' ); ?> /> <?php esc_html_e( 'Inactive', 'referral-tracker-pro' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="rtp-notes"><?php esc_html_e( 'Notes', 'referral-tracker-pro' ); ?></label></th>
				<td><textarea name="notes" id="rtp-notes" class="large-text" rows="4"><?php echo esc_textarea( $rtp_notes ); ?></textarea></td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Referral', 'referral-tracker-pro' ); ?></button>
			<a href="<?php echo esc_url( $rtp_back ); ?>" class="button"><?php esc_html_e( 'Cancel', 'referral-tracker-pro' ); ?></a>
		</p>
	</form>
</div>
