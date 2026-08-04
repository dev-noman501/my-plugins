<?php
/**
 * Public voucher rendering (/voucher/{uuid}/).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TGMV_Frontend {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	public static function maybe_render() {
		$uuid = get_query_var( 'tgmv_uuid' );
		if ( ! $uuid && isset( $_GET['tgmv_uuid'] ) ) {
			$uuid = sanitize_text_field( wp_unslash( $_GET['tgmv_uuid'] ) );
		}
		if ( ! $uuid ) {
			return;
		}

		$post = TGMV_Data::find_by_uuid( $uuid );
		if ( ! $post ) {
			status_header( 404 );
			wp_die( 'Voucher not found.', 'Voucher', array( 'response' => 404 ) );
		}

		$data     = TGMV_Data::load( $post->ID );
		$settings = TGMV_Settings::get();
		$public_url = TGMV_Data::public_url( $post->ID );

		nocache_headers();
		include TGMV_DIR . 'templates/voucher.php';
		exit;
	}

	/**
	 * d-m-y display date from a Y-m-d input value (falls back to raw value).
	 */
	public static function fdate( $value, $format = 'd-m-y' ) {
		if ( ! $value ) {
			return '';
		}
		$ts = strtotime( $value );
		return $ts ? date_i18n( $format, $ts ) : $value;
	}

	/**
	 * "08-JUL 16:45" from a datetime-local value (2026-07-08T16:45).
	 * Old vouchers with free-text values are shown as-is.
	 */
	public static function fdatetime( $value ) {
		if ( ! $value ) {
			return '';
		}
		if ( false === strpos( $value, 'T' ) ) {
			return $value;
		}
		$ts = strtotime( $value );
		return $ts ? strtoupper( date_i18n( 'd-M H:i', $ts ) ) : $value;
	}
}

TGMV_Frontend::init();
