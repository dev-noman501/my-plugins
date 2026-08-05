<?php
/**
 * Server-side form plugin integrations.
 *
 * These hooks fire after a form is successfully submitted. The referral
 * attribution is read from the first-touch cookie (sent with the form POST),
 * which makes attribution reliable even across pages and page caching.
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records form-submission events for supported form plugins.
 */
class RTP_Form_Integrations {

	/**
	 * Registers integration hooks (only those whose plugin is active).
	 *
	 * @return void
	 */
	public function init() {
		if ( ! RTP_Helpers::get_setting( 'enable_form_tracking', 1 ) ) {
			return;
		}

		// Contact Form 7.
		add_action( 'wpcf7_mail_sent', array( $this, 'handle_cf7' ), 20, 1 );

		// WPForms.
		add_action( 'wpforms_process_complete', array( $this, 'handle_wpforms' ), 20, 4 );

		// Gravity Forms.
		add_action( 'gform_after_submission', array( $this, 'handle_gravity' ), 20, 2 );

		// Elementor Pro forms.
		add_action( 'elementor_pro/forms/new_record', array( $this, 'handle_elementor' ), 20, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Cookie / attribution
	 * ------------------------------------------------------------------- */

	/**
	 * Reads & validates the first-touch referral cookie.
	 *
	 * @return array|null { code, session_id, landing } or null when absent/invalid.
	 */
	private function get_referral_context() {
		if ( empty( $_COOKIE[ RTP_COOKIE_NAME ] ) ) {
			return null;
		}

		$raw = wp_unslash( $_COOKIE[ RTP_COOKIE_NAME ] );

		// PHP usually url-decodes cookies already; handle both forms safely.
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$data = json_decode( rawurldecode( $raw ), true );
		}
		if ( ! is_array( $data ) ) {
			return null;
		}

		$code       = RTP_Helpers::sanitize_code( isset( $data['c'] ) ? $data['c'] : '' );
		$session_id = RTP_Helpers::sanitize_session_id( isset( $data['s'] ) ? $data['s'] : '' );
		$landing    = RTP_Helpers::sanitize_url_field( isset( $data['l'] ) ? $data['l'] : '' );

		if ( '' === $code || '' === $session_id ) {
			return null;
		}

		$campaign = RTP_Database::get_active_campaign_by_code( $code );
		if ( ! $campaign ) {
			return null;
		}

		return array(
			'campaign'   => $campaign,
			'code'       => $code,
			'session_id' => $session_id,
			'landing'    => $landing,
		);
	}

	/**
	 * Records a form event from the given context + meta.
	 *
	 * @param string $form_id   Form identifier.
	 * @param string $form_name Human form title.
	 * @param string $form_type Form plugin slug.
	 * @param array  $fields    Raw submitted fields (optional).
	 * @return void
	 */
	private function record_form( $form_id, $form_name, $form_type, $fields = array() ) {
		$ctx = $this->get_referral_context();
		if ( null === $ctx ) {
			return; // Not a referred visitor.
		}

		// Avoid double-counting when the generic JS submit handler already
		// logged this same submission moments earlier.
		if ( RTP_Database::recent_event_exists( $ctx['session_id'], 'form' ) ) {
			return;
		}

		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$uadata = RTP_Helpers::parse_user_agent( $ua );
		$page   = RTP_Helpers::sanitize_url_field( wp_get_referer() );

		// Refresh session activity / ensure it exists (first-touch preserved).
		RTP_Database::ensure_session(
			array(
				'session_id'    => $ctx['session_id'],
				'campaign_id'   => (int) $ctx['campaign']->id,
				'referral_code' => $ctx['code'],
				'referral_type' => sanitize_key( $ctx['campaign']->type ),
				'landing_page'  => $ctx['landing'],
				'referrer_url'  => '',
				'utm'           => '',
				'ip_store'      => RTP_Helpers::get_ip_for_storage(),
				'user_agent'    => substr( $ua, 0, 255 ),
				'device'        => $uadata['device'],
				'browser'       => $uadata['browser'],
				'os'            => $uadata['os'],
			)
		);

		// Always extract lead identity (lightweight, the whole point of the feature);
		// full sanitized field dump only when admin has explicitly opted in.
		$lead  = RTP_Helpers::extract_lead_fields( $fields );
		$extra = '';
		if ( RTP_Helpers::get_setting( 'enable_field_storage', 0 ) && ! empty( $fields ) ) {
			$clean = RTP_Helpers::filter_field_data( $fields );
			if ( ! empty( $clean ) ) {
				$extra = RTP_Helpers::json_encode( array( 'fields' => $clean ) );
			}
		}

		RTP_Database::insert_event(
			array(
				'session_id'    => $ctx['session_id'],
				'campaign_id'   => (int) $ctx['campaign']->id,
				'referral_code' => $ctx['code'],
				'event_type'    => 'form',
				'event_page'    => $page,
				'landing_page'  => $ctx['landing'],
				'phone_number'  => '',
				'form_id'       => substr( sanitize_text_field( (string) $form_id ), 0, 100 ),
				'form_name'     => substr( sanitize_text_field( (string) $form_name ), 0, 191 ),
				'form_type'     => substr( sanitize_key( (string) $form_type ), 0, 40 ),
				'lead_name'     => $lead['name'],
				'lead_email'    => $lead['email'],
				'lead_phone'    => $lead['phone'],
				'lead_amount'   => $lead['amount'],
				'device'        => $uadata['device'],
				'browser'       => $uadata['browser'],
				'os'            => $uadata['os'],
				'extra'         => $extra,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Per-plugin handlers
	 * ------------------------------------------------------------------- */

	/**
	 * Contact Form 7.
	 *
	 * @param mixed $contact_form WPCF7_ContactForm instance.
	 * @return void
	 */
	public function handle_cf7( $contact_form ) {
		if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'id' ) ) {
			return;
		}

		$fields = array();
		if ( class_exists( 'WPCF7_Submission' ) ) {
			$submission = WPCF7_Submission::get_instance();
			if ( $submission ) {
				$posted = $submission->get_posted_data();
				if ( is_array( $posted ) ) {
					$fields = $posted;
				}
			}
		}

		$this->record_form(
			(string) $contact_form->id(),
			(string) $contact_form->title(),
			'cf7',
			$fields
		);
	}

	/**
	 * WPForms.
	 *
	 * @param array $fields    Submitted fields.
	 * @param array $entry     Raw entry.
	 * @param array $form_data Form configuration.
	 * @param int   $entry_id  Entry id.
	 * @return void
	 */
	public function handle_wpforms( $fields, $entry, $form_data, $entry_id ) {
		$form_id   = isset( $form_data['id'] ) ? $form_data['id'] : '';
		$form_name = isset( $form_data['settings']['form_title'] ) ? $form_data['settings']['form_title'] : '';

		$flat = array();
		if ( is_array( $fields ) ) {
			foreach ( $fields as $field ) {
				if ( isset( $field['name'] ) ) {
					$flat[ $field['name'] ] = isset( $field['value'] ) ? $field['value'] : '';
				}
			}
		}

		$this->record_form( $form_id, $form_name, 'wpforms', $flat );
	}

	/**
	 * Gravity Forms.
	 *
	 * @param array $entry Entry data.
	 * @param array $form  Form definition.
	 * @return void
	 */
	public function handle_gravity( $entry, $form ) {
		$form_id   = isset( $form['id'] ) ? $form['id'] : '';
		$form_name = isset( $form['title'] ) ? $form['title'] : '';

		$flat = array();
		if ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				$label = isset( $field->label ) ? $field->label : ( isset( $field['label'] ) ? $field['label'] : '' );
				$fid   = isset( $field->id ) ? $field->id : ( isset( $field['id'] ) ? $field['id'] : '' );
				if ( '' !== $label && isset( $entry[ $fid ] ) ) {
					$flat[ $label ] = $entry[ $fid ];
				}
			}
		}

		$this->record_form( $form_id, $form_name, 'gravityforms', $flat );
	}

	/**
	 * Elementor Pro forms.
	 *
	 * @param mixed $record  Form record.
	 * @param mixed $handler Ajax handler.
	 * @return void
	 */
	public function handle_elementor( $record, $handler ) {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get_form_settings' ) ) {
			return;
		}

		$form_name = (string) $record->get_form_settings( 'form_name' );
		$form_id   = (string) $record->get_form_settings( 'id' );

		$flat = array();
		$raw  = $record->get( 'fields' );
		if ( is_array( $raw ) ) {
			foreach ( $raw as $key => $field ) {
				$label = isset( $field['title'] ) && '' !== $field['title'] ? $field['title'] : $key;
				$flat[ $label ] = isset( $field['value'] ) ? $field['value'] : '';
			}
		}

		$this->record_form( $form_id, $form_name, 'elementor', $flat );
	}
}
