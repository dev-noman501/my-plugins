<?php
/**
 * Shared helper utilities.
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helper methods used across the plugin.
 */
class RTP_Helpers {

	/**
	 * Returns plugin settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'cookie_expiry_days'       => 30,
			'enable_call_tracking'     => 1,
			'enable_form_tracking'     => 1,
			'enable_field_storage'     => 0,
			'delete_on_uninstall'      => 0,
			'retention_days'           => 365,
			'exclude_logged_in'        => 1,
			'store_ip'                 => 0,
			'custom_form_selectors'    => '#submit-btn',
			'callrail_enabled'          => 0,
			'callrail_api_key'          => '',
			'callrail_account_id'       => '',
			'callrail_company_id'       => '',
			'callrail_webhook_secret'   => '',
			'callrail_tracking_number'  => '',
		);

		$saved = get_option( 'rtp_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Reads a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when not set.
	 * @return mixed
	 */
	public static function get_setting( $key, $default = null ) {
		$settings = self::get_settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Determines whether tracking should be skipped for the current user.
	 *
	 * @return bool
	 */
	public static function should_skip_tracking() {
		if ( self::get_setting( 'exclude_logged_in' ) && is_user_logged_in() ) {
			return true;
		}
		return false;
	}

	/**
	 * Returns the visitor IP address (best effort, proxy aware but conservative).
	 *
	 * @return string
	 */
	public static function get_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		// Only trust a forwarded header if it is a valid IP (single hop).
		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$candidate = trim( $forwarded[0] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
			}
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Stores either a salted hash of the IP, the raw IP, or empty,
	 * depending on the "store_ip" setting.
	 *
	 * @return string
	 */
	public static function get_ip_for_storage() {
		$ip = self::get_ip();
		if ( '' === $ip ) {
			return '';
		}

		if ( self::get_setting( 'store_ip' ) ) {
			return $ip;
		}

		// Privacy-preserving: salted SHA-256, not reversible.
		return hash( 'sha256', $ip . wp_salt( 'auth' ) );
	}

	/**
	 * Parses a User-Agent string into device / browser / os.
	 *
	 * Lightweight, dependency-free detection. Good enough for analytics
	 * segmentation without shipping a heavy UA library.
	 *
	 * @param string $ua Raw user agent.
	 * @return array{device:string,browser:string,os:string}
	 */
	public static function parse_user_agent( $ua ) {
		$ua = (string) $ua;

		$device = 'Desktop';
		if ( preg_match( '/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua ) ) {
			$device = 'Tablet';
		} elseif ( preg_match( '/Mobi|Android|iPhone|iPod|IEMobile|BlackBerry|Opera Mini/i', $ua ) ) {
			$device = 'Mobile';
		}

		$browser = 'Other';
		if ( preg_match( '/Edg|Edge/i', $ua ) ) {
			$browser = 'Edge';
		} elseif ( preg_match( '/OPR|Opera/i', $ua ) ) {
			$browser = 'Opera';
		} elseif ( preg_match( '/Chrome|CriOS/i', $ua ) ) {
			$browser = 'Chrome';
		} elseif ( preg_match( '/Firefox|FxiOS/i', $ua ) ) {
			$browser = 'Firefox';
		} elseif ( preg_match( '/Safari/i', $ua ) ) {
			$browser = 'Safari';
		}

		$os = 'Other';
		if ( preg_match( '/Windows NT/i', $ua ) ) {
			$os = 'Windows';
		} elseif ( preg_match( '/iPhone|iPad|iPod/i', $ua ) ) {
			$os = 'iOS';
		} elseif ( preg_match( '/Mac OS X/i', $ua ) ) {
			$os = 'macOS';
		} elseif ( preg_match( '/Android/i', $ua ) ) {
			$os = 'Android';
		} elseif ( preg_match( '/Linux/i', $ua ) ) {
			$os = 'Linux';
		}

		return array(
			'device'  => $device,
			'browser' => $browser,
			'os'      => $os,
		);
	}

	/**
	 * Sanitizes a referral code to a safe, predictable format.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	public static function sanitize_code( $code ) {
		$code = sanitize_text_field( (string) $code );
		$code = preg_replace( '/[^A-Za-z0-9_\-]/', '', $code );
		return substr( (string) $code, 0, 64 );
	}

	/**
	 * Sanitizes a URL/path to a safe relative-ish string for storage.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	public static function sanitize_url_field( $url ) {
		$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
		return substr( $url, 0, 512 );
	}

	/**
	 * Field-name patterns that must never be persisted.
	 *
	 * @return string[]
	 */
	public static function sensitive_field_patterns() {
		$patterns = array(
			'pass',
			'pwd',
			'card',
			'cc-num',
			'ccnum',
			'cvv',
			'cvc',
			'cvc2',
			'security_code',
			'iban',
			'sortcode',
			'sort_code',
			'account_number',
			'acct',
			'routing',
			'ssn',
			'payment',
			'paypal',
			'stripe',
			'token',
			'secret',
		);

		/**
		 * Allows extending the list of blocked sensitive field name fragments.
		 *
		 * @param string[] $patterns Lowercase fragments.
		 */
		return apply_filters( 'rtp_sensitive_field_patterns', $patterns );
	}

	/**
	 * Removes sensitive entries from a captured field map and sanitizes the rest.
	 *
	 * @param array $fields Raw key => value pairs.
	 * @return array
	 */
	public static function filter_field_data( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$patterns = self::sensitive_field_patterns();
		$clean    = array();
		$count    = 0;

		foreach ( $fields as $key => $value ) {
			if ( $count >= 40 ) {
				break; // Hard cap to avoid bloated rows.
			}

			$key_l = strtolower( (string) $key );
			$skip  = false;
			foreach ( $patterns as $pattern ) {
				if ( false !== strpos( $key_l, $pattern ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			$safe_key = substr( sanitize_key( $key ), 0, 64 );
			if ( '' === $safe_key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}

			$clean[ $safe_key ] = substr( sanitize_textarea_field( (string) $value ), 0, 500 );
			$count++;
		}

		return $clean;
	}

	/**
	 * Safe JSON encode for DB storage.
	 *
	 * @param mixed $data Data to encode.
	 * @return string
	 */
	public static function json_encode( $data ) {
		$json = wp_json_encode( $data );
		return ( false === $json ) ? '' : $json;
	}

	/**
	 * Extracts lead identity (name / email / phone / amount) from a raw
	 * submitted-fields array. Works universally for the calculator (which
	 * uses Elementor `form-field-*` ids) and any standard WP form plugin
	 * by scanning for common naming patterns.
	 *
	 * @param array $fields Raw key => value pairs as submitted.
	 * @return array{name:string,email:string,phone:string,amount:?float}
	 */
	public static function extract_lead_fields( $fields ) {
		$blank = array(
			'name'   => '',
			'email'  => '',
			'phone'  => '',
			'amount' => null,
		);

		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return $blank;
		}

		// Normalise keys so different form plugins all map onto the same vocab:
		//   "form-field-first_name"   → "first_name"   (Elementor calculator id)
		//   "form_fields[first_name]" → "first_name"   (Elementor Pro field name)
		//   "Your-Email"              → "your_email"   (CF7 / generic)
		$norm = array();
		foreach ( $fields as $k => $v ) {
			$kn = strtolower( (string) $k );
			if ( preg_match( '/form_fields\[([^\]]+)\]/', $kn, $m ) ) {
				$kn = $m[1];
			}
			$kn = preg_replace( '/^form-field-/', '', $kn );
			$kn = str_replace( array( '-', ' ' ), '_', $kn );
			if ( is_array( $v ) ) {
				$v = implode( ', ', array_map( 'strval', $v ) );
			}
			$norm[ $kn ] = (string) $v;
		}

		// Name — prefer first + last, fall back to single-name fields.
		$first = isset( $norm['first_name'] ) ? trim( $norm['first_name'] ) : '';
		$last  = isset( $norm['last_name'] ) ? trim( $norm['last_name'] ) : '';
		$name  = trim( $first . ' ' . $last );

		if ( '' === $name ) {
			foreach ( array( 'full_name', 'name', 'your_name', 'contact_name', 'customer_name' ) as $k ) {
				if ( ! empty( $norm[ $k ] ) ) {
					$name = trim( $norm[ $k ] );
					break;
				}
			}
		}

		// Email — prefer common keys, then any key containing "email".
		$email = '';
		foreach ( array( 'email', 'e_mail', 'email_address', 'your_email', 'customer_email' ) as $k ) {
			if ( ! empty( $norm[ $k ] ) && is_email( $norm[ $k ] ) ) {
				$email = $norm[ $k ];
				break;
			}
		}
		if ( '' === $email ) {
			foreach ( $norm as $k => $v ) {
				if ( false !== strpos( $k, 'email' ) && is_email( $v ) ) {
					$email = $v;
					break;
				}
			}
		}

		// Phone — common keys then fuzzy match.
		$phone = '';
		foreach ( array( 'phone', 'mobile', 'tel', 'telephone', 'phone_number', 'mobile_number', 'contact_number', 'your_phone' ) as $k ) {
			if ( ! empty( $norm[ $k ] ) ) {
				$phone = $norm[ $k ];
				break;
			}
		}
		if ( '' === $phone ) {
			foreach ( $norm as $k => $v ) {
				if ( '' === $v ) {
					continue;
				}
				if ( false !== strpos( $k, 'phone' ) || false !== strpos( $k, 'mobile' ) || false !== strpos( $k, 'telephone' ) ) {
					$phone = $v;
					break;
				}
			}
		}

		// Amount — parse the first numeric value out of things like
		// "Total Estimate: £225" or "£180.50" or "225".
		$amount = null;
		foreach ( array( 'total_estimate', 'estimate', 'amount', 'price', 'total', 'quote', 'total_price', 'monetary_value' ) as $k ) {
			if ( empty( $norm[ $k ] ) ) {
				continue;
			}
			if ( preg_match( '/-?\d+(\.\d+)?/', $norm[ $k ], $m ) ) {
				$amount = (float) $m[0];
				break;
			}
		}

		return array(
			'name'   => substr( $name, 0, 191 ),
			'email'  => substr( $email, 0, 191 ),
			'phone'  => substr( $phone, 0, 40 ),
			'amount' => $amount,
		);
	}

	/**
	 * Validates a UUID-ish session id coming from the client.
	 *
	 * @param string $sid Session id.
	 * @return string Sanitized id or '' if invalid.
	 */
	public static function sanitize_session_id( $sid ) {
		$sid = preg_replace( '/[^a-f0-9\-]/i', '', (string) $sid );
		if ( strlen( $sid ) < 16 || strlen( $sid ) > 64 ) {
			return '';
		}
		return $sid;
	}
}
