<?php
/**
 * Calls list view — referred visitors who phoned in, with their actual
 * caller-id captured by CallRail.
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

$rtp_export_url = wp_nonce_url(
	add_query_arg(
		array(
			'action'      => 'rtp_export_calls',
			'from'        => $filters['from'],
			'to'          => $filters['to'],
			'campaign_id' => $filters['campaign_id'],
			's'           => $search,
		),
		admin_url( 'admin-post.php' )
	),
	'rtp_export_calls'
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
			'page'        => 'rtp-calls',
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
	<h1><?php esc_html_e( 'Calls', 'referral-tracker-pro' ); ?></h1>
	<p class="rtp-sub">
		<?php esc_html_e( 'Phone calls placed by referred visitors. The "Caller" column shows the visitor’s actual number (captured via CallRail). The "Dialed" column shows the rotating tracking number CallRail displayed to that visitor.', 'referral-tracker-pro' ); ?>
	</p>

	<form method="get" class="rtp-filters">
		<input type="hidden" name="page" value="rtp-calls" />
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
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Caller name or phone', 'referral-tracker-pro' ); ?>" />
		</label>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'referral-tracker-pro' ); ?></button>
		<a class="button" href="<?php echo esc_url( $rtp_export_url ); ?>"><?php esc_html_e( 'Export CSV', 'referral-tracker-pro' ); ?></a>
	</form>

	<p class="rtp-count">
		<?php echo esc_html( sprintf( /* translators: %s: number of calls */ __( '%s calls found', 'referral-tracker-pro' ), number_format_i18n( $rtp_total ) ) ); ?>
	</p>

	<table class="widefat striped rtp-table rtp-calls-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Referrer', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Caller name', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Caller phone', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Dialed (tracking #)', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Duration', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Source', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Landing page', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Recording', 'referral-tracker-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $rtp_rows ) ) : ?>
			<tr><td colspan="9"><?php esc_html_e( 'No calls match these filters yet. If you just connected CallRail, allow a few minutes for the next polling tick (or make a test call).', 'referral-tracker-pro' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $rtp_rows as $r ) :
				$cr           = isset( $r['callrail'] ) && is_array( $r['callrail'] ) ? $r['callrail'] : array();
				$duration     = isset( $cr['duration'] ) ? (int) $cr['duration'] : 0;
				$recording    = isset( $cr['recording_url'] ) ? (string) $cr['recording_url'] : '';
				$source_name  = isset( $cr['source'] ) ? (string) $cr['source'] : '';
				$caller_loc   = trim( implode( ', ', array_filter( array(
					isset( $cr['caller_city'] ) ? $cr['caller_city'] : '',
					isset( $cr['caller_state'] ) ? $cr['caller_state'] : '',
				) ) ) );
				?>
				<tr>
					<td><?php echo esc_html( mysql2date( 'd M Y H:i', $r['created_at'] ) ); ?></td>
					<td>
						<strong><?php echo esc_html( $r['campaign_name'] ); ?></strong><br />
						<code><?php echo esc_html( $r['referral_code'] ); ?></code>
					</td>
					<td>
						<strong><?php echo esc_html( $r['lead_name'] ? $r['lead_name'] : '—' ); ?></strong>
						<?php if ( '' !== $caller_loc ) : ?>
							<br /><small><?php echo esc_html( $caller_loc ); ?></small>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! empty( $r['lead_phone'] ) ) : ?>
							<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $r['lead_phone'] ) ); ?>">
								<?php echo esc_html( $r['lead_phone'] ); ?>
							</a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td>
						<?php echo $r['phone_number'] ? esc_html( $r['phone_number'] ) : '&mdash;'; ?>
					</td>
					<td><?php echo esc_html( class_exists( 'RTP_CallRail' ) ? RTP_CallRail::format_duration( $duration ) : ( $duration . 's' ) ); ?></td>
					<td><?php echo $source_name ? esc_html( $source_name ) : '&mdash;'; ?></td>
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
						<?php if ( '' !== $recording ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $recording ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Listen', 'referral-tracker-pro' ); ?>
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
