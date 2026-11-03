<?php
/**
 * Milestone 1: Scaffold & Foundation Tests.
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

echo "\n--- Running Milestone 1: Scaffold Tests ---\n\n";

// Test 1: Plugin Constants
echo "1. Checking plugin constants:\n";
assert_true( defined( 'KSTNEWS_AI_VERSION' ), 'KSTNEWS_AI_VERSION is defined' );
assert_equals( '1.0.0', KSTNEWS_AI_VERSION, 'Version is 1.0.0' );
assert_true( defined( 'KSTNEWS_AI_PLUGIN_DIR' ), 'KSTNEWS_AI_PLUGIN_DIR is defined' );

// Test 2: Settings Defaults
echo "\n2. Checking default settings:\n";
$defaults = KSTNews_AI_Settings::get_defaults();
assert_equals( '', $defaults['gemini_key'], 'Default gemini_key is empty' );
assert_true( in_array( 'basherkella', $defaults['channels'], true ), 'Default channel is basherkella' );
assert_equals( 'draft', $defaults['post_status'], 'Default post_status is draft for safety' );
assert_equals( 0, $defaults['auto_cron'], 'Default auto_cron is off (0)' );
assert_equals( 3, $defaults['max_per_run'], 'Default max_per_run is 3' );
assert_equals( 20, $defaults['daily_cap'], 'Default daily_cap is 20' );
assert_equals( 'জাতীয়', $defaults['default_category'], 'Default category is জাতীয়' );
assert_true( count( $defaults['blocked_keywords'] ) >= 10, 'Blocked keywords list populated' );

// Test 3: API Key Masking
echo "\n3. Checking API key masking:\n";
assert_equals( '', KSTNews_AI_Settings::mask_api_key( '' ), 'Empty key returns empty string' );
assert_equals( '••••••', KSTNews_AI_Settings::mask_api_key( 'secret' ), 'Short key masked completely' );
$masked = KSTNews_AI_Settings::mask_api_key( 'AIzaSyA1234567890BCDEF' );
assert_true( str_ends_with( $masked, 'CDEF' ), 'Masked key preserves last 4 chars' );
assert_true( str_starts_with( $masked, '••••' ), 'Masked key starts with bullets' );

// Test 4: Channel Input Sanitization
echo "\n4. Checking channel parsing and sanitization:\n";
$raw_input = [
	'channels' => "https://t.me/basherkella\n@second_channel, https://t.me/s/third_channel\ninvalid!",
];
$sanitized = KSTNews_AI_Settings::sanitize_settings( $raw_input );
assert_true( in_array( 'basherkella', $sanitized['channels'], true ), 'basherkella parsed from URL' );
assert_true( in_array( 'second_channel', $sanitized['channels'], true ), 'second_channel parsed from @ handle' );
assert_true( in_array( 'third_channel', $sanitized['channels'], true ), 'third_channel parsed from t.me/s/ URL' );
assert_true( ! in_array( 'invalid!', $sanitized['channels'], true ), 'Invalid characters stripped' );

// Test 5: Number Constraints
echo "\n5. Checking boundaries on max_per_run and daily_cap:\n";
$clamped = KSTNews_AI_Settings::sanitize_settings( [
	'max_per_run' => 999, // Should be clamped to 10
	'daily_cap'   => -5,  // Should be clamped to 1
] );
assert_equals( 10, $clamped['max_per_run'], 'max_per_run clamped to 10 max' );
assert_equals( 1, $clamped['daily_cap'], 'daily_cap clamped to 1 min' );

// Test 6: Preserving API Key when Masked String submitted
echo "\n6. Checking API key preservation during settings update:\n";
KSTNews_AI_Settings::update_all( [ 'gemini_key' => 'REAL_SECRET_KEY_1234' ] );
assert_equals( 'REAL_SECRET_KEY_1234', KSTNews_AI_Settings::get( 'gemini_key' ), 'Real key saved' );

// Now simulate form save where UI submits masked value
$simulated_post = [
	'gemini_key' => '••••••••••••••••••••1234',
];
KSTNews_AI_Settings::update_all( $simulated_post );
assert_equals( 'REAL_SECRET_KEY_1234', KSTNews_AI_Settings::get( 'gemini_key' ), 'Existing key retained when masked value is submitted' );

// Test 7: State Management & Rolling Processed IDs
echo "\n7. Checking state management:\n";
KSTNews_AI_Settings::reset_state();
$state = KSTNews_AI_Settings::get_state();
assert_equals( 0, $state['daily_count'], 'Daily count reset to 0' );
assert_equals( [], $state['last_message_id'], 'Last message IDs empty' );

// Add 550 entries to processed_ids
$fake_ids = [];
for ( $i = 1; $i <= 550; $i++ ) {
	$fake_ids[] = "basherkella:{$i}";
}
KSTNews_AI_Settings::update_state( [ 'processed_ids' => $fake_ids ] );
$updated_state = KSTNews_AI_Settings::get_state();
assert_equals( 500, count( $updated_state['processed_ids'] ), 'Processed IDs capped at 500 rolling items' );
assert_equals( 'basherkella:550', end( $updated_state['processed_ids'] ), 'Newest ID is preserved' );

// Test 8: Activity Logger
echo "\n8. Checking activity logger:\n";
KSTNews_AI_Logger::clear_logs();
assert_equals( 0, KSTNews_AI_Logger::get_total_count(), 'Logs cleared' );

KSTNews_AI_Logger::log( 'basherkella', 101, KSTNews_AI_Logger::STATUS_DRAFT, 'Created draft post', 42 );
KSTNews_AI_Logger::log( 'basherkella', 102, KSTNews_AI_Logger::STATUS_BLOCKED_PREFILTER, 'Contains promo keyword' );

assert_equals( 2, KSTNews_AI_Logger::get_total_count(), 'Total count is 2' );
assert_equals( 1, KSTNews_AI_Logger::get_total_count( KSTNews_AI_Logger::STATUS_DRAFT ), 'Filter by status count works' );

$logs = KSTNews_AI_Logger::get_logs( 10 );
assert_equals( 2, count( $logs ), 'Retrieved 2 logs' );

// Test 9: Cron Interval Registration & Reschedule
echo "\n9. Checking cron scheduling logic:\n";
$schedules = KSTNews_AI_Cron::add_intervals( [] );
assert_true( isset( $schedules['fifteen_minutes'] ), 'fifteen_minutes interval added' );
assert_true( isset( $schedules['thirty_minutes'] ), 'thirty_minutes interval added' );
assert_equals( 900, $schedules['fifteen_minutes']['interval'], '15 min is 900s' );
assert_equals( 1800, $schedules['thirty_minutes']['interval'], '30 min is 1800s' );

// When auto_cron is off, reschedule clears hook
KSTNews_AI_Settings::update_all( [ 'auto_cron' => 0 ] );
KSTNews_AI_Cron::reschedule();
assert_equals( false, KSTNews_AI_Cron::get_next_run(), 'Cron is not scheduled when auto_cron is 0' );

// When auto_cron is on, reschedule schedules hook
KSTNews_AI_Settings::update_all( [ 'auto_cron' => 1, 'interval' => 'thirty_minutes' ] );
KSTNews_AI_Cron::reschedule();
assert_true( false !== KSTNews_AI_Cron::get_next_run(), 'Cron is scheduled when auto_cron is 1' );

echo "\n Milestone 1 Scaffold tests passed successfully!\n";
