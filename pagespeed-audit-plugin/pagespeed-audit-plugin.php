<?php
/*
Plugin Name: PageSpeed Audit Plugin
Description: Fetches Google PageSpeed Insights scores for any URL using shortcode and AJAX.
Version: 1.7
Author: Noman Nadeem
*/

defined('ABSPATH') || exit;

define('PSA_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Returns the Google PageSpeed Insights API key.
 *
 * Looks for a PSA_API_KEY constant first (define it in wp-config.php to keep
 * the key out of the database and out of version control), then falls back to
 * the value saved on Settings -> PageSpeed Audit.
 *
 * @return string Empty string when no key has been configured.
 */
function psa_get_api_key() {
    if ( defined( 'PSA_API_KEY' ) && PSA_API_KEY ) {
        return (string) PSA_API_KEY;
    }
    return trim( (string) get_option( 'psa_api_key', '' ) );
}

/**
 * Registers the settings screen under Settings -> PageSpeed Audit.
 */
add_action('admin_menu', function () {
    add_options_page(
        'PageSpeed Audit',
        'PageSpeed Audit',
        'manage_options',
        'psa-settings',
        'psa_render_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting( 'psa_settings', 'psa_api_key', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ) );
    register_setting( 'psa_settings', 'psa_report_logo_id', array(
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 0,
    ) );
    register_setting( 'psa_settings', 'psa_contact_email', array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_email',
        'default'           => '',
    ) );
});

function psa_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $from_constant = defined( 'PSA_API_KEY' ) && PSA_API_KEY;
    ?>
    <div class="wrap">
        <h1>PageSpeed Audit</h1>
        <p>This plugin calls the Google PageSpeed Insights API. The API is free, but it needs your own key.</p>

        <?php if ( $from_constant ) : ?>
            <div class="notice notice-info inline">
                <p>The API key is currently set by the <code>PSA_API_KEY</code> constant in <code>wp-config.php</code>, which overrides the field below.</p>
            </div>
        <?php elseif ( '' === psa_get_api_key() ) : ?>
            <div class="notice notice-warning inline">
                <p><strong>No API key configured.</strong> Audits will fail until you add one.</p>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'psa_settings' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="psa_api_key">Google API key</label></th>
                    <td>
                        <input type="password" id="psa_api_key" name="psa_api_key" class="regular-text"
                               autocomplete="off" spellcheck="false"
                               value="<?php echo esc_attr( get_option( 'psa_api_key', '' ) ); ?>" />
                        <p class="description">
                            Get one free at <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a> &rarr;
                            Create credentials &rarr; API key, then enable the <em>PageSpeed Insights API</em> for that project.
                            Restrict the key to that single API.
                        </p>
                        <p class="description">
                            To keep the key out of the database, add this to <code>wp-config.php</code> instead:<br />
                            <code>define( 'PSA_API_KEY', 'AIza...' );</code>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="psa_report_logo_id">PDF report logo</label></th>
                    <td>
                        <input type="number" id="psa_report_logo_id" name="psa_report_logo_id" class="small-text" min="0"
                               value="<?php echo esc_attr( (int) get_option( 'psa_report_logo_id', 0 ) ); ?>" />
                        <p class="description">
                            Media library attachment ID of the logo printed at the top of the PDF report.
                            Upload the logo under <strong>Media</strong>, open it, and copy the <code>item=</code> number from the URL.
                            Leave as <code>0</code> to print the report without a logo. Best at roughly 238&times;72 px.
                        </p>
                        <?php
                        $logo_id = (int) get_option( 'psa_report_logo_id', 0 );
                        if ( $logo_id && wp_get_attachment_image_url( $logo_id, 'medium' ) ) {
                            echo '<p>' . wp_get_attachment_image( $logo_id, array( 238, 72 ) ) . '</p>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="psa_contact_email">Contact email on the report</label></th>
                    <td>
                        <input type="email" id="psa_contact_email" name="psa_contact_email" class="regular-text"
                               placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
                               value="<?php echo esc_attr( get_option( 'psa_contact_email', '' ) ); ?>" />
                        <p class="description">Printed in the PDF footer. Leave empty to use the site admin email.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save Settings' ); ?>
        </form>

        <h2>Usage</h2>
        <p>Place the shortcode <code>[pagespeed_audit]</code> on any page to render the audit form.</p>
    </div>
    <?php
}

/**
 * Shows an admin notice while no API key is configured.
 */
add_action('admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) || '' !== psa_get_api_key() ) {
        return;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && 'settings_page_psa-settings' === $screen->id ) {
        return;
    }
    printf(
        '<div class="notice notice-warning"><p><strong>PageSpeed Audit:</strong> no Google API key configured yet. <a href="%s">Add one</a> before running an audit.</p></div>',
        esc_url( admin_url( 'options-general.php?page=psa-settings' ) )
    );
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('psa-style', PSA_PLUGIN_URL . 'assets/style.css');
    wp_enqueue_script('psa-script', PSA_PLUGIN_URL . 'assets/script.js', ['jquery'], null, true);
    wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css');
    wp_localize_script('psa-script', 'psa_ajax', [
        'ajax_url' => admin_url('admin-ajax.php')
    ]);
});

register_activation_hook(__FILE__, 'psa_create_email_table');
function psa_create_email_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'psa_emails';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        email varchar(255) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_shortcode('pagespeed_audit', 'page_speeed_shortcode');
function page_speeed_shortcode() {
    ob_start(); ?>
    <div id="email-popup" style="display: none;">
        <div class="popup-content">
            <h2>Enter your email to continue</h2>
            <input type="email" id="user-email" placeholder="you@example.com" required />
            <button id="submit-email">Continue</button>
        </div>
    </div>
    <div class="pagespeed-container">
        <div class="container">
            <h1>PageSpeed Insights Dashboard</h1>
            <form class="psa-box" id="psa-form">
                <div class="input-group">
                    <input type="text" id="psa-url" name="psa-url" placeholder="Enter website URL" required />
                    <button type="submit" id="psa-submit">Start Audit</button>
                </div>
            </form>
            <div id="loading" class="flex justify-center" style="display: none;">
                <div class="spinner"></div>
            </div>
            <div id="error" class="error"></div>
            <div id="view-tabs" style="display:none;"></div>
            <div id="psa-result"></div>
        </div>
    </div>
    <?php return ob_get_clean();
}

add_action('wp_ajax_psa_save_email', 'psa_save_email');
add_action('wp_ajax_nopriv_psa_save_email', 'psa_save_email');
function psa_save_email() {
    global $wpdb;
    $table = $wpdb->prefix . 'psa_emails';
    $email = sanitize_email($_POST['email']);
    if (!is_email($email)) {
        wp_send_json_error('Invalid email.');
    }
    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE email = %s", $email));
    if (!$exists) {
        $wpdb->insert($table, ['email' => $email]);
    }
    setcookie('psa_user_email', $email, time() + 86400 * 30, "/");
    wp_send_json_success();
}

add_action('wp_ajax_psa_run_audit', 'psa_run_audit');
add_action('wp_ajax_nopriv_psa_run_audit', 'psa_run_audit');
function psa_run_audit() {
    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    if (empty($url)) {
        wp_send_json_error('Missing URL.');
    }

    $api_key = psa_get_api_key();
    if ( '' === $api_key ) {
        wp_send_json_error( 'No Google API key configured. An administrator must add one under Settings → PageSpeed Audit.' );
    }

    $strategies = ['desktop', 'mobile'];
    $results = [];

    foreach ($strategies as $strategy) {
        $api_url = "https://www.googleapis.com/pagespeedonline/v5/runPagespeed"
            . "?url=" . urlencode($url)
            . "&strategy={$strategy}"
            . "&category=performance"
            . "&category=accessibility"
            . "&category=best-practices"
            . "&category=seo"
            . "&key={$api_key}";

        $response = wp_remote_get($api_url, ['timeout' => 200]);
        if (is_wp_error($response)) {
            wp_send_json_error('API Error: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!isset($data['lighthouseResult']['categories'])) {
            wp_send_json_error('Invalid API response structure.');
        }

        $categories = $data['lighthouseResult']['categories'];
        $audits = $data['lighthouseResult']['audits'];

        $scores = [
            'Performance'       => round($categories['performance']['score'] * 100),
            'Accessibility'     => round($categories['accessibility']['score'] * 100),
            'Best Practices'    => round($categories['best-practices']['score'] * 100),
            'SEO'               => round($categories['seo']['score'] * 100),
        ];

        // Extract all Core Web Vitals shown on frontend
        $metrics = [
            'First Contentful Paint (FCP)'  => $audits['first-contentful-paint']['displayValue'] ?? 'N/A',
            'Largest Contentful Paint (LCP)' => $audits['largest-contentful-paint']['displayValue'] ?? 'N/A',
            'Total Blocking Time (TBT)'     => $audits['total-blocking-time']['displayValue'] ?? 'N/A',
            'Cumulative Layout Shift (CLS)' => $audits['cumulative-layout-shift']['displayValue'] ?? 'N/A',
            'Speed Index'                   => $audits['speed-index']['displayValue'] ?? 'N/A',
            // 'Interaction to Next Paint (INP)' => $audits['interaction-to-next-paint']['displayValue'] ?? 'N/A'
        ];

        $results[$strategy] = [
            'scores'  => $scores,
            'metrics' => $metrics
        ];
    }

    $email = $_COOKIE['psa_user_email'] ?? null;
    if ($email && is_email($email)) {
        $pdf_path = psa_generate_pdf($results['desktop']['scores'], $results['desktop']['metrics'], $url);
        $subject = 'Your PageSpeed Audit Report';
        $message = 'Hello,<br><br>Please find your PageSpeed Insights audit report attached.<br><br>Thank you!';
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($email, $subject, $message, $headers, [$pdf_path]);

        if (file_exists($pdf_path)) {
            unlink($pdf_path);
        }
    }

    wp_send_json_success($results);
}

require_once __DIR__ . '/dompdf/autoload.inc.php';
use Dompdf\Dompdf;

function psa_generate_pdf($scores, $metrics, $url = null) {
    $domain = $url ? parse_url($url, PHP_URL_HOST) : 'website';
    $timestamp = time();
    $date_string = date('F j, Y, g:i A', $timestamp);

    // Logo shown at the top of the PDF. Set one under Settings -> PageSpeed Audit;
    // leave it empty and the report simply prints without a logo.
    $banner_base64 = '';
    $logo_id = (int) get_option('psa_report_logo_id', 0);
    $banner_path = $logo_id ? get_attached_file($logo_id) : '';
    if ($banner_path && file_exists($banner_path)) {
        $banner_data = file_get_contents($banner_path);
        $banner_type = pathinfo($banner_path, PATHINFO_EXTENSION);
        $banner_base64 = 'data:image/' . $banner_type . ';base64,' . base64_encode($banner_data);
    }

    $style = '
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #333; margin: 0; padding: 0; }
        .psa-report { padding: 30px; }
        .banner { text-align: center; margin-bottom: 20px; }
        .banner img { width: 238px; height: 72px; }
        .title { text-align: center; }
        .title h1 { font-size: 22px; color: #257FBF; margin-bottom: 5px; }
        .title p { font-size: 14px; margin: 0; color: #555; }
        table { width: 90%; margin: 20px auto; border-collapse: collapse; font-size: 15px; }
        th, td { border: 1px solid #ccc; padding: 10px; }
        th { background: #f0f8ff; color: #257FBF; font-weight: bold; text-align: left; }
        td.score, td.value { text-align: center; font-weight: bold; color: #257FBF; }
        .footer { margin-top: 40px; text-align: center; font-size: 13px; color: #555; }
        .footer hr { width: 90%; margin: 20px auto; border-top: 1px solid #ccc; }
    </style>';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>PageSpeed Audit Report</title>' . $style . '</head><body>';
    $html .= '<div class="psa-report">';
    if ($banner_base64) {
        $html .= '<div class="banner"><img src="' . $banner_base64 . '" alt="Banner" /></div>';
    }
    $html .= '<div class="title"><h1>PageSpeed Audit Report</h1><p>Domain: <strong>' . htmlspecialchars($domain) . '</strong></p><p>Date: ' . $date_string . '</p></div>';

    // Main Scores Table
    $html .= '<table><tr><th>Category</th><th style="text-align:center;">Score</th></tr>';
    foreach ($scores as $key => $value) {
        $html .= '<tr><td>' . htmlspecialchars($key) . '</td><td class="score">' . htmlspecialchars($value) . '</td></tr>';
    }
    $html .= '</table>';

    // Core Web Vitals Table
    $html .= '<table><tr><th>Core Web Vital</th><th style="text-align:center;">Value</th></tr>';
    foreach ($metrics as $key => $value) {
        $html .= '<tr><td>' . htmlspecialchars($key) . '</td><td class="value">' . htmlspecialchars($value) . '</td></tr>';
    }
    $html .= '</table>';

    $contact_email = sanitize_email( get_option( 'psa_contact_email', get_option( 'admin_email' ) ) );
    if ( $contact_email ) {
        $html .= '<div class="footer"><hr /><p>If you have any questions, contact us at <a href="mailto:' . esc_attr( $contact_email ) . '">' . esc_html( $contact_email ) . '</a></p></div>';
    }
    $html .= '</div></body></html>';

    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $upload_dir = wp_upload_dir();
    $pdf_path = $upload_dir['path'] . "/report-{$domain}-{$timestamp}.pdf";
    file_put_contents($pdf_path, $dompdf->output());

    return $pdf_path;
}

add_action('admin_menu', function () {
    add_menu_page(
        'PageSpeed Emails',
        'PageSpeed Emails',
        'manage_options',
        'psa-export-emails',
        'psa_export_emails_page',
        'dashicons-download',
        80
    );
});

function psa_export_emails_page() {
    $export_url = admin_url('admin-post.php?action=psa_export_csv'); ?>
    <div class="wrap">
        <h1>Export Submitted Emails</h1>
        <a href="<?php echo esc_url($export_url); ?>" class="button button-primary">Download Emails (CSV)</a>
    </div>
<?php }

add_action('admin_post_psa_export_csv', 'psa_download_email_csv');
function psa_download_email_csv() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }
    global $wpdb;
    $table = $wpdb->prefix . 'psa_emails';
    $results = $wpdb->get_results("SELECT email, created_at FROM $table", ARRAY_A);
    if (empty($results)) {
        wp_die('No emails found.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=psa_emails_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Email', 'Submitted At']);
    foreach ($results as $row) {
        fputcsv($output, [$row['email'], $row['created_at']]);
    }
    fclose($output);
    exit;
}
