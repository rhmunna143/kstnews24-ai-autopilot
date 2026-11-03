<?php
/**
 * Milestone 2: Telegram Web Scraper Unit Tests.
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

echo "\n--- Running Milestone 2: Telegram Scraper Tests ---\n\n";

// Test 1: Real Live Fixture Parsing
echo "1. Parsing real live Telegram fixture (live_basherkella.html):\n";
$fixture_path = __DIR__ . '/fixtures/live_basherkella.html';
assert_true( file_exists( $fixture_path ), 'Fixture file exists' );

$html = file_get_contents( $fixture_path );
$posts = KSTNews_AI_Telegram_Scraper::parse_html( $html, 'basherkella' );

assert_true( count( $posts ) > 10, 'Parsed more than 10 messages from live feed' );
echo "  Found " . count( $posts ) . " parsed messages.\n";

$first = $posts[0];
assert_equals( 'basherkella', $first['channel'], 'Channel name set on message' );
assert_true( $first['message_id'] > 80000, 'Valid numeric message ID extracted' );
assert_true( ! empty( $first['raw_text'] ), 'Raw text extracted' );
assert_true( count( $first['photos'] ) >= 1, 'Photos extracted from background-image style' );
assert_true( str_starts_with( $first['primary_image'], 'https://' ), 'Primary image URL extracted' );
assert_true( ! empty( $first['date'] ), 'Date extracted from time element' );

// Test 2: Albums / Grouped Photos Selection
echo "\n2. Testing photo grouping / album selection:\n";
// The first post in live_basherkella has 5 photos
assert_equals( 5, count( $first['photos'] ), 'Album with 5 photos detected' );
assert_equals( $first['photos'][0], $first['primary_image'], 'First photo selected as primary featured image' );

// Test 3: Inline Button Detection
echo "\n3. Testing inline button detection:\n";
$button_html = file_get_contents( __DIR__ . '/fixtures/fixture_buttons.html' );
$button_posts = KSTNews_AI_Telegram_Scraper::parse_html( $button_html, 'testchannel' );
assert_equals( 1, count( $button_posts ), 'Parsed 1 message with buttons' );
assert_true( $button_posts[0]['has_buttons'], 'Inline button detected as true' );

// Normal post should have has_buttons = false
assert_true( ! $first['has_buttons'], 'Normal post has_buttons is false' );

// Test 4: Video MP4 & Video Thumbnail
echo "\n4. Testing video MP4 & video thumb extraction:\n";
$video_html = file_get_contents( __DIR__ . '/fixtures/fixture_video_mp4.html' );
$video_posts = KSTNews_AI_Telegram_Scraper::parse_html( $video_html, 'testchannel' );
assert_equals( 1, count( $video_posts ), 'Parsed 1 video message' );
assert_equals( 'https://cdn5.telesco.pe/file/sample_video.mp4', $video_posts[0]['videos'][0], 'Video MP4 src extracted' );
assert_equals( 'https://cdn5.telesco.pe/file/video_thumb.jpg', $video_posts[0]['video_thumb'], 'Video thumb extracted' );
assert_equals( 'https://cdn5.telesco.pe/file/video_thumb.jpg', $video_posts[0]['primary_image'], 'Video thumb used as primary image when photos empty' );

// Test 5: Empty Channel
echo "\n5. Testing empty channel parsing:\n";
$empty_html = file_get_contents( __DIR__ . '/fixtures/fixture_empty.html' );
$empty_posts = KSTNews_AI_Telegram_Scraper::parse_html( $empty_html, 'empty_channel' );
assert_equals( 0, count( $empty_posts ), 'Empty channel yields 0 messages' );

// Test 6: SSRF Host Verification
echo "\n6. Testing SSRF host validator:\n";
assert_true( KSTNews_AI_Telegram_Scraper::is_allowed_telegram_cdn( 'https://cdn5.telesco.pe/file/123.jpg' ), 'cdn5.telesco.pe is allowed' );
assert_true( KSTNews_AI_Telegram_Scraper::is_allowed_telegram_cdn( 'https://telesco.pe/photo.jpg' ), 'telesco.pe is allowed' );
assert_true( KSTNews_AI_Telegram_Scraper::is_allowed_telegram_cdn( 'https://t.me/photo.jpg' ), 't.me is allowed' );
assert_true( ! KSTNews_AI_Telegram_Scraper::is_allowed_telegram_cdn( 'https://malicious-site.com/photo.jpg' ), 'External host blocked' );
assert_true( ! KSTNews_AI_Telegram_Scraper::is_allowed_telegram_cdn( 'http://169.254.169.254/latest/meta-data/' ), 'AWS metadata IP blocked' );

// Test 7: Watermark & Deduplication & Ordering in fetch_channel_posts
echo "\n7. Testing watermark filtering, sorting, and batch limit:\n";
global $test_http_responses;
$test_http_responses['https://t.me/s/basherkella'] = [
	'response' => [ 'code' => 200 ],
	'body'     => $html,
];

// Let's set watermark to 88285. All messages <= 88285 must be filtered out.
$res = KSTNews_AI_Telegram_Scraper::fetch_channel_posts( 'basherkella', 88285, [], 3 );
assert_true( $res['success'], 'fetch_channel_posts succeeded' );
assert_true( count( $res['posts'] ) <= 3, 'Result capped at 3 posts' );

// Verify all posts have ID > 88285
foreach ( $res['posts'] as $p ) {
	assert_true( $p['message_id'] > 88285, "Post ID {$p['message_id']} is strictly > watermark 88285" );
}

// Verify ordering: oldest-new to newest
$ids = array_map( fn( $p ) => (int) $p['message_id'], $res['posts'] );
$sorted_ids = $ids;
sort( $sorted_ids );
assert_equals( $sorted_ids, $ids, 'Posts are sorted in chronological ascending order (oldest first)' );

// Verify processed_ids filter
$processed_to_skip = [ 'basherkella:' . $ids[0] ];
$res2 = KSTNews_AI_Telegram_Scraper::fetch_channel_posts( 'basherkella', 88285, $processed_to_skip, 3 );
$ids2 = array_map( fn( $p ) => (int) $p['message_id'], $res2['posts'] );
assert_true( ! in_array( $ids[0], $ids2, true ), "Already processed ID {$ids[0]} was skipped" );

// Test 8: Invalid channel name handling
echo "\n8. Testing invalid channel rejection:\n";
$invalid_res = KSTNews_AI_Telegram_Scraper::fetch_channel_posts( 'bad/channel*name' );
assert_true( ! $invalid_res['success'], 'Invalid channel name cleanly rejected' );

echo "\n Milestone 2 Telegram Scraper tests passed successfully!\n";
