<?php
/**
 * Today's Special — the single promo banner on the app's home screen.
 *
 * Modelled on WooCommerce's own "Featured" star: the offer lives on the
 * product, not in a separate settings page. Flag a product from the Products
 * list (Special column) or from its Special Offer tab, and that product becomes
 * the banner. Flagging another one moves it — only ever one at a time.
 *
 * The offer switches itself off, so nobody has to remember: get_offer() reports
 * inactive once the end time passes, or if the product goes out of stock or
 * unpublished, so the banner never sends a customer to a dead end.
 */

defined( 'ABSPATH' ) || exit;

class CPC_Special_Offer {

	const META_FLAG     = '_cpc_is_special';
	const META_HEADLINE = '_cpc_offer_headline';
	const META_PRICE    = '_cpc_offer_price_display';
	const META_SUBTITLE = '_cpc_offer_subtitle';
	const META_IMAGE    = '_cpc_offer_image';
	const META_ENDS     = '_cpc_offer_ends_at';

	/** Pre-product storage; kept only so an existing offer can be migrated once. */
	const LEGACY_OPTION = 'cpc_special_offer';

	public static function init() {
		// On `init`, not `admin_init`: the app reads the offer over REST and may
		// never load wp-admin, so an admin-only migration would leave the live
		// offer invisible until someone happened to open the dashboard.
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 20 );

		// Products list: a tag toggle sitting right next to Woo's featured star.
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 15 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'admin_post_cpc_toggle_special', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'admin_head-edit.php', array( __CLASS__, 'column_styles' ) );

		// Product edit screen: its own tab for the banner wording.
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product' ) );
	}

	/* ---------- Reading the offer ---------- */

	/** The product currently flagged as the special, or 0. */
	public static function get_special_id() {
		$ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => self::META_FLAG,
			'meta_value'     => 'yes',
		) );
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Flag one product as the special and unflag every other, so the "one at a
	 * time" rule holds no matter which screen made the change.
	 */
	public static function set_special( $product_id ) {
		$product_id = (int) $product_id;

		foreach ( get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::META_FLAG,
			'meta_value'     => 'yes',
		) ) as $other ) {
			if ( (int) $other !== $product_id ) {
				delete_post_meta( $other, self::META_FLAG );
			}
		}

		if ( $product_id ) {
			update_post_meta( $product_id, self::META_FLAG, 'yes' );
		}
	}

	public static function clear_special() {
		self::set_special( 0 );
	}

	/**
	 * Accept a datetime-local value ('Y-m-d\TH:i') or plain 'Y-m-d H:i';
	 * store the plain form. Anything unparseable becomes "no end".
	 */
	public static function clean_ends_at( $value ) {
		$value = str_replace( 'T', ' ', trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		// Interpret the typed time as STORE time and store it as a plain string.
		// (strtotime would read it as UTC, which shifted the expiry by hours.)
		$dt = date_create_from_format( 'Y-m-d H:i', $value, wp_timezone() );
		return $dt ? $dt->format( 'Y-m-d H:i' ) : '';
	}

	/** Has the end time passed? The stored time is store-local, so compare in the store's timezone. */
	public static function has_expired( $product_id = null ) {
		$product_id = $product_id ? $product_id : self::get_special_id();
		if ( ! $product_id ) {
			return false;
		}
		$ends = get_post_meta( $product_id, self::META_ENDS, true );
		if ( ! $ends ) {
			return false;
		}
		$tz  = wp_timezone();
		$end = date_create_from_format( 'Y-m-d H:i', $ends, $tz ) ?: date_create( $ends, $tz );
		$now = date_create( 'now', $tz );
		return $end && $now >= $end;
	}

	/**
	 * The payload the app's home screen reads. `active` false means "hide the
	 * card" — the app needs no other reason.
	 */
	public static function get_offer() {
		$empty = array(
			'active'            => false,
			'product_id'        => 0,
			'headline'          => '',
			'price_display'     => '',
			'offer_price'       => 0.0,
			'regular_price'     => 0.0,
			'subtitle'          => '',
			'image'             => '',
			'ends_at'           => null,
			'seconds_remaining' => 0,
		);

		$id = self::get_special_id();
		if ( ! $id || self::has_expired( $id ) ) {
			return $empty;
		}

		// A banner pointing at a sold-out or unpublished product is worse than
		// no banner at all.
		$product = wc_get_product( $id );
		if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_in_stock() ) {
			return $empty;
		}

		$headline = get_post_meta( $id, self::META_HEADLINE, true );
		$image    = get_post_meta( $id, self::META_IMAGE, true );
		$ends     = get_post_meta( $id, self::META_ENDS, true );
		$offer    = self::offer_price( $id );
		$regular  = (float) $product->get_regular_price();
		// The price to advertise: the offer price when it's a real discount.
		$show     = ( $offer > 0 && $offer < $regular ) ? $offer : $regular;

		return array(
			'active'            => true,
			'product_id'        => $id,
			'headline'          => $headline ? $headline : $product->get_name(),
			'price_display'     => self::product_price_display( $product, $show ),
			'offer_price'       => $offer > 0 ? round( $offer, 2 ) : 0.0,
			'regular_price'     => round( $regular, 2 ),
			'subtitle'          => (string) get_post_meta( $id, self::META_SUBTITLE, true ),
			'image'             => $image ? $image : self::product_image( $product ),
			'ends_at'           => $ends ? mysql2date( 'c', $ends ) : null,
			'seconds_remaining' => self::seconds_remaining( $id ),
		);
	}

	/**
	 * Word a price exactly as the products endpoint does, so the banner and the
	 * product page never disagree. Pass a price to word the offer price; omit it
	 * to word the product's regular price. The currency symbol arrives
	 * HTML-encoded ("&#36;"), which an app would print literally — decode it.
	 */
	public static function product_price_display( $product, $price = null ) {
		$sold_by = get_post_meta( $product->get_id(), '_cpc_sold_by', true ) ?: 'each';
		$price   = null === $price ? (float) $product->get_regular_price() : (float) $price;
		$symbol  = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );

		return 'weight' === $sold_by
			? $symbol . number_format( $price, 2 ) . ' / lb'
			: $symbol . number_format( $price, 2 ) . ' each';
	}

	/* ---------- Offer price (a real per-unit discount) ---------- */

	/** The numeric offer price stored on a product (per lb / per each), or 0. */
	public static function offer_price( $product_id ) {
		$raw = get_post_meta( $product_id, self::META_PRICE, true );
		return '' === $raw ? 0.0 : (float) $raw;
	}

	/**
	 * The price a product should actually be charged at right now: the offer
	 * price when this product is the LIVE special (flagged, not expired,
	 * published, in stock) and the offer price is a genuine discount; otherwise
	 * the regular price. One rule, used by the product API, cart and checkout,
	 * so a discount can never appear in one place and not another.
	 */
	public static function effective_price( $product ) {
		$regular = (float) $product->get_regular_price();

		if ( self::get_special_id() !== (int) $product->get_id() ) {
			return $regular;
		}
		if ( self::has_expired( $product->get_id() )
			|| 'publish' !== $product->get_status()
			|| ! $product->is_in_stock() ) {
			return $regular;
		}

		$offer = self::offer_price( $product->get_id() );
		return ( $offer > 0 && $offer < $regular ) ? $offer : $regular;
	}

	/** True when a product is being sold at its offer price right now. */
	public static function is_on_offer( $product ) {
		return self::effective_price( $product ) < (float) $product->get_regular_price();
	}

	/** Seconds until the current offer ends (0 when none / already ended). */
	public static function seconds_remaining( $product_id = null ) {
		$product_id = $product_id ? $product_id : self::get_special_id();
		if ( ! $product_id ) {
			return 0;
		}
		$ends = get_post_meta( $product_id, self::META_ENDS, true );
		if ( ! $ends ) {
			return 0; // no end time = runs until switched off
		}
		$tz  = wp_timezone();
		$end = date_create_from_format( 'Y-m-d H:i', $ends, $tz ) ?: date_create( $ends, $tz );
		$now = date_create( 'now', $tz );
		return ( $end && $end > $now ) ? $end->getTimestamp() - $now->getTimestamp() : 0;
	}

	protected static function product_image( $product ) {
		$id = $product->get_image_id();
		return $id ? wp_get_attachment_image_url( $id, 'large' ) : '';
	}

	/** Products the manager panel can choose from. */
	public static function product_choices() {
		$out = array();
		foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'orderby' => 'name', 'order' => 'ASC' ) ) as $p ) {
			$out[ $p->get_id() ] = $p->get_name();
		}
		return $out;
	}

	/**
	 * Write the banner wording onto a product. Used by the manager panel; the
	 * product screen saves through save_product() instead.
	 */
	public static function update_offer( $product_id, $fields ) {
		$product_id = (int) $product_id;
		if ( ! $product_id ) {
			return;
		}
		update_post_meta( $product_id, self::META_HEADLINE, sanitize_text_field( $fields['headline'] ?? '' ) );
		// Offer price is now a real number (per lb / each), not display text.
		$offer_price = trim( (string) ( $fields['price_display'] ?? '' ) );
		update_post_meta( $product_id, self::META_PRICE, '' === $offer_price ? '' : (float) $offer_price );
		update_post_meta( $product_id, self::META_SUBTITLE, sanitize_text_field( $fields['subtitle'] ?? '' ) );
		update_post_meta( $product_id, self::META_IMAGE, esc_url_raw( $fields['image'] ?? '' ) );
		update_post_meta( $product_id, self::META_ENDS, self::clean_ends_at( $fields['ends_at'] ?? '' ) );
	}

	/* ---------- Products list column ---------- */

	public static function add_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			// Sit next to Woo's featured star so the two toggles read as a pair.
			if ( 'featured' === $key ) {
				$new['cpc_special'] = self::column_heading();
			}
		}
		if ( ! isset( $new['cpc_special'] ) ) {
			$new['cpc_special'] = self::column_heading();
		}
		return $new;
	}

	/**
	 * An icon, like Woo's own featured-star heading. The word "Special" wrapped
	 * to one letter per line in the narrow column, so keep the heading to a
	 * single glyph and put the wording in the tooltip.
	 */
	protected static function column_heading() {
		return '<span class="dashicons dashicons-tag" title="' . esc_attr__( "Today's Special", 'casa-prime-core' ) . '"></span>'
			. '<span class="screen-reader-text">' . esc_html__( "Today's Special", 'casa-prime-core' ) . '</span>';
	}

	/**
	 * Give the column a fixed narrow width. Without this it collapses and the
	 * heading spills over the Date column.
	 */
	public static function column_styles() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		echo '<style>
			.wp-list-table .column-cpc_special { width:52px; text-align:center; white-space:nowrap; }
			.wp-list-table .column-cpc_special .dashicons { width:20px; height:20px; font-size:20px; line-height:20px; }
			.wp-list-table th.column-cpc_special .dashicons { color:#787c82; }
			.wp-list-table .column-cpc_special small { display:block; line-height:1.3; }
		</style>';
	}

	public static function render_column( $column, $post_id ) {
		if ( 'cpc_special' !== $column ) {
			return;
		}
		$is_special = self::get_special_id() === (int) $post_id;
		$url = wp_nonce_url(
			add_query_arg( array(
				'action'     => 'cpc_toggle_special',
				'product_id' => $post_id,
				'redirect'   => rawurlencode( ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' ) ),
			), admin_url( 'admin-post.php' ) ),
			'cpc_toggle_special_' . $post_id
		);

		// Dashicons rather than an emoji: emoji render inconsistently across
		// Windows/Mac and looked like a broken glyph in the column header.
		$note = '';
		if ( $is_special ) {
			if ( self::has_expired( $post_id ) ) {
				$note = '<br><small style="color:#b45309;">ended</small>';
			} elseif ( ! self::get_offer()['active'] ) {
				$note = '<br><small style="color:#b45309;">hidden</small>';
			}
		}

		printf(
			'<a href="%s" title="%s" style="text-decoration:none;"><span class="dashicons dashicons-tag" style="color:%s"></span></a>%s',
			esc_url( $url ),
			esc_attr( $is_special ? "Remove today's special" : "Make this today's special" ),
			esc_attr( $is_special ? '#b32d2e' : '#c3c4c7' ),
			wp_kses_post( $note )
		);
	}

	public static function handle_toggle() {
		$product_id = isset( $_GET['product_id'] ) ? (int) $_GET['product_id'] : 0;
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $product_id
			|| ! check_admin_referer( 'cpc_toggle_special_' . $product_id ) ) {
			wp_die( 'Not allowed.' );
		}

		if ( self::get_special_id() === $product_id ) {
			self::clear_special();
		} else {
			self::set_special( $product_id );
		}

		$redirect = isset( $_GET['redirect'] ) ? rawurldecode( wp_unslash( $_GET['redirect'] ) ) : '';
		wp_safe_redirect( $redirect ? $redirect : admin_url( 'edit.php?post_type=product' ) );
		exit;
	}

	/* ---------- Product Data tab ---------- */

	public static function add_tab( $tabs ) {
		$tabs['cpc_special'] = array(
			'label'    => "Today's Special",
			'target'   => 'cpc_special_data',
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	public static function render_panel() {
		global $post;
		$id         = $post->ID;
		$is_special = self::get_special_id() === (int) $id;
		$ends       = get_post_meta( $id, self::META_ENDS, true );
		?>
		<div id="cpc_special_data" class="panel woocommerce_options_panel hidden">
			<?php
			woocommerce_wp_checkbox( array(
				'id'          => 'cpc_is_special',
				'value'       => $is_special ? 'yes' : 'no',
				'label'       => "Today's Special",
				'description' => 'Show this product in the app home-screen banner.',
			) );
			?>
			<p class="form-field"><span class="description" style="margin-left:12px;">Only one product can be the special. Ticking this removes it from any other product.</span></p>
			<?php
			woocommerce_wp_text_input( array(
				'id'          => 'cpc_offer_headline',
				'value'       => get_post_meta( $id, self::META_HEADLINE, true ),
				'label'       => 'Headline',
				'placeholder' => 'Fresh brisket in',
				'desc_tip'    => true,
				'description' => 'Leave blank to use the product name.',
			) );
			woocommerce_wp_text_input( array(
				'id'          => 'cpc_offer_price_display',
				'value'       => get_post_meta( $id, self::META_PRICE, true ),
				'label'       => 'Offer price (per lb / each)',
				'type'        => 'number',
				'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
				'placeholder' => '9.49',
				'desc_tip'    => true,
				'description' => "The discounted price the customer is actually charged while the offer runs. Must be lower than the regular price. Blank = no discount (banner only).",
			) );
			woocommerce_wp_text_input( array(
				'id'          => 'cpc_offer_subtitle',
				'value'       => get_post_meta( $id, self::META_SUBTITLE, true ),
				'label'       => 'Small line',
				'placeholder' => 'Ends tonight',
			) );
			woocommerce_wp_text_input( array(
				'id'          => 'cpc_offer_ends_at',
				'value'       => $ends ? str_replace( ' ', 'T', $ends ) : '',
				'label'       => 'Ends at',
				'type'        => 'datetime-local',
				'desc_tip'    => true,
				'description' => 'The banner hides itself at this time. Leave blank to keep it until the tag is removed.',
			) );
			woocommerce_wp_text_input( array(
				'id'          => 'cpc_offer_image',
				'value'       => get_post_meta( $id, self::META_IMAGE, true ),
				'label'       => 'Banner image URL',
				'placeholder' => 'leave blank to use the product image',
			) );
			?>
			<p class="form-field"><span class="description" style="margin-left:12px;">
				Store time now: <?php echo esc_html( current_time( 'D j M Y, g:i a' ) ); ?>.
				App endpoint: <code>/wp-json/casa-prime/v1/special-offer</code>
			</span></p>
		</div>
		<?php
	}

	public static function save_product( $product_id ) {
		// Woo has already checked the nonce and capability by this point.
		if ( ! empty( $_POST['cpc_is_special'] ) ) {
			self::set_special( $product_id );
		} elseif ( self::get_special_id() === (int) $product_id ) {
			self::clear_special();
		}

		self::update_offer( $product_id, array(
			'headline'      => wp_unslash( $_POST['cpc_offer_headline'] ?? '' ),
			'price_display' => wp_unslash( $_POST['cpc_offer_price_display'] ?? '' ),
			'subtitle'      => wp_unslash( $_POST['cpc_offer_subtitle'] ?? '' ),
			'image'         => wp_unslash( $_POST['cpc_offer_image'] ?? '' ),
			'ends_at'       => wp_unslash( $_POST['cpc_offer_ends_at'] ?? '' ),
		) );
	}

	/* ---------- One-time migration off the old settings page ---------- */

	public static function maybe_migrate() {
		$old = get_option( self::LEGACY_OPTION, null );
		if ( null === $old ) {
			return;
		}

		if ( is_array( $old ) && ! empty( $old['product_id'] ) ) {
			$id = (int) $old['product_id'];
			self::update_offer( $id, array(
				'headline'      => $old['headline'] ?? '',
				'price_display' => $old['price_display'] ?? '',
				'subtitle'      => $old['subtitle'] ?? '',
				'image'         => $old['image'] ?? '',
				'ends_at'       => $old['ends_at'] ?? '',
			) );
			if ( ! empty( $old['enabled'] ) ) {
				self::set_special( $id );
			}
		}

		delete_option( self::LEGACY_OPTION );
	}
}
