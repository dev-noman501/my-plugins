<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Thin AI API client (embeddings + chat) over wp_remote_post.
 * Chat providers: OpenAI, Google Gemini, OpenRouter (all via OpenAI-compatible APIs).
 * Embeddings: OpenAI or Gemini only — OpenRouter has no embeddings API, so when
 * chatting through OpenRouter a separate embeddings provider/key is configured.
 */
class ASC_OpenAI {

	private $api_key;
	private $provider;

	public function __construct() {
		$this->api_key  = self::key( 'ASC_API_KEY', 'asc_api_key' );
		$this->provider = get_option( 'asc_provider', 'openai' );
	}

	/**
	 * Reads a credential, preferring a wp-config.php constant over the database.
	 *
	 * Defining the key in wp-config.php keeps it out of the options table, out of
	 * database exports and backups, and out of anything committed to version
	 * control. The settings field stays as the easy option for non-developers.
	 *
	 * @param string $constant Constant name, e.g. ASC_API_KEY.
	 * @param string $option   Option name to fall back to.
	 * @return string
	 */
	public static function key( $constant, $option ) {
		if ( defined( $constant ) && constant( $constant ) ) {
			return (string) constant( $constant );
		}
		return (string) get_option( $option, '' );
	}

	private function base_url( $provider ) {
		switch ( $provider ) {
			case 'gemini':
				return 'https://generativelanguage.googleapis.com/v1beta/openai/';
			case 'openrouter':
				return 'https://openrouter.ai/api/v1/';
			default:
				return 'https://api.openai.com/v1/';
		}
	}

	private function embed_provider() {
		$p = get_option( 'asc_embed_provider' );
		if ( $p && 'same' !== $p ) {
			return $p;
		}
		return $this->provider;
	}

	private function embed_key() {
		$key = self::key( 'ASC_EMBED_API_KEY', 'asc_embed_api_key' );
		return $key ? $key : $this->api_key;
	}

	private function chat_model() {
		$model = get_option( 'asc_model' );
		// Guard against a stale model after switching provider.
		switch ( $this->provider ) {
			case 'gemini':
				return ( $model && 0 === strpos( $model, 'gemini' ) ) ? $model : 'gemini-2.0-flash';
			case 'openrouter':
				return ( $model && false !== strpos( $model, '/' ) ) ? $model : 'meta-llama/llama-3.3-70b-instruct:free';
			default:
				return ( $model && 0 === strpos( $model, 'gpt' ) ) ? $model : 'gpt-4o-mini';
		}
	}

	private function request( $provider, $api_key, $endpoint, $body ) {
		if ( ! $api_key ) {
			return new WP_Error( 'asc_no_key', 'API key is not configured.' );
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		);
		if ( 'openrouter' === $provider ) {
			$headers['HTTP-Referer'] = home_url();
			$headers['X-Title']      = get_bloginfo( 'name' );
		}
		$res = wp_remote_post( $this->base_url( $provider ) . $endpoint, array(
			'timeout' => 60,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'AI API error (HTTP ' . $code . ')';
			return new WP_Error( 'asc_api', $msg );
		}
		return $data;
	}

	/**
	 * @param string[] $texts
	 * @return array[]|WP_Error Array of embedding vectors, same order as input.
	 */
	public function embed( array $texts ) {
		$provider = $this->embed_provider();
		if ( 'openrouter' === $provider ) {
			return new WP_Error(
				'asc_embed',
				'OpenRouter has no embeddings API. On the settings page set the Embeddings provider to Gemini or OpenAI with its own key.'
			);
		}
		$data = $this->request( $provider, $this->embed_key(), 'embeddings', array(
			'model' => 'gemini' === $provider ? 'text-embedding-004' : 'text-embedding-3-small',
			'input' => array_values( $texts ),
		) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$vectors = array();
		foreach ( $data['data'] as $item ) {
			$vectors[ $item['index'] ] = $item['embedding'];
		}
		ksort( $vectors );
		return array_values( $vectors );
	}

	/**
	 * @param array $messages [['role'=>..., 'content'=>...], ...]
	 * @return string|WP_Error Assistant reply text.
	 */
	public function chat( array $messages ) {
		$body = array(
			'model'       => $this->chat_model(),
			'messages'    => $messages,
			'temperature' => 0.2,
			'max_tokens'  => 500,
		);
		// Free models get rate-limited upstream often; let OpenRouter fall back.
		if ( 'openrouter' === $this->provider ) {
			// Curated chat models only — the openrouter/free auto-router can pick
			// non-chat models (e.g. safety classifiers) that return garbage.
			// OpenRouter allows at most 3 entries; spread across providers so
			// one congested provider doesn't take all fallbacks down.
			$body['models'] = array_slice( array_values( array_unique( array(
				$this->chat_model(),
				'google/gemma-4-31b-it:free',
				'openai/gpt-oss-20b:free',
				'meta-llama/llama-3.3-70b-instruct:free',
			) ) ), 0, 3 );
		}
		$data = $this->request( $this->provider, $this->api_key, 'chat/completions', $body );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'asc_api', 'Empty response from the AI API.' );
		}
		return $data['choices'][0]['message']['content'];
	}
}
