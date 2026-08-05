<?php
/**
 * CallRail admin page — verified call records pulled from the CallRail API.
 *
 * @package ReferralTrackerPro
 *
 * @var array  $filters
 * @var int    $paged
 * @var array  $result
 * @var array  $tracking_numbers
 * @var string $last_sync_at
 * @var array  $last_sync_result
 * @var bool   $configured
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rtp_rows  = $result['rows'];
$rtp_total = (int) $result['total'];
$rtp_pages = (int) ceil( $rtp_total / 25 );
$rtp_paged = max( 1, (int) $paged );

$rtp_sync_url = wp_nonce_url(
	add_query_arg(
		array(
			'action'          => 'rtp_callrail_sync',
			'from'            => $filters['from_date'],
			'to'              => $filters['to_date'],
			'tracking_number' => $filters['tracking_number'],
			's'               => $filters['search'],
		),
		admin_url( 'admin-post.php' )
	),
	'rtp_callrail_sync'
);

$rtp_page_url = function ( $p ) use ( $filters ) {
	return add_query_arg(
		array(
			'page'            => 'rtp-callrail',
			'from'            => $filters['from_date'],
			'to'              => $filters['to_date'],
			'tracking_number' => $filters['tracking_number'],
			's'               => $filters['search'],
			'paged'           => $p,
		),
		admin_url( 'admin.php' )
	);
};
?>
<div class="wrap rtp-wrap">
	<h1>
		<?php esc_html_e( 'CallRail Calls', 'referral-tracker-pro' ); ?>
		<a href="<?php echo esc_url( $rtp_sync_url ); ?>" class="page-title-action">
			<?php esc_html_e( 'Sync CallRail Calls', 'referral-tracker-pro' ); ?>
		</a>
	</h1>
	<p class="rtp-sub">
		<?php esc_html_e( 'Verified call records pulled directly from the CallRail API. Local phone-click attempts are not shown here.', 'referral-tracker-pro' ); ?>
		<br />
		<small style="color:#666;">
			<?php
			$rtp_sync_file = RTP_PLUGIN_DIR . 'includes/class-rtp-callrail-sync.php';
			$rtp_mtime     = file_exists( $rtp_sync_file ) ? gmdate( 'Y-m-d H:i:s', filemtime( $rtp_sync_file ) ) : '—';
			echo esc_html(
				sprintf(
					/* translators: 1: plugin version 2: sync file last-modified */
					__( 'Plugin v%1$s · Sync code last modified: %2$s UTC', 'referral-tracker-pro' ),
					defined( 'RTP_VERSION' ) ? RTP_VERSION : '?',
					$rtp_mtime
				)
			);
			?>
		</small>
	</p>

	<?php if ( ! $configured ) : ?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'CallRail is not configured.', 'referral-tracker-pro' ); ?></strong>
				<?php esc_html_e( 'Add your API key and Account ID in Referrals → Settings (or define RTP_CALLRAIL_API_KEY and RTP_CALLRAIL_ACCOUNT_ID in wp-config.php).', 'referral-tracker-pro' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $last_sync_result ) ) :
		$is_ok    = ! empty( $last_sync_result['ok'] );
		$css      = $is_ok ? 'notice-success' : 'notice-error';
		$when     = ! empty( $last_sync_result['synced_at'] ) ? mysql2date( 'd M Y H:i', $last_sync_result['synced_at'] ) : '';
		?>
		<div class="notice <?php echo esc_attr( $css ); ?> is-dismissible">
			<?php if ( $is_ok ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: inserted, 2: updated, 3: processed, 4: when */
							__( 'Last sync at %4$s: %1$d new, %2$d updated, %3$d processed.', 'referral-tracker-pro' ),
							(int) ( $last_sync_result['inserted'] ?? 0 ),
							(int) ( $last_sync_result['updated'] ?? 0 ),
							(int) ( $last_sync_result['processed'] ?? 0 ),
							$when
						)
					);
					if ( isset( $last_sync_result['api_total'] ) ) {
						echo ' ';
						echo esc_html(
							sprintf(
								/* translators: %d: total available on the CallRail side */
								__( 'CallRail reports %d total calls in this window.', 'referral-tracker-pro' ),
								(int) $last_sync_result['api_total']
							)
						);
					}
					?>
				</p>
			<?php else : ?>
				<p>
					<strong><?php esc_html_e( 'Last sync failed:', 'referral-tracker-pro' ); ?></strong>
					<?php echo esc_html( (string) ( $last_sync_result['error'] ?? __( 'Unknown error', 'referral-tracker-pro' ) ) ); ?>
					<?php if ( $when ) : ?>
						<em>(<?php echo esc_html( $when ); ?>)</em>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form method="get" class="rtp-filters">
		<input type="hidden" name="page" value="rtp-callrail" />
		<label><?php esc_html_e( 'From', 'referral-tracker-pro' ); ?>
			<input type="date" name="from" value="<?php echo esc_attr( $filters['from_date'] ); ?>" />
		</label>
		<label><?php esc_html_e( 'To', 'referral-tracker-pro' ); ?>
			<input type="date" name="to" value="<?php echo esc_attr( $filters['to_date'] ); ?>" />
		</label>
		<label><?php esc_html_e( 'Tracking number', 'referral-tracker-pro' ); ?>
			<select name="tracking_number">
				<option value=""><?php esc_html_e( 'All tracking numbers', 'referral-tracker-pro' ); ?></option>
				<?php foreach ( $tracking_numbers as $tn ) : ?>
					<option value="<?php echo esc_attr( $tn ); ?>" <?php selected( $filters['tracking_number'], $tn ); ?>>
						<?php echo esc_html( $tn ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
		<label><?php esc_html_e( 'Search', 'referral-tracker-pro' ); ?>
			<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Caller number / name / call id', 'referral-tracker-pro' ); ?>" />
		</label>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'referral-tracker-pro' ); ?></button>
	</form>

	<p class="rtp-count">
		<?php echo esc_html( sprintf( /* translators: %s: number of calls */ __( '%s verified calls found', 'referral-tracker-pro' ), number_format_i18n( $rtp_total ) ) ); ?>
		<?php if ( $last_sync_at ) : ?>
			&nbsp;&middot;&nbsp;
			<em><?php
			echo esc_html(
				sprintf(
					/* translators: %s: last sync time */
					__( 'Last sync: %s', 'referral-tracker-pro' ),
					mysql2date( 'd M Y H:i', $last_sync_at )
				)
			);
			?></em>
		<?php endif; ?>
	</p>

	<table class="widefat striped rtp-table rtp-callrail-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Call ID', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Caller Number', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Tracking Number', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Source / Referral', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Call Date &amp; Time', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Duration', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Recording', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Verified', 'referral-tracker-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $rtp_rows ) ) : ?>
			<tr>
				<td colspan="9">
					<?php esc_html_e( 'No verified CallRail calls in the local store yet. Click "Sync CallRail Calls" above to pull from the API.', 'referral-tracker-pro' ); ?>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $rtp_rows as $r ) :
				$status    = (string) $r['call_status'];
				$status_lc = strtolower( $status );
				$loc       = trim( implode( ', ', array_filter( array( $r['customer_city'], $r['customer_state'] ) ) ) );
				$src_bits  = array_filter( array( $r['source'], $r['referral'] ? '?ref=' . $r['referral'] : '' ) );
				?>
				<tr>
					<td><code><?php echo esc_html( $r['callrail_call_id'] ); ?></code></td>
					<td>
						<?php if ( ! empty( $r['caller_number'] ) ) : ?>
							<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $r['caller_number'] ) ); ?>">
								<strong><?php echo esc_html( $r['caller_number'] ); ?></strong>
							</a>
							<?php if ( ! empty( $r['caller_name'] ) ) : ?>
								<br /><small><?php echo esc_html( $r['caller_name'] ); ?></small>
							<?php endif; ?>
							<?php if ( '' !== $loc ) : ?>
								<br /><small><?php echo esc_html( $loc ); ?></small>
							<?php endif; ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td>
						<?php echo $r['tracking_number'] ? esc_html( $r['tracking_number'] ) : '&mdash;'; ?>
						<?php if ( ! empty( $r['tracking_name'] ) ) : ?>
							<br /><small><?php echo esc_html( $r['tracking_name'] ); ?></small>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! empty( $src_bits ) ) : ?>
							<?php echo esc_html( implode( ' · ', $src_bits ) ); ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( ! empty( $r['call_started_at'] ) ? mysql2date( 'd M Y H:i', $r['call_started_at'] ) : '—' ); ?></td>
					<td><?php echo esc_html( RTP_CallRail_Sync::format_duration( (int) $r['duration'] ) ); ?></td>
					<td>
						<?php if ( '' !== $status ) : ?>
							<span class="rtp-status rtp-status-<?php echo esc_attr( $status_lc ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! empty( $r['recording_url'] ) ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $r['recording_url'] ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Listen', 'referral-tracker-pro' ); ?>
							</a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td>
						<?php if ( (int) $r['verified_from_callrail'] === 1 ) : ?>
							<span class="rtp-verified" style="color:#2271b1;font-weight:600;">&#10004; <?php esc_html_e( 'Verified', 'referral-tracker-pro' ); ?></span>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php
	$rtp_debug = RTP_CallRail_Sync::get_last_debug();
	if ( $rtp_debug ) :
		$rtp_dbg_code = (int) ( $rtp_debug['http_code'] ?? 0 );
		$rtp_dbg_url  = (string) ( $rtp_debug['url'] ?? '' );
		// Mask the api key in the URL display if it ever appears (it shouldn't,
		// since the key only goes via the Authorization header).
		$rtp_dbg_url_masked = preg_replace( '/(ctrk_)[A-Za-z0-9]+/', '$1***', $rtp_dbg_url );
		?>
		<details style="margin-top:24px; padding:12px 16px; background:#f6f7f7; border:1px solid #ccd0d4; border-radius:4px;">
			<summary style="cursor:pointer; font-weight:600;">
				<?php esc_html_e( 'Debug: last CallRail API request', 'referral-tracker-pro' ); ?>
				<span style="font-weight:normal; color:#666;">
					(HTTP <?php echo esc_html( $rtp_dbg_code ); ?>
					<?php if ( ! empty( $rtp_debug['requested_at'] ) ) : ?>
						· <?php echo esc_html( mysql2date( 'd M H:i:s', $rtp_debug['requested_at'] ) ); ?>
					<?php endif; ?>)
				</span>
			</summary>
			<div style="margin-top:10px; font-family:Menlo,Consolas,monospace; font-size:12px;">
				<p><strong>Auth prefix:</strong> <code><?php echo esc_html( (string) ( $rtp_debug['auth_prefix'] ?? '' ) ); ?></code></p>
				<p><strong>URL:</strong><br /><code style="word-break:break-all;"><?php echo esc_html( $rtp_dbg_url_masked ); ?></code></p>
				<p><strong>HTTP code:</strong> <?php echo esc_html( $rtp_dbg_code ); ?></p>
				<p><strong>Response body (first 800 chars):</strong></p>
				<pre style="background:#fff; padding:8px; border:1px solid #ddd; white-space:pre-wrap; word-break:break-all; max-height:300px; overflow:auto;"><?php echo esc_html( (string) ( $rtp_debug['body_preview'] ?? '' ) ); ?></pre>
			</div>
		</details>
	<?php endif; ?>

	<?php if ( $rtp_pages > 1 ) : ?>
		<div class="rtp-pagination">
			<?php if ( $rtp_paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( $rtp_page_url( $rtp_paged - 1 ) ); ?>">&laquo; <?php esc_html_e( 'Previous', 'referral-tracker-pro' ); ?></a>
			<?php endif; ?>
			<span class="rtp-page-info">
				<?php echo esc_html( sprintf( /* translators: 1: current 2: total */ __( 'Page %1$d of %2$d', 'referral-tracker-pro' ), $rtp_paged, $rtp_pages ) ); ?>
			</span>
			<?php if ( $rtp_paged < $rtp_pages ) : ?>
				<a class="button" href="<?php echo esc_url( $rtp_page_url( $rtp_paged + 1 ) ); ?>"><?php esc_html_e( 'Next', 'referral-tracker-pro' ); ?> &raquo;</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
