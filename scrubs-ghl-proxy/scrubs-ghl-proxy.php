<?php
/**
 * Plugin Name:       Scrubs GHL Proxy
 * Description:       Server-side proxy to the GoHighLevel Contacts API. Fixes the CORS error caused by direct browser → GHL calls and keeps the API token off the page source.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Noman Nadeem
 * License:           GPL-2.0-or-later
 * Text Domain:       scrubs-ghl-proxy
 *
 * @package ScrubsGhlProxy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * -------------------------------------------------------------------------
 * CONFIG — set your GHL credentials in wp-config.php, above the
 * "That's all, stop editing!" line:
 *
 *     define( 'SCRUBS_GHL_TOKEN',       'pit-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' );
 *     define( 'SCRUBS_GHL_LOCATION_ID', 'yourLocationIdHere' );
 *
 * The token must be a GHL v2 Private Integration Token — it starts with
 * "pit-". v1 API keys and OAuth access tokens (starting "eyJ") are rejected.
 *
 * Keeping these in wp-config.php means the secret never lives in the plugin
 * files or in version control. The placeholders below are intentionally left
 * empty so that an unconfigured install fails loudly instead of writing into
 * somebody else's CRM.
 * -------------------------------------------------------------------------
 */
if ( ! defined( 'SCRUBS_GHL_TOKEN' ) ) {
	// GHL v2 Private Integration Token (starts with "pit-").
	define( 'SCRUBS_GHL_TOKEN', '' );
}
if ( ! defined( 'SCRUBS_GHL_LOCATION_ID' ) ) {
	define( 'SCRUBS_GHL_LOCATION_ID', '' );
}
if ( ! defined( 'SCRUBS_GHL_ENDPOINT' ) ) {
	define( 'SCRUBS_GHL_ENDPOINT', 'https://services.leadconnectorhq.com/contacts/' );
}
if ( ! defined( 'SCRUBS_GHL_API_VERSION' ) ) {
	define( 'SCRUBS_GHL_API_VERSION', '2021-07-28' );
}

/**
 * Registers the REST proxy route.
 *
 * @return void
 */
add_action( 'rest_api_init', function () {
	register_rest_route(
		'scrubs/v1',
		'/ghl-contact',
		array(
			'methods'             => 'POST',
			'callback'            => 'scrubs_ghl_proxy_handle',
			'permission_callback' => '__return_true',
		)
	);
} );

/**
 * Receives the form payload, forwards it server-side to GHL, returns the response.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response
 */
function scrubs_ghl_proxy_handle( WP_REST_Request $request ) {

	// Same-origin check (defence in depth).
	$host_self = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
		$origin_host = wp_parse_url( wp_unslash( $_SERVER['HTTP_ORIGIN'] ), PHP_URL_HOST );
		if ( $origin_host && 0 !== strcasecmp( (string) $origin_host, (string) $host_self ) ) {
			return new WP_REST_Response( array( 'error' => 'Forbidden origin' ), 403 );
		}
	}

	// Basic per-IP rate limiting (20 requests / minute).
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
	$key = 'sghp_' . md5( $ip );
	$count = (int) get_transient( $key );
	if ( $count >= 20 ) {
		return new WP_REST_Response( array( 'error' => 'Too many requests' ), 429 );
	}
	set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) || empty( $payload['email'] ) ) {
		return new WP_REST_Response( array( 'error' => 'Invalid payload (email required)' ), 400 );
	}

	if ( 0 !== strpos( (string) SCRUBS_GHL_TOKEN, 'pit-' ) ) {
		return new WP_REST_Response(
			array( 'error' => 'GHL Private Integration Token not configured. Set SCRUBS_GHL_TOKEN (must start with "pit-") in wp-config.php or in the proxy plugin file.' ),
			500
		);
	}

	$payload_v2 = scrubs_ghl_proxy_transform_to_v2( $payload, SCRUBS_GHL_LOCATION_ID );

	$response = wp_remote_post(
		SCRUBS_GHL_ENDPOINT,
		array(
			'method'  => 'POST',
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . SCRUBS_GHL_TOKEN,
				'Version'       => SCRUBS_GHL_API_VERSION,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload_v2 ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_REST_Response( array( 'error' => $response->get_error_message() ), 502 );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$decoded = json_decode( $body, true );

	// Wrap GHL v2 error responses ({message: "..."}) into the nested shape
	// ({error: {message: "..."}}) that the legacy calculator JS expects, so
	// the actual reason (e.g. "duplicate contact") is shown to the visitor
	// instead of a generic "Network response was not ok".
	if ( $code >= 400 ) {
		$msg = '';
		if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
			$msg = (string) $decoded['message'];
		}
		if ( '' === $msg ) {
			$msg = 'Submission failed. Please try again.';
		}
		return new WP_REST_Response(
			array( 'error' => array( 'message' => $msg ) ),
			$code
		);
	}

	return new WP_REST_Response(
		is_array( $decoded ) ? $decoded : array( 'raw' => $body ),
		$code > 0 ? $code : 200
	);
}

/**
 * Converts the legacy v1-style payload (sent by the existing calculator JS)
 * into the GoHighLevel v2 Contacts API format.
 *
 *  - Adds the required `locationId`.
 *  - Passes basic identity fields through unchanged.
 *  - Enriches `source` with the calculator total estimate when available.
 *  - Maps the most useful customField selections (clean type, property type,
 *    bedrooms, bathrooms, balcony/zone flags) into the v2 `tags` array so they
 *    remain visible in the CRM without needing per-custom-field IDs yet.
 *  - Strips the v1 `customField` object (v2 expects `customFields` with field
 *    IDs which we don't have mapped yet — handled in a later phase).
 *
 * @param array  $input       Raw incoming payload.
 * @param string $location_id GHL sub-account id.
 * @return array
 */
function scrubs_ghl_proxy_transform_to_v2( $input, $location_id ) {
	$output = array(
		'locationId' => $location_id,
	);

	foreach ( array( 'firstName', 'lastName', 'email', 'phone' ) as $field ) {
		if ( ! empty( $input[ $field ] ) ) {
			$output[ $field ] = substr( (string) $input[ $field ], 0, 191 );
		}
	}

	$source = isset( $input['source'] ) ? trim( (string) $input['source'] ) : '';
	if ( '' === $source ) {
		$source = 'Website Calculator';
	}

	$custom = isset( $input['customField'] ) && is_array( $input['customField'] ) ? $input['customField'] : array();

	if ( ! empty( $custom['total_estimate'] ) ) {
		$amount = preg_replace( '/[^0-9.]/', '', (string) $custom['total_estimate'] );
		if ( '' !== $amount ) {
			$source .= ' | Est. £' . $amount;
		}
	}
	$output['source'] = substr( $source, 0, 250 );

	// Baseline tag used by the GHL workflow ("Website Form Submission")
	// as its trigger. Structured data (bedrooms, total, etc.) goes into
	// proper GHL custom fields below, not into tags.
	$output['tags'] = array( 'Website Calculator' );

	// Build customFields v2 array from the v1 customField object.
	// The calculator's keys map 1:1 to GHL custom field keys for this
	// location (verified against the live customFields list), so a direct
	// pass-through populates each field exactly as the original integration
	// did. GHL silently ignores any key it doesn't recognise.
	$custom_fields_v2 = array();
	foreach ( $custom as $key => $value ) {
		$key = (string) $key;
		if ( '' === $key || is_array( $value ) || is_object( $value ) ) {
			continue;
		}
		$value = (string) $value;
		if ( '' === $value ) {
			continue;
		}
		$custom_fields_v2[] = array(
			'key'         => substr( $key, 0, 100 ),
			'field_value' => substr( $value, 0, 500 ),
		);
	}
	if ( ! empty( $custom_fields_v2 ) ) {
		$output['customFields'] = $custom_fields_v2;
	}

	return $output;
}
