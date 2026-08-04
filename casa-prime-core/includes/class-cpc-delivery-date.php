<?php
/**
 * Delivery day — "Today" or a chosen upcoming date.
 *
 * Replaces the earlier free-text ASAP / Schedule string. The customer picks a
 * day; the store staff need to see that day on every screen, so it is stored as
 * a real date (Y-m-d in store time) with a label rendered from it rather than
 * whatever wording the app happened to send.
 *
 * How far ahead they may book comes from Casa Prime → Delivery Settings, so the
 * client can change it without a code release.
 */

defined( 'ABSPATH' ) || exit;

class CPC_Delivery_Date {

	const META_DATE = '_cpc_delivery_date';

	/** Days the customer may choose from, oldest first. */
	public static function selectable_days() {
		$settings = CPC_Delivery_Settings::get_settings();
		$ahead    = max( 0, (int) ( $settings['schedule_days'] ?? 7 ) );
		$today    = current_time( 'Y-m-d' );

		$days = array();
		for ( $i = 0; $i <= $ahead; $i++ ) {
			$date = gmdate( 'Y-m-d', strtotime( $today . ' +' . $i . ' day' ) );
			$days[] = array(
				'date'     => $date,
				'label'    => self::label_for( $date ),
				'is_today' => 0 === $i,
			);
		}
		return $days;
	}

	/**
	 * "Today", "Tomorrow", else "Fri 25 Jul" — the same wording everywhere, so
	 * the customer, the manager and the rider all read the identical string.
	 */
	public static function label_for( $date ) {
		if ( ! $date ) {
			return '';
		}
		$today    = current_time( 'Y-m-d' );
		$tomorrow = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );

		if ( $date === $today ) {
			return 'Today';
		}
		if ( $date === $tomorrow ) {
			return 'Tomorrow';
		}
		return date_i18n( 'D j M', strtotime( $date ) );
	}

	/**
	 * Turn whatever the app sent into a stored date.
	 *
	 * Accepts 'today' (or empty) and 'Y-m-d'. Also tolerates the legacy 'ASAP'
	 * string so orders from an app build that predates this change still work.
	 * Returns a WP_Error for a past date or one beyond the booking window —
	 * silently snapping it would hide the mistake from the customer.
	 */
	public static function parse( $value ) {
		$value = trim( (string) $value );
		$today = current_time( 'Y-m-d' );

		if ( '' === $value || 0 === strcasecmp( $value, 'today' ) || 0 === strcasecmp( $value, 'asap' ) ) {
			return $today;
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) || ! strtotime( $value ) ) {
			return new WP_Error(
				'cpc_bad_delivery_date',
				'delivery_date must be "today" or a date in YYYY-MM-DD form.',
				array( 'status' => 400 )
			);
		}

		if ( $value < $today ) {
			return new WP_Error( 'cpc_delivery_date_past', 'That delivery date has already passed.', array( 'status' => 400 ) );
		}

		$settings = CPC_Delivery_Settings::get_settings();
		$last     = gmdate( 'Y-m-d', strtotime( $today . ' +' . max( 0, (int) ( $settings['schedule_days'] ?? 7 ) ) . ' day' ) );
		if ( $value > $last ) {
			return new WP_Error(
				'cpc_delivery_date_far',
				sprintf( 'We only take orders up to %s.', self::label_for( $last ) ),
				array( 'status' => 400 )
			);
		}

		return $value;
	}

	/* ---------- Reading it back off an order ---------- */

	public static function get( $order ) {
		return (string) $order->get_meta( self::META_DATE );
	}

	public static function label( $order ) {
		return self::label_for( self::get( $order ) );
	}

	/** True when the customer booked a day other than the day they ordered. */
	public static function is_scheduled( $order ) {
		$date = self::get( $order );
		return $date && $date !== current_time( 'Y-m-d' );
	}
}
