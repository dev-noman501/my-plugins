<?php
/**
 * Plugin Name: WP Migrate Suite
 * Description: Export any post type (content, images, ACF fields, RankMath/Yoast SEO meta) from one WordPress site and import it into another - whole post types, or specific posts by URL.
 * Version: 2.2.0
 * Author: Noman Nadeem
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BCM_VERSION', '2.2.0' );
define( 'BCM_META_SOURCE_ID', '_bcm_source_id' );
define( 'BCM_META_SOURCE_URL', '_bcm_source_url' );

// Postmeta keys that are site-specific / internal and should never be copied between sites.
define( 'BCM_META_BLACKLIST', serialize( array(
	'_edit_lock',
	'_edit_last',
	'_wp_old_slug',
	'_wp_old_date',
	'_wp_trash_meta_status',
	'_wp_trash_meta_time',
	'_wp_desired_post_slug',
	'_thumbnail_id',
	'_oembed_time',
) ) );

register_activation_hook( __FILE__, 'bcm_activate' );
function bcm_activate() {
	if ( ! get_option( 'bcm_secret_key' ) ) {
		update_option( 'bcm_secret_key', wp_generate_password( 40, false ) );
	}
}

/* ---------------------------------------------------------------------
 * Auto-provision missing post types on the TARGET site so imported
 * content from a post type this site has never heard of (e.g. a CPT that
 * belongs to a plugin only installed on the source) still shows up in
 * the admin instead of silently existing only in the database.
 * ------------------------------------------------------------------- */

add_action( 'init', 'bcm_register_dynamic_post_types', 5 );
function bcm_register_dynamic_post_types() {
	$types = get_option( 'bcm_dynamic_post_types', array() );
	foreach ( $types as $slug => $label ) {
		if ( post_type_exists( $slug ) ) {
			continue; // A real plugin/theme already owns this slug - don't shadow it.
		}
		register_post_type( $slug, array(
			'label'        => $label,
			'public'       => true,
			'show_ui'      => true,
			'show_in_menu' => true,
			'has_archive'  => true,
			'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
			'menu_icon'    => 'dashicons-admin-page',
		) );
	}
}

function bcm_ensure_post_type_registered( $slug, $label ) {
	if ( post_type_exists( $slug ) ) {
		return; // Either a real plugin already defines it, or we've already registered it this request.
	}
	$types = get_option( 'bcm_dynamic_post_types', array() );
	if ( ! isset( $types[ $slug ] ) ) {
		$types[ $slug ] = $label;
		update_option( 'bcm_dynamic_post_types', $types );
	}
	bcm_register_dynamic_post_types();
}

add_action( 'init', 'bcm_register_dynamic_taxonomies', 6 );
function bcm_register_dynamic_taxonomies() {
	$taxes = get_option( 'bcm_dynamic_taxonomies', array() );
	foreach ( $taxes as $slug => $info ) {
		if ( taxonomy_exists( $slug ) ) {
			continue; // A real plugin/theme already owns this slug - don't shadow it.
		}
		register_taxonomy( $slug, $info['post_types'], array(
			'label'             => $info['label'],
			'hierarchical'      => ! empty( $info['hierarchical'] ),
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
		) );
	}
}

function bcm_ensure_taxonomy_registered( $slug, $label, $hierarchical, $post_type ) {
	if ( taxonomy_exists( $slug ) ) {
		register_taxonomy_for_object_type( $slug, $post_type );
		return;
	}
	$taxes = get_option( 'bcm_dynamic_taxonomies', array() );
	if ( ! isset( $taxes[ $slug ] ) ) {
		$taxes[ $slug ] = array( 'label' => $label, 'hierarchical' => $hierarchical, 'post_types' => array( $post_type ) );
	} elseif ( ! in_array( $post_type, $taxes[ $slug ]['post_types'], true ) ) {
		$taxes[ $slug ]['post_types'][] = $post_type;
	}
	update_option( 'bcm_dynamic_taxonomies', $taxes );
	bcm_register_dynamic_taxonomies();
	register_taxonomy_for_object_type( $slug, $post_type );
}

/* ---------------------------------------------------------------------
 * Admin menu
 * ------------------------------------------------------------------- */

add_action( 'admin_menu', 'bcm_admin_menu' );
function bcm_admin_menu() {
	add_management_page(
		'WP Migrate Suite',
		'WP Migrate Suite',
		'manage_options',
		'blog-content-migrator',
		'bcm_render_admin_page'
	);
}

/**
 * Page builders and their add-ons (Elementor, ElementsKit, Royal Addons, etc.)
 * register their own "post types" purely to store templates/widgets/menus
 * internally - never real site content. Hide those from the migrator so the
 * post type list only shows things a person would actually want to migrate.
 */
function bcm_is_builder_internal_post_type( $slug ) {
	if ( in_array( $slug, array( 'post', 'page' ), true ) ) {
		return false;
	}

	$exact = array(
		'elementor_library',
		'elementor-hf',
		'e-landing-page',
		'elementskit_content',
		'elementskit_template',
		'elementskit_widget',
		'wpr_templates',
		'wpr_mega_menu',
		'wpr_template',
	);
	if ( in_array( $slug, $exact, true ) ) {
		return true;
	}

	$keywords = array( 'elementor', 'elementskit', 'wpr_', 'template', 'mega_menu', 'widget', 'library' );
	foreach ( $keywords as $keyword ) {
		if ( false !== strpos( $slug, $keyword ) ) {
			return true;
		}
	}

	return false;
}

function bcm_local_post_types() {
	$types = get_post_types( array( 'public' => true ), 'objects' );
	$out   = array();
	foreach ( $types as $slug => $obj ) {
		if ( 'attachment' === $slug || bcm_is_builder_internal_post_type( $slug ) ) {
			continue;
		}
		$count = wp_count_posts( $slug );
		$out[] = array(
			'slug'     => $slug,
			'label'    => $obj->labels->name,
			'count'    => isset( $count->publish ) ? (int) $count->publish : 0,
			'uses_acf' => bcm_post_type_uses_acf( $slug ),
		);
	}
	return $out;
}

function bcm_post_type_uses_acf( $post_type ) {
	if ( ! function_exists( 'acf_get_field_groups' ) ) {
		return false;
	}
	$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
	return ! empty( $groups );
}

function bcm_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['bcm_regenerate_key'] ) && check_admin_referer( 'bcm_regenerate_key' ) ) {
		update_option( 'bcm_secret_key', wp_generate_password( 40, false ) );
		echo '<div class="notice notice-success"><p>New secret key generated.</p></div>';
	}

	$secret_key  = get_option( 'bcm_secret_key' );
	$export_url  = trailingslashit( get_rest_url( null, 'blog-migrator/v1/export' ) );
	$nonce       = wp_create_nonce( 'bcm_import_nonce' );
	$local_types = bcm_local_post_types();
	?>
	<div class="wrap bcm-wrap">
		<div class="bcm-header">
			<h1>WP Migrate Suite</h1>
			<p>Move posts, pages, and any custom post type - with images, links and custom fields - from one WordPress site straight into this one.</p>
		</div>

		<details class="bcm-card bcm-collapsible">
			<summary class="bcm-card-title"><span class="bcm-step-badge">Source</span> This site's export details <span class="bcm-collapse-hint">(click to expand)</span></summary>
			<p class="bcm-hint">If <em>this</em> site is the old site you're migrating <strong>from</strong>, copy the key below into the Import step on the other site.</p>

			<div class="bcm-field-row">
				<div class="bcm-field-label">Export endpoint</div>
				<div class="bcm-field-value">
					<span class="bcm-copy-field">
						<code id="bcm-export-url"><?php echo esc_html( $export_url ); ?></code>
						<button type="button" class="bcm-copy-btn" data-copy-target="bcm-export-url" title="Copy">
							<span class="dashicons dashicons-clipboard"></span>
						</button>
					</span>
				</div>
			</div>
			<div class="bcm-field-row">
				<div class="bcm-field-label">Secret key</div>
				<div class="bcm-field-value">
					<span class="bcm-copy-field">
						<code id="bcm-secret-key" data-value="<?php echo esc_attr( $secret_key ); ?>" class="bcm-masked">••••••••••••••••••••••••••••••••••••••••</code>
						<button type="button" class="bcm-eye-btn" id="bcm-toggle-key" title="Show/hide">
							<span class="dashicons dashicons-visibility"></span>
						</button>
						<button type="button" class="bcm-copy-btn" data-copy-target="bcm-secret-key" title="Copy">
							<span class="dashicons dashicons-clipboard"></span>
						</button>
					</span>
					<form method="post" style="display:inline-block;margin-left:10px;">
						<?php wp_nonce_field( 'bcm_regenerate_key' ); ?>
						<button type="submit" name="bcm_regenerate_key" class="button button-small" onclick="return confirm('Regenerating will invalidate the old key. Continue?');">Regenerate</button>
					</form>
				</div>
			</div>
			<div class="bcm-field-row">
				<div class="bcm-field-label">Post types available here</div>
				<div class="bcm-field-value">
					<div class="bcm-type-chips">
					<?php foreach ( $local_types as $t ) : ?>
						<span class="bcm-chip"><code><?php echo esc_html( $t['slug'] ); ?></code> <?php echo esc_html( $t['label'] ); ?> <b><?php echo (int) $t['count']; ?></b><?php echo $t['uses_acf'] ? ' <span class="bcm-acf-badge">ACF</span>' : ''; ?></span>
					<?php endforeach; ?>
					</div>
				</div>
			</div>
		</details>

		<div class="bcm-card">
			<div class="bcm-card-title"><span class="bcm-step-badge">Step 1</span> Connect to the old site</div>
			<p class="bcm-hint">Enter the <strong>old site's</strong> URL (the one you're pulling content from) and the secret key shown on its WP Migrate Suite page.</p>

			<div class="bcm-connect-row">
				<div>
					<label for="bcm-source-url">Source site URL</label>
					<input type="url" id="bcm-source-url" class="bcm-input" placeholder="https://old-site.com" />
				</div>
				<div>
					<label for="bcm-source-key">Source secret key</label>
					<div class="bcm-input-with-icon">
						<input type="password" id="bcm-source-key" class="bcm-input" placeholder="paste the source site's secret key" autocomplete="off" />
						<button type="button" class="bcm-eye-btn bcm-input-eye" id="bcm-toggle-source-key" title="Show/hide">
							<span class="dashicons dashicons-visibility"></span>
						</button>
					</div>
				</div>
				<div>
					<label>&nbsp;</label>
					<button type="button" id="bcm-connect" class="button button-primary bcm-btn-connect">Connect</button>
				</div>
			</div>
			<div id="bcm-connect-status"></div>
		</div>

		<div id="bcm-connected-area" class="bcm-card" style="display:none;">
			<div class="bcm-card-title"><span class="bcm-step-badge">Step 2</span> Choose what to bring over</div>

			<div class="bcm-tabs">
				<button type="button" class="bcm-tab active" data-tab="types">Whole post type(s)</button>
				<button type="button" class="bcm-tab" data-tab="urls">Specific posts / pages by URL</button>
			</div>

			<div class="bcm-tab-panel" data-panel="types">
				<div class="bcm-list-toolbar">
					<label><input type="checkbox" id="bcm-select-all" /> Select all</label>
				</div>
				<div id="bcm-post-types-list" class="bcm-type-list">Loading...</div>
				<p><button type="button" id="bcm-import-types" class="button button-primary button-hero">Import selected post type(s)</button></p>
			</div>

			<div class="bcm-tab-panel" data-panel="urls" style="display:none;">
				<p class="bcm-hint">Paste one or many URLs from the old site - one per line, or comma separated. Works for a single blog post, a single page, or a big bulk list.</p>
				<textarea id="bcm-urls" rows="3" class="bcm-textarea" placeholder="https://old-site.com/blog/my-post/&#10;https://old-site.com/about-us/&#10;https://old-site.com/portfolio/my-project/"></textarea>
				<p>
					<button type="button" id="bcm-resolve-urls" class="button">Preview / resolve URLs</button>
					<button type="button" id="bcm-import-urls" class="button button-primary button-hero" style="display:none;">Import resolved posts</button>
				</p>
				<div id="bcm-resolve-results"></div>
			</div>
		</div>

		<div id="bcm-progress-card" class="bcm-card" style="display:none;">
			<div class="bcm-card-title"><span class="bcm-step-badge">Step 3</span> Import progress</div>
			<div class="bcm-progress-bar-track">
				<div id="bcm-progress-bar" class="bcm-progress-bar-fill" style="width:0%;"></div>
			</div>
			<div id="bcm-progress-text" class="bcm-progress-text">Waiting to start...</div>
			<div id="bcm-log" class="bcm-log"></div>
		</div>
	</div>

	<style>
	.bcm-wrap { max-width: 900px; }
	.bcm-header { margin-bottom: 4px; }
	.bcm-header h1 { margin-bottom: 2px; }
	.bcm-header p { font-size: 13px; color: #666; max-width: 640px; margin: 0 0 14px; }
	.bcm-card { background: #fff; border: 1px solid #e2e4e7; border-radius: 8px; padding: 14px 18px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
	.bcm-card-title { font-size: 14px; font-weight: 600; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
	.bcm-collapsible summary.bcm-card-title { cursor: pointer; list-style: none; margin-bottom: 0; }
	.bcm-collapsible summary.bcm-card-title::-webkit-details-marker { display: none; }
	.bcm-collapsible[open] summary.bcm-card-title { margin-bottom: 4px; }
	.bcm-collapse-hint { font-size: 11px; font-weight: 400; color: #888; }
	.bcm-collapsible:not([open]) .bcm-hint { display: none; }
	.bcm-step-badge { background: #2271b1; color: #fff; font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em; }
	.bcm-hint { color: #666; font-size: 12px; margin: 2px 0 10px; }
	.bcm-field-row { display: flex; padding: 7px 0; border-top: 1px solid #f0f0f1; align-items: center; flex-wrap: wrap; }
	.bcm-field-row:first-of-type { border-top: none; }
	.bcm-field-label { width: 210px; flex-shrink: 0; color: #444; font-weight: 500; font-size: 13px; }
	.bcm-field-value { flex: 1; min-width: 260px; }
	.bcm-copy-field { display: inline-flex; align-items: center; gap: 2px; background: #f6f7f7; border: 1px solid #e2e4e7; border-radius: 5px; padding: 3px 4px 3px 10px; }
	.bcm-copy-field code { background: none; padding: 0; }
	.bcm-copy-field .bcm-masked { letter-spacing: 1px; color: #888; }
	.bcm-copy-btn, .bcm-eye-btn { background: none; border: none; cursor: pointer; padding: 4px 6px; border-radius: 4px; color: #555; display: inline-flex; align-items: center; }
	.bcm-copy-btn:hover, .bcm-eye-btn:hover { background: #e2e4e7; color: #2271b1; }
	.bcm-copy-btn .dashicons, .bcm-eye-btn .dashicons { width: 16px; height: 16px; font-size: 16px; }
	.bcm-copy-btn.bcm-copied .dashicons:before { content: "\f147"; color: #1a7f37; }
	.bcm-type-chips { display: flex; flex-wrap: wrap; gap: 8px; }
	.bcm-chip { background: #f6f7f7; border: 1px solid #e2e4e7; border-radius: 6px; padding: 4px 10px; font-size: 12px; color: #333; }
	.bcm-chip code { background: none; padding: 0; color: #2271b1; }
	.bcm-chip b { margin-left: 4px; }
	.bcm-acf-badge { background: #7c3aed; color: #fff; border-radius: 4px; padding: 1px 5px; font-size: 10px; margin-left: 4px; }
	.bcm-connect-row { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
	.bcm-connect-row > div { flex: 1; min-width: 220px; }
	.bcm-input-with-icon { position: relative; }
	.bcm-input-with-icon .bcm-input { padding-right: 34px; }
	.bcm-input-eye { position: absolute; right: 2px; top: 50%; transform: translateY(-50%); }
	.bcm-connect-row label { display: block; font-size: 12px; font-weight: 600; color: #444; margin-bottom: 4px; }
	.bcm-input, .bcm-textarea { width: 100%; box-sizing: border-box; border: 1px solid #ccd0d4; border-radius: 4px; padding: 7px 10px; font-size: 13px; }
	.bcm-btn-connect { white-space: nowrap; }
	#bcm-connect-status { margin-top: 8px; font-size: 12px; }
	.bcm-status-ok { color: #1a7f37; font-weight: 600; }
	.bcm-status-error { color: #b32d2e; font-weight: 600; }
	.bcm-tabs { display: flex; gap: 4px; border-bottom: 1px solid #e2e4e7; margin-bottom: 12px; }
	.bcm-tab { background: none; border: none; padding: 7px 14px; font-size: 13px; font-weight: 600; color: #666; cursor: pointer; border-bottom: 2px solid transparent; }
	.bcm-tab.active { color: #2271b1; border-bottom-color: #2271b1; }
	.bcm-list-toolbar { margin-bottom: 6px; font-size: 12px; }
	.bcm-type-list { display: flex; flex-direction: column; gap: 5px; max-height: 240px; overflow-y: auto; padding-right: 4px; }
	.bcm-type-row { display: flex; align-items: center; gap: 10px; border: 1px solid #e2e4e7; border-radius: 6px; padding: 7px 12px; font-size: 13px; }
	.bcm-type-row:hover { border-color: #2271b1; background: #f8fbff; }
	.bcm-type-row .bcm-type-slug { color: #2271b1; }
	.bcm-type-row .bcm-type-count { margin-left: auto; color: #666; }
	.bcm-textarea { font-family: Consolas, Monaco, monospace; font-size: 12px; }
	#bcm-resolve-results ul { margin: 10px 0 0; list-style: none; padding: 0; }
	#bcm-resolve-results li { padding: 6px 10px; border-radius: 4px; margin-bottom: 4px; font-size: 13px; background: #f6f7f7; }
	#bcm-resolve-results li.bcm-not-found { background: #fcf0f1; color: #b32d2e; }
	.bcm-progress-bar-track { background: #eef0f2; border-radius: 999px; height: 10px; overflow: hidden; }
	.bcm-progress-bar-fill { background: linear-gradient(90deg,#2271b1,#72aee6); height: 100%; width: 0%; transition: width .3s ease; }
	.bcm-progress-text { font-size: 13px; color: #444; margin: 8px 0 14px; }
	.bcm-log { background: #1e1e1e; color: #d4d4d4; border-radius: 6px; padding: 12px 14px; max-height: 360px; overflow-y: auto; font-family: Consolas, Monaco, monospace; font-size: 12px; line-height: 1.7; }
	.bcm-log-row.status-imported { color: #7ee787; }
	.bcm-log-row.status-skipped { color: #e3b341; }
	.bcm-log-row.status-error { color: #ff7b72; }
	.bcm-log-row.status-info { color: #79c0ff; }
	.bcm-spinner { display: inline-block; width: 13px; height: 13px; border: 2px solid #c3c4c7; border-top-color: #2271b1; border-radius: 50%; animation: bcm-spin 0.7s linear infinite; vertical-align: middle; margin-right: 6px; }
	@keyframes bcm-spin { to { transform: rotate(360deg); } }
	</style>

	<script>
	(function(){
		var nonce      = <?php echo wp_json_encode( $nonce ); ?>;
		var ajaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var thisSiteHost = <?php echo wp_json_encode( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>;

		var log             = document.getElementById('bcm-log');
		var progressCard    = document.getElementById('bcm-progress-card');
		var progressBar     = document.getElementById('bcm-progress-bar');
		var progressText    = document.getElementById('bcm-progress-text');
		var connectBtn      = document.getElementById('bcm-connect');
		var connectStatus   = document.getElementById('bcm-connect-status');
		var typesList       = document.getElementById('bcm-post-types-list');
		var connectedArea   = document.getElementById('bcm-connected-area');
		var selectAll        = document.getElementById('bcm-select-all');
		var importTypesBtn  = document.getElementById('bcm-import-types');
		var resolveBtn       = document.getElementById('bcm-resolve-urls');
		var importUrlsBtn    = document.getElementById('bcm-import-urls');
		var resolveResults   = document.getElementById('bcm-resolve-results');

		var lastResolved = [];

		function appendLog(text, status) {
			var row = document.createElement('div');
			row.className = 'bcm-log-row status-' + (status || 'info');
			row.textContent = text;
			log.appendChild(row);
			log.scrollTop = log.scrollHeight;
		}

		function resetLog() {
			log.innerHTML = '';
			progressCard.style.display = 'block';
			setProgress(0, 'Starting...');
		}

		function setProgress(percent, text) {
			progressBar.style.width = Math.max(0, Math.min(100, percent)) + '%';
			progressText.textContent = text;
		}

		function sourceUrl() { return document.getElementById('bcm-source-url').value.trim(); }
		function sourceKey() { return document.getElementById('bcm-source-key').value.trim(); }

		function post(action, extra) {
			var params = new URLSearchParams();
			params.append('action', action);
			params.append('nonce', nonce);
			for (var k in extra) { params.append(k, extra[k]); }
			return fetch(ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: params.toString()
			}).then(function(r){ return r.json(); });
		}

		var secretKeyEl = document.getElementById('bcm-secret-key');
		var toggleKeyBtn = document.getElementById('bcm-toggle-key');
		if (toggleKeyBtn) {
			toggleKeyBtn.addEventListener('click', function(){
				var showing = !secretKeyEl.classList.contains('bcm-masked');
				if (showing) {
					secretKeyEl.textContent = '••••••••••••••••••••••••••••••••••••••••';
					secretKeyEl.classList.add('bcm-masked');
					toggleKeyBtn.querySelector('.dashicons').className = 'dashicons dashicons-visibility';
				} else {
					secretKeyEl.textContent = secretKeyEl.getAttribute('data-value');
					secretKeyEl.classList.remove('bcm-masked');
					toggleKeyBtn.querySelector('.dashicons').className = 'dashicons dashicons-hidden';
				}
			});
		}

		var sourceKeyInput = document.getElementById('bcm-source-key');
		var toggleSourceKeyBtn = document.getElementById('bcm-toggle-source-key');
		if (toggleSourceKeyBtn) {
			toggleSourceKeyBtn.addEventListener('click', function(){
				var showing = sourceKeyInput.type === 'text';
				sourceKeyInput.type = showing ? 'password' : 'text';
				toggleSourceKeyBtn.querySelector('.dashicons').className = 'dashicons ' + (showing ? 'dashicons-visibility' : 'dashicons-hidden');
			});
		}

		function copyText(text) {
			if (navigator.clipboard && window.isSecureContext) {
				return navigator.clipboard.writeText(text);
			}
			return new Promise(function(resolve, reject){
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.style.position = 'fixed';
				ta.style.opacity = '0';
				document.body.appendChild(ta);
				ta.focus();
				ta.select();
				var ok = false;
				try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
				document.body.removeChild(ta);
				ok ? resolve() : reject(new Error('copy failed'));
			});
		}

		document.querySelectorAll('.bcm-copy-btn').forEach(function(btn){
			btn.addEventListener('click', function(){
				var targetEl = document.getElementById(btn.getAttribute('data-copy-target'));
				var text = targetEl.hasAttribute('data-value') ? targetEl.getAttribute('data-value') : targetEl.textContent;
				copyText(text).then(function(){
					btn.classList.add('bcm-copied');
					setTimeout(function(){ btn.classList.remove('bcm-copied'); }, 1200);
				}).catch(function(){
					alert('Could not copy automatically. Value:\n\n' + text);
				});
			});
		});

		document.querySelectorAll('.bcm-tab').forEach(function(tab){
			tab.addEventListener('click', function(){
				document.querySelectorAll('.bcm-tab').forEach(function(t){ t.classList.remove('active'); });
				tab.classList.add('active');
				var target = tab.getAttribute('data-tab');
				document.querySelectorAll('.bcm-tab-panel').forEach(function(p){
					p.style.display = (p.getAttribute('data-panel') === target) ? 'block' : 'none';
				});
			});
		});

		connectBtn.addEventListener('click', function(){
			if (!sourceUrl() || !sourceKey()) {
				alert('Please enter the source site URL and secret key.');
				return;
			}
			var typedHost = sourceUrl().replace(/^https?:\/\//i, '').replace(/\/.*$/, '').toLowerCase();
			if (typedHost === thisSiteHost.toLowerCase()) {
				alert('That is this site\'s own URL. Enter the OLD site\'s URL instead - the one you want to pull content FROM, not this one.');
				return;
			}
			connectStatus.innerHTML = '<span class="bcm-spinner"></span> Connecting...';
			connectBtn.disabled = true;
			typesList.textContent = 'Loading...';
			connectedArea.style.display = 'block';
			post('bcm_fetch_post_types', { source_url: sourceUrl(), source_key: sourceKey() })
				.then(function(res){
					connectBtn.disabled = false;
					if (!res.success) {
						connectStatus.innerHTML = '<span class="bcm-status-error">Connection failed - ' + (res.data && res.data.message ? res.data.message : 'could not connect') + '</span>';
						typesList.textContent = '';
						return;
					}
					connectStatus.innerHTML = '<span class="bcm-status-ok">Connected</span> - found ' + res.data.length + ' post type(s) on the source site.';
					var html = '';
					res.data.forEach(function(t){
						html += '<label class="bcm-type-row">' +
							'<input type="checkbox" class="bcm-type-checkbox" value="' + t.slug + '" data-acf="' + (t.uses_acf ? '1' : '0') + '"> ' +
							'<span class="bcm-type-slug"><code>' + t.slug + '</code></span> ' + t.label +
							(t.uses_acf ? ' <span class="bcm-acf-badge">ACF</span>' : '') +
							'<span class="bcm-type-count">' + t.count + ' published</span>' +
							'</label>';
					});
					typesList.innerHTML = html || 'No post types found on the source site.';
				})
				.catch(function(err){
					connectBtn.disabled = false;
					connectStatus.innerHTML = '<span class="bcm-status-error">Error - ' + err.message + '</span>';
				});
		});

		selectAll.addEventListener('change', function(){
			document.querySelectorAll('.bcm-type-checkbox').forEach(function(c){ c.checked = selectAll.checked; });
		});

		// Auto-provisions this site before an import: registers any missing
		// post type and recreates the matching ACF field group. Returns
		// true if it's safe to proceed, false if it hard-blocked (e.g. ACF
		// isn't installed at all for a post type that needs it).
		function prepareImport(postTypes) {
			return post('bcm_prepare_import', {
				source_url: sourceUrl(),
				source_key: sourceKey(),
				post_types: JSON.stringify(postTypes)
			}).then(function(res){
				if (!res.success) {
					alert((res.data && res.data.message) ? res.data.message : 'Could not prepare this site for import.');
					return false;
				}
				(res.data.log || []).forEach(function(line){ appendLog(line, 'info'); });
				return true;
			});
		}

		function runBatchForType(postType, page, importedSoFar) {
			return post('bcm_run_import_batch', {
				source_url: sourceUrl(),
				source_key: sourceKey(),
				post_type: postType,
				page: page
			}).then(function(res){
				if (!res.success) {
					appendLog('ERROR (' + postType + '): ' + (res.data && res.data.message ? res.data.message : 'Unknown error'), 'error');
					return importedSoFar;
				}
				var data = res.data;
				(data.results || []).forEach(function(r){
					appendLog('[' + postType + '] ' + r.title + (r.message ? ' - ' + r.message : ''), r.status);
				});
				importedSoFar += data.imported_count || 0;
				var total = data.total || importedSoFar;
				setProgress((importedSoFar / total) * 100, postType + ': ' + importedSoFar + ' / ' + total);
				if (data.done) {
					return importedSoFar;
				}
				return runBatchForType(postType, page + 1, importedSoFar);
			});
		}

		function runBatchForIds(postType, ids) {
			var chunks = [];
			for (var i = 0; i < ids.length; i += 5) { chunks.push(ids.slice(i, i + 5)); }
			var done = 0;

			var chain = Promise.resolve();
			chunks.forEach(function(chunk){
				chain = chain.then(function(){
					return post('bcm_run_import_batch', {
						source_url: sourceUrl(),
						source_key: sourceKey(),
						post_type: postType,
						include: chunk.join(',')
					}).then(function(res){
						if (!res.success) {
							appendLog('ERROR (' + postType + '): ' + (res.data && res.data.message ? res.data.message : 'Unknown error'), 'error');
							return;
						}
						(res.data.results || []).forEach(function(r){
							appendLog('[' + postType + '] ' + r.title + (r.message ? ' - ' + r.message : ''), r.status);
						});
						done += chunk.length;
						setProgress((done / ids.length) * 100, postType + ': ' + done + ' / ' + ids.length);
					});
				});
			});
			return chain;
		}

		importTypesBtn.addEventListener('click', function(){
			var checked = Array.prototype.slice.call(document.querySelectorAll('.bcm-type-checkbox:checked'));
			if (!checked.length) {
				alert('Select at least one post type to import.');
				return;
			}
			var postTypes = checked.map(function(c){ return c.value; });

			resetLog();
			importTypesBtn.disabled = true;
			prepareImport(postTypes).then(function(ok){
				if (!ok) { importTypesBtn.disabled = false; return; }
				var chain = Promise.resolve();
				postTypes.forEach(function(pt){
					chain = chain.then(function(){
						appendLog('--- Importing post type: ' + pt + ' ---', 'info');
						return runBatchForType(pt, 1, 0);
					});
				});
				chain.then(function(){
					appendLog('--- All selected post types finished. ---', 'info');
					setProgress(100, 'Done.');
					importTypesBtn.disabled = false;
				});
			});
		});

		resolveBtn.addEventListener('click', function(){
			var urls = document.getElementById('bcm-urls').value.trim();
			if (!urls || !sourceUrl() || !sourceKey()) {
				alert('Enter the source URL/key above and paste at least one URL.');
				return;
			}
			resolveResults.textContent = 'Resolving...';
			post('bcm_resolve_urls', { source_url: sourceUrl(), source_key: sourceKey(), urls: urls })
				.then(function(res){
					if (!res.success) {
						resolveResults.textContent = 'Error: ' + (res.data && res.data.message ? res.data.message : 'could not resolve');
						importUrlsBtn.style.display = 'none';
						return;
					}
					lastResolved = res.data.filter(function(r){ return r.found; });
					var notFound = res.data.filter(function(r){ return !r.found; });
					var html = '<ul>';
					lastResolved.forEach(function(r){
						html += '<li>' + r.title + ' <code>(' + r.post_type + ')</code></li>';
					});
					notFound.forEach(function(r){
						html += '<li class="bcm-not-found">Not found - ' + r.url + '</li>';
					});
					html += '</ul>';
					resolveResults.innerHTML = html;
					importUrlsBtn.style.display = lastResolved.length ? 'inline-block' : 'none';
				});
		});

		importUrlsBtn.addEventListener('click', function(){
			if (!lastResolved.length) { return; }
			var byType = {};
			lastResolved.forEach(function(r){
				byType[r.post_type] = byType[r.post_type] || [];
				byType[r.post_type].push(r.id);
			});
			var postTypes = Object.keys(byType);

			resetLog();
			importUrlsBtn.disabled = true;
			prepareImport(postTypes).then(function(ok){
				if (!ok) { importUrlsBtn.disabled = false; return; }
				var chain = Promise.resolve();
				postTypes.forEach(function(pt){
					chain = chain.then(function(){
						appendLog('--- Importing ' + byType[pt].length + ' post(s) of type: ' + pt + ' ---', 'info');
						return runBatchForIds(pt, byType[pt]);
					});
				});
				chain.then(function(){
					appendLog('--- Import finished. ---', 'info');
					setProgress(100, 'Done.');
					importUrlsBtn.disabled = false;
				});
			});
		});
	})();
	</script>
	<?php
}

/* ---------------------------------------------------------------------
 * REST API (runs on the SOURCE/old site)
 * ------------------------------------------------------------------- */

add_action( 'rest_api_init', 'bcm_register_routes' );
function bcm_register_routes() {
	register_rest_route(
		'blog-migrator/v1',
		'/export',
		array(
			'methods'             => 'GET',
			'callback'            => 'bcm_handle_export',
			'permission_callback' => 'bcm_check_export_permission',
			'args'                => array(
				'page'      => array( 'default' => 1 ),
				'per_page'  => array( 'default' => 5 ),
				'post_type' => array( 'default' => 'post' ),
				'include'   => array( 'default' => '' ),
			),
		)
	);

	register_rest_route(
		'blog-migrator/v1',
		'/post-types',
		array(
			'methods'             => 'GET',
			'callback'            => 'bcm_handle_post_types_endpoint',
			'permission_callback' => 'bcm_check_export_permission',
		)
	);

	register_rest_route(
		'blog-migrator/v1',
		'/resolve-urls',
		array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => 'bcm_handle_resolve_urls',
			'permission_callback' => 'bcm_check_export_permission',
		)
	);

	register_rest_route(
		'blog-migrator/v1',
		'/acf-field-groups',
		array(
			'methods'             => 'GET',
			'callback'            => 'bcm_handle_acf_field_groups',
			'permission_callback' => 'bcm_check_export_permission',
			'args'                => array(
				'post_type' => array( 'default' => 'post' ),
			),
		)
	);
}

/**
 * Export the ACF field group definition(s) attached to a post type, in the
 * same shape ACF's own "Import Field Groups" tool expects, so the target
 * site can recreate them locally with acf_import_field_group().
 */
function bcm_handle_acf_field_groups( WP_REST_Request $request ) {
	if ( ! function_exists( 'acf_get_field_groups' ) ) {
		return new WP_REST_Response( array(), 200 );
	}

	$post_type = sanitize_key( $request->get_param( 'post_type' ) );
	$groups    = acf_get_field_groups( array( 'post_type' => $post_type ) );
	$out       = array();

	foreach ( $groups as $group ) {
		$group['fields'] = acf_get_fields( $group['key'] );
		if ( function_exists( 'acf_prepare_field_group_for_export' ) ) {
			$group = acf_prepare_field_group_for_export( $group );
		}
		$out[] = $group;
	}

	return new WP_REST_Response( $out, 200 );
}

function bcm_check_export_permission( WP_REST_Request $request ) {
	$provided = $request->get_header( 'x-migrator-key' );
	$stored   = get_option( 'bcm_secret_key' );

	if ( empty( $stored ) || empty( $provided ) || ! hash_equals( $stored, $provided ) ) {
		return new WP_Error( 'bcm_forbidden', 'Invalid or missing secret key.', array( 'status' => 403 ) );
	}

	return true;
}

function bcm_handle_post_types_endpoint( WP_REST_Request $request ) {
	return new WP_REST_Response( bcm_local_post_types(), 200 );
}

function bcm_handle_resolve_urls( WP_REST_Request $request ) {
	$urls = $request->get_param( 'urls' );
	if ( is_string( $urls ) ) {
		$urls = preg_split( '/[\r\n,]+/', $urls );
	}
	$urls = array_filter( array_map( 'trim', (array) $urls ) );

	$results = array();
	foreach ( $urls as $url ) {
		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			$results[] = array( 'url' => $url, 'found' => false );
			continue;
		}
		$post = get_post( $post_id );
		$results[] = array(
			'url'       => $url,
			'found'     => true,
			'id'        => $post_id,
			'post_type' => $post->post_type,
			'title'     => get_the_title( $post ),
		);
	}

	return new WP_REST_Response( $results, 200 );
}

function bcm_handle_export( WP_REST_Request $request ) {
	$page       = max( 1, (int) $request->get_param( 'page' ) );
	$per_page   = min( 20, max( 1, (int) $request->get_param( 'per_page' ) ) );
	$post_type  = sanitize_key( $request->get_param( 'post_type' ) );
	$post_type  = $post_type ?: 'post';
	$include    = trim( (string) $request->get_param( 'include' ) );

	$args = array(
		'post_type' => $post_type,
		'orderby'   => 'ID',
		'order'     => 'ASC',
	);

	if ( '' !== $include ) {
		$ids                     = array_filter( array_map( 'intval', explode( ',', $include ) ) );
		$args['post__in']        = ! empty( $ids ) ? $ids : array( 0 );
		$args['post_status']     = 'any';
		$args['posts_per_page']  = ! empty( $ids ) ? count( $ids ) : 1;
		$args['paged']           = 1;
		$has_more_override       = false;
	} else {
		$args['post_status']    = 'publish';
		$args['posts_per_page'] = $per_page;
		$args['paged']          = $page;
	}

	$query     = new WP_Query( $args );
	$blacklist = unserialize( BCM_META_BLACKLIST );
	$posts     = array();

	foreach ( $query->posts as $post ) {
		$raw_meta = get_post_meta( $post->ID );
		$meta     = array();
		foreach ( $raw_meta as $key => $values ) {
			if ( in_array( $key, $blacklist, true ) ) {
				continue;
			}
			$value        = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
			$meta[ $key ] = bcm_transform_meta_for_export( $post->ID, $key, $value );
		}

		$featured_image = null;
		if ( has_post_thumbnail( $post->ID ) ) {
			$featured_image = wp_get_attachment_image_url( get_post_thumbnail_id( $post->ID ), 'full' );
		}

		$posts[] = array(
			'id'             => $post->ID,
			'post_type'      => $post->post_type,
			'title'          => get_the_title( $post ),
			'slug'           => $post->post_name,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'status'         => $post->post_status,
			'date'           => $post->post_date,
			'date_gmt'       => $post->post_date_gmt,
			'taxonomies'     => bcm_export_post_taxonomies( $post ),
			'featured_image' => $featured_image,
			'meta'           => $meta,
			'source_url'     => get_permalink( $post ),
		);
	}

	return new WP_REST_Response( array(
		'page'     => $page,
		'per_page' => $per_page,
		'total'    => (int) $query->found_posts,
		'has_more' => isset( $has_more_override ) ? $has_more_override : ( $page < (int) $query->max_num_pages ),
		'posts'    => $posts,
	), 200 );
}

/**
 * Export every taxonomy term assigned to a post - not just categories/tags,
 * but any custom taxonomy registered for its post type - including each
 * term's parent (by slug) so hierarchy can be rebuilt on the target site.
 */
function bcm_export_post_taxonomies( $post ) {
	$blacklist  = array( 'nav_menu', 'link_category', 'post_format' );
	$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
	$out        = array();

	foreach ( $taxonomies as $tax ) {
		if ( in_array( $tax, $blacklist, true ) ) {
			continue;
		}
		$terms = wp_get_post_terms( $post->ID, $tax, array( 'fields' => 'all' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			continue;
		}

		$tax_object = get_taxonomy( $tax );
		$list       = array();
		foreach ( $terms as $term ) {
			$parent_slug = '';
			if ( $term->parent ) {
				$parent_term = get_term( $term->parent, $tax );
				if ( $parent_term && ! is_wp_error( $parent_term ) ) {
					$parent_slug = $parent_term->slug;
				}
			}
			$list[] = array(
				'name'        => $term->name,
				'slug'        => $term->slug,
				'parent_slug' => $parent_slug,
			);
		}

		$out[ $tax ] = array(
			'label'         => $tax_object ? $tax_object->labels->name : ucwords( str_replace( array( '_', '-' ), ' ', $tax ) ),
			'hierarchical'  => $tax_object ? (bool) $tax_object->hierarchical : false,
			'terms'         => $list,
		);
	}

	return $out;
}

/* ---------------------------------------------------------------------
 * ACF-aware meta transform (export side)
 * ------------------------------------------------------------------- */

function bcm_transform_meta_for_export( $post_id, $meta_key, $value ) {
	if ( ! function_exists( 'get_field_object' ) || '_' === substr( $meta_key, 0, 1 ) ) {
		return $value;
	}
	$field = get_field_object( $meta_key, $post_id, false, false );
	if ( ! $field || empty( $field['type'] ) ) {
		return $value;
	}
	return bcm_transform_acf_value_by_type( $field, $value );
}

function bcm_transform_acf_value_by_type( $field, $value ) {
	switch ( $field['type'] ) {
		case 'image':
		case 'file':
			return bcm_acf_value_to_marker( $value );

		case 'gallery':
			if ( ! is_array( $value ) ) {
				return $value;
			}
			return array_map( 'bcm_acf_value_to_marker', $value );

		case 'repeater':
			if ( ! is_array( $value ) || empty( $field['sub_fields'] ) ) {
				return $value;
			}
			$sub_map = bcm_acf_sub_field_map( $field['sub_fields'] );
			return array_map( function( $row ) use ( $sub_map ) {
				return bcm_transform_acf_row( $row, $sub_map );
			}, $value );

		case 'flexible_content':
			if ( ! is_array( $value ) || empty( $field['layouts'] ) ) {
				return $value;
			}
			return array_map( function( $row ) use ( $field ) {
				$layout_name = isset( $row['acf_fc_layout'] ) ? $row['acf_fc_layout'] : null;
				$sub_map     = array();
				foreach ( $field['layouts'] as $layout_def ) {
					if ( isset( $layout_def['name'] ) && $layout_def['name'] === $layout_name && ! empty( $layout_def['sub_fields'] ) ) {
						$sub_map = bcm_acf_sub_field_map( $layout_def['sub_fields'] );
						break;
					}
				}
				return bcm_transform_acf_row( $row, $sub_map );
			}, $value );

		case 'group':
			if ( ! is_array( $value ) || empty( $field['sub_fields'] ) ) {
				return $value;
			}
			return bcm_transform_acf_row( $value, bcm_acf_sub_field_map( $field['sub_fields'] ) );

		default:
			return $value;
	}
}

function bcm_acf_sub_field_map( $sub_fields ) {
	$map = array();
	foreach ( $sub_fields as $sf ) {
		if ( isset( $sf['name'] ) ) {
			$map[ $sf['name'] ] = $sf;
		}
	}
	return $map;
}

function bcm_transform_acf_row( $row, $sub_map ) {
	if ( ! is_array( $row ) ) {
		return $row;
	}
	$new_row = array();
	foreach ( $row as $k => $v ) {
		$new_row[ $k ] = isset( $sub_map[ $k ] ) ? bcm_transform_acf_value_by_type( $sub_map[ $k ], $v ) : $v;
	}
	return $new_row;
}

function bcm_acf_value_to_marker( $value ) {
	if ( empty( $value ) ) {
		return $value;
	}
	$id = is_array( $value ) ? ( isset( $value['ID'] ) ? $value['ID'] : ( isset( $value['id'] ) ? $value['id'] : null ) ) : $value;
	if ( ! $id || ! is_numeric( $id ) ) {
		return $value;
	}
	$url = wp_get_attachment_url( (int) $id );
	return $url ? array( '__bcm_attachment__' => $url ) : $value;
}

/* ---------------------------------------------------------------------
 * AJAX proxies + import runner (run on the TARGET/new site)
 * ------------------------------------------------------------------- */

function bcm_remote_get_from_source( $source_url, $source_key, $path, $query = array() ) {
	$source_url = preg_replace( '#/wp-json(/.*)?$#i', '', trim( $source_url ) );
	$endpoint   = trailingslashit( $source_url ) . 'wp-json/blog-migrator/v1/' . ltrim( $path, '/' );
	if ( ! empty( $query ) ) {
		$endpoint = add_query_arg( $query, $endpoint );
	}
	return wp_remote_get( $endpoint, array(
		'headers' => array( 'X-Migrator-Key' => $source_key ),
		'timeout' => 30,
	) );
}

function bcm_is_own_site( $source_url ) {
	$source_host = wp_parse_url( trim( $source_url ), PHP_URL_HOST );
	$this_host   = wp_parse_url( home_url(), PHP_URL_HOST );
	return $source_host && $this_host && strtolower( $source_host ) === strtolower( $this_host );
}

function bcm_decode_remote_response( $response ) {
	if ( is_wp_error( $response ) ) {
		return array( null, $response->get_error_message() );
	}
	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== $code ) {
		$message = is_array( $body ) && isset( $body['message'] ) ? $body['message'] : 'Source site returned an error (HTTP ' . $code . ').';
		return array( null, $message );
	}
	return array( $body, null );
}

add_action( 'wp_ajax_bcm_fetch_post_types', 'bcm_ajax_fetch_post_types' );
function bcm_ajax_fetch_post_types() {
	check_ajax_referer( 'bcm_import_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}
	$source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
	$source_key = isset( $_POST['source_key'] ) ? sanitize_text_field( wp_unslash( $_POST['source_key'] ) ) : '';

	if ( bcm_is_own_site( $source_url ) ) {
		wp_send_json_error( array( 'message' => 'That is this site\'s own URL. Enter the OLD site\'s URL instead.' ) );
	}

	list( $body, $error ) = bcm_decode_remote_response( bcm_remote_get_from_source( $source_url, $source_key, 'post-types' ) );
	if ( $error ) {
		wp_send_json_error( array( 'message' => $error ) );
	}
	wp_send_json_success( $body );
}

add_action( 'wp_ajax_bcm_resolve_urls', 'bcm_ajax_resolve_urls' );
function bcm_ajax_resolve_urls() {
	check_ajax_referer( 'bcm_import_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}
	$source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
	$source_key = isset( $_POST['source_key'] ) ? sanitize_text_field( wp_unslash( $_POST['source_key'] ) ) : '';
	$urls       = isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) : '';

	if ( bcm_is_own_site( $source_url ) ) {
		wp_send_json_error( array( 'message' => 'That is this site\'s own URL. Enter the OLD site\'s URL instead.' ) );
	}

	list( $body, $error ) = bcm_decode_remote_response( bcm_remote_get_from_source( $source_url, $source_key, 'resolve-urls', array( 'urls' => $urls ) ) );
	if ( $error ) {
		wp_send_json_error( array( 'message' => $error ) );
	}
	wp_send_json_success( $body );
}

/**
 * Runs before an import starts. For each post type the user is about to
 * pull in:
 *  - if it isn't registered here yet, register it now (persisted, so it
 *    keeps showing up in the admin menu on every future page load).
 *  - if it uses ACF on the source, fetch that field group definition and
 *    recreate it here via ACF's own import function, so the fields show
 *    and edit correctly instead of just sitting in postmeta.
 * If a post type needs ACF and ACF isn't active on this site at all, we
 * refuse and tell the caller exactly which post types are blocked - there's
 * no field group to recreate without ACF installed.
 */
add_action( 'wp_ajax_bcm_prepare_import', 'bcm_ajax_prepare_import' );
function bcm_ajax_prepare_import() {
	check_ajax_referer( 'bcm_import_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}

	$source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
	$source_key = isset( $_POST['source_key'] ) ? sanitize_text_field( wp_unslash( $_POST['source_key'] ) ) : '';
	$post_types = isset( $_POST['post_types'] ) ? json_decode( wp_unslash( $_POST['post_types'] ), true ) : array();
	$post_types = is_array( $post_types ) ? array_map( 'sanitize_key', $post_types ) : array();

	if ( bcm_is_own_site( $source_url ) ) {
		wp_send_json_error( array( 'message' => 'That is this site\'s own URL - refusing to import a site into itself.' ) );
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$acf_active = function_exists( 'acf_get_field_groups' )
		|| is_plugin_active( 'advanced-custom-fields/acf.php' )
		|| is_plugin_active( 'advanced-custom-fields-pro/acf.php' );

	list( $remote_types_body, $error ) = bcm_decode_remote_response( bcm_remote_get_from_source( $source_url, $source_key, 'post-types' ) );
	if ( $error ) {
		wp_send_json_error( array( 'message' => $error ) );
	}
	$remote_types = array();
	foreach ( (array) $remote_types_body as $t ) {
		$remote_types[ $t['slug'] ] = $t;
	}

	$acf_blocked = array();
	foreach ( $post_types as $pt ) {
		if ( ! empty( $remote_types[ $pt ]['uses_acf'] ) && ! $acf_active ) {
			$acf_blocked[] = $pt;
		}
	}
	if ( ! empty( $acf_blocked ) ) {
		wp_send_json_error( array(
			'code'        => 'acf_required',
			'message'     => 'The post type(s) ' . implode( ', ', $acf_blocked ) . ' use ACF (Advanced Custom Fields) on the source site, but ACF is not installed/active here. Please install & activate ACF on this site first, then try again.',
			'acf_blocked' => $acf_blocked,
		) );
	}

	$log = array();

	foreach ( $post_types as $pt ) {
		if ( ! post_type_exists( $pt ) ) {
			$label = isset( $remote_types[ $pt ]['label'] ) ? $remote_types[ $pt ]['label'] : ucwords( str_replace( array( '_', '-' ), ' ', $pt ) );
			bcm_ensure_post_type_registered( $pt, $label );
			$log[] = 'Registered post type "' . $pt . '" (' . $label . ') on this site.';
		}

		if ( $acf_active && ! empty( $remote_types[ $pt ]['uses_acf'] ) ) {
			list( $groups, $group_error ) = bcm_decode_remote_response( bcm_remote_get_from_source( $source_url, $source_key, 'acf-field-groups', array( 'post_type' => $pt ) ) );
			if ( $group_error || empty( $groups ) ) {
				continue;
			}
			foreach ( $groups as $group ) {
				if ( function_exists( 'acf_import_field_group' ) ) {
					acf_import_field_group( $group );
					$log[] = 'Set up ACF field group "' . ( isset( $group['title'] ) ? $group['title'] : $pt ) . '" for "' . $pt . '".';
				}
			}
		}
	}

	wp_send_json_success( array( 'log' => $log ) );
}

add_action( 'wp_ajax_bcm_run_import_batch', 'bcm_run_import_batch' );
function bcm_run_import_batch() {
	check_ajax_referer( 'bcm_import_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ) );
	}

	$source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
	$source_key = isset( $_POST['source_key'] ) ? sanitize_text_field( wp_unslash( $_POST['source_key'] ) ) : '';
	$post_type  = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
	$page       = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
	$include    = isset( $_POST['include'] ) ? sanitize_text_field( wp_unslash( $_POST['include'] ) ) : '';

	if ( ! $source_url || ! $source_key ) {
		wp_send_json_error( array( 'message' => 'Missing source URL or key.' ) );
	}

	if ( bcm_is_own_site( $source_url ) ) {
		wp_send_json_error( array( 'message' => 'That is this site\'s own URL - refusing to import a site into itself.' ) );
	}

	$query = array( 'post_type' => $post_type, 'per_page' => 5 );
	if ( '' !== $include ) {
		$query['include'] = $include;
	} else {
		$query['page'] = $page;
	}

	list( $body, $error ) = bcm_decode_remote_response( bcm_remote_get_from_source( $source_url, $source_key, 'export', $query ) );
	if ( $error ) {
		wp_send_json_error( array( 'message' => $error ) );
	}

	$results        = array();
	$imported_count = 0;
	$source_host    = wp_parse_url( $source_url, PHP_URL_HOST );

	foreach ( $body['posts'] as $remote_post ) {
		$results[]      = bcm_import_single_post( $remote_post, $source_host, $post_type );
		$imported_count++;
	}

	wp_send_json_success( array(
		'results'        => $results,
		'imported_count' => $imported_count,
		'total'          => isset( $body['total'] ) ? $body['total'] : $imported_count,
		'done'           => empty( $body['has_more'] ),
	) );
}

function bcm_import_single_post( $remote_post, $source_host, $default_post_type ) {
	$title     = isset( $remote_post['title'] ) ? $remote_post['title'] : '(untitled)';
	$post_type = ! empty( $remote_post['post_type'] ) ? $remote_post['post_type'] : $default_post_type;

	// Skip if this post was already imported before.
	$existing = get_posts( array(
		'post_type'      => $post_type,
		'post_status'    => 'any',
		'meta_key'       => BCM_META_SOURCE_ID,
		'meta_value'     => $remote_post['id'],
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );

	if ( ! empty( $existing ) ) {
		return array( 'title' => $title, 'status' => 'skipped', 'message' => 'already imported' );
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$target_host = wp_parse_url( home_url(), PHP_URL_HOST );
	bcm_reset_failed_uploads();
	$content = bcm_migrate_urls_in_value( $remote_post['content'], $source_host, $target_host );

	$post_id = wp_insert_post( array(
		'post_type'     => $post_type,
		'post_status'   => in_array( $remote_post['status'], array( 'publish', 'draft', 'private' ), true ) ? $remote_post['status'] : 'draft',
		'post_title'    => $remote_post['title'],
		'post_name'     => $remote_post['slug'],
		'post_content'  => $content,
		'post_excerpt'  => $remote_post['excerpt'],
		'post_date'     => $remote_post['date'],
		'post_date_gmt' => $remote_post['date_gmt'],
	), true );

	if ( is_wp_error( $post_id ) ) {
		return array( 'title' => $title, 'status' => 'error', 'message' => $post_id->get_error_message() );
	}

	if ( ! empty( $remote_post['taxonomies'] ) ) {
		bcm_import_post_taxonomies( $post_id, $post_type, $remote_post['taxonomies'] );
	}

	foreach ( $remote_post['meta'] as $key => $value ) {
		$value = bcm_resolve_acf_import_value( $value );
		$value = bcm_migrate_urls_in_value( $value, $source_host, $target_host );
		update_post_meta( $post_id, $key, $value );
	}
	update_post_meta( $post_id, BCM_META_SOURCE_ID, $remote_post['id'] );
	update_post_meta( $post_id, BCM_META_SOURCE_URL, $remote_post['source_url'] );

	if ( ! empty( $remote_post['featured_image'] ) ) {
		$attachment_id = bcm_sideload_image( $remote_post['featured_image'], $post_id );
		if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	$failed = bcm_get_failed_uploads();
	$message = 'post #' . $post_id . ' (' . $post_type . ')';
	if ( ! empty( $failed ) ) {
		$message .= ' - ' . count( $failed ) . ' image(s) could not be downloaded and still point to the source site';
	}

	return array( 'title' => $title, 'status' => 'imported', 'message' => $message );
}

function bcm_reset_failed_uploads() {
	$GLOBALS['bcm_failed_uploads'] = array();
}

function bcm_track_failed_upload( $url ) {
	$GLOBALS['bcm_failed_uploads'][] = $url;
}

function bcm_get_failed_uploads() {
	return isset( $GLOBALS['bcm_failed_uploads'] ) ? $GLOBALS['bcm_failed_uploads'] : array();
}

/**
 * Recreate every taxonomy term exported by bcm_export_post_taxonomies() on
 * this site and assign them to the imported post. Missing taxonomies are
 * auto-registered (like missing post types); missing terms are created,
 * resolving each one's parent by slug first so hierarchy survives.
 */
function bcm_import_post_taxonomies( $post_id, $post_type, $taxonomies ) {
	foreach ( $taxonomies as $tax => $tax_data ) {
		$label        = isset( $tax_data['label'] ) ? $tax_data['label'] : ucwords( str_replace( array( '_', '-' ), ' ', $tax ) );
		$hierarchical = ! empty( $tax_data['hierarchical'] );
		$terms        = isset( $tax_data['terms'] ) ? $tax_data['terms'] : array();

		if ( empty( $terms ) ) {
			continue;
		}

		bcm_ensure_taxonomy_registered( $tax, $label, $hierarchical, $post_type );

		$term_ids = array();
		foreach ( $terms as $t ) {
			$parent_id = 0;
			if ( ! empty( $t['parent_slug'] ) ) {
				$parent_id = bcm_get_or_create_term( $t['parent_slug'], $t['parent_slug'], $tax, 0 );
			}
			$term_id = bcm_get_or_create_term( $t['name'], $t['slug'], $tax, $parent_id );
			if ( $term_id ) {
				$term_ids[] = $term_id;
			}
		}

		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $post_id, $term_ids, $tax, false );
		}
	}
}

function bcm_get_or_create_term( $name, $slug, $taxonomy, $parent_id ) {
	$existing = get_term_by( 'slug', $slug, $taxonomy );
	if ( $existing && ! is_wp_error( $existing ) ) {
		return (int) $existing->term_id;
	}
	$inserted = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug, 'parent' => $parent_id ) );
	if ( is_wp_error( $inserted ) ) {
		// Term already exists under a different slug collision - fall back to matching by name.
		$existing = get_term_by( 'name', $name, $taxonomy );
		return $existing && ! is_wp_error( $existing ) ? (int) $existing->term_id : 0;
	}
	return (int) $inserted['term_id'];
}

/**
 * Recursively walk an ACF meta value looking for image/file markers left by
 * bcm_transform_meta_for_export(), sideload each one, and swap in the new
 * local attachment ID so repeaters/galleries/flexible content survive intact.
 */
function bcm_resolve_acf_import_value( $value ) {
	if ( is_array( $value ) ) {
		if ( isset( $value['__bcm_attachment__'] ) ) {
			$attachment_id = bcm_sideload_image( $value['__bcm_attachment__'], 0 );
			return ( $attachment_id && ! is_wp_error( $attachment_id ) ) ? $attachment_id : null;
		}
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ $k ] = bcm_resolve_acf_import_value( $v );
		}
		return $out;
	}
	return $value;
}

/**
 * Recursively rewrite every string in a value (post content, or a meta
 * value that may be a nested array / a JSON-encoded string like Elementor's
 * _elementor_data) so nothing keeps pointing at the source site:
 *  - any /wp-content/uploads/... file is actually downloaded and sideloaded
 *    into this site's media library, then swapped for its real local URL.
 *  - any other reference to the source domain (internal links, anchors,
 *    canonical URLs, etc.) has its host swapped for this site's host,
 *    keeping the same path.
 */
function bcm_migrate_urls_in_value( $value, $source_host, $target_host ) {
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ $k ] = bcm_migrate_urls_in_value( $v, $source_host, $target_host );
		}
		return $out;
	}

	if ( ! is_string( $value ) || empty( $source_host ) || false === stripos( $value, $source_host ) ) {
		return $value;
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Placeholder token used to "protect" a host occurrence from the broad
	// domain swap below when the file behind it couldn't be downloaded -
	// better to leave a working link to the old site than a guaranteed
	// 404 on a file that was never actually copied here.
	$placeholder = '%%BCM_KEEP_HOST%%';
	$value       = bcm_sideload_uploads_in_string( $value, $source_host, $placeholder );

	if ( $target_host && $target_host !== $source_host ) {
		$value = str_ireplace( $source_host, $target_host, $value );
	}

	$value = str_replace( $placeholder, $source_host, $value );

	return $value;
}

/**
 * Find every /wp-content/uploads/... URL for the given host inside a string
 * (handles both plain HTML and JSON-escaped "http:\/\/host\/..." forms),
 * sideload each file, and swap in the new local attachment URL. Any file
 * that fails to download has its host masked with $placeholder so the
 * caller's broad domain swap doesn't turn it into a broken local link.
 */
function bcm_sideload_uploads_in_string( $str, $source_host, $placeholder ) {
	$normalized = str_replace( '\\/', '/', $str );
	$pattern    = '#https?://' . preg_quote( $source_host, '#' ) . '/wp-content/uploads/[^\s"\'\\\\)>]+#i';

	if ( ! preg_match_all( $pattern, $normalized, $matches ) ) {
		return $str;
	}

	foreach ( array_unique( $matches[0] ) as $url ) {
		$url           = rtrim( $url, '.,;:' );
		$attachment_id = bcm_sideload_image( $url, 0 );

		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			bcm_track_failed_upload( $url );
			$masked = str_replace( $source_host, $placeholder, $url );
			$str    = str_replace( $url, $masked, $str );
			$str    = str_replace( str_replace( '/', '\\/', $url ), str_replace( '/', '\\/', $masked ), $str );
			continue;
		}

		$new_url = wp_get_attachment_url( $attachment_id );
		if ( ! $new_url ) {
			continue;
		}

		$str = str_replace( $url, $new_url, $str );
		$str = str_replace( str_replace( '/', '\\/', $url ), str_replace( '/', '\\/', $new_url ), $str );
	}

	return $str;
}

function bcm_sideload_image( $url, $post_id ) {
	static $cache = array();
	if ( isset( $cache[ $url ] ) ) {
		return $cache[ $url ];
	}

	$tmp = bcm_download_url( $url );
	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}

	$file_array = array(
		'name'     => basename( parse_url( $url, PHP_URL_PATH ) ),
		'tmp_name' => $tmp,
	);

	$attachment_id = media_handle_sideload( $file_array, $post_id );

	if ( is_wp_error( $attachment_id ) ) {
		@unlink( $tmp );
		return $attachment_id;
	}

	$cache[ $url ] = $attachment_id;
	return $attachment_id;
}

/**
 * WordPress core's download_url() goes through wp_safe_remote_get(), which
 * deliberately refuses requests to local/private-network hosts (SSRF
 * protection) - including *.local dev domains and loopback IPs. That's the
 * right default for random third-party input, but here the "remote" host is
 * a site the admin explicitly typed in and authenticated to with a secret
 * key, so we fetch with the unrestricted wp_remote_get() instead.
 */
function bcm_download_url( $url ) {
	$response = wp_remote_get( $url, array(
		'timeout'  => 60,
		'stream'   => true,
		'filename' => wp_tempnam( $url ),
	) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$tmp_file = $response['filename'];

	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		@unlink( $tmp_file );
		return new WP_Error( 'bcm_download_failed', 'Could not download ' . $url . ' (HTTP ' . wp_remote_retrieve_response_code( $response ) . ')' );
	}

	if ( ! file_exists( $tmp_file ) || 0 === filesize( $tmp_file ) ) {
		@unlink( $tmp_file );
		return new WP_Error( 'bcm_download_empty', 'Downloaded file is empty: ' . $url );
	}

	return $tmp_file;
}
