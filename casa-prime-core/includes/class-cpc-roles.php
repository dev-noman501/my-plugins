<?php
/**
 * Custom roles & capabilities for the Casa Prime platform.
 *
 * Roles:
 *  - customer      → WooCommerce built-in (app customers)
 *  - rider         → delivery driver: assigned orders, delivery statuses, live location, COD
 *  - manager       → runs the whole store flow: accepts orders, prepares them,
 *                    marks them ready, then assigns a rider
 *  - administrator → gets every casa-prime capability
 *
 * NOTE: the separate "store_worker" role was retired at the client's request —
 * the manager prepares orders and assigns riders. See maybe_migrate().
 */

defined( 'ABSPATH' ) || exit;

class CPC_Roles {

	const ROLES_VERSION = '2';

	/**
	 * Capability sets per role.
	 */
	public static function get_role_definitions() {
		return array(
			'rider' => array(
				'display_name' => 'Rider',
				'capabilities' => array(
					'read'                       => true,
					'cpc_view_assigned_orders'   => true, // own deliveries only
					'cpc_update_delivery_status' => true, // picked up / delivered / failed
					'cpc_update_location'        => true, // live lat/lng ping
					'cpc_collect_cod'            => true, // mark COD collected
					'cpc_set_availability'       => true, // available / offline toggle
				),
			),
			'manager' => array(
				'display_name' => 'Manager',
				'capabilities' => array(
					'read'                       => true,
					// Full visibility over the casa-prime flow.
					'cpc_view_order_queue'       => true,
					'cpc_accept_orders'          => true,
					'cpc_update_packing_status'  => true,
					'cpc_view_assigned_orders'   => true,
					'cpc_update_delivery_status' => true,
					'cpc_assign_riders'          => true, // assign order → rider
					'cpc_manage_riders'          => true, // riders list, availability, COD reconciliation
					'cpc_view_reports'           => true,
					// WooCommerce order access for the WP dashboard.
					'read_shop_order'            => true,
					'edit_shop_orders'           => true,
					'edit_others_shop_orders'    => true,
					'view_woocommerce_reports'   => true,
				),
			),
		);
	}

	/**
	 * Every casa-prime capability (used to grant admins everything).
	 */
	public static function get_all_caps() {
		$caps = array();
		foreach ( self::get_role_definitions() as $role ) {
			foreach ( $role['capabilities'] as $cap => $granted ) {
				if ( 0 === strpos( $cap, 'cpc_' ) ) {
					$caps[ $cap ] = true;
				}
			}
		}
		return $caps;
	}

	/**
	 * Create roles + grant all casa-prime caps to administrators. Runs on activation.
	 */
	public static function add_roles() {
		foreach ( self::get_role_definitions() as $role_key => $role ) {
			remove_role( $role_key ); // refresh caps if the role already exists
			add_role( $role_key, $role['display_name'], $role['capabilities'] );
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::get_all_caps() as $cap => $granted ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * One-time migration: retire the old "store_worker" role.
	 *
	 * The client decided the manager prepares orders and assigns riders, so the
	 * separate worker role is gone. Existing store_worker accounts are moved to
	 * `manager` (they are store staff, not customers) so nobody is locked out.
	 */
	public static function maybe_migrate() {
		if ( self::ROLES_VERSION === get_option( 'cpc_roles_version' ) ) {
			return;
		}

		if ( get_role( 'store_worker' ) ) {
			foreach ( get_users( array( 'role' => 'store_worker' ) ) as $u ) {
				$user = new WP_User( $u->ID );
				$user->remove_role( 'store_worker' );
				if ( ! in_array( 'manager', (array) $user->roles, true ) ) {
					$user->add_role( 'manager' );
				}
			}
			remove_role( 'store_worker' );
		}

		update_option( 'cpc_roles_version', self::ROLES_VERSION );
	}

	/**
	 * Remove roles + admin caps. Used by uninstall.php.
	 */
	public static function remove_roles() {
		$keys = array_keys( self::get_role_definitions() );
		$keys[] = 'store_worker'; // legacy role, retired
		foreach ( $keys as $role_key ) {
			remove_role( $role_key );
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::get_all_caps() as $cap => $granted ) {
				$admin->remove_cap( $cap );
			}
		}
	}
}
