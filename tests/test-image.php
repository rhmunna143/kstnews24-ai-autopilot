<?php
/**
 * Milestone 5: Image Processor Tests.
 */

require_once __DIR__ . '/bootstrap.php';

function assert_true( bool $condition, string $msg ): void {
	if ( ! $condition ) {
		throw new Exception( "Assertion FAILED: {$msg}" );
	}
	echo "  [PASS] {$msg}\n";
}

function assert_equals( mixed $expected, mixed $actual, string $msg ): void {
	if ( $expected !== $actual ) {
		$exp_str = is_scalar( $expected ) ? (string) $expected : json_encode( $expected );
		$act_str = is_scalar( $actual ) ? (string) $actual : json_encode( $actual );
		throw new Exception( "Assertion FAILED: {$msg}. Expected [{$exp_str}], got [{$act_str}]" );
	}
	echo "  [PASS] {$msg}\n";
}

echo "\n--- Running Milestone 5: Image Processor Tests ---\n\n";

if ( ! extension_loaded( 'gd' ) ) {
	echo "NOTE: GD extension not loaded in default CLI, testing fallback mode...\n";
	$fallback_path = __DIR__ . '/fixtures/test_fallback.jpg';
	file_put_contents( $fallback_path, 'dummy image content' );
	$res = KSTNews_AI_Image_Processor::transform_image( $fallback_path );
	assert_equals( null, $res, 'GD missing returns null fallback gracefully' );
	@unlink( $fallback_path );
	echo "To run full GD transformations, run with: php -d extension=gd tests/test-image.php\n";
	exit( 0 );
}

echo "1. Creating test images with GD:\n";
$temp_dir = __DIR__ . '/temp';
if ( ! is_dir( $temp_dir ) ) {
	mkdir( $temp_dir, 0777, true );
}

// 1. Create Landscape Image (800x450 - 16:9)
$landscape_file = $temp_dir . '/test_landscape.jpg';
$im_land = imagecreatetruecolor( 800, 450 );
$bg_blue = imagecolorallocate( $im_land, 30, 100, 200 );
imagefill( $im_land, 0, 0, $bg_blue );
imagejpeg( $im_land, $landscape_file, 90 );
imagedestroy( $im_land );
assert_true( file_exists( $landscape_file ), 'Landscape test image created' );

// 2. Create Portrait Image (600x900 - 2:3)
$portrait_file = $temp_dir . '/test_portrait.jpg';
$im_port = imagecreatetruecolor( 600, 900 );
$bg_green = imagecolorallocate( $im_port, 34, 139, 34 );
imagefill( $im_port, 0, 0, $bg_green );
imagejpeg( $im_port, $portrait_file, 90 );
imagedestroy( $im_port );
assert_true( file_exists( $portrait_file ), 'Portrait test image created' );

// 3. Create Square Image (700x700 - 1:1)
$square_file = $temp_dir . '/test_square.jpg';
$im_sq = imagecreatetruecolor( 700, 700 );
$bg_orange = imagecolorallocate( $im_sq, 230, 100, 30 );
imagefill( $im_sq, 0, 0, $bg_orange );
imagejpeg( $im_sq, $square_file, 90 );
imagedestroy( $im_sq );
assert_true( file_exists( $square_file ), 'Square test image created' );

// Test Landscape Transformation
echo "\n2. Testing Landscape 1200x630 Transformation:\n";
$processed_land = KSTNews_AI_Image_Processor::transform_image( $landscape_file );
assert_true( null !== $processed_land && file_exists( $processed_land ), 'Landscape transformation succeeded' );
$size_land = getimagesize( $processed_land );
assert_equals( 1200, $size_land[0], 'Landscape width is exactly 1200px' );
assert_equals( 630, $size_land[1], 'Landscape height is exactly 630px' );
@unlink( $processed_land );

// Test Portrait Transformation
echo "\n3. Testing Portrait 1200x630 Background Blur & Padding Transformation:\n";
$processed_port = KSTNews_AI_Image_Processor::transform_image( $portrait_file );
assert_true( null !== $processed_port && file_exists( $processed_port ), 'Portrait transformation succeeded' );
$size_port = getimagesize( $processed_port );
assert_equals( 1200, $size_port[0], 'Portrait canvas width is exactly 1200px' );
assert_equals( 630, $size_port[1], 'Portrait canvas height is exactly 630px' );
@unlink( $processed_port );

// Test Square Transformation
echo "\n4. Testing Square 1200x630 Transformation:\n";
$processed_sq = KSTNews_AI_Image_Processor::transform_image( $square_file );
assert_true( null !== $processed_sq && file_exists( $processed_sq ), 'Square transformation succeeded' );
$size_sq = getimagesize( $processed_sq );
assert_equals( 1200, $size_sq[0], 'Square canvas width is exactly 1200px' );
assert_equals( 630, $size_sq[1], 'Square canvas height is exactly 630px' );
@unlink( $processed_sq );

// Test Sideload & Featured Image Attachment
echo "\n5. Testing WordPress Media Library sideload and attachment:\n";
$post_id = 55;
$attach_id = KSTNews_AI_Image_Processor::sideload_to_media(
	$landscape_file,
	$post_id,
	'জাতীয় সংসদ নির্বাচন প্রস্তুতি',
	'নির্বাচনী নিরাপত্তা পরিস্থিতি পর্যবেক্ষণ'
);
assert_true( $attach_id > 0, "Attachment created with ID {$attach_id}" );
$meta = get_post_meta( $post_id, '_thumbnail_id', true );
assert_equals( $attach_id, $meta, 'Post _thumbnail_id set to attachment ID' );
$alt_meta = get_post_meta( $attach_id, '_wp_attachment_image_alt', true );
assert_equals( 'নির্বাচনী নিরাপত্তা পরিস্থিতি পর্যবেক্ষণ', $alt_meta, 'Attachment alt text saved correctly' );

// Clean up
@unlink( $landscape_file );
@unlink( $portrait_file );
@unlink( $square_file );
@rmdir( $temp_dir );

echo "\n Milestone 5 Image Processor tests passed successfully!\n";
