<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Settings screen, organised into tabs: General (AI), Training (index +
 * documents), Widget (appearance/placement), Team (notification emails),
 * Embed (Next.js snippet + CORS).
 *
 * Each tab has its own settings group so saving one tab never wipes the
 * options of another (options.php nulls unposted options in a group).
 */
class ASC_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'wp_ajax_asc_reindex', array( $this, 'ajax_reindex' ) );
	}

	public function menu() {
		add_menu_page(
			'AI Support Chat',
			'AI Support Chat',
			'manage_options',
			'asc-settings',
			array( $this, 'render' ),
			'dashicons-format-chat',
			58
		);
	}

	public function settings() {
		// General.
		register_setting( 'asc_general', 'asc_provider', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'openai' ) );
		register_setting( 'asc_general', 'asc_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'asc_general', 'asc_model', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'gpt-4o-mini' ) );
		register_setting( 'asc_general', 'asc_embed_provider', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'same' ) );
		register_setting( 'asc_general', 'asc_embed_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );

		// Team.
		register_setting( 'asc_team', 'asc_notify_emails', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );

		// Widget.
		register_setting( 'asc_widget', 'asc_widget_enabled', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '1' ) );
		register_setting( 'asc_widget', 'asc_widget_title', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'asc_widget', 'asc_greeting', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'asc_widget', 'asc_color', array( 'sanitize_callback' => 'sanitize_hex_color', 'default' => '#2271b1' ) );
		register_setting( 'asc_widget', 'asc_position', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'right' ) );
		register_setting( 'asc_widget', 'asc_font', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'asc_widget', 'asc_display_mode', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'all' ) );
		register_setting( 'asc_widget', 'asc_display_ids', array( 'sanitize_callback' => 'sanitize_text_field' ) );

		// Embed.
		register_setting( 'asc_embed', 'asc_allowed_origins', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
	}

	private function tabs() {
		return array(
			'general'  => '⚙️ General',
			'training' => '🧠 Training',
			'widget'   => '🎨 Widget',
			'team'     => '👥 Team',
			'embed'    => '🔗 Embed',
		);
	}

	public function render() {
		$tabs    = $this->tabs();
		$current = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'general';
		}
		?>
		<div class="wrap">
			<h1>AI Support Chat</h1>

			<nav class="nav-tab-wrapper" style="margin-bottom:18px;">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=asc-settings&tab=' . $slug ) ); ?>"
						class="nav-tab <?php echo $slug === $current ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php call_user_func( array( $this, 'render_' . $current ) ); ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- General */

	private function render_general() {
		?>
		<p class="description">Connect the AI that powers the chatbot.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'asc_general' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="asc_provider">AI Provider</label></th>
					<td>
						<select id="asc_provider" name="asc_provider">
							<option value="openai" <?php selected( get_option( 'asc_provider', 'openai' ), 'openai' ); ?>>OpenAI (paid, prepaid credits)</option>
							<option value="gemini" <?php selected( get_option( 'asc_provider', 'openai' ), 'gemini' ); ?>>Google Gemini (free tier available)</option>
							<option value="openrouter" <?php selected( get_option( 'asc_provider', 'openai' ), 'openrouter' ); ?>>OpenRouter (free models available, chat only)</option>
						</select>
						<p class="description"><strong>Note:</strong> after switching provider, run "Re-index all content" (Training tab) — embeddings from different providers are not compatible.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="asc_api_key">API Key</label></th>
					<td>
						<input type="password" id="asc_api_key" name="asc_api_key"
							value="<?php echo esc_attr( get_option( 'asc_api_key' ) ); ?>"
							class="regular-text" autocomplete="off" placeholder="sk-... / AIza...">
						<p class="description">OpenAI: platform.openai.com &rarr; API keys. Gemini: aistudio.google.com &rarr; Get API key (free, no card needed). One key is used for both chat and embeddings.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="asc_model">Chat model</label></th>
					<td>
						<select id="asc_model" name="asc_model">
							<optgroup label="OpenAI">
								<?php foreach ( array( 'gpt-4o-mini', 'gpt-4o' ) as $model ) : ?>
									<option value="<?php echo esc_attr( $model ); ?>" <?php selected( get_option( 'asc_model', 'gpt-4o-mini' ), $model ); ?>><?php echo esc_html( $model ); ?></option>
								<?php endforeach; ?>
							</optgroup>
							<optgroup label="Google Gemini">
								<?php foreach ( array( 'gemini-2.0-flash', 'gemini-2.5-flash' ) as $model ) : ?>
									<option value="<?php echo esc_attr( $model ); ?>" <?php selected( get_option( 'asc_model', 'gpt-4o-mini' ), $model ); ?>><?php echo esc_html( $model ); ?></option>
								<?php endforeach; ?>
							</optgroup>
							<optgroup label="OpenRouter (free)">
								<?php foreach ( array( 'google/gemma-4-31b-it:free', 'meta-llama/llama-3.3-70b-instruct:free', 'openai/gpt-oss-120b:free', 'qwen/qwen3-next-80b-a3b-instruct:free' ) as $model ) : ?>
									<option value="<?php echo esc_attr( $model ); ?>" <?php selected( get_option( 'asc_model', 'gpt-4o-mini' ), $model ); ?>><?php echo esc_html( $model ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						</select>
						<p class="description">Pick a model that matches the provider above.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="asc_embed_provider">Embeddings provider</label></th>
					<td>
						<select id="asc_embed_provider" name="asc_embed_provider">
							<option value="same" <?php selected( get_option( 'asc_embed_provider', 'same' ), 'same' ); ?>>Same as AI provider</option>
							<option value="gemini" <?php selected( get_option( 'asc_embed_provider', 'same' ), 'gemini' ); ?>>Google Gemini</option>
							<option value="openai" <?php selected( get_option( 'asc_embed_provider', 'same' ), 'openai' ); ?>>OpenAI</option>
						</select>
						<input type="password" name="asc_embed_api_key" style="margin-left:8px;"
							value="<?php echo esc_attr( get_option( 'asc_embed_api_key' ) ); ?>"
							class="regular-text" autocomplete="off" placeholder="Embeddings API key (optional)">
						<p class="description"><strong>Required when the AI provider is OpenRouter</strong> — OpenRouter has no embeddings API, so pick Gemini/OpenAI here with its key. Leave key empty to reuse the main API key. Without embeddings the bot falls back to keyword search.</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* --------------------------------------------------------------- Training */

	private function render_training() {
		global $wpdb;
		$chunk_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}asc_chunks" );
		$post_count  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->prefix}asc_chunks" );
		?>
		<p class="description">Everything the bot has learned — your site content plus any uploaded documents.</p>

		<h2>Site content index</h2>
		<p><strong><?php echo $post_count; ?></strong> items indexed, <strong><?php echo $chunk_count; ?></strong> chunks stored. Posts and pages re-index automatically when saved.</p>
		<p>
			<button type="button" class="button button-secondary" id="asc-reindex-btn"
				<?php disabled( ! get_option( 'asc_api_key' ) ); ?>>Re-index all content</button>
			<span id="asc-reindex-status" style="margin-left:10px;"></span>
		</p>

		<?php ASC_Documents::render_section(); ?>

		<script>
		(function () {
			var btn = document.getElementById('asc-reindex-btn');
			var status = document.getElementById('asc-reindex-status');
			if (!btn) return;

			function step(start) {
				var body = new URLSearchParams({
					action: 'asc_reindex',
					nonce: '<?php echo esc_js( wp_create_nonce( 'asc_reindex' ) ); ?>',
					start: start ? '1' : '0'
				});
				fetch(ajaxurl, { method: 'POST', body: body })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res.success) {
							status.textContent = 'Error: ' + (res.data && res.data.message ? res.data.message : 'unknown');
							btn.disabled = false;
							return;
						}
						if (res.data.remaining > 0) {
							status.textContent = res.data.remaining + ' items remaining...';
							step(false);
						} else {
							status.textContent = 'Done! All content indexed.';
							btn.disabled = false;
						}
					})
					.catch(function () {
						status.textContent = 'Request failed — check the network tab.';
						btn.disabled = false;
					});
			}

			btn.addEventListener('click', function () {
				btn.disabled = true;
				status.textContent = 'Starting...';
				step(true);
			});
		})();
		</script>
		<?php
	}

	/* ----------------------------------------------------------------- Widget */

	private function render_widget() {
		?>
		<p class="description">These settings apply everywhere the widget loads — the WordPress site and the Next.js subdomains (the widget fetches them automatically).</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'asc_widget' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Show widget</th>
					<td>
						<input type="hidden" name="asc_widget_enabled" value="0">
						<label><input type="checkbox" name="asc_widget_enabled" value="1" <?php checked( get_option( 'asc_widget_enabled', '1' ), '1' ); ?>> Enable the chat widget on this WordPress site</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="asc_display_mode">Where to show</label></th>
					<td>
						<select id="asc_display_mode" name="asc_display_mode">
							<option value="all" <?php selected( get_option( 'asc_display_mode', 'all' ), 'all' ); ?>>Entire website</option>
							<option value="include" <?php selected( get_option( 'asc_display_mode', 'all' ), 'include' ); ?>>Only on specific pages</option>
							<option value="exclude" <?php selected( get_option( 'asc_display_mode', 'all' ), 'exclude' ); ?>>Everywhere except specific pages</option>
						</select>
						<input type="text" name="asc_display_ids" style="margin-left:8px;width:280px;"
							value="<?php echo esc_attr( get_option( 'asc_display_ids' ) ); ?>"
							placeholder="Page/post IDs, e.g. home, 8, 11">
						<p class="description">For "specific pages" modes, enter page/post IDs separated by commas — use <code>home</code> for the homepage (e.g. <code>home, 8, 11</code>). Find a page's ID in the URL while editing it. If the list is empty, the widget shows everywhere.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="asc_position">Position</label></th>
					<td>
						<select id="asc_position" name="asc_position">
							<option value="right" <?php selected( get_option( 'asc_position', 'right' ), 'right' ); ?>>Bottom right</option>
							<option value="left" <?php selected( get_option( 'asc_position', 'right' ), 'left' ); ?>>Bottom left</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="asc_color">Primary color</label></th>
					<td>
						<input type="color" id="asc_color" name="asc_color" value="<?php echo esc_attr( get_option( 'asc_color', '#2271b1' ) ); ?>">
						<p class="description">Used for the launcher bubble, header, buttons and your visitors' message bubbles.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="asc_widget_title">Widget title</label></th>
					<td>
						<input type="text" id="asc_widget_title" name="asc_widget_title" class="regular-text"
							value="<?php echo esc_attr( get_option( 'asc_widget_title' ) ); ?>"
							placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="asc_greeting">Greeting message</label></th>
					<td>
						<input type="text" id="asc_greeting" name="asc_greeting" class="large-text"
							value="<?php echo esc_attr( get_option( 'asc_greeting' ) ); ?>"
							placeholder="Hi! Ask me anything about <?php echo esc_attr( get_bloginfo( 'name' ) ); ?>...">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="asc_font">Font family</label></th>
					<td>
						<input type="text" id="asc_font" name="asc_font" class="regular-text"
							value="<?php echo esc_attr( get_option( 'asc_font' ) ); ?>"
							placeholder="e.g. Poppins, sans-serif">
						<p class="description">Leave empty for the clean system font. A custom font must already be loaded by your theme.</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ------------------------------------------------------------------- Team */

	private function render_team() {
		?>
		<p class="description">Who gets notified when a visitor opens a support ticket. Replies are handled from <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=support_ticket' ) ); ?>">Support Tickets</a>.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'asc_team' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="asc_notify_emails">Support team emails</label></th>
					<td>
						<textarea id="asc_notify_emails" name="asc_notify_emails" rows="5" class="regular-text"
							placeholder="support@example.com&#10;ali@example.com&#10;sara@example.com"><?php echo esc_textarea( get_option( 'asc_notify_emails', get_option( 'asc_notify_email' ) ) ); ?></textarea>
						<p class="description">One email per line (or comma separated) — every listed team member gets each new ticket notification. Empty = admin email.</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ------------------------------------------------------------------ Embed */

	private function render_embed() {
		$embed_src = ASC_URL . 'widget/chat.js';
		$api_base  = rest_url( 'asc/v1' );
		?>
		<p class="description">Run the same chatbot on your Next.js subdomains — one dashboard, one AI, every site.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'asc_embed' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="asc_allowed_origins">Allowed origins (CORS)</label></th>
					<td>
						<textarea id="asc_allowed_origins" name="asc_allowed_origins" rows="3" class="large-text"
							placeholder="https://app.example.com"><?php echo esc_textarea( get_option( 'asc_allowed_origins' ) ); ?></textarea>
						<p class="description">One per line — the Next.js subdomains that will load the widget.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Embed snippet</th>
					<td>
						<p class="description" style="margin-bottom:6px;">Add this in <code>app/layout.tsx</code> (and add each subdomain above under allowed origins):</p>
						<textarea readonly rows="4" class="large-text code" onclick="this.select()">&lt;Script
  src="<?php echo esc_url( $embed_src ); ?>"
  data-api="<?php echo esc_url( $api_base ); ?>"
  strategy="lazyOnload"
/&gt;</textarea>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------- Re-index */

	public function ajax_reindex() {
		check_ajax_referer( 'asc_reindex', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ) );
		}

		global $wpdb;
		$indexer = ASC_Indexer::instance();
		$start   = ! empty( $_POST['start'] );

		if ( $start ) {
			$ids = get_posts( array(
				'post_type'   => $indexer->post_types(),
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
			) );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}asc_chunks" );
			update_option( 'asc_reindex_queue', $ids, false );
		}

		$queue = get_option( 'asc_reindex_queue', array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$batch = array_splice( $queue, 0, 2 ); // small batches to stay under PHP timeouts
		foreach ( $batch as $post_id ) {
			$result = $indexer->index_post( $post_id );
			if ( is_wp_error( $result ) ) {
				delete_option( 'asc_reindex_queue' );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
		}

		if ( $queue ) {
			update_option( 'asc_reindex_queue', $queue, false );
			wp_send_json_success( array( 'remaining' => count( $queue ) ) );
		}

		delete_option( 'asc_reindex_queue' );
		wp_send_json_success( array( 'remaining' => 0 ) );
	}
}
