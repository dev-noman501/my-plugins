<?php
/**
 * Analytics dashboard view.
 *
 * @package ReferralTrackerPro
 *
 * @var array $filters
 * @var array $summary
 * @var array $top
 * @var array $visit_pgs
 * @var array $call_pgs
 * @var array $form_pgs
 * @var array $campaigns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a horizontal bar list block.
 *
 * @param string $title Section title.
 * @param array  $rows  Rows with 'page'/'name' + 'total'/'visits'.
 * @param string $label Value key.
 * @return void
 */
$rtp_bar_block = function ( $title, $rows, $label ) {
	$max = 0;
	foreach ( $rows as $r ) {
		$max = max( $max, (int) $r[ $label ] );
	}
	echo '<div class="rtp-card rtp-bars"><h3>' . esc_html( $title ) . '</h3>';
	if ( empty( $rows ) ) {
		echo '<p class="rtp-empty">' . esc_html__( 'No data for this period.', 'referral-tracker-pro' ) . '</p>';
	} else {
		echo '<ul>';
		foreach ( $rows as $r ) {
			$val = (int) $r[ $label ];
			$pct = $max > 0 ? round( ( $val / $max ) * 100 ) : 0;
			$key = isset( $r['page'] ) ? $r['page'] : $r['name'];
			echo '<li><span class="rtp-bar-label" title="' . esc_attr( $key ) . '">' . esc_html( wp_trim_words( $key, 12, '…' ) ) . '</span>';
			echo '<span class="rtp-bar-track"><span class="rtp-bar-fill" style="width:' . esc_attr( $pct ) . '%"></span></span>';
			echo '<span class="rtp-bar-val">' . esc_html( number_format_i18n( $val ) ) . '</span></li>';
		}
		echo '</ul>';
	}
	echo '</div>';
};

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
?>
<div class="wrap rtp-wrap">
	<h1><?php esc_html_e( 'Referral Analytics', 'referral-tracker-pro' ); ?></h1>
	<p class="rtp-sub"><?php esc_html_e( 'See exactly which calls and form enquiries came from your referral links.', 'referral-tracker-pro' ); ?></p>

	<form method="get" class="rtp-filters">
		<input type="hidden" name="page" value="rtp-analytics" />
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
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'referral-tracker-pro' ); ?></button>
		<a class="button" href="<?php echo esc_url( $rtp_export_url ); ?>"><?php esc_html_e( 'Export CSV', 'referral-tracker-pro' ); ?></a>
	</form>

	<div class="rtp-stats">
		<div class="rtp-stat"><span class="rtp-stat-num"><?php echo esc_html( number_format_i18n( $summary['visits'] ) ); ?></span><span class="rtp-stat-lbl"><?php esc_html_e( 'Referral Visits', 'referral-tracker-pro' ); ?></span></div>
		<div class="rtp-stat"><span class="rtp-stat-num"><?php echo esc_html( number_format_i18n( $summary['calls'] ) ); ?></span><span class="rtp-stat-lbl"><?php esc_html_e( 'Call Clicks', 'referral-tracker-pro' ); ?></span></div>
		<div class="rtp-stat"><span class="rtp-stat-num"><?php echo esc_html( number_format_i18n( $summary['forms'] ) ); ?></span><span class="rtp-stat-lbl"><?php esc_html_e( 'Form Submissions', 'referral-tracker-pro' ); ?></span></div>
		<div class="rtp-stat rtp-stat-accent"><span class="rtp-stat-num"><?php echo esc_html( $summary['rate'] ); ?>%</span><span class="rtp-stat-lbl"><?php esc_html_e( 'Conversion Rate', 'referral-tracker-pro' ); ?></span></div>
	</div>

	<div class="rtp-grid">
		<?php
		$rtp_bar_block( __( 'Top Referrals (by visits)', 'referral-tracker-pro' ), $top, 'visits' );
		$rtp_bar_block( __( 'Top Landing Pages', 'referral-tracker-pro' ), $visit_pgs, 'total' );
		$rtp_bar_block( __( 'Top Call Pages', 'referral-tracker-pro' ), $call_pgs, 'total' );
		$rtp_bar_block( __( 'Top Submission Pages', 'referral-tracker-pro' ), $form_pgs, 'total' );
		?>
	</div>

	<div class="rtp-card">
		<h3><?php esc_html_e( 'Referral Performance', 'referral-tracker-pro' ); ?></h3>
		<table class="widefat striped rtp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Referral', 'referral-tracker-pro' ); ?></th>
					<th><?php esc_html_e( 'Code', 'referral-tracker-pro' ); ?></th>
					<th><?php esc_html_e( 'Visits', 'referral-tracker-pro' ); ?></th>
					<th><?php esc_html_e( 'Calls', 'referral-tracker-pro' ); ?></th>
					<th><?php esc_html_e( 'Forms', 'referral-tracker-pro' ); ?></th>
					<th><?php esc_html_e( 'Conv. %', 'referral-tracker-pro' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $top ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No referral activity in this period.', 'referral-tracker-pro' ); ?></td></tr>
			<?php else : ?>
				<?php
				foreach ( $top as $row ) :
					$v    = (int) $row['visits'];
					$conv = (int) $row['calls'] + (int) $row['forms'];
					$rate = $v > 0 ? round( ( $conv / $v ) * 100, 1 ) : 0;
					$detail_url = add_query_arg(
						array(
							'page' => 'rtp-referral-detail',
							'id'   => (int) $row['campaign_id'],
						),
						admin_url( 'admin.php' )
					);
					?>
					<tr>
						<td><?php echo esc_html( $row['name'] ); ?></td>
						<td><code><?php echo esc_html( $row['referral_code'] ); ?></code></td>
						<td><?php echo esc_html( number_format_i18n( $v ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['calls'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['forms'] ) ); ?></td>
						<td><strong><?php echo esc_html( $rate ); ?>%</strong></td>
						<td>
							<?php if ( (int) $row['campaign_id'] > 0 ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'Details', 'referral-tracker-pro' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
