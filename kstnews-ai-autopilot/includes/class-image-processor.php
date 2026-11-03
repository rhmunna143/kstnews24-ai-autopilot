<?php
/**
 * Image Processing Engine (1200x630 Resizer & Background Blur).
 *
 * @package KSTNews_AI_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KSTNews_AI_Image_Processor {

	public const TARGET_WIDTH   = 1200;
	public const TARGET_HEIGHT  = 630;
	public const JPEG_QUALITY   = 82;
	public const MAX_FILE_SIZE  = 8388608; // 8 MB max
	public const MAX_PIXELS     = 30000000; // 30 Megapixels max

	/**
	 * Process and attach featured image to a WordPress post.
	 *
	 * @param string $image_url
	 * @param int    $post_id
	 * @param string $title
	 * @param string $alt_text
	 * @return int|null Attachment ID on success, null on failure.
	 */
	public static function process_and_attach( string $image_url, int $post_id, string $title = '', string $alt_text = '' ): ?int {
		$downloaded_path = self::download_image( $image_url );
		if ( ! $downloaded_path || ! file_exists( $downloaded_path ) ) {
			return null;
		}

		// Transform image (1200x630)
		$processed_path = self::transform_image( $downloaded_path );
		$final_path     = $processed_path ?: $downloaded_path;

		$attachment_id = self::sideload_to_media( $final_path, $post_id, $title, $alt_text );

		// Clean up temp files if different
		if ( $processed_path && $processed_path !== $downloaded_path && file_exists( $processed_path ) ) {
			@unlink( $processed_path );
		}
		if ( file_exists( $downloaded_path ) ) {
			@unlink( $downloaded_path );
		}

		return $attachment_id;
	}

	/**
	 * Download image safely from verified Telegram CDN.
	 *
	 * @param string $image_url
	 * @return string|null Path to downloaded temporary file.
	 */
	public static function download_image( string $image_url ): ?string {
		$image_url = trim( $image_url );
		if ( empty( $image_url ) ) {
			return null;
		}

		// SSRF Guard
		if ( ! KSTNews_AI_Telegram_Scraper::is_allowed_telegram_cdn( $image_url ) ) {
			return null;
		}

		$tmp_file = wp_tempnam( 'kstnews_img_' );
		if ( ! $tmp_file ) {
			return null;
		}

		$response = wp_remote_get(
			$image_url,
			[
				'timeout'    => 20,
				'user-agent' => KSTNews_AI_Telegram_Scraper::USER_AGENT,
				'stream'     => true,
				'filename'   => $tmp_file,
			]
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp_file );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			@unlink( $tmp_file );
			return null;
		}

		$size = @filesize( $tmp_file );
		if ( ! $size || $size > self::MAX_FILE_SIZE ) {
			@unlink( $tmp_file );
			return null;
		}

		return $tmp_file;
	}

	/**
	 * Transform image to exactly 1200x630 using PHP GD.
	 *
	 * @param string $src_path
	 * @return string|null Path to processed JPEG or null on error / fallback.
	 */
	public static function transform_image( string $src_path ): ?string {
		if ( ! extension_loaded( 'gd' ) ) {
			// Gracefully fallback to original image if GD is missing per PRD
			return null;
		}

		$info = @getimagesize( $src_path );
		if ( ! $info ) {
			return null;
		}

		[ $orig_w, $orig_h, $type ] = $info;

		if ( $orig_w <= 0 || $orig_h <= 0 ) {
			return null;
		}

		// Memory safety cap
		if ( ( $orig_w * $orig_h ) > self::MAX_PIXELS ) {
			return null;
		}

		// Load source image resource
		$src = null;
		switch ( $type ) {
			case IMAGETYPE_JPEG:
				$src = @imagecreatefromjpeg( $src_path );
				break;
			case IMAGETYPE_PNG:
				$src = @imagecreatefrompng( $src_path );
				break;
			case IMAGETYPE_WEBP:
				$src = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $src_path ) : null;
				break;
		}

		if ( ! $src ) {
			return null;
		}

		$target_w     = self::TARGET_WIDTH;
		$target_h     = self::TARGET_HEIGHT;
		$target_ratio = $target_w / $target_h; // ~1.90476
		$orig_ratio   = $orig_w / $orig_h;

		// Create 1200x630 truecolor destination canvas
		$dst = imagecreatetruecolor( $target_w, $target_h );
		if ( ! $dst ) {
			imagedestroy( $src );
			return null;
		}

		// Case 1: Landscape or near-ratio (orig_ratio >= 1.3) -> Center crop & resize
		if ( $orig_ratio >= 1.3 ) {
			if ( $orig_ratio > $target_ratio ) {
				// Wider than 1.905 -> crop sides
				$crop_h = $orig_h;
				$crop_w = (int) round( $orig_h * $target_ratio );
				$src_x  = (int) round( ( $orig_w - $crop_w ) / 2 );
				$src_y  = 0;
			} else {
				// Taller than 1.905 but >= 1.3 -> crop top & bottom
				$crop_w = $orig_w;
				$crop_h = (int) round( $orig_w / $target_ratio );
				$src_x  = 0;
				$src_y  = (int) round( ( $orig_h - $crop_h ) / 2 );
			}

			imagecopyresampled( $dst, $src, 0, 0, $src_x, $src_y, $target_w, $target_h, $crop_w, $crop_h );
		} else {
			// Case 2: Portrait or square (orig_ratio < 1.3)
			// Step 2a: Blurred background: Downsample to a small thumbnail, blur, and scale back up
			// for optimal performance and ultra-smooth blur on shared hosting.
			$bg_crop_w = $orig_w;
			$bg_crop_h = (int) round( $orig_w / $target_ratio );
			$bg_src_x  = 0;
			$bg_src_y  = (int) round( ( $orig_h - $bg_crop_h ) / 2 );

			$blur_w = 240;
			$blur_h = 126;
			$blur_canvas = imagecreatetruecolor( $blur_w, $blur_h );
			if ( $blur_canvas ) {
				imagecopyresampled( $blur_canvas, $src, 0, 0, $bg_src_x, $bg_src_y, $blur_w, $blur_h, $bg_crop_w, $bg_crop_h );
				imagefilter( $blur_canvas, IMG_FILTER_GAUSSIAN_BLUR );
				imagefilter( $blur_canvas, IMG_FILTER_GAUSSIAN_BLUR );
				imagefilter( $blur_canvas, IMG_FILTER_COLORIZE, 0, 0, 0, 20 );

				// Resample smooth blur back onto 1200x630 destination
				imagecopyresampled( $dst, $blur_canvas, 0, 0, 0, 0, $target_w, $target_h, $blur_w, $blur_h );
				imagedestroy( $blur_canvas );
			}

			// Step 2b: Scale original to fit target height (630) and place centered
			$new_h = $target_h;
			$new_w = (int) round( $target_h * $orig_ratio );
			$dst_x = (int) round( ( $target_w - $new_w ) / 2 );

			imagecopyresampled( $dst, $src, $dst_x, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h );
		}

		// Optional watermark hook
		if ( has_filter( 'kstnews_apply_watermark' ) ) {
			$dst = apply_filters( 'kstnews_apply_watermark', $dst, $target_w, $target_h );
		}

		// Save output JPEG
		$output_path = wp_tempnam( 'kstnews_processed_' ) . '.jpg';
		$saved       = imagejpeg( $dst, $output_path, self::JPEG_QUALITY );

		imagedestroy( $src );
		imagedestroy( $dst );

		return $saved ? $output_path : null;
	}

	/**
	 * Sideload image file into WordPress media library and set as featured image.
	 *
	 * @param string $file_path
	 * @param int    $post_id
	 * @param string $title
	 * @param string $alt_text
	 * @return int|null
	 */
	public static function sideload_to_media( string $file_path, int $post_id, string $title = '', string $alt_text = '' ): ?int {
		if ( ! file_exists( $file_path ) ) {
			return null;
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$filename = sanitize_file_name( ( $title ? sanitize_title( $title ) : 'news-image' ) . '-' . time() . '.jpg' );

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $file_path,
		];

		$attachment_id = media_handle_sideload( $file_array, $post_id, $title );

		if ( is_wp_error( $attachment_id ) ) {
			return null;
		}

		if ( ! empty( $alt_text ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
		}

		if ( $post_id > 0 ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		return (int) $attachment_id;
	}
}
