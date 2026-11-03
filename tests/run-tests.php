<?php
/**
 * Master Test Suite Runner for KSTNews AI Telegram AutoPilot.
 */

$test_files = [
	'Milestone 1: Scaffold & Foundation'     => __DIR__ . '/test-scaffold.php',
	'Milestone 2: Telegram Scraper'          => __DIR__ . '/test-scraper.php',
	'Milestone 3: Pre-filter & State/Logger' => __DIR__ . '/test-prefilter.php',
	'Milestone 4: Gemini 2.5 Flash Client'   => __DIR__ . '/test-gemini.php',
	'Milestone 5: Image Processor (GD)'      => __DIR__ . '/test-image.php',
	'Milestone 6: Publisher & Internal Link' => __DIR__ . '/test-publisher.php',
	'Milestone 7: Pipeline Governance & Cron'=> __DIR__ . '/test-pipeline-governance.php',
	'Milestone 8: Hardening & Security'      => __DIR__ . '/test-hardening.php',
	'Milestone 9: All 13 Acceptance Criteria'=> __DIR__ . '/test-acceptance-all.php',
];

$passed_suites = 0;
$failed_suites = 0;

echo "==============================================================\n";
echo "   RUNNING ALL KSTNEWS24 AI AUTOPILOT TEST SUITES             \n";
echo "==============================================================\n";

foreach ( $test_files as $name => $path ) {
	echo "\n>>> Executing Suite: {$name}\n";
	$cmd = sprintf( 'php -d extension=gd "%s"', addslashes( $path ) );
	passthru( $cmd, $exit_code );

	if ( 0 === $exit_code ) {
		$passed_suites++;
	} else {
		$failed_suites++;
		echo "\n[ERROR] Suite '{$name}' exited with non-zero status ({$exit_code})\n";
	}
}

echo "\n==============================================================\n";
echo "   FINAL TEST RESULTS SUMMARY                                 \n";
echo "==============================================================\n";
echo "Suites Passed: {$passed_suites} / " . count( $test_files ) . "\n";
echo "Suites Failed: {$failed_suites}\n";

if ( $failed_suites > 0 ) {
	echo "Status: SOME TESTS FAILED!\n";
	exit( 1 );
} else {
	echo "Status: ALL TEST SUITES PASSED CLEANLY (100%)\n";
	exit( 0 );
}
