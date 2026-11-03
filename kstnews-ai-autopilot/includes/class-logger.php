<?php
/**
 * Activity Logger.
 *
 * @package KSTNews_AI_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KSTNews_AI_Logger {

	/**
	 * Log status constants.
	 */
	public const STATUS_PUBLISHED        = 'Published';
	public const STATUS_DRAFT            = 'Draft';
	public const STATUS_BLOCKED_PREFILTER = 'Blocked-Prefilter';
	public const STATUS_BLOCKED_AI       = 'Blocked-AI';
	public const STATUS_SKIPPED_DUPLICATE = 'Skipped-Duplicate';
	public const STATUS_ERROR            = 'Error';

	/**
	 * Get table name with WordPress prefix.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'kstnews_ai_log';
	}

	/**
	 * Write an entry to the activity log.
	 *
	 * @param string   $channel
	 * @param int      $message_id
	 * @param string   $status
	 * @param string   $reason
	 * @param int|null $post_id
	 * @return bool
	 */
	public static function log( string $channel, int $message_id, string $status, string $reason = '', ?int $post_id = 0 ): bool {
		global $wpdb;

		$table = self::get_table_name();

		$result = $wpdb->insert(
			$table,
			[
				'created_at' => current_time( 'mysql' ),
				'channel'    => sanitize_text_field( $channel ),
				'message_id' => (int) $message_id,
				'status'     => sanitize_text_field( $status ),
				'reason'     => sanitize_textarea_field( $reason ),
				'post_id'    => (int) $post_id,
			],
			[ '%s', '%s', '%d', '%s', '%s', '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Retrieve activity logs with pagination and optional status filter.
	 *
	 * @param int    $limit
	 * @param int    $offset
	 * @param string $status
	 * @return array
	 */
	public static function get_logs( int $limit = 100, int $offset = 0, string $status = '' ): array {
		global $wpdb;

		$table  = self::get_table_name();
		$limit  = max( 1, min( 500, (int) $limit ) );
		$offset = max( 0, (int) $offset );

		if ( ! empty( $status ) ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d",
				$status,
				$limit,
				$offset
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			);
		}

		$results = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $results ) ? $results : [];
	}

	/**
	 * Get total count of log entries.
	 *
	 * @param string $status
	 * @return int
	 */
	public static function get_total_count( string $status = '' ): int {
		global $wpdb;

		$table = self::get_table_name();

		if ( ! empty( $status ) ) {
			$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status );
		} else {
			$sql = "SELECT COUNT(*) FROM {$table}";
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get summary statistics for dashboard cards.
	 *
	 * @return array
	 */
	public static function get_stats(): array {
		global $wpdb;

		$table = self::get_table_name();
		$today = current_time( 'Y-m-d' );

		$stats = [
			'total'             => 0,
			'published_today'   => 0,
			'draft_today'       => 0,
			'blocked_prefilter' => 0,
			'blocked_ai'        => 0,
			'errors'            => 0,
		];

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
			return $stats;
		}

		$stats['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$today_counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) as cnt FROM {$table} WHERE created_at >= %s GROUP BY status",
				$today . ' 00:00:00'
			),
			ARRAY_A
		);

		if ( is_array( $today_counts ) ) {
			foreach ( $today_counts as $row ) {
				$st = $row['status'];
				$c  = (int) $row['cnt'];
				if ( self::STATUS_PUBLISHED === $st ) {
					$stats['published_today'] = $c;
				} elseif ( self::STATUS_DRAFT === $st ) {
					$stats['draft_today'] = $c;
				} elseif ( self::STATUS_BLOCKED_PREFILTER === $st ) {
					$stats['blocked_prefilter'] += $c;
				} elseif ( self::STATUS_BLOCKED_AI === $st ) {
					$stats['blocked_ai'] += $c;
				} elseif ( self::STATUS_ERROR === $st ) {
					$stats['errors'] += $c;
				}
			}
		}

		return $stats;
	}

	/**
	 * Clear all logs.
	 *
	 * @return bool
	 */
	public static function clear_logs(): bool {
		global $wpdb;
		$table = self::get_table_name();
		return false !== $wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Prune old logs keeping the newest $keep rows.
	 *
	 * @param int $keep
	 * @return int Number of deleted rows.
	 */
	public static function prune_logs( int $keep = 500 ): int {
		global $wpdb;
		$table = self::get_table_name();

		$cutoff_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
				$keep
			)
		);

		if ( $cutoff_id ) {
			return (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff_id )
			);
		}

		return 0;
	}
}
