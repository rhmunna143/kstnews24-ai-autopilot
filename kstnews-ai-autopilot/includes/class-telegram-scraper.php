<?php
/**
 * Telegram Public Channel Web Scraper.
 *
 * @package KSTNews_AI_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KSTNews_AI_Telegram_Scraper {

	public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

	/**
	 * Fetch and parse posts from a public Telegram channel.
	 *
	 * @param string $channel
	 * @param int    $last_message_id
	 * @param array  $processed_ids
	 * @param int    $limit
	 * @return array
	 */
	public static function fetch_channel_posts( string $channel, int $last_message_id = 0, array $processed_ids = [], int $limit = 3 ): array {
		$channel = trim( ltrim( $channel, '@' ) );

		if ( ! preg_match( '/^[a-zA-Z0-9_]{4,64}$/', $channel ) ) {
			return [
				'success' => false,
				'error'   => sprintf( 'Invalid Telegram channel format: %s', esc_html( $channel ) ),
				'posts'   => [],
			];
		}

		$url = 'https://t.me/s/' . rawurlencode( $channel );

		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 15,
				'user-agent' => self::USER_AGENT,
				'headers'    => [
					'Accept-Language' => 'en-US,en;q=0.9,bn;q=0.8',
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'error'   => 'HTTP request failed: ' . $response->get_error_message(),
				'posts'   => [],
			];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 404 === $code ) {
			return [
				'success' => false,
				'error'   => sprintf( 'Channel @%s not found (HTTP 404).', $channel ),
				'posts'   => [],
			];
		}

		if ( 429 === $code ) {
			return [
				'success' => false,
				'error'   => 'Telegram rate limit encountered (HTTP 429). Please back off and retry later.',
				'posts'   => [],
			];
		}

		if ( 200 !== $code || empty( $body ) ) {
			return [
				'success' => false,
				'error'   => sprintf( 'Unexpected HTTP status %d while fetching channel @%s', $code, $channel ),
				'posts'   => [],
			];
		}

		$all_posts = self::parse_html( $body, $channel );

		if ( empty( $all_posts ) ) {
			return [
				'success' => true,
				'error'   => '',
				'posts'   => [],
			];
		}

		// Filter out posts that are <= last_message_id or already in processed_ids
		$new_posts = [];
		foreach ( $all_posts as $post ) {
			$msg_id = (int) $post['message_id'];
			$post_key = $channel . ':' . $msg_id;

			// Skip if already processed or older than watermark
			if ( $msg_id <= $last_message_id ) {
				continue;
			}
			if ( in_array( $post_key, $processed_ids, true ) ) {
				continue;
			}

			$new_posts[] = $post;
		}

		// Sort oldest-new to newest per PRD
		usort( $new_posts, fn( $a, $b ) => (int) $a['message_id'] <=> (int) $b['message_id'] );

		// Cap by limit
		if ( $limit > 0 && count( $new_posts ) > $limit ) {
			$new_posts = array_slice( $new_posts, 0, $limit );
		}

		return [
			'success' => true,
			'error'   => '',
			'posts'   => $new_posts,
		];
	}

	/**
	 * Parse HTML content from t.me/s/{channel}.
	 *
	 * @param string $html
	 * @param string $channel
	 * @return array
	 */
	public static function parse_html( string $html, string $channel ): array {
		if ( empty( $html ) ) {
			return [];
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		// Ensure UTF-8 characters (like Bengali script) load properly into DOMDocument
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );

		// Query message elements with data-post attribute
		$message_nodes = $xpath->query( "//div[contains(@class, 'tgme_widget_message') and @data-post]" );

		if ( ! $message_nodes || 0 === $message_nodes->length ) {
			return [];
		}

		$parsed_messages = [];

		foreach ( $message_nodes as $msg_node ) {
			if ( ! ( $msg_node instanceof DOMElement ) ) {
				continue;
			}

			$data_post = $msg_node->getAttribute( 'data-post' ); // e.g. "basherkella/88271"
			$parts     = explode( '/', $data_post );
			$msg_id    = isset( $parts[1] ) ? (int) $parts[1] : (int) $data_post;

			if ( $msg_id <= 0 ) {
				continue;
			}

			// Extract text
			$text = '';
			$text_nodes = $xpath->query( ".//div[contains(@class, 'tgme_widget_message_text')]", $msg_node );
			if ( $text_nodes->length > 0 ) {
				$text = self::extract_message_text( $text_nodes->item( 0 ) );
			}

			// Extract photos
			$photos = self::extract_photos( $msg_node, $xpath );

			// Extract videos and video thumbnail
			$videos      = self::extract_videos( $msg_node, $xpath );
			$video_thumb = self::extract_video_thumb( $msg_node, $xpath );

			// Determine primary media URL (first photo or video thumbnail)
			$primary_image = null;
			if ( ! empty( $photos ) ) {
				$primary_image = $photos[0];
			} elseif ( ! empty( $video_thumb ) ) {
				$primary_image = $video_thumb;
			}

			// Detect inline buttons
			$has_buttons = self::has_inline_buttons( $msg_node, $xpath );

			// Extract message date
			$date = self::extract_date( $msg_node, $xpath );

			$parsed_messages[ $msg_id ] = [
				'channel'       => $channel,
				'data_post'     => $data_post,
				'message_id'    => $msg_id,
				'source_url'    => 'https://t.me/' . $data_post,
				'raw_text'      => $text,
				'photos'        => $photos,
				'videos'        => $videos,
				'video_thumb'   => $video_thumb,
				'primary_image' => $primary_image,
				'has_buttons'   => $has_buttons,
				'date'          => $date,
			];
		}

		return array_values( $parsed_messages );
	}

	/**
	 * Extract text preserving line breaks.
	 *
	 * @param DOMNode $node
	 * @return string
	 */
	public static function extract_message_text( DOMNode $node ): string {
		$text = '';
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text .= $child->textContent;
			} elseif ( XML_ELEMENT_NODE === $child->nodeType ) {
				$tag = strtolower( $child->nodeName );
				if ( 'br' === $tag ) {
					$text .= "\n";
				} else {
					$text .= self::extract_message_text( $child );
				}
			}
		}

		return trim( $text );
	}

	/**
	 * Extract photo URLs from background-image styles.
	 *
	 * @param DOMElement $msg_node
	 * @param DOMXPath   $xpath
	 * @return array
	 */
	public static function extract_photos( DOMElement $msg_node, DOMXPath $xpath ): array {
		$photos = [];
		$photo_nodes = $xpath->query( ".//a[contains(@class, 'tgme_widget_message_photo_wrap')]", $msg_node );

		foreach ( $photo_nodes as $p ) {
			if ( ! ( $p instanceof DOMElement ) ) {
				continue;
			}
			$style = $p->getAttribute( 'style' );
			if ( preg_match( "/background-image:url\(['\"]?(.*?)['\"]?\)/i", $style, $m ) ) {
				$url = trim( $m[1] );
				if ( self::is_allowed_telegram_cdn( $url ) ) {
					$photos[] = $url;
				}
			}
		}

		return array_values( array_unique( $photos ) );
	}

	/**
	 * Extract direct video MP4 URLs.
	 *
	 * @param DOMElement $msg_node
	 * @param DOMXPath   $xpath
	 * @return array
	 */
	public static function extract_videos( DOMElement $msg_node, DOMXPath $xpath ): array {
		$videos = [];
		$video_nodes = $xpath->query( ".//video", $msg_node );

		foreach ( $video_nodes as $v ) {
			if ( ! ( $v instanceof DOMElement ) ) {
				continue;
			}
			$src = $v->getAttribute( 'src' );
			if ( ! empty( $src ) && self::is_allowed_telegram_cdn( $src ) ) {
				$videos[] = $src;
			}
		}

		return array_values( array_unique( $videos ) );
	}

	/**
	 * Extract video thumbnail URL from background-image style.
	 *
	 * @param DOMElement $msg_node
	 * @param DOMXPath   $xpath
	 * @return string|null
	 */
	public static function extract_video_thumb( DOMElement $msg_node, DOMXPath $xpath ): ?string {
		$thumb_nodes = $xpath->query( ".//*[contains(@class, 'tgme_widget_message_video_thumb')]", $msg_node );

		foreach ( $thumb_nodes as $vt ) {
			if ( ! ( $vt instanceof DOMElement ) ) {
				continue;
			}
			$style = $vt->getAttribute( 'style' );
			if ( preg_match( "/background-image:url\(['\"]?(.*?)['\"]?\)/i", $style, $m ) ) {
				$url = trim( $m[1] );
				if ( self::is_allowed_telegram_cdn( $url ) ) {
					return $url;
				}
			}
		}

		return null;
	}

	/**
	 * Check if message has inline buttons or button links.
	 *
	 * @param DOMElement $msg_node
	 * @param DOMXPath   $xpath
	 * @return bool
	 */
	public static function has_inline_buttons( DOMElement $msg_node, DOMXPath $xpath ): bool {
		$buttons = $xpath->query(
			".//*[contains(@class, 'tgme_widget_message_inline_button') or contains(@class, 'tgme_widget_message_inline_keyboard') or contains(@class, 'tgme_widget_message_reply')]",
			$msg_node
		);

		return $buttons && $buttons->length > 0;
	}

	/**
	 * Extract message ISO 8601 or formatted date.
	 *
	 * @param DOMElement $msg_node
	 * @param DOMXPath   $xpath
	 * @return string
	 */
	public static function extract_date( DOMElement $msg_node, DOMXPath $xpath ): string {
		$time_nodes = $xpath->query( ".//time", $msg_node );
		if ( $time_nodes && $time_nodes->length > 0 ) {
			$dt = $time_nodes->item( 0 )->getAttribute( 'datetime' );
			if ( ! empty( $dt ) ) {
				return $dt;
			}
		}

		return '';
	}

	/**
	 * SSRF guard: Ensure URL host belongs to verified Telegram CDN domains.
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function is_allowed_telegram_cdn( string $url ): bool {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return false;
		}

		$host = strtolower( $host );

		// Match *.telesco.pe, *.telegram.org, *.t.me, telesco.pe, t.me, etc.
		if ( 't.me' === $host || 'telesco.pe' === $host || 'telegram.org' === $host ) {
			return true;
		}

		if ( str_ends_with( $host, '.telesco.pe' ) || str_ends_with( $host, '.telegram.org' ) || str_ends_with( $host, '.t.me' ) ) {
			return true;
		}

		return false;
	}
}
