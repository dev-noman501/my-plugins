<?php
/**
 * Data layer: save / load / sanitize voucher data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TGMV_Data {

	/**
	 * Blank voucher structure (also documents every field).
	 */
	public static function blank() {
		return array(
			'voucher_no'    => '',
			'manual_no'     => '',
			'voucher_date'  => '',
			'package'       => '',
			'family_head'   => '',
			'pax_adult'     => 0,
			'pax_child'     => 0,
			'pax_infant'    => 0,
			'beds'          => 0,
			'status'        => 'unapproved', // approved | unapproved
			// Left header block (agency branding, defaults come from settings).
			'agency_name'   => '',
			'agency_logo'   => '', // attachment URL, empty = default logo
			// Right header block (receiver / arkan).
			'arkan_name'    => '',
			'arkan_ref'     => '',
			'arkan_city'    => '',
			'arkan_whatsapp' => '',
			// Repeaters.
			'mutamers'      => array(), // passport, name, gender, pax, bed, mofa, grp, visa, pnr
			'hotels'        => array(), // city, hotel, view, meal, conf, room_type, checkin, checkout, nights
			'transport'     => array(), // travel_date, transporter, type, description
			'dep_flights'   => array(), // flight, sector, departure, arrival
			'arr_flights'   => array(), // flight, sector, departure, arrival
			'instructions'  => '',
		);
	}

	/**
	 * Sanitize a full voucher data array coming from $_POST.
	 */
	public static function sanitize( $raw ) {
		$clean = self::blank();

		foreach ( array( 'manual_no', 'voucher_date', 'package', 'family_head', 'agency_name', 'arkan_name', 'arkan_ref', 'arkan_city', 'arkan_whatsapp' ) as $key ) {
			$clean[ $key ] = isset( $raw[ $key ] ) ? sanitize_text_field( wp_unslash( $raw[ $key ] ) ) : '';
		}

		foreach ( array( 'pax_adult', 'pax_child', 'pax_infant', 'beds' ) as $key ) {
			$clean[ $key ] = isset( $raw[ $key ] ) ? absint( $raw[ $key ] ) : 0;
		}

		$clean['agency_logo']  = isset( $raw['agency_logo'] ) ? esc_url_raw( wp_unslash( $raw['agency_logo'] ) ) : '';
		$clean['status']       = ( isset( $raw['status'] ) && 'approved' === $raw['status'] ) ? 'approved' : 'unapproved';
		$clean['instructions'] = isset( $raw['instructions'] ) ? sanitize_textarea_field( wp_unslash( $raw['instructions'] ) ) : '';

		$clean['mutamers'] = self::sanitize_rows(
			$raw,
			'mutamers',
			array( 'passport', 'name', 'gender', 'pax', 'bed', 'mofa', 'grp', 'visa', 'pnr' )
		);
		$clean['hotels'] = self::sanitize_rows(
			$raw,
			'hotels',
			array( 'city', 'hotel', 'view', 'meal', 'conf', 'room_type', 'checkin', 'checkout', 'nights' )
		);
		$clean['transport'] = self::sanitize_rows(
			$raw,
			'transport',
			array( 'travel_date', 'transporter', 'type', 'description' )
		);
		$clean['dep_flights'] = self::sanitize_rows(
			$raw,
			'dep_flights',
			array( 'flight', 'sector', 'departure', 'arrival' )
		);
		$clean['arr_flights'] = self::sanitize_rows(
			$raw,
			'arr_flights',
			array( 'flight', 'sector', 'departure', 'arrival' )
		);

		// PAX counts always derive from the Mutamers rows (no manual entry).
		$adult = $child = $infant = $beds = 0;
		foreach ( $clean['mutamers'] as $m ) {
			if ( 'Adult' === $m['pax'] ) {
				$adult++;
			} elseif ( 'Child' === $m['pax'] ) {
				$child++;
			} elseif ( 'Infant' === $m['pax'] ) {
				$infant++;
			}
			if ( 'Yes' === $m['bed'] ) {
				$beds++;
			}
		}
		$clean['pax_adult']  = $adult;
		$clean['pax_child']  = $child;
		$clean['pax_infant'] = $infant;
		$clean['beds']       = $beds;

		return $clean;
	}

	/**
	 * Repeater rows arrive as field-name arrays: mutamers[name][], mutamers[passport][] ...
	 * Rebuild them row-wise and drop fully empty rows.
	 */
	private static function sanitize_rows( $raw, $group, $fields ) {
		if ( empty( $raw[ $group ] ) || ! is_array( $raw[ $group ] ) ) {
			return array();
		}

		$src   = $raw[ $group ];
		$count = 0;
		foreach ( $fields as $f ) {
			if ( isset( $src[ $f ] ) && is_array( $src[ $f ] ) ) {
				$count = max( $count, count( $src[ $f ] ) );
			}
		}

		$rows = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$row   = array();
			$empty = true;
			foreach ( $fields as $f ) {
				$val       = isset( $src[ $f ][ $i ] ) ? sanitize_text_field( wp_unslash( $src[ $f ][ $i ] ) ) : '';
				$row[ $f ] = $val;
				if ( '' !== $val ) {
					$empty = false;
				}
			}
			if ( ! $empty ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Create or update a voucher. Returns post ID or WP_Error.
	 */
	public static function save( $data, $post_id = 0 ) {
		$is_new = empty( $post_id );

		if ( $is_new ) {
			$settings = TGMV_Settings::get();
			$number   = (int) get_option( 'tgmv_next_number', 100001 );
			$data['voucher_no'] = $settings['prefix'] . $number;

			$post_id = wp_insert_post(
				array(
					'post_type'   => 'tgm_voucher',
					'post_status' => 'publish',
					'post_title'  => $data['voucher_no'] . ' — ' . $data['family_head'],
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			update_option( 'tgmv_next_number', $number + 1 );
			update_post_meta( $post_id, '_tgmv_uuid', wp_generate_uuid4() );
		} else {
			$existing = get_post( $post_id );
			if ( ! $existing || 'tgm_voucher' !== $existing->post_type ) {
				return new WP_Error( 'tgmv_not_found', 'Voucher not found.' );
			}
			$data['voucher_no'] = get_post_meta( $post_id, '_tgmv_voucher_no', true );
			if ( '' === $data['voucher_no'] ) {
				$data['voucher_no'] = get_post_meta( $post_id, '_tgmv_data', true )['voucher_no'] ?? '';
			}
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $data['voucher_no'] . ' — ' . $data['family_head'],
				)
			);
		}

		update_post_meta( $post_id, '_tgmv_data', $data );

		// Flat copies for list-table search / sort / filter.
		update_post_meta( $post_id, '_tgmv_voucher_no', $data['voucher_no'] );
		update_post_meta( $post_id, '_tgmv_family_head', $data['family_head'] );
		update_post_meta( $post_id, '_tgmv_package', $data['package'] );
		update_post_meta( $post_id, '_tgmv_status', $data['status'] );
		update_post_meta( $post_id, '_tgmv_voucher_date', $data['voucher_date'] );

		self::remember_suggestions( $data );

		return $post_id;
	}

	/**
	 * Load a voucher's data array (merged over blank so new keys never break old vouchers).
	 */
	public static function load( $post_id ) {
		$data = get_post_meta( $post_id, '_tgmv_data', true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		return array_merge( self::blank(), $data );
	}

	/**
	 * Find voucher post by public UUID.
	 */
	public static function find_by_uuid( $uuid ) {
		$posts = get_posts(
			array(
				'post_type'      => 'tgm_voucher',
				'posts_per_page' => 1,
				'meta_key'       => '_tgmv_uuid',
				'meta_value'     => $uuid,
				'post_status'    => 'publish',
			)
		);
		return $posts ? $posts[0] : null;
	}

	public static function public_url( $post_id ) {
		$uuid = get_post_meta( $post_id, '_tgmv_uuid', true );
		if ( ! $uuid ) {
			return '';
		}
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/voucher/' . $uuid . '/' );
		}
		return add_query_arg( 'tgmv_uuid', $uuid, home_url( '/' ) );
	}

	/**
	 * Duplicate a voucher (new number + uuid, status reset to unapproved).
	 */
	public static function duplicate( $post_id ) {
		$data = self::load( $post_id );
		$data['status'] = 'unapproved';
		unset( $data['voucher_no'] );
		return self::save( $data, 0 );
	}

	/**
	 * Store previously used values so form fields can offer autocomplete suggestions.
	 */
	private static function remember_suggestions( $data ) {
		$map = array(
			'package'     => array( $data['package'] ),
			'arkan_name'  => array( $data['arkan_name'] ),
			'arkan_city'  => array( $data['arkan_city'] ),
			'agency_name' => array( $data['agency_name'] ),
			'hotel'       => wp_list_pluck( $data['hotels'], 'hotel' ),
			'view'        => wp_list_pluck( $data['hotels'], 'view' ),
			'meal'        => wp_list_pluck( $data['hotels'], 'meal' ),
			'room_type'   => wp_list_pluck( $data['hotels'], 'room_type' ),
			'transporter' => wp_list_pluck( $data['transport'], 'transporter' ),
			'transport_type' => wp_list_pluck( $data['transport'], 'type' ),
			'transport_desc' => wp_list_pluck( $data['transport'], 'description' ),
			'sector'      => array_merge( wp_list_pluck( $data['dep_flights'], 'sector' ), wp_list_pluck( $data['arr_flights'], 'sector' ) ),
			'flight'      => array_merge( wp_list_pluck( $data['dep_flights'], 'flight' ), wp_list_pluck( $data['arr_flights'], 'flight' ) ),
		);

		$stored = get_option( 'tgmv_suggestions', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		foreach ( $map as $key => $values ) {
			$current = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array();
			foreach ( $values as $v ) {
				$v = trim( (string) $v );
				if ( '' !== $v && ! in_array( $v, $current, true ) ) {
					$current[] = $v;
				}
			}
			$stored[ $key ] = array_slice( $current, -50 ); // keep the most recent 50
		}

		update_option( 'tgmv_suggestions', $stored, false );
	}

	public static function suggestions( $key ) {
		$stored = get_option( 'tgmv_suggestions', array() );
		return isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array();
	}

	/**
	 * "9 (A:9,C:0,I:0),Beds=9" — the PAX summary line used on the voucher header.
	 */
	public static function pax_line( $data ) {
		$total = (int) $data['pax_adult'] + (int) $data['pax_child'] + (int) $data['pax_infant'];
		return sprintf(
			'%d (A:%d,C:%d,I:%d),Beds=%d',
			$total,
			(int) $data['pax_adult'],
			(int) $data['pax_child'],
			(int) $data['pax_infant'],
			(int) $data['beds']
		);
	}
}
