<?php
if (!defined('ABSPATH')) exit;

class Redirection_CPT {
    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
    }

    public function register_cpt() {
        register_post_type('redirection_url', [
            'labels' => [
                'name'               => 'Redirection URLs',
                'singular_name'      => 'Redirection URL',
                'menu_name'          => 'Redirects',
                'add_new'            => 'Add New Redirection',
                'add_new_item'       => 'Add New Redirection',
                'edit_item'          => 'Edit Redirection',
                'new_item'           => 'New Redirection',
                'view_item'          => 'View Redirection',
                'search_items'       => 'Search Redirections',
                'not_found'          => 'No redirections yet.',
                'not_found_in_trash' => 'No redirections in the trash.',
                'all_items'          => 'All Redirects',
            ],
            'public'        => false,
            'show_ui'       => true,
            'menu_icon'     => 'dashicons-randomize',
            'supports'      => ['title'],
            'menu_position' => 25,
            // Managing redirects is an SEO/admin job, not something an Author
            // or Contributor should be able to do — an unnoticed redirect can
            // send a page's traffic anywhere. Mapping every capability straight
            // onto manage_options (with map_meta_cap left off) keeps this simple
            // and avoids inventing capabilities no role actually holds.
            'map_meta_cap'    => false,
            'capabilities'    => [
                'edit_post'          => 'manage_options',
                'read_post'          => 'manage_options',
                'delete_post'        => 'manage_options',
                'edit_posts'         => 'manage_options',
                'edit_others_posts'  => 'manage_options',
                'publish_posts'      => 'manage_options',
                'read_private_posts' => 'manage_options',
                'delete_posts'       => 'manage_options',
                'create_posts'       => 'manage_options',
            ],
        ]);
    }
}
