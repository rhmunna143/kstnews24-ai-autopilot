<?php
/**
 * Milestone 4: Gemini Client Tests.
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

echo "\n--- Running Milestone 4: Gemini Client Tests ---\n\n";

global $test_http_responses;

// Test 1: test_connection with empty key
echo "1. Testing test_connection with empty key:\n";
$empty_res = KSTNews_AI_Gemini_Client::test_connection( '' );
assert_true( ! $empty_res['success'], 'Empty key fails' );

// Test 2: test_connection with valid mock response
echo "\n2. Testing test_connection with mock success response:\n";
$test_url = KSTNews_AI_Gemini_Client::ENDPOINT . KSTNews_AI_Gemini_Client::MODEL . ':generateContent?key=VALID_KEY';
$test_http_responses[ $test_url ] = [
	'response' => [ 'code' => 200 ],
	'body'     => json_encode( [
		'candidates' => [
			[ 'content' => [ 'parts' => [ [ 'text' => 'OK' ] ] ] ],
		],
	] ),
];

$valid_res = KSTNews_AI_Gemini_Client::test_connection( 'VALID_KEY' );
assert_true( $valid_res['success'], 'Valid key returns success' );
assert_true( str_contains( $valid_res['message'], 'verified successfully' ), 'Success message returned' );

// Test 3: test_connection with invalid mock key (HTTP 400)
echo "\n3. Testing test_connection with mock invalid key:\n";
$bad_url = KSTNews_AI_Gemini_Client::ENDPOINT . KSTNews_AI_Gemini_Client::MODEL . ':generateContent?key=BAD_KEY';
$test_http_responses[ $bad_url ] = [
	'response' => [ 'code' => 400 ],
	'body'     => json_encode( [
		'error' => [ 'message' => 'API key not valid. Please pass a valid API key.' ],
	] ),
];

$bad_res = KSTNews_AI_Gemini_Client::test_connection( 'BAD_KEY' );
assert_true( ! $bad_res['success'], 'Invalid key returns failure' );
assert_true( str_contains( $bad_res['message'], 'API key not valid' ), 'Clear error message returned' );

// Test 4: AI Safety Gate (Layer 2) - Graphic Violence
echo "\n4. Testing AI Safety Gate: Graphic violence blocked:\n";
$mock_blocked_violence = [
	'isGenuineNews'   => true,
	'isAdSenseSafe'   => false,
	'rejectReason'    => 'Violates Google AdSense policy: depicts graphic bloodshed and gruesome violence.',
];

$violence_url = KSTNews_AI_Gemini_Client::ENDPOINT . KSTNews_AI_Gemini_Client::MODEL . ':generateContent?key=TEST_KEY';
$test_http_responses[ $violence_url ] = [
	'response' => [ 'code' => 200 ],
	'body'     => json_encode( [
		'candidates' => [
			[ 'content' => [ 'parts' => [ [ 'text' => json_encode( $mock_blocked_violence ) ] ] ] ],
		],
	] ),
];

$msg_violence = [
	'channel'    => 'basherkella',
	'message_id' => 9001,
	'raw_text'   => 'রক্তাক্ত সংঘর্ষের ছবি ও খবর...',
];
$gen_res = KSTNews_AI_Gemini_Client::generate_article( $msg_violence, [ 'gemini_key' => 'TEST_KEY' ] );
assert_true( ! $gen_res['success'], 'Graphic violence blocked' );
assert_true( ! empty( $gen_res['blocked_by_ai'] ), 'blocked_by_ai flag set' );
assert_true( str_contains( $gen_res['rejectReason'], 'graphic bloodshed' ), 'Rejection reason contains graphic bloodshed note' );

// Test 5: AI Safety Gate (Layer 2) - Commercial Promotion / Spam
echo "\n5. Testing AI Safety Gate: Commercial promotion blocked:\n";
$mock_blocked_promo = [
	'isGenuineNews'   => false,
	'isAdSenseSafe'   => true,
	'rejectReason'    => 'Commercial marketing post with no genuine news value.',
];
$test_http_responses[ $violence_url ] = [
	'response' => [ 'code' => 200 ],
	'body'     => json_encode( [
		'candidates' => [
			[ 'content' => [ 'parts' => [ [ 'text' => json_encode( $mock_blocked_promo ) ] ] ] ],
		],
	] ),
];
$gen_promo = KSTNews_AI_Gemini_Client::generate_article( $msg_violence, [ 'gemini_key' => 'TEST_KEY' ] );
assert_true( ! $gen_promo['success'], 'Promo blocked' );
assert_true( ! empty( $gen_promo['blocked_by_ai'] ), 'blocked_by_ai flag set' );
assert_true( str_contains( $gen_promo['rejectReason'], 'Commercial marketing' ), 'Rejection reason logged' );

// Test 6: Genuine News Article Generation & Schema Validation
echo "\n6. Testing valid 300+ word article generation with schema:\n";
// Create 320-word Bengali content with 2 H2 headings
$para1 = str_repeat( 'জাতীয় সংসদ নির্বাচনের সার্বিক প্রস্তুতি পরিদর্শন করেছেন নির্বাচন কমিশন। দেশের প্রধান প্রধান শহরগুলোতে নিরাপত্তা ব্যবস্থা জোরদার করা হয়েছে। ', 10 );
$para2 = str_repeat( 'আইনশৃঙ্খলা রক্ষাকারী বাহিনীর অতিরিক্ত সদস্যদের মাঠে নামানো হয়েছে যাতে সাধারণ ভোটাররা শান্তিপূর্ণভাবে ভোটকেন্দ্রে আসতে পারেন। ', 10 );
$para3 = str_repeat( 'নির্বাচন পর্যবেক্ষক সংস্থাগুলো ইতিবাচক প্রতিক্রিয়া জানিয়েছে। দেশি ও বিদেশি প্রতিনিধিদের উপস্থিতি স্বচ্ছতা নিশ্চিত করবে। ', 10 );

$mock_content_html = "<h2>নির্বাচনী পরিবেশ ও নিরাপত্তা পর্যালোচনা</h2><p>{$para1}</p><h2>পর্যবেক্ষক ও আইন প্রয়োগকারী সংস্থার ভূমিকা</h2><p>{$para2}</p><p>{$para3}</p>";

$mock_valid_article = [
	'isGenuineNews'   => true,
	'isAdSenseSafe'   => true,
	'rejectReason'    => '',
	'title'           => 'জাতীয় সংসদ নির্বাচনের সার্বিক নিরাপত্তা প্রস্তুতি পরিদর্শন করলেন সিইসি',
	'contentHtml'     => $mock_content_html,
	'englishSlug'     => 'cec-inspects-national-election-security-preparations',
	'metaDescription' => 'জাতীয় সংসদ নির্বাচনের অবাধ ও সুষ্ঠু পরিবেশ নিশ্চিত করতে সার্বিক নিরাপত্তা পরিস্থিতি পর্যবেক্ষণ করেছে নির্বাচন কমিশন।',
	'category'        => 'জাতীয়',
	'tags'            => [ 'নির্বাচন', 'রাজনীতি', 'সিইসি', 'ভোট' ],
	'imageAltText'    => 'নির্বাচনী প্রস্তুতি পরিদর্শন করছেন প্রধান নির্বাচন কমিশনার',
];

$test_http_responses[ $violence_url ] = [
	'response' => [ 'code' => 200 ],
	'body'     => json_encode( [
		'candidates' => [
			[ 'content' => [ 'parts' => [ [ 'text' => json_encode( $mock_valid_article ) ] ] ] ],
		],
	] ),
];

$article_res = KSTNews_AI_Gemini_Client::generate_article( $msg_violence, [ 'gemini_key' => 'TEST_KEY' ] );
assert_true( $article_res['success'], 'Article generation succeeded' );

$art = $article_res['article'];
assert_true( $art['wordCount'] >= 300, "Word count is {$art['wordCount']} (>= 300)" );
assert_true( str_contains( $art['contentHtml'], '<h2>' ), 'Content contains <h2> subheadings' );
assert_equals( 'cec-inspects-national-election-security-preparations', $art['englishSlug'], 'English slug correctly set' );
assert_true( mb_strlen( $art['metaDescription'] ) <= 160, 'Meta description <= 160 chars' );
assert_equals( 'জাতীয়', $art['category'], 'Category is জাতীয়' );
assert_true( count( $art['tags'] ) >= 3, 'Tags populated' );

// Test 7: HTML Sanitization (strips forbidden tags like <script> and <iframe>)
echo "\n7. Testing HTML sanitization on output:\n";
$dirty_data = $mock_valid_article;
$dirty_data['contentHtml'] = "<h2>শিরোনাম</h2><p>স্বাভাবিক অনুচ্ছেদ। <script>alert('xss');</script><iframe src='https://evil.com'></iframe></p><h2>দ্বিতীয় অংশ</h2><p>{$para1}</p><p>{$para2}</p>";

$cleaned = KSTNews_AI_Gemini_Client::validate_and_sanitize( $dirty_data, 'source text' );
assert_true( ! str_contains( $cleaned['article']['contentHtml'], '<script>' ), 'Script tag stripped' );
assert_true( ! str_contains( $cleaned['article']['contentHtml'], '<iframe>' ), 'Iframe tag stripped' );
assert_true( str_contains( $cleaned['article']['contentHtml'], '<h2>' ), 'H2 tag preserved' );
assert_true( str_contains( $cleaned['article']['contentHtml'], '<p>' ), 'P tag preserved' );

echo "\n Milestone 4 Gemini Client tests passed successfully!\n";
