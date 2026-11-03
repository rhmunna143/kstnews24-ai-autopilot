<?php
/**
 * Cron Scheduling and Execution Manager.
 *
 * @package KSTNews_AI_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KSTNews_AI_Cron {

	public const HOOK = 'kstnews_ai_cron_event';

	/**
	 * Initialize cron hooks.
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_intervals' ] );
		add_action( self::HOOK, [ __CLASS__, 'execute_cron' ] );
	}

	/**
	 * Add custom intervals for WP-Cron.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public static function add_intervals( array $schedules ): array {
		if ( ! isset( $schedules['fifteen_minutes'] ) ) {
			$schedules['fifteen_minutes'] = [
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => esc_html__( 'Every 15 Minutes', 'kstnews-ai-autopilot' ),
			];
		}
		if ( ! isset( $schedules['thirty_minutes'] ) ) {
			$schedules['thirty_minutes'] = [
				'interval' => 30 * MINUTE_IN_SECONDS,
				'display'  => esc_html__( 'Every 30 Minutes', 'kstnews-ai-autopilot' ),
			];
		}
		return $schedules;
	}

	/**
	 * Reschedule or clear cron based on current settings.
	 */
	public static function reschedule(): void {
		$settings = KSTNews_AI_Settings::get_all();
		$auto_cron = ! empty( $settings['auto_cron'] );
		$interval  = ! empty( $settings['interval'] ) ? $settings['interval'] : 'thirty_minutes';

		$timestamp = wp_next_scheduled( self::HOOK );

		if ( ! $auto_cron ) {
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::HOOK );
			}
			return;
		}

		// If already scheduled, check if schedule interval matches
		if ( $timestamp ) {
			$current_schedule = wp_get_schedule( self::HOOK );
			if ( $current_schedule === $interval ) {
				return; // Already correctly scheduled
			}
			wp_unschedule_event( $timestamp, self::HOOK );
		}

		wp_schedule_event( time() + 60, $interval, self::HOOK );
	}

	/**
	 * Get next scheduled execution timestamp or false if not scheduled.
	 *
	 * @return int|false
	 */
	public static function get_next_run() {
		return wp_next_scheduled( self::HOOK );
	}

	/**
	 * Background execution handler.
	 */
	public static function execute_cron(): void {
		if ( class_exists( 'KSTNews_AI_Publisher' ) && method_exists( 'KSTNews_AI_Publisher', 'run_pipeline' ) ) {
			KSTNews_AI_Publisher::run_pipeline( 'cron' );
		}
	}
}
