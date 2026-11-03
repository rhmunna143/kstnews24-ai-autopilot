<?php
/**
 * Google Gemini 2.5 Flash API Client.
 *
 * @package KSTNews_AI_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KSTNews_AI_Gemini_Client {

	public const MODEL    = 'gemini-2.5-flash';
	public const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';
	public const MIN_WORD_COUNT = 300;

	/**
	 * Allowed HTML tags in article content for wp_kses.
	 */
	public const ALLOWED_HTML_TAGS = [
		'p'      => [],
		'h2'     => [],
		'h3'     => [],
		'ul'     => [],
		'ol'     => [],
		'li'     => [],
		'strong' => [],
		'em'     => [],
		'a'      => [
			'href'   => true,
			'title'  => true,
			'target' => true,
			'rel'    => true,
		],
		'br'     => [],
	];

	/**
	 * Test the Gemini API connection with the provided key.
	 *
	 * @param string $api_key
	 * @return array ['success' => bool, 'message' => string]
	 */
	public static function test_connection( string $api_key ): array {
		$api_key = trim( $api_key );
		if ( empty( $api_key ) ) {
			return [
				'success' => false,
				'message' => 'API key cannot be empty.',
			];
		}

		$url = self::ENDPOINT . self::MODEL . ':generateContent?key=' . rawurlencode( $api_key );

		$payload = [
			'contents' => [
				[
					'parts' => [
						[ 'text' => 'Respond with OK if you receive this message.' ],
					],
				],
			],
			'generationConfig' => [
				'maxOutputTokens' => 10,
			],
		];

		$response = wp_remote_post(
			$url,
			[
				'timeout' => 15,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => 'Connection failed: ' . $response->get_error_message(),
			];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( 200 === $code ) {
			return [
				'success' => true,
				'message' => 'Gemini 2.5 Flash API connection verified successfully! Ready to generate news.',
			];
		}

		$error_msg = $json['error']['message'] ?? ( 'HTTP ' . $code . ' ' . $body );
		return [
			'success' => false,
			'message' => 'Gemini API Error (' . $code . '): ' . $error_msg,
		];
	}

	/**
	 * Process and expand a Telegram message into a full SEO news article.
	 *
	 * @param array $message  Scraped message data.
	 * @param array $settings Plugin settings.
	 * @param int   $attempt  Internal retry counter.
	 * @return array
	 */
	public static function generate_article( array $message, array $settings, int $attempt = 1 ): array {
		$api_key = $settings['gemini_key'] ?? KSTNews_AI_Settings::get( 'gemini_key', '' );
		if ( empty( $api_key ) ) {
			return [
				'success' => false,
				'error'   => 'Missing Gemini API key.',
			];
		}

		$raw_text = trim( (string) ( $message['raw_text'] ?? '' ) );
		if ( empty( $raw_text ) ) {
			return [
				'success' => false,
				'error'   => 'Empty message text.',
			];
		}

		// Collect category options
		$default_cat = $settings['default_category'] ?? 'জাতীয়';
		$categories  = [ $default_cat, 'জাতীয়', 'আন্তর্জাতিক', 'রাজনীতি', 'অর্থনীতি', 'খেলাধুলা', 'বিজ্ঞান ও প্রযুক্তি', 'বিনোদন', 'সারাদেশ' ];
		$categories  = array_values( array_unique( $categories ) );

		$prompt = ( 1 === $attempt )
			? self::build_prompt( $raw_text, $categories )
			: self::build_corrective_prompt( $raw_text, $categories );

		$payload = [
			'contents' => [
				[
					'parts' => [
						[ 'text' => $prompt ],
					],
				],
			],
			'generationConfig' => [
				'responseMimeType' => 'application/json',
				'responseSchema'   => self::get_response_schema(),
				'temperature'      => 0.3, // Low temperature for factual precision
			],
		];

		$response = self::call_api( $api_key, $payload );

		if ( ! $response['success'] ) {
			return $response;
		}

		$data = $response['data'];

		// Step 1: Layer 2 Gate Safety Checks
		if ( empty( $data['isGenuineNews'] ) || empty( $data['isAdSenseSafe'] ) ) {
			$reason = ! empty( $data['rejectReason'] ) ? $data['rejectReason'] : 'Blocked by Gemini AI safety gate.';
			return [
				'success'      => false,
				'blocked_by_ai' => true,
				'rejectReason' => $reason,
			];
		}

		// Step 2: Validate output constraints (word count, slug, meta, html)
		$validation = self::validate_and_sanitize( $data, $raw_text, $default_cat );

		// If validation failed on word count and we have not retried yet, retry once with corrective prompt
		if ( ! $validation['valid'] && $attempt < 2 ) {
			return self::generate_article( $message, $settings, $attempt + 1 );
		}

		if ( ! $validation['valid'] ) {
			return [
				'success' => false,
				'error'   => 'Article output validation failed: ' . implode( '; ', $validation['errors'] ),
			];
		}

		return [
			'success' => true,
			'article' => $validation['article'],
		];
	}

	/**
	 * Build primary instruction prompt.
	 *
	 * @param string $raw_text
	 * @param array  $categories
	 * @return string
	 */
	public static function build_prompt( string $raw_text, array $categories ): string {
		$cat_list = implode( ', ', $categories );

		return <<<PROMPT
You are a senior professional Bengali investigative journalist and digital news editor at "kstnews24.com".
Your task is to analyze the following Telegram news source material, check for authenticity & Google AdSense compliance, and if acceptable, rewrite and expand it into a comprehensive, authoritative Bengali news article.

SOURCE MATERIAL:
"""
{$raw_text}
"""

SAFETY & EDITORIAL POLICIES:
1. isGenuineNews: Set to false if this is an advertisement, betting promotion, clickbait spam, trading/crypto signal, or personal promotion.
2. isAdSenseSafe: Set to false if it contains graphic violence, blood, adult content, vulgarity, hate speech, illegal drug trade, or defamatory unverified rumors about named individuals.
3. If either is false, provide a clear, concise rejectReason in Bengali or English.

ARTICLE WRITING RULES (if approved):
- LANGUAGE: Fluent, grammatically immaculate standard Bengali (প্রমিত বাংলা).
- LENGTH: STRICTLY 300+ words in Bengali (strip HTML tags). Multi-paragraph format.
- STRUCTURE: Use <p> tags for paragraphs. Include 2 to 4 <h2> subheadings to divide key angles. Do not use markdown backticks in contentHtml; only valid HTML <p> and <h2>, <h3>, <strong>, <em> tags.
- FACTUAL ACCURACY: Expand the context, background, and significance based ONLY on facts present in or directly related to the source. DO NOT invent fake quotes, fictional figures, or non-existent eyewitnesses. Maintain an objective, balanced journalistic tone.
- TITLE: Compelling, accurate Bengali news headline (maximum 70 characters).
- ENGLISH SLUG: SEO-friendly lowercase hyphenated URL slug in English (maximum 60 characters, regex ^[a-z0-9-]+$, e.g. "govt-declares-emergency-relief-fund").
- META DESCRIPTION: Concise, search-engine-ready Bengali summary (maximum 155 characters).
- CATEGORY: Choose the best matching category from this list: [{$cat_list}].
- TAGS: Array of 3 to 6 relevant Bengali keyword tags.
- IMAGE ALT TEXT: Descriptive Bengali alt text for the featured news image.
PROMPT;
	}

	/**
	 * Build corrective retry prompt if previous attempt was too short.
	 *
	 * @param string $raw_text
	 * @param array  $categories
	 * @return string
	 */
	public static function build_corrective_prompt( string $raw_text, array $categories ): string {
		$base = self::build_prompt( $raw_text, $categories );
		return $base . "\n\nCRITICAL RETRY INSTRUCTION: The previous attempt was under the 300-word minimum requirement. You MUST expand further with relevant journalistic context, background, public impact, and official responses to comfortably exceed 350 words in contentHtml.";
	}

	/**
	 * Definition of strict JSON output schema.
	 *
	 * @return array
	 */
	public static function get_response_schema(): array {
		return [
			'type'       => 'OBJECT',
			'properties' => [
				'isGenuineNews'   => [ 'type' => 'BOOLEAN' ],
				'isAdSenseSafe'   => [ 'type' => 'BOOLEAN' ],
				'rejectReason'    => [ 'type' => 'STRING' ],
				'title'           => [ 'type' => 'STRING' ],
				'contentHtml'     => [ 'type' => 'STRING' ],
				'englishSlug'     => [ 'type' => 'STRING' ],
				'metaDescription' => [ 'type' => 'STRING' ],
				'category'        => [ 'type' => 'STRING' ],
				'tags'            => [
					'type'  => 'ARRAY',
					'items' => [ 'type' => 'STRING' ],
				],
				'imageAltText'    => [ 'type' => 'STRING' ],
			],
			'required'   => [
				'isGenuineNews',
				'isAdSenseSafe',
			],
		];
	}

	/**
	 * Execute API call with exponential backoff on 429 and 5xx errors.
	 *
	 * @param string $api_key
	 * @param array  $payload
	 * @param int    $max_retries
	 * @return array
	 */
	public static function call_api( string $api_key, array $payload, int $max_retries = 3 ): array {
		$url = self::ENDPOINT . self::MODEL . ':generateContent?key=' . rawurlencode( $api_key );

		$attempt = 0;
		$backoff_ms = 1000;

		while ( $attempt < $max_retries ) {
			$attempt++;

			$response = wp_remote_post(
				$url,
				[
					'timeout' => 30,
					'headers' => [ 'Content-Type' => 'application/json' ],
					'body'    => wp_json_encode( $payload ),
				]
			);

			if ( is_wp_error( $response ) ) {
				if ( $attempt >= $max_retries ) {
					return [
						'success' => false,
						'error'   => 'Gemini API network error: ' . $response->get_error_message(),
					];
				}
				usleep( $backoff_ms * 1000 );
				$backoff_ms *= 2;
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			// Rate limit or server error: backoff and retry
			if ( ( 429 === $code || $code >= 500 ) && $attempt < $max_retries ) {
				usleep( $backoff_ms * 1000 );
				$backoff_ms *= 2;
				continue;
			}

			if ( 200 !== $code ) {
				$json = json_decode( $body, true );
				$msg  = $json['error']['message'] ?? ( 'HTTP ' . $code . ' response from Gemini API.' );
				return [
					'success' => false,
					'error'   => $msg,
				];
			}

			$json = json_decode( $body, true );
			$raw_content = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';

			if ( empty( $raw_content ) ) {
				return [
					'success' => false,
					'error'   => 'Gemini returned empty candidate content.',
				];
			}

			$parsed = json_decode( $raw_content, true );
			if ( ! is_array( $parsed ) ) {
				// Fallback strip markdown code fences if model enclosed in ```json
				$clean = preg_replace( '/^```json\s*|\s*```$/i', '', trim( $raw_content ) );
				$parsed = json_decode( $clean, true );
			}

			if ( ! is_array( $parsed ) ) {
				return [
					'success' => false,
					'error'   => 'Failed to parse Gemini response as JSON: ' . json_last_error_msg(),
				];
			}

			return [
				'success' => true,
				'data'    => $parsed,
			];
		}

		return [
			'success' => false,
			'error'   => 'Maximum API retry attempts exceeded.',
		];
	}

	/**
	 * Validate and sanitize generated output data according to PRD criteria.
	 *
	 * @param array  $data
	 * @param string $source_text
	 * @param string $fallback_cat
	 * @return array ['valid' => bool, 'article' => array, 'errors' => array]
	 */
	public static function validate_and_sanitize( array $data, string $source_text, string $fallback_cat = 'জাতীয়' ): array {
		$errors = [];

		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		if ( empty( $title ) ) {
			$errors[] = 'Title is empty';
		} elseif ( mb_strlen( $title ) > 100 ) {
			$title = mb_substr( $title, 0, 70 );
		}

		// Content HTML sanitization with wp_kses
		$raw_html     = (string) ( $data['contentHtml'] ?? '' );
		$content_html = wp_kses( $raw_html, self::ALLOWED_HTML_TAGS );

		// Word count validation (strip HTML tags, count Unicode words)
		$plain_text = wp_strip_all_tags( $content_html );
		$words      = preg_split( '/[\s\p{Z}]+/u', trim( $plain_text ), -1, PREG_SPLIT_NO_EMPTY );
		$word_count = is_array( $words ) ? count( $words ) : 0;

		if ( $word_count < self::MIN_WORD_COUNT ) {
			$errors[] = sprintf( 'Article content has only %d words (minimum %d required)', $word_count, self::MIN_WORD_COUNT );
		}

		// Check for at least 2 H2 headings
		preg_match_all( '/<h2\b[^>]*>/i', $content_html, $h2_matches );
		if ( count( $h2_matches[0] ) < 2 ) {
			// If missing H2 headings, inject reasonable section splits or flag
			$errors[] = 'Content does not contain at least 2 <h2> subheadings';
		}

		// English slug validation & normalization
		$raw_slug = strtolower( trim( (string) ( $data['englishSlug'] ?? '' ) ) );
		$slug     = preg_replace( '/[^a-z0-9-]+/', '-', $raw_slug );
		$slug     = trim( $slug, '-' );
		if ( mb_strlen( $slug ) > 60 ) {
			$slug = mb_substr( $slug, 0, 60 );
			$slug = rtrim( $slug, '-' );
		}
		if ( empty( $slug ) ) {
			$slug = 'news-update-' . time();
		}

		// Meta description validation
		$meta = sanitize_text_field( (string) ( $data['metaDescription'] ?? '' ) );
		if ( mb_strlen( $meta ) > 160 ) {
			$meta = mb_substr( $meta, 0, 155 );
		}
		if ( empty( $meta ) ) {
			$meta = mb_substr( $plain_text, 0, 155 );
		}

		// Category
		$category = sanitize_text_field( (string) ( $data['category'] ?? '' ) );
		if ( empty( $category ) ) {
			$category = $fallback_cat;
		}

		// Tags
		$raw_tags = (array) ( $data['tags'] ?? [] );
		$clean_tags = [];
		foreach ( $raw_tags as $t ) {
			$t = sanitize_text_field( (string) $t );
			if ( ! empty( $t ) ) {
				$clean_tags[] = $t;
			}
		}

		// Image Alt Text
		$alt = sanitize_text_field( (string) ( $data['imageAltText'] ?? '' ) );
		if ( empty( $alt ) ) {
			$alt = $title;
		}

		$valid = empty( $errors );

		return [
			'valid'   => $valid,
			'errors'  => $errors,
			'article' => [
				'title'           => $title,
				'contentHtml'     => $content_html,
				'englishSlug'     => $slug,
				'metaDescription' => $meta,
				'category'        => $category,
				'tags'            => $clean_tags,
				'imageAltText'    => $alt,
				'wordCount'       => $word_count,
			],
		];
	}
}
