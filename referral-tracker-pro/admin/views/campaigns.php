<?php
/**
 * Referral links list view.
 *
 * @package ReferralTrackerPro
 *
 * @var array $campaigns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rtp_msg = isset( $_GET['rtp_msg'] ) ? sanitize_key( wp_unslash( $_GET['rtp_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$rtp_notices = array(
	'saved'   => array( 'updated', __( 'Referral saved.', 'referral-tracker-pro' ) ),
	'deleted' => array( 'updated', __( 'Referral deleted.', 'referral-tracker-pro' ) ),
	'dupe'    => array( 'error', __( 'That referral code is already in use. Please choose another.', 'referral-tracker-pro' ) ),
	'missing' => array( 'error', __( 'Name and code are required.', 'referral-tracker-pro' ) ),
	'error'   => array( 'error', __( 'Could not save the referral. Please try again.', 'referral-tracker-pro' ) ),
);

$rtp_new_url = add_query_arg(
	array(
		'page'   => 'rtp-campaigns',
		'action' => 'new',
	),
	admin_url( 'admin.php' )
);
?>
<div class="wrap rtp-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Referral Links', 'referral-tracker-pro' ); ?></h1>
	<a href="<?php echo esc_url( $rtp_new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'referral-tracker-pro' ); ?></a>
	<hr class="wp-header-end" />

	<?php if ( isset( $rtp_notices[ $rtp_msg ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $rtp_notices[ $rtp_msg ][0] ); ?> is-dismissible">
			<p><?php echo esc_html( $rtp_notices[ $rtp_msg ][1] ); ?></p>
		</div>
	<?php endif; ?>

	<table class="widefat striped rtp-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Code', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Type', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Referral Link', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Status', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Created', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'referral-tracker-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $campaigns ) ) : ?>
			<tr><td colspan="7"><?php esc_html_e( 'No referrals yet. Click “Add New” to create your first referral link.', 'referral-tracker-pro' ); ?></td></tr>
		<?php else : ?>
			<?php
			foreach ( $campaigns as $c ) :
				$base = ( 'page' === $c['type'] && ! empty( $c['target_url'] ) ) ? $c['target_url'] : home_url( '/' );
				$link = add_query_arg( 'ref', rawurlencode( $c['code'] ), $base );

				$edit_url = add_query_arg(
					array(
						'page'   => 'rtp-campaigns',
						'action' => 'edit',
						'id'     => (int) $c['id'],
					),
					admin_url( 'admin.php' )
				);
				$detail_url = add_query_arg(
					array(
						'page' => 'rtp-referral-detail',
						'id'   => (int) $c['id'],
					),
					admin_url( 'admin.php' )
				);
				?>
				<tr>
					<td><strong><?php echo esc_html( $c['name'] ); ?></strong></td>
					<td><code><?php echo esc_html( $c['code'] ); ?></code></td>
					<td><?php echo esc_html( 'page' === $c['type'] ? __( 'Specific page', 'referral-tracker-pro' ) : __( 'General', 'referral-tracker-pro' ) ); ?></td>
					<td>
						<input type="text" class="rtp-link-input" readonly value="<?php echo esc_attr( $link ); ?>" />
						<button type="button" class="button button-small rtp-copy" data-clipboard="<?php echo esc_attr( $link ); ?>"><?php esc_html_e( 'Copy', 'referral-tracker-pro' ); ?></button>
					</td>
					<td>
						<span class="rtp-status rtp-status-<?php echo esc_attr( $c['status'] ); ?>">
							<?php echo esc_html( 'active' === $c['status'] ? __( 'Active', 'referral-tracker-pro' ) : __( 'Inactive', 'referral-tracker-pro' ) ); ?>
						</span>
					</td>
					<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $c['created_at'] ) ); ?></td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'Stats', 'referral-tracker-pro' ); ?></a>
						<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'referral-tracker-pro' ); ?></a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rtp-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this referral? Its history will be kept.', 'referral-tracker-pro' ) ); ?>');">
							<input type="hidden" name="action" value="rtp_delete_campaign" />
							<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $c['id'] ); ?>" />
							<?php wp_nonce_field( 'rtp_delete_campaign' ); ?>
							<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'referral-tracker-pro' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
</div>
