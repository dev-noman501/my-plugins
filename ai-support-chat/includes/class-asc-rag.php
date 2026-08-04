<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RAG pipeline: embed the question, find the most relevant content chunks,
 * ask the model with that context only, detect when to hand off to a human.
 */
class ASC_RAG {

	const TOP_K     = 5;
	const MIN_SCORE = 0.2;
	const HANDOFF   = '[HANDOFF]';

	/**
	 * @return array|WP_Error ['reply' => string, 'handoff' => bool]
	 */
	public function answer( $session_id, $question ) {
		// Greetings/thanks get a deterministic reply — no AI call, no chance
		// of a flaky model refusing "hello" or wasting free-tier quota.
		$canned = $this->canned_reply( $question );
		if ( null !== $canned ) {
			$this->save_message( $session_id, 'user', $question );
			$this->save_message( $session_id, 'assistant', $canned );
			return array( 'reply' => $canned, 'handoff' => false );
		}

		$openai = new ASC_OpenAI();

		// Embeddings failure degrades to keyword search instead of killing the chat.
		$context = '';
		$vectors = $openai->embed( array( $question ) );
		if ( is_wp_error( $vectors ) ) {
			error_log( 'ASC embed error, using keyword search: ' . $vectors->get_error_message() );
		} else {
			$context = $this->top_chunks( $vectors[0] );
		}
		if ( '' === $context ) {
			$context = $this->keyword_chunks( $question );
		}

		$system = 'You are a friendly AI support assistant for "' . get_bloginfo( 'name' ) . '". '
			. "For factual questions about the business, answer using ONLY the information in the CONTEXT below.\n"
			. "Rules:\n"
			. "- Greetings, small talk, thanks, and questions about you yourself (e.g. \"are you an AI?\", \"what can you do?\") do NOT need the context — answer them naturally: you are an AI assistant that answers questions about " . get_bloginfo( 'name' ) . " and can connect the user to the human team.\n"
			. "- For business facts: if the answer is not in the context, do NOT invent anything. Say briefly that you don't have that information and append the exact token " . self::HANDOFF . "\n"
			. '- If the user asks to talk to a human, the team, or support, reply briefly and append ' . self::HANDOFF . "\n"
			. "- Only state facts written explicitly in the context. Never guess or infer names, roles, prices, or relationships that are not stated.\n"
			. "- Earlier assistant replies in this conversation may contain mistakes. The CONTEXT below is the only source of truth — if an earlier reply conflicts with it, correct the mistake instead of repeating it.\n"
			. "- Client testimonials/reviews in the context are written by customers of the business — they are NOT employees, founders, or executives. When the user asks about reviews, feedback, or what clients say, DO share and quote those testimonials.\n"
			. "- Write in plain text only: no markdown, no asterisks, no headings, no bullet symbols.\n"
			. "- Reply in the same language the user writes in.\n"
			. "- Keep answers short, accurate and helpful.\n\n"
			. "CONTEXT:\n" . ( $context ? $context : '(no relevant content found)' );

		$messages = array( array( 'role' => 'system', 'content' => $system ) );
		foreach ( $this->history( $session_id, 6 ) as $row ) {
			$messages[] = array( 'role' => $row->role, 'content' => $row->message );
		}
		$messages[] = array( 'role' => 'user', 'content' => $question );

		$reply = $openai->chat( $messages );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$handoff = false !== strpos( $reply, self::HANDOFF );
		$reply   = trim( str_replace( self::HANDOFF, '', $reply ) );
		$reply   = $this->strip_markdown( $reply );

		$this->save_message( $session_id, 'user', $question );
		$this->save_message( $session_id, 'assistant', $reply );

		return array( 'reply' => $reply, 'handoff' => $handoff );
	}

	/**
	 * @return string|null Canned reply for greetings/thanks, null otherwise.
	 */
	private function canned_reply( $question ) {
		$q = strtolower( trim( preg_replace( '/[\s!.?,]+$/u', '', $question ) ) );
		if ( preg_match( '/^(hi+|hii+|hello+|hey+|salam|salaam|a?ssalam[ou]?\s*[ao]?laik[ou]m|good\s+(morning|afternoon|evening)|how are you\??)$/i', $q ) ) {
			return 'Hello! I\'m the AI assistant for ' . get_bloginfo( 'name' ) . '. Ask me anything about our services — and if I can\'t help, I\'ll connect you with our team.';
		}
		if ( preg_match( '/^(thanks?|thank you|thanku|thx|shukriya|great,?\s*thanks?|ok(ay)?,?\s*thanks?)$/i', $q ) ) {
			return 'You\'re welcome! Is there anything else I can help you with?';
		}
		if ( preg_match( '/^(bye+|goodbye|allah hafiz|khuda hafiz|see you)$/i', $q ) ) {
			return 'Goodbye! Feel free to come back anytime you have questions.';
		}
		return null;
	}

	/**
	 * The widget renders plain text, and models ignore "no markdown"
	 * instructions often enough that we strip formatting server-side.
	 */
	private function strip_markdown( $text ) {
		$text = preg_replace( '/\*\*(.+?)\*\*/s', '$1', $text );          // **bold**
		$text = preg_replace( '/__(.+?)__/s', '$1', $text );              // __bold__
		$text = preg_replace( '/(?<!\*)\*([^*\n]+)\*(?!\*)/', '$1', $text ); // *italic*
		$text = preg_replace( '/`([^`\n]+)`/', '$1', $text );             // `code`
		$text = preg_replace( '/^#{1,6}\s+/m', '', $text );               // # headings
		$text = preg_replace( '/^\s*[-*]\s+/m', '- ', $text );            // normalize bullets
		return trim( $text );
	}

	private function top_chunks( array $query_vector ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT c.content, c.embedding, p.post_title
			 FROM {$wpdb->prefix}asc_chunks c
			 LEFT JOIN {$wpdb->posts} p ON p.ID = c.post_id"
		);
		if ( empty( $rows ) ) {
			return '';
		}

		$scored = array();
		foreach ( $rows as $row ) {
			$vec = json_decode( $row->embedding, true );
			if ( ! is_array( $vec ) ) continue;
			$score = $this->cosine( $query_vector, $vec );
			if ( $score >= self::MIN_SCORE ) {
				$scored[] = array( 'score' => $score, 'row' => $row );
			}
		}
		usort( $scored, function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
		$scored = array_slice( $scored, 0, self::TOP_K );

		$context = '';
		foreach ( $scored as $item ) {
			$context .= '[Source: ' . $item['row']->post_title . "]\n" . $item['row']->content . "\n\n";
		}
		return trim( $context );
	}

	/**
	 * Fallback retrieval when no embeddings are available: score chunks by
	 * how often the question's terms appear in them.
	 */
	private function keyword_chunks( $question ) {
		global $wpdb;

		$stop  = array( 'the', 'and', 'for', 'you', 'your', 'what', 'who', 'why', 'how', 'are', 'can', 'does', 'do', 'with', 'about', 'have', 'this', 'that', 'please', 'tell' );
		$terms = array_filter(
			array_unique( preg_split( '/\s+/', strtolower( preg_replace( '/[^a-z0-9\s]/i', ' ', $question ) ) ) ),
			function ( $word ) use ( $stop ) {
				return strlen( $word ) > 2 && ! in_array( $word, $stop, true );
			}
		);
		if ( empty( $terms ) ) {
			return '';
		}

		$rows = $wpdb->get_results(
			"SELECT c.content, p.post_title
			 FROM {$wpdb->prefix}asc_chunks c
			 LEFT JOIN {$wpdb->posts} p ON p.ID = c.post_id"
		);

		$scored = array();
		foreach ( $rows as $row ) {
			$haystack = strtolower( $row->post_title . ' ' . $row->content );
			$score    = 0;
			foreach ( $terms as $term ) {
				$score += substr_count( $haystack, $term );
			}
			if ( $score > 0 ) {
				$scored[] = array( 'score' => $score, 'row' => $row );
			}
		}
		usort( $scored, function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
		$scored = array_slice( $scored, 0, self::TOP_K );

		$context = '';
		foreach ( $scored as $item ) {
			$context .= '[Source: ' . $item['row']->post_title . "]\n" . $item['row']->content . "\n\n";
		}
		return trim( $context );
	}

	private function cosine( array $a, array $b ) {
		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		$n   = min( count( $a ), count( $b ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
			$na  += $a[ $i ] * $a[ $i ];
			$nb  += $b[ $i ] * $b[ $i ];
		}
		if ( ! $na || ! $nb ) {
			return 0.0;
		}
		return $dot / ( sqrt( $na ) * sqrt( $nb ) );
	}

	private function history( $session_id, $limit ) {
		global $wpdb;
		// Only recent USER messages. Old assistant replies are excluded on
		// purpose: small models parrot their own earlier answers even when
		// those were wrong, which poisons every later turn of the session.
		$since = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT role, message FROM {$wpdb->prefix}asc_messages
			 WHERE session_id = %s AND role = 'user' AND created_at >= %s
			 ORDER BY id DESC LIMIT %d",
			$session_id, $since, $limit
		) );
		return array_reverse( $rows );
	}

	private function save_message( $session_id, $role, $message ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'asc_messages', array(
			'session_id' => $session_id,
			'role'       => $role,
			'message'    => $message,
			'created_at' => current_time( 'mysql' ),
		) );
	}
}
