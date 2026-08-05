<?php
/**
 * Plugin Name: Redirection URLs
 * Description: Manage 301/302/307 URL redirects one at a time or in bulk from a CSV. Built for SEO migrations.
 * Version: 1.1.0
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * Author: Noman Nadeem
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit; // Prevent direct access

// Include files
require_once plugin_dir_path(__FILE__) . 'includes/class-redirection-cpt.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-redirection-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-redirection-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-redirection-import.php';

// Initialize
add_action('plugins_loaded', function() {
    new Redirection_CPT();
    new Redirection_Admin();
    new Redirection_Handler();
    new Redirection_Import();
});
