<?php
/**
 * Detailed events table view.
 *
 * @package ReferralTrackerPro
 *
 * @var array $filters
 * @var string $type
 * @var int $paged
 * @var array $result
 * @var array $campaigns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rtp_rows  = $result['rows'];
$rtp_total = (int) $result['total'];
$rtp_pages = (int) ceil( $rtp_total / 25 );
$rtp_paged = max( 1, (int) $paged );

$rtp_export_url = wp_nonce_url(
	add_query_arg(
		array(
			'action'      => 'rtp_export_csv',
			'from'        => $filters['from'],
			'to'          => $filters['to'],
			'campaign_id' => $filters['campaign_id'],
		),
		admin_url( 'admin-post.php' )
	),
	'rtp_export_csv'
);

/**
 * Builds a paging URL preserving filters.
 *
 * @param int $p Page number.
 * @return string
 */
$rtp_page_url = function ( $p ) use ( $filters, $type ) {
	return add_query_arg(
		array(
			'page'        => 'rtp-events',
			'from'        => $filters['from'],
			'to'          => $filters['to'],
			'campaign_id' => $filters['campaign_id'],
			'etype'       => $type,
			'paged'       => $p,
		),
		admin_url( 'admin.php' )
	);
};
?>
<div class="wrap rtp-wrap">
	<h1><?php esc_html_e( 'Referral Events', 'referral-tracker-pro' ); ?></h1>
	<p class="rtp-sub"><?php esc_html_e( 'Every visit, call click and form submission that came from a referral link.', 'referral-tracker-pro' ); ?></p>

	<form method="get" class="rtp-filters">
		<input type="hidden" name="page" value="rtp-events" />
		<label><?php esc_html_e( 'From', 'referral-tracker-pro' ); ?>
			<input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" />
		</label>
		<label><?php esc_html_e( 'To', 'referral-tracker-pro' ); ?>
			<input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" />
		</label>
		<label><?php esc_html_e( 'Referral', 'referral-tracker-pro' ); ?>
			<select name="campaign_id">
				<option value="0"><?php esc_html_e( 'All referrals', 'referral-tracker-pro' ); ?></option>
				<?php foreach ( $campaigns as $c ) : ?>
					<option value="<?php echo esc_attr( $c['id'] ); ?>" <?php selected( (int) $filters['campaign_id'], (int) $c['id'] ); ?>>
						<?php echo esc_html( $c['name'] . ' (' . $c['code'] . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
		<label><?php esc_html_e( 'Type', 'referral-tracker-pro' ); ?>
			<select name="etype">
				<option value="" <?php selected( $type, '' ); ?>><?php esc_html_e( 'All', 'referral-tracker-pro' ); ?></option>
				<option value="visit" <?php selected( $type, 'visit' ); ?>><?php esc_html_e( 'Visit', 'referral-tracker-pro' ); ?></option>
				<option value="call" <?php selected( $type, 'call' ); ?>><?php esc_html_e( 'Call', 'referral-tracker-pro' ); ?></option>
				<option value="form" <?php selected( $type, 'form' ); ?>><?php esc_html_e( 'Form', 'referral-tracker-pro' ); ?></option>
			</select>
		</label>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'referral-tracker-pro' ); ?></button>
		<a class="button" href="<?php echo esc_url( $rtp_export_url ); ?>"><?php esc_html_e( 'Export CSV', 'referral-tracker-pro' ); ?></a>
	</form>

	<p class="rtp-count"><?php echo esc_html( sprintf( /* translators: %s: number of events */ __( '%s events found', 'referral-tracker-pro' ), number_format_i18n( $rtp_total ) ) ); ?></p>

	<table class="widefat striped rtp-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Referral', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Action', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Lead / Detail', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Action Page', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Device', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'referral-tracker-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $rtp_rows ) ) : ?>
			<tr><td colspan="7"><?php esc_html_e( 'No events match these filters.', 'referral-tracker-pro' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $rtp_rows as $r ) : ?>
				<tr>
					<td><?php echo esc_html( mysql2date( 'd M Y H:i', $r['created_at'] ) ); ?></td>
					<td>
						<?php echo esc_html( $r['campaign_name'] ); ?><br />
						<code><?php echo esc_html( $r['referral_code'] ); ?></code>
					</td>
					<td><span class="rtp-tag rtp-tag-<?php echo esc_attr( $r['event_type'] ); ?>"><?php echo esc_html( ucfirst( $r['event_type'] ) ); ?></span></td>
					<td>
						<?php
						if ( 'call' === $r['event_type'] ) {
							$num = $r['phone_number'];
							if ( $num ) {
								echo '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $num ) ) . '">' . esc_html( $num ) . '</a>';
							} else {
								echo '&mdash;';
							}
						} elseif ( 'form' === $r['event_type'] ) {
							if ( ! empty( $r['lead_name'] ) ) {
								echo '<strong>' . esc_html( $r['lead_name'] ) . '</strong><br />';
							}
							if ( ! empty( $r['lead_email'] ) ) {
								echo '<a href="mailto:' . esc_attr( $r['lead_email'] ) . '">' . esc_html( $r['lead_email'] ) . '</a><br />';
							}
							if ( ! empty( $r['lead_phone'] ) ) {
								echo '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $r['lead_phone'] ) ) . '">' . esc_html( $r['lead_phone'] ) . '</a>';
							}
							if ( null !== $r['lead_amount'] && '' !== $r['lead_amount'] ) {
								echo '<br /><small>£' . esc_html( number_format_i18n( (float) $r['lead_amount'], 2 ) ) . '</small>';
							}
							if ( empty( $r['lead_name'] ) && empty( $r['lead_email'] ) && empty( $r['lead_phone'] ) ) {
								echo esc_html( $r['form_name'] ? $r['form_name'] : $r['form_id'] );
							}
						} else {
							echo '&mdash;';
						}
						?>
					</td>
					<td class="rtp-url" title="<?php echo esc_attr( $r['event_page'] ); ?>">
						<?php if ( ! empty( $r['event_page'] ) ) : ?>
							<a href="<?php echo esc_url( $r['event_page'] ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( wp_trim_words( wp_parse_url( $r['event_page'], PHP_URL_PATH ), 8, '…' ) ); ?>
							</a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( trim( $r['device'] . ' / ' . $r['browser'], ' /' ) ); ?></td>
					<td>
						<?php if ( 'form' === $r['event_type'] ) : ?>
							<a class="button button-small rtp-view-lead"
								data-id="<?php echo (int) $r['id']; ?>"
								href="<?php echo esc_url( add_query_arg( array( 'page' => 'rtp-lead-detail', 'id' => (int) $r['id'] ), admin_url( 'admin.php' ) ) ); ?>">
								<?php esc_html_e( 'View', 'referral-tracker-pro' ); ?>
							</a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $rtp_pages > 1 ) : ?>
		<div class="rtp-pagination">
			<?php if ( $rtp_paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( $rtp_page_url( $rtp_paged - 1 ) ); ?>">&laquo; <?php esc_html_e( 'Previous', 'referral-tracker-pro' ); ?></a>
			<?php endif; ?>
			<span class="rtp-page-info"><?php echo esc_html( sprintf( /* translators: 1: current page 2: total pages */ __( 'Page %1$d of %2$d', 'referral-tracker-pro' ), $rtp_paged, $rtp_pages ) ); ?></span>
			<?php if ( $rtp_paged < $rtp_pages ) : ?>
				<a class="button" href="<?php echo esc_url( $rtp_page_url( $rtp_paged + 1 ) ); ?>"><?php esc_html_e( 'Next', 'referral-tracker-pro' ); ?> &raquo;</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
