<?php
if (!defined('ABSPATH')) exit;

class Redirection_Import {
    const CAP   = 'manage_options';
    const NONCE = 'redirection_import';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_import_page']);
        add_action('admin_post_upload_redirections', [$this, 'handle_file_upload']);
        add_action('admin_notices', [$this, 'maybe_show_notice']);
    }

    public function add_import_page() {
        add_submenu_page(
            'edit.php?post_type=redirection_url',
            'Bulk Upload',
            'Bulk Upload',
            self::CAP,
            'redirection-import',
            [$this, 'upload_page_html']
        );
    }

    public function upload_page_html() {
        if (!current_user_can(self::CAP)) return;
        ?>
        <div class="wrap">
            <h1>Bulk Upload Redirects</h1>

            <p>Upload a <strong>CSV</strong> file with two columns and <strong>no header row</strong>:
               the old URL in the first column, the new URL in the second.</p>

            <p>If you built the list in Excel or Google Sheets, use <em>File &rarr; Save as / Download &rarr; CSV</em>
               first &mdash; a real <code>.xlsx</code> file cannot be read.</p>

            <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:760px;overflow:auto;">https://example.com/old-page/,https://example.com/new-page/
https://example.com/blog/2019/legacy-post/,https://example.com/blog/new-post/</pre>

            <p class="description">
                Old URLs must be <strong>absolute</strong> and must match how visitors actually arrive
                (same scheme and host). Re-uploading the same file updates existing destinations rather
                than creating duplicates.
            </p>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="upload_redirections" />
                <?php wp_nonce_field(self::NONCE); ?>
                <p><input type="file" name="file" accept=".csv,text/csv" required /></p>
                <?php submit_button('Upload & Process'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Shows the result of the last import.
     */
    public function maybe_show_notice() {
        if (!isset($_GET['redirection_import'])) return;
        $status  = sanitize_key(wp_unslash($_GET['redirection_import']));
        $added   = isset($_GET['added'])   ? absint($_GET['added'])   : 0;
        $updated = isset($_GET['updated']) ? absint($_GET['updated']) : 0;
        $skipped = isset($_GET['skipped']) ? absint($_GET['skipped']) : 0;

        if ('done' === $status) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>Import finished: <strong>%d</strong> added, <strong>%d</strong> updated, <strong>%d</strong> skipped.</p></div>',
                $added, $updated, $skipped
            );
        } elseif ('nofile' === $status) {
            echo '<div class="notice notice-error is-dismissible"><p>No file was uploaded.</p></div>';
        } elseif ('unreadable' === $status) {
            echo '<div class="notice notice-error is-dismissible"><p>That file could not be read. Make sure it is a plain CSV, not an .xlsx workbook.</p></div>';
        }
    }

    public function handle_file_upload() {
        if (!current_user_can(self::CAP)) {
            wp_die('You are not allowed to import redirects.', 'Forbidden', ['response' => 403]);
        }
        check_admin_referer(self::NONCE);

        $back = admin_url('edit.php?post_type=redirection_url');

        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            wp_safe_redirect(add_query_arg('redirection_import', 'nofile', $back));
            exit;
        }
        if (!empty($_FILES['file']['error'])) {
            wp_safe_redirect(add_query_arg('redirection_import', 'unreadable', $back));
            exit;
        }

        $lines = @file($_FILES['file']['tmp_name'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            wp_safe_redirect(add_query_arg('redirection_import', 'unreadable', $back));
            exit;
        }

        $added = $updated = $skipped = 0;

        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (!is_array($row) || count($row) < 2) { $skipped++; continue; }

            $old_url = Redirection_Handler::clean_url($row[0]);
            $new_url = Redirection_Handler::clean_url($row[1]);

            // Drops blank rows and a header row such as "Old URL,New URL".
            if ('' === $old_url || '' === $new_url) { $skipped++; continue; }
            if (!Redirection_Handler::is_valid_target($new_url)) { $skipped++; continue; }

            $existing = get_posts([
                'post_type'      => 'redirection_url',
                'meta_key'       => '_old_url',
                'meta_value'     => $old_url,
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);

            if ($existing) {
                update_post_meta($existing[0], '_new_url', $new_url);
                $updated++;
            } else {
                $id = wp_insert_post([
                    'post_type'   => 'redirection_url',
                    'post_status' => 'publish',
                    'post_title'  => $old_url,
                    'meta_input'  => [
                        '_old_url' => $old_url,
                        '_new_url' => $new_url,
                    ],
                ]);
                if ($id && !is_wp_error($id)) { $added++; } else { $skipped++; }
            }
        }

        Redirection_Handler::flush_cache();

        wp_safe_redirect(add_query_arg([
            'redirection_import' => 'done',
            'added'              => $added,
            'updated'            => $updated,
            'skipped'            => $skipped,
        ], $back));
        exit;
    }
}
