<?php
/**
 * WordPress Post Publisher & Pipeline Coordinator.
 *
 * @package KSTNews_AI_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KSTNews_AI_Publisher {

	public const LOCK_KEY = 'kstnews_ai_run_lock';
	public const LOCK_TTL = 600; // 10 minutes

	/**
	 * Run complete pipeline across configured channels.
	 *
	 * @param string $trigger 'manual' or 'cron'
	 * @return array
	 */
	public static function run_pipeline( string $trigger = 'manual' ): array {
		$start_time = microtime( true );

		// Step 1: Concurrency check using WordPress transient lock
		$lock = get_transient( self::LOCK_KEY );
		if ( $lock ) {
			return [
				'success' => false,
				'message' => sprintf( 'Pipeline is currently running or locked (PID/Time: %s). Please wait.', esc_html( $lock ) ),
				'status'  => 'locked',
			];
		}

		// Acquire lock
		$lock_val = time() . ':' . ( function_exists( 'getmypid' ) ? getmypid() : '1' );
		set_transient( self::LOCK_KEY, $lock_val, self::LOCK_TTL );

		// Load settings and state
		$settings = KSTNews_AI_Settings::get_all();
		$state    = KSTNews_AI_Settings::get_state();

		$daily_cap   = (int) ( $settings['daily_cap'] ?? 20 );
		$daily_count = (int) ( $state['daily_count'] ?? 0 );
		$max_per_run = (int) ( $settings['max_per_run'] ?? 3 );
		$channels    = (array) ( $settings['channels'] ?? [ 'basherkella' ] );

		// Check daily cap
		if ( $daily_count >= $daily_cap ) {
			delete_transient( self::LOCK_KEY );
			return [
				'success' => true,
				'message' => sprintf( 'Daily post cap reached (%d / %d posts published today). Pipeline idle.', $daily_count, $daily_cap ),
				'status'  => 'daily_cap_reached',
			];
		}

		$total_processed = 0;
		$total_published = 0;
		$max_exec_time   = (int) ini_get( 'max_execution_time' );
		$time_budget_sec = ( $max_exec_time > 0 ) ? max( 25, $max_exec_time - 10 ) : 50;

		$last_ids      = (array) ( $state['last_message_id'] ?? [] );
		$processed_ids = (array) ( $state['processed_ids'] ?? [] );

		foreach ( $channels as $channel ) {
			$channel = trim( $channel );
			if ( empty( $channel ) ) {
				continue;
			}

			// Check time budget before starting channel
			if ( ( microtime( true ) - $start_time ) >= $time_budget_sec ) {
				break;
			}

			// Remaining budget in this run
			$remaining_cap = $daily_cap - $daily_count;
			$run_limit     = min( $max_per_run, $remaining_cap );
			if ( $run_limit <= 0 ) {
				break;
			}

			$channel_watermark = (int) ( $last_ids[ $channel ] ?? 0 );

			$scrape_res = KSTNews_AI_Telegram_Scraper::fetch_channel_posts(
				$channel,
				$channel_watermark,
				$processed_ids,
				$run_limit
			);

			if ( ! $scrape_res['success'] ) {
				KSTNews_AI_Logger::log(
					$channel,
					0,
					KSTNews_AI_Logger::STATUS_ERROR,
					$scrape_res['error'] ?? 'Scraper failure'
				);
				continue;
			}

			$posts = $scrape_res['posts'] ?? [];

			foreach ( $posts as $msg ) {
				$msg_id   = (int) $msg['message_id'];
				$post_key = $channel . ':' . $msg_id;

				// Check time budget
				if ( ( microtime( true ) - $start_time ) >= $time_budget_sec ) {
					break 2;
				}

				// Check daily cap
				if ( $daily_count >= $daily_cap ) {
					break 2;
				}

				$total_processed++;

				// Step 2: Layer 1 Pre-Filter (no API cost)
				$prefilter_check = KSTNews_AI_Prefilter::check( $msg, $settings );
				if ( ! $prefilter_check['passed'] ) {
					KSTNews_AI_Logger::log(
						$channel,
						$msg_id,
						KSTNews_AI_Logger::STATUS_BLOCKED_PREFILTER,
						$prefilter_check['reason']
					);

					// Advance watermark
					$last_ids[ $channel ] = max( $last_ids[ $channel ] ?? 0, $msg_id );
					$processed_ids[]      = $post_key;
					KSTNews_AI_Settings::update_state( [
						'last_message_id' => $last_ids,
						'processed_ids'   => $processed_ids,
					] );
					continue;
				}

				// Step 3: Layer 2 Gemini Generation & Safety Gate
				$gen_res = KSTNews_AI_Gemini_Client::generate_article( $msg, $settings );

				if ( ! empty( $gen_res['blocked_by_ai'] ) ) {
					KSTNews_AI_Logger::log(
						$channel,
						$msg_id,
						KSTNews_AI_Logger::STATUS_BLOCKED_AI,
						$gen_res['rejectReason'] ?? 'Blocked by Gemini AI safety gate.'
					);

					$last_ids[ $channel ] = max( $last_ids[ $channel ] ?? 0, $msg_id );
					$processed_ids[]      = $post_key;
					KSTNews_AI_Settings::update_state( [
						'last_message_id' => $last_ids,
						'processed_ids'   => $processed_ids,
					] );
					continue;
				}

				if ( ! $gen_res['success'] ) {
					KSTNews_AI_Logger::log(
						$channel,
						$msg_id,
						KSTNews_AI_Logger::STATUS_ERROR,
						$gen_res['error'] ?? 'Gemini generation failed'
					);
					continue;
				}

				// Step 4: WordPress Publishing
				$article = $gen_res['article'];
				$publish_res = self::publish_article( $msg, $article, $settings );

				if ( ! empty( $publish_res['skipped'] ) ) {
					KSTNews_AI_Logger::log(
						$channel,
						$msg_id,
						KSTNews_AI_Logger::STATUS_SKIPPED_DUPLICATE,
						$publish_res['reason'] ?? 'Duplicate post exists'
					);

					$last_ids[ $channel ] = max( $last_ids[ $channel ] ?? 0, $msg_id );
					$processed_ids[]      = $post_key;
					KSTNews_AI_Settings::update_state( [
						'last_message_id' => $last_ids,
						'processed_ids'   => $processed_ids,
					] );
					continue;
				}

				if ( ! empty( $publish_res['success'] ) ) {
					$post_id     = (int) $publish_res['post_id'];
					$post_status = $publish_res['status'] ?? 'draft';
					$log_status  = ( 'publish' === $post_status )
						? KSTNews_AI_Logger::STATUS_PUBLISHED
						: KSTNews_AI_Logger::STATUS_DRAFT;

					KSTNews_AI_Logger::log(
						$channel,
						$msg_id,
						$log_status,
						sprintf( 'Created %s article: "%s"', $post_status, $article['title'] ),
						$post_id
					);

					$total_published++;
					$daily_count++;

					// Advance watermark
					$last_ids[ $channel ] = max( $last_ids[ $channel ] ?? 0, $msg_id );
					$processed_ids[]      = $post_key;

					KSTNews_AI_Settings::update_state( [
						'last_message_id' => $last_ids,
						'processed_ids'   => $processed_ids,
						'daily_count'     => $daily_count,
						'last_run_time'   => time(),
						'last_run_status' => 'completed',
					] );
				} else {
					KSTNews_AI_Logger::log(
						$channel,
						$msg_id,
						KSTNews_AI_Logger::STATUS_ERROR,
						$publish_res['error'] ?? 'Post insertion failed'
					);
				}
			}
		}

		// Step 5: Release lock and update final state
		delete_transient( self::LOCK_KEY );

		KSTNews_AI_Settings::update_state( [
			'last_run_time'   => time(),
			'last_run_status' => 'idle',
		] );

		return [
			'success'   => true,
			'message'   => sprintf( 'Pipeline completed. Processed %d posts; created %d articles.', $total_processed, $total_published ),
			'processed' => $total_processed,
			'published' => $total_published,
		];
	}

	/**
	 * Insert and publish a single news article to WordPress.
	 *
	 * @param array $message  Scraped message data.
	 * @param array $article  Validated Gemini output article.
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	public static function publish_article( array $message, array $article, array $settings ): array {
		$channel    = $message['channel'];
		$message_id = (int) $message['message_id'];

		// Duplicate guard: check existing post meta
		$existing_id = self::get_post_by_telegram_id( $channel, $message_id );
		if ( $existing_id > 0 ) {
			return [
				'success' => false,
				'skipped' => true,
				'reason'  => sprintf( 'Post already exists with ID #%d', $existing_id ),
			];
		}

		// Resolve category
		$category_name = ! empty( $article['category'] ) ? $article['category'] : ( $settings['default_category'] ?? 'জাতীয়' );
		$cat_id        = self::get_or_create_category( $category_name );

		// Assemble content
		$content = $article['contentHtml'];

		// Optional video embed if setting enabled
		if ( ! empty( $settings['embed_video'] ) && ! empty( $message['videos'] ) ) {
			$video_src = esc_url( $message['videos'][0] );
			$content  .= "\n<div class=\"kstnews-video-wrap\" style=\"margin: 20px 0;\"><video controls style=\"max-width: 100%; height: auto;\" src=\"{$video_src}\"></video></div>\n";
		}

		// Source attribution line
		$source_label = ! empty( $settings['source_label'] ) ? $settings['source_label'] : 'সূত্র: Telegram';
		$content     .= "\n<p class=\"kstnews-source-attribution\" style=\"margin-top: 20px; font-style: italic; color: #64748b;\">" . esc_html( $source_label ) . "</p>\n";

		// Post status: draft or publish
		$post_status = $settings['post_status'] ?? 'draft';

		// Insert post
		$post_data = [
			'post_title'    => $article['title'],
			'post_content'  => $content,
			'post_status'   => $post_status,
			'post_type'     => 'post',
			'post_name'     => $article['englishSlug'],
			'post_excerpt'  => $article['metaDescription'],
			'post_category' => [ $cat_id ],
		];

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return [
				'success' => false,
				'error'   => $post_id->get_error_message(),
			];
		}

		// Set tags
		if ( ! empty( $article['tags'] ) ) {
			wp_set_post_tags( $post_id, (array) $article['tags'] );
		}

		// Set post meta tracking
		update_post_meta( $post_id, '_kstnews_source_channel', $channel );
		update_post_meta( $post_id, '_kstnews_source_msg_id', $message_id );
		update_post_meta( $post_id, '_kstnews_source_url', $message['source_url'] ?? ( 'https://t.me/' . $channel . '/' . $message_id ) );
		update_post_meta( $post_id, '_kstnews_meta_description', $article['metaDescription'] );

		// SEO plugins compatibility
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $article['metaDescription'] );
		update_post_meta( $post_id, 'rank_math_description', $article['metaDescription'] );
		update_post_meta( $post_id, '_aioseo_description', $article['metaDescription'] );

		// Process and attach featured image
		if ( ! empty( $message['primary_image'] ) ) {
			KSTNews_AI_Image_Processor::process_and_attach(
				$message['primary_image'],
				$post_id,
				$article['title'],
				$article['imageAltText'] ?? $article['title']
			);
		}

		// Inject related news internal links
		$related_block = KSTNews_AI_Internal_Linker::generate_block( $cat_id, $post_id );
		if ( ! empty( $related_block ) ) {
			$final_content = $content . "\n" . $related_block;
			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => $final_content,
			] );
		}

		return [
			'success' => true,
			'post_id' => $post_id,
			'status'  => $post_status,
		];
	}

	/**
	 * Find existing post ID by Telegram source channel and message ID.
	 *
	 * @param string $channel
	 * @param int    $message_id
	 * @return int 0 if not found, post ID if exists.
	 */
	public static function get_post_by_telegram_id( string $channel, int $message_id ): int {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_kstnews_source_channel'
			INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_kstnews_source_msg_id'
			WHERE pm1.meta_value = %s AND pm2.meta_value = %s
			LIMIT 1",
			$channel,
			(string) $message_id
		);

		$post_id = $wpdb->get_var( $sql );
		return $post_id ? (int) $post_id : 0;
	}

	/**
	 * Get category ID by name, creating it if it doesn't exist.
	 *
	 * @param string $name
	 * @return int
	 */
	public static function get_or_create_category( string $name ): int {
		$name = trim( $name );
		if ( empty( $name ) ) {
			$name = 'জাতীয়';
		}

		$term = get_term_by( 'name', $name, 'category' );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}

		$created = wp_insert_term( $name, 'category' );
		if ( is_array( $created ) && isset( $created['term_id'] ) ) {
			return (int) $created['term_id'];
		}

		return 1; // Fallback to default uncategorized
	}
}
