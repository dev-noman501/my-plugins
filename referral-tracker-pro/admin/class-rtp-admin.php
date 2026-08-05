<?php
/**
 * Admin UI controller: menus, assets, form handlers, CSV export.
 *
 * @package ReferralTrackerPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires up everything that lives under wp-admin.
 */
class RTP_Admin {

	/**
	 * Required capability for every screen / action.
	 */
	const CAP = 'manage_options';

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_post_rtp_save_campaign', array( $this, 'handle_save_campaign' ) );
		add_action( 'admin_post_rtp_delete_campaign', array( $this, 'handle_delete_campaign' ) );
		add_action( 'admin_post_rtp_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_rtp_export_csv', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_rtp_export_leads', array( $this, 'handle_export_leads' ) );
		add_action( 'admin_post_rtp_export_calls', array( $this, 'handle_export_calls' ) );
		add_action( 'admin_post_rtp_callrail_sync', array( $this, 'handle_callrail_sync' ) );
		add_action( 'wp_ajax_rtp_lead_detail', array( $this, 'handle_lead_detail_ajax' ) );
	}

	/**
	 * AJAX: returns the rendered HTML for the lead-detail modal.
	 *
	 * @return void
	 */
	public function handle_lead_detail_ajax() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'rtp_lead_detail' );

		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$event = $id ? RTP_Analytics::get_event_by_id( $id ) : null;

		if ( ! $event ) {
			wp_send_json_error( array( 'error' => 'not-found' ), 404 );
		}

		$fields = array();
		if ( ! empty( $event['extra'] ) ) {
			$decoded = json_decode( $event['extra'], true );
			if ( is_array( $decoded ) && ! empty( $decoded['fields'] ) && is_array( $decoded['fields'] ) ) {
				$fields = $decoded['fields'];
			}
		}

		ob_start();
		require RTP_PLUGIN_DIR . 'admin/views/lead-detail-content.php';
		$html = ob_get_clean();

		wp_send_json_success( $html );
	}

	/* ---------------------------------------------------------------------
	 * Menu + assets
	 * ------------------------------------------------------------------- */

	/**
	 * Builds the admin menu tree.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Referrals', 'referral-tracker-pro' ),
			__( 'Referrals', 'referral-tracker-pro' ),
			self::CAP,
			'rtp-analytics',
			array( $this, 'render_analytics' ),
			'dashicons-share',
			58
		);

		add_submenu_page(
			'rtp-analytics',
			__( 'Analytics', 'referral-tracker-pro' ),
			__( 'Analytics', 'referral-tracker-pro' ),
			self::CAP,
			'rtp-analytics',
			array( $this, 'render_analytics' )
		);

		add_submenu_page(
			'rtp-analytics',
			__( 'Referral Links', 'referral-tracker-pro' ),
			__( 'Referral Links', 'referral-tracker-pro' ),
			self::CAP,
			'rtp-campaigns',
			array( $this, 'render_campaigns' )
		);

		add_submenu_page(
			'rtp-analytics',
			__( 'Leads', 'referral-tracker-pro' ),
			__( 'Leads', 'referral-tracker-pro' ),
			self::CAP,
			'rtp-leads',
			array( $this, 'render_leads' )
		);

		add_submenu_page(
			'rtp-analytics',
			__( 'Calls', 'referral-tracker-pro' ),
			__( 'Calls', 'referral-tracker-pro' ),
			self::CAP,
			'rtp-calls',
			array( $this, 'render_calls' )
		);

		add_submenu_page(
			'rtp-analytics',
			__( 'CallRail Calls', 'referral-tracker-pro' ),
			__( 'CallRail', 'referral-tracker-pro' ),
			self::CAP,
			'rtp-callrail',
			array( $this, 'render_callrail' )
		);

		add_submenu_page(
			'rtp-analytics',
			__( 'Events', 'referral-tracker-pro' ),
			__( 'Events', 'referral-tracker-pro' ),
			self::CAP,
			'rtp-events',
			array( $this, 'render_events' )
		);

		add_submenu_page(
			'rtp-analytics',
			__( 'Settings', 'referral-tracker-pro' ),
			__( 'Settings', 'referral-tracker-pro' ),
			self::CAP,
			'rtp-settings',
			array( $this, 'render_settings' )
		);

		// Hidden detail screen (linked from lists).
		add_submenu_page(
			'',
			__( 'Referral Detail', 'referral-tracker-pro' ),
			__( 'Referral Detail', 'referral-tracker-pro' ),
			self::CAP,
			'rtp-referral-detail',
			array( $this, 'render_referral_detail' )
		);

		// Hidden lead detail screen (View button on Leads / Events).
		add_submenu_page(
			'',
			__( 'Lead Detail', 'referral-tracker-pro' ),
			__( 'Lead Detail', 'referral-tracker-pro' ),
			self::CAP,
			'rtp-lead-detail',
			array( $this, 'render_lead_detail' )
		);
	}

	/**
	 * Loads admin CSS/JS only on plugin screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'rtp-' ) ) {
			return;
		}

		wp_enqueue_style(
			'rtp-admin',
			RTP_PLUGIN_URL . 'assets/css/rtp-admin.css',
			array(),
			RTP_VERSION
		);

		wp_enqueue_script(
			'rtp-admin',
			RTP_PLUGIN_URL . 'assets/js/rtp-admin.js',
			array(),
			RTP_VERSION,
			true
		);

		wp_localize_script(
			'rtp-admin',
			'RTP_ADMIN',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rtp_lead_detail' ),
				'i18n'    => array(
					'loading'  => __( 'Loading lead…', 'referral-tracker-pro' ),
					'failed'   => __( 'Could not load lead details.', 'referral-tracker-pro' ),
					'title'    => __( 'Lead Details', 'referral-tracker-pro' ),
					'download' => __( 'Download / Print PDF', 'referral-tracker-pro' ),
					'close'    => __( 'Close', 'referral-tracker-pro' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Render callbacks
	 * ------------------------------------------------------------------- */

	/**
	 * Guards every screen render.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'referral-tracker-pro' ) );
		}
	}

	/**
	 * Analytics dashboard.
	 *
	 * @return void
	 */
	public function render_analytics() {
		$this->guard();

		$filters    = RTP_Analytics::normalize_filters( $this->get_request_filters() );
		$summary    = RTP_Analytics::get_summary( $filters );
		$top        = RTP_Analytics::get_top_campaigns( $filters, 10 );
		$visit_pgs  = RTP_Analytics::get_top_pages( $filters, 'visit', 'landing_page', 10 );
		$call_pgs   = RTP_Analytics::get_top_pages( $filters, 'call', 'event_page', 10 );
		$form_pgs   = RTP_Analytics::get_top_pages( $filters, 'form', 'event_page', 10 );
		$campaigns  = RTP_Analytics::list_campaigns();

		require RTP_PLUGIN_DIR . 'admin/views/analytics.php';
	}

	/**
	 * Referral links list / add / edit.
	 *
	 * @return void
	 */
	public function render_campaigns() {
		$this->guard();

		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'edit' === $action || 'new' === $action ) {
			$campaign = $edit_id ? RTP_Database::get_campaign( $edit_id ) : null;
			require RTP_PLUGIN_DIR . 'admin/views/campaign-edit.php';
			return;
		}

		$campaigns = RTP_Analytics::list_campaigns();
		require RTP_PLUGIN_DIR . 'admin/views/campaigns.php';
	}

	/**
	 * Detailed events table.
	 *
	 * @return void
	 */
	public function render_events() {
		$this->guard();

		$filters  = RTP_Analytics::normalize_filters( $this->get_request_filters() );
		$type     = isset( $_GET['etype'] ) ? sanitize_key( wp_unslash( $_GET['etype'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result   = RTP_Analytics::get_events( $filters, $paged, 25, $type );
		$campaigns = RTP_Analytics::list_campaigns();

		require RTP_PLUGIN_DIR . 'admin/views/events.php';
	}

	/**
	 * Leads list (only form-submission events with full contact details).
	 *
	 * @return void
	 */
	public function render_leads() {
		$this->guard();

		$filters  = RTP_Analytics::normalize_filters( $this->get_request_filters() );
		$paged    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result    = RTP_Analytics::get_leads( $filters, $paged, 25, $search );
		$campaigns = RTP_Analytics::list_campaigns();

		require RTP_PLUGIN_DIR . 'admin/views/leads.php';
	}

	/**
	 * Calls list (CallRail-captured calls with the visitor's real number).
	 *
	 * @return void
	 */
	public function render_calls() {
		$this->guard();

		$filters   = RTP_Analytics::normalize_filters( $this->get_request_filters() );
		$paged     = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result    = RTP_Analytics::get_calls( $filters, $paged, 25, $search );
		$campaigns = RTP_Analytics::list_campaigns();

		require RTP_PLUGIN_DIR . 'admin/views/calls.php';
	}

	/**
	 * CallRail submenu — verified call records from the CallRail v3 API.
	 *
	 * @return void
	 */
	public function render_callrail() {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$from = isset( $_GET['from'] ) ? preg_replace( '/[^0-9\-]/', '', wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? preg_replace( '/[^0-9\-]/', '', wp_unslash( $_GET['to'] ) ) : '';

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$from = gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$to = gmdate( 'Y-m-d' );
		}

		$filters = array(
			'from_date'       => $from,
			'to_date'         => $to,
			'tracking_number' => isset( $_GET['tracking_number'] ) ? sanitize_text_field( wp_unslash( $_GET['tracking_number'] ) ) : '',
			'search'          => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);

		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result            = RTP_CallRail_Sync::get_calls( $filters, $paged, 25 );
		$tracking_numbers  = RTP_CallRail_Sync::get_distinct_tracking_numbers();
		$last_sync_at      = RTP_CallRail_Sync::get_last_sync_time();
		$last_sync_result  = RTP_CallRail_Sync::get_last_sync_result();
		$configured        = '' !== RTP_CallRail_Sync::get_api_key() && '' !== RTP_CallRail_Sync::get_account_id();

		require RTP_PLUGIN_DIR . 'admin/views/callrail.php';
	}

	/**
	 * Single-lead detail (print-friendly, used for "View" action and PDF).
	 *
	 * @return void
	 */
	public function render_lead_detail() {
		$this->guard();

		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event = $id ? RTP_Analytics::get_event_by_id( $id ) : null;

		if ( ! $event ) {
			wp_die( esc_html__( 'Lead not found.', 'referral-tracker-pro' ) );
		}

		// Parse the extra JSON of submitted fields, if any.
		$fields = array();
		if ( ! empty( $event['extra'] ) ) {
			$decoded = json_decode( $event['extra'], true );
			if ( is_array( $decoded ) && ! empty( $decoded['fields'] ) && is_array( $decoded['fields'] ) ) {
				$fields = $decoded['fields'];
			}
		}

		require RTP_PLUGIN_DIR . 'admin/views/lead-detail.php';
	}

	/**
	 * Per-referral detail screen.
	 *
	 * @return void
	 */
	public function render_referral_detail() {
		$this->guard();

		$campaign_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$campaign    = RTP_Database::get_campaign( $campaign_id );

		if ( ! $campaign ) {
			wp_die( esc_html__( 'Referral not found.', 'referral-tracker-pro' ) );
		}

		$summary  = RTP_Analytics::get_campaign_summary( $campaign_id );
		$sessions = RTP_Analytics::get_campaign_sessions( $campaign_id, 50 );
		$timeline = RTP_Analytics::get_campaign_timeline( $campaign_id, 100 );

		require RTP_PLUGIN_DIR . 'admin/views/referral-detail.php';
	}

	/**
	 * Settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		$this->guard();

		$settings = RTP_Helpers::get_settings();
		$saved    = isset( $_GET['updated'] ) && '1' === $_GET['updated']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		require RTP_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/* ---------------------------------------------------------------------
	 * POST handlers (admin-post.php)
	 * ------------------------------------------------------------------- */

	/**
	 * Creates or updates a referral campaign.
	 *
	 * @return void
	 */
	public function handle_save_campaign() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'referral-tracker-pro' ) );
		}
		check_admin_referer( 'rtp_save_campaign' );

		$id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$code = isset( $_POST['code'] ) ? RTP_Helpers::sanitize_code( wp_unslash( $_POST['code'] ) ) : '';
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'general';
		$type = in_array( $type, array( 'general', 'page' ), true ) ? $type : 'general';

		$target = isset( $_POST['target_url'] ) ? RTP_Helpers::sanitize_url_field( wp_unslash( $_POST['target_url'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'active';
		$status = in_array( $status, array( 'active', 'inactive' ), true ) ? $status : 'active';
		$notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		$redirect = admin_url( 'admin.php?page=rtp-campaigns' );

		if ( '' === $name || '' === $code ) {
			wp_safe_redirect( add_query_arg( 'rtp_msg', 'missing', $redirect ) );
			exit;
		}

		if ( RTP_Database::code_exists( $code, $id ) ) {
			wp_safe_redirect( add_query_arg( 'rtp_msg', 'dupe', $redirect ) );
			exit;
		}

		$result = RTP_Database::save_campaign(
			array(
				'name'       => $name,
				'code'       => $code,
				'type'       => $type,
				'target_url' => $target,
				'status'     => $status,
				'notes'      => $notes,
			),
			$id
		);

		$msg = ( false === $result ) ? 'error' : 'saved';
		wp_safe_redirect( add_query_arg( 'rtp_msg', $msg, $redirect ) );
		exit;
	}

	/**
	 * Deletes a campaign definition.
	 *
	 * @return void
	 */
	public function handle_delete_campaign() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'referral-tracker-pro' ) );
		}
		check_admin_referer( 'rtp_delete_campaign' );

		$id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		if ( $id ) {
			RTP_Database::delete_campaign( $id );
		}

		wp_safe_redirect( add_query_arg( 'rtp_msg', 'deleted', admin_url( 'admin.php?page=rtp-campaigns' ) ) );
		exit;
	}

	/**
	 * Persists the settings form.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'referral-tracker-pro' ) );
		}
		check_admin_referer( 'rtp_save_settings' );

		$raw = isset( $_POST['rtp_settings'] ) && is_array( $_POST['rtp_settings'] )
			? wp_unslash( $_POST['rtp_settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		RTP_Settings::save( $raw );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=rtp-settings' ) ) );
		exit;
	}

	/**
	 * Streams a CSV export of the filtered events.
	 *
	 * @return void
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'referral-tracker-pro' ) );
		}
		check_admin_referer( 'rtp_export_csv' );

		$filters = RTP_Analytics::normalize_filters(
			array(
				'from'        => isset( $_GET['from'] ) ? wp_unslash( $_GET['from'] ) : '',
				'to'          => isset( $_GET['to'] ) ? wp_unslash( $_GET['to'] ) : '',
				'campaign_id' => isset( $_GET['campaign_id'] ) ? wp_unslash( $_GET['campaign_id'] ) : 0,
			)
		);

		$rows = RTP_Analytics::get_export_rows( $filters );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=referral-events-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array( 'Date', 'Referral', 'Code', 'Session', 'Action', 'Landing Page', 'Action Page', 'Phone', 'Form', 'Form Type', 'Lead Name', 'Lead Email', 'Lead Phone', 'Lead Amount', 'Device', 'Browser', 'OS' )
		);

		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array(
					mysql2date( 'Y-m-d H:i', $r['created_at'] ),
					$r['campaign_name'],
					$r['referral_code'],
					$r['session_id'],
					$r['event_type'],
					$r['landing_page'],
					$r['event_page'],
					$r['phone_number'],
					$r['form_name'],
					$r['form_type'],
					$r['lead_name'],
					$r['lead_email'],
					$r['lead_phone'],
					null === $r['lead_amount'] ? '' : $r['lead_amount'],
					$r['device'],
					$r['browser'],
					$r['os'],
				)
			);
		}

		fclose( $out );
		exit;
	}

	/**
	 * Streams a lead-focused CSV (form submissions only, with contact details).
	 *
	 * @return void
	 */
	public function handle_export_leads() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'referral-tracker-pro' ) );
		}
		check_admin_referer( 'rtp_export_leads' );

		$filters = RTP_Analytics::normalize_filters(
			array(
				'from'        => isset( $_GET['from'] ) ? wp_unslash( $_GET['from'] ) : '',
				'to'          => isset( $_GET['to'] ) ? wp_unslash( $_GET['to'] ) : '',
				'campaign_id' => isset( $_GET['campaign_id'] ) ? wp_unslash( $_GET['campaign_id'] ) : 0,
			)
		);
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$rows = RTP_Analytics::get_leads_export( $filters, $search );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=referral-leads-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array( 'Date', 'Referral', 'Code', 'Name', 'Email', 'Phone', 'Amount', 'Page', 'Form' )
		);

		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array(
					mysql2date( 'Y-m-d H:i', $r['created_at'] ),
					$r['campaign_name'],
					$r['referral_code'],
					$r['lead_name'],
					$r['lead_email'],
					$r['lead_phone'],
					null === $r['lead_amount'] ? '' : $r['lead_amount'],
					$r['event_page'],
					$r['form_name'] ? $r['form_name'] : $r['form_id'],
				)
			);
		}

		fclose( $out );
		exit;
	}

	/**
	 * Streams a CSV of CallRail call events (caller number + duration +
	 * recording link) for the current filter set.
	 *
	 * @return void
	 */
	public function handle_export_calls() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'referral-tracker-pro' ) );
		}
		check_admin_referer( 'rtp_export_calls' );

		$filters = RTP_Analytics::normalize_filters(
			array(
				'from'        => isset( $_GET['from'] ) ? wp_unslash( $_GET['from'] ) : '',
				'to'          => isset( $_GET['to'] ) ? wp_unslash( $_GET['to'] ) : '',
				'campaign_id' => isset( $_GET['campaign_id'] ) ? wp_unslash( $_GET['campaign_id'] ) : 0,
			)
		);
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$rows = RTP_Analytics::get_calls_export( $filters, $search );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=referral-calls-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array( 'Date', 'Referral', 'Code', 'Caller Name', 'Caller Phone', 'Dialed Tracking #', 'Duration (s)', 'Source', 'Landing Page', 'Recording URL' )
		);

		foreach ( $rows as $r ) {
			$cr = class_exists( 'RTP_CallRail' ) ? RTP_CallRail::decode_extra( isset( $r['extra'] ) ? $r['extra'] : '' ) : array();
			fputcsv(
				$out,
				array(
					mysql2date( 'Y-m-d H:i', $r['created_at'] ),
					$r['campaign_name'],
					$r['referral_code'],
					$r['lead_name'],
					$r['lead_phone'],
					$r['phone_number'],
					isset( $cr['duration'] ) ? (int) $cr['duration'] : 0,
					isset( $cr['source'] ) ? $cr['source'] : '',
					$r['event_page'],
					isset( $cr['recording_url'] ) ? $cr['recording_url'] : '',
				)
			);
		}

		fclose( $out );
		exit;
	}

	/**
	 * Handles the manual "Sync CallRail Calls" button click.
	 *
	 * @return void
	 */
	public function handle_callrail_sync() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'referral-tracker-pro' ) );
		}
		check_admin_referer( 'rtp_callrail_sync' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$from            = isset( $_GET['from'] ) ? preg_replace( '/[^0-9\-]/', '', wp_unslash( $_GET['from'] ) ) : '';
		$to              = isset( $_GET['to'] ) ? preg_replace( '/[^0-9\-]/', '', wp_unslash( $_GET['to'] ) ) : '';
		$tracking_number = isset( $_GET['tracking_number'] ) ? sanitize_text_field( wp_unslash( $_GET['tracking_number'] ) ) : '';
		$search          = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$from = gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$to = gmdate( 'Y-m-d' );
		}

		RTP_CallRail_Sync::sync_calls(
			array(
				'from_date'       => $from,
				'to_date'         => $to,
				'tracking_number' => $tracking_number,
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'rtp-callrail',
					'from'            => $from,
					'to'              => $to,
					'tracking_number' => $tracking_number,
					's'               => $search,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Collects (read-only) filter params from the request.
	 *
	 * Filters are non-mutating GET params used purely to scope reports,
	 * so a nonce is intentionally not required here.
	 *
	 * @return array
	 */
	private function get_request_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		return array(
			'from'        => isset( $_GET['from'] ) ? wp_unslash( $_GET['from'] ) : '',
			'to'          => isset( $_GET['to'] ) ? wp_unslash( $_GET['to'] ) : '',
			'campaign_id' => isset( $_GET['campaign_id'] ) ? wp_unslash( $_GET['campaign_id'] ) : 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
