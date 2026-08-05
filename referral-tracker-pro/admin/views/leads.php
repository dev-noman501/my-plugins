<?php
/**
 * Leads list view — form submissions with full contact details, attributed
 * to a referral.
 *
 * @package ReferralTrackerPro
 *
 * @var array  $filters
 * @var int    $paged
 * @var string $search
 * @var array  $result
 * @var array  $campaigns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rtp_rows  = $result['rows'];
$rtp_total = (int) $result['total'];
$rtp_pages = (int) ceil( $rtp_total / 25 );
$rtp_paged = max( 1, (int) $paged );

$rtp_currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '£';

$rtp_export_url = wp_nonce_url(
	add_query_arg(
		array(
			'action'      => 'rtp_export_leads',
			'from'        => $filters['from'],
			'to'          => $filters['to'],
			'campaign_id' => $filters['campaign_id'],
			's'           => $search,
		),
		admin_url( 'admin-post.php' )
	),
	'rtp_export_leads'
);

/**
 * Builds a paging URL preserving filters.
 *
 * @param int $p Page number.
 * @return string
 */
$rtp_page_url = function ( $p ) use ( $filters, $search ) {
	return add_query_arg(
		array(
			'page'        => 'rtp-leads',
			'from'        => $filters['from'],
			'to'          => $filters['to'],
			'campaign_id' => $filters['campaign_id'],
			's'           => $search,
			'paged'       => $p,
		),
		admin_url( 'admin.php' )
	);
};
?>
<div class="wrap rtp-wrap">
	<h1><?php esc_html_e( 'Leads', 'referral-tracker-pro' ); ?></h1>
	<p class="rtp-sub"><?php esc_html_e( 'Every form submission (calculator and standard forms) that came from a referral link — with contact details, estimate and the page they submitted from.', 'referral-tracker-pro' ); ?></p>

	<form method="get" class="rtp-filters">
		<input type="hidden" name="page" value="rtp-leads" />
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
		<label><?php esc_html_e( 'Search', 'referral-tracker-pro' ); ?>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name / email / phone', 'referral-tracker-pro' ); ?>" />
		</label>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'referral-tracker-pro' ); ?></button>
		<a class="button" href="<?php echo esc_url( $rtp_export_url ); ?>"><?php esc_html_e( 'Export CSV', 'referral-tracker-pro' ); ?></a>
	</form>

	<p class="rtp-count">
		<?php echo esc_html( sprintf( /* translators: %s: number of leads */ __( '%s leads found', 'referral-tracker-pro' ), number_format_i18n( $rtp_total ) ) ); ?>
	</p>

	<table class="widefat striped rtp-table rtp-leads-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Referrer', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Name', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Email', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Phone', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Amount', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Page', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Form', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'referral-tracker-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $rtp_rows ) ) : ?>
			<tr><td colspan="9"><?php esc_html_e( 'No leads match these filters yet.', 'referral-tracker-pro' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $rtp_rows as $r ) : ?>
				<tr>
					<td><?php echo esc_html( mysql2date( 'd M Y H:i', $r['created_at'] ) ); ?></td>
					<td>
						<strong><?php echo esc_html( $r['campaign_name'] ); ?></strong><br />
						<code><?php echo esc_html( $r['referral_code'] ); ?></code>
					</td>
					<td><strong><?php echo esc_html( $r['lead_name'] ? $r['lead_name'] : '—' ); ?></strong></td>
					<td>
						<?php if ( ! empty( $r['lead_email'] ) ) : ?>
							<a href="mailto:<?php echo esc_attr( $r['lead_email'] ); ?>"><?php echo esc_html( $r['lead_email'] ); ?></a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! empty( $r['lead_phone'] ) ) : ?>
							<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $r['lead_phone'] ) ); ?>"><?php echo esc_html( $r['lead_phone'] ); ?></a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td>
						<?php
						if ( null === $r['lead_amount'] || '' === $r['lead_amount'] ) {
							echo '—';
						} else {
							echo esc_html( $rtp_currency . number_format_i18n( (float) $r['lead_amount'], 2 ) );
						}
						?>
					</td>
					<td class="rtp-url" title="<?php echo esc_attr( $r['event_page'] ); ?>">
						<?php if ( ! empty( $r['event_page'] ) ) : ?>
							<a href="<?php echo esc_url( $r['event_page'] ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( wp_trim_words( wp_parse_url( $r['event_page'], PHP_URL_PATH ), 6, '…' ) ); ?>
							</a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( $r['form_name'] ? $r['form_name'] : $r['form_id'] ); ?>
						<?php if ( ! empty( $r['form_type'] ) ) : ?>
							<small>(<?php echo esc_html( $r['form_type'] ); ?>)</small>
						<?php endif; ?>
					</td>
					<td>
						<a class="button button-small rtp-view-lead"
							data-id="<?php echo (int) $r['id']; ?>"
							href="<?php echo esc_url( add_query_arg( array( 'page' => 'rtp-lead-detail', 'id' => (int) $r['id'] ), admin_url( 'admin.php' ) ) ); ?>">
							<?php esc_html_e( 'View', 'referral-tracker-pro' ); ?>
						</a>
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
