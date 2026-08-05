<?php
/**
 * Settings persistence + sanitization.
 *
 * The settings form itself is rendered in admin/views/settings.php and
 * submitted through admin-post.php (handled in RTP_Admin) so we keep full
 * control over nonces and capability checks.
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes and stores the plugin settings.
 */
class RTP_Settings {

	/**
	 * Sanitizes a raw settings array (from $_POST) into safe values.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();

		$cookie_days    = isset( $input['cookie_expiry_days'] ) ? absint( $input['cookie_expiry_days'] ) : 30;
		$retention_days = isset( $input['retention_days'] ) ? absint( $input['retention_days'] ) : 365;

		// Sensible bounds.
		$cookie_days    = max( 1, min( 365, $cookie_days ) );
		$retention_days = max( 7, min( 3650, $retention_days ) );

		return array(
			'cookie_expiry_days'   => $cookie_days,
			'retention_days'       => $retention_days,
			'enable_call_tracking' => empty( $input['enable_call_tracking'] ) ? 0 : 1,
			'enable_form_tracking' => empty( $input['enable_form_tracking'] ) ? 0 : 1,
			'enable_field_storage' => empty( $input['enable_field_storage'] ) ? 0 : 1,
			'delete_on_uninstall'  => empty( $input['delete_on_uninstall'] ) ? 0 : 1,
			'exclude_logged_in'    => empty( $input['exclude_logged_in'] ) ? 0 : 1,
			'store_ip'             => empty( $input['store_ip'] ) ? 0 : 1,
			'custom_form_selectors' => isset( $input['custom_form_selectors'] )
				? substr( sanitize_text_field( $input['custom_form_selectors'] ), 0, 500 )
				: '',
			'callrail_enabled'        => empty( $input['callrail_enabled'] ) ? 0 : 1,
			'callrail_api_key'        => isset( $input['callrail_api_key'] )
				? substr( sanitize_text_field( $input['callrail_api_key'] ), 0, 191 )
				: '',
			'callrail_account_id'     => isset( $input['callrail_account_id'] )
				? substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $input['callrail_account_id'] ), 0, 64 )
				: '',
			'callrail_company_id'     => isset( $input['callrail_company_id'] )
				? substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $input['callrail_company_id'] ), 0, 64 )
				: '',
			'callrail_webhook_secret' => isset( $input['callrail_webhook_secret'] )
				? substr( sanitize_text_field( $input['callrail_webhook_secret'] ), 0, 191 )
				: '',
			'callrail_tracking_number' => isset( $input['callrail_tracking_number'] )
				? substr( preg_replace( '/[^0-9+]/', '', $input['callrail_tracking_number'] ), 0, 40 )
				: '',
		);
	}

	/**
	 * Persists sanitized settings.
	 *
	 * @param array $input Raw input.
	 * @return void
	 */
	public static function save( $input ) {
		update_option( 'rtp_settings', self::sanitize( $input ) );
	}
}
