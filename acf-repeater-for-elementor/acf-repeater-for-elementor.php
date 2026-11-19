<?php

/**
 * Plugin Name: ACF Repeater for Elementor
 * Description: Render ACF Repeater field data (or JSON) inside an Elementor Template, in a flexible Grid or a fully-styleable Carousel, with Repeater subfield dynamic tag support.
 * Version: 2.0.0
 * Author: Noman Nadeem
 * Text Domain: acf-repeater-for-elementor
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: elementor
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('ARFE_VERSION')) {
    return;
}

define('ARFE_VERSION', '2.0.0');
define('ARFE_FILE', __FILE__);
define('ARFE_DIR', __DIR__);
define('ARFE_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {
    $is_elementor_active = defined('ELEMENTOR_VERSION');
    $is_acf_active = function_exists('get_field');

    if (!($is_elementor_active && $is_acf_active)) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>ACF Repeater for Elementor:</strong> requires <strong>Elementor</strong> and <strong>ACF</strong> (free or Pro) to be installed and activated.</p></div>';
        });
        return;
    }

    add_action('elementor/widgets/register', function ($widgets_manager) {
        require_once ARFE_DIR . '/widgets/class-repeater-widget.php';

        if (class_exists('\AcfRepeaterForElementor\Widgets\ARFE_Repeater_Widget')) {
            $widgets_manager->register(new \AcfRepeaterForElementor\Widgets\ARFE_Repeater_Widget());
        }
    });

    add_action('elementor/dynamic_tags/register', function ($dynamic_tags) {
        require_once ARFE_DIR . '/classes/class-subfield-tags.php';

        // Elementor Pro registers the "acf" group; without Pro the tags would have no group to show in.
        if (!defined('ELEMENTOR_PRO_VERSION')) {
            $dynamic_tags->register_group('acf', ['title' => esc_html__('ACF', 'acf-repeater-for-elementor')]);
        }

        $tag_classes = [
            '\AcfRepeaterForElementor\Tags\ARFE_Subfield_Tag',
            '\AcfRepeaterForElementor\Tags\ARFE_Subfield_Image_Tag',
            '\AcfRepeaterForElementor\Tags\ARFE_Subfield_Gallery_Tag',
        ];

        foreach ($tag_classes as $tag_class) {
            if (class_exists($tag_class)) {
                $dynamic_tags->register(new $tag_class());
            }
        }
    });

    add_action('elementor/frontend/after_register_scripts', function () {
        // Registered under our own handles (never "swiper") so we never collide with
        // whatever Swiper version Elementor/Elementor Pro bundles on the host site.
        wp_register_script('arfe-swiper', ARFE_URL . 'assets/js/vendor/swiper-bundle.min.js', [], '8.4.5', true);
        wp_register_style('arfe-swiper', ARFE_URL . 'assets/css/vendor/swiper-bundle.min.css', [], '8.4.5');

        wp_register_script('acf-repeater-for-elementor', ARFE_URL . 'assets/js/acf-repeater-for-elementor.js', ['jquery', 'arfe-swiper'], ARFE_VERSION, true);
        wp_register_style('acf-repeater-for-elementor', ARFE_URL . 'assets/css/acf-repeater-for-elementor.css', ['arfe-swiper'], ARFE_VERSION);
    });
});
