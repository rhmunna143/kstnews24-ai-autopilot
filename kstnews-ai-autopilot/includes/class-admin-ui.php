<?php
/**
 * Admin User Interface and Settings Handler.
 *
 * @package KSTNews_AI_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KSTNews_AI_Admin_UI {

	/**
	 * Menu slug.
	 */
	public const MENU_SLUG = 'kstnews-ai-autopilot';

	/**
	 * Nonce action strings.
	 */
	public const NONCE_SETTINGS = 'kstnews_ai_settings_action';
	public const NONCE_AJAX     = 'kstnews_ai_ajax_nonce';

	/**
	 * Initialize admin hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_form_save' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		// AJAX handlers
		add_action( 'wp_ajax_kstnews_test_gemini', [ __CLASS__, 'ajax_test_gemini' ] );
		add_action( 'wp_ajax_kstnews_run_manual', [ __CLASS__, 'ajax_run_manual' ] );
		add_action( 'wp_ajax_kstnews_reset_state', [ __CLASS__, 'ajax_reset_state' ] );
		add_action( 'wp_ajax_kstnews_clear_logs', [ __CLASS__, 'ajax_clear_logs' ] );
	}

	/**
	 * Register admin menu.
	 */
	public static function register_menu(): void {
		add_menu_page(
			esc_html__( 'KSTNews AI Bot', 'kstnews-ai-autopilot' ),
			esc_html__( 'KSTNews AI Bot', 'kstnews-ai-autopilot' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ],
			'dashicons-robot',
			26
		);
	}

	/**
	 * Enqueue styles and scripts on plugin admin page only.
	 *
	 * @param string $hook
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'kstnews-ai-admin-css',
			KSTNEWS_AI_PLUGIN_URL . 'assets/admin.css',
			[],
			KSTNEWS_AI_VERSION
		);

		wp_enqueue_script(
			'kstnews-ai-admin-js',
			KSTNEWS_AI_PLUGIN_URL . 'assets/admin.js',
			[ 'jquery' ],
			KSTNEWS_AI_VERSION,
			true
		);

		wp_localize_script(
			'kstnews-ai-admin-js',
			'kstnewsAiData',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'ajaxNonce' => wp_create_nonce( self::NONCE_AJAX ),
				'strings'   => [
					'confirmReset'    => esc_html__( 'Are you sure you want to reset all channel message IDs and history? Posts will not be deleted.', 'kstnews-ai-autopilot' ),
					'confirmClearLog' => esc_html__( 'Are you sure you want to clear all activity log entries?', 'kstnews-ai-autopilot' ),
					'running'         => esc_html__( 'Processing... Please wait.', 'kstnews-ai-autopilot' ),
					'testing'         => esc_html__( 'Testing Gemini API...', 'kstnews-ai-autopilot' ),
				],
			]
		);
	}

	/**
	 * Handle standard POST settings form submission.
	 */
	public static function handle_form_save(): void {
		if ( ! isset( $_POST['kstnews_save_settings'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized user action.', 'kstnews-ai-autopilot' ) );
		}

		check_admin_referer( self::NONCE_SETTINGS, 'kstnews_nonce' );

		$raw_settings = $_POST['kstnews_settings'] ?? [];
		if ( ! is_array( $raw_settings ) ) {
			$raw_settings = [];
		}

		KSTNews_AI_Settings::update_all( $raw_settings );

		// Reschedule cron if needed
		if ( class_exists( 'KSTNews_AI_Cron' ) ) {
			KSTNews_AI_Cron::reschedule();
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => self::MENU_SLUG,
					'updated' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * AJAX: Test Gemini connection.
	 */
	public static function ajax_test_gemini(): void {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'kstnews-ai-autopilot' ) ] );
		}

		$provided_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';

		// If blank or masked placeholder, use stored key
		if ( empty( $provided_key ) || str_starts_with( $provided_key, '••••' ) ) {
			$provided_key = KSTNews_AI_Settings::get( 'gemini_key', '' );
		}

		if ( empty( $provided_key ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'No Gemini API key provided. Please enter an API key first.', 'kstnews-ai-autopilot' ) ] );
		}

		$result = KSTNews_AI_Gemini_Client::test_connection( $provided_key );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( [ 'message' => $result['message'] ?? esc_html__( 'Gemini API connection successful!', 'kstnews-ai-autopilot' ) ] );
		} else {
			wp_send_json_error( [ 'message' => $result['message'] ?? esc_html__( 'Gemini API connection failed.', 'kstnews-ai-autopilot' ) ] );
		}
	}

	/**
	 * AJAX: Run manual fetch & publish pipeline.
	 */
	public static function ajax_run_manual(): void {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'kstnews-ai-autopilot' ) ] );
		}

		$result = KSTNews_AI_Publisher::run_pipeline( 'manual' );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: Reset tracking state.
	 */
	public static function ajax_reset_state(): void {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'kstnews-ai-autopilot' ) ] );
		}

		KSTNews_AI_Settings::reset_state();

		wp_send_json_success( [ 'message' => esc_html__( 'Tracking state successfully reset. Previous message IDs cleared.', 'kstnews-ai-autopilot' ) ] );
	}

	/**
	 * AJAX: Clear activity logs.
	 */
	public static function ajax_clear_logs(): void {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'kstnews-ai-autopilot' ) ] );
		}

		KSTNews_AI_Logger::clear_logs();

		wp_send_json_success( [ 'message' => esc_html__( 'Activity logs cleared.', 'kstnews-ai-autopilot' ) ] );
	}

	/**
	 * Render the main plugin admin dashboard.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'kstnews-ai-autopilot' ) );
		}

		$settings = KSTNews_AI_Settings::get_all();
		$state    = KSTNews_AI_Settings::get_state();
		$stats    = KSTNews_AI_Logger::get_stats();

		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$paged        = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$per_page     = 50;
		$offset       = ( $paged - 1 ) * $per_page;

		$total_logs   = KSTNews_AI_Logger::get_total_count( $status_filter );
		$logs         = KSTNews_AI_Logger::get_logs( $per_page, $offset, $status_filter );
		$total_pages  = (int) ceil( $total_logs / $per_page );

		// Next cron run
		$next_cron_ts = KSTNews_AI_Cron::get_next_run();
		$next_cron_human = $next_cron_ts ? human_time_diff( $next_cron_ts ) : esc_html__( 'Not scheduled', 'kstnews-ai-autopilot' );

		// Categories for dropdown
		$categories = get_categories( [ 'hide_empty' => false ] );
		?>
		<div class="wrap kstnews-ai-wrap">
			<!-- Header -->
			<div class="kstnews-ai-header">
				<div class="kstnews-ai-title-group">
					<h1 class="wp-heading-inline">
						<span class="dashicons dashicons-robot"></span>
						<?php esc_html_e( 'KSTNews AI Telegram AutoPilot', 'kstnews-ai-autopilot' ); ?>
					</h1>
					<span class="kstnews-version-badge">v<?php echo esc_html( KSTNEWS_AI_VERSION ); ?></span>
				</div>
				<div class="kstnews-header-actions">
					<button type="button" class="button button-primary kstnews-btn-fetch" id="kstnews-btn-manual-run">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Fetch & Publish Now', 'kstnews-ai-autopilot' ); ?>
					</button>
					<button type="button" class="button button-secondary" id="kstnews-btn-test-gemini">
						<span class="dashicons dashicons-admin-network"></span>
						<?php esc_html_e( 'Test Gemini Connection', 'kstnews-ai-autopilot' ); ?>
					</button>
					<button type="button" class="button button-link-delete" id="kstnews-btn-reset-state">
						<span class="dashicons dashicons-trash"></span>
						<?php esc_html_e( 'Reset State', 'kstnews-ai-autopilot' ); ?>
					</button>
				</div>
			</div>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully.', 'kstnews-ai-autopilot' ); ?></p>
				</div>
			<?php endif; ?>

			<div id="kstnews-ajax-notice" class="kstnews-ajax-notice" style="display:none;"></div>

			<!-- Summary Stat Cards -->
			<div class="kstnews-stats-grid">
				<div class="kstnews-stat-card">
					<div class="kstnews-stat-icon dashicons-admin-generic"></div>
					<div class="kstnews-stat-content">
						<span class="kstnews-stat-label"><?php esc_html_e( 'Gemini API', 'kstnews-ai-autopilot' ); ?></span>
						<span class="kstnews-stat-value <?php echo ! empty( $settings['gemini_key'] ) ? 'is-good' : 'is-warn'; ?>">
							<?php echo ! empty( $settings['gemini_key'] ) ? esc_html__( 'Connected', 'kstnews-ai-autopilot' ) : esc_html__( 'Missing Key', 'kstnews-ai-autopilot' ); ?>
						</span>
					</div>
				</div>

				<div class="kstnews-stat-card">
					<div class="kstnews-stat-icon dashicons-clock"></div>
					<div class="kstnews-stat-content">
						<span class="kstnews-stat-label"><?php esc_html_e( 'Auto-Cron', 'kstnews-ai-autopilot' ); ?></span>
						<span class="kstnews-stat-value <?php echo ! empty( $settings['auto_cron'] ) ? 'is-good' : 'is-neutral'; ?>">
							<?php
							if ( ! empty( $settings['auto_cron'] ) ) {
								printf( esc_html__( 'Active (%s)', 'kstnews-ai-autopilot' ), esc_html( $next_cron_human ) );
							} else {
								esc_html_e( 'Disabled', 'kstnews-ai-autopilot' );
							}
							?>
						</span>
					</div>
				</div>

				<div class="kstnews-stat-card">
					<div class="kstnews-stat-icon dashicons-media-document"></div>
					<div class="kstnews-stat-content">
						<span class="kstnews-stat-label"><?php esc_html_e( "Today's Posts / Cap", 'kstnews-ai-autopilot' ); ?></span>
						<span class="kstnews-stat-value">
							<?php echo (int) ( $state['daily_count'] ?? 0 ); ?> / <?php echo (int) ( $settings['daily_cap'] ?? 20 ); ?>
						</span>
					</div>
				</div>

				<div class="kstnews-stat-card">
					<div class="kstnews-stat-icon dashicons-shield"></div>
					<div class="kstnews-stat-content">
						<span class="kstnews-stat-label"><?php esc_html_e( 'Blocked Ads / Spam', 'kstnews-ai-autopilot' ); ?></span>
						<span class="kstnews-stat-value">
							<?php echo (int) ( $stats['blocked_prefilter'] + $stats['blocked_ai'] ); ?>
						</span>
					</div>
				</div>
			</div>

			<!-- Tab Navigation -->
			<nav class="nav-tab-wrapper kstnews-nav-tab-wrapper">
				<a href="?page=<?php echo esc_attr( self::MENU_SLUG ); ?>&tab=settings" class="nav-tab <?php echo 'settings' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-admin-settings"></span>
					<?php esc_html_e( 'Settings & Configuration', 'kstnews-ai-autopilot' ); ?>
				</a>
				<a href="?page=<?php echo esc_attr( self::MENU_SLUG ); ?>&tab=logs" class="nav-tab <?php echo 'logs' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( 'Activity Log', 'kstnews-ai-autopilot' ); ?>
					<span class="kstnews-tab-count"><?php echo (int) $total_logs; ?></span>
				</a>
				<a href="?page=<?php echo esc_attr( self::MENU_SLUG ); ?>&tab=system" class="nav-tab <?php echo 'system' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-info"></span>
					<?php esc_html_e( 'System & Diagnostics', 'kstnews-ai-autopilot' ); ?>
				</a>
			</nav>

			<!-- Tab 1: Settings Form -->
			<?php if ( 'settings' === $current_tab ) : ?>
				<div class="kstnews-tab-content">
					<form method="post" action="" id="kstnews-settings-form">
						<?php wp_nonce_field( self::NONCE_SETTINGS, 'kstnews_nonce' ); ?>

						<div class="kstnews-section-box">
							<h2>
								<span class="dashicons dashicons-key"></span>
								<?php esc_html_e( 'Gemini AI Configuration', 'kstnews-ai-autopilot' ); ?>
							</h2>
							<p class="description">
								<?php esc_html_e( 'Powered by Gemini 2.5 Flash. Generates structured Bengali news with 300+ words, H2 subheadings, clean English slugs, and meta descriptions.', 'kstnews-ai-autopilot' ); ?>
							</p>

							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">
										<label for="gemini_key"><?php esc_html_e( 'Gemini API Key', 'kstnews-ai-autopilot' ); ?></label>
									</th>
									<td>
										<div class="kstnews-api-key-wrap">
											<input type="password"
												name="kstnews_settings[gemini_key]"
												id="gemini_key"
												value="<?php echo esc_attr( KSTNews_AI_Settings::mask_api_key( $settings['gemini_key'] ) ); ?>"
												class="regular-text"
												autocomplete="new-password"
												placeholder="<?php esc_attr_e( 'Enter your Gemini API key', 'kstnews-ai-autopilot' ); ?>" />
											<button type="button" class="button button-secondary" id="kstnews-toggle-key">
												<?php esc_html_e( 'Show/Change', 'kstnews-ai-autopilot' ); ?>
											</button>
										</div>
										<p class="description">
											<?php
											printf(
												/* translators: %s: Google AI Studio URL */
												esc_html__( 'Obtain a free key at %s. Stored securely and masked.', 'kstnews-ai-autopilot' ),
												'<a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">Google AI Studio</a>'
											);
											?>
										</p>
									</td>
								</tr>
							</table>
						</div>

						<div class="kstnews-section-box">
							<h2>
								<span class="dashicons dashicons-share"></span>
								<?php esc_html_e( 'Telegram Sources & Ingestion', 'kstnews-ai-autopilot' ); ?>
							</h2>

							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">
										<label for="tg_channels"><?php esc_html_e( 'Telegram Channels', 'kstnews-ai-autopilot' ); ?></label>
									</th>
									<td>
										<textarea name="kstnews_settings[channels]"
											id="tg_channels"
											rows="3"
											class="large-text code"
											placeholder="basherkella, channel2"><?php echo esc_textarea( implode( "\n", (array) $settings['channels'] ) ); ?></textarea>
										<p class="description">
											<?php esc_html_e( 'Public Telegram channel username(s), one per line or comma-separated (e.g. basherkella). No @ or https:// needed.', 'kstnews-ai-autopilot' ); ?>
										</p>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="max_per_run"><?php esc_html_e( 'Max Posts Per Run', 'kstnews-ai-autopilot' ); ?></label>
									</th>
									<td>
										<input type="number"
											name="kstnews_settings[max_per_run]"
											id="max_per_run"
											value="<?php echo esc_attr( $settings['max_per_run'] ); ?>"
											min="1"
											max="10"
											class="small-text" />
										<p class="description">
											<?php esc_html_e( 'Default: 3. Keeps execution time safe within shared hosting limits (under 60s).', 'kstnews-ai-autopilot' ); ?>
										</p>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="daily_cap"><?php esc_html_e( 'Daily Post Cap', 'kstnews-ai-autopilot' ); ?></label>
									</th>
									<td>
										<input type="number"
											name="kstnews_settings[daily_cap]"
											id="daily_cap"
											value="<?php echo esc_attr( $settings['daily_cap'] ); ?>"
											min="1"
											max="100"
											class="small-text" />
										<p class="description">
											<?php esc_html_e( 'Maximum articles generated per day across all channels to safeguard Gemini free tier limits and maintain editorial quality.', 'kstnews-ai-autopilot' ); ?>
										</p>
									</td>
								</tr>
							</table>
						</div>

						<div class="kstnews-section-box">
							<h2>
								<span class="dashicons dashicons-admin-post"></span>
								<?php esc_html_e( 'Publishing & Taxonomy', 'kstnews-ai-autopilot' ); ?>
							</h2>

							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">
										<label for="post_status"><?php esc_html_e( 'Default Post Status', 'kstnews-ai-autopilot' ); ?></label>
									</th>
									<td>
										<select name="kstnews_settings[post_status]" id="post_status">
											<option value="draft" <?php selected( $settings['post_status'], 'draft' ); ?>>
												<?php esc_html_e( 'Draft (Recommended for initial verification)', 'kstnews-ai-autopilot' ); ?>
											</option>
											<option value="publish" <?php selected( $settings['post_status'], 'publish' ); ?>>
												<?php esc_html_e( 'Publish Immediately', 'kstnews-ai-autopilot' ); ?>
											</option>
										</select>
										<p class="description">
											<?php esc_html_e( 'Keep as Draft during initial deployment so you can review content quality and safety before going live.', 'kstnews-ai-autopilot' ); ?>
										</p>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="default_category"><?php esc_html_e( 'Default Category', 'kstnews-ai-autopilot' ); ?></label>
									</th>
									<td>
										<?php if ( ! empty( $categories ) ) : ?>
											<select name="kstnews_settings[default_category]" id="default_category">
												<?php foreach ( $categories as $cat ) : ?>
													<option value="<?php echo esc_attr( $cat->name ); ?>" <?php selected( $settings['default_category'], $cat->name ); ?>>
														<?php echo esc_html( $cat->name ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										<?php else : ?>
											<input type="text"
												name="kstnews_settings[default_category]"
												id="default_category"
												value="<?php echo esc_attr( $settings['default_category'] ); ?>"
												class="regular-text" />
										<?php endif; ?>
										<p class="description">
											<?php esc_html_e( 'Fallback category if Gemini does not match an existing taxonomy category.', 'kstnews-ai-autopilot' ); ?>
										</p>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="source_label"><?php esc_html_e( 'Attribution Label', 'kstnews-ai-autopilot' ); ?></label>
									</th>
									<td>
										<input type="text"
											name="kstnews_settings[source_label]"
											id="source_label"
											value="<?php echo esc_attr( $settings['source_label'] ); ?>"
											class="regular-text" />
										<p class="description">
											<?php esc_html_e( 'Appended to the end of articles (e.g. "সূত্র: Telegram"). Preserves journalistic attribution.', 'kstnews-ai-autopilot' ); ?>
										</p>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="embed_video"><?php esc_html_e( 'Embed Video', 'kstnews-ai-autopilot' ); ?></label>
									</th>
									<td>
										<label>
											<input type="checkbox"
												name="kstnews_settings[embed_video]"
												id="embed_video"
												value="1"
												<?php checked( ! empty( $settings['embed_video'] ) ); ?> />
											<?php esc_html_e( 'Embed direct MP4 video link in post body if video is present in Telegram post', 'kstnews-ai-autopilot' ); ?>
										</label>
									</td>
								</tr>
							</table>
						</div>

						<div class="kstnews-section-box">
							<h2>
								<span class="dashicons dashicons-clock"></span>
								<?php esc_html_e( 'Automation & WP-Cron', 'kstnews-ai-autopilot' ); ?>
							</h2>

							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">
										<label for="auto_cron"><?php esc_html_e( 'Enable Auto-Cron', 'kstnews-ai-autopilot' ); ?></label>
									</th>
									<td>
										<label>
											<input type="checkbox"
												name="kstnews_settings[auto_cron]"
												id="auto_cron"
												value="1"
												<?php checked( ! empty( $settings['auto_cron'] ) ); ?> />
											<?php esc_html_e( 'Automatically run news ingestion on schedule', 'kstnews-ai-autopilot' ); ?>
										</label>
									</td>
								</tr>

								<tr>
									<th scope="row">
										<label for="cron_interval"><?php esc_html_e( 'Interval', 'kstnews-ai-autopilot' ); ?></label>
									</th>
									<td>
										<select name="kstnews_settings[interval]" id="cron_interval">
											<option value="fifteen_minutes" <?php selected( $settings['interval'], 'fifteen_minutes' ); ?>>
												<?php esc_html_e( 'Every 15 Minutes', 'kstnews-ai-autopilot' ); ?>
											</option>
											<option value="thirty_minutes" <?php selected( $settings['interval'], 'thirty_minutes' ); ?>>
												<?php esc_html_e( 'Every 30 Minutes (Recommended)', 'kstnews-ai-autopilot' ); ?>
											</option>
											<option value="hourly" <?php selected( $settings['interval'], 'hourly' ); ?>>
												<?php esc_html_e( 'Every Hour', 'kstnews-ai-autopilot' ); ?>
											</option>
										</select>
										<p class="description">
											<?php esc_html_e( 'Note: WP-Cron relies on site visitor traffic. For guaranteed precise execution on low-traffic hours, configure a Hostinger cron job to call wp-cron.php.', 'kstnews-ai-autopilot' ); ?>
										</p>
									</td>
								</tr>
							</table>
						</div>

						<div class="kstnews-section-box">
							<h2>
								<span class="dashicons dashicons-filter"></span>
								<?php esc_html_e( 'Spam & Ad Pre-Filter Keywords', 'kstnews-ai-autopilot' ); ?>
							</h2>
							<p class="description">
								<?php esc_html_e( 'Posts containing any of these keywords will be discarded immediately at zero API cost before reaching Gemini.', 'kstnews-ai-autopilot' ); ?>
							</p>

							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">
										<label for="blocked_keywords"><?php esc_html_e( 'Blocked Keywords', 'kstnews-ai-autopilot' ); ?></label>
									</th>
									<td>
										<textarea name="kstnews_settings[blocked_keywords]"
											id="blocked_keywords"
											rows="6"
											class="large-text"><?php echo esc_textarea( implode( "\n", (array) $settings['blocked_keywords'] ) ); ?></textarea>
										<p class="description">
											<?php esc_html_e( 'One keyword or phrase per line (comma-separated also supported). Matches case-insensitively.', 'kstnews-ai-autopilot' ); ?>
										</p>
									</td>
								</tr>
							</table>
						</div>

						<p class="submit">
							<button type="submit" name="kstnews_save_settings" class="button button-primary button-hero">
								<span class="dashicons dashicons-saved"></span>
								<?php esc_html_e( 'Save Settings', 'kstnews-ai-autopilot' ); ?>
							</button>
						</p>
					</form>
				</div>
			<?php endif; ?>

			<!-- Tab 2: Activity Log -->
			<?php if ( 'logs' === $current_tab ) : ?>
				<div class="kstnews-tab-content">
					<div class="kstnews-log-toolbar">
						<form method="get" action="" class="kstnews-log-filter-form">
							<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
							<input type="hidden" name="tab" value="logs" />
							<label for="status-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'kstnews-ai-autopilot' ); ?></label>
							<select name="status" id="status-filter">
								<option value=""><?php esc_html_e( 'All Statuses', 'kstnews-ai-autopilot' ); ?></option>
								<option value="<?php echo esc_attr( KSTNews_AI_Logger::STATUS_PUBLISHED ); ?>" <?php selected( $status_filter, KSTNews_AI_Logger::STATUS_PUBLISHED ); ?>><?php esc_html_e( 'Published', 'kstnews-ai-autopilot' ); ?></option>
								<option value="<?php echo esc_attr( KSTNews_AI_Logger::STATUS_DRAFT ); ?>" <?php selected( $status_filter, KSTNews_AI_Logger::STATUS_DRAFT ); ?>><?php esc_html_e( 'Draft', 'kstnews-ai-autopilot' ); ?></option>
								<option value="<?php echo esc_attr( KSTNews_AI_Logger::STATUS_BLOCKED_PREFILTER ); ?>" <?php selected( $status_filter, KSTNews_AI_Logger::STATUS_BLOCKED_PREFILTER ); ?>><?php esc_html_e( 'Blocked (Pre-filter)', 'kstnews-ai-autopilot' ); ?></option>
								<option value="<?php echo esc_attr( KSTNews_AI_Logger::STATUS_BLOCKED_AI ); ?>" <?php selected( $status_filter, KSTNews_AI_Logger::STATUS_BLOCKED_AI ); ?>><?php esc_html_e( 'Blocked (AI Gate)', 'kstnews-ai-autopilot' ); ?></option>
								<option value="<?php echo esc_attr( KSTNews_AI_Logger::STATUS_SKIPPED_DUPLICATE ); ?>" <?php selected( $status_filter, KSTNews_AI_Logger::STATUS_SKIPPED_DUPLICATE ); ?>><?php esc_html_e( 'Skipped (Duplicate)', 'kstnews-ai-autopilot' ); ?></option>
								<option value="<?php echo esc_attr( KSTNews_AI_Logger::STATUS_ERROR ); ?>" <?php selected( $status_filter, KSTNews_AI_Logger::STATUS_ERROR ); ?>><?php esc_html_e( 'Error', 'kstnews-ai-autopilot' ); ?></option>
							</select>
							<button type="submit" class="button"><?php esc_html_e( 'Filter', 'kstnews-ai-autopilot' ); ?></button>
						</form>

						<button type="button" class="button button-link-delete" id="kstnews-btn-clear-logs">
							<?php esc_html_e( 'Clear All Logs', 'kstnews-ai-autopilot' ); ?>
						</button>
					</div>

					<table class="wp-list-table widefat fixed striped table-view-list">
						<thead>
							<tr>
								<th style="width: 70px;"><?php esc_html_e( 'ID', 'kstnews-ai-autopilot' ); ?></th>
								<th style="width: 150px;"><?php esc_html_e( 'Date & Time', 'kstnews-ai-autopilot' ); ?></th>
								<th style="width: 140px;"><?php esc_html_e( 'Channel', 'kstnews-ai-autopilot' ); ?></th>
								<th style="width: 110px;"><?php esc_html_e( 'Msg ID', 'kstnews-ai-autopilot' ); ?></th>
								<th style="width: 140px;"><?php esc_html_e( 'Status', 'kstnews-ai-autopilot' ); ?></th>
								<th><?php esc_html_e( 'Reason / Details', 'kstnews-ai-autopilot' ); ?></th>
								<th style="width: 110px;"><?php esc_html_e( 'Post Link', 'kstnews-ai-autopilot' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $logs ) ) : ?>
								<tr>
									<td colspan="7" style="text-align:center; padding: 20px;">
										<?php esc_html_e( 'No log entries found. Run "Fetch & Publish Now" to test the pipeline.', 'kstnews-ai-autopilot' ); ?>
									</td>
								</tr>
							<?php else : ?>
								<?php foreach ( $logs as $item ) : ?>
									<?php
									$badge_class = match ( $item['status'] ) {
										KSTNews_AI_Logger::STATUS_PUBLISHED        => 'kstnews-badge-published',
										KSTNews_AI_Logger::STATUS_DRAFT            => 'kstnews-badge-draft',
										KSTNews_AI_Logger::STATUS_BLOCKED_PREFILTER => 'kstnews-badge-blocked-prefilter',
										KSTNews_AI_Logger::STATUS_BLOCKED_AI       => 'kstnews-badge-blocked-ai',
										KSTNews_AI_Logger::STATUS_SKIPPED_DUPLICATE => 'kstnews-badge-skipped',
										default                                    => 'kstnews-badge-error',
									};
									?>
									<tr>
										<td>#<?php echo (int) $item['id']; ?></td>
										<td><?php echo esc_html( $item['created_at'] ); ?></td>
										<td><strong>@<?php echo esc_html( $item['channel'] ); ?></strong></td>
										<td>
											<?php if ( ! empty( $item['message_id'] ) ) : ?>
												<a href="<?php echo esc_url( 'https://t.me/' . $item['channel'] . '/' . $item['message_id'] ); ?>" target="_blank" rel="noopener noreferrer">
													#<?php echo (int) $item['message_id']; ?>
												</a>
											<?php else : ?>
												-
											<?php endif; ?>
										</td>
										<td>
											<span class="kstnews-badge <?php echo esc_attr( $badge_class ); ?>">
												<?php echo esc_html( $item['status'] ); ?>
											</span>
										</td>
										<td><?php echo esc_html( $item['reason'] ); ?></td>
										<td>
											<?php if ( ! empty( $item['post_id'] ) ) : ?>
												<a href="<?php echo esc_url( get_edit_post_link( (int) $item['post_id'] ) ); ?>" class="button button-small" target="_blank">
													<?php esc_html_e( 'View Post', 'kstnews-ai-autopilot' ); ?>
												</a>
											<?php else : ?>
												-
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

					<!-- Pagination -->
					<?php if ( $total_pages > 1 ) : ?>
						<div class="tablenav bottom">
							<div class="tablenav-pages">
								<span class="displaying-num">
									<?php printf( esc_html__( '%d items', 'kstnews-ai-autopilot' ), (int) $total_logs ); ?>
								</span>
								<span class="pagination-links">
									<?php
									echo paginate_links(
										[
											'base'      => add_query_arg( 'paged', '%#%' ),
											'format'    => '',
											'prev_text' => '&laquo;',
											'next_text' => '&raquo;',
											'total'     => $total_pages,
											'current'   => $paged,
										]
									);
									?>
								</span>
							</div>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<!-- Tab 3: System Status & Diagnostics -->
			<?php if ( 'system' === $current_tab ) : ?>
				<div class="kstnews-tab-content">
					<div class="kstnews-section-box">
						<h2>
							<span class="dashicons dashicons-admin-tools"></span>
							<?php esc_html_e( 'System Environment & Diagnostics', 'kstnews-ai-autopilot' ); ?>
						</h2>

						<table class="widefat striped" style="margin-top: 15px;">
							<tbody>
								<tr>
									<td style="width: 250px;"><strong><?php esc_html_e( 'PHP Version', 'kstnews-ai-autopilot' ); ?></strong></td>
									<td>
										<?php echo esc_html( PHP_VERSION ); ?>
										<?php if ( version_compare( PHP_VERSION, '8.0', '>=' ) ) : ?>
											<span class="kstnews-badge kstnews-badge-published"><?php esc_html_e( 'Compatible', 'kstnews-ai-autopilot' ); ?></span>
										<?php else : ?>
											<span class="kstnews-badge kstnews-badge-error"><?php esc_html_e( 'PHP 8.0+ Required', 'kstnews-ai-autopilot' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'PHP GD Extension (1200x630 Resizing)', 'kstnews-ai-autopilot' ); ?></strong></td>
									<td>
										<?php if ( extension_loaded( 'gd' ) ) : ?>
											<span class="kstnews-badge kstnews-badge-published"><?php esc_html_e( 'Loaded & Active', 'kstnews-ai-autopilot' ); ?></span>
										<?php else : ?>
											<span class="kstnews-badge kstnews-badge-blocked-ai"><?php esc_html_e( 'Missing - Fallback Mode (Uncropped uploads)', 'kstnews-ai-autopilot' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'DOMDocument & DOMXPath (Telegram Parsing)', 'kstnews-ai-autopilot' ); ?></strong></td>
									<td>
										<?php if ( class_exists( 'DOMDocument' ) ) : ?>
											<span class="kstnews-badge kstnews-badge-published"><?php esc_html_e( 'Available', 'kstnews-ai-autopilot' ); ?></span>
										<?php else : ?>
											<span class="kstnews-badge kstnews-badge-error"><?php esc_html_e( 'Missing php-xml / DOMDocument', 'kstnews-ai-autopilot' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Active Run Lock Status', 'kstnews-ai-autopilot' ); ?></strong></td>
									<td>
										<?php
										$lock = get_transient( 'kstnews_ai_run_lock' );
										if ( $lock ) :
											?>
											<span class="kstnews-badge kstnews-badge-blocked-prefilter">
												<?php printf( esc_html__( 'Locked (PID/Time: %s)', 'kstnews-ai-autopilot' ), esc_html( $lock ) ); ?>
											</span>
										<?php else : ?>
											<span class="kstnews-badge kstnews-badge-draft"><?php esc_html_e( 'Idle / Free', 'kstnews-ai-autopilot' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Tracked Channels & Last IDs', 'kstnews-ai-autopilot' ); ?></strong></td>
									<td>
										<pre style="margin: 0; background: #fff; padding: 10px; border: 1px solid #ddd; max-height: 200px; overflow: auto;"><?php
											echo esc_html( wp_json_encode( $state['last_message_id'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
										?></pre>
									</td>
								</tr>
								<tr>
									<td><strong><?php esc_html_e( 'Processed IDs Count (Rolling Max 500)', 'kstnews-ai-autopilot' ); ?></strong></td>
									<td>
										<?php echo count( (array) ( $state['processed_ids'] ?? [] ) ); ?> / 500
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
