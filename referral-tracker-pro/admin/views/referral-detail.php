<?php
/**
 * Per-referral detail view.
 *
 * @package ReferralTrackerPro
 *
 * @var object $campaign
 * @var array  $summary
 * @var array  $sessions
 * @var array  $timeline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rtp_base = ( 'page' === $campaign->type && ! empty( $campaign->target_url ) ) ? $campaign->target_url : home_url( '/' );
$rtp_link = add_query_arg( 'ref', rawurlencode( $campaign->code ), $rtp_base );
$rtp_back = add_query_arg( 'page', 'rtp-analytics', admin_url( 'admin.php' ) );
?>
<div class="wrap rtp-wrap">
	<h1>
		<?php echo esc_html( $campaign->name ); ?>
		<span class="rtp-status rtp-status-<?php echo esc_attr( $campaign->status ); ?>">
			<?php echo esc_html( 'active' === $campaign->status ? __( 'Active', 'referral-tracker-pro' ) : __( 'Inactive', 'referral-tracker-pro' ) ); ?>
		</span>
	</h1>
	<p class="rtp-sub">
		<?php esc_html_e( 'Code', 'referral-tracker-pro' ); ?>: <code><?php echo esc_html( $campaign->code ); ?></code>
		&nbsp;|&nbsp;
		<a href="<?php echo esc_url( $rtp_back ); ?>">&laquo; <?php esc_html_e( 'Back to analytics', 'referral-tracker-pro' ); ?></a>
	</p>

	<div class="rtp-card">
		<label class="rtp-link-row">
			<span><?php esc_html_e( 'Referral link', 'referral-tracker-pro' ); ?></span>
			<input type="text" class="rtp-link-input" readonly value="<?php echo esc_attr( $rtp_link ); ?>" />
			<button type="button" class="button rtp-copy" data-clipboard="<?php echo esc_attr( $rtp_link ); ?>"><?php esc_html_e( 'Copy', 'referral-tracker-pro' ); ?></button>
		</label>
	</div>

	<div class="rtp-stats">
		<div class="rtp-stat"><span class="rtp-stat-num"><?php echo esc_html( number_format_i18n( $summary['visits'] ) ); ?></span><span class="rtp-stat-lbl"><?php esc_html_e( 'Visits', 'referral-tracker-pro' ); ?></span></div>
		<div class="rtp-stat"><span class="rtp-stat-num"><?php echo esc_html( number_format_i18n( $summary['calls'] ) ); ?></span><span class="rtp-stat-lbl"><?php esc_html_e( 'Calls', 'referral-tracker-pro' ); ?></span></div>
		<div class="rtp-stat"><span class="rtp-stat-num"><?php echo esc_html( number_format_i18n( $summary['forms'] ) ); ?></span><span class="rtp-stat-lbl"><?php esc_html_e( 'Submissions', 'referral-tracker-pro' ); ?></span></div>
		<div class="rtp-stat rtp-stat-accent"><span class="rtp-stat-num"><?php echo esc_html( $summary['rate'] ); ?>%</span><span class="rtp-stat-lbl"><?php esc_html_e( 'Conversion', 'referral-tracker-pro' ); ?></span></div>
	</div>

	<div class="rtp-two-col">
		<div class="rtp-card">
			<h3><?php esc_html_e( 'Visitor Journeys', 'referral-tracker-pro' ); ?></h3>
			<table class="widefat striped rtp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'First seen', 'referral-tracker-pro' ); ?></th>
						<th><?php esc_html_e( 'Landing page', 'referral-tracker-pro' ); ?></th>
						<th><?php esc_html_e( 'Device', 'referral-tracker-pro' ); ?></th>
						<th><?php esc_html_e( 'Events', 'referral-tracker-pro' ); ?></th>
						<th><?php esc_html_e( 'Calls', 'referral-tracker-pro' ); ?></th>
						<th><?php esc_html_e( 'Forms', 'referral-tracker-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $sessions ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No visitors yet.', 'referral-tracker-pro' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $sessions as $s ) : ?>
						<tr>
							<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $s['created_at'] ) ); ?></td>
							<td class="rtp-url" title="<?php echo esc_attr( $s['landing_page'] ); ?>"><?php echo esc_html( wp_trim_words( $s['landing_page'], 8, '…' ) ); ?></td>
							<td><?php echo esc_html( trim( $s['device'] . ' / ' . $s['browser'], ' /' ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $s['event_count'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $s['calls'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $s['forms'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div class="rtp-card">
			<h3><?php esc_html_e( 'Event Timeline', 'referral-tracker-pro' ); ?></h3>
			<?php if ( empty( $timeline ) ) : ?>
				<p class="rtp-empty"><?php esc_html_e( 'No events recorded yet.', 'referral-tracker-pro' ); ?></p>
			<?php else : ?>
				<ul class="rtp-timeline">
					<?php foreach ( $timeline as $t ) : ?>
						<li class="rtp-tl-<?php echo esc_attr( $t['event_type'] ); ?>">
							<span class="rtp-tl-time"><?php echo esc_html( mysql2date( 'M j, H:i', $t['created_at'] ) ); ?></span>
							<span class="rtp-tag rtp-tag-<?php echo esc_attr( $t['event_type'] ); ?>"><?php echo esc_html( ucfirst( $t['event_type'] ) ); ?></span>
							<span class="rtp-tl-desc">
								<?php
								if ( 'call' === $t['event_type'] ) {
									echo esc_html( $t['phone_number'] );
								} elseif ( 'form' === $t['event_type'] ) {
									echo esc_html( $t['form_name'] );
								}
								echo ' &middot; ';
								echo esc_html( wp_trim_words( $t['event_page'], 8, '…' ) );
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</div>
