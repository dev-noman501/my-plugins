<?php
/*
Plugin Name: App Auth & Commerce API
Description: A ready-made REST API for mobile apps on top of WordPress + WooCommerce — registration, login, password reset, products, categories, content, cart, vouchers and checkout. Delivery zones and fees are configurable, so it works on any store.
Version: 1.1.0
Requires at least: 5.7
Requires PHP: 7.0
Author: Noman Nadeem
License: GPL-2.0-or-later
Text Domain: app-auth-api
*/

  if (!defined('ABSPATH')) exit;

  // -------- CONFIG --------
  if (!defined('APP_REGISTER_DEFAULT_ROLE')) {
    define('APP_REGISTER_DEFAULT_ROLE', 'customer');
  }

  /**
   * Settings accessor. Everything that used to be hardcoded to one store now
   * lives in the `app_api_settings` option, editable under Settings -> App API.
   */
  function app_api_settings() {
    $defaults = array(
      'delivery_fee'          => 0,      // flat fee applied to every delivery order
      'free_shipping_over'    => 0,      // 0 disables the free-shipping threshold
      'shipping_zones'        => '',     // one "Zone name|fee" per line
      'require_known_zone'    => 0,      // 1 = reject locations missing from the list
      'content_post_types'    => 'post', // comma separated, used by /content
      'force_country'         => '',     // leave empty to use whatever the customer sends
      'force_state'           => '',
      'force_city'            => '',
      'expose_post_meta'      => 0,      // 0 = never expose raw product post_meta
    );
    $saved = get_option('app_api_settings', array());
    return wp_parse_args(is_array($saved) ? $saved : array(), $defaults);
  }

  function app_api_setting($key, $fallback = null) {
    $all = app_api_settings();
    return array_key_exists($key, $all) ? $all[$key] : $fallback;
  }

  /**
   * Parses the "Zone name|fee" textarea into an array of name => fee.
   * An empty list means "deliver anywhere at the flat delivery fee".
   *
   * @return array<string,float>
   */
  function app_api_shipping_zones() {
    $zones = array();
    $raw   = (string) app_api_setting('shipping_zones', '');
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
      $line = trim($line);
      if ($line === '' || strpos($line, '|') === false) continue;
      list($name, $fee) = array_map('trim', explode('|', $line, 2));
      if ($name === '') continue;
      $zones[$name] = (float) $fee;
    }
    return apply_filters('app_api_shipping_zones', $zones);
  }

  // -------- SETTINGS SCREEN --------
  add_action('admin_menu', function () {
    add_options_page('App API', 'App API', 'manage_options', 'app-api-settings', 'app_api_render_settings');
  });

  add_action('admin_init', function () {
    register_setting('app_api_settings_group', 'app_api_settings', array(
      'type'              => 'array',
      'sanitize_callback' => 'app_api_sanitize_settings',
      'default'           => array(),
    ));
  });

  function app_api_sanitize_settings($in) {
    $in = is_array($in) ? $in : array();
    return array(
      'delivery_fee'       => isset($in['delivery_fee']) ? (float) $in['delivery_fee'] : 0,
      'free_shipping_over' => isset($in['free_shipping_over']) ? (float) $in['free_shipping_over'] : 0,
      'shipping_zones'     => isset($in['shipping_zones']) ? sanitize_textarea_field($in['shipping_zones']) : '',
      'require_known_zone' => empty($in['require_known_zone']) ? 0 : 1,
      'content_post_types' => isset($in['content_post_types']) ? sanitize_text_field($in['content_post_types']) : 'post',
      'force_country'      => isset($in['force_country']) ? strtoupper(sanitize_text_field($in['force_country'])) : '',
      'force_state'        => isset($in['force_state']) ? strtoupper(sanitize_text_field($in['force_state'])) : '',
      'force_city'         => isset($in['force_city']) ? sanitize_text_field($in['force_city']) : '',
      'expose_post_meta'   => empty($in['expose_post_meta']) ? 0 : 1,
    );
  }

  function app_api_render_settings() {
    if (!current_user_can('manage_options')) return;
    $s = app_api_settings();
    ?>
    <div class="wrap">
      <h1>App API</h1>
      <p>Endpoints live under <code><?php echo esc_html( rest_url('custom/v1') ); ?></code>.
         Everything below used to be hardcoded &mdash; set it to match your own store.</p>

      <?php if (!class_exists('WooCommerce')) : ?>
        <div class="notice notice-warning inline"><p>
          <strong>WooCommerce is not active.</strong> Auth and content endpoints still work;
          product, cart, voucher and checkout endpoints need WooCommerce.
        </p></div>
      <?php endif; ?>

      <form method="post" action="options.php">
        <?php settings_fields('app_api_settings_group'); ?>
        <h2>Delivery</h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="app_delivery_fee">Flat delivery fee</label></th>
            <td>
              <input type="number" step="0.01" min="0" id="app_delivery_fee" class="small-text"
                     name="app_api_settings[delivery_fee]" value="<?php echo esc_attr($s['delivery_fee']); ?>" />
              <p class="description">Added to every delivery order, in the store currency. <code>0</code> for none.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="app_free_over">Free shipping over</label></th>
            <td>
              <input type="number" step="0.01" min="0" id="app_free_over" class="small-text"
                     name="app_api_settings[free_shipping_over]" value="<?php echo esc_attr($s['free_shipping_over']); ?>" />
              <p class="description">Zero the zone fee once the cart subtotal reaches this. <code>0</code> disables the threshold.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="app_zones">Delivery zones</label></th>
            <td>
              <textarea id="app_zones" name="app_api_settings[shipping_zones]" rows="8" class="large-text code"
                        placeholder="City Centre|0&#10;North Side|5.99&#10;Outer Ring|14.99"><?php echo esc_textarea($s['shipping_zones']); ?></textarea>
              <p class="description">One per line, <code>Zone name|fee</code>. The name must match the
                 <code>location</code> value your app sends to <code>/cart/set-shipping</code>.
                 Leave empty to accept any location at the flat delivery fee.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Unknown locations</th>
            <td>
              <label><input type="checkbox" name="app_api_settings[require_known_zone]" value="1"
                     <?php checked($s['require_known_zone'], 1); ?> />
                Reject a location that is not in the list above</label>
              <p class="description">Off: unlisted locations are accepted with a zero zone fee.</p>
            </td>
          </tr>
        </table>

        <h2>Checkout address</h2>
        <p class="description">Leave all three empty to use whatever the customer submits. Fill them only for a single-city store.</p>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="app_force_city">Force city</label></th>
            <td><input type="text" id="app_force_city" class="regular-text" name="app_api_settings[force_city]" value="<?php echo esc_attr($s['force_city']); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="app_force_state">Force state code</label></th>
            <td><input type="text" id="app_force_state" class="small-text" name="app_api_settings[force_state]" value="<?php echo esc_attr($s['force_state']); ?>" />
                <p class="description">WooCommerce state code, e.g. <code>CA</code>.</p></td>
          </tr>
          <tr>
            <th scope="row"><label for="app_force_country">Force country code</label></th>
            <td><input type="text" id="app_force_country" class="small-text" name="app_api_settings[force_country]" value="<?php echo esc_attr($s['force_country']); ?>" />
                <p class="description">Two-letter ISO code, e.g. <code>GB</code>.</p></td>
          </tr>
        </table>

        <h2>Content &amp; products</h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="app_types">Post types for /content</label></th>
            <td>
              <input type="text" id="app_types" class="regular-text" name="app_api_settings[content_post_types]"
                     value="<?php echo esc_attr($s['content_post_types']); ?>" />
              <p class="description">Comma separated, e.g. <code>post,page,articles</code>. These are the values
                 <code>/content?type=</code> will accept.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Product meta</th>
            <td>
              <label><input type="checkbox" name="app_api_settings[expose_post_meta]" value="1"
                     <?php checked($s['expose_post_meta'], 1); ?> />
                Include public custom fields in product responses</label>
              <p class="description">
                <strong>Off by default, and off is the safe choice.</strong> Product endpoints are public, so
                anything exposed here is readable by anyone. Private <code>_</code>-prefixed keys are never
                included either way. Use the <code>app_api_product_meta</code> filter for fine-grained control.
              </p>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

  // -------- HELPERS --------
  function app_auth_require_https() {
    if (defined('APP_AUTH_ALLOW_INSECURE') && APP_AUTH_ALLOW_INSECURE) {
      return true;
    }
    return is_ssl();
  }

  function app_auth_rate_limit($bucket, $seconds = 60) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'app_auth_rate_' . $bucket . '_' . md5($ip);
    if (get_transient($key)) return false;
    set_transient($key, 1, $seconds);
    return true;
  }

  function app_boolish($val) {
    if (is_bool($val)) return $val;
    if (is_string($val)) return !in_array(strtolower($val), ['false', '0', '', 'no'], true);
    return (bool)$val;
  }

  // -------- REGISTER ENDPOINT --------
  add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/register', [
      'methods'  => ['POST','GET'], 
      'permission_callback' => '__return_true',
      'args' => [
        'username'    => ['type'=>'string','required'=>false], 
        'email'       => ['type'=>'string','required'=>false],
        'password'    => ['type'=>'string','required'=>false],
        'first_name'  => ['type'=>'string','required'=>false],
        'last_name'   => ['type'=>'string','required'=>false],
        'phone'       => ['type'=>'string','required'=>false],
        'user_token'  => ['type'=>'string','required'=>false], 
      ],
      'callback' => 'app_auth_register',
    ]);
  });

  function app_auth_register(WP_REST_Request $req) {
    $token = sanitize_text_field((string) $req->get_param('user_token'));
    if (!empty($token)) {
      $users = get_users([
        'meta_key'   => '_app_auth_token',
        'meta_value' => $token,
        'number'     => 1,
        'count_total'=> false,
      ]);

      if (empty($users)) {
        return new WP_Error('invalid_token', 'No user found for this token.', ['status'=>404]);
      }

      $user = $users[0];
      return new WP_REST_Response([
        'id'         => (int) $user->ID,
        'username'   => $user->user_login,
        'email'      => $user->user_email,
        'first_name' => get_user_meta($user->ID, 'first_name', true) ?: '',
        'last_name'  => get_user_meta($user->ID, 'last_name', true) ?: '',
        'phone'      => get_user_meta($user->ID, 'phone', true) ?: '',
        'role'       => !empty($user->roles) ? (string) reset($user->roles) : '',
        'token'      => $token,
      ], 200);
    }

    // -------- Normal Register Flow --------
    $username   = sanitize_user((string) $req->get_param('username'));
    $email      = sanitize_email((string) $req->get_param('email'));
    $password   = (string) $req->get_param('password');
    $first_name = sanitize_text_field((string) $req->get_param('first_name'));
    $last_name  = sanitize_text_field((string) $req->get_param('last_name'));
    $phone      = sanitize_text_field((string) $req->get_param('phone'));

    if (empty($username) || empty($email) || empty($password)) {
      return new WP_Error('missing_fields', 'username, email and password are required.', ['status'=>400]);
    }

    if (strlen($username) < 3) {
      return new WP_Error('invalid_username', 'Username must be at least 3 characters.', ['status'=>400]);
    }
    if (!is_email($email)) {
      return new WP_Error('invalid_email', 'Please provide a valid email address.', ['status'=>400]);
    }
    if (strlen($password) < 6) {
      return new WP_Error('weak_password', 'Password must be at least 6 characters.', ['status'=>400]);
    }
    if (username_exists($username)) {
      $username = $username . '_' . wp_generate_password(4, false, false);
    }
    if (email_exists($email)) {
      return new WP_Error('email_exists', 'That email is already registered.', ['status'=>409]);
    }

    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) {
      return new WP_Error('create_failed', $user_id->get_error_message(), ['status'=>400]);
    }

    if ($first_name) update_user_meta($user_id, 'first_name', $first_name);
    if ($last_name)  update_user_meta($user_id, 'last_name', $last_name);
    if ($phone) {
      update_user_meta($user_id, 'phone', $phone);
      // Also store it where WooCommerce and most themes look for it.
      update_user_meta($user_id, 'billing_phone', $phone);
    }

    $wp_user  = new WP_User($user_id);
    $new_role = APP_REGISTER_DEFAULT_ROLE;
    if (!wp_roles()->is_role($new_role)) {
      // WooCommerce creates the "customer" role; fall back to a core role so
      // registration still works on a plain WordPress install.
      $new_role = 'subscriber';
    }
    $wp_user->set_role($new_role);

    if (function_exists('wp_new_user_notification')) {
      wp_new_user_notification($user_id, null, 'user');
    }

    $new_token = wp_generate_password(64, false);
    update_user_meta($user_id, '_app_auth_token', $new_token);

    $user = get_userdata($user_id);
    return new WP_REST_Response([
      'id'         => (int) $user_id,
      'username'   => $user ? $user->user_login : '',
      'email'      => $user ? $user->user_email : '',
      'first_name' => get_user_meta($user_id, 'first_name', true) ?: '',
      'last_name'  => get_user_meta($user_id, 'last_name', true) ?: '',
      'phone'      => get_user_meta($user_id, 'phone', true) ?: '',
      'role'       => 'customer',
      'token'      => $new_token,
    ], 201);
  }


  // -------- LOGIN ENDPOINT --------
  add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/login', [
      'methods'  => 'POST', 
      'permission_callback' => '__return_true',
      'args' => [
        'username' => ['type'=>'string','required'=>true],
        'password' => ['type'=>'string','required'=>true],
      ],
      'callback' => 'app_auth_login',
    ]);
  });

  function app_auth_login(WP_REST_Request $req) {
    if (!app_auth_require_https()) {
      return new WP_Error('insecure_connection', 'Use HTTPS.', ['status'=>403]);
    }

    if (!app_auth_rate_limit('login', 30)) {
      return new WP_Error('rate_limited', 'Too many attempts. Please wait a moment.', ['status'=>429]);
    }

    $raw_login = (string) $req->get_param('username');
    $password  = (string) $req->get_param('password');

    if (is_email($raw_login)) {
      $login = sanitize_email($raw_login);           
    } else {
      $login = sanitize_user($raw_login, true);     
    }

    $creds = [
      'user_login'    => $login,
      'user_password' => $password,
      'remember'      => true,
    ];

    $user = wp_signon($creds, false);
    if (is_wp_error($user)) {
      return new WP_Error('invalid_login', 'Invalid username or password.', ['status'=>401]);
    }

    $token = wp_generate_password(64, false);
    update_user_meta($user->ID, '_app_auth_token', $token);

    return [
      'user_id'  => (int) $user->ID,
      'username' => $user->user_login,
      'email'    => $user->user_email,
      'role'     => APP_REGISTER_DEFAULT_ROLE,
      'token'    => $token,
    ];
  }

  // -------- FORGOT PASSWORD (email only) --------
  add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/forgot-password', [
      'methods'  => 'POST',
      'permission_callback' => '__return_true',
      'args' => [
        'email' => ['type' => 'string', 'required' => true],
      ],
      'callback' => function (WP_REST_Request $req) {
        if (!app_auth_require_https()) {
          return new WP_Error('insecure_connection', 'Use HTTPS.', ['status' => 403]);
        }

        if (!app_auth_rate_limit('forgot', 60)) {
          return new WP_Error('rate_limited', 'Too many attempts. Please wait a moment.', ['status' => 429]);
        }

        $email = sanitize_email((string)$req->get_param('email'));
        if (!is_email($email)) {
          return new WP_Error('invalid_email', 'Please provide a valid email address.', ['status' => 400]);
        }

        $user = get_user_by('email', $email);

        if (!$user) {
          return new WP_REST_Response([
            'sent'    => true,
            'message' => 'If that email exists, a reset link has been sent.'
          ], 200);
        }

        $result = retrieve_password($user->user_login);
        if ($result === true) {
          return new WP_REST_Response([
            'sent'    => true,
            'message' => 'If that email exists, a reset link has been sent.'
          ], 200);
        }
        if (is_wp_error($result)) {
          return new WP_Error('email_failed', $result->get_error_message(), ['status' => 400]);
        }

        return new WP_REST_Response(['sent' => true], 200);
      }
    ]);
  });

  // -------- PRODUCTS ENDPOINT (full detail like single product page) --------
  add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/products', [
      'methods'  => 'GET',
      'permission_callback' => '__return_true',
      'callback' => 'app_get_products',
    ]);
  });

  function app_get_products(WP_REST_Request $req) {
    if (!function_exists('wc_get_products')) {
      return new WP_Error('woocommerce_inactive', 'WooCommerce not active', ['status'=>503]);
    }

    // Featured Products
    $featured = wc_get_products([
      'status'   => 'publish',
      'limit'    => 5,
      'featured' => true,
      'return'   => 'objects',
    ]);

    // Special Products (on sale)
    $on_sale_ids = array_slice(wc_get_product_ids_on_sale(), 0, 5);
    $special = [];
    if ($on_sale_ids) {
      $special = wc_get_products([
        'status'  => 'publish',
        'include' => $on_sale_ids,
        'return'  => 'objects',
      ]);
    }

    // Recently Reviewed Products (prefix-safe)
    global $wpdb;
    $reviewed_ids = $wpdb->get_col("
      SELECT DISTINCT c.comment_post_ID
      FROM {$wpdb->comments} c
      INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
      WHERE c.comment_approved = 1
        AND c.comment_type = 'review'
        AND p.post_type = 'product'
        AND p.post_status = 'publish'
      ORDER BY c.comment_date_gmt DESC
      LIMIT 5
    ");
    $reviewed = [];
    if ($reviewed_ids) {
      $reviewed = wc_get_products([
        'include' => $reviewed_ids,
        'status'  => 'publish',
        'return'  => 'objects',
      ]);
    }

    // Map to full formatter (same as category endpoint; includes post_meta)
    $map_full = function($products) {
      return array_map('app_format_product_full', $products);
    };

    return [
      'featured' => $map_full($featured),
      'special'  => $map_full($special),
      'reviewed' => $map_full($reviewed),
    ];
  }

  //CONTENT ENDPOINT (Blogs + Articles) 
  add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/content', [
      'methods'  => 'GET',
      'permission_callback' => '__return_true',
      'args' => [
        'type'   => ['type'=>'string','required'=>true],
        'limit'  => ['type'=>'integer','required'=>false],
        'page'   => ['type'=>'integer','required'=>false],
        'search' => ['type'=>'string','required'=>false],
      ],
      'callback' => 'app_get_content',
    ]);
  });

  function app_get_content(WP_REST_Request $req) {
    global $wpdb;
    $posts_table = $wpdb->posts;

    $type   = sanitize_key($req->get_param('type')); 
    $limit  = intval($req->get_param('limit'));
    if ($limit <= 0) $limit = 10;
    if ($limit > 50) $limit = 50;

    $page   = intval($req->get_param('page'));
    if ($page <= 0) $page = 1;

    $offset = ($page - 1) * $limit;
    $search = sanitize_text_field((string)$req->get_param('search'));

    $allowed_types = array_filter(array_map('trim', explode(',', (string) app_api_setting('content_post_types', 'post'))));
    if (empty($allowed_types)) $allowed_types = ['post'];
    if (!in_array($type, $allowed_types, true)) {
      return new WP_Error('invalid_type', 'Type must be either "post" or "articles".', ['status'=>400]);
    }

    $where = $wpdb->prepare("WHERE post_type = %s AND post_status = 'publish'", $type);
    if ($search !== '') {
      $like = '%' . $wpdb->esc_like($search) . '%';
      $where .= $wpdb->prepare(" AND (post_title LIKE %s OR post_content LIKE %s)", $like, $like);
    }

    $count_sql = "SELECT COUNT(1) FROM {$posts_table} {$where}";
    $total = (int) $wpdb->get_var($count_sql);

    $list_sql = $wpdb->prepare("
      SELECT ID, post_title, post_excerpt, post_content, post_date_gmt, post_author
      FROM {$posts_table}
      {$where}
      ORDER BY post_date_gmt DESC
      LIMIT %d OFFSET %d
    ", $limit, $offset);

    $rows = $wpdb->get_results($list_sql);

    $items = [];
    foreach ($rows as $r) {
      $id = (int)$r->ID;
    $title = html_entity_decode(wp_strip_all_tags($r->post_title), ENT_QUOTES);
      $title   = html_entity_decode(wp_strip_all_tags($r->post_title), ENT_QUOTES);
      $content = apply_filters('the_content', $r->post_content); 
      $excerpt = $r->post_excerpt ?: wp_trim_words(wp_strip_all_tags($r->post_content), 30, '…');
      $excerpt = html_entity_decode($excerpt, ENT_QUOTES);
      $thumb   = get_the_post_thumbnail_url($id, 'full');
      $author  = get_the_author_meta('display_name', (int)$r->post_author);

      $items[] = [
        'id'           => $id,
        'title'        => $title,
        'excerpt'      => $excerpt,
        'content'      => $content,
        'date_gmt'     => $r->post_date_gmt,
        'author'       => $author,
        'featured_img' => $thumb ?: null,
        'permalink'    => get_permalink($id),
      ];
    }

    return [
      'type'      => $type,
      'total'     => $total,
      'page'      => $page,
      'per_page'  => $limit,
      'has_more'  => ($offset + count($items)) < $total,
      'items'     => $items,
    ];
  }

  //PRODUCT CATEGORIES ENDPOINT
  add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/product-categories', [
      'methods'  => 'GET',
      'permission_callback' => '__return_true',
      'args' => [
        'parent'     => ['type'=>'integer','required'=>false],
        'hide_empty' => ['type'=>'boolean','required'=>false],
        'depth'      => ['type'=>'integer','required'=>false],
      ],
      'callback' => 'app_get_product_categories',
    ]);
  });

  function app_build_product_cat_tree($parent_id = 0, $hide_empty = true, $depth = 0) {
    $terms = get_terms([
      'taxonomy'   => 'product_cat',
      'parent'     => (int)$parent_id,
      // Force it to show all categories (even empty ones)
      'hide_empty' => false,
      'orderby'    => 'name',
      'order'      => 'ASC',
    ]);
    if (is_wp_error($terms)) return [];

    $out = [];
    foreach ($terms as $term) {
      $thumb_id = get_term_meta($term->term_id, 'thumbnail_id', true);
      $image    = $thumb_id ? wp_get_attachment_url($thumb_id) : null;

      $children = [];
      if ($depth === 0 || $depth > 1) {
        $next_depth = ($depth === 0) ? 0 : $depth - 1; // 0 = unlimited
        $children = app_build_product_cat_tree($term->term_id, false, $next_depth);
      }

      $out[] = [
        'id'             => (int)$term->term_id,
        'name'           => $term->name,
        'image'          => $image,
        'sub_categories' => $children,
      ];
    }
    return $out;
  }

  function app_get_product_categories(WP_REST_Request $req) {
    $parent     = (int) $req->get_param('parent');
    $hide_empty = $req->get_param('hide_empty');
    $hide_empty = is_null($hide_empty) ? true : (bool)$hide_empty;

    $depth = (int) $req->get_param('depth');
    if ($depth < 0) $depth = 0; // 0 = unlimited

    return [
      'parent'     => $parent,
      'hide_empty' => $hide_empty,
      'depth'      => $depth,
      'items'      => app_build_product_cat_tree($parent, $hide_empty, $depth),
    ];
  }

  //PRODUCTS BY CATEGORY ENDPOINT
  add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/products/(?P<category>[^/]+)', [
      'methods'  => 'GET',
      'permission_callback' => '__return_true',
      'args' => [
        'category' => ['type' => 'string',  'required' => true, 'description' => 'product_cat term ID or slug'],
        'limit'    => ['type' => 'integer', 'required' => false, 'description' => 'Items per page (default 50, max 100)'],
        'page'     => ['type' => 'integer', 'required' => false, 'description' => 'Page number (default 1)'],
        'include_children' => ['type' => 'boolean', 'required' => false, 'description' => 'Include child categories (default true)'],
      ],
      'callback' => 'app_get_products_by_category',
    ]);
  });

  if (!function_exists('app_format_product_full')) {
    function app_format_product_full(WC_Product $p) {
      $id = $p->get_id();

      // Main + gallery images (always include featured as first item)
      $image_ids = array_merge(
        [$p->get_image_id()],              
        $p->get_gallery_image_ids()        
      );

      // Clean up duplicates/nulls
      $image_ids = array_filter(array_unique($image_ids));

      $images = [];
      foreach ($image_ids as $att_id) {
        $images[] = [
          'id'  => (int) $att_id,
          'src' => $att_id ? wp_get_attachment_url($att_id) : null,
        ];
      }

      // Categories (Parent + Child Categories)
      $cats = [];
      $sub_categories = [];  
      $terms = get_the_terms($id, 'product_cat');
      if (!is_wp_error($terms) && $terms) {
        foreach ($terms as $t) {
          $cats[] = ['id' => (int)$t->term_id, 'name' => $t->name, 'slug' => $t->slug];

          // Add child categories (only for the current product)
          $children = get_term_children($t->term_id, 'product_cat');
          if (!is_wp_error($children) && !empty($children)) {
            foreach ($children as $child_id) {
              $child_term = get_term($child_id, 'product_cat');
              if (!is_wp_error($child_term)) {
                if (has_term($child_term->term_id, 'product_cat', $id)) {
                  $sub_categories[] = [
                    'id'   => (int)$child_term->term_id,
                    'name' => $child_term->name,
                    'slug' => $child_term->slug,
                    'parent' => $t->term_id, 
                  ];
                }
              }
            }
          }
        }
      }

      // Tags
      $tags = [];
      $tag_terms = get_the_terms($id, 'product_tag');
      if (!is_wp_error($tag_terms) && $tag_terms) {
        foreach ($tag_terms as $t) {
          $tags[] = ['id' => (int)$t->term_id, 'name' => $t->name, 'slug' => $t->slug];
        }
      }

      // Attributes
      $attrs = [];
      foreach ($p->get_attributes() as $attr) {
        $name  = $attr->get_name();
        $label = wc_attribute_label($name);

        if ($attr->is_taxonomy()) {
          $vals  = [];
          $terms = wp_get_post_terms($id, $name, ['fields' => 'all']);
          foreach ($terms as $t) {
            $vals[] = ['id' => (int)$t->term_id, 'name' => $t->name, 'slug' => $t->slug];
          }
        } else {
          $vals = array_values(array_map('wc_clean', (array)$attr->get_options()));
        }

        $attrs[] = [
          'name'      => $label,
          'slug'      => $name,
          'values'    => $vals,
          'visible'   => (bool)$attr->get_visible(),
          'variation' => (bool)$attr->get_variation(),
        ];
      }

      // Variations (for variable products)
      $variations = [];
      if ($p->is_type('variable')) {
        foreach ($p->get_children() as $var_id) {
          $v = wc_get_product($var_id);
          if (!$v) continue;

          $v_image_id = $v->get_image_id();
          $v_image    = $v_image_id ? wp_get_attachment_url($v_image_id) : null;

          $v_attrs_out = [];
          foreach ($v->get_attributes() as $raw_key => $val) {
            $tax   = str_replace('attribute_', '', $raw_key); 
            $label = wc_attribute_label($tax);

            $pretty_val = $val;
            if (taxonomy_exists($tax)) {
              $term = get_term_by('slug', $val, $tax);
              if ($term && !is_wp_error($term)) $pretty_val = $term->name;
            }

            $v_attrs_out[] = [
              'name'  => $label,
              'slug'  => $tax,
              'value' => $pretty_val,
              'raw'   => $val,
            ];
          }

          $variations[] = [
            'id'            => $v->get_id(),
            'sku'           => $v->get_sku(),
            'price'         => $v->get_price(),
            'regular_price' => $v->get_regular_price(),
            'sale_price'    => $v->get_sale_price(),
            'price_html'    => $v->get_price_html(),
            'on_sale'       => $v->is_on_sale(),
            'stock_status'  => $v->get_stock_status(),
            'stock_quantity'=> $v->get_stock_quantity(),
            'image'         => $v_image,
            'attributes'    => $v_attrs_out,
            'permalink'     => get_permalink($v->get_id()),
          ];
        }
      }

      // Raw post meta is NOT exposed by default: it would publish every private
      // "_"-prefixed key (cost price, download URLs, third-party plugin data) on
      // an unauthenticated endpoint. Turn it on under Settings -> App API only if
      // you understand that, and use the filter to whitelist what you need.
      $post_meta = [];
      if (app_api_setting('expose_post_meta', 0)) {
        $raw = get_post_meta($id); // returns [meta_key => [values...]]
        foreach ($raw as $key => $values) {
          if (strpos($key, '_') === 0) continue; // never expose private keys
          $values = array_map('maybe_unserialize', $values);
          $post_meta[$key] = (count($values) === 1) ? $values[0] : $values;
        }
      }
      $post_meta = apply_filters('app_api_product_meta', $post_meta, $id);

      return [
        'id'                => $id,
        'name'              => $p->get_name(),
        'slug'              => $p->get_slug(),
        'sku'               => $p->get_sku(),
        'type'              => $p->get_type(),
        'status'            => $p->get_status(),
        'permalink'         => get_permalink($id),

        // Prices
        'price'             => $p->get_price(),
        'regular_price'     => $p->get_regular_price(),
        'sale_price'        => $p->get_sale_price(),
        'price_html'        => $p->get_price_html(),
        'on_sale'           => $p->is_on_sale(),

        // Stock
        'stock_status'      => $p->get_stock_status(),
        'stock_quantity'    => $p->get_stock_quantity(),
        'manage_stock'      => $p->get_manage_stock(),

        // Content
        'short_description' => wp_strip_all_tags($p->get_short_description()),
        'description'       => wp_strip_all_tags($p->get_description()),

        // Media & Taxonomies
        'images'            => $images,
        'categories'        => $cats,
        'tags'              => $tags,

        // Attributes & Variations
        'attributes'        => $attrs,
        'variations'        => $variations,

        // Ratings
        'average_rating'    => $p->get_average_rating(),
        'rating_count'      => (int)$p->get_rating_count(),
        'review_count'      => (int)$p->get_review_count(),

        // Product's own subcategories (to be shown for each product)
        'sub_categories'    => $sub_categories,

        // All post meta (ACF + Woo + custom)
        'post_meta'         => $post_meta,
      ];
    }
  }

  function app_get_products_by_category(WP_REST_Request $req) {
    if (!function_exists('wc_get_products')) {
      return new WP_Error('woocommerce_inactive', 'WooCommerce not active', ['status'=>503]);
    }

    // Params
    $cat_param = sanitize_text_field((string)$req->get_param('category'));
    
    // Set limit to 50 products per page (default limit 50)
    $limit = (int)$req->get_param('limit');
    if ($limit <= 0) $limit = 50;  
    
    $page = (int)$req->get_param('page');  
    if ($page <= 0) $page = 1; 

    // Robust boolean parsing for include_children
    $include_children = $req->get_param('include_children');
    $include_children = is_null($include_children) ? true : app_boolish($include_children);

    //Resolve category by ID, slug, or name
    $term = null;
    if ($cat_param !== '' && ctype_digit($cat_param)) {
      $term = get_term((int)$cat_param, 'product_cat');
    }
    if (!$term || is_wp_error($term)) {
      $term = get_term_by('slug', sanitize_title($cat_param), 'product_cat');
    }
    if (!$term || is_wp_error($term)) {
      $term = get_term_by('name', $cat_param, 'product_cat'); 
    }
    if (!$term || is_wp_error($term)) {
      return new WP_Error('invalid_category', 'Category not found.', ['status'=>404]);
    }

    //Build term id list (parent + descendants) if include_children
    $term_ids = [(int)$term->term_id];
    if ($include_children) {
      $children = get_term_children($term->term_id, 'product_cat'); 
      if (!is_wp_error($children) && !empty($children)) {
        $term_ids = array_values(array_unique(array_map('intval', array_merge($term_ids, $children)))); 
      }
    }

    //Query products across all collected term IDs
    $args = [
      'status'         => 'publish',
      'posts_per_page' => $limit,
      'paged'          => $page,
      'orderby'        => 'ID',   
      'order'          => 'DESC',
      'return'         => 'objects',
      'tax_query'      => [[
        'taxonomy'         => 'product_cat',
        'field'            => 'term_id',
        'terms'            => $term_ids,
        'operator'         => 'IN',
        'include_children' => false,
      ]],
    ];

    $products = wc_get_products($args);
    $items    = array_map('app_format_product_full', $products);

    //Total count for pagination
    $count_args = $args;
    $count_args['posts_per_page'] = -1; 
    $count_args['paged'] = 1;           
    $all_ids = wc_get_products($count_args);
    $total   = is_array($all_ids) ? count($all_ids) : 0;

    return [
      'category' => [
        'id'   => (int)$term->term_id,
        'slug' => $term->slug,
        'name' => $term->name,
        'sub_categories' => get_term_children($term->term_id, 'product_cat'), 
      ],
      'meta' => [
        'page'              => $page,
        'per_page'          => $limit,
        'total'             => $total,
        'has_more'          => ($page * $limit) < $total,
        'children_included' => (bool)$include_children,
        'matched_term_ids'  => $term_ids, 
      ],
      'products' => $items,
    ];
  }

  //CART HELPERS (Session + Auth)
  function app_get_user_by_token($token) {
    if (!$token) return null;
    $users = get_users([
      'meta_key'   => '_app_auth_token',
      'meta_value' => $token,
      'number'     => 1,
      'fields'     => 'all',
    ]);
    return $users ? $users[0] : null;
  }

  function app_auth_current_user_from_request(WP_REST_Request $req) {
    $hdr = $req->get_header('authorization');
    $token = '';
    if ($hdr && preg_match('/Bearer\s+(.+)/i', $hdr, $m)) {
      $token = trim($m[1]);
    } else {
      $token = sanitize_text_field((string)$req->get_param('token'));
    }
    $user = app_get_user_by_token($token);
    if ($user && $user->ID) {
      wp_set_current_user($user->ID);
      return $user;
    }
    return null;
  }

  function app_bootstrap_wc_session_and_cart() {
    if (function_exists('WC') && WC()) {
      if (!WC()->session) {
        $handler = new WC_Session_Handler();
        $handler->init();
        WC()->session = $handler;
      }
      if (!WC()->customer) {
        WC()->customer = new WC_Customer(get_current_user_id(), true);
        WC()->customer->save();
      }
      if (!WC()->cart) {
        WC()->cart = new WC_Cart();
        WC()->cart->get_cart();
      }
      
      // Ensure session is started and data is loaded
      if (!WC()->session->has_session()) {
        WC()->session->set_customer_session_cookie(true);
      }
      
      // Load cart from session
      WC()->cart->get_cart_from_session();
    }
  }

function app_cart_snapshot() {
  $items_out = [];
  if (WC()->cart && !WC()->cart->is_empty()) {
    foreach (WC()->cart->get_cart() as $key => $item) {
      $prod = $item['data']; 
      $pid  = $prod->get_id();
      $items_out[] = [
        'cart_item_key' => $key,
        'product_id'    => $item['product_id'],
        'variation_id'  => $item['variation_id'],
        'name'          => $prod->get_name(),
        'sku'           => $prod->get_sku(),
        'quantity'      => (int)$item['quantity'],
        'price'         => (float)$prod->get_price(),
        'price_html'    => $prod->get_price_html(),
        'subtotal'      => (float)WC()->cart->get_product_subtotal($prod, $item['quantity'], false),
        'image'         => ($img_id = $prod->get_image_id()) ? wp_get_attachment_url($img_id) : null,
        'attributes'    => isset($item['variation']) ? $item['variation'] : [],
        'permalink'     => get_permalink($pid),
      ];
    }
  }

  $subtotal = WC()->cart ? (float) WC()->cart->get_subtotal() : 0.0;
  $delivery_fee = (float) WC()->session->get('custom_delivery_fee', 0);
  $shipping_fee = (float) WC()->session->get('custom_shipping_fee', 0);
  $total = $subtotal + $delivery_fee + $shipping_fee;
  
  // Get selected shipping method and location from session
  $shipping_method = WC()->session->get('selected_shipping_method', null);
  $location = WC()->session->get('selected_location', null);

  return [
    'items'           => $items_out,
    'item_count'      => WC()->cart ? WC()->cart->get_cart_contents_count() : 0,
    'subtotal'        => $subtotal,
    'delivery_fee'    => $delivery_fee,
    'shipping_fee'    => $shipping_fee,
    'total'           => $total,
    'currency'        => get_woocommerce_currency(),
    'shipping_method' => $shipping_method,
    'location'        => $location,
  ];
}


  // ENHANCED SESSION MANAGEMENT: Token-based session persistence
  function app_get_user_cart_session_key($user_id) {
    return 'user_cart_session_' . $user_id;
  }

  function app_save_cart_session($user_id) {
    $session_data = [
      'cart' => WC()->cart->get_cart_contents(),
      'applied_coupons' => WC()->cart->get_applied_coupons(),
      'cart_totals' => WC()->cart->get_totals(),
      'shipping_methods' => WC()->session ? WC()->session->get('chosen_shipping_methods', array()) : array(),
    ];
    
    update_user_meta($user_id, app_get_user_cart_session_key($user_id), $session_data);
  }

  function app_load_cart_session($user_id) {
    $session_data = get_user_meta($user_id, app_get_user_cart_session_key($user_id), true);
    
    if (!empty($session_data)) {
      // Clear existing cart first
      WC()->cart->empty_cart(false);
      
      if (!empty($session_data['cart'])) {
        foreach ($session_data['cart'] as $cart_item_key => $cart_item) {
          WC()->cart->cart_contents[$cart_item_key] = $cart_item;
        }
      }
      
      if (!empty($session_data['applied_coupons'])) {
        WC()->cart->set_applied_coupons($session_data['applied_coupons']);
      }
      
      if (!empty($session_data['shipping_methods']) && WC()->session) {
        WC()->session->set('chosen_shipping_methods', $session_data['shipping_methods']);
      }
    }
  }

function app_calculate_custom_fees($shipping_method, $location) {
  $cart_subtotal = WC()->cart ? WC()->cart->get_subtotal() : 0;
  $delivery_fee  = 0.0;
  $shipping_fee  = 0.0;

  if ($shipping_method === 'pickup') {
    $delivery_fee = 0;
    $shipping_fee = 0;
    
    // Store shipping method in session
    WC()->session->set('selected_shipping_method', 'pickup');
    WC()->session->set('selected_location', $location);
} else {
    $delivery_fee = (float) app_api_setting('delivery_fee', 0);

    // Zone fees come from Settings -> App API ("Zone name|fee", one per line).
    $shipping_zones = app_api_shipping_zones();

    if (isset($shipping_zones[$location])) {
      $shipping_fee = (float) $shipping_zones[$location];
    } elseif (!empty($shipping_zones) && app_api_setting('require_known_zone', 0)) {
      // Only reject unknown locations when the store explicitly asks for it.
      return [null, null, 'unsupported_location'];
    } else {
      $shipping_fee = 0.0;
    }

    $free_over = (float) app_api_setting('free_shipping_over', 0);
    if ($free_over > 0 && $cart_subtotal >= $free_over) {
      $shipping_fee = 0.0;
    }

    // Store shipping method and location in session
    WC()->session->set('selected_shipping_method', 'delivery');
    WC()->session->set('selected_location', $location);
  }

  WC()->session->set('custom_delivery_fee', $delivery_fee);
  WC()->session->set('custom_shipping_fee', $shipping_fee);

  return [$delivery_fee, $shipping_fee, 'success'];
}


  //CART ROUTES
  add_action('rest_api_init', function () {

    register_rest_route('custom/v1', '/cart/add', [
      'methods'  => 'POST',
      'permission_callback' => '__return_true',
      'args' => [
        'product_id'   => ['type'=>'integer','required'=>false],
        'variation_id' => ['type'=>'integer','required'=>false],
        'quantity'     => ['type'=>'integer','required'=>false],
        'attributes'   => ['type'=>'object', 'required'=>false,
          'description'=>'For variable products, e.g. {"attribute_pa_size":"m"}'],
      ],
      'callback' => 'app_cart_add',
    ]);

    register_rest_route('custom/v1', '/cart', [
      'methods'  => 'GET',
      'permission_callback' => '__return_true',
      'callback' => 'app_cart_get',
    ]);

    register_rest_route('custom/v1', '/cart/update', [
      'methods'  => 'POST',
      'permission_callback' => '__return_true',
      'args' => [
        'cart_item_key' => ['type'=>'string','required'=>true],
        'quantity'      => ['type'=>'integer','required'=>true],
      ],
      'callback' => 'app_cart_update',
    ]);

    register_rest_route('custom/v1', '/cart/remove', [
      'methods'  => 'POST',
      'permission_callback' => '__return_true',
      'args' => [
        'cart_item_key' => ['type'=>'string','required'=>true],
      ],
      'callback' => 'app_cart_remove',
    ]);

    register_rest_route('custom/v1', '/cart/clear', [
      'methods'  => 'POST',
      'permission_callback' => '__return_true',
      'callback' => 'app_cart_clear',
    ]);

    // Set address (so shipping/tax totals align with Dubai)
    register_rest_route('custom/v1', '/cart/set-address', [
      'methods'  => 'POST',
      'permission_callback' => '__return_true',
      'args' => [
        'country'  => ['type'=>'string','required'=>true], 
        'state'    => ['type'=>'string','required'=>false], 
        'city'     => ['type'=>'string','required'=>false],
        'postcode' => ['type'=>'string','required'=>false],
        'address1' => ['type'=>'string','required'=>false],
        'address2' => ['type'=>'string','required'=>false],
      ],
      'callback' => 'app_cart_set_address',
    ]);

    // Add shipping method selection endpoint
    register_rest_route('custom/v1', '/cart/set-shipping', [
      'methods'  => 'POST',
      'permission_callback' => '__return_true',
      'args' => [
        'shipping_method' => ['type'=>'string','required'=>true], 
      ],
      'callback' => 'app_cart_set_shipping',
    ]);
  });

  function app_cart_add(WP_REST_Request $req) {
    if (!app_auth_require_https()) return new WP_Error('insecure_connection','Use HTTPS.',['status'=>403]);

    $user = app_auth_current_user_from_request($req);
    if (!$user) return new WP_Error('unauthorized','Invalid or missing token.',['status'=>401]);

    if (!function_exists('wc_get_product')) return new WP_Error('woocommerce_inactive','WooCommerce not active',['status'=>503]);

    $product_id   = (int) $req->get_param('product_id');
    $variation_id = (int) $req->get_param('variation_id');
    $qty          = max(1, (int)$req->get_param('quantity'));
    $attributes   = (array)$req->get_param('attributes');

    $product = $variation_id ? wc_get_product($variation_id) : wc_get_product($product_id);
    if (!$product) return new WP_Error('invalid_product','Product/variation not found.',['status'=>404]);

    // Normalize for variation
    if ($variation_id && !$product->is_type('variation')) {
      return new WP_Error('invalid_variation','Variation not found.',['status'=>404]);
    }
    if ($variation_id) $product_id = $product->get_parent_id();

    if (!$product->is_purchasable()) return new WP_Error('not_purchasable','Not purchasable.',['status'=>400]);
    if (!$product->is_in_stock())    return new WP_Error('out_of_stock','Out of stock.',['status'=>400]);

    app_bootstrap_wc_session_and_cart();
    app_load_cart_session($user->ID); 

    $variation_data = [];
    if ($variation_id) {
      foreach ($attributes as $k => $v) {
        $k = strtolower($k);
        if (strpos($k,'attribute_') !== 0) $k = 'attribute_'.ltrim($k,'_');
        $variation_data[$k] = wc_clean($v);
      }
    }

    // Clear any existing instances of this product first (to prevent quantity accumulation)
    $cart_id = WC()->cart->generate_cart_id($product_id, $variation_id ?: 0, $variation_data);
    $cart_item_key = WC()->cart->find_product_in_cart($cart_id);
    if ($cart_item_key) {
      WC()->cart->remove_cart_item($cart_item_key);
    }

    $key = WC()->cart->add_to_cart($product_id, $qty, $variation_id ?: 0, $variation_data);
    if (!$key) return new WP_Error('add_failed','Could not add to cart.',['status'=>400]);

    WC()->cart->calculate_totals();
    app_save_cart_session($user->ID); 
    
    return new WP_REST_Response(['added'=>true,'cart_item_key'=>$key,'cart'=>app_cart_snapshot()], 201);
  }

  function app_cart_get(WP_REST_Request $req) {
    $user = app_auth_current_user_from_request($req);
    if (!$user) return new WP_Error('unauthorized','Invalid or missing token.',['status'=>401]);

    if (!function_exists('WC')) return new WP_Error('woocommerce_inactive','WooCommerce not active',['status'=>503]);

    app_bootstrap_wc_session_and_cart();
    app_load_cart_session($user->ID); 
    WC()->cart->calculate_totals();

    return new WP_REST_Response(app_cart_snapshot(), 200);
  }

  function app_cart_update(WP_REST_Request $req) {
    $user = app_auth_current_user_from_request($req);
    if (!$user) return new WP_Error('unauthorized','Invalid or missing token.',['status'=>401]);

    app_bootstrap_wc_session_and_cart();
    app_load_cart_session($user->ID); 

    $key = sanitize_text_field((string)$req->get_param('cart_item_key'));
    $qty = max(0, (int)$req->get_param('quantity')); 

    if (!WC()->cart->find_product_in_cart($key) && !isset(WC()->cart->get_cart()[$key])) {
      return new WP_Error('not_found','Cart item not found.',['status'=>404]);
    }

    if ($qty === 0) {
      WC()->cart->remove_cart_item($key);
    } else {
      WC()->cart->set_quantity($key, $qty, true);
    }

    WC()->cart->calculate_totals();
    app_save_cart_session($user->ID); 
    
    return new WP_REST_Response(['updated'=>true,'cart'=>app_cart_snapshot()], 200);
  }

  function app_cart_remove(WP_REST_Request $req) {
    $user = app_auth_current_user_from_request($req);
    if (!$user) return new WP_Error('unauthorized','Invalid or missing token.',['status'=>401]);

    app_bootstrap_wc_session_and_cart();
    app_load_cart_session($user->ID); 

    $key = sanitize_text_field((string)$req->get_param('cart_item_key'));
    if (!isset(WC()->cart->get_cart()[$key])) {
      return new WP_Error('not_found','Cart item not found.',['status'=>404]);
    }

    WC()->cart->remove_cart_item($key);
    WC()->cart->calculate_totals();
    
    // Save updated cart to user meta
    app_save_cart_session($user->ID);

    return new WP_REST_Response(['removed'=>true,'cart'=>app_cart_snapshot()], 200);
  }

  function app_cart_clear(WP_REST_Request $req) {
    $user = app_auth_current_user_from_request($req);
    if (!$user) return new WP_Error('unauthorized','Invalid or missing token.',['status'=>401]);

    app_bootstrap_wc_session_and_cart();
    WC()->cart->empty_cart();
    WC()->cart->calculate_totals();
    
    // Clear saved cart session
    app_save_cart_session($user->ID);

    return new WP_REST_Response(['cleared'=>true,'cart'=>app_cart_snapshot()], 200);
  }

  function app_cart_set_address(WP_REST_Request $req) {
    $user = app_auth_current_user_from_request($req);
    if (!$user) return new WP_Error('unauthorized','Invalid or missing token.',['status'=>401]);

    app_bootstrap_wc_session_and_cart();
    app_load_cart_session($user->ID); 

    $country  = strtoupper(sanitize_text_field((string)$req->get_param('country'))); 
    $state    = strtoupper(sanitize_text_field((string)$req->get_param('state')));   
    $city     = sanitize_text_field((string)$req->get_param('city'));
    $postcode = sanitize_text_field((string)$req->get_param('postcode'));
    $address1 = sanitize_text_field((string)$req->get_param('address1'));
    $address2 = sanitize_text_field((string)$req->get_param('address2'));

    $cust = WC()->customer;
    $fields = compact('country','state','city','postcode','address1','address2');
    foreach ($fields as $k=>$v) {
      if ($v === '') continue;
      $cust->{"set_billing_$k"}($v);
      $cust->{"set_shipping_$k"}($v);
    }
    $cust->save();

    WC()->cart->calculate_totals();
    app_save_cart_session($user->ID); 
    
    return new WP_REST_Response(['ok'=>true,'cart'=>app_cart_snapshot()], 200);
  }

function app_cart_set_shipping(WP_REST_Request $req) {
  $user = app_auth_current_user_from_request($req);
  if (!$user) return new WP_Error('unauthorized','Invalid or missing token.',['status'=>401]);

  app_bootstrap_wc_session_and_cart();
  app_load_cart_session($user->ID);

  $shipping_method = sanitize_text_field($req->get_param('shipping_method'));
  $location = sanitize_text_field($req->get_param('location'));

  // Validate required parameters
  if (empty($shipping_method)) {
    return new WP_Error('missing_parameter', 'shipping_method is required.', ['status'=>400]);
  }

  if ($shipping_method !== 'pickup' && empty($location)) {
    return new WP_Error('missing_parameter', 'location is required for delivery.', ['status'=>400]);
  }

  // Calculate and store fees
  list($delivery_fee, $shipping_fee, $status) = app_calculate_custom_fees($shipping_method, $location);

  // Check if location is unsupported
  if ($status === 'unsupported_location') {
    return new WP_Error(
      'unsupported_location', 
      'Sorry, we do not deliver to this location. Please choose a different area.', 
      ['status'=>400]
    );
  }

  WC()->cart->calculate_totals();
  app_save_cart_session($user->ID);

  return new WP_REST_Response([
    'success'         => true,
    'shipping_method' => $shipping_method,
    'location'        => $location,
    'delivery_fee'    => $delivery_fee,
    'shipping_fee'    => $shipping_fee,
    'cart'            => app_cart_snapshot()
  ], 200);
}

// CUSTOM CHECKOUT API
add_action('rest_api_init', function () {
  register_rest_route('custom/v1', '/checkout', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => 'custom_wc_checkout',
    'args'     => [
      'billing_first_name' => ['required' => true, 'type' => 'string'],
      'billing_last_name'  => ['required' => true, 'type' => 'string'],
      'billing_location'       => ['required' => false, 'type' => 'string'],
      'billing_city'           => ['required' => false, 'type' => 'string'],
      // Legacy name kept so existing apps keep working.
      'billing_dubai_location' => ['required' => false, 'type' => 'string'],
      'billing_address_1'  => ['required' => true, 'type' => 'string'],
      'billing_address_2'  => ['required' => false, 'type' => 'string'],
      'billing_phone'      => ['required' => true, 'type' => 'string'],
      'billing_email'      => ['required' => true, 'type' => 'string'],
      'order_notes'        => ['required' => false, 'type' => 'string'],
      'payment_method'     => ['required' => true, 'type' => 'string'], 
      'shipping_method'    => ['required' => true, 'type' => 'string'], 
      'location'           => ['required' => false, 'type' => 'string'],
    ]
  ]);
});

function custom_wc_checkout(WP_REST_Request $req) {
  if (!function_exists('WC')) {
    return new WP_Error('woocommerce_inactive', 'WooCommerce is not active', ['status'=>503]);
  }

  if (!app_auth_require_https()) {
    return new WP_Error('insecure_connection', 'Use HTTPS.', ['status'=>403]);
  }

  $user = app_auth_current_user_from_request($req);
  if (!$user) {
    return new WP_Error('unauthorized','Invalid or missing token.',['status'=>401]);
  }

  app_bootstrap_wc_session_and_cart();
  app_load_cart_session($user->ID);

  if (!WC()->cart || WC()->cart->is_empty()) {
    return new WP_Error('empty_cart', 'Your cart is empty', ['status'=>400]);
  }

  // Collect billing/shipping data
  $billing_first_name = sanitize_text_field($req->get_param('billing_first_name'));
  $billing_last_name  = sanitize_text_field($req->get_param('billing_last_name'));
  $billing_location   = sanitize_text_field($req->get_param('billing_location'));
  if ($billing_location === '') {
    $billing_location = sanitize_text_field($req->get_param('billing_dubai_location'));
  }
  $billing_address_1  = sanitize_text_field($req->get_param('billing_address_1'));
  $billing_address_2  = sanitize_text_field($req->get_param('billing_address_2'));
  $billing_phone      = sanitize_text_field($req->get_param('billing_phone'));
  $billing_email      = sanitize_email($req->get_param('billing_email'));
  $order_notes        = sanitize_textarea_field($req->get_param('order_notes'));
  $payment_method     = sanitize_text_field($req->get_param('payment_method'));

  // Create order
  $order = wc_create_order();

  foreach (WC()->cart->get_cart() as $cart_item) {
    $order->add_product($cart_item['data'], $cart_item['quantity']);
  }

  // Billing
  $order->set_billing_first_name($billing_first_name);
  $order->set_billing_last_name($billing_last_name);
  $order->set_billing_address_1($billing_address_1);
  $order->set_billing_address_2($billing_address_2);
  // Optional store-wide overrides (Settings -> App API). Empty = use what the
  // customer supplied, so the plugin works for any country.
  $force_city    = (string) app_api_setting('force_city', '');
  $force_state   = (string) app_api_setting('force_state', '');
  $force_country = (string) app_api_setting('force_country', '');
  $order->set_billing_city($force_city !== '' ? $force_city : sanitize_text_field($req->get_param('billing_city')));
  if ($force_state !== '')   $order->set_billing_state($force_state);
  if ($force_country !== '') $order->set_billing_country($force_country);
  $order->set_billing_phone($billing_phone);
  $order->set_billing_email($billing_email);

  // Store custom location
  $order->update_meta_data('billing_location', $billing_location);

  // Shipping = same as billing
  $order->set_shipping_first_name($billing_first_name);
  $order->set_shipping_last_name($billing_last_name);
  $order->set_shipping_address_1($billing_address_1);
  $order->set_shipping_address_2($billing_address_2);
  $order->set_shipping_city($force_city !== '' ? $force_city : sanitize_text_field($req->get_param('billing_city')));
  if ($force_state !== '')   $order->set_shipping_state($force_state);
  if ($force_country !== '') $order->set_shipping_country($force_country);

  if (!empty($order_notes)) {
    $order->add_order_note($order_notes);
  }

  // Payment
  $available_gateways = WC()->payment_gateways->payment_gateways();
  if (!isset($available_gateways[$payment_method])) {
    return new WP_Error('invalid_payment', 'Payment method not found', ['status'=>400]);
  }
  $order->set_payment_method($available_gateways[$payment_method]);

  // RETRIEVE fees from session (already calculated by /cart/set-shipping)
  $delivery_fee = (float) WC()->session->get('custom_delivery_fee', 0);
  $shipping_fee = (float) WC()->session->get('custom_shipping_fee', 0);

  // Calculate cart subtotal for response
  $cart_subtotal = 0;
  foreach (WC()->cart->get_cart() as $cart_item) {
    $cart_subtotal += $cart_item['line_total']; 
  }

  // Add fees as WooCommerce order items
  if ($delivery_fee > 0) {
    $fee = new WC_Order_Item_Fee();
    $fee->set_name('Delivery Fee');
    $fee->set_total($delivery_fee);
    $order->add_item($fee);
  }

  if ($shipping_fee > 0) {
    $fee = new WC_Order_Item_Fee();
    $fee->set_name('Shipping Fee');
    $fee->set_total($shipping_fee);
    $order->add_item($fee);
  }

  // Now calculate total including fees
  $order->calculate_totals();
  $order->save();

  WC()->cart->empty_cart();
  app_save_cart_session($user->ID);

  // Build response
  $response = [
    'success'     => true,
    'order_id'    => $order->get_id(),
    'order_key'   => $order->get_order_key(),
    'status'      => $order->get_status(),
    'subtotal'    => $cart_subtotal,
    'delivery_fee'=> $delivery_fee,
    'shipping_fee'=> $shipping_fee,
    'total'       => $order->get_total(),
    'currency'    => $order->get_currency(),
  ];

  // Only return payment link if not COD
  if ($payment_method !== 'cod') {
    $response['payment_url'] = $order->get_checkout_payment_url();
  }

  return new WP_REST_Response($response, 201);

}
add_action('rest_api_init', function () {
  register_rest_route('custom/v1', '/payment-gateways', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function() {
      $gateways = WC()->payment_gateways->payment_gateways();
      $out = [];
      foreach ($gateways as $id => $gateway) {
        $out[$id] = $gateway->get_title();
      }
      return $out;
    }
  ]);
});


  // VOUCHER ROUTES (GET)
  add_action('rest_api_init', function () {
    
    // Apply voucher
    register_rest_route('custom/v1', '/voucher/apply', [
      'methods'  => 'GET',
      'permission_callback' => '__return_true',
      'args' => [
        'code' => ['type'=>'string','required'=>true, 'description'=>'Voucher code (coupon)'],
      ],
      'callback' => 'app_voucher_apply_enhanced', 
    ]);
    
    // Remove voucher
    register_rest_route('custom/v1', '/voucher/remove', [
      'methods'  => 'GET',
      'permission_callback' => '__return_true',
      'args' => [
        'code' => ['type'=>'string','required'=>true, 'description'=>'Voucher code to remove'],
      ],
      'callback' => 'app_voucher_remove_enhanced', 
    ]);
    
    // Get applied vouchers
    register_rest_route('custom/v1', '/voucher', [
      'methods'  => 'GET',
      'permission_callback' => '__return_true',
      'callback' => 'app_voucher_list_get',
    ]);
  });

  function app_voucher_apply_enhanced(WP_REST_Request $req) {
    $user = app_auth_current_user_from_request($req);
    if (!$user) return new WP_Error('unauthorized','Invalid or missing token.',['status'=>401]);
    
    app_bootstrap_wc_session_and_cart();
    app_load_cart_session($user->ID); 
    
    $code = sanitize_text_field($req->get_param('code'));
    if (empty($code)) {
      return new WP_Error('invalid_code','Coupon code required.',['status'=>400]);
    }
    
    $coupon = new WC_Coupon($code);
    if (!$coupon->get_id()) {
      return new WP_Error('not_found','Invalid coupon code.',['status'=>404]);
    }
    
    $result = WC()->cart->apply_coupon($code);
    WC()->cart->calculate_totals();
    
    // Save to user meta for persistence across API calls
    app_save_cart_session($user->ID);
    
    if (!$result) {
      return new WP_Error('apply_failed','Could not apply this voucher.',['status'=>400]);
    }
    
    return new WP_REST_Response([
      'applied' => true,
      'code'    => $code,
      'cart'    => app_cart_snapshot(),
    ], 200);
  }

  function app_voucher_remove_enhanced(WP_REST_Request $req) {
    $user = app_auth_current_user_from_request($req);
    if (!$user) {
      return new WP_Error('unauthorized','Invalid or missing token.',['status'=>401]);
    }
    
    app_bootstrap_wc_session_and_cart();
    app_load_cart_session($user->ID); 
    
    $code = sanitize_text_field($req->get_param('code'));
    if (!$code) {
      return new WP_Error('invalid_code','Coupon code required.',['status'=>400]);
    }
    
    $result = WC()->cart->remove_coupon($code);
    if (!$result) {
      $code_lower = wc_strtolower($code);
      $applied = WC()->cart->get_applied_coupons();
      
      if (($key = array_search($code_lower, $applied)) !== false) {
        unset($applied[$key]);
        WC()->cart->set_applied_coupons($applied);
        $result = true;
      }
    }
    
    WC()->cart->calculate_totals();
    
    // Save to user meta for persistence across API calls
    app_save_cart_session($user->ID);
    
    return new WP_REST_Response([
      'removed' => $result,
      'code'    => $code,
      'cart'    => app_cart_snapshot(),
    ], 200);
  }

  function app_voucher_list_get(WP_REST_Request $req) {
    $user = app_auth_current_user_from_request($req);
    if (!$user) return new WP_Error('unauthorized','Invalid or missing token.',['status'=>401]);
    
    app_bootstrap_wc_session_and_cart();
    app_load_cart_session($user->ID);
    
    $coupons = WC()->cart->get_applied_coupons();
    
    return new WP_REST_Response([
      'ok'      => true,
      'coupons' => $coupons,
      'cart'    => app_cart_snapshot(),
    ], 200);
  }