<?php
if (!defined('ABSPATH')) exit;

class Redirection_Admin {
    const NONCE = 'redirection_save_meta';

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post_redirection_url', [$this, 'save_redirect_data'], 10, 2);

        // Show the destination and the redirect type in the list table.
        add_filter('manage_redirection_url_posts_columns', [$this, 'columns']);
        add_action('manage_redirection_url_posts_custom_column', [$this, 'column_content'], 10, 2);
    }

    public function add_meta_box() {
        add_meta_box('redirect_details', 'Redirect', [$this, 'meta_box_callback'], 'redirection_url', 'normal', 'high');
    }

    public function meta_box_callback($post) {
        $old_url = get_post_meta($post->ID, '_old_url', true);
        $new_url = get_post_meta($post->ID, '_new_url', true);
        $type    = (int) get_post_meta($post->ID, '_redirect_type', true);
        if (!in_array($type, [301, 302, 307], true)) $type = 301;

        wp_nonce_field(self::NONCE, 'redirection_nonce');
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="rd_old_url">Old URL</label></th>
                <td>
                    <input type="text" id="rd_old_url" name="old_url" class="widefat"
                           value="<?php echo esc_attr($old_url); ?>"
                           placeholder="<?php echo esc_attr(home_url('/old-page/')); ?>" />
                    <p class="description">The address visitors arrive on. A full URL or a site-relative path
                       such as <code>/old-page/</code>. Matching ignores http vs https, letter case and a
                       trailing slash.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="rd_new_url">New URL</label></th>
                <td>
                    <input type="text" id="rd_new_url" name="new_url" class="widefat"
                           value="<?php echo esc_attr($new_url); ?>"
                           placeholder="<?php echo esc_attr(home_url('/new-page/')); ?>" />
                    <p class="description">Where to send them. Must be <code>http</code>, <code>https</code>,
                       or a site-relative path.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="rd_type">Redirect type</label></th>
                <td>
                    <select id="rd_type" name="redirect_type">
                        <option value="301" <?php selected($type, 301); ?>>301 &mdash; Permanent (passes SEO value)</option>
                        <option value="302" <?php selected($type, 302); ?>>302 &mdash; Temporary</option>
                        <option value="307" <?php selected($type, 307); ?>>307 &mdash; Temporary, keeps the request method</option>
                    </select>
                    <p class="description">Use 301 for a permanent move. Browsers cache 301s hard, so test in a private window.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_redirect_data($post_id, $post = null) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!isset($_POST['redirection_nonce']) || !wp_verify_nonce(wp_unslash($_POST['redirection_nonce']), self::NONCE)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['old_url'])) {
            update_post_meta($post_id, '_old_url', Redirection_Handler::clean_url(sanitize_text_field(wp_unslash($_POST['old_url']))));
        }
        if (isset($_POST['new_url'])) {
            $new = Redirection_Handler::clean_url(sanitize_text_field(wp_unslash($_POST['new_url'])));
            // Reject anything that is not http(s) or site-relative.
            update_post_meta($post_id, '_new_url', Redirection_Handler::is_valid_target($new) ? $new : '');
        }
        if (isset($_POST['redirect_type'])) {
            $type = (int) $_POST['redirect_type'];
            update_post_meta($post_id, '_redirect_type', in_array($type, [301, 302, 307], true) ? $type : 301);
        }

        Redirection_Handler::flush_cache();
    }

    public function columns($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ('title' === $key) {
                $new['rd_old']  = 'Old URL';
                $new['rd_new']  = 'Redirects to';
                $new['rd_type'] = 'Type';
            }
        }
        return $new;
    }

    public function column_content($column, $post_id) {
        if ('rd_old' === $column) {
            echo esc_html(get_post_meta($post_id, '_old_url', true));
        } elseif ('rd_new' === $column) {
            $new = get_post_meta($post_id, '_new_url', true);
            if ($new) {
                printf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($new), esc_html($new));
            } else {
                echo '<span style="color:#d63638;">not set</span>';
            }
        } elseif ('rd_type' === $column) {
            $type = (int) get_post_meta($post_id, '_redirect_type', true);
            echo esc_html(in_array($type, [301, 302, 307], true) ? $type : 301);
        }
    }
}
