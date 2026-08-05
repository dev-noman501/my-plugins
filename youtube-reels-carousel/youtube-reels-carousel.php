<?php
/**
 * Plugin Name: YouTube Reels Carousel
 * Description: Full-width YouTube Reels carousel (5 per row, scroll 2) with autoplay and lightbox center-mode. Shortcode: [youtube_reels]
 * Version: 1.5.0
 * Author: Noman Nadeem
 * Text Domain: yrc-reels
 */

if (!defined('ABSPATH')) exit;

define('YRC_REELS_VERSION', '1.5.0');
define('YRC_REELS_DIR', plugin_dir_path(__FILE__));
define('YRC_REELS_URL', plugin_dir_url(__FILE__));

/**
 * Register CPT
 */
function yrc_reels_register_post_type() {
    $labels = array(
        'name'          => 'Reels',
        'singular_name' => 'Reel',
        'menu_name'     => 'YouTube Reels Carousel',
    );
    $args = array(
        'labels'            => $labels,
        'public'            => true,
        'has_archive'       => false,
        'supports'          => array('title','editor','thumbnail'),
        'show_in_rest'      => true,
        'menu_icon'         => 'dashicons-video-alt3',
    );
    register_post_type('yrc_reel', $args);
}
add_action('init', 'yrc_reels_register_post_type');

/**
 * Enqueue assets (Slick + plugin CSS/JS)
 */
function yrc_reels_enqueue_assets() {
    // Slick CSS/JS (CDN)
    wp_enqueue_style('slick-css', 'https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.8.1/slick.min.css', array(), '1.8.1');
    wp_enqueue_style('slick-theme-css', 'https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.8.1/slick-theme.min.css', array('slick-css'), '1.8.1');
    wp_enqueue_script('slick-js', 'https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.8.1/slick.min.js', array('jquery'), '1.8.1', true);

    // Plugin CSS/JS
    wp_enqueue_style('yrc-reels-css', YRC_REELS_URL . 'assets/css/yrc-reels.css', array('slick-css','slick-theme-css'), YRC_REELS_VERSION);
    wp_enqueue_script('yrc-reels-js', YRC_REELS_URL . 'assets/js/yrc-reels.js', array('jquery','slick-js'), YRC_REELS_VERSION, true);

    wp_localize_script('yrc-reels-js', 'YRC_REELS', array(
        'autoplay'       => true,
        'autoplaySpeed'  => 3500,
        'centerPadding'  => '12%', // lightbox center padding
    ));
}
add_action('wp_enqueue_scripts', 'yrc_reels_enqueue_assets');

/**
 * Robust YouTube ID extractor — supports watch, youtu.be, shorts, embed, or pasted <iframe>
 */
if (!function_exists('yrc_reels_get_youtube_id')) {
    function yrc_reels_get_youtube_id($input) {
        if (empty($input)) return '';

        // If an iframe was pasted, extract its src first
        if (strpos($input, '<iframe') !== false && preg_match('/src="([^"]+)"/i', $input, $m)) {
            $input = $m[1];
        }

        // Normalize shorts to watch?v=
        $input = str_replace('youtube.com/shorts/', 'youtube.com/watch?v=', $input);
        $input = trim($input);

        $parts = @parse_url($input);

        // youtu.be/<id>
        if (!empty($parts['host']) && stripos($parts['host'], 'youtu.be') !== false) {
            $path = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
            if (preg_match('/^[A-Za-z0-9_-]{11}$/', $path)) return $path;
        }

        // v= param
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $qs);
            if (!empty($qs['v']) && preg_match('/^[A-Za-z0-9_-]{11}$/', $qs['v'])) return $qs['v'];
        }

        // /embed/<id>
        if (!empty($parts['path']) && preg_match('#/embed/([A-Za-z0-9_-]{11})#', $parts['path'], $m2)) {
            return $m2[1];
        }

        // Fallback: first 11-char token anywhere
        if (preg_match('/([A-Za-z0-9_-]{11})/', $input, $m3)) return $m3[1];

        return '';
    }
}

/**
 * Shortcode: [yrc_reels posts_per_page="-1"]
 * Uses ACF field `video_url` (URL or iframe). Ignores thumbnail/title visually.
 */
// Convert "W:H" (e.g., "9:16" or "16:9") to padding-top percentage
if (!function_exists('yrc_reels_aspect_padding')) {
    function yrc_reels_aspect_padding($aspect) {
        if (preg_match('/^\s*(\d+)\s*:\s*(\d+)\s*$/', $aspect, $m)) {
            $w = max(1, intval($m[1]));
            $h = max(1, intval($m[2]));
            return round(($h / $w) * 100, 5) . '%';
        }
        // default to vertical reels feel
        return '177.77778%'; // 9:16
    }
}

function yrc_reels_shortcode($atts) {
    $atts = shortcode_atts(array(
        'posts_per_page' => -1,
        // NEW: controls visual height
        // Examples: "9:16" (tall), "9:14" (shorter), "16:9" (landscape)
        'aspect'         => '9:16',
        // NEW: scales the iframe to "cover" the container (cropping top/bottom a bit),
        // so you don't see letterboxing. 1 = no crop. 1.08–1.18 looks good for 9:14.
        'cover_scale'    => '1',  // string because shortcode
    ), $atts, 'yrc_reels');

    // Convert aspect to CSS padding-top %
    if (!function_exists('yrc_reels_aspect_padding')) {
        function yrc_reels_aspect_padding($aspect) {
            if (preg_match('/^\s*(\d+)\s*:\s*(\d+)\s*$/', $aspect, $m)) {
                $w = max(1, (int)$m[1]); $h = max(1, (int)$m[2]);
                return round(($h / $w) * 100, 5) . '%';
            }
            return '177.77778%'; // fallback to 9:16
        }
    }

    $padding = yrc_reels_aspect_padding($atts['aspect']);
    $cover_scale = floatval($atts['cover_scale']);
    if ($cover_scale < 1) $cover_scale = 1;  // safety

    $q = new WP_Query(array(
        'post_type'      => 'yrc_reel',
        'posts_per_page' => (int)$atts['posts_per_page'],
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ));

    ob_start(); ?>
    <div class="yrc-reels-wrap"
         data-aspect="<?php echo esc_attr($atts['aspect']); ?>"
         style="--reel-padding: <?php echo esc_attr($padding); ?>; --reel-cover-scale: <?php echo esc_attr($cover_scale); ?>;">
        <div class="yrc-reels-carousel">
            <?php if ($q->have_posts()) : while ($q->have_posts()) : $q->the_post();
                $video_url = function_exists('get_field') ? get_field('video_url') : '';
                $video_id  = yrc_reels_get_youtube_id($video_url);
                if (!$video_id) continue;

                $inline_embed = 'https://www.youtube-nocookie.com/embed/' . $video_id .
                    '?autoplay=1&mute=1&rel=0&modestbranding=1&playsinline=1' .
                    '&controls=0&disablekb=1&fs=0&iv_load_policy=3' .
                    '&loop=1&playlist=' . $video_id . '&enablejsapi=1';
                ?>
                <div class="yrc-reel-item" data-video-id="<?php echo esc_attr($video_id); ?>" data-video-url="<?php echo esc_attr($video_url); ?>">
                    <div class="yrc-reel-thumb">
                        <div class="yrc-iframe-wrap">
                            <iframe
                                src="<?php echo esc_url($inline_embed); ?>"
                                allow="autoplay; encrypted-media"
                                loading="lazy"
                                referrerpolicy="origin-when-cross-origin"
                                allowfullscreen
                                frameborder="0"></iframe>
                        </div>
                        <button class="yrc-play-button" aria-label="Open in lightbox">▶</button>
                    </div>
                </div>
            <?php endwhile; wp_reset_postdata(); else: ?>
                <p class="yrc-no-reels">No reels found.</p>
            <?php endif; ?>
        </div>

        <div class="yrc-prev" aria-label="Previous">‹</div>
        <div class="yrc-next" aria-label="Next">›</div>
    </div>

    <!-- Lightbox (unchanged) -->
    <div id="yrc-reels-lightbox" class="yrc-lightbox" aria-hidden="true">
        <div class="yrc-lightbox-inner">
            <div class="yrc-lightbox-close" aria-label="Close">×</div>
            <div class="yrc-lightbox-slider"></div>
            <div class="yrc-lightbox-prev" aria-label="Prev">&#x2039;</div>
            <div class="yrc-lightbox-next" aria-label="Next">&#x203A;</div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('youtube_reels', 'yrc_reels_shortcode');
// Older name kept so existing pages keep rendering.
add_shortcode('metaviz_reels', 'yrc_reels_shortcode');

// (Optional helper file hook)
if (file_exists(YRC_REELS_DIR . 'inc/templates.php')) {
    require_once YRC_REELS_DIR . 'inc/templates.php';
}
