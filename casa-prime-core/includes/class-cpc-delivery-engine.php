<?php
/**
 * Delivery fee engine — turns (customer location, cart subtotal) into a quote.
 *
 * Distance providers are pluggable:
 *  - 'radius'  → Haversine straight-line distance (free, no API key)
 *  - 'google'  → Google Distance Matrix driving distance (needs API key);
 *                falls back to Haversine if the API call fails.
 */

defined( 'ABSPATH' ) || exit;

class CPC_Delivery_Engine {

	const EARTH_RADIUS_MILES = 3958.8;

	/**
	 * Get a delivery quote.
	 *
	 * @param float $lat      Customer latitude.
	 * @param float $lng      Customer longitude.
	 * @param float $subtotal Cart/order subtotal (for the free-delivery threshold).
	 * @return array {deliverable, distance_miles, fee, free, method, message}
	 */
	public static function quote( $lat, $lng, $subtotal = 0 ) {
		$settings = CPC_Delivery_Settings::get_settings();
		$distance = self::get_distance( (float) $settings['store_lat'], (float) $settings['store_lng'], (float) $lat, (float) $lng, $settings );

		$quote = array(
			'deliverable'    => false,
			'distance_miles' => round( $distance, 2 ),
			'fee'            => null,
			'free'           => false,
			'method'         => $settings['distance_method'],
			'message'        => '',
		);

		// Find the matching tier.
		$tier_fee = null;
		foreach ( $settings['tiers'] as $tier ) {
			if ( $distance >= (float) $tier['from'] && $distance < (float) $tier['to'] ) {
				$tier_fee = (float) $tier['fee'];
				break;
			}
		}

		if ( null === $tier_fee ) {
			$quote['message'] = sprintf( "Sorry, we don't deliver to this address yet (%.1f miles away, max %.0f miles).", $distance, CPC_Delivery_Settings::get_max_range( $settings ) );
			return $quote;
		}

		$quote['deliverable'] = true;
		$quote['fee']         = $tier_fee;

		// Free-delivery threshold overrides any tier fee.
		if ( $tier_fee > 0 && ! empty( $settings['threshold_enabled'] ) && (float) $subtotal >= (float) $settings['threshold_amount'] ) {
			$quote['fee']     = 0.0;
			$quote['message'] = sprintf( 'Free delivery — order is over $%s.', number_format( (float) $settings['threshold_amount'], 2 ) );
		} elseif ( 0.0 === $tier_fee ) {
			$quote['message'] = 'You qualify for free delivery!';
		} else {
			$quote['message'] = sprintf( 'Delivery fee: $%s.', number_format( $tier_fee, 2 ) );
			if ( ! empty( $settings['threshold_enabled'] ) ) {
				$quote['message'] .= sprintf( ' Orders over $%s deliver free.', number_format( (float) $settings['threshold_amount'], 2 ) );
			}
		}

		$quote['free'] = ( 0.0 === (float) $quote['fee'] );
		return $quote;
	}

	/**
	 * Distance in miles between two points, using the configured method.
	 */
	public static function get_distance( $lat1, $lng1, $lat2, $lng2, $settings = null ) {
		$settings = $settings ? $settings : CPC_Delivery_Settings::get_settings();

		if ( 'google' === $settings['distance_method'] && ! empty( $settings['google_api_key'] ) ) {
			$driving = self::google_driving_distance( $lat1, $lng1, $lat2, $lng2, $settings['google_api_key'] );
			if ( null !== $driving ) {
				return $driving;
			}
			// API failure → fall through to Haversine so checkout never breaks.
		}

		return self::haversine( $lat1, $lng1, $lat2, $lng2 );
	}

	/**
	 * Straight-line (great-circle) distance in miles.
	 */
	public static function haversine( $lat1, $lng1, $lat2, $lng2 ) {
		$dlat = deg2rad( $lat2 - $lat1 );
		$dlng = deg2rad( $lng2 - $lng1 );
		$a = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlng / 2 ) ** 2;
		return self::EARTH_RADIUS_MILES * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/**
	 * Geocode a street address to [lat, lng] (null on failure).
	 * Uses Google Geocoding when an API key is set, otherwise OpenStreetMap
	 * Nominatim (free). Results are cached for a week.
	 */
	public static function geocode( $address ) {
		$address = trim( (string) $address );
		if ( '' === $address ) {
			return null;
		}
		$cache_key = 'cpc_geo_' . md5( strtolower( $address ) );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$settings = CPC_Delivery_Settings::get_settings();
		$coords = null;

		if ( ! empty( $settings['google_api_key'] ) ) {
			$response = wp_remote_get( add_query_arg( array(
				'address' => rawurlencode( $address ),
				'key'     => $settings['google_api_key'],
			), 'https://maps.googleapis.com/maps/api/geocode/json' ), array( 'timeout' => 8 ) );
			if ( ! is_wp_error( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				$loc = $body['results'][0]['geometry']['location'] ?? null;
				if ( $loc ) {
					$coords = array( 'lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng'] );
				}
			}
		}

		if ( ! $coords ) {
			$response = wp_remote_get( add_query_arg( array(
				'format' => 'json',
				'limit'  => 1,
				'q'      => rawurlencode( $address ),
			), 'https://nominatim.openstreetmap.org/search' ), array(
				'timeout' => 8,
				'headers' => array( 'User-Agent' => 'CasaPrimeStore/1.0 (WordPress; ' . home_url() . ')' ),
			) );
			if ( ! is_wp_error( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! empty( $body[0]['lat'] ) ) {
					$coords = array( 'lat' => (float) $body[0]['lat'], 'lng' => (float) $body[0]['lon'] );
				}
			}
		}

		if ( $coords ) {
			set_transient( $cache_key, $coords, WEEK_IN_SECONDS );
		}
		return $coords;
	}

	/**
	 * Google Distance Matrix driving distance in miles (null on any failure).
	 */
	public static function google_driving_distance( $lat1, $lng1, $lat2, $lng2, $api_key ) {
		$url = add_query_arg( array(
			'origins'      => $lat1 . ',' . $lng1,
			'destinations' => $lat2 . ',' . $lng2,
			'units'        => 'imperial',
			'key'          => $api_key,
		), 'https://maps.googleapis.com/maps/api/distancematrix/json' );

		$response = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$element = $body['rows'][0]['elements'][0] ?? null;
		if ( ! $element || 'OK' !== ( $element['status'] ?? '' ) || empty( $element['distance']['value'] ) ) {
			return null;
		}
		return (float) $element['distance']['value'] / 1609.344; // meters → miles
	}
}
