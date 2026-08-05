<?php
/**
 * Lead detail — content partial (the printable card body).
 *
 * Included by both:
 *  - admin/views/lead-detail.php (full-page direct view)
 *  - AJAX response for the modal opened from the View button.
 *
 * @package ReferralTrackerPro
 *
 * @var array $event   Full event row joined with campaign name.
 * @var array $fields  Decoded `extra.fields` array (may be empty).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rtp_currency = '£';

/**
 * Cleans a raw form-field key into a friendly label.
 *  - "form-field-first_name"          → "First Name"
 *  - "form_fields[first_name]"        → "First Name"
 *  - "form_fieldsfirst_name"          → "First Name"  (sanitize_key flattens brackets)
 *  - "type_of_property_"              → "Type Of Property"
 */
$rtp_label = function ( $key ) {
	$key = strtolower( (string) $key );
	$key = preg_replace( '/^form-field-/', '', $key );
	$key = preg_replace( '/form_fields\[([^\]]+)\]/', '$1', $key );
	$key = preg_replace( '/^form_fields/', '', $key );
	$key = trim( $key, "_- \t" );
	$key = str_replace( array( '_', '-' ), ' ', $key );
	return ucwords( trim( $key ) );
};

/**
 * Fields we never show in the "All Submitted Fields" table:
 *  - duplicates of what the Contact / Submission sections already show
 *  - internal WP/Elementor/UTM metadata that's noise to a non-tech admin.
 */
$rtp_hidden_keys = array(
	// Already in Contact section.
	'first_name', 'firstname', 'last_name', 'lastname',
	'email', 'e_mail', 'email_address',
	'phone', 'mobile', 'tel', 'phone_number', 'mobile_number',
	'total_estimate', 'estimate', 'amount', 'price',
	// Internal / meta noise.
	'post_id', 'form_id', 'queried_id', 'referer_title', 'referrer_title',
	'utm_campaign', 'utm_source', 'utm_medium', 'utm_term', 'utm_content',
	'wp_contact',
);

/**
 * Decides whether a raw key should be rendered.
 *
 * @param string $raw_key Raw field key.
 * @return bool
 */
$rtp_should_show = function ( $raw_key ) use ( $rtp_hidden_keys, $rtp_label ) {
	$clean = strtolower( str_replace( ' ', '_', $rtp_label( $raw_key ) ) );
	if ( '' === $clean ) {
		return false;
	}
	if ( in_array( $clean, $rtp_hidden_keys, true ) ) {
		return false;
	}
	// Catch variants like "no_of_carpet_check" → only show actual selections, not checkbox flags.
	if ( preg_match( '/_check$/', $clean ) || preg_match( '/_check_\d+$/', $clean ) ) {
		return false;
	}
	return true;
};
?>
<div class="rtp-pd-header">
	<div>
		<h1><?php esc_html_e( 'Lead Submission', 'referral-tracker-pro' ); ?></h1>
		<p class="rtp-pd-sub">
			<?php echo esc_html( get_bloginfo( 'name' ) ); ?> &middot;
			<?php echo esc_html( mysql2date( 'd F Y, H:i', $event['created_at'] ) ); ?>
			<?php
			$tz = wp_timezone_string();
			if ( $tz ) {
				echo ' &middot; ' . esc_html( $tz );
			}
			?>
		</p>
	</div>
	<div class="rtp-pd-ref">
		<span class="rtp-pd-label"><?php esc_html_e( 'Referral', 'referral-tracker-pro' ); ?></span>
		<strong><?php echo esc_html( $event['campaign_name'] ); ?></strong>
		<code><?php echo esc_html( $event['referral_code'] ); ?></code>
	</div>
</div>

<h2><?php esc_html_e( 'Contact', 'referral-tracker-pro' ); ?></h2>
<table class="rtp-pd-table">
	<tbody>
		<tr>
			<th><?php esc_html_e( 'Name', 'referral-tracker-pro' ); ?></th>
			<td><?php echo $event['lead_name'] ? esc_html( $event['lead_name'] ) : '<span class="rtp-pd-empty">—</span>'; ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Email', 'referral-tracker-pro' ); ?></th>
			<td>
				<?php if ( ! empty( $event['lead_email'] ) ) : ?>
					<a href="mailto:<?php echo esc_attr( $event['lead_email'] ); ?>"><?php echo esc_html( $event['lead_email'] ); ?></a>
				<?php else : ?>
					<span class="rtp-pd-empty">—</span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Phone', 'referral-tracker-pro' ); ?></th>
			<td>
				<?php if ( ! empty( $event['lead_phone'] ) ) : ?>
					<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $event['lead_phone'] ) ); ?>"><?php echo esc_html( $event['lead_phone'] ); ?></a>
				<?php else : ?>
					<span class="rtp-pd-empty">—</span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Estimated Amount', 'referral-tracker-pro' ); ?></th>
			<td>
				<?php
				if ( null !== $event['lead_amount'] && '' !== $event['lead_amount'] ) {
					echo esc_html( $rtp_currency . number_format_i18n( (float) $event['lead_amount'], 2 ) );
				} else {
					echo '<span class="rtp-pd-empty">—</span>';
				}
				?>
			</td>
		</tr>
	</tbody>
</table>

<h2><?php esc_html_e( 'Submission', 'referral-tracker-pro' ); ?></h2>
<table class="rtp-pd-table">
	<tbody>
		<tr>
			<th><?php esc_html_e( 'Date / Time', 'referral-tracker-pro' ); ?></th>
			<td><?php echo esc_html( mysql2date( 'd M Y, H:i:s', $event['created_at'] ) ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Form', 'referral-tracker-pro' ); ?></th>
			<td>
				<?php echo esc_html( $event['form_name'] ? $event['form_name'] : $event['form_id'] ); ?>
				<?php if ( ! empty( $event['form_type'] ) ) : ?>
					<small>(<?php echo esc_html( $event['form_type'] ); ?>)</small>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Submitted From', 'referral-tracker-pro' ); ?></th>
			<td>
				<?php if ( ! empty( $event['event_page'] ) ) : ?>
					<a href="<?php echo esc_url( $event['event_page'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $event['event_page'] ); ?></a>
				<?php else : ?>
					<span class="rtp-pd-empty">—</span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Landing Page', 'referral-tracker-pro' ); ?></th>
			<td>
				<?php if ( ! empty( $event['landing_page'] ) ) : ?>
					<a href="<?php echo esc_url( $event['landing_page'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $event['landing_page'] ); ?></a>
				<?php else : ?>
					<span class="rtp-pd-empty">—</span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Device', 'referral-tracker-pro' ); ?></th>
			<td><?php echo esc_html( trim( $event['device'] . ' / ' . $event['browser'] . ' / ' . $event['os'], ' /' ) ); ?></td>
		</tr>
	</tbody>
</table>

<?php
// Filter fields once so we can also detect the "empty after filter" case.
$rtp_visible = array();
if ( ! empty( $fields ) && is_array( $fields ) ) {
	foreach ( $fields as $rtp_k => $rtp_v ) {
		$rtp_v_str = trim( (string) $rtp_v );
		if ( '' === $rtp_v_str ) {
			continue; // Skip empty values.
		}
		if ( ! $rtp_should_show( $rtp_k ) ) {
			continue; // Skip noise / duplicate keys.
		}
		$rtp_visible[ $rtp_k ] = $rtp_v_str;
	}
}
?>
<h2><?php esc_html_e( 'Service Selections', 'referral-tracker-pro' ); ?></h2>
<?php if ( empty( $rtp_visible ) ) : ?>
	<p class="rtp-pd-empty-block">
		<?php esc_html_e( 'No additional service selections recorded for this submission. To capture all calculator fields for future submissions, enable "Store form field data" in Referrals → Settings.', 'referral-tracker-pro' ); ?>
	</p>
<?php else : ?>
	<table class="rtp-pd-table rtp-pd-fields">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Selection', 'referral-tracker-pro' ); ?></th>
				<th><?php esc_html_e( 'Value', 'referral-tracker-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rtp_visible as $k => $v ) : ?>
			<tr>
				<th><?php echo esc_html( $rtp_label( $k ) ); ?></th>
				<td><?php echo esc_html( $v ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<p class="rtp-pd-footer">
	<?php echo esc_html( sprintf( /* translators: %s: site URL */ __( 'Lead generated via %s', 'referral-tracker-pro' ), home_url() ) ); ?>
</p>
