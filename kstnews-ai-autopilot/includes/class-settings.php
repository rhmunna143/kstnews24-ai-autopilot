<?php
/**
 * Settings and State Management.
 *
 * @package KSTNews_AI_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KSTNews_AI_Settings {

	public const OPTION_KEY = 'kstnews_ai_settings';
	public const STATE_KEY  = 'kstnews_ai_state';

	/**
	 * Default settings values.
	 *
	 * @return array
	 */
	public static function get_defaults(): array {
		return [
			'gemini_key'       => '',
			'channels'         => [ 'basherkella' ],
			'post_status'      => 'draft', // Default safe mode per PRD
			'auto_cron'        => 0,
			'interval'         => 'thirty_minutes', // 15, 30, 60
			'max_per_run'      => 3,
			'daily_cap'        => 20,
			'default_category' => 'জাতীয়',
			'blocked_keywords' => [
				'বিজ্ঞাপন',
				'স্পন্সর',
				'প্রমোশন',
				'অফার',
				'কুপন',
				'ডিসকাউন্ট',
				'রেফারেল',
				'জুয়া',
				'ক্যাসিনো',
				'বাজি',
				'বেটিং',
				'ট্রেডিং চ্যানেল',
				'ইনভেস্টমেন্ট',
				'ক্রিপ্টো সিগন্যাল',
				'ফ্রি আর্নিং',
				'ইনকাম করুন',
				'ডলার ইনকাম',
				'promo',
				'sponsored',
				'discount',
				'offer',
				'referral',
				'bonus',
				'casino',
				'betting',
				'1xbet',
				'melbet',
				'parimatch',
				'trade with us',
				'crypto signal',
				'giveaway',
				'free gift',
				'join channel',
				'subscribe now',
			],
			'source_label'     => 'সূত্র: Telegram',
			'embed_video'      => 0,
		];
	}

	/**
	 * Default state values.
	 *
	 * @return array
	 */
	public static function get_default_state(): array {
		return [
			'last_message_id'  => [],
			'processed_ids'    => [],
			'daily_count'      => 0,
			'daily_count_date' => gmdate( 'Y-m-d' ),
			'last_run_time'    => 0,
			'last_run_status'  => 'idle',
		];
	}

	/**
	 * Get all settings with defaults merged.
	 *
	 * @return array
	 */
	public static function get_all(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		return array_merge( self::get_defaults(), $saved );
	}

	/**
	 * Get a single setting by key.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::get_all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Update all settings.
	 *
	 * @param array $input Raw or sanitized settings.
	 * @return bool
	 */
	public static function update_all( array $input ): bool {
		$sanitized = self::sanitize_settings( $input );
		return update_option( self::OPTION_KEY, $sanitized );
	}

	/**
	 * Get current runtime state.
	 *
	 * @return array
	 */
	public static function get_state(): array {
		$state = get_option( self::STATE_KEY, [] );
		if ( ! is_array( $state ) ) {
			$state = [];
		}
		$merged = array_merge( self::get_default_state(), $state );

		// Reset daily count if date has changed
		$today = gmdate( 'Y-m-d' );
		if ( ( $merged['daily_count_date'] ?? '' ) !== $today ) {
			$merged['daily_count']      = 0;
			$merged['daily_count_date'] = $today;
			update_option( self::STATE_KEY, $merged );
		}

		return $merged;
	}

	/**
	 * Update runtime state.
	 *
	 * @param array $state
	 * @return bool
	 */
	public static function update_state( array $state ): bool {
		$current = self::get_state();
		$updated = array_merge( $current, $state );

		// Ensure processed_ids is kept to at most 500 entries (rolling list)
		if ( isset( $updated['processed_ids'] ) && is_array( $updated['processed_ids'] ) ) {
			if ( count( $updated['processed_ids'] ) > 500 ) {
				$updated['processed_ids'] = array_slice( $updated['processed_ids'], -500 );
			}
		}

		return update_option( self::STATE_KEY, $updated );
	}

	/**
	 * Reset state (clears message IDs and processed list, does not touch posts).
	 *
	 * @return bool
	 */
	public static function reset_state(): bool {
		return update_option( self::STATE_KEY, self::get_default_state() );
	}

	/**
	 * Sanitize raw settings input from admin forms.
	 *
	 * @param array $input
	 * @return array
	 */
	public static function sanitize_settings( array $input ): array {
		$current  = self::get_all();
		$defaults = self::get_defaults();
		$output   = [];

		// Gemini Key: if placeholder or blank submitted when already set, retain existing key
		if ( isset( $input['gemini_key'] ) ) {
			$key = trim( (string) $input['gemini_key'] );
			if ( empty( $key ) || str_starts_with( $key, '••••' ) || str_contains( $key, 'configured' ) ) {
				$output['gemini_key'] = $current['gemini_key'] ?? '';
			} else {
				$output['gemini_key'] = sanitize_text_field( $key );
			}
		} else {
			$output['gemini_key'] = $current['gemini_key'] ?? '';
		}

		// Channels: parse comma or newline separated usernames
		if ( isset( $input['channels'] ) ) {
			if ( is_array( $input['channels'] ) ) {
				$raw_channels = $input['channels'];
			} else {
				$raw_channels = preg_split( '/[\r\n,]+/', (string) $input['channels'] );
			}
			$clean_channels = [];
			foreach ( $raw_channels as $ch ) {
				$ch = trim( (string) $ch );
				$ch = ltrim( $ch, '@' );
				$ch = preg_replace( '/^https?:\/\/(?:www\.)?t\.me\/(?:s\/)?/i', '', $ch );
				$ch = trim( $ch, '/' );
				$ch = sanitize_text_field( $ch );
				if ( ! empty( $ch ) && preg_match( '/^[A-Za-z0-9_]{4,}$/', $ch ) ) {
					$clean_channels[] = $ch;
				}
			}
			$output['channels'] = ! empty( $clean_channels ) ? array_values( array_unique( $clean_channels ) ) : $defaults['channels'];
		} else {
			$output['channels'] = $current['channels'] ?? $defaults['channels'];
		}

		// Post status: 'draft' or 'publish'
		if ( isset( $input['post_status'] ) && in_array( $input['post_status'], [ 'publish', 'draft' ], true ) ) {
			$output['post_status'] = $input['post_status'];
		} else {
			$output['post_status'] = $current['post_status'] ?? 'draft';
		}

		// Auto cron: 0 or 1
		$output['auto_cron'] = ! empty( $input['auto_cron'] ) ? 1 : 0;

		// Interval: fifteen_minutes, thirty_minutes, hourly
		$valid_intervals = [ 'fifteen_minutes', 'thirty_minutes', 'hourly' ];
		if ( isset( $input['interval'] ) && in_array( $input['interval'], $valid_intervals, true ) ) {
			$output['interval'] = $input['interval'];
		} else {
			$output['interval'] = $current['interval'] ?? 'thirty_minutes';
		}

		// Max per run (between 1 and 10)
		if ( isset( $input['max_per_run'] ) ) {
			$max = (int) $input['max_per_run'];
			$output['max_per_run'] = max( 1, min( 10, $max ) );
		} else {
			$output['max_per_run'] = $current['max_per_run'] ?? 3;
		}

		// Daily cap (between 1 and 100)
		if ( isset( $input['daily_cap'] ) ) {
			$cap = (int) $input['daily_cap'];
			$output['daily_cap'] = max( 1, min( 100, $cap ) );
		} else {
			$output['daily_cap'] = $current['daily_cap'] ?? 20;
		}

		// Default category
		if ( isset( $input['default_category'] ) ) {
			$output['default_category'] = sanitize_text_field( (string) $input['default_category'] );
		} else {
			$output['default_category'] = $current['default_category'] ?? $defaults['default_category'];
		}

		// Blocked keywords: list of phrases/words
		if ( isset( $input['blocked_keywords'] ) ) {
			if ( is_array( $input['blocked_keywords'] ) ) {
				$raw_kws = $input['blocked_keywords'];
			} else {
				$raw_kws = preg_split( '/[\r\n,]+/', (string) $input['blocked_keywords'] );
			}
			$clean_kws = [];
			foreach ( $raw_kws as $kw ) {
				$kw = trim( sanitize_text_field( (string) $kw ) );
				if ( ! empty( $kw ) ) {
					$clean_kws[] = $kw;
				}
			}
			$output['blocked_keywords'] = array_values( array_unique( $clean_kws ) );
		} else {
			$output['blocked_keywords'] = $current['blocked_keywords'] ?? $defaults['blocked_keywords'];
		}

		// Source label
		if ( isset( $input['source_label'] ) ) {
			$output['source_label'] = sanitize_text_field( (string) $input['source_label'] );
		} else {
			$output['source_label'] = $current['source_label'] ?? $defaults['source_label'];
		}

		// Embed video: 0 or 1
		$output['embed_video'] = ! empty( $input['embed_video'] ) ? 1 : 0;

		return $output;
	}

	/**
	 * Mask API key for UI display.
	 *
	 * @param string $key
	 * @return string
	 */
	public static function mask_api_key( string $key ): string {
		$key = trim( $key );
		if ( empty( $key ) ) {
			return '';
		}
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}
		$tail = substr( $key, -4 );
		return '••••••••••••••••••••' . $tail;
	}
}
