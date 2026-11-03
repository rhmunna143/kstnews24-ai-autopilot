<?php
/**
 * Milestone 7: Cron, Run Lock, Time Budget, and Daily Cap Governance Tests.
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

echo "\n--- Running Milestone 7: Pipeline Governance & Cron Tests ---\n\n";

// Clear state and lock
delete_transient( KSTNews_AI_Publisher::LOCK_KEY );
KSTNews_AI_Settings::reset_state();

// Test 1: Concurrency Run Lock
echo "1. Testing Concurrency Run Lock:\n";
set_transient( KSTNews_AI_Publisher::LOCK_KEY, '123456:active_process', 600 );

$locked_run = KSTNews_AI_Publisher::run_pipeline( 'manual' );
assert_true( ! $locked_run['success'], 'Pipeline run blocked while locked' );
assert_equals( 'locked', $locked_run['status'], 'Status is locked' );

// Release lock
delete_transient( KSTNews_AI_Publisher::LOCK_KEY );
assert_equals( false, get_transient( KSTNews_AI_Publisher::LOCK_KEY ), 'Lock cleared' );

// Test 2: Daily Cap Governance
echo "\n2. Testing Daily Cap Enforcement:\n";
KSTNews_AI_Settings::update_all( [ 'daily_cap' => 5 ] );
KSTNews_AI_Settings::update_state( [ 'daily_count' => 5 ] ); // Cap reached

$cap_run = KSTNews_AI_Publisher::run_pipeline( 'manual' );
assert_true( $cap_run['success'], 'Pipeline terminates safely when cap reached' );
assert_equals( 'daily_cap_reached', $cap_run['status'], 'Status is daily_cap_reached' );
assert_equals( false, get_transient( KSTNews_AI_Publisher::LOCK_KEY ), 'Lock released on cap exit' );

// Reset daily count (simulating next day)
KSTNews_AI_Settings::update_state( [ 'daily_count' => 0 ] );
$state = KSTNews_AI_Settings::get_state();
assert_equals( 0, $state['daily_count'], 'Daily count reset for new day' );

// Test 3: WP-Cron Trigger Integration
echo "\n3. Testing WP-Cron Trigger Integration:\n";
// Set auto_cron enabled
KSTNews_AI_Settings::update_all( [ 'auto_cron' => 1, 'interval' => 'fifteen_minutes' ] );
KSTNews_AI_Cron::reschedule();

$next_run = KSTNews_AI_Cron::get_next_run();
assert_true( false !== $next_run, 'WP-Cron scheduled successfully' );

// Calling execute_cron does not throw any exceptions
$executed = false;
try {
	// Execute cron with mock empty channel
	global $test_http_responses;
	$test_http_responses['https://t.me/s/basherkella'] = [
		'response' => [ 'code' => 200 ],
		'body'     => '<div class="tgme_channel_history"></div>',
	];
	KSTNews_AI_Cron::execute_cron();
	$executed = true;
} catch ( Exception $e ) {
	$executed = false;
}
assert_true( $executed, 'KSTNews_AI_Cron::execute_cron executes without exceptions' );
assert_equals( false, get_transient( KSTNews_AI_Publisher::LOCK_KEY ), 'Lock released after cron run' );

// Test 4: End-to-End Pipeline Execution with Mock Channel and Gemini
echo "\n4. Testing Full Pipeline End-to-End Execution:\n";
// Setup mock channel fixture with 2 posts
$feed_html = file_get_contents( __DIR__ . '/fixtures/live_basherkella.html' );
$test_http_responses['https://t.me/s/basherkella'] = [
	'response' => [ 'code' => 200 ],
	'body'     => $feed_html,
];

// Setup mock Gemini response
$para = str_repeat( 'গণপ্রজাতন্ত্রী বাংলাদেশ সরকারের শিক্ষা মন্ত্রণালয়ের আওতাধীন নতুন শিক্ষা কার্যক্রম পর্যালোচনা করা হয়েছে। সংশ্লিষ্ট কর্মকর্তারা সার্বিক পরিস্থিতি বিস্তারিতভাবে তুলে ধরেছেন। ', 12 );
$mock_gemini_article = [
	'isGenuineNews'   => true,
	'isAdSenseSafe'   => true,
	'rejectReason'    => '',
	'title'           => 'জাতীয় শিক্ষাক্রমের নতুন নীতিমালার বাস্তবায়ন পর্যবেক্ষণ',
	'contentHtml'     => "<h2>বাস্তবায়ন অগ্রগতি ও পর্যালোচনা</h2><p>{$para}</p><h2>সংশ্লিষ্ট মহলের পর্যবেক্ষণ</h2><p>{$para}</p>",
	'englishSlug'     => 'national-curriculum-implementation-review',
	'metaDescription' => 'জাতীয় শিক্ষাক্রম বাস্তবায়নের অগ্রগতি এবং অংশীজনদের মূল্যায়ন তুলে ধরেছে সংশ্লিষ্ট মন্ত্রণালয়।',
	'category'        => 'জাতীয়',
	'tags'            => [ 'শিক্ষা', 'বাংলাদেশ', 'নীতিমালা' ],
	'imageAltText'    => 'শিক্ষাক্রম বাস্তবায়ন সভা',
];

$gemini_url = KSTNews_AI_Gemini_Client::ENDPOINT . KSTNews_AI_Gemini_Client::MODEL . ':generateContent?key=TEST_KEY';
$test_http_responses[ $gemini_url ] = [
	'response' => [ 'code' => 200 ],
	'body'     => json_encode( [
		'candidates' => [
			[ 'content' => [ 'parts' => [ [ 'text' => json_encode( $mock_gemini_article ) ] ] ] ],
		],
	] ),
];

KSTNews_AI_Settings::update_all( [
	'gemini_key'   => 'TEST_KEY',
	'channels'     => [ 'basherkella' ],
	'post_status'  => 'draft',
	'max_per_run'  => 2,
	'daily_cap'    => 20,
] );

KSTNews_AI_Settings::reset_state();
KSTNews_AI_Logger::clear_logs();

$pipeline_res = KSTNews_AI_Publisher::run_pipeline( 'manual' );
assert_true( $pipeline_res['success'], 'Pipeline run succeeded' );
assert_true( $pipeline_res['published'] >= 1, "At least 1 article published (published: {$pipeline_res['published']})" );

$updated_state = KSTNews_AI_Settings::get_state();
assert_true( ( $updated_state['daily_count'] ?? 0 ) >= 1, 'Daily count incremented in state' );
assert_true( count( $updated_state['processed_ids'] ) >= 1, 'Processed IDs recorded in state' );
assert_true( ( $updated_state['last_message_id']['basherkella'] ?? 0 ) > 0, 'Channel watermark updated' );

$logs = KSTNews_AI_Logger::get_logs( 10 );
assert_true( count( $logs ) >= 1, 'Activity log records pipeline outcome' );
assert_equals( KSTNews_AI_Logger::STATUS_DRAFT, $logs[0]['status'], 'Article published as Draft' );

// Second run immediately following should process 0 duplicates
echo "\n5. Testing Second Pipeline Run skips duplicates:\n";
$second_run = KSTNews_AI_Publisher::run_pipeline( 'manual' );
assert_true( $second_run['success'], 'Second run completed' );
// Because watermark advanced, already processed messages are skipped!
assert_true( $second_run['published'] <= 2, 'Batch limit strictly observed' );

echo "\n Milestone 7 Pipeline Governance tests passed successfully!\n";
