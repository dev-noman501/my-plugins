<?php
/**
 * Admin: menus, list page, multi-step add/edit form, settings page, action handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once TGMV_DIR . 'includes/class-tgmv-list-table.php';

class TGMV_Admin {

	const CAP = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_tgmv_save_voucher', array( __CLASS__, 'handle_save_voucher' ) );
		add_action( 'admin_post_tgmv_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_tgmv_duplicate', array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'admin_post_tgmv_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_tgmv_toggle_status', array( __CLASS__, 'handle_toggle_status' ) );
		add_action( 'wp_ajax_tgmv_autosave', array( __CLASS__, 'handle_autosave' ) );
	}

	public static function menu() {
		add_menu_page( 'Vouchers', 'Vouchers', self::CAP, 'tgmv-list', array( __CLASS__, 'render_list' ), 'dashicons-tickets-alt', 26 );
		add_submenu_page( 'tgmv-list', 'All Vouchers', 'All Vouchers', self::CAP, 'tgmv-list', array( __CLASS__, 'render_list' ) );
		add_submenu_page( 'tgmv-list', 'Add New Voucher', 'Add New', self::CAP, 'tgmv-add', array( __CLASS__, 'render_form' ) );
		add_submenu_page( 'tgmv-list', 'Voucher Settings', 'Settings', self::CAP, 'tgmv-settings', array( __CLASS__, 'render_settings' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'tgmv-' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'tgmv-admin', TGMV_URL . 'assets/admin.css', array(), TGMV_VERSION );
		wp_enqueue_script( 'tgmv-admin', TGMV_URL . 'assets/admin.js', array( 'jquery' ), TGMV_VERSION, true );
	}

	/* ---------------------------------------------------------------- */
	/* Handlers                                                          */
	/* ---------------------------------------------------------------- */

	public static function handle_save_voucher() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'tgmv_save_voucher' );

		$post_id = isset( $_POST['voucher_id'] ) ? absint( $_POST['voucher_id'] ) : 0;
		$data    = TGMV_Data::sanitize( $_POST );
		$result  = TGMV_Data::save( $data, $post_id );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=tgmv-add&voucher_id=' . $result . '&saved=1' ) );
		exit;
	}

	/**
	 * AJAX autosave — fired when the user moves between form steps.
	 */
	public static function handle_autosave() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ) );
		}
		check_ajax_referer( 'tgmv_save_voucher' );

		$post_id = isset( $_POST['voucher_id'] ) ? absint( $_POST['voucher_id'] ) : 0;
		$data    = TGMV_Data::sanitize( $_POST );
		$result  = TGMV_Data::save( $data, $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'voucher_id' => $result,
				'voucher_no' => get_post_meta( $result, '_tgmv_voucher_no', true ),
				'view_url'   => TGMV_Data::public_url( $result ),
				'edit_url'   => admin_url( 'admin.php?page=tgmv-add&voucher_id=' . $result ),
			)
		);
	}

	public static function handle_save_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'tgmv_save_settings' );

		update_option( 'tgmv_settings', TGMV_Settings::sanitize( $_POST ) );

		if ( isset( $_POST['next_number'] ) && absint( $_POST['next_number'] ) > 0 ) {
			update_option( 'tgmv_next_number', absint( $_POST['next_number'] ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=tgmv-settings&saved=1' ) );
		exit;
	}

	public static function handle_duplicate() {
		$post_id = isset( $_GET['voucher_id'] ) ? absint( $_GET['voucher_id'] ) : 0;
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'tgmv_duplicate_' . $post_id );

		$new_id = TGMV_Data::duplicate( $post_id );
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=tgmv-add&voucher_id=' . $new_id . '&saved=1' ) );
		exit;
	}

	public static function handle_delete() {
		$post_id = isset( $_GET['voucher_id'] ) ? absint( $_GET['voucher_id'] ) : 0;
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'tgmv_delete_' . $post_id );

		if ( 'tgm_voucher' === get_post_type( $post_id ) ) {
			wp_delete_post( $post_id, true );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=tgmv-list&deleted=1' ) );
		exit;
	}

	public static function handle_toggle_status() {
		$post_id = isset( $_GET['voucher_id'] ) ? absint( $_GET['voucher_id'] ) : 0;
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'tgmv_toggle_' . $post_id );

		$data           = TGMV_Data::load( $post_id );
		$data['status'] = ( 'approved' === $data['status'] ) ? 'unapproved' : 'approved';
		TGMV_Data::save( $data, $post_id );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=tgmv-list' ) );
		exit;
	}

	/* ---------------------------------------------------------------- */
	/* List page                                                         */
	/* ---------------------------------------------------------------- */

	public static function render_list() {
		$table = new TGMV_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap tgmv-wrap">
			<h1 class="wp-heading-inline">Vouchers</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=tgmv-add' ) ); ?>" class="page-title-action">Add New</a>
			<hr class="wp-header-end">
			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Voucher deleted.</p></div>
			<?php endif; ?>
			<form method="get">
				<input type="hidden" name="page" value="tgmv-list">
				<?php
				$table->search_box( 'Search voucher # / name', 'tgmv' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/* Add / Edit form                                                   */
	/* ---------------------------------------------------------------- */

	private static function datalist( $id, $key, $extra = array() ) {
		$values = array_unique( array_merge( $extra, TGMV_Data::suggestions( $key ) ) );
		echo '<datalist id="' . esc_attr( $id ) . '">';
		foreach ( $values as $v ) {
			echo '<option value="' . esc_attr( $v ) . '"></option>';
		}
		echo '</datalist>';
	}

	public static function render_form() {
		$post_id = isset( $_GET['voucher_id'] ) ? absint( $_GET['voucher_id'] ) : 0;
		$is_edit = $post_id && 'tgm_voucher' === get_post_type( $post_id );
		$data    = $is_edit ? TGMV_Data::load( $post_id ) : TGMV_Data::blank();
		$settings = TGMV_Settings::get();
		$view_url = $is_edit ? TGMV_Data::public_url( $post_id ) : '';
		?>
		<div class="wrap tgmv-wrap">
			<h1 class="wp-heading-inline">
				<?php echo $is_edit ? 'Edit Voucher — ' . esc_html( $data['voucher_no'] ) : 'Add New Voucher'; ?>
			</h1>
			<?php if ( $is_edit ) : ?>
				<a href="<?php echo esc_url( $view_url ); ?>" target="_blank" class="page-title-action">View Voucher</a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php if ( isset( $_GET['saved'] ) && $is_edit ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						Voucher saved. Public link:
						<a href="<?php echo esc_url( $view_url ); ?>" target="_blank"><?php echo esc_html( $view_url ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tgmv-form">
				<?php wp_nonce_field( 'tgmv_save_voucher' ); ?>
				<input type="hidden" name="action" value="tgmv_save_voucher">
				<input type="hidden" name="voucher_id" value="<?php echo esc_attr( $post_id ); ?>">

				<div class="tgmv-steps-nav">
					<button type="button" class="tgmv-step-btn active" data-step="1">1. Basic Info</button>
					<button type="button" class="tgmv-step-btn" data-step="2">2. Agency &amp; Receiver</button>
					<button type="button" class="tgmv-step-btn" data-step="3">3. Mutamers</button>
					<button type="button" class="tgmv-step-btn" data-step="4">4. Accommodation</button>
					<button type="button" class="tgmv-step-btn" data-step="5">5. Transport &amp; Flights</button>
					<button type="button" class="tgmv-step-btn" data-step="6">6. Status &amp; Save</button>
				</div>

				<!-- STEP 1: Basic -->
				<div class="tgmv-step active" data-step="1">
					<h2>Basic Info</h2>
					<div class="tgmv-grid">
						<p>
							<label>Voucher Date</label>
							<input type="date" name="voucher_date" value="<?php echo esc_attr( $data['voucher_date'] ); ?>">
						</p>
						<p>
							<label>Package</label>
							<input type="text" name="package" list="tgmv-dl-package" placeholder="e.g. 20 VIP" value="<?php echo esc_attr( $data['package'] ); ?>">
							<?php self::datalist( 'tgmv-dl-package', 'package' ); ?>
						</p>
						<p>
							<label>Family Head</label>
							<input type="text" name="family_head" placeholder="e.g. AYAAN RAFIQ" value="<?php echo esc_attr( $data['family_head'] ); ?>">
						</p>
						<p>
							<label>Manual No</label>
							<input type="text" name="manual_no" placeholder="e.g. TGM" value="<?php echo esc_attr( $data['manual_no'] ); ?>">
						</p>
						<p>
							<label>Adults (auto)</label>
							<input type="number" name="pax_adult" readonly class="tgmv-pax tgmv-readonly" value="<?php echo esc_attr( $data['pax_adult'] ); ?>">
						</p>
						<p>
							<label>Children (auto)</label>
							<input type="number" name="pax_child" readonly class="tgmv-pax tgmv-readonly" value="<?php echo esc_attr( $data['pax_child'] ); ?>">
						</p>
						<p>
							<label>Infants (auto)</label>
							<input type="number" name="pax_infant" readonly class="tgmv-pax tgmv-readonly" value="<?php echo esc_attr( $data['pax_infant'] ); ?>">
						</p>
						<p>
							<label>Beds (auto)</label>
							<input type="number" name="beds" readonly class="tgmv-pax tgmv-readonly" value="<?php echo esc_attr( $data['beds'] ); ?>">
						</p>
					</div>
					<p class="description">PAX line preview: <strong id="tgmv-pax-preview"></strong> — these counts are calculated automatically from Step 3 (Mutamers).</p>
				</div>

				<!-- STEP 2: Agency & Receiver -->
				<div class="tgmv-step" data-step="2">
					<h2>Agency (left block on voucher) — optional</h2>
					<p class="description">Your own logo and brand name always appear in the <strong>center</strong> of the voucher. Fill this block only when another travel agency's name/logo should appear on the left side.</p>
					<div class="tgmv-grid">
						<p>
							<label>Agency Name</label>
							<input type="text" name="agency_name" list="tgmv-dl-agency" placeholder="e.g. partner agency name" value="<?php echo esc_attr( $data['agency_name'] ); ?>">
							<?php self::datalist( 'tgmv-dl-agency', 'agency_name' ); ?>
						</p>
						<p>
							<label>Agency Logo</label>
							<input type="hidden" name="agency_logo" id="tgmv-agency-logo" value="<?php echo esc_attr( $data['agency_logo'] ); ?>">
							<button type="button" class="button tgmv-media" data-target="tgmv-agency-logo">Choose Logo</button>
							<button type="button" class="button tgmv-media-clear" data-target="tgmv-agency-logo">Remove</button><br>
							<img id="tgmv-agency-logo-preview" class="tgmv-logo-preview" src="<?php echo esc_url( $data['agency_logo'] ); ?>" alt="" <?php echo $data['agency_logo'] ? '' : 'style="display:none;"'; ?>>
						</p>
					</div>
					<h2>Receiver / Arkan (right block on voucher)</h2>
					<div class="tgmv-grid">
						<p>
							<label>Name</label>
							<input type="text" name="arkan_name" list="tgmv-dl-arkan" placeholder="e.g. ARKAN" value="<?php echo esc_attr( $data['arkan_name'] ); ?>">
							<?php self::datalist( 'tgmv-dl-arkan', 'arkan_name' ); ?>
						</p>
						<p>
							<label>Reference / Sub-line</label>
							<input type="text" name="arkan_ref" placeholder="e.g. ABC" value="<?php echo esc_attr( $data['arkan_ref'] ); ?>">
						</p>
						<p>
							<label>City / Location</label>
							<input type="text" name="arkan_city" list="tgmv-dl-city" placeholder="e.g. Islamabad" value="<?php echo esc_attr( $data['arkan_city'] ); ?>">
							<?php self::datalist( 'tgmv-dl-city', 'arkan_city' ); ?>
						</p>
						<p>
							<label>WhatsApp</label>
							<input type="text" name="arkan_whatsapp" placeholder="e.g. +92 300 1234567" value="<?php echo esc_attr( $data['arkan_whatsapp'] ); ?>">
						</p>
					</div>
				</div>

				<!-- STEP 3: Mutamers -->
				<div class="tgmv-step" data-step="3">
					<h2>Mutamers</h2>
					<table class="widefat tgmv-repeater" id="tgmv-mutamers">
						<thead>
							<tr>
								<th>#</th><th>Passport</th><th>Name</th><th>Gender</th><th>PAX</th><th>Bed</th>
								<th>MOFA</th><th>GRP #</th><th>Visa #</th><th>PNR</th><th></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$rows = $data['mutamers'] ? $data['mutamers'] : array( array() );
							foreach ( $rows as $row ) :
								$row = array_merge( array( 'passport' => '', 'name' => '', 'gender' => '', 'pax' => '', 'bed' => '', 'mofa' => '', 'grp' => '', 'visa' => '', 'pnr' => '' ), $row );
								?>
							<tr>
								<td class="tgmv-sno"></td>
								<td><input type="text" name="mutamers[passport][]" value="<?php echo esc_attr( $row['passport'] ); ?>"></td>
								<td><input type="text" name="mutamers[name][]" value="<?php echo esc_attr( $row['name'] ); ?>"></td>
								<td>
									<select name="mutamers[gender][]">
										<option value=""></option>
										<option value="M" <?php selected( $row['gender'], 'M' ); ?>>M</option>
										<option value="F" <?php selected( $row['gender'], 'F' ); ?>>F</option>
									</select>
								</td>
								<td>
									<select name="mutamers[pax][]">
										<option value=""></option>
										<option value="Adult" <?php selected( $row['pax'], 'Adult' ); ?>>Adult</option>
										<option value="Child" <?php selected( $row['pax'], 'Child' ); ?>>Child</option>
										<option value="Infant" <?php selected( $row['pax'], 'Infant' ); ?>>Infant</option>
									</select>
								</td>
								<td>
									<select name="mutamers[bed][]">
										<option value=""></option>
										<option value="Yes" <?php selected( $row['bed'], 'Yes' ); ?>>Yes</option>
										<option value="No" <?php selected( $row['bed'], 'No' ); ?>>No</option>
									</select>
								</td>
								<td><input type="text" name="mutamers[mofa][]" class="tgmv-narrow" list="tgmv-dl-yesno" value="<?php echo esc_attr( $row['mofa'] ); ?>"></td>
								<td><input type="text" name="mutamers[grp][]" value="<?php echo esc_attr( $row['grp'] ); ?>"></td>
								<td><input type="text" name="mutamers[visa][]" value="<?php echo esc_attr( $row['visa'] ); ?>"></td>
								<td><input type="text" name="mutamers[pnr][]" class="tgmv-narrow" value="<?php echo esc_attr( $row['pnr'] ); ?>"></td>
								<td><button type="button" class="button-link-delete tgmv-remove-row">&times;</button></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<datalist id="tgmv-dl-yesno"><option value="Yes"></option><option value="No"></option></datalist>
					<p><button type="button" class="button tgmv-add-row" data-repeater="tgmv-mutamers">+ Add Mutamer</button>
					<span class="description">Copy the first mutamer's GRP # to all rows: <button type="button" class="button-link" id="tgmv-copy-grp">Copy GRP to all</button></span></p>
				</div>

				<!-- STEP 4: Accommodation -->
				<div class="tgmv-step" data-step="4">
					<h2>Accommodation</h2>
					<table class="widefat tgmv-repeater" id="tgmv-hotels">
						<thead>
							<tr>
								<th>City</th><th>Hotel Name</th><th>View</th><th>Meal</th><th>Conf#</th>
								<th>Room Type</th><th>Checkin</th><th>Checkout</th><th>Nights</th><th></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$rows = $data['hotels'] ? $data['hotels'] : array( array() );
							foreach ( $rows as $row ) :
								$row = array_merge( array( 'city' => '', 'hotel' => '', 'view' => '', 'meal' => '', 'conf' => '', 'room_type' => '', 'checkin' => '', 'checkout' => '', 'nights' => '' ), $row );
								?>
							<tr>
								<td><input type="text" name="hotels[city][]" class="tgmv-narrow" list="tgmv-dl-hcity" value="<?php echo esc_attr( $row['city'] ); ?>"></td>
								<td><input type="text" name="hotels[hotel][]" list="tgmv-dl-hotel" value="<?php echo esc_attr( $row['hotel'] ); ?>"></td>
								<td><input type="text" name="hotels[view][]" class="tgmv-narrow" list="tgmv-dl-view" value="<?php echo esc_attr( $row['view'] ); ?>"></td>
								<td><input type="text" name="hotels[meal][]" class="tgmv-narrow" list="tgmv-dl-meal" value="<?php echo esc_attr( $row['meal'] ); ?>"></td>
								<td><input type="text" name="hotels[conf][]" class="tgmv-narrow" value="<?php echo esc_attr( $row['conf'] ); ?>"></td>
								<td><input type="text" name="hotels[room_type][]" list="tgmv-dl-room" value="<?php echo esc_attr( $row['room_type'] ); ?>"></td>
								<td><input type="date" name="hotels[checkin][]" class="tgmv-checkin" value="<?php echo esc_attr( $row['checkin'] ); ?>"></td>
								<td><input type="date" name="hotels[checkout][]" class="tgmv-checkout" value="<?php echo esc_attr( $row['checkout'] ); ?>"></td>
								<td><input type="number" name="hotels[nights][]" class="tgmv-nights tgmv-narrow" min="0" value="<?php echo esc_attr( $row['nights'] ); ?>"></td>
								<td><button type="button" class="button-link-delete tgmv-remove-row">&times;</button></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php
					self::datalist( 'tgmv-dl-hcity', 'hotel_city', array( 'Makkah', 'Medinah' ) );
					self::datalist( 'tgmv-dl-hotel', 'hotel' );
					self::datalist( 'tgmv-dl-view', 'view', array( 'Standard', 'Haram View' ) );
					self::datalist( 'tgmv-dl-meal', 'meal', array( 'RO', 'BB', 'HB', 'FB' ) );
					self::datalist( 'tgmv-dl-room', 'room_type', array( 'Sharing (Gender)', 'Double', 'Triple', 'Quad', 'Quint' ) );
					?>
					<p>
						<button type="button" class="button tgmv-add-row" data-repeater="tgmv-hotels">+ Add Hotel</button>
						<span class="description">Total Nights: <strong id="tgmv-total-nights">0</strong> (nights are calculated automatically from checkin/checkout dates)</span>
					</p>
				</div>

				<!-- STEP 5: Transport & Flights -->
				<div class="tgmv-step" data-step="5">
					<h2>Transport / Services</h2>
					<table class="widefat tgmv-repeater" id="tgmv-transport">
						<thead><tr><th>Travel Date</th><th>Transporter</th><th>Type</th><th>Description</th><th></th></tr></thead>
						<tbody>
							<?php
							$rows = $data['transport'] ? $data['transport'] : array( array() );
							foreach ( $rows as $row ) :
								$row = array_merge( array( 'travel_date' => '', 'transporter' => '', 'type' => '', 'description' => '' ), $row );
								?>
							<tr>
								<td><input type="date" name="transport[travel_date][]" value="<?php echo esc_attr( $row['travel_date'] ); ?>"></td>
								<td><input type="text" name="transport[transporter][]" list="tgmv-dl-transporter" value="<?php echo esc_attr( $row['transporter'] ); ?>"></td>
								<td><input type="text" name="transport[type][]" list="tgmv-dl-ttype" value="<?php echo esc_attr( $row['type'] ); ?>"></td>
								<td><input type="text" name="transport[description][]" list="tgmv-dl-tdesc" value="<?php echo esc_attr( $row['description'] ); ?>"></td>
								<td><button type="button" class="button-link-delete tgmv-remove-row">&times;</button></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php
					self::datalist( 'tgmv-dl-transporter', 'transporter', array( 'Company Transport' ) );
					self::datalist( 'tgmv-dl-ttype', 'transport_type', array( 'Private Hi-Ace', 'Coaster', 'Bus', 'GMC' ) );
					self::datalist( 'tgmv-dl-tdesc', 'transport_desc', array( 'Jed-Mak', 'Mak-Med', 'Med-Jed', 'Jed-Mak-Med-Jed' ) );
					?>
					<p><button type="button" class="button tgmv-add-row" data-repeater="tgmv-transport">+ Add Transport</button></p>

					<h2>Departure (Pakistan to KSA)</h2>
					<table class="widefat tgmv-repeater" id="tgmv-dep">
						<thead><tr><th>Flight</th><th>Sector</th><th>Departure</th><th>Arrival</th><th></th></tr></thead>
						<tbody>
							<?php
							$rows = $data['dep_flights'] ? $data['dep_flights'] : array( array() );
							foreach ( $rows as $row ) :
								$row = array_merge( array( 'flight' => '', 'sector' => '', 'departure' => '', 'arrival' => '' ), $row );
								?>
							<tr>
								<td><input type="text" name="dep_flights[flight][]" list="tgmv-dl-flight" placeholder="SV-801" value="<?php echo esc_attr( $row['flight'] ); ?>"></td>
								<td><input type="text" name="dep_flights[sector][]" list="tgmv-dl-sector" placeholder="MUX-JED" value="<?php echo esc_attr( $row['sector'] ); ?>"></td>
								<td><input type="datetime-local" name="dep_flights[departure][]" value="<?php echo esc_attr( $row['departure'] ); ?>"></td>
								<td><input type="datetime-local" name="dep_flights[arrival][]" value="<?php echo esc_attr( $row['arrival'] ); ?>"></td>
								<td><button type="button" class="button-link-delete tgmv-remove-row">&times;</button></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p><button type="button" class="button tgmv-add-row" data-repeater="tgmv-dep">+ Add Flight</button></p>

					<h2>Arrival (KSA to PAK)</h2>
					<table class="widefat tgmv-repeater" id="tgmv-arr">
						<thead><tr><th>Flight</th><th>Sector</th><th>Departure</th><th>Arrival</th><th></th></tr></thead>
						<tbody>
							<?php
							$rows = $data['arr_flights'] ? $data['arr_flights'] : array( array() );
							foreach ( $rows as $row ) :
								$row = array_merge( array( 'flight' => '', 'sector' => '', 'departure' => '', 'arrival' => '' ), $row );
								?>
							<tr>
								<td><input type="text" name="arr_flights[flight][]" list="tgmv-dl-flight" placeholder="SV-800" value="<?php echo esc_attr( $row['flight'] ); ?>"></td>
								<td><input type="text" name="arr_flights[sector][]" list="tgmv-dl-sector" placeholder="JED-MUX" value="<?php echo esc_attr( $row['sector'] ); ?>"></td>
								<td><input type="datetime-local" name="arr_flights[departure][]" value="<?php echo esc_attr( $row['departure'] ); ?>"></td>
								<td><input type="datetime-local" name="arr_flights[arrival][]" value="<?php echo esc_attr( $row['arrival'] ); ?>"></td>
								<td><button type="button" class="button-link-delete tgmv-remove-row">&times;</button></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php
					self::datalist( 'tgmv-dl-flight', 'flight' );
					self::datalist( 'tgmv-dl-sector', 'sector', array( 'MUX-JED', 'JED-MUX', 'LHE-JED', 'JED-LHE', 'ISB-JED', 'JED-ISB', 'KHI-JED', 'JED-KHI', 'MUX-MED', 'MED-MUX' ) );
					?>
					<p><button type="button" class="button tgmv-add-row" data-repeater="tgmv-arr">+ Add Flight</button></p>
				</div>

				<!-- STEP 6: Status & Save -->
				<div class="tgmv-step" data-step="6">
					<h2>Status &amp; Special Instructions</h2>
					<div class="tgmv-grid">
						<p>
							<label>Voucher Status</label>
							<select name="status" id="tgmv-status">
								<option value="unapproved" <?php selected( $data['status'], 'unapproved' ); ?>>Unapproved (red watermark)</option>
								<option value="approved" <?php selected( $data['status'], 'approved' ); ?>>Approved (green watermark)</option>
							</select>
						</p>
					</div>
					<p>
						<label>Special Instructions</label><br>
						<textarea name="instructions" rows="4" class="large-text"><?php echo esc_textarea( $data['instructions'] ); ?></textarea>
					</p>
					<?php if ( $is_edit ) : ?>
						<p class="description">Public link (the QR code opens this): <a href="<?php echo esc_url( $view_url ); ?>" target="_blank"><?php echo esc_html( $view_url ); ?></a></p>
					<?php endif; ?>
				</div>

				<div class="tgmv-form-footer">
					<button type="button" class="button" id="tgmv-prev">&larr; Back</button>
					<button type="button" class="button button-primary" id="tgmv-next">Next &rarr;</button>
					<span id="tgmv-autosave-status"></span>
					<button type="submit" class="button button-hero button-primary" id="tgmv-save">Save Voucher</button>
				</div>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/* Settings page                                                     */
	/* ---------------------------------------------------------------- */

	public static function render_settings() {
		$s           = TGMV_Settings::get();
		$next_number = (int) get_option( 'tgmv_next_number', 100001 );
		?>
		<div class="wrap tgmv-wrap">
			<h1>Voucher Settings</h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'tgmv_save_settings' ); ?>
				<input type="hidden" name="action" value="tgmv_save_settings">

				<table class="form-table">
					<tr>
						<th><label>Default Brand Name</label></th>
						<td><input type="text" name="brand_name" class="regular-text" value="<?php echo esc_attr( $s['brand_name'] ); ?>"></td>
					</tr>
					<tr>
						<th><label>Default Brand Logo</label></th>
						<td>
							<input type="hidden" name="brand_logo" id="tgmv-brand-logo" value="<?php echo esc_attr( $s['brand_logo'] ); ?>">
							<button type="button" class="button tgmv-media" data-target="tgmv-brand-logo">Choose Logo</button>
							<button type="button" class="button tgmv-media-clear" data-target="tgmv-brand-logo">Use Default (TGM)</button><br>
							<img id="tgmv-brand-logo-preview" class="tgmv-logo-preview" src="<?php echo esc_url( TGMV_Settings::brand_logo_url( $s ) ); ?>" alt="">
						</td>
					</tr>
					<tr>
						<th><label>Center Logo (header middle)</label></th>
						<td>
							<input type="hidden" name="center_logo" id="tgmv-center-logo" value="<?php echo esc_attr( $s['center_logo'] ); ?>">
							<button type="button" class="button tgmv-media" data-target="tgmv-center-logo">Choose Logo</button>
							<button type="button" class="button tgmv-media-clear" data-target="tgmv-center-logo">Remove</button><br>
							<?php if ( $s['center_logo'] ) : ?>
								<img id="tgmv-center-logo-preview" class="tgmv-logo-preview" src="<?php echo esc_url( $s['center_logo'] ); ?>" alt="">
							<?php else : ?>
								<img id="tgmv-center-logo-preview" class="tgmv-logo-preview" src="" alt="" style="display:none;">
							<?php endif; ?>
							<p class="description">Leave empty to show the default brand logo with the brand name in the center.</p>
						</td>
					</tr>
					<tr>
						<th><label>Center Title</label></th>
						<td><input type="text" name="center_title" class="regular-text" value="<?php echo esc_attr( $s['center_title'] ); ?>"></td>
					</tr>
					<tr>
						<th><label>Voucher Number Prefix</label></th>
						<td><input type="text" name="prefix" class="small-text" value="<?php echo esc_attr( $s['prefix'] ); ?>"></td>
					</tr>
					<tr>
						<th><label>Next Voucher Number</label></th>
						<td>
							<input type="number" name="next_number" class="regular-text" value="<?php echo esc_attr( $next_number ); ?>">
							<p class="description">The next voucher will be created with this number, e.g. <?php echo esc_html( $s['prefix'] . $next_number ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label>Show QR Code</label></th>
						<td><label><input type="checkbox" name="show_qr" value="1" <?php checked( $s['show_qr'], 1 ); ?>> Show QR code on the voucher</label></td>
					</tr>
					<tr>
						<th><label>Urdu Terms (page 2)</label></th>
						<td>
							<textarea name="terms_urdu" rows="12" class="large-text" dir="rtl" style="font-family: 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', serif;"><?php echo esc_textarea( $s['terms_urdu'] ); ?></textarea>
							<p class="description">Each line prints as a separate point on page 2 of the voucher.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Settings' ); ?>
			</form>
		</div>
		<?php
	}
}

TGMV_Admin::init();
