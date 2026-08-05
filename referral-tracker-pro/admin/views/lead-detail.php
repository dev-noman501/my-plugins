<?php
/**
 * Single-lead detail — full page wrapper (used for direct URL access and as
 * a fallback when JS is disabled; the in-app View buttons now open a modal
 * with the same content for a smoother experience).
 *
 * @package ReferralTrackerPro
 *
 * @var array $event   Full event row joined with campaign name.
 * @var array $fields  Decoded `extra.fields` array (may be empty).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rtp_back = add_query_arg( 'page', 'rtp-leads', admin_url( 'admin.php' ) );
?>
<div class="wrap rtp-wrap rtp-lead-detail-wrap">

	<div class="rtp-print-bar">
		<a href="<?php echo esc_url( $rtp_back ); ?>" class="button">&laquo; <?php esc_html_e( 'Back to Leads', 'referral-tracker-pro' ); ?></a>
		<button type="button" class="button button-primary" id="rtp-print-lead">
			<span class="dashicons dashicons-download" style="vertical-align:middle;margin-right:4px;"></span>
			<?php esc_html_e( 'Download / Print PDF', 'referral-tracker-pro' ); ?>
		</button>
	</div>

	<div class="rtp-printable" id="rtp-printable">
		<?php require RTP_PLUGIN_DIR . 'admin/views/lead-detail-content.php'; ?>
	</div>
</div>

<script>
( function () {
	var btn = document.getElementById( 'rtp-print-lead' );
	if ( btn ) {
		btn.addEventListener( 'click', function () {
			window.print();
		} );
	}
} )();
</script>
