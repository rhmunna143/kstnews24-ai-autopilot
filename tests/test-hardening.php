<?php
/**
 * Milestone 8: Hardening & Security Tests.
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

echo "\n--- Running Milestone 8: Hardening & Security Tests ---\n\n";

// Test 1: Direct Access Guards on all PHP files
echo "1. Checking direct access prevention in all PHP files:\n";
$plugin_dir = realpath( __DIR__ . '/../kstnews-ai-autopilot' );
$iterator   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir ) );

foreach ( $iterator as $file ) {
	if ( $file->isFile() && 'php' === $file->getExtension() ) {
		$content = file_get_contents( $file->getPathname() );
		$filename = $file->getFilename();

		if ( 'uninstall.php' === $filename ) {
			assert_true(
				str_contains( $content, "defined( 'WP_UNINSTALL_PLUGIN' )" ),
				"uninstall.php guarded by WP_UNINSTALL_PLUGIN check"
			);
		} else {
			assert_true(
				str_contains( $content, "defined( 'ABSPATH' )" ),
				"{$filename} guarded by ABSPATH check"
			);
		}
	}
}

// Test 2: SQL Injection Protection (all queries use prepare)
echo "\n2. Verifying SQL queries use parameterized prepare:\n";
$scraper_code = file_get_contents( $plugin_dir . '/includes/class-publisher.php' );
$logger_code  = file_get_contents( $plugin_dir . '/includes/class-logger.php' );

assert_true( str_contains( $scraper_code, '$wpdb->prepare(' ), 'Publisher uses $wpdb->prepare' );
assert_true( str_contains( $logger_code, '$wpdb->prepare(' ), 'Logger uses $wpdb->prepare' );

// Test 3: Uninstall Routine Clean-up
echo "\n3. Testing uninstall.php clean-up execution:\n";
// Set dummy values in options and state
update_option( 'kstnews_ai_settings', [ 'test' => 1 ] );
update_option( 'kstnews_ai_state', [ 'test' => 2 ] );
set_transient( 'kstnews_ai_run_lock', 'locked', 600 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}

require $plugin_dir . '/uninstall.php';

assert_equals( false, get_option( 'kstnews_ai_settings' ), 'kstnews_ai_settings option deleted on uninstall' );
assert_equals( false, get_option( 'kstnews_ai_state' ), 'kstnews_ai_state option deleted on uninstall' );
assert_equals( false, get_transient( 'kstnews_ai_run_lock' ), 'kstnews_ai_run_lock transient deleted on uninstall' );

// Test 4: SSRF Guard on Scraper & Image Downloader
echo "\n4. Verifying SSRF Protection on untrusted inputs:\n";
$dangerous_urls = [
	'http://169.254.169.254/latest/meta-data/',
	'http://localhost/admin',
	'http://127.0.0.1:8080/secret',
	'https://attacker.com/payload.jpg',
	'ftp://t.me/test',
	'data:image/png;base64,xxxx',
];

foreach ( $dangerous_urls as $bad_url ) {
	assert_true(
		! KSTNews_AI_Telegram_Scraper::is_allowed_telegram_cdn( $bad_url ),
		"SSRF blocked: {$bad_url}"
	);
	assert_equals(
		null,
		KSTNews_AI_Image_Processor::download_image( $bad_url ),
		"Image processor rejected: {$bad_url}"
	);
}

echo "\n Milestone 8 Hardening & Security tests passed successfully!\n";
