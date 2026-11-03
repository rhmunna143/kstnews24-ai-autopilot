<?php
/**
 * Milestone 6: Publisher & Internal Linker Tests.
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

echo "\n--- Running Milestone 6: Publisher & Internal Linker Tests ---\n\n";

global $test_posts, $test_post_meta;
$test_posts = [];
$test_post_meta = [];

// Seed an existing post in category 10 for internal linking test
$seed_id = wp_insert_post([
	'post_title'    => 'পূর্ববর্তী জাতীয় নির্বাচনের বিশেষ প্রতিবেদন',
	'post_name'     => 'previous-election-report',
	'post_type'     => 'post',
	'post_status'   => 'publish',
	'post_category' => [ 10 ],
]);

// Test 1: Internal Linker Block Generation
echo "1. Testing Internal Linker block generation:\n";
$block = KSTNews_AI_Internal_Linker::generate_block( 10, 999 );
assert_true( ! empty( $block ), 'Internal linking block generated' );
assert_true( str_contains( $block, 'আরও পড়ুন' ), 'Block contains Bengali header "আরও পড়ুন"' );
assert_true( str_contains( $block, 'পূর্ববর্তী জাতীয় নির্বাচনের বিশেষ প্রতিবেদন' ), 'Block links to existing category post' );

// Exclude seed post -> should return empty
$empty_block = KSTNews_AI_Internal_Linker::generate_block( 10, $seed_id );
assert_equals( '', $empty_block, 'Returns empty string if no other category posts exist' );

// Test 2: Publishing a new article
echo "\n2. Testing publish_article function:\n";
$msg = [
	'channel'       => 'basherkella',
	'message_id'    => 88277,
	'source_url'    => 'https://t.me/basherkella/88277',
	'primary_image' => '', // Test without image first
	'photos'        => [],
	'videos'        => [ 'https://cdn5.telesco.pe/file/video.mp4' ],
];

$article = [
	'title'           => 'সারাদেশে নতুন ভোটার তালিকা হালনাগাদ কর্মসূচি শুরু',
	'contentHtml'     => '<h2>ভোটার তালিকা সংক্রান্ত ব্রিফিং</h2><p>নির্বাচন কমিশনের উদ্যোগে দেশজুড়ে শুরু হয়েছে নতুন ভোটার তালিকা প্রণয়নের কার্যক্রম। যোগ্য নাগরিকদের অন্তর্ভুক্ত করতে কাজ করছেন কর্মকর্তারা।</p>',
	'englishSlug'     => 'nationwide-voter-list-update-starts',
	'metaDescription' => 'দেশজুড়ে নতুন ভোটার তালিকা হালনাগাদ কার্যক্রম আনুষ্ঠানিকভাবে শুরু করেছে নির্বাচন কমিশন।',
	'category'        => 'জাতীয়',
	'tags'            => [ 'ভোটার', 'নির্বাচন', 'বাংলাদেশ' ],
	'imageAltText'    => 'ভোটার তালিকা হালনাগাদ',
];

$settings = KSTNews_AI_Settings::get_defaults();
$settings['post_status']  = 'draft';
$settings['embed_video']  = 1;
$settings['source_label'] = 'সূত্র: Telegram (@basherkella)';

$pub_res = KSTNews_AI_Publisher::publish_article( $msg, $article, $settings );
assert_true( $pub_res['success'], 'publish_article succeeded' );
$new_post_id = $pub_res['post_id'];
assert_true( $new_post_id > 0, "Post created with ID {$new_post_id}" );

$created_post = $test_posts[ $new_post_id ];
assert_equals( 'draft', $created_post['post_status'], 'Post status is draft' );
assert_equals( 'nationwide-voter-list-update-starts', $created_post['post_name'], 'English slug saved' );
assert_true( str_contains( $created_post['post_content'], 'সূত্র: Telegram (@basherkella)' ), 'Attribution line appended' );
assert_true( str_contains( $created_post['post_content'], '<video controls' ), 'Video embed included when embed_video enabled' );
assert_true( str_contains( $created_post['post_content'], 'আরও পড়ুন' ), 'Internal links injected into published content' );

// Test 3: SEO Meta Keys verification
echo "\n3. Testing SEO & Tracking Meta Fields:\n";
assert_equals( 'basherkella', get_post_meta( $new_post_id, '_kstnews_source_channel', true ), '_kstnews_source_channel saved' );
assert_equals( 88277, get_post_meta( $new_post_id, '_kstnews_source_msg_id', true ), '_kstnews_source_msg_id saved' );
assert_equals( 'https://t.me/basherkella/88277', get_post_meta( $new_post_id, '_kstnews_source_url', true ), '_kstnews_source_url saved' );
assert_equals( $article['metaDescription'], get_post_meta( $new_post_id, '_kstnews_meta_description', true ), '_kstnews_meta_description saved' );
assert_equals( $article['metaDescription'], get_post_meta( $new_post_id, '_yoast_wpseo_metadesc', true ), '_yoast_wpseo_metadesc saved' );
assert_equals( $article['metaDescription'], get_post_meta( $new_post_id, 'rank_math_description', true ), 'rank_math_description saved' );
assert_equals( $article['metaDescription'], get_post_meta( $new_post_id, '_aioseo_description', true ), '_aioseo_description saved' );

// Test 4: Deduplication Check
echo "\n4. Testing duplicate post prevention:\n";
$dup_res = KSTNews_AI_Publisher::publish_article( $msg, $article, $settings );
assert_true( ! $dup_res['success'], 'Duplicate publication prevented' );
assert_true( ! empty( $dup_res['skipped'] ), 'Skipped flag set' );
assert_true( str_contains( $dup_res['reason'], 'Post already exists' ), 'Reason states post already exists' );

echo "\n Milestone 6 Publisher & Internal Linker tests passed successfully!\n";
