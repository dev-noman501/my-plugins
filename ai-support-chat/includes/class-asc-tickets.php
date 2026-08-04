<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Human handoff: support_ticket CPT, HTML email notification (with the full
 * chat transcript), a chat-style conversation view in wp-admin, and a reply
 * box that emails the visitor back.
 */
class ASC_Tickets {

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_boxes' ) );
		add_action( 'admin_post_asc_ticket_reply', array( $this, 'send_reply' ) );
		add_filter( 'manage_support_ticket_posts_columns', array( $this, 'list_columns' ) );
		add_action( 'manage_support_ticket_posts_custom_column', array( $this, 'list_column_content' ), 10, 2 );
	}

	public function register_cpt() {
		register_post_type( 'support_ticket', array(
			'labels' => array(
				'name'          => 'Support Tickets',
				'singular_name' => 'Support Ticket',
				'menu_name'     => 'Support Tickets',
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_icon'           => 'dashicons-sos',
			'menu_position'       => 59,
			'supports'            => array( 'title' ),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'exclude_from_search' => true,
		) );
	}

	/**
	 * Called from the REST handoff endpoint.
	 *
	 * @return int|WP_Error Ticket post ID.
	 */
	public static function create_ticket( $name, $email, $message, $session_id ) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT role, message, created_at FROM {$wpdb->prefix}asc_messages
			 WHERE session_id = %s ORDER BY id ASC LIMIT 100",
			$session_id
		) );

		$transcript = array();
		$plain      = '';
		foreach ( $rows as $row ) {
			$transcript[] = array(
				'role'    => $row->role,
				'message' => $row->message,
				'time'    => $row->created_at,
			);
			$plain .= sprintf( "[%s] %s: %s\n", $row->created_at, strtoupper( $row->role ), $row->message );
		}

		$ticket_id = wp_insert_post( array(
			'post_type'    => 'support_ticket',
			'post_status'  => 'publish',
			'post_title'   => sprintf( '%s — %s', $name, current_time( 'Y-m-d H:i' ) ),
			'post_content' => "Visitor message:\n" . $message . ( $plain ? "\n\n--- Chat transcript ---\n" . $plain : '' ),
		), true );

		if ( is_wp_error( $ticket_id ) ) {
			return $ticket_id;
		}

		update_post_meta( $ticket_id, '_asc_name', $name );
		update_post_meta( $ticket_id, '_asc_email', $email );
		update_post_meta( $ticket_id, '_asc_session', $session_id );
		update_post_meta( $ticket_id, '_asc_message', $message );
		update_post_meta( $ticket_id, '_asc_transcript', wp_slash( wp_json_encode( $transcript ) ) );

		wp_mail(
			self::notify_recipients(),
			sprintf( '[%s] New support ticket #%d from %s', get_bloginfo( 'name' ), $ticket_id, $name ),
			self::email_html( $ticket_id, $name, $email, $message, $transcript ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		return $ticket_id;
	}

	/**
	 * All support-team notification recipients. Reads the multi-email option
	 * (one per line / comma separated), falls back to the legacy single-email
	 * option, then to the admin email.
	 *
	 * @return string[]
	 */
	public static function notify_recipients() {
		$raw = get_option( 'asc_notify_emails' );
		if ( ! $raw ) {
			$raw = get_option( 'asc_notify_email' ); // pre-1.2 single-email option
		}
		$emails = array_filter(
			array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) $raw ) ),
			'is_email'
		);
		if ( empty( $emails ) ) {
			$emails = array( get_option( 'admin_email' ) );
		}
		return array_values( array_unique( $emails ) );
	}

	/**
	 * HTML notification email: visitor card + message + full chat transcript
	 * rendered as chat bubbles (inline styles for email-client support).
	 */
	private static function email_html( $ticket_id, $name, $email, $message, array $transcript ) {
		$admin_link = admin_url( 'post.php?post=' . $ticket_id . '&action=edit' );
		$site       = esc_html( get_bloginfo( 'name' ) );

		$bubbles = '';
		foreach ( $transcript as $item ) {
			$is_user = ( 'user' === $item['role'] );
			$bubbles .= sprintf(
				'<tr><td style="padding:4px 0;text-align:%s;">
					<div style="display:inline-block;max-width:80%%;padding:9px 13px;border-radius:12px;background:%s;color:%s;font-size:14px;line-height:1.5;text-align:left;">%s</div>
					<div style="font-size:11px;color:#8c8f94;margin-top:2px;">%s · %s</div>
				</td></tr>',
				$is_user ? 'right' : 'left',
				$is_user ? '#2271b1' : '#f0f0f1',
				$is_user ? '#ffffff' : '#1d2327',
				nl2br( esc_html( $item['message'] ) ),
				$is_user ? 'Visitor' : 'AI Bot',
				esc_html( $item['time'] )
			);
		}
		if ( ! $bubbles ) {
			$bubbles = '<tr><td style="color:#8c8f94;font-size:13px;">No chat history before this ticket.</td></tr>';
		}

		return '
		<div style="background:#f6f7f7;padding:24px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">
			<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #dcdcde;">
				<div style="background:#2271b1;color:#ffffff;padding:18px 24px;">
					<h2 style="margin:0;font-size:18px;">🎫 New Support Ticket #' . (int) $ticket_id . '</h2>
					<p style="margin:4px 0 0;font-size:13px;opacity:.85;">' . $site . ' — a visitor asked to talk to the team</p>
				</div>
				<div style="padding:20px 24px;">
					<table style="width:100%;font-size:14px;border-collapse:collapse;">
						<tr><td style="padding:4px 0;color:#8c8f94;width:80px;">Name</td><td style="padding:4px 0;"><strong>' . esc_html( $name ) . '</strong></td></tr>
						<tr><td style="padding:4px 0;color:#8c8f94;">Email</td><td style="padding:4px 0;"><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></td></tr>
					</table>
					<div style="margin:14px 0;padding:14px 16px;background:#fcf9e8;border-left:4px solid #dba617;border-radius:6px;font-size:14px;line-height:1.5;">
						<strong style="display:block;margin-bottom:4px;">Their message:</strong>' . nl2br( esc_html( $message ) ) . '
					</div>
					<h3 style="font-size:14px;color:#1d2327;margin:20px 0 8px;">💬 Chat transcript</h3>
					<table style="width:100%;border-collapse:collapse;">' . $bubbles . '</table>
					<div style="text-align:center;margin-top:22px;">
						<a href="' . esc_url( $admin_link ) . '" style="display:inline-block;background:#2271b1;color:#ffffff;text-decoration:none;padding:11px 26px;border-radius:6px;font-size:14px;font-weight:600;">Open ticket &amp; reply</a>
					</div>
				</div>
			</div>
		</div>';
	}

	public function meta_boxes() {
		add_meta_box( 'asc_ticket_conversation', '💬 Conversation', array( $this, 'render_conversation_box' ), 'support_ticket', 'normal', 'high' );
		add_meta_box( 'asc_ticket_reply', '✉️ Reply to visitor (sends email)', array( $this, 'render_reply_box' ), 'support_ticket', 'normal' );
		add_meta_box( 'asc_ticket_info', 'Visitor', array( $this, 'render_info_box' ), 'support_ticket', 'side' );
	}

	public function render_info_box( $post ) {
		$name  = get_post_meta( $post->ID, '_asc_name', true );
		$email = get_post_meta( $post->ID, '_asc_email', true );
		echo '<p style="font-size:14px;"><span class="dashicons dashicons-admin-users" style="color:#2271b1;"></span> <strong>' . esc_html( $name ) . '</strong></p>';
		echo '<p><span class="dashicons dashicons-email" style="color:#2271b1;"></span> <a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></p>';
		echo '<p><span class="dashicons dashicons-calendar-alt" style="color:#2271b1;"></span> ' . esc_html( get_the_date( 'Y-m-d H:i', $post ) ) . '</p>';
	}

	public function render_conversation_box( $post ) {
		$message    = get_post_meta( $post->ID, '_asc_message', true );
		$transcript = json_decode( (string) get_post_meta( $post->ID, '_asc_transcript', true ), true );
		?>
		<style>
			.asc-convo{background:#f6f7f7;border-radius:8px;padding:16px;max-height:420px;overflow-y:auto;}
			.asc-convo-row{margin:8px 0;display:flex;flex-direction:column;}
			.asc-convo-row.asc-user{align-items:flex-end;}
			.asc-convo-row.asc-bot{align-items:flex-start;}
			.asc-convo-bubble{max-width:75%;padding:9px 13px;border-radius:12px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;}
			.asc-user .asc-convo-bubble{background:#2271b1;color:#fff;border-bottom-right-radius:4px;}
			.asc-bot .asc-convo-bubble{background:#fff;border:1px solid #dcdcde;border-bottom-left-radius:4px;}
			.asc-convo-meta{font-size:11px;color:#8c8f94;margin-top:3px;}
			.asc-ticket-msg{margin:0 0 14px;padding:12px 16px;background:#fcf9e8;border-left:4px solid #dba617;border-radius:6px;font-size:14px;line-height:1.5;white-space:pre-wrap;}
		</style>
		<?php if ( $message ) : ?>
			<div class="asc-ticket-msg"><strong>Ticket message:</strong><br><?php echo esc_html( $message ); ?></div>
		<?php endif; ?>
		<div class="asc-convo">
			<?php if ( is_array( $transcript ) && $transcript ) : ?>
				<?php foreach ( $transcript as $item ) :
					$is_user = ( 'user' === $item['role'] ); ?>
					<div class="asc-convo-row <?php echo $is_user ? 'asc-user' : 'asc-bot'; ?>">
						<div class="asc-convo-bubble"><?php echo esc_html( $item['message'] ); ?></div>
						<div class="asc-convo-meta"><?php echo $is_user ? 'Visitor' : 'AI Bot'; ?> · <?php echo esc_html( $item['time'] ); ?></div>
					</div>
				<?php endforeach; ?>
			<?php elseif ( $post->post_content ) : ?>
				<pre style="white-space:pre-wrap;font-size:13px;margin:0;"><?php echo esc_html( $post->post_content ); ?></pre>
			<?php else : ?>
				<p style="color:#8c8f94;margin:0;">No chat history before this ticket.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_reply_box( $post ) {
		$replies = get_comments( array( 'post_id' => $post->ID, 'order' => 'ASC' ) );
		if ( $replies ) {
			echo '<h4 style="margin:4px 0 8px;">Previous replies</h4>';
			foreach ( $replies as $reply ) {
				echo '<div style="border-left:3px solid #00a32a;padding:8px 12px;margin:6px 0;background:#f0f6f0;border-radius:0 6px 6px 0;">';
				echo '<small style="color:#8c8f94;">' . esc_html( $reply->comment_date ) . ' — ' . esc_html( $reply->comment_author ) . '</small><br>';
				echo nl2br( esc_html( $reply->comment_content ) );
				echo '</div>';
			}
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'asc_ticket_reply_' . $post->ID ); ?>
			<input type="hidden" name="action" value="asc_ticket_reply">
			<input type="hidden" name="ticket_id" value="<?php echo (int) $post->ID; ?>">
			<textarea name="reply" rows="5" style="width:100%;border-radius:6px;" required placeholder="Write your reply to the visitor..."></textarea>
			<p><button type="submit" class="button button-primary">Send reply</button></p>
		</form>
		<?php
	}

	public function send_reply() {
		$ticket_id = isset( $_POST['ticket_id'] ) ? (int) $_POST['ticket_id'] : 0;
		check_admin_referer( 'asc_ticket_reply_' . $ticket_id );
		if ( ! current_user_can( 'edit_post', $ticket_id ) ) {
			wp_die( 'Not allowed.' );
		}

		$reply = isset( $_POST['reply'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reply'] ) ) : '';
		$email = get_post_meta( $ticket_id, '_asc_email', true );
		$name  = get_post_meta( $ticket_id, '_asc_name', true );

		if ( $reply && is_email( $email ) ) {
			$site = esc_html( get_bloginfo( 'name' ) );
			$html = '
			<div style="background:#f6f7f7;padding:24px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">
				<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #dcdcde;">
					<div style="background:#2271b1;color:#ffffff;padding:18px 24px;">
						<h2 style="margin:0;font-size:18px;">' . $site . ' — Support</h2>
					</div>
					<div style="padding:20px 24px;font-size:14px;line-height:1.6;color:#1d2327;">
						<p>Hi ' . esc_html( $name ) . ',</p>
						<p>' . nl2br( esc_html( $reply ) ) . '</p>
						<p style="color:#8c8f94;">— ' . $site . ' team</p>
					</div>
				</div>
			</div>';
			wp_mail(
				$email,
				sprintf( 'Re: your support request at %s', get_bloginfo( 'name' ) ),
				$html,
				array( 'Content-Type: text/html; charset=UTF-8' )
			);
			$user = wp_get_current_user();
			wp_insert_comment( array(
				'comment_post_ID'  => $ticket_id,
				'comment_author'   => $user->display_name,
				'comment_content'  => $reply,
				'comment_approved' => 1,
				'comment_type'     => 'asc_reply',
				'user_id'          => $user->ID,
			) );
		}

		wp_safe_redirect( admin_url( 'post.php?post=' . $ticket_id . '&action=edit&asc_replied=1' ) );
		exit;
	}

	public function list_columns( $columns ) {
		return array(
			'cb'          => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'       => 'Ticket',
			'asc_visitor' => 'Visitor',
			'asc_email'   => 'Email',
			'asc_replies' => 'Replies',
			'date'        => 'Date',
		);
	}

	public function list_column_content( $column, $post_id ) {
		if ( 'asc_visitor' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_asc_name', true ) );
		} elseif ( 'asc_email' === $column ) {
			$email = get_post_meta( $post_id, '_asc_email', true );
			echo '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
		} elseif ( 'asc_replies' === $column ) {
			$count = count( get_comments( array( 'post_id' => $post_id, 'count' => false ) ) );
			echo $count ? '<span style="color:#00a32a;font-weight:600;">' . (int) $count . ' ✓</span>' : '<span style="color:#dba617;">pending</span>';
		}
	}
}
