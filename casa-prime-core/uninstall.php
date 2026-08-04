<?php
/**
 * Casa Prime Core — uninstall cleanup.
 * Removes the custom roles and the casa-prime capabilities from administrators.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-cpc-roles.php';

CPC_Roles::remove_roles();
