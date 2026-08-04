<?php
/**
 * Vouchers list table (dashboard).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class TGMV_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'voucher',
				'plural'   => 'vouchers',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'voucher_no'   => 'Voucher #',
			'family_head'  => 'Family Head',
			'package'      => 'Package',
			'pax'          => 'PAX',
			'voucher_date' => 'Voucher Date',
			'status'       => 'Status',
			'created'      => 'Created',
			'actions'      => 'Actions',
		);
	}

	public function get_sortable_columns() {
		return array(
			'voucher_no'   => array( 'voucher_no', true ),
			'family_head'  => array( 'family_head', false ),
			'voucher_date' => array( 'voucher_date', false ),
			'created'      => array( 'created', true ),
		);
	}

	public function prepare_items() {
		$per_page = 20;
		$paged    = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'created';
		$order    = ( isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'ASC' : 'DESC';

		$args = array(
			'post_type'      => 'tgm_voucher',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
		);

		if ( $search ) {
			// Match voucher no / family head via post_title (title = "UB-100066 — NAME").
			$args['s'] = $search;
		}

		if ( in_array( $status, array( 'approved', 'unapproved' ), true ) ) {
			$args['meta_query'][] = array(
				'key'   => '_tgmv_status',
				'value' => $status,
			);
		}

		switch ( $orderby ) {
			case 'voucher_no':
				$args['meta_key'] = '_tgmv_voucher_no';
				$args['orderby']  = 'meta_value';
				break;
			case 'family_head':
				$args['meta_key'] = '_tgmv_family_head';
				$args['orderby']  = 'meta_value';
				break;
			case 'voucher_date':
				$args['meta_key'] = '_tgmv_voucher_date';
				$args['orderby']  = 'meta_value';
				break;
			default:
				$args['orderby'] = 'date';
		}
		$args['order'] = $order;

		$query = new WP_Query( $args );

		$this->items = $query->posts;
		$this->set_pagination_args(
			array(
				'total_items' => $query->found_posts,
				'per_page'    => $per_page,
				'total_pages' => $query->max_num_pages,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		?>
		<div class="alignleft actions">
			<select name="status">
				<option value="">All Statuses</option>
				<option value="approved" <?php selected( $status, 'approved' ); ?>>Approved</option>
				<option value="unapproved" <?php selected( $status, 'unapproved' ); ?>>Unapproved</option>
			</select>
			<?php submit_button( 'Filter', '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	public function column_default( $post, $column ) {
		$data = TGMV_Data::load( $post->ID );

		switch ( $column ) {
			case 'voucher_no':
				$edit = admin_url( 'admin.php?page=tgmv-add&voucher_id=' . $post->ID );
				return '<strong><a href="' . esc_url( $edit ) . '">' . esc_html( $data['voucher_no'] ) . '</a></strong>';

			case 'family_head':
				return esc_html( $data['family_head'] );

			case 'package':
				return esc_html( $data['package'] );

			case 'pax':
				return esc_html( TGMV_Data::pax_line( $data ) );

			case 'voucher_date':
				return esc_html( $data['voucher_date'] );

			case 'status':
				$toggle = wp_nonce_url(
					admin_url( 'admin-post.php?action=tgmv_toggle_status&voucher_id=' . $post->ID ),
					'tgmv_toggle_' . $post->ID
				);
				$badge = 'approved' === $data['status']
					? '<span class="tgmv-badge tgmv-badge-green">Approved</span>'
					: '<span class="tgmv-badge tgmv-badge-red">Unapproved</span>';
				return $badge . ' <a class="tgmv-toggle" href="' . esc_url( $toggle ) . '" title="Toggle status">&#8635;</a>';

			case 'created':
				return esc_html( get_the_date( 'd-m-Y H:i', $post ) );

			case 'actions':
				$view      = TGMV_Data::public_url( $post->ID );
				$edit      = admin_url( 'admin.php?page=tgmv-add&voucher_id=' . $post->ID );
				$duplicate = wp_nonce_url(
					admin_url( 'admin-post.php?action=tgmv_duplicate&voucher_id=' . $post->ID ),
					'tgmv_duplicate_' . $post->ID
				);
				$delete = wp_nonce_url(
					admin_url( 'admin-post.php?action=tgmv_delete&voucher_id=' . $post->ID ),
					'tgmv_delete_' . $post->ID
				);
				$out  = '<div class="tgmv-actions">';
				$out .= '<a href="' . esc_url( $view ) . '" target="_blank" class="button button-small">View</a>';
				$out .= '<a href="' . esc_url( $edit ) . '" class="button button-small">Edit</a>';
				$out .= '<a href="' . esc_url( $duplicate ) . '" class="button button-small">Duplicate</a>';
				$out .= '<a href="' . esc_url( $delete ) . '" class="button button-small tgmv-delete" onclick="return confirm(\'Delete this voucher permanently?\');">Delete</a>';
				$out .= '</div>';
				return $out;
		}

		return '';
	}

	public function no_items() {
		echo 'No vouchers yet. <a href="' . esc_url( admin_url( 'admin.php?page=tgmv-add' ) ) . '">Create your first voucher</a>.';
	}
}
