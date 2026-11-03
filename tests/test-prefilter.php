<?php
/**
 * Milestone 3: Pre-filter + Logger + State Tests.
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

echo "\n--- Running Milestone 3: Pre-filter & State/Logger Tests ---\n\n";

$settings = KSTNews_AI_Settings::get_defaults();

// Test 1: Short text without media
echo "1. Testing short text without media:\n";
$short_post = [
	'channel'       => 'basherkella',
	'message_id'    => 1001,
	'raw_text'      => 'ব্রেকিং নিউজ!', // < 40 chars
	'primary_image' => null,
	'photos'        => [],
	'videos'        => [],
	'has_buttons'   => false,
];
$res = KSTNews_AI_Prefilter::check( $short_post, $settings );
assert_true( ! $res['passed'], 'Short text with no media is blocked' );
assert_true( str_contains( $res['reason'], 'Short text' ), 'Reason mentions short text' );

// Test 2: Short text WITH media is allowed
echo "\n2. Testing short text WITH media:\n";
$short_with_photo = $short_post;
$short_with_photo['primary_image'] = 'https://cdn5.telesco.pe/file/photo1.jpg';
$short_with_photo['photos'] = [ 'https://cdn5.telesco.pe/file/photo1.jpg' ];
$res2 = KSTNews_AI_Prefilter::check( $short_with_photo, $settings );
assert_true( $res2['passed'], 'Short text with media is accepted' );

// Test 3: Inline CTA buttons
echo "\n3. Testing message with inline CTA buttons:\n";
$btn_post = [
	'channel'       => 'basherkella',
	'message_id'    => 1002,
	'raw_text'      => 'আজকের সেরা সুযোগ! এখনই রেজিস্ট্রেশন করুন এবং বিশেষ সুবিধা নিন।',
	'primary_image' => 'https://cdn5.telesco.pe/file/photo2.jpg',
	'photos'        => [ 'https://cdn5.telesco.pe/file/photo2.jpg' ],
	'videos'        => [],
	'has_buttons'   => true,
];
$res3 = KSTNews_AI_Prefilter::check( $btn_post, $settings );
assert_true( ! $res3['passed'], 'Message with inline CTA buttons is blocked' );
assert_true( str_contains( $res3['reason'], 'inline CTA buttons' ), 'Reason mentions inline buttons' );

// Test 4: Blocked keywords (Bengali & English)
echo "\n4. Testing blocked keywords:\n";
$ad_posts = [
	'অনলাইন বেটিং সাইটে ডিপোজিট করুন আর দ্বিগুণ বোনাস পান।' => 'বেটিং',
	'বিশেষ মূল্যছাড় ও ডিসকাউন্ট পেতে আজই ভিজিট করুন।'      => 'ডিসকাউন্ট',
	'Join our 1xbet VIP channel for exclusive winnings today.' => '1xbet',
	'Play at the best online casino and get sign up reward.' => 'casino',
];

foreach ( $ad_posts as $text => $expected_kw ) {
	$p = [
		'channel'       => 'basherkella',
		'message_id'    => 1003,
		'raw_text'      => $text,
		'primary_image' => 'https://cdn5.telesco.pe/file/p.jpg',
		'photos'        => [ 'https://cdn5.telesco.pe/file/p.jpg' ],
		'videos'        => [],
		'has_buttons'   => false,
	];
	$check = KSTNews_AI_Prefilter::check( $p, $settings );
	assert_true( ! $check['passed'], "Ad containing '{$expected_kw}' was blocked" );
	assert_true( str_contains( mb_strtolower( $check['reason'] ), mb_strtolower( $expected_kw ) ), "Reason mentions matched keyword '{$expected_kw}'" );
}

// Test 5: Mostly links and hashtags (>50%)
echo "\n5. Testing link and hashtag heavy message:\n";
$spam_links_post = [
	'channel'       => 'basherkella',
	'message_id'    => 1004,
	'raw_text'      => '#news #breaking https://t.me/channel1 https://t.me/channel2 @newsbot #viral #trending #update',
	'primary_image' => 'https://cdn5.telesco.pe/file/p.jpg',
	'photos'        => [ 'https://cdn5.telesco.pe/file/p.jpg' ],
	'videos'        => [],
	'has_buttons'   => false,
];
$res_spam = KSTNews_AI_Prefilter::check( $spam_links_post, $settings );
assert_true( ! $res_spam['passed'], 'Post with >50% links/hashtags is blocked' );

// Test 6: Genuine news message passes cleanly
echo "\n6. Testing genuine news article passes pre-filter:\n";
$news_post = [
	'channel'       => 'basherkella',
	'message_id'    => 1005,
	'raw_text'      => "জাতীয় সংসদ নির্বাচনের সার্বিক প্রস্তুতি পরিদর্শন করেছেন প্রধান নির্বাচন কমিশনার। তিনি জানান, ভোটারদের অবাধ অংশগ্রহণ নিশ্চিতে সব ধরনের নিরাপত্তা ব্যবস্থা জোরদার করা হয়েছে।",
	'primary_image' => 'https://cdn5.telesco.pe/file/cec_news.jpg',
	'photos'        => [ 'https://cdn5.telesco.pe/file/cec_news.jpg' ],
	'videos'        => [],
	'has_buttons'   => false,
];
$res_news = KSTNews_AI_Prefilter::check( $news_post, $settings );
assert_true( $res_news['passed'], 'Genuine news message passes prefilter' );
assert_equals( '', $res_news['reason'], 'Reason is empty on pass' );

// Test 7: Integration: Pre-filter rejection logs to Activity Log and updates state
echo "\n7. Testing logging and state updates on blocked items:\n";
KSTNews_AI_Logger::clear_logs();
KSTNews_AI_Settings::reset_state();

// Simulate pipeline handling a blocked post
$blocked_candidate = $btn_post;
$check = KSTNews_AI_Prefilter::check( $blocked_candidate, $settings );
if ( ! $check['passed'] ) {
	KSTNews_AI_Logger::log(
		$blocked_candidate['channel'],
		$blocked_candidate['message_id'],
		KSTNews_AI_Logger::STATUS_BLOCKED_PREFILTER,
		$check['reason']
	);

	// Update state watermark
	$state = KSTNews_AI_Settings::get_state();
	$last_ids = $state['last_message_id'] ?? [];
	$last_ids[ $blocked_candidate['channel'] ] = $blocked_candidate['message_id'];
	$processed = $state['processed_ids'] ?? [];
	$processed[] = $blocked_candidate['channel'] . ':' . $blocked_candidate['message_id'];

	KSTNews_AI_Settings::update_state([
		'last_message_id' => $last_ids,
		'processed_ids'   => $processed,
	]);
}

$logs = KSTNews_AI_Logger::get_logs( 10 );
assert_equals( 1, count( $logs ), 'Logged 1 blocked-prefilter entry' );
assert_equals( KSTNews_AI_Logger::STATUS_BLOCKED_PREFILTER, $logs[0]['status'], 'Status is Blocked-Prefilter' );
assert_true( str_contains( $logs[0]['reason'], 'inline CTA buttons' ), 'Reason logged accurately' );

$new_state = KSTNews_AI_Settings::get_state();
assert_equals( 1002, $new_state['last_message_id']['basherkella'], 'Watermark advanced past blocked post' );
assert_true( in_array( 'basherkella:1002', $new_state['processed_ids'], true ), 'Blocked post recorded in processed_ids' );

echo "\n Milestone 3 Pre-filter tests passed successfully!\n";
