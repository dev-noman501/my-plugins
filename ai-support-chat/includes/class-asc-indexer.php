<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Turns site content into embedded chunks (the bot's "training data").
 * Re-indexes a post automatically on save; full re-index runs in batches
 * from the admin page.
 */
class ASC_Indexer {

	const CHUNK_WORDS   = 400;
	const OVERLAP_WORDS = 50;

	private static $inst = null;

	public static function instance() {
		if ( null === self::$inst ) {
			self::$inst = new self();
			add_action( 'save_post', array( self::$inst, 'on_save_post' ), 20, 2 );
			add_action( 'before_delete_post', array( self::$inst, 'delete_post_chunks' ) );
		}
		return self::$inst;
	}

	public function post_types() {
		return apply_filters( 'asc_post_types', array( 'post', 'page', 'asc_document' ) );
	}

	public function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
		if ( ! in_array( $post->post_type, $this->post_types(), true ) ) return;
		if ( '' === ASC_OpenAI::key( 'ASC_API_KEY', 'asc_api_key' ) ) return;

		$this->delete_post_chunks( $post_id );
		if ( 'publish' === $post->post_status ) {
			$this->index_post( $post );
		}
	}

	public function delete_post_chunks( $post_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'asc_chunks', array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * @return int|WP_Error Number of chunks stored.
	 */
	public function index_post( $post ) {
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post );
		}
		if ( ! $post ) {
			return 0;
		}

		$text = $post->post_title . "\n\n" . wp_strip_all_tags( strip_shortcodes( $post->post_content ) );

		// Pull in ACF text fields when ACF is active.
		if ( function_exists( 'get_fields' ) ) {
			$fields = get_fields( $post->ID );
			if ( is_array( $fields ) ) {
				foreach ( $fields as $value ) {
					if ( is_string( $value ) && strlen( $value ) > 30 ) {
						$text .= "\n\n" . wp_strip_all_tags( $value );
					}
				}
			}
		}

		$chunks = $this->chunk( $text );
		if ( empty( $chunks ) ) {
			return 0;
		}

		$openai     = new ASC_OpenAI();
		$embeddings = $openai->embed( $chunks );
		if ( is_wp_error( $embeddings ) ) {
			// No embeddings available — store the chunks anyway so the
			// keyword-search fallback in ASC_RAG can still use them.
			error_log( 'ASC indexer: embeddings unavailable, storing chunks for keyword search. (' . $embeddings->get_error_message() . ')' );
			$embeddings = array_fill( 0, count( $chunks ), null );
		}

		global $wpdb;
		foreach ( $chunks as $i => $chunk ) {
			$wpdb->insert( $wpdb->prefix . 'asc_chunks', array(
				'post_id'     => $post->ID,
				'chunk_index' => $i,
				'content'     => $chunk,
				'embedding'   => is_array( $embeddings[ $i ] ) ? wp_json_encode( $embeddings[ $i ] ) : '',
				'updated_at'  => current_time( 'mysql' ),
			) );
		}
		return count( $chunks );
	}

	/**
	 * Split text into overlapping word-based chunks.
	 *
	 * @return string[]
	 */
	private function chunk( $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( strlen( $text ) < 40 ) {
			return array();
		}
		$words = explode( ' ', $text );
		$total = count( $words );
		$step  = self::CHUNK_WORDS - self::OVERLAP_WORDS;

		$chunks = array();
		for ( $i = 0; $i < $total; $i += $step ) {
			$slice = array_slice( $words, $i, self::CHUNK_WORDS );
			if ( count( $slice ) < 20 && ! empty( $chunks ) ) {
				break; // tail already covered by the overlap
			}
			$chunks[] = implode( ' ', $slice );
			if ( $i + self::CHUNK_WORDS >= $total ) {
				break;
			}
		}
		return $chunks;
	}
}
