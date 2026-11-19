<?php

namespace AcfRepeaterForElementor\Tags;

if (!defined('ABSPATH')) {
    exit;
}

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Data_Tag;

if (class_exists(__NAMESPACE__ . '\\ARFE_Subfield_Tag')) {
    return;
}

/**
 * Shared behaviour for every repeater subfield tag: one "Subfield Key" control and
 * a lookup in the row context that the ACF Repeater widget sets while it
 * renders each row.
 *
 * Built on Elementor core's Data_Tag (not Elementor Pro's), so the tags work on
 * sites that only have the free Elementor plugin.
 */
abstract class ARFE_Base_Subfield_Tag extends Data_Tag {

    public function get_group() {
        return 'acf';
    }

    protected function register_controls() {
        $this->add_control('key', [
            'label' => __('Subfield Key', 'acf-repeater-for-elementor'),
            'type' => Controls_Manager::TEXT,
            'description' => __('Subfield name, or _row_number / _row_index for the row position.', 'acf-repeater-for-elementor'),
        ]);
    }

    /**
     * Raw value of the configured subfield for the row being rendered, or null.
     */
    protected function get_context_value() {
        $key = $this->get_settings('key');

        if ($key === null || $key === '' || !isset($GLOBALS['arfe_repeater_context'][$key])) {
            return null;
        }

        return $GLOBALS['arfe_repeater_context'][$key];
    }

    /**
     * Normalise an ACF image value (array, attachment ID or URL) to Elementor's
     * ['id' => int, 'url' => string] shape.
     */
    protected static function to_image($value) {
        if (is_array($value) && isset($value['url'])) {
            return [
                'id' => (int) ($value['ID'] ?? ($value['id'] ?? 0)),
                'url' => (string) $value['url'],
            ];
        }

        if (is_numeric($value) && (int) $value > 0) {
            $url = wp_get_attachment_image_url((int) $value, 'full');
            return $url ? ['id' => (int) $value, 'url' => $url] : null;
        }

        if (is_string($value) && $value !== '') {
            return ['id' => (int) attachment_url_to_postid($value), 'url' => $value];
        }

        return null;
    }
}

/**
 * Text, URL, colour and number subfields.
 */
class ARFE_Subfield_Tag extends ARFE_Base_Subfield_Tag {

    public function get_name() {
        return 'arfe_repeater_subfield';
    }

    public function get_title() {
        return __('Repeater Subfield', 'acf-repeater-for-elementor');
    }

    public function get_categories() {
        // Plain string literals (not the Manager::*_CATEGORY constants) so this
        // keeps working across Elementor versions that moved/renamed that class.
        return ['text', 'url', 'color', 'number', 'post_meta'];
    }

    public function get_value(array $options = []) {
        $value = $this->get_context_value();

        if ($value === null || $value === false) {
            return '';
        }

        if (!is_array($value)) {
            return $value;
        }

        // Image / file / link arrays expose a URL; select arrays expose a label.
        if (isset($value['url'])) {
            return $value['url'];
        }
        if (isset($value['label'])) {
            return $value['label'];
        }

        // Plain lists (checkbox, multi-select) read as a comma-separated string.
        return implode(', ', array_filter($value, 'is_scalar'));
    }
}

/**
 * Image subfields, for Image widgets and background images.
 */
class ARFE_Subfield_Image_Tag extends ARFE_Base_Subfield_Tag {

    public function get_name() {
        return 'arfe_repeater_subfield_image';
    }

    public function get_title() {
        return __('Repeater Image', 'acf-repeater-for-elementor');
    }

    public function get_categories() {
        return ['image'];
    }

    public function get_value(array $options = []) {
        return self::to_image($this->get_context_value()) ?: ['id' => 0, 'url' => ''];
    }
}

/**
 * Gallery subfields, for Gallery / Basic Gallery widgets.
 */
class ARFE_Subfield_Gallery_Tag extends ARFE_Base_Subfield_Tag {

    public function get_name() {
        return 'arfe_repeater_subfield_gallery';
    }

    public function get_title() {
        return __('Repeater Gallery', 'acf-repeater-for-elementor');
    }

    public function get_categories() {
        return ['gallery'];
    }

    public function get_value(array $options = []) {
        $value = $this->get_context_value();

        if (!is_array($value)) {
            return [];
        }

        $images = [];
        foreach ($value as $item) {
            $image = self::to_image($item);
            if ($image && $image['id']) {
                $images[] = ['id' => $image['id']];
            }
        }

        return $images;
    }
}
