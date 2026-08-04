<?php
/**
 * REST API — products & categories for the app (casa-prime/v1).
 *
 * Returns everything the app's product tiles and detail page need, including
 * Casa Prime's own fields (sold_by, per-lb weight step / min / max, cut
 * options). Public — customers browse as guests.
 *
 * IMPORTANT (exact-charge model): the price is final. total = weight x per-lb
 * price. There is NO packing-time adjustment and NO "estimate" — the API never
 * returns buffer/estimate fields. `exact_charge` is always true.
 *
 * GET /products                list/browse (category, search, featured, paging)
 * GET /products/{id}           single product detail
 * GET /categories              product categories
 */

defined( 'ABSPATH' ) || exit;

class CPC_REST_Products {

	const REST_NAMESPACE = 'casa-prime/v1';

	// Default cut options for weight-sold items when a product has none set.
	const DEFAULT_CUTS = array( 'Whole', 'Sliced thin', 'Sliced thick', 'Cubed' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$ns = self::REST_NAMESPACE;

		register_rest_route( $ns, '/products', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_products' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'category' => array( 'type' => 'string', 'required' => false ),   // slug or id
				'search'   => array( 'type' => 'string', 'required' => false ),
				'featured' => array( 'type' => 'boolean', 'required' => false ),
				'per_page' => array( 'type' => 'integer', 'required' => false, 'default' => 20 ),
				'page'     => array( 'type' => 'integer', 'required' => false, 'default' => 1 ),
				'orderby'  => array( 'type' => 'string', 'required' => false, 'default' => 'menu_order' ),
			),
		) );

		register_rest_route( $ns, '/products/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_product' ),
			'permission_callback' => '__return_true',
			'args'                => array( 'id' => array( 'validate_callback' => function ( $v ) { return is_numeric( $v ); } ) ),
		) );

		register_rest_route( $ns, '/categories', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_categories' ),
			'permission_callback' => '__return_true',
		) );
	}

	/* ---------- List ---------- */

	public static function list_products( WP_REST_Request $request ) {
		$args = array(
			'status'   => 'publish',
			'limit'    => min( 50, max( 1, (int) $request['per_page'] ) ),
			'page'     => max( 1, (int) $request['page'] ),
			'paginate' => true,
			'orderby'  => in_array( $request['orderby'], array( 'menu_order', 'title', 'date', 'price', 'popularity' ), true ) ? $request['orderby'] : 'menu_order',
			'order'    => 'ASC',
		);

		if ( ! empty( $request['category'] ) ) {
			$cat = $request['category'];
			$args['category'] = array( is_numeric( $cat ) ? self::cat_slug_by_id( (int) $cat ) : sanitize_title( $cat ) );
		}
		if ( ! empty( $request['search'] ) ) {
			$args['s'] = sanitize_text_field( $request['search'] );
		}
		if ( null !== $request['featured'] && '' !== $request['featured'] ) {
			$args['featured'] = rest_sanitize_boolean( $request['featured'] );
		}

		$results = wc_get_products( $args );

		$products = array();
		foreach ( $results->products as $product ) {
			$products[] = self::format_product( $product, false );
		}

		return rest_ensure_response( array(
			'success'     => true,
			'page'        => $args['page'],
			'per_page'    => $args['limit'],
			'total'       => (int) $results->total,
			'total_pages' => (int) $results->max_num_pages,
			'data'        => $products,
		) );
	}

	/* ---------- Single ---------- */

	public static function get_product( WP_REST_Request $request ) {
		$product = wc_get_product( (int) $request['id'] );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_Error( 'cpc_product_not_found', 'Product not found.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'data' => self::format_product( $product, true ) ) );
	}

	/* ---------- Categories ---------- */

	public static function list_categories( WP_REST_Request $request ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$cats = array();
		foreach ( $terms as $t ) {
			if ( 'uncategorized' === $t->slug ) { continue; }
			$thumb_id = get_term_meta( $t->term_id, 'thumbnail_id', true );
			$cats[] = array(
				'id'    => $t->term_id,
				'name'  => $t->name,
				'slug'  => $t->slug,
				'count' => (int) $t->count,
				'image' => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : null,
			);
		}
		return rest_ensure_response( array( 'success' => true, 'data' => $cats ) );
	}

	/* ---------- Formatter ---------- */

	// Public so the favourites endpoint can render products in the same shape.
	public static function format_product( WC_Product $product, $detail = false ) {
		$id      = $product->get_id();
		$sold_by = get_post_meta( $id, '_cpc_sold_by', true ) ?: 'each';
		$regular = (float) $product->get_regular_price();
		// The price actually charged right now — the offer price while this
		// product is the live special, otherwise the regular price.
		$price    = class_exists( 'CPC_Special_Offer' ) ? (float) CPC_Special_Offer::effective_price( $product ) : $regular;
		$on_offer = $price < $regular;
		$symbol  = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$unit    = 'weight' === $sold_by ? ' / lb' : ' each';

		$data = array(
			'id'                => $id,
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'sold_by'           => 'weight' === $sold_by ? 'weight' : 'each',
			'price'             => $price,
			'regular_price'     => $regular,
			'on_offer'          => $on_offer,
			'offer_price'       => $on_offer ? $price : null,
			// Struck-through regular price for the app when on offer.
			'regular_price_display' => $symbol . number_format( $regular, 2 ) . $unit,
			'offer_ends_at'     => $on_offer && class_exists( 'CPC_Special_Offer' )
				? ( CPC_Special_Offer::get_offer()['ends_at'] ?? null ) : null,
			'price_unit'        => 'weight' === $sold_by ? 'lb' : 'each',
			'price_display'     => $symbol . number_format( $price, 2 ) . $unit,
			'currency'          => get_woocommerce_currency(),
			'currency_symbol'   => $symbol,
			'short_description' => wp_strip_all_tags( $product->get_short_description() ),
			'featured'          => $product->get_featured(),
			'in_stock'          => $product->is_in_stock(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'image'             => self::image_url( $product->get_image_id(), 'large' ),
			'categories'        => self::product_categories( $id ),
			// Exact-charge model: the shown total is final, never an estimate.
			'exact_charge'      => true,
			// Heart icon state for the logged-in customer; false for guests.
			'is_favorite'       => class_exists( 'CPC_REST_Favorites' )
				? CPC_REST_Favorites::is_favorite( get_current_user_id(), $id )
				: false,
		);

		if ( 'weight' === $sold_by ) {
			$data['weight_step'] = (float) ( get_post_meta( $id, '_cpc_weight_step', true ) ?: 0.5 );
			$data['min_weight']  = (float) ( get_post_meta( $id, '_cpc_min_weight', true ) ?: $data['weight_step'] );
			$data['max_weight']  = (float) ( get_post_meta( $id, '_cpc_max_weight', true ) ?: 10 );
			$cuts = get_post_meta( $id, '_cpc_cut_options', true );
			$data['cut_options'] = is_array( $cuts ) && $cuts ? array_values( $cuts ) : self::DEFAULT_CUTS;
		} else {
			$data['cut_options'] = array();
		}

		if ( $detail ) {
			$data['description']                   = wp_kses_post( $product->get_description() );
			$data['sku']                           = $product->get_sku();
			$data['images']                        = self::gallery_urls( $product );
			$data['supports_special_instructions'] = true;
			// Convenience for the app's weight stepper: total for the minimum weight.
			if ( 'weight' === $sold_by ) {
				$data['price_for_min_weight'] = round( $price * $data['min_weight'], 2 );
			}
		}

		return $data;
	}

	protected static function image_url( $image_id, $size = 'large' ) {
		return $image_id ? wp_get_attachment_image_url( $image_id, $size ) : wc_placeholder_img_src( $size );
	}

	protected static function gallery_urls( WC_Product $product ) {
		$ids = array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
		$urls = array();
		foreach ( $ids as $iid ) {
			$u = self::image_url( $iid, 'large' );
			if ( $u ) { $urls[] = $u; }
		}
		return $urls ? $urls : array( wc_placeholder_img_src( 'large' ) );
	}

	protected static function product_categories( $product_id ) {
		$terms = wp_get_post_terms( $product_id, 'product_cat' );
		$out = array();
		foreach ( $terms as $t ) {
			if ( 'uncategorized' === $t->slug ) { continue; }
			$out[] = array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
		}
		return $out;
	}

	protected static function cat_slug_by_id( $id ) {
		$term = get_term( $id, 'product_cat' );
		return ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
	}
}
