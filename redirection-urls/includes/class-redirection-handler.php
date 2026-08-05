<?php
if (!defined('ABSPATH')) exit;

class Redirection_Handler {
    const CACHE_KEY = 'redirection_urls_map';

    public function __construct() {
        add_action('template_redirect', [$this, 'maybe_redirect']);
        // Keep the cached map in step with edits.
        add_action('save_post_redirection_url', [__CLASS__, 'flush_cache']);
        add_action('deleted_post', [__CLASS__, 'flush_cache']);
        add_action('trashed_post', [__CLASS__, 'flush_cache']);
        add_action('untrashed_post', [__CLASS__, 'flush_cache']);
    }

    /**
     * Normalises a URL for comparison: trims, lowercases the scheme+host,
     * and forces a trailing slash on the path.
     */
    public static function clean_url($url) {
        $url = trim((string) wp_unslash($url));
        if ('' === $url) return '';
        // Strip a UTF-8 BOM that spreadsheet exports often prepend.
        $url = preg_replace('/^\xEF\xBB\xBF/', '', $url);
        return $url;
    }

    public static function normalise($url) {
        $url = self::clean_url($url);
        if ('' === $url) return '';
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            // Relative path: make it absolute against this site.
            $url   = home_url('/' . ltrim($url, '/'));
            $parts = wp_parse_url($url);
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $host   = isset($parts['host'])   ? strtolower($parts['host'])   : '';
        $path   = isset($parts['path'])   ? $parts['path']               : '/';
        $query  = isset($parts['query'])  ? '?' . $parts['query']        : '';
        // Scheme is deliberately dropped so http/https both match.
        unset($scheme);
        return strtolower($host) . trailingslashit($path) . $query;
    }

    /**
     * Only http(s) destinations are allowed, so a redirect can never be turned
     * into a javascript: or data: payload.
     */
    public static function is_valid_target($url) {
        $url = self::clean_url($url);
        if ('' === $url) return false;
        if (0 === strpos($url, '/')) return true; // site-relative is fine
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    public static function flush_cache() {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Builds (and caches) a normalised old-URL => [new_url, code] map so the
     * front end does not query every redirect post on every page view.
     */
    public static function get_map() {
        $map = get_transient(self::CACHE_KEY);
        if (is_array($map)) return $map;

        $map = [];
        $ids = get_posts([
            'post_type'      => 'redirection_url',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        foreach ($ids as $id) {
            $old = get_post_meta($id, '_old_url', true);
            $new = get_post_meta($id, '_new_url', true);
            if ('' === $old || '' === $new) continue;
            $code = (int) get_post_meta($id, '_redirect_type', true);
            if (!in_array($code, [301, 302, 307], true)) $code = 301;
            $map[self::normalise($old)] = ['url' => $new, 'code' => $code];
        }

        set_transient(self::CACHE_KEY, $map, DAY_IN_SECONDS);
        return $map;
    }

    public function maybe_redirect() {
        if (is_admin()) return;

        $map = self::get_map();
        if (empty($map)) return;

        $request = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $current = self::normalise(home_url($request));

        if (!isset($map[$current])) return;

        $target = $map[$current]['url'];
        $code   = $map[$current]['code'];

        if (!self::is_valid_target($target)) return;

        // Never redirect a URL to itself — that is an infinite loop.
        if (self::normalise($target) === $current) return;

        wp_redirect($target, $code);
        exit;
    }
}
