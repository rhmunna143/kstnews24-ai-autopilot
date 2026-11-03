<?php
/**
 * Dual-Layer Pre-Filter (Layer 1 - Zero API Cost).
 *
 * @package KSTNews_AI_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KSTNews_AI_Prefilter {

	public const MIN_TEXT_LENGTH = 40;

	/**
	 * Run Layer 1 Pre-filter checks on a scraped Telegram message.
	 *
	 * @param array $message  Scraped message data.
	 * @param array $settings Plugin settings.
	 * @return array ['passed' => bool, 'reason' => string]
	 */
	public static function check( array $message, array $settings = [] ): array {
		$raw_text = trim( (string) ( $message['raw_text'] ?? '' ) );
		$has_buttons = ! empty( $message['has_buttons'] );
		$has_media   = ! empty( $message['primary_image'] ) || ! empty( $message['photos'] ) || ! empty( $message['videos'] );

		// 1. Reject messages with inline buttons typical of ads
		if ( $has_buttons ) {
			return [
				'passed' => false,
				'reason' => 'Blocked: Contains inline CTA buttons typical of ads/promos',
			];
		}

		// 2. Reject short text without media
		$char_count = mb_strlen( $raw_text );
		if ( $char_count < self::MIN_TEXT_LENGTH && ! $has_media ) {
			return [
				'passed' => false,
				'reason' => sprintf( 'Blocked: Short text (%d chars, minimum %d) with no media', $char_count, self::MIN_TEXT_LENGTH ),
			];
		}

		// 3. Reject on blocked keywords
		$blocked_keywords = $settings['blocked_keywords'] ?? KSTNews_AI_Settings::get( 'blocked_keywords', [] );
		$matched_keyword = self::find_blocked_keyword( $raw_text, (array) $blocked_keywords );
		if ( null !== $matched_keyword ) {
			return [
				'passed' => false,
				'reason' => sprintf( "Blocked: Matched blocked keyword '%s'", esc_html( $matched_keyword ) ),
			];
		}

		// 4. Reject if text is mostly links or hashtags (> 50%)
		if ( self::is_mostly_links_or_hashtags( $raw_text ) ) {
			return [
				'passed' => false,
				'reason' => 'Blocked: Text contains excessive links or hashtags (>50% content)',
			];
		}

		return [
			'passed' => true,
			'reason' => '',
		];
	}

	/**
	 * Search for any blocked keyword inside text (case-insensitive, unicode safe).
	 *
	 * @param string $text
	 * @param array  $keywords
	 * @return string|null The matched keyword or null.
	 */
	public static function find_blocked_keyword( string $text, array $keywords ): ?string {
		if ( empty( $text ) || empty( $keywords ) ) {
			return null;
		}

		$normalized_text = mb_strtolower( $text, 'UTF-8' );

		foreach ( $keywords as $kw ) {
			$kw = trim( (string) $kw );
			if ( empty( $kw ) ) {
				continue;
			}
			$normalized_kw = mb_strtolower( $kw, 'UTF-8' );

			if ( str_contains( $normalized_text, $normalized_kw ) ) {
				return $kw;
			}
		}

		return null;
	}

	/**
	 * Check if text consists predominantly (> 50%) of URLs, @ mentions, and #hashtags.
	 *
	 * @param string $text
	 * @return bool
	 */
	public static function is_mostly_links_or_hashtags( string $text ): bool {
		$clean = trim( $text );
		if ( empty( $clean ) ) {
			return false;
		}

		$total_len = mb_strlen( $clean, 'UTF-8' );
		if ( $total_len < 20 ) {
			return false;
		}

		// Extract all URLs, @ handles, and #hashtags
		$junk_chars = 0;

		// URLs: http:// or https:// or t.me
		preg_match_all( '#https?://[^\s]+|t\.me/[^\s]+#i', $clean, $url_matches );
		foreach ( $url_matches[0] as $u ) {
			$junk_chars += mb_strlen( $u, 'UTF-8' );
		}

		// Hashtags
		preg_match_all( '/#[^\s#]+/u', $clean, $hash_matches );
		foreach ( $hash_matches[0] as $h ) {
			$junk_chars += mb_strlen( $h, 'UTF-8' );
		}

		// Mentions @username
		preg_match_all( '/@[a-zA-Z0-9_]+/u', $clean, $mention_matches );
		foreach ( $mention_matches[0] as $m ) {
			$junk_chars += mb_strlen( $m, 'UTF-8' );
		}

		// Calculate ratio
		$ratio = $junk_chars / $total_len;
		return $ratio >= 0.50;
	}
}
