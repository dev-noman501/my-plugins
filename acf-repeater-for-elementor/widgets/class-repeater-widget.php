<?php

namespace AcfRepeaterForElementor\Widgets;

if (!defined('ABSPATH')) {
    exit;
}

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Repeater;

if (class_exists(__NAMESPACE__ . '\\ARFE_Repeater_Widget')) {
    return;
}

class ARFE_Repeater_Widget extends Widget_Base {

    public function get_name() {
        return 'arfe_repeater_widget';
    }

    public function get_title() {
        return esc_html__('ACF Repeater', 'acf-repeater-for-elementor');
    }

    public function get_icon() {
        return 'eicon-post-list';
    }

    public function get_categories() {
        return ['general'];
    }

    public function get_keywords() {
        return ['acf', 'repeater', 'grid', 'carousel', 'slider'];
    }

    public function get_script_depends() {
        return ['arfe-swiper', 'acf-repeater-for-elementor'];
    }

    public function get_style_depends() {
        return ['arfe-swiper', 'acf-repeater-for-elementor'];
    }

    /* ---------------------------------------------------------------- */
    /* Helpers for building control option lists                        */
    /* ---------------------------------------------------------------- */

    private function get_acf_repeater_fields() {
        if (!function_exists('acf_get_field_groups')) {
            return [];
        }

        $groups = acf_get_field_groups();
        $options = [];

        foreach ($groups as $group) {
            $fields = acf_get_fields($group['key']);
            if ($fields) {
                $this->extract_repeater_fields($fields, $options);
            }
        }

        return $options ?: ['' => esc_html__('No repeater fields found', 'acf-repeater-for-elementor')];
    }

    private function extract_repeater_fields($fields, &$options, $prefix = '') {
        foreach ($fields as $field) {
            if ($field['type'] === 'repeater') {
                $field_key = $prefix ? $prefix . '.' . $field['name'] : $field['name'];
                $field_label = $prefix ? $prefix . ' → ' . $field['label'] : $field['label'];
                $options[$field_key] = $field_label . ' (' . $field_key . ')';

                if (!empty($field['sub_fields'])) {
                    $this->extract_repeater_fields($field['sub_fields'], $options, $field_key);
                }
            } elseif (in_array($field['type'], ['group', 'flexible_content']) && !empty($field['sub_fields'])) {
                $nested_prefix = $prefix ? $prefix . '.' . $field['name'] : $field['name'];
                $this->extract_repeater_fields($field['sub_fields'], $options, $nested_prefix);
            }
        }
    }

    private function get_elementor_templates() {
        $posts = get_posts([
            'post_type' => 'elementor_library',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        $options = [];
        foreach ($posts as $post) {
            $options[$post->ID] = $post->post_title;
        }

        return $options ?: ['' => esc_html__('No templates found', 'acf-repeater-for-elementor')];
    }

    /* ---------------------------------------------------------------- */
    /* Controls                                                          */
    /* ---------------------------------------------------------------- */

    protected function register_controls() {
        $this->register_content_controls();
        $this->register_layout_controls();
        $this->register_style_controls();
        $this->register_carousel_style_controls();
        $this->register_navigation_style_controls();
    }

    private function register_content_controls() {
        $this->start_controls_section('content_section', [
            'label' => esc_html__('Data Source', 'acf-repeater-for-elementor'),
        ]);

        $this->add_control('data_source', [
            'label' => esc_html__('Data Source', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SELECT,
            'options' => [
                'acf' => esc_html__('ACF Repeater Field', 'acf-repeater-for-elementor'),
                'parent' => esc_html__('Parent Repeater Row (nested)', 'acf-repeater-for-elementor'),
                'json' => esc_html__('JSON Array', 'acf-repeater-for-elementor'),
            ],
            'default' => 'acf',
        ]);

        $this->add_control('acf_field_name', [
            'label' => esc_html__('ACF Repeater Field', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SELECT,
            'options' => $this->get_acf_repeater_fields(),
            'condition' => ['data_source' => 'acf'],
        ]);

        $this->add_control('acf_location', [
            'label' => esc_html__('Read From', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SELECT,
            'options' => [
                'current' => esc_html__('Current Post', 'acf-repeater-for-elementor'),
                'option' => esc_html__('Options Page', 'acf-repeater-for-elementor'),
                'custom' => esc_html__('Specific Post ID', 'acf-repeater-for-elementor'),
            ],
            'default' => 'current',
            'condition' => ['data_source' => 'acf'],
        ]);

        $this->add_control('acf_post_id', [
            'label' => esc_html__('Post ID', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::NUMBER,
            'min' => 1,
            'condition' => ['data_source' => 'acf', 'acf_location' => 'custom'],
        ]);

        $this->add_control('parent_field_name', [
            'label' => esc_html__('Nested Repeater Subfield', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::TEXT,
            'placeholder' => 'section_items',
            'condition' => ['data_source' => 'parent'],
        ]);

        $this->add_control('parent_note', [
            'type' => Controls_Manager::RAW_HTML,
            'raw' => __('Place this widget inside the template used by an outer ACF Repeater widget. It renders the named repeater subfield of the row being displayed.', 'acf-repeater-for-elementor'),
            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
            'condition' => ['data_source' => 'parent'],
        ]);

        $this->add_control('row_keys_note', [
            'type' => Controls_Manager::RAW_HTML,
            'raw' => __('Every row also exposes <code>_row_number</code> (1, 2, 3…), <code>_row_index</code> (0, 1, 2…) and <code>_row_count</code> to the subfield dynamic tags.', 'acf-repeater-for-elementor'),
            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        ]);

        $this->add_control('json_data', [
            'label' => esc_html__('JSON Array Data', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::TEXTAREA,
            'placeholder' => '[{"title":"Item 1"},{"title":"Item 2"}]',
            'rows' => 6,
            'condition' => ['data_source' => 'json'],
        ]);

        $this->add_control('nested_note', [
            'type' => Controls_Manager::RAW_HTML,
            'raw' => __('<strong>Nested Repeaters:</strong> Use dot notation (e.g., "parent_repeater.child_repeater") to access nested repeater fields.', 'acf-repeater-for-elementor'),
            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
            'condition' => ['data_source' => 'acf'],
        ]);

        $repeater = new Repeater();
        $repeater->add_control('subfield_key', [
            'label' => esc_html__('Subfield Key', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::TEXT,
        ]);
        $repeater->add_control('dynamic_tag_key', [
            'label' => esc_html__('Dynamic Tag Key', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::TEXT,
        ]);
        $this->add_control('fields_mapping', [
            'label' => esc_html__('Subfield to Dynamic Tag Mapping', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::REPEATER,
            'fields' => $repeater->get_controls(),
            'title_field' => '{{subfield_key}} → {{dynamic_tag_key}}',
            'prevent_empty' => false,
        ]);

        $style_repeater = new Repeater();
        $style_repeater->add_control('css_var_name', [
            'label' => esc_html__('CSS Variable Name', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::TEXT,
            'placeholder' => 'card-bg',
            'description' => esc_html__('No "--" prefix, no spaces, e.g. card-bg', 'acf-repeater-for-elementor'),
        ]);
        $style_repeater->add_control('style_subfield_key', [
            'label' => esc_html__('ACF Subfield Key', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::TEXT,
        ]);
        $this->add_control('style_vars_note', [
            'type' => Controls_Manager::RAW_HTML,
            'raw' => __('Use this for per-row Style values (background color, border color, etc.) — Elementor\'s Style tab controls are compiled once into static CSS and can\'t change per row. Each row below is set as a CSS variable on that card, which you can then reference inside the template\'s Background/Border via <strong>Custom CSS</strong> (Elementor Pro), e.g.: <code>selector{background-color: var(--card-bg, #1e1e2d);}</code>', 'acf-repeater-for-elementor'),
            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        ]);
        $this->add_control('style_vars_mapping', [
            'label' => esc_html__('Per-Row Style Variables (CSS Vars)', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::REPEATER,
            'fields' => $style_repeater->get_controls(),
            'title_field' => '--{{css_var_name}} ← {{style_subfield_key}}',
            'prevent_empty' => false,
        ]);

        $this->add_control('template_id', [
            'label' => esc_html__('Elementor Template', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SELECT,
            'options' => $this->get_elementor_templates(),
            'label_block' => true,
        ]);

        $this->add_control('items_limit', [
            'label' => esc_html__('Number of Items to Show', 'acf-repeater-for-elementor'),
            'description' => esc_html__('Leave empty to show all items.', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::NUMBER,
            'min' => 1,
            'placeholder' => esc_html__('All', 'acf-repeater-for-elementor'),
        ]);

        $this->end_controls_section();
    }

    private function register_layout_controls() {
        $this->start_controls_section('layout_section', [
            'label' => esc_html__('Layout', 'acf-repeater-for-elementor'),
        ]);

        $this->add_control('layout', [
            'label' => esc_html__('Layout', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::CHOOSE,
            'options' => [
                'grid' => ['title' => esc_html__('Grid', 'acf-repeater-for-elementor'), 'icon' => 'eicon-gallery-grid'],
                'carousel' => ['title' => esc_html__('Carousel', 'acf-repeater-for-elementor'), 'icon' => 'eicon-slider-push'],
            ],
            'default' => 'grid',
            'toggle' => false,
        ]);

        /* ---- Grid specific ---- */
        $this->add_responsive_control('columns', [
            'label' => esc_html__('Columns', 'acf-repeater-for-elementor'),
            'description' => esc_html__('Type any number. Items are arranged in exactly this many equal-width columns per row.', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::NUMBER,
            'min' => 1,
            'max' => 12,
            'step' => 1,
            'default' => 3,
            'tablet_default' => 2,
            'mobile_default' => 1,
            'condition' => ['layout' => 'grid'],
            'selectors' => [
                '{{WRAPPER}} .arfe-repeater-grid' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
            ],
        ]);

        $this->add_control('rows', [
            'label' => esc_html__('Rows', 'acf-repeater-for-elementor'),
            'description' => esc_html__('Limits how many rows are displayed (columns × rows items). Leave empty to show all items.', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::NUMBER,
            'min' => 1,
            'placeholder' => esc_html__('All', 'acf-repeater-for-elementor'),
            'condition' => ['layout' => 'grid'],
        ]);

        $this->add_control('equal_height', [
            'label' => esc_html__('Enable Equal Height', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SWITCHER,
            'label_on' => esc_html__('Yes', 'acf-repeater-for-elementor'),
            'label_off' => esc_html__('No', 'acf-repeater-for-elementor'),
            'return_value' => 'yes',
            'default' => 'no',
            'description' => esc_html__('Stretches every card to the height of the tallest one in its row (grid) or in the slider (carousel).', 'acf-repeater-for-elementor'),
        ]);

        /* ---- Carousel specific ---- */
        $this->add_responsive_control('slides_to_show', [
            'label' => esc_html__('Slides to Show', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::NUMBER,
            'min' => 1,
            'max' => 12,
            'step' => 1,
            'default' => 3,
            'tablet_default' => 2,
            'mobile_default' => 1,
            'condition' => ['layout' => 'carousel'],
        ]);

        $this->add_responsive_control('slides_to_scroll', [
            'label' => esc_html__('Slides to Scroll', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::NUMBER,
            'min' => 1,
            'max' => 12,
            'step' => 1,
            'default' => 1,
            'condition' => ['layout' => 'carousel'],
        ]);

        $this->add_control('loop', [
            'label' => esc_html__('Infinite Loop', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default' => 'yes',
            'condition' => ['layout' => 'carousel'],
        ]);

        $this->add_control('autoplay', [
            'label' => esc_html__('Autoplay', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default' => 'yes',
            'condition' => ['layout' => 'carousel'],
        ]);

        $this->add_control('autoplay_speed', [
            'label' => esc_html__('Autoplay Speed (ms)', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::NUMBER,
            'min' => 500,
            'step' => 100,
            'default' => 3000,
            'condition' => ['layout' => 'carousel', 'autoplay' => 'yes'],
        ]);

        $this->add_control('pause_on_hover', [
            'label' => esc_html__('Pause on Hover', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default' => 'yes',
            'condition' => ['layout' => 'carousel', 'autoplay' => 'yes'],
        ]);

        $this->add_control('transition_speed', [
            'label' => esc_html__('Transition Speed (ms)', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::NUMBER,
            'min' => 100,
            'step' => 50,
            'default' => 500,
            'condition' => ['layout' => 'carousel'],
        ]);

        $this->add_control('show_arrows', [
            'label' => esc_html__('Arrows', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default' => 'yes',
            'condition' => ['layout' => 'carousel'],
        ]);

        $this->add_control('show_dots', [
            'label' => esc_html__('Pagination Dots', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default' => 'yes',
            'condition' => ['layout' => 'carousel'],
        ]);

        $this->end_controls_section();
    }

    private function register_style_controls() {
        $this->start_controls_section('style_section', [
            'label' => esc_html__('Container Style', 'acf-repeater-for-elementor'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('column_gap', [
            'label' => esc_html__('Column Gap', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem', '%'],
            'range' => [
                'px' => ['min' => 0, 'max' => 200],
                'em' => ['min' => 0, 'max' => 20],
                'rem' => ['min' => 0, 'max' => 20],
                '%' => ['min' => 0, 'max' => 50],
            ],
            'default' => ['size' => 20, 'unit' => 'px'],
            'condition' => ['layout' => 'grid'],
            'selectors' => [
                '{{WRAPPER}} .arfe-repeater-grid' => 'column-gap: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('row_gap', [
            'label' => esc_html__('Row Gap', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem', '%'],
            'range' => [
                'px' => ['min' => 0, 'max' => 200],
                'em' => ['min' => 0, 'max' => 20],
                'rem' => ['min' => 0, 'max' => 20],
                '%' => ['min' => 0, 'max' => 50],
            ],
            'default' => ['size' => 20, 'unit' => 'px'],
            'condition' => ['layout' => 'grid'],
            'selectors' => [
                '{{WRAPPER}} .arfe-repeater-grid' => 'row-gap: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('container_padding', [
            'label' => esc_html__('Padding', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::DIMENSIONS,
            'selectors' => [
                '{{WRAPPER}} .arfe-repeater-wrapper' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('container_margin', [
            'label' => esc_html__('Margin', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::DIMENSIONS,
            'selectors' => [
                '{{WRAPPER}} .arfe-repeater-wrapper' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();
    }

    private function register_carousel_style_controls() {
        $this->start_controls_section('carousel_style_section', [
            'label' => esc_html__('Carousel', 'acf-repeater-for-elementor'),
            'tab' => Controls_Manager::TAB_STYLE,
            'condition' => ['layout' => 'carousel'],
        ]);

        $this->add_responsive_control('carousel_gap', [
            'label' => esc_html__('Gap Between Items', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'em', 'rem'],
            'range' => [
                'px' => ['min' => 0, 'max' => 200],
                'em' => ['min' => 0, 'max' => 20],
                'rem' => ['min' => 0, 'max' => 20],
            ],
            'default' => ['size' => 20, 'unit' => 'px'],
            'tablet_default' => ['size' => 15, 'unit' => 'px'],
            'mobile_default' => ['size' => 10, 'unit' => 'px'],
        ]);

        $this->add_control('carousel_height', [
            'label' => esc_html__('Fixed Slide Height', 'acf-repeater-for-elementor'),
            'description' => esc_html__('Leave empty to let each slide size itself automatically to its content.', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', 'vh'],
            'range' => [
                'px' => ['min' => 0, 'max' => 1000],
                'vh' => ['min' => 0, 'max' => 100],
            ],
            'selectors' => [
                '{{WRAPPER}} .swiper-slide' => 'height: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->end_controls_section();
    }

    private function register_navigation_style_controls() {
        $this->start_controls_section('nav_style_section', [
            'label' => esc_html__('Arrows & Dots', 'acf-repeater-for-elementor'),
            'tab' => Controls_Manager::TAB_STYLE,
            'condition' => ['layout' => 'carousel'],
        ]);

        $this->add_control('arrows_heading', [
            'label' => esc_html__('Arrows', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::HEADING,
            'condition' => ['show_arrows' => 'yes'],
        ]);

        $this->add_control('arrow_size', [
            'label' => esc_html__('Size', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::SLIDER,
            'range' => ['px' => ['min' => 10, 'max' => 80]],
            'default' => ['size' => 20, 'unit' => 'px'],
            'condition' => ['show_arrows' => 'yes'],
            'selectors' => [
                '{{WRAPPER}} .arfe-nav' => 'font-size: {{SIZE}}{{UNIT}}; width: calc({{SIZE}}{{UNIT}} * 2); height: calc({{SIZE}}{{UNIT}} * 2);',
            ],
        ]);

        $this->add_control('arrow_color', [
            'label' => esc_html__('Color', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::COLOR,
            'default' => '#ffffff',
            'condition' => ['show_arrows' => 'yes'],
            'selectors' => [
                '{{WRAPPER}} .arfe-nav' => 'color: {{VALUE}};',
            ],
        ]);

        $this->add_control('arrow_bg_color', [
            'label' => esc_html__('Background Color', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::COLOR,
            'default' => 'rgba(0,0,0,0.5)',
            'condition' => ['show_arrows' => 'yes'],
            'selectors' => [
                '{{WRAPPER}} .arfe-nav' => 'background-color: {{VALUE}};',
            ],
        ]);

        $this->add_control('dots_heading', [
            'label' => esc_html__('Dots', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::HEADING,
            'separator' => 'before',
            'condition' => ['show_dots' => 'yes'],
        ]);

        $this->add_control('dot_color', [
            'label' => esc_html__('Color', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::COLOR,
            'default' => '#c4c4c4',
            'condition' => ['show_dots' => 'yes'],
            'selectors' => [
                '{{WRAPPER}} .swiper-pagination-bullet' => 'background: {{VALUE}};',
            ],
        ]);

        $this->add_control('dot_active_color', [
            'label' => esc_html__('Active Color', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::COLOR,
            'default' => '#333333',
            'condition' => ['show_dots' => 'yes'],
            'selectors' => [
                '{{WRAPPER}} .swiper-pagination-bullet-active' => 'background: {{VALUE}};',
            ],
        ]);

        $this->end_controls_section();
    }

    /* ---------------------------------------------------------------- */
    /* Data fetching (same engine for ACF repeaters, incl. nested)       */
    /* ---------------------------------------------------------------- */

    private function get_simple_repeater_data($field_name, $post_id) {
        $rows = [];

        if (have_rows($field_name, $post_id)) {
            while (have_rows($field_name, $post_id)) {
                the_row();
                $rows[] = $this->process_row_data(get_row(true));
            }
        }

        return $rows;
    }

    private function get_nested_repeater_data($field_path, $post_id) {
        $path_parts = explode('.', $field_path);

        if (count($path_parts) !== 2) {
            return [];
        }

        [$outer_field, $inner_field] = $path_parts;
        $all_rows = [];

        if (have_rows($outer_field, $post_id)) {
            $outer_index = 0;

            while (have_rows($outer_field, $post_id)) {
                the_row();
                $outer_row_data = $this->process_row_data(get_row(true));

                $nested_rows = [];
                if (have_rows($inner_field)) {
                    $inner_index = 0;
                    while (have_rows($inner_field)) {
                        the_row();
                        $inner_row_data = $this->process_row_data(get_row(true));

                        $combined_row = [];
                        foreach ($outer_row_data as $key => $value) {
                            $combined_row['outer_' . $key] = $value;
                            $combined_row[$key] = $value;
                        }
                        foreach ($inner_row_data as $key => $value) {
                            $combined_row['inner_' . $key] = $value;
                            $combined_row[$key] = $value;
                        }

                        $combined_row['_outer_index'] = $outer_index;
                        $combined_row['_inner_index'] = $inner_index;
                        $combined_row['_combined_index'] = count($all_rows);

                        $nested_rows[] = $combined_row;
                        $inner_index++;
                    }
                }

                if (empty($nested_rows)) {
                    $outer_row_data['_outer_index'] = $outer_index;
                    $outer_row_data['_inner_index'] = -1;
                    $outer_row_data['_combined_index'] = count($all_rows);
                    $all_rows[] = $outer_row_data;
                } else {
                    $all_rows = array_merge($all_rows, $nested_rows);
                }

                $outer_index++;
            }
        }

        return $all_rows;
    }

    private function process_row_data($raw_data) {
        $processed_data = [];

        foreach ($raw_data as $key => $value) {
            if (is_array($value)) {
                if (isset($value['url'])) {
                    $processed_data[$key] = $value;
                    $processed_data[$key . '_url'] = $value['url'];
                    $processed_data[$key . '_id'] = $value['ID'] ?? ($value['id'] ?? null);
                    if (isset($value['alt'])) {
                        $processed_data[$key . '_alt'] = $value['alt'];
                    }
                    if (isset($value['title'])) {
                        $processed_data[$key . '_title'] = $value['title'];
                    }
                } elseif (isset($value['value'], $value['label'])) {
                    $processed_data[$key] = $value;
                    $processed_data[$key . '_value'] = $value['value'];
                    $processed_data[$key . '_label'] = $value['label'];
                } elseif (is_numeric(array_keys($value)[0] ?? '')) {
                    $processed_data[$key] = $value;
                    $processed_data[$key . '_text'] = implode(', ', array_filter($value, 'is_scalar'));
                } else {
                    $processed_data[$key] = $value;
                    $processed_data[$key . '_json'] = wp_json_encode($value);
                }
            } else {
                $processed_data[$key] = $value;
            }
        }

        return $processed_data;
    }

    /* ---------------------------------------------------------------- */
    /* CSS for the Elementor template                                    */
    /* ---------------------------------------------------------------- */

    /**
     * Get the selected template's generated CSS as plain text.
     *
     * We print this ourselves as an inline <style> block right where the widget
     * renders. wp_enqueue_style()/Post::enqueue() only works reliably when called
     * before wp_head has already printed - but a widget's render() runs deep inside
     * the_content(), long after that. Enqueuing at that point silently does nothing,
     * which is why the template's styling never showed up on the front end.
     */
    private function get_template_css($template_id) {
        static $printed = [];

        $template_id = (int) $template_id;
        if (!$template_id || isset($printed[$template_id]) || !class_exists('\Elementor\Core\Files\CSS\Post')) {
            return '';
        }

        // Print each template's CSS once per page, however many widgets use it.
        $printed[$template_id] = true;

        try {
            $css_file = \Elementor\Core\Files\CSS\Post::create($template_id);
            $meta = $css_file->get_meta();
            $path = $css_file->get_path();

            // Regenerate only when Elementor has no usable copy (first view or after "Regenerate CSS"),
            // not on every page load.
            $needs_update = empty($meta['status'])
                || ($meta['status'] === 'file' && (!$path || !file_exists($path)));

            if ($needs_update) {
                $css_file->update();
                $meta = $css_file->get_meta();
            }

            if (($meta['status'] ?? '') === 'inline') {
                return (string) ($meta['css'] ?? '');
            }

            if ($path && file_exists($path)) {
                return (string) file_get_contents($path);
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }

    /**
     * Post ID that ACF should read the repeater from.
     */
    private function get_source_post_id($settings) {
        switch ($settings['acf_location'] ?? 'current') {
            case 'option':
                return 'option';
            case 'custom':
                return !empty($settings['acf_post_id']) ? (int) $settings['acf_post_id'] : get_the_ID();
            default:
                return get_the_ID();
        }
    }

    /**
     * Rows of a nested repeater, read from the outer row currently being rendered.
     */
    private function get_parent_row_data($subfield) {
        $context = $GLOBALS['arfe_repeater_context'] ?? [];

        if ($subfield === '' || empty($context[$subfield]) || !is_array($context[$subfield])) {
            return [];
        }

        $rows = [];
        foreach ($context[$subfield] as $row) {
            if (is_array($row)) {
                $rows[] = $this->process_row_data($row);
            }
        }

        return $rows;
    }

    private function render_empty_notice($message) {
        if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
            echo '<p class="arfe-repeater-empty">' . esc_html($message) . '</p>';
        }
    }

    /* ---------------------------------------------------------------- */
    /* Render                                                            */
    /* ---------------------------------------------------------------- */

    public function render() {
        $settings = $this->get_settings_for_display();

        $data_source = $settings['data_source'];
        $template_id = $settings['template_id'];
        $fields_mapping = $settings['fields_mapping'];
        $style_vars_mapping = $settings['style_vars_mapping'] ?? [];
        $layout = $settings['layout'] ?: 'grid';
        $equal_height = ($settings['equal_height'] ?? '') === 'yes';

        if (empty($template_id)) {
            $this->render_empty_notice(__('Please select an Elementor template.', 'acf-repeater-for-elementor'));
            return;
        }

        $rows = [];

        if ($data_source === 'acf') {
            $acf_field = $settings['acf_field_name'];
            if (empty($acf_field)) {
                return;
            }

            $post_id = $this->get_source_post_id($settings);
            $rows = (strpos($acf_field, '.') !== false)
                ? $this->get_nested_repeater_data($acf_field, $post_id)
                : $this->get_simple_repeater_data($acf_field, $post_id);

            if (empty($rows)) {
                $this->render_empty_notice(__('No rows found for this repeater on the source post.', 'acf-repeater-for-elementor'));
                return;
            }
        } elseif ($data_source === 'parent') {
            $rows = $this->get_parent_row_data(trim((string) ($settings['parent_field_name'] ?? '')));

            if (empty($rows)) {
                $this->render_empty_notice(__('No nested rows in this row.', 'acf-repeater-for-elementor'));
                return;
            }
        } elseif ($data_source === 'json') {
            $decoded = json_decode($settings['json_data'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $rows = $decoded;
            } else {
                // Visitors never see a broken widget; editors get the reason.
                $this->render_empty_notice(__('Invalid JSON format.', 'acf-repeater-for-elementor'));
                return;
            }
        }

        // Apply item limit (and, for grid, columns x rows if "rows" is set).
        $limit = !empty($settings['items_limit']) ? (int) $settings['items_limit'] : null;
        if ($layout === 'grid' && !empty($settings['rows'])) {
            $columns = (int) ($settings['columns'] ?: 3);
            $grid_limit = $columns * (int) $settings['rows'];
            $limit = $limit ? min($limit, $grid_limit) : $grid_limit;
        }
        if ($limit) {
            $rows = array_slice($rows, 0, $limit);
        }

        // Row position, available to the subfield tags (e.g. numbered process steps).
        $rows = array_values($rows);
        $row_count = count($rows);
        foreach ($rows as $index => &$row) {
            if (is_array($row)) {
                $row['_row_index'] = $index;
                $row['_row_number'] = $index + 1;
                $row['_row_count'] = $row_count;
            }
        }
        unset($row);

        $wrapper_id = 'arfe-repeater-' . $this->get_id();

        if ($equal_height) {
            $this->render_equal_height_css($wrapper_id);
        }

        $template_css = $this->get_template_css($template_id);
        if ($template_css !== '') {
            echo '<style>' . $template_css . '</style>'; // phpcs:ignore -- Elementor-generated CSS, not user input.
        }

        echo '<div id="' . esc_attr($wrapper_id) . '" class="arfe-repeater-wrapper arfe-layout-' . esc_attr($layout) . '">';

        if ($layout === 'carousel') {
            $this->render_carousel($rows, $template_id, $fields_mapping, $style_vars_mapping, $settings);
        } else {
            echo '<div class="arfe-repeater-grid">';
            foreach ($rows as $index => $row_data) {
                $this->render_isolated_row($row_data, $template_id, $fields_mapping, $style_vars_mapping, $index);
            }
            echo '</div>';
        }

        echo '</div>';
    }

    private function render_equal_height_css($wrapper_id) {
        ?>
        <style>
            #<?php echo esc_attr($wrapper_id); ?> .arfe-repeater-grid {
                align-items: stretch;
            }
            #<?php echo esc_attr($wrapper_id); ?> .swiper-wrapper {
                align-items: stretch;
            }
            #<?php echo esc_attr($wrapper_id); ?> .swiper-slide {
                height: auto;
                display: flex;
            }
            #<?php echo esc_attr($wrapper_id); ?> .swiper-slide > .arfe-repeater-item {
                flex: 1 1 auto;
            }
            #<?php echo esc_attr($wrapper_id); ?> .arfe-repeater-item {
                display: flex;
                flex-direction: column;
                height: 100%;
            }
            #<?php echo esc_attr($wrapper_id); ?> .arfe-repeater-item > * {
                flex: 1 1 auto;
                display: flex;
                flex-direction: column;
                width: 100%;
            }
            #<?php echo esc_attr($wrapper_id); ?> .arfe-repeater-item > * > * {
                flex: 1 1 auto;
                width: 100%;
            }
        </style>
        <?php
    }

    private function render_carousel($rows, $template_id, $fields_mapping, $style_vars_mapping, $settings) {
        $show_arrows = ($settings['show_arrows'] ?? '') === 'yes';
        $show_dots = ($settings['show_dots'] ?? '') === 'yes';

        $gap = $settings['carousel_gap'] ?? [];
        $gap_tablet = $settings['carousel_gap_tablet'] ?? [];
        $gap_mobile = $settings['carousel_gap_mobile'] ?? [];

        $swiper_options = [
            'slidesPerView' => (float) ($settings['slides_to_show'] ?: 3),
            'slidesPerView_tablet' => (float) ($settings['slides_to_show_tablet'] ?: 2),
            'slidesPerView_mobile' => (float) ($settings['slides_to_show_mobile'] ?: 1),
            'slidesPerGroup' => (int) ($settings['slides_to_scroll'] ?: 1),
            'loop' => ($settings['loop'] ?? '') === 'yes',
            'autoplay' => ($settings['autoplay'] ?? '') === 'yes' ? [
                'delay' => (int) ($settings['autoplay_speed'] ?: 3000),
                'pauseOnMouseEnter' => ($settings['pause_on_hover'] ?? '') === 'yes',
                'disableOnInteraction' => false,
            ] : false,
            'speed' => (int) ($settings['transition_speed'] ?: 500),
            'spaceBetween' => isset($gap['size']) ? (float) $gap['size'] : 20,
            'spaceBetween_tablet' => isset($gap_tablet['size']) ? (float) $gap_tablet['size'] : 15,
            'spaceBetween_mobile' => isset($gap_mobile['size']) ? (float) $gap_mobile['size'] : 10,
            // Equal-height slides need every slide measured against the tallest one,
            // which Swiper's autoHeight (resizing to the active slide) would undo.
            'autoHeight' => empty($settings['carousel_height']['size']) && ($settings['equal_height'] ?? '') !== 'yes',
            'navigation' => $show_arrows,
            'pagination' => $show_dots,
        ];

        echo '<div class="arfe-carousel swiper" data-swiper-options="' . esc_attr(wp_json_encode($swiper_options)) . '">';
        echo '<div class="swiper-wrapper">';

        foreach ($rows as $index => $row_data) {
            echo '<div class="swiper-slide">';
            $this->render_isolated_row($row_data, $template_id, $fields_mapping, $style_vars_mapping, $index);
            echo '</div>';
        }

        echo '</div>';

        if ($show_arrows) {
            echo '<div class="arfe-nav arfe-nav-prev swiper-button-prev"></div>';
            echo '<div class="arfe-nav arfe-nav-next swiper-button-next"></div>';
        }

        if ($show_dots) {
            echo '<div class="swiper-pagination"></div>';
        }

        echo '</div>';
    }

    private function render_isolated_row($row_data, $template_id, $fields_mapping, $style_vars_mapping, $row_index) {
        $backup_context = $GLOBALS['arfe_repeater_context'] ?? null;
        $GLOBALS['arfe_repeater_context'] = [];

        foreach ($row_data as $key => $value) {
            $GLOBALS['arfe_repeater_context'][$key] = $value;
        }

        if (!empty($fields_mapping) && is_array($fields_mapping)) {
            foreach ($fields_mapping as $map) {
                $subfield_key = $map['subfield_key'] ?? '';
                $dynamic_tag_key = $map['dynamic_tag_key'] ?? '';

                if ($subfield_key && $dynamic_tag_key && isset($row_data[$subfield_key])) {
                    $GLOBALS['arfe_repeater_context'][$dynamic_tag_key] = $row_data[$subfield_key];
                }
            }
        }

        $GLOBALS['arfe_repeater_context']['_current_row_index'] = $row_index;

        // Per-row CSS variables (background/border colors, etc.) - set directly as an
        // inline style on this wrapper. Elementor's own Style-tab controls compile to a
        // single static CSS file shared by every repeated row, so a dynamic tag used
        // there always shows the same, one baked-in value. CSS variables set here, on
        // the other hand, are real per-row inline styles that cascade down into the
        // template markup, so the template can read them with var(--name, fallback).
        $style_vars = '';
        if (!empty($style_vars_mapping) && is_array($style_vars_mapping)) {
            foreach ($style_vars_mapping as $style_map) {
                $var_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $style_map['css_var_name'] ?? '');
                $subfield_key = $style_map['style_subfield_key'] ?? '';

                $var_value = isset($row_data[$subfield_key]) && is_scalar($row_data[$subfield_key])
                    ? preg_replace('/[;{}<>"\\\\]/', '', (string) $row_data[$subfield_key])
                    : '';

                if ($var_name && $subfield_key && $var_value !== '') {
                    $style_vars .= '--' . $var_name . ':' . $var_value . ';';
                }
            }
        }

        echo '<div class="arfe-repeater-item" data-row="' . esc_attr($row_index) . '"' . ($style_vars ? ' style="' . esc_attr($style_vars) . '"' : '') . '>';

        $template_content = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($template_id, false);

        // In case Elementor still inlines any <style>/<link> of its own, strip it -
        // the template's CSS is already printed once, up top, in render().
        $template_content = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $template_content);
        $template_content = preg_replace('#<link\b[^>]*rel=["\']stylesheet["\'][^>]*>#i', '', $template_content);

        echo $template_content;

        echo '</div>';

        if ($backup_context !== null) {
            $GLOBALS['arfe_repeater_context'] = $backup_context;
        } else {
            unset($GLOBALS['arfe_repeater_context']);
        }
    }
}
