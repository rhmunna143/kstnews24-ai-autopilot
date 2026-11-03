<?php
/**
 * Plugin Name:       KSTNews24 AI Telegram AutoPilot
 * Plugin URI:        https://kstnews24.com
 * Description:       Automatically fetches news from public Telegram channels, screens for spam/AdSense safety, rewrites into SEO-ready Bengali articles using Gemini 2.5 Flash, formats images, and publishes them.
 * Version:           1.0.0
 * Author:            KSTNews24 Team
 * Author URI:        https://kstnews24.com
 * License:           GPL v2 or later
 * Text Domain:       kstnews-ai-autopilot
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 *
 * @package KSTNews_AI_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'KSTNEWS_AI_VERSION', '1.0.0' );
define( 'KSTNEWS_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KSTNEWS_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KSTNEWS_AI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoload / load dependencies immediately so classes are available
require_once KSTNEWS_AI_PLUGIN_DIR . 'includes/class-settings.php';
require_once KSTNEWS_AI_PLUGIN_DIR . 'includes/class-logger.php';
require_once KSTNEWS_AI_PLUGIN_DIR . 'includes/class-telegram-scraper.php';
require_once KSTNEWS_AI_PLUGIN_DIR . 'includes/class-prefilter.php';
require_once KSTNEWS_AI_PLUGIN_DIR . 'includes/class-gemini-client.php';
require_once KSTNEWS_AI_PLUGIN_DIR . 'includes/class-image-processor.php';
require_once KSTNEWS_AI_PLUGIN_DIR . 'includes/class-internal-linker.php';
require_once KSTNEWS_AI_PLUGIN_DIR . 'includes/class-publisher.php';
require_once KSTNEWS_AI_PLUGIN_DIR . 'includes/class-cron.php';
require_once KSTNEWS_AI_PLUGIN_DIR . 'includes/class-admin-ui.php';

/**
 * Main Autopilot Plugin Class.
 */
class KSTNews_AI_Autopilot {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Register core hooks.
	 */
	private function init_hooks(): void {
		// Plugin action links
		add_filter( 'plugin_action_links_' . KSTNEWS_AI_PLUGIN_BASENAME, [ $this, 'add_action_links' ] );

		// Initialize admin UI if in admin area
		if ( is_admin() ) {
			KSTNews_AI_Admin_UI::init();
		}

		// Initialize cron
		KSTNews_AI_Cron::init();
	}

	/**
	 * Add custom action links on the plugins listing page.
	 *
	 * @param array $links
	 * @return array
	 */
	public function add_action_links( array $links ): array {
		$settings_url  = admin_url( 'admin.php?page=kstnews-ai-autopilot' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'kstnews-ai-autopilot' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Plugin activation routine.
	 */
	public static function activate(): void {
		// Create log table
		self::create_tables();

		// Initialize settings if empty
		if ( false === get_option( KSTNews_AI_Settings::OPTION_KEY ) ) {
			add_option( KSTNews_AI_Settings::OPTION_KEY, KSTNews_AI_Settings::get_defaults() );
		}

		// Initialize state if empty
		if ( false === get_option( KSTNews_AI_Settings::STATE_KEY ) ) {
			add_option( KSTNews_AI_Settings::STATE_KEY, KSTNews_AI_Settings::get_default_state() );
		}

		// Schedule cron if enabled
		$settings = KSTNews_AI_Settings::get_all();
		if ( ! empty( $settings['auto_cron'] ) ) {
			if ( class_exists( 'KSTNews_AI_Cron' ) ) {
				KSTNews_AI_Cron::reschedule();
			}
		}
	}

	/**
	 * Create or upgrade custom database tables.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'kstnews_ai_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			channel varchar(100) NOT NULL DEFAULT '',
			message_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(50) NOT NULL DEFAULT '',
			reason text NULL,
			post_id bigint(20) unsigned NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY channel (channel),
			KEY message_id (message_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );
	}

	/**
	 * Plugin deactivation routine.
	 */
	public static function deactivate(): void {
		// Clear cron
		wp_clear_scheduled_hook( 'kstnews_ai_cron_event' );

		// Clear lock transient
		delete_transient( 'kstnews_ai_run_lock' );
	}
}

// Register activation / deactivation hooks
register_activation_hook( __FILE__, [ 'KSTNews_AI_Autopilot', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'KSTNews_AI_Autopilot', 'deactivate' ] );

// Initialize plugin on plugins_loaded
add_action( 'plugins_loaded', [ 'KSTNews_AI_Autopilot', 'get_instance' ] );
