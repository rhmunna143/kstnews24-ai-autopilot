<?php
/**
 * Test Bootstrap and WordPress Test Doubles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

// Global in-memory storage for test options, transients, and database
global $test_options, $test_transients, $test_cron, $wpdb, $test_posts, $test_post_meta;
$test_options    = [];
$test_transients = [];
$test_cron       = [];
$test_posts      = [];
$test_post_meta  = [];

class MockWPDB {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public array $tables = [];
	public array $queries = [];

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function prepare( string $query, ...$args ): string {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$pos_d = strpos( $query, '%d' );
			$pos_s = strpos( $query, '%s' );

			if ( false !== $pos_d && ( false === $pos_s || $pos_d < $pos_s ) ) {
				$query = substr_replace( $query, (string) (int) $arg, $pos_d, 2 );
			} elseif ( false !== $pos_s ) {
				$val = "'" . addslashes( (string) $arg ) . "'";
				$query = substr_replace( $query, $val, $pos_s, 2 );
			}
		}
		return $query;
	}

	public function insert( string $table, array $data, ?array $format = null ): int|false {
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->tables[ $table ] = [];
		}
		$id = count( $this->tables[ $table ] ) + 1;
		$data['id'] = $id;
		$this->tables[ $table ][] = $data;
		return 1;
	}

	public function get_results( string $query, string $output = 'OBJECT' ): array {
		$this->queries[] = $query;
		// Simple mock query simulation for log queries
		$table = $this->prefix . 'kstnews_ai_log';
		$rows  = $this->tables[ $table ] ?? [];

		if ( str_contains( $query, 'ORDER BY id DESC' ) ) {
			$rows = array_reverse( $rows );
		}

		if ( str_contains( $query, 'GROUP BY status' ) ) {
			$counts = [];
			foreach ( $rows as $r ) {
				$st = $r['status'] ?? '';
				$counts[ $st ] = ( $counts[ $st ] ?? 0 ) + 1;
			}
			$res = [];
			foreach ( $counts as $st => $cnt ) {
				$res[] = [ 'status' => $st, 'cnt' => $cnt ];
			}
			return $res;
		}

		if ( preg_match( "/WHERE status = '([^']+)'/", $query, $m ) ) {
			$filtered = array_filter( $rows, fn( $r ) => ( $r['status'] ?? '' ) === $m[1] );
			return array_values( $filtered );
		}

		return $rows;
	}

	public function get_var( string $query ): mixed {
		$this->queries[] = $query;
		$table = $this->prefix . 'kstnews_ai_log';
		$rows  = $this->tables[ $table ] ?? [];

		if ( str_contains( $query, 'ORDER BY id DESC' ) ) {
			$rows = array_reverse( $rows );
		}

		if ( str_contains( $query, 'SHOW TABLES LIKE' ) ) {
			return $table;
		}

		if ( str_contains( $query, '_kstnews_source_channel' ) && str_contains( $query, '_kstnews_source_msg_id' ) ) {
			global $test_post_meta;
			if ( preg_match( "/pm1\.meta_value = '([^']+)'\s+AND\s+pm2\.meta_value = '([^']+)'/i", $query, $m ) ) {
				$ch = $m[1];
				$id = $m[2];
				foreach ( $test_post_meta as $pid => $meta ) {
					if ( ( $meta['_kstnews_source_channel'] ?? '' ) === $ch && (string) ( $meta['_kstnews_source_msg_id'] ?? '' ) === (string) $id ) {
						return (string) $pid;
					}
				}
			}
			return null;
		}

		if ( str_contains( $query, 'COUNT(*)' ) ) {
			if ( preg_match( "/WHERE status = '([^']+)'/", $query, $m ) ) {
				$count = count( array_filter( $rows, fn( $r ) => ( $r['status'] ?? '' ) === $m[1] ) );
				return (string) $count;
			}
			return (string) count( $rows );
		}

		return null;
	}

	public function query( string $query ): int|bool {
		$this->queries[] = $query;
		$table = $this->prefix . 'kstnews_ai_log';
		if ( str_contains( $query, 'TRUNCATE TABLE' ) ) {
			$this->tables[ $table ] = [];
			return true;
		}
		return true;
	}
}

$wpdb = new MockWPDB();

// WordPress Core Function Mocks
function get_option( string $option, mixed $default = false ): mixed {
	global $test_options;
	return array_key_exists( $option, $test_options ) ? $test_options[ $option ] : $default;
}

function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
	global $test_options;
	$test_options[ $option ] = $value;
	return true;
}

function add_option( string $option, mixed $value = '', mixed $deprecated = '', mixed $autoload = 'yes' ): bool {
	global $test_options;
	if ( ! array_key_exists( $option, $test_options ) ) {
		$test_options[ $option ] = $value;
		return true;
	}
	return false;
}

function delete_option( string $option ): bool {
	global $test_options;
	unset( $test_options[ $option ] );
	return true;
}

function get_transient( string $transient ): mixed {
	global $test_transients;
	return $test_transients[ $transient ] ?? false;
}

function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
	global $test_transients;
	$test_transients[ $transient ] = $value;
	return true;
}

function delete_transient( string $transient ): bool {
	global $test_transients;
	unset( $test_transients[ $transient ] );
	return true;
}

function sanitize_text_field( string $str ): string {
	return strip_tags( trim( $str ) );
}

function sanitize_textarea_field( string $str ): string {
	return strip_tags( trim( $str ) );
}

function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
}

function wp_unslash( mixed $value ): mixed {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( string $url ): string {
	return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
}

function esc_textarea( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return $text;
}

function esc_attr_e( string $text, string $domain = 'default' ): void {
	echo esc_attr( $text );
}

function esc_html_e( string $text, string $domain = 'default' ): void {
	echo esc_html( $text );
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function _e( string $text, string $domain = 'default' ): void {
	echo $text;
}

function current_time( string $type, int $gmt = 0 ): string {
	return gmdate( 'mysql' === $type ? 'Y-m-d H:i:s' : $type );
}

function human_time_diff( int $from, int $to = 0 ): string {
	if ( empty( $to ) ) {
		$to = time();
	}
	$diff = abs( $to - $from );
	return $diff . ' seconds';
}

function admin_url( string $path = '' ): string {
	return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
}

function wp_create_nonce( string|int $action = -1 ): string {
	return substr( md5( (string) $action . 'secret' ), 0, 10 );
}

function check_ajax_referer( string|int $action = -1, string $query_arg = 'false', bool $die = true ): int|bool {
	return 1;
}

function check_admin_referer( string|int $action = -1, string $query_arg = '_wpnonce' ): int|bool {
	return 1;
}

function current_user_can( string $capability, ...$args ): bool {
	return true;
}

function wp_die( string $message = '', string $title = '', array $args = [] ): void {
	throw new \RuntimeException( 'wp_die: ' . $message );
}

function wp_send_json_success( mixed $data = null ): void {
	echo json_encode( [ 'success' => true, 'data' => $data ] );
}

function wp_send_json_error( mixed $data = null ): void {
	echo json_encode( [ 'success' => false, 'data' => $data ] );
}

function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
	return json_encode( $data, $options, $depth );
}

function get_categories( array $args = [] ): array {
	return [
		(object) [ 'term_id' => 1, 'name' => 'জাতীয়', 'slug' => 'national' ],
		(object) [ 'term_id' => 2, 'name' => 'রাজনীতি', 'slug' => 'politics' ],
	];
}

function get_edit_post_link( int $id = 0 ): string {
	return admin_url( 'post.php?post=' . $id . '&action=edit' );
}

function wp_next_scheduled( string $hook, array $args = [] ): int|false {
	global $test_cron;
	return $test_cron[ $hook ]['timestamp'] ?? false;
}

function wp_get_schedule( string $hook, array $args = [] ): string|false {
	global $test_cron;
	return $test_cron[ $hook ]['recurrence'] ?? false;
}

function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = [] ): bool {
	global $test_cron;
	$test_cron[ $hook ] = [
		'timestamp'  => $timestamp,
		'recurrence' => $recurrence,
		'args'       => $args,
	];
	return true;
}

function wp_unschedule_event( int $timestamp, string $hook, array $args = [] ): bool {
	global $test_cron;
	unset( $test_cron[ $hook ] );
	return true;
}

function wp_clear_scheduled_hook( string $hook, array $args = [] ): int {
	global $test_cron;
	unset( $test_cron[ $hook ] );
	return 1;
}

function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return true;
}

function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return true;
}

function plugin_dir_path( string $file ): string {
	return dirname( $file ) . '/';
}

function plugin_dir_url( string $file ): string {
	return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

function plugin_basename( string $file ): string {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function is_admin(): bool {
	return true;
}

function dbDelta( mixed $queries = '' ): array {
	return [ 'kstnews_ai_log' => 'Created table' ];
}

function register_activation_hook( string $file, callable $callback ): void {}
function register_deactivation_hook( string $file, callable $callback ): void {}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	return parse_url( $url, $component );
}

function wp_remote_retrieve_response_code( mixed $response ): int {
	if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
		return (int) $response['response']['code'];
	}
	return 200;
}

function wp_remote_retrieve_body( mixed $response ): string {
	if ( is_array( $response ) && isset( $response['body'] ) ) {
		return (string) $response['body'];
	}
	return '';
}

function wp_remote_get( string $url, array $args = [] ): array|WP_Error {
	global $test_http_responses;
	if ( isset( $test_http_responses[ $url ] ) ) {
		return $test_http_responses[ $url ];
	}
	return [
		'response' => [ 'code' => 200 ],
		'body'     => '',
	];
}

function wp_remote_post( string $url, array $args = [] ): array|WP_Error {
	global $test_http_responses;
	if ( isset( $test_http_responses[ $url ] ) ) {
		return $test_http_responses[ $url ];
	}
	return [
		'response' => [ 'code' => 200 ],
		'body'     => '',
	];
}

function wp_kses( string $string, array|string $allowed_html, array $allowed_protocols = [] ): string {
	$allowed_tags = is_array( $allowed_html ) ? '<' . implode( '><', array_keys( $allowed_html ) ) . '>' : $allowed_html;
	return strip_tags( $string, $allowed_tags );
}

function wp_tempnam( string $filename = '', string $dir = '' ): string {
	$dir = sys_get_temp_dir();
	return tempnam( $dir, $filename ?: 'wp_' );
}

function sanitize_file_name( string $filename ): string {
	return preg_replace( '/[^A-Za-z0-9._-]/', '', $filename );
}

function sanitize_title( string $title ): string {
	return strtolower( trim( preg_replace( '/[^A-Za-z0-9-]+/', '-', $title ), '-' ) );
}

function media_handle_sideload( array $file_array, int $post_id = 0, ?string $desc = null, array $post_data = [] ): int|WP_Error {
	global $test_posts;
	$attach_id = count( $test_posts ) + 1000;
	$test_posts[ $attach_id ] = [
		'post_type' => 'attachment',
		'file'      => $file_array['name'],
		'parent'    => $post_id,
	];
	return $attach_id;
}

function set_post_thumbnail( int $post_id, int $thumbnail_id ): bool {
	global $test_post_meta;
	$test_post_meta[ $post_id ]['_thumbnail_id'] = $thumbnail_id;
	return true;
}

function update_post_meta( int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): bool|int {
	global $test_post_meta;
	$test_post_meta[ $post_id ][ $meta_key ] = $meta_value;
	return true;
}

function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
	global $test_post_meta;
	if ( empty( $key ) ) {
		return $test_post_meta[ $post_id ] ?? [];
	}
	$val = $test_post_meta[ $post_id ][ $key ] ?? false;
	return $single ? $val : ( false !== $val ? [ $val ] : [] );
}

function has_filter( string $hook_name, callable|string|array|bool $callback = false ): bool {
	return false;
}

function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	return $value;
}

function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
	$text = strip_tags( $text );
	return $remove_breaks ? preg_replace( '/[\r\n\t ]+/', ' ', $text ) : trim( $text );
}

function is_wp_error( mixed $thing ): bool {
	return is_object( $thing ) && isset( $thing->is_error ) && true === $thing->is_error;
}

class WP_Error {
	public bool $is_error = true;
	public string $code;
	public string $message;
	public function __construct( string $code = '', string $message = '' ) {
		$this->code = $code;
		$this->message = $message;
	}
	public function get_error_message(): string {
		return $this->message;
	}
	public function get_error_code(): string {
		return $this->code;
	}
}

function wp_insert_post( array $postarr, bool $wp_error = false ): int|WP_Error {
	global $test_posts;
	$id = count( $test_posts ) + 1;
	$postarr['ID'] = $id;
	$test_posts[ $id ] = $postarr;
	return $id;
}

function wp_update_post( array $postarr, bool $wp_error = false ): int|WP_Error {
	global $test_posts;
	$id = $postarr['ID'] ?? 0;
	if ( isset( $test_posts[ $id ] ) ) {
		$test_posts[ $id ] = array_merge( $test_posts[ $id ], $postarr );
	}
	return $id;
}

function wp_set_post_tags( int $post_id = 0, string|array $tags = '', bool $append = false ): array|false|WP_Error {
	global $test_post_meta;
	$test_post_meta[ $post_id ]['_tags'] = (array) $tags;
	return (array) $tags;
}

function get_term_by( string $field, string|int $value, string $taxonomy = '', string $output = 'OBJECT' ): object|false {
	if ( 'name' === $field && 'জাতীয়' === $value ) {
		return (object) [ 'term_id' => 10, 'name' => 'জাতীয়', 'slug' => 'national' ];
	}
	return false;
}

function wp_insert_term( string $term, string $taxonomy, array $args = [] ): array|WP_Error {
	return [ 'term_id' => 11, 'term_taxonomy_id' => 11 ];
}

function get_posts( array $args = [] ): array {
	global $test_posts;
	$res = [];
	$exclude = $args['post__not_in'] ?? [];
	foreach ( $test_posts as $id => $p ) {
		if ( in_array( $id, $exclude, true ) ) {
			continue;
		}
		if ( ( $p['post_type'] ?? '' ) === 'post' ) {
			$res[] = (object) [
				'ID'         => $id,
				'post_title' => $p['post_title'] ?? '',
			];
		}
	}
	return array_slice( $res, 0, $args['posts_per_page'] ?? 3 );
}

function get_the_title( int|object $post = 0 ): string {
	global $test_posts;
	$id = is_object( $post ) ? $post->ID : (int) $post;
	return $test_posts[ $id ]['post_title'] ?? "Post #{$id}";
}

function get_permalink( int|object $post = 0 ): string {
	global $test_posts;
	$id = is_object( $post ) ? $post->ID : (int) $post;
	$slug = $test_posts[ $id ]['post_name'] ?? "post-{$id}";
	return "https://kstnews24.com/{$slug}/";
}

// Require main plugin files
require_once __DIR__ . '/../kstnews-ai-autopilot/kstnews-ai-autopilot.php';
