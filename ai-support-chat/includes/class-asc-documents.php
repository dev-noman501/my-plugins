<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Knowledge documents: upload PDF/DOCX/TXT/MD from the dashboard, extract
 * their text into a hidden asc_document post, and let the existing indexer
 * chunk/index it like any other content. Deleting a document removes its
 * chunks via the indexer's before_delete_post hook.
 */
class ASC_Documents {

	const CPT      = 'asc_document';
	const MAX_SIZE = 10485760; // 10 MB

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'admin_post_asc_upload_doc', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_asc_delete_doc', array( $this, 'handle_delete' ) );
	}

	public function register_cpt() {
		register_post_type( self::CPT, array(
			'labels'              => array( 'name' => 'Knowledge Documents', 'singular_name' => 'Knowledge Document' ),
			'public'              => false,
			'show_ui'             => false,
			'exclude_from_search' => true,
		) );
	}

	private static function autoload_pdfparser() {
		static $registered = false;
		if ( $registered ) return;
		$registered = true;
		spl_autoload_register( function ( $class ) {
			$prefix = 'Smalot\\PdfParser\\';
			if ( 0 !== strpos( $class, $prefix ) ) return;
			$path = ASC_PATH . 'includes/lib/PdfParser/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( file_exists( $path ) ) require $path;
		} );
	}

	public function handle_upload() {
		check_admin_referer( 'asc_upload_doc' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );

		if ( empty( $_FILES['asc_doc_file']['name'] ) ) {
			$this->redirect( 'err', 'No file selected.' );
		}
		if ( ! empty( $_FILES['asc_doc_file']['size'] ) && $_FILES['asc_doc_file']['size'] > self::MAX_SIZE ) {
			$this->redirect( 'err', 'File is larger than 10 MB.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$uploaded = wp_handle_upload( $_FILES['asc_doc_file'], array(
			'test_form' => false,
			'mimes'     => array(
				'pdf'  => 'application/pdf',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'txt'  => 'text/plain',
				'md'   => 'text/plain',
			),
		) );
		if ( isset( $uploaded['error'] ) ) {
			$this->redirect( 'err', $uploaded['error'] );
		}

		$filename = sanitize_file_name( wp_unslash( $_FILES['asc_doc_file']['name'] ) );
		$doc_id   = self::create_document( $uploaded['file'], $filename, $uploaded['url'] );

		if ( is_wp_error( $doc_id ) ) {
			wp_delete_file( $uploaded['file'] );
			$this->redirect( 'err', $doc_id->get_error_message() );
		}
		$this->redirect( 'added', $filename );
	}

	public function handle_delete() {
		$doc_id = isset( $_GET['doc_id'] ) ? (int) $_GET['doc_id'] : 0;
		check_admin_referer( 'asc_delete_doc_' . $doc_id );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );

		$post = get_post( $doc_id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			$this->redirect( 'err', 'Document not found.' );
		}

		$file = get_post_meta( $doc_id, '_asc_file_path', true );
		if ( $file && file_exists( $file ) ) {
			wp_delete_file( $file );
		}
		wp_delete_post( $doc_id, true ); // indexer's before_delete_post removes the chunks
		$this->redirect( 'deleted', $post->post_title );
	}

	private function redirect( $status, $detail = '' ) {
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'asc-settings', 'tab' => 'training', 'asc_doc' => $status, 'asc_doc_msg' => rawurlencode( $detail ) ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Extract text and store as an asc_document post (auto-indexed on save).
	 * Also used directly by CLI tests.
	 *
	 * @return int|WP_Error Document post ID.
	 */
	public static function create_document( $path, $filename, $url = '' ) {
		$text = self::extract_text( $path );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$text = trim( preg_replace( '/[ \t]+/', ' ', $text ) );
		if ( strlen( $text ) < 40 ) {
			return new WP_Error( 'asc_doc_empty', 'No readable text found in this document. Scanned/image-only PDFs are not supported.' );
		}

		$doc_id = wp_insert_post( array(
			'post_type'    => self::CPT,
			'post_status'  => 'publish',
			'post_title'   => $filename,
			'post_content' => $text,
		), true );
		if ( is_wp_error( $doc_id ) ) {
			return $doc_id;
		}

		update_post_meta( $doc_id, '_asc_file_path', $path );
		update_post_meta( $doc_id, '_asc_file_url', $url );
		return $doc_id;
	}

	/**
	 * @return string|WP_Error
	 */
	public static function extract_text( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( 'txt' === $ext || 'md' === $ext ) {
			$text = file_get_contents( $path );
			return false === $text ? new WP_Error( 'asc_doc_read', 'Could not read the file.' ) : $text;
		}

		if ( 'docx' === $ext ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				return new WP_Error( 'asc_doc_zip', 'The PHP zip extension is required for DOCX files.' );
			}
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				return new WP_Error( 'asc_doc_read', 'Could not open the DOCX file.' );
			}
			$xml = $zip->getFromName( 'word/document.xml' );
			$zip->close();
			if ( false === $xml ) {
				return new WP_Error( 'asc_doc_read', 'Invalid DOCX file.' );
			}
			$xml = str_replace( array( '</w:p>', '<w:br/>', '<w:tab/>' ), array( "\n", "\n", ' ' ), $xml );
			return wp_strip_all_tags( $xml );
		}

		if ( 'pdf' === $ext ) {
			self::autoload_pdfparser();
			try {
				$parser = new \Smalot\PdfParser\Parser();
				return $parser->parseFile( $path )->getText();
			} catch ( \Throwable $e ) {
				return new WP_Error( 'asc_pdf', 'Could not read the PDF: ' . $e->getMessage() );
			}
		}

		return new WP_Error( 'asc_doc_type', 'Unsupported file type. Allowed: PDF, DOCX, TXT, MD.' );
	}

	/**
	 * Settings-page section: notices, upload form, documents table.
	 */
	public static function render_section() {
		global $wpdb;

		if ( isset( $_GET['asc_doc'] ) ) {
			$detail = isset( $_GET['asc_doc_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['asc_doc_msg'] ) ) ) : '';
			$map    = array(
				'added'   => array( 'notice-success', 'Document uploaded and indexed: ' ),
				'deleted' => array( 'notice-success', 'Document deleted: ' ),
				'err'     => array( 'notice-error', 'Document error: ' ),
			);
			$key = sanitize_key( $_GET['asc_doc'] );
			if ( isset( $map[ $key ] ) ) {
				echo '<div class="notice ' . esc_attr( $map[ $key ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $key ][1] . $detail ) . '</p></div>';
			}
		}
		?>
		<hr>
		<h2>📄 Knowledge Documents</h2>
		<p class="description">Upload documents the AI should learn from — price lists, policies, FAQs, manuals. Supported: PDF, DOCX, TXT, MD (max 10 MB). Text-only PDFs work; scanned/image PDFs are not supported.</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin:12px 0 18px;">
			<?php wp_nonce_field( 'asc_upload_doc' ); ?>
			<input type="hidden" name="action" value="asc_upload_doc">
			<input type="file" name="asc_doc_file" accept=".pdf,.docx,.txt,.md" required>
			<button type="submit" class="button button-primary">Upload &amp; train</button>
		</form>

		<?php
		$docs = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC' ) );
		if ( $docs ) : ?>
			<table class="widefat striped" style="max-width:760px;">
				<thead><tr><th>Document</th><th>Chunks</th><th>Uploaded</th><th style="width:70px;"></th></tr></thead>
				<tbody>
				<?php foreach ( $docs as $doc ) :
					$chunks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}asc_chunks WHERE post_id = %d", $doc->ID ) );
					$delete = wp_nonce_url(
						admin_url( 'admin-post.php?action=asc_delete_doc&doc_id=' . $doc->ID ),
						'asc_delete_doc_' . $doc->ID
					); ?>
					<tr>
						<td>📄 <strong><?php echo esc_html( $doc->post_title ); ?></strong></td>
						<td><?php echo $chunks; ?></td>
						<td><?php echo esc_html( get_the_date( 'Y-m-d H:i', $doc ) ); ?></td>
						<td><a href="<?php echo esc_url( $delete ); ?>" style="color:#b32d2e;" onclick="return confirm('Delete this document and its training data?');">Delete</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p style="color:#8c8f94;">No documents uploaded yet.</p>
		<?php endif;
	}
}
