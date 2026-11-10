<?php
/**
 * Plugin Name: KSTNews24 AI Telegram AutoPilot (OpenRouter Edition)
 * Plugin URI:  https://kstnews24.com
 * Description: Fetches public Telegram news & media directly, rewrites articles in Bengali using OpenRouter Free Models (nvidia/nemotron-3.5-super-vl:free), resizes Telegram images to 1200x630 via PHP GD, and publishes to WordPress.
 * Version:     2.0.1
 * Author:      KSTNews24
 * Text Domain: kstnews-ai-autopilot
 */

if (!defined('ABSPATH')) {
    exit;
}

class KSTNews_AI_AutoPilot {
    private string $option_key = 'kstnews_ai_or_settings';
    private string $log_key    = 'kstnews_ai_logs';
    private string $state_key  = 'kstnews_ai_last_msg_id';

    private array $defaults = [
        'or_api_key'   => '',
        'or_model'     => 'google/gemma-4-31b-it:free',
        'tg_channel'   => 'basherkella',
        'post_status'  => 'draft',
        'auto_cron'    => 1,
        'daily_limit'  => 150,
        'author_name'  => 'ওপেন সোর্স প্রতিনিধি',
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_form_actions']);
        add_action('init', [$this, 'ensure_cron_scheduled']);
        add_action('init', [$this, 'handle_worker_request']);
        add_action('init', [$this, 'heartbeat_cron_check']);
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
        add_action('kstnews_ai_cron_hook', [$this, 'run_cron_job']);

        // Completely disable internal self-pingbacks and pingback comments
        add_action('pre_ping', [$this, 'disable_self_pingbacks']);
        add_filter('preprocess_comment', [$this, 'block_internal_pingbacks']);

        add_action('wp_ajax_kst_run_pipeline_ajax', [$this, 'ajax_run_pipeline']);
        add_action('wp_ajax_kst_test_api_ajax', [$this, 'ajax_test_api']);
    }

    public function run_cron_job(): void {
        $this->run_pipeline(false);
    }

    public function handle_worker_request(): void {
        if (isset($_GET['kstnews_auto_worker'])) {
            // Bypass page caching plugins (LiteSpeed Cache, Cloudflare, etc.)
            nocache_headers();
            if (function_exists('set_time_limit')) {
                @set_time_limit(180);
            }
            $result = $this->run_pipeline(false);
            delete_transient('kstnews_ai_cron_lock');
            header('Content-Type: application/json; charset=utf-8');
            echo wp_json_encode($result);
            exit;
        }
    }

    public function heartbeat_cron_check(): void {
        $settings = $this->get_settings();
        if (empty($settings['auto_cron'])) {
            return;
        }

        if (wp_doing_ajax() || wp_doing_cron() || isset($_GET['kstnews_auto_worker'])) {
            return;
        }

        $last_run = (int) get_option('kstnews_ai_last_cron_run', 0);
        // If 10 minutes (600s) have passed since last automated run
        if ((time() - $last_run) >= 600) {
            if (!get_transient('kstnews_ai_cron_lock')) {
                set_transient('kstnews_ai_cron_lock', 1, 180);
                
                // Spawn async non-blocking execution to worker endpoint
                $url = add_query_arg('kstnews_auto_worker', '1', home_url('/'));
                wp_remote_get($url, [
                    'timeout'   => 2,
                    'blocking'  => false,
                    'sslverify' => false,
                ]);
            }
        }
    }

    public function get_24h_post_count(): int {
        $history = get_option('kstnews_ai_24h_history', []);
        if (!is_array($history)) {
            $history = [];
        }
        $cutoff = time() - 86400; // 24 hours ago
        $active = array_values(array_filter($history, fn($t) => is_numeric($t) && (int) $t > $cutoff));
        if (count($active) !== count($history)) {
            update_option('kstnews_ai_24h_history', $active);
        }
        return count($active);
    }

    private function record_published_post_timestamp(): void {
        $history = get_option('kstnews_ai_24h_history', []);
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = time();
        $cutoff    = time() - 86400;
        $history   = array_values(array_filter($history, fn($t) => is_numeric($t) && (int) $t > $cutoff));
        update_option('kstnews_ai_24h_history', $history);
    }

    public function get_or_create_ai_author_id(string $name = 'ওপেন সোর্স প্রতিনিধি'): int {
        $name = trim($name) ?: 'ওপেন সোর্স প্রতিনিধি';

        // 1. Check if user exists by dedicated login
        $user = get_user_by('login', 'opensource_reporter');
        if ($user) {
            if ($user->display_name !== $name) {
                wp_update_user([
                    'ID'           => $user->ID,
                    'display_name' => $name,
                    'nickname'     => $name,
                ]);
            }
            return (int) $user->ID;
        }

        // 2. Search existing users by display name or login directly
        $all_users = get_users(['number' => 100]);
        foreach ($all_users as $u) {
            if (trim($u->display_name) === $name || trim($u->user_login) === $name) {
                return (int) $u->ID;
            }
        }

        // 3. Create dedicated author user
        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'kstnews24.com';
        $user_id = wp_insert_user([
            'user_login'   => 'opensource_reporter',
            'display_name' => $name,
            'nickname'     => $name,
            'first_name'   => 'ওপেন সোর্স',
            'last_name'    => 'প্রতিনিধি',
            'user_pass'    => wp_generate_password(24, true, true),
            'user_email'   => 'opensource@' . $host,
            'role'         => 'author',
        ]);

        if (!is_wp_error($user_id)) {
            return (int) $user_id;
        }

        return get_current_user_id() ?: 1;
    }

    public function get_free_models_list(): array {
        return [
            'google/gemma-4-31b-it:free'                   => 'Google: Gemma 4 31B (Free - Recommended)',
            'google/gemma-4-26b-a4b-it:free'               => 'Google: Gemma 4 26B A4B (Free)',
            'nvidia/nemotron-3.5-lightning:free'           => 'NVIDIA: Nemotron 3.5 Lightning (Free - Fast)',
            'nvidia/nemotron-3-ultra-550b-a55b:free'       => 'NVIDIA: Nemotron 3 Ultra 550B (Free)',
            'nvidia/nemotron-3.5-super-vl:free'            => 'NVIDIA: Nemotron 3.5 Super VL (Free)',
            'dots-studio/dots-3-note-preview:free'         => 'Dots Studio: Dots3-Note Preview (Free)',
            'liquid/lfm-2.5-2.6b:free'                     => 'LiquidAI: LFM 2.5-2.6B (Free)',
            'liquid/lfm-3-1.7b:free'                       => 'LiquidAI: LFM 3-1.7B (Free)',
            'thinkingmachines/inkling-2-small:free'        => 'Thinking Machines: Inkling 2 Small (Free)',
            'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free' => 'NVIDIA: Nemotron 3 Nano Omni (Free)',
            'openrouter/free'                              => 'OpenRouter: Free Auto-Router (Free)',
        ];
    }

    public function disable_self_pingbacks(&$links): void {
        $home = home_url();
        foreach ($links as $l => $link) {
            if (strpos($link, $home) === 0) {
                unset($links[$l]);
            }
        }
    }

    public function block_internal_pingbacks(array $commentdata): array {
        if (!empty($commentdata['comment_type']) && in_array($commentdata['comment_type'], ['pingback', 'trackback'], true)) {
            $home = home_url();
            $author_url = $commentdata['comment_author_url'] ?? '';
            if (!empty($author_url) && strpos($author_url, $home) === 0) {
                wp_die(__('Internal self-pingbacks disabled.', 'kstnews-ai-autopilot'), '', ['response' => 200]);
            }
        }
        return $commentdata;
    }

    private function is_post_already_published(int $msg_id): bool {
        if ($msg_id <= 0) {
            return false;
        }

        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_kst_telegram_msg_id' AND meta_value = %s LIMIT 1",
            (string) $msg_id
        ));
        if (!empty($exists)) {
            return true;
        }

        $logs = get_option($this->log_key, []);
        if (is_array($logs)) {
            foreach ($logs as $log) {
                if (!empty($log['msg_id']) && (int) $log['msg_id'] === $msg_id && !empty($log['status']) && $log['status'] === 'Published') {
                    return true;
                }
            }
        }

        return false;
    }

    public function ensure_cron_scheduled(): void {
        $settings = $this->get_settings();
        if (!empty($settings['auto_cron'])) {
            if (!wp_next_scheduled('kstnews_ai_cron_hook')) {
                wp_schedule_event(time() + 60, 'every_ten_minutes', 'kstnews_ai_cron_hook');
            }
        } else {
            if (wp_next_scheduled('kstnews_ai_cron_hook')) {
                wp_clear_scheduled_hook('kstnews_ai_cron_hook');
            }
        }
    }

    public static function on_activate(): void {
        if (!wp_next_scheduled('kstnews_ai_cron_hook')) {
            wp_schedule_event(time() + 60, 'every_ten_minutes', 'kstnews_ai_cron_hook');
        }
    }

    public static function on_deactivate(): void {
        wp_clear_scheduled_hook('kstnews_ai_cron_hook');
    }

    private function get_settings(): array {
        $saved = get_option($this->option_key, []);
        return wp_parse_args($saved, $this->defaults);
    }

    public function add_cron_interval(array $schedules): array {
        $schedules['every_ten_minutes'] = [
            'interval' => 600,
            'display'  => __('Every 10 Minutes', 'kstnews-ai-autopilot'),
        ];
        return $schedules;
    }

    public function register_admin_menu(): void {
        add_menu_page(
            'KSTNews AI Bot',
            'KSTNews AI Bot',
            'manage_options',
            'kstnews-ai-bot',
            [$this, 'render_admin_ui'],
            'dashicons-megaphone',
            25
        );
    }

    public function handle_form_actions(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['kst_save_settings']) && check_admin_referer('kst_ai_nonce')) {
            $settings = [
                'or_api_key'   => sanitize_text_field($_POST['or_api_key'] ?? ''),
                'or_model'     => sanitize_text_field($_POST['or_model'] ?? 'google/gemma-4-31b-it:free'),
                'tg_channel'   => sanitize_text_field($_POST['tg_channel'] ?? 'basherkella'),
                'post_status'  => sanitize_text_field($_POST['post_status'] ?? 'draft'),
                'auto_cron'    => isset($_POST['auto_cron']) ? 1 : 0,
                'daily_limit'  => max(1, absint($_POST['daily_limit'] ?? 150)),
                'author_name'  => sanitize_text_field($_POST['author_name'] ?? 'ওপেন সোর্স প্রতিনিধি'),
            ];
            update_option($this->option_key, $settings);

            wp_clear_scheduled_hook('kstnews_ai_cron_hook');
            if (!empty($settings['auto_cron'])) {
                wp_schedule_event(time(), 'every_ten_minutes', 'kstnews_ai_cron_hook');
            }
            add_settings_error('kst_msg', 'saved', 'OpenRouter settings saved successfully!', 'updated');
        }

        if (isset($_POST['kst_reset_state']) && check_admin_referer('kst_ai_nonce')) {
            delete_option($this->state_key);
            update_option($this->log_key, []);
            add_settings_error('kst_msg', 'reset', 'State and activity logs reset successfully.', 'updated');
        }
    }

    public function ajax_test_api(): void {
        check_ajax_referer('kst_ai_ajax_nonce', 'security');
        if (!current_user_can('manage_options')) {
            if (ob_get_length()) ob_clean();
            wp_send_json_error(['message' => 'Unauthorized user.']);
        }

        $settings = $this->get_settings();
        $api_key  = trim($_POST['or_api_key'] ?? $settings['or_api_key']);
        $model    = trim($_POST['or_model'] ?? $settings['or_model']);

        if (empty($api_key)) {
            if (ob_get_length()) ob_clean();
            wp_send_json_error(['message' => 'Please enter an OpenRouter API Key first.']);
        }

        $start    = microtime(true);
        // Test the selected model specifically without silent cascade
        $res      = $this->call_openrouter_raw($api_key, $model, 'Reply with the single word: OK', 500, 25, false);
        $duration = round(microtime(true) - $start, 2);

        if (ob_get_length()) {
            ob_clean();
        }

        if (isset($res['error'])) {
            wp_send_json_error(['message' => $res['error'] . " ({$duration}s)"]);
        }

        $reply      = trim($res['text'] ?? 'OK');
        $used_model = $res['model'] ?? $model;
        wp_send_json_success([
            'message'  => "Connected to OpenRouter ({$used_model}) in {$duration}s! Reply: " . esc_html(mb_substr($reply, 0, 40)),
            'duration' => $duration,
        ]);
    }

    public function ajax_run_pipeline(): void {
        check_ajax_referer('kst_ai_ajax_nonce', 'security');
        if (!current_user_can('manage_options')) {
            if (ob_get_length()) ob_clean();
            wp_send_json_error(['message' => 'Unauthorized user.']);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $result = $this->run_pipeline(true);

        if (ob_get_length()) {
            ob_clean();
        }

        wp_send_json_success($result);
    }

    private function add_log(int $msg_id, string $status, string $details): void {
        $logs = get_option($this->log_key, []);
        array_unshift($logs, [
            'time'    => current_time('Y-m-d H:i:s'),
            'msg_id'  => $msg_id,
            'status'  => $status,
            'details' => $details,
        ]);
        update_option($this->log_key, array_slice($logs, 0, 30));
    }

    public function run_pipeline(bool $is_manual = false): array {
        // Prevent concurrent overlapping runs
        if (!$is_manual) {
            if (get_transient('kstnews_ai_pipeline_lock')) {
                return ['status' => 'locked', 'message' => 'Pipeline is already running in another process.'];
            }
            set_transient('kstnews_ai_pipeline_lock', 1, 180);
        }

        update_option('kstnews_ai_last_cron_run', time());

        $settings = $this->get_settings();
        $api_key  = trim($settings['or_api_key']);
        $model    = trim($settings['or_model']);
        $channel  = trim($settings['tg_channel'], '@/ ');
        $status   = $settings['post_status'];

        // Enforce daily post limit (default: 150 posts in rolling 24-hour window)
        $daily_limit = (int) ($settings['daily_limit'] ?? 150);
        $count_24h   = $this->get_24h_post_count();
        if ($count_24h >= $daily_limit) {
            if (!$is_manual) {
                delete_transient('kstnews_ai_pipeline_lock');
            }
            $limit_msg = "Daily post limit of {$daily_limit} posts reached ({$count_24h} published in last 24h). Autopilot paused.";
            return [
                'status'  => 'limit_reached',
                'message' => $limit_msg,
            ];
        }

        if (empty($api_key)) {
            if (!$is_manual) delete_transient('kstnews_ai_pipeline_lock');
            return ['status' => 'error', 'message' => 'OpenRouter API Key is missing.'];
        }

        $url = "https://t.me/s/" . urlencode($channel);
        $response = wp_remote_get($url, [
            'timeout'    => 20,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        if (is_wp_error($response)) {
            if (!$is_manual) delete_transient('kstnews_ai_pipeline_lock');
            return ['status' => 'error', 'message' => 'Telegram connection error: ' . $response->get_error_message()];
        }

        $html  = wp_remote_retrieve_body($response);
        $posts = $this->parse_telegram_html($html);

        if (empty($posts)) {
            if (!$is_manual) delete_transient('kstnews_ai_pipeline_lock');
            return ['status' => 'error', 'message' => 'No public posts found on t.me/s/' . esc_html($channel)];
        }

        // Sort posts ascending by ID (oldest to newest) to process chronologically
        usort($posts, fn($a, $b) => $a['id'] <=> $b['id']);

        $last_id = (int) get_option($this->state_key, 0);

        // Find unposted candidate posts (ID > last_id AND not already published in WordPress)
        $candidates = [];
        foreach ($posts as $p) {
            if ($p['id'] > $last_id && !$this->is_post_already_published($p['id'])) {
                $candidates[] = $p;
            }
        }

        // If no newer post found, check if any post in the fetched batch hasn't been published yet
        if (empty($candidates)) {
            foreach ($posts as $p) {
                if (!$this->is_post_already_published($p['id'])) {
                    $candidates[] = $p;
                }
            }
        }

        // If all fetched posts are already published, channel is 100% up to date
        if (empty($candidates)) {
            $latest_channel_id = end($posts)['id'] ?? $last_id;
            update_option($this->state_key, max($last_id, $latest_channel_id));
            if (!$is_manual) delete_transient('kstnews_ai_pipeline_lock');
            return [
                'status'  => 'info',
                'message' => "All recent Telegram posts on @{$channel} have already been published in WordPress! Waiting for new channel updates (Latest: #{$latest_channel_id}).",
            ];
        }

        // Take the earliest unposted message
        $tg_post = $candidates[0];
        $msg_id  = $tg_post['id'];
        $published_posts = [];

        if (mb_strlen($tg_post['text']) < 15) {
            $this->add_log($msg_id, 'Skipped', 'Text too short for news article.');
            update_option($this->state_key, max($last_id, $msg_id));
            if (!$is_manual) delete_transient('kstnews_ai_pipeline_lock');
            return ['status' => 'skipped', 'message' => "Post #{$msg_id} was skipped (text too short)."];
        }

        if ($this->is_spam_or_ad($tg_post['text'])) {
            $this->add_log($msg_id, 'Blocked Ad', 'Matched promo/spam keyword filter.');
            update_option($this->state_key, max($last_id, $msg_id));
            if (!$is_manual) delete_transient('kstnews_ai_pipeline_lock');
            return ['status' => 'blocked', 'message' => "Post #{$msg_id} was blocked as an advertisement."];
        }

        $news = $this->polish_with_openrouter($tg_post['text'], $api_key, $model);
        if (!$news || isset($news['error'])) {
            $err = $news['error'] ?? 'Invalid AI response';
            $this->add_log($msg_id, 'AI Error', $err);
            if (!$is_manual) delete_transient('kstnews_ai_pipeline_lock');
            return ['status' => 'error', 'message' => "AI Error on #{$msg_id}: " . $err];
        }

        if (empty($news['isGenuineNews']) || empty($news['isAdSenseSafe'])) {
            $reason = $news['skipReason'] ?? 'Non-news or AdSense policy violation';
            $this->add_log($msg_id, 'Rejected by AI', $reason);
            update_option($this->state_key, max($last_id, $msg_id));
            if (!$is_manual) delete_transient('kstnews_ai_pipeline_lock');
            return ['status' => 'rejected', 'message' => "Post #{$msg_id} rejected by AI: " . $reason];
        }

        $uploaded_images = [];
        foreach ($tg_post['images'] as $idx => $img_url) {
            $is_featured = ($idx === 0);
            $att_id = $this->download_and_resize_image($img_url, $news['title'], $is_featured);
            if ($att_id) {
                $uploaded_images[] = [
                    'id'  => $att_id,
                    'url' => wp_get_attachment_url($att_id),
                ];
            }
        }

        $featured_id = !empty($uploaded_images)
            ? $uploaded_images[0]['id']
            : $this->generate_fallback_banner($news['title']);

        $cat_ids = [];
        foreach (($news['categories'] ?? ['জাতীয়']) as $cat_name) {
            $term = term_exists($cat_name, 'category');
            if (!$term) {
                $term = wp_insert_term($cat_name, 'category');
            }
            if (!is_wp_error($term)) {
                $cat_ids[] = (int) $term['term_id'];
            }
        }

        $content = '<p><strong>ক্যাটাগরি:</strong> ' . esc_html(implode(' / ', $news['categories'] ?? ['জাতীয়'])) . '</p>';
        $content .= '<h3><strong>বিস্তারিত সংবাদ:</strong></h3>';
        $content .= $news['articleHtml'];

        if (!empty($tg_post['video'])) {
            $content .= '<figure class="wp-block-video"><video controls src="' . esc_url($tg_post['video']) . '" style="max-width:100%;height:auto;"></video></figure>';
        }

        if (count($uploaded_images) > 1) {
            for ($i = 1; $i < count($uploaded_images); $i++) {
                $content .= '<figure class="wp-block-image size-large"><img src="' . esc_url($uploaded_images[$i]['url']) . '" alt="' . esc_attr($news['title']) . '"/></figure>';
            }
        }

        $related_html = !empty($cat_ids) ? $this->get_related_posts_html($cat_ids[0]) : '';
        if ($related_html) {
            $content .= $related_html;
        }

        // Clean, validate, and ensure 5-8 rich SEO tags
        $raw_tags = is_array($news['tags'] ?? null) ? $news['tags'] : [];
        $cleaned_tags = [];
        foreach ($raw_tags as $t) {
            $t = trim(strip_tags((string) $t));
            $t = ltrim($t, '#');
            if ($t !== '' && mb_strlen($t) >= 2 && mb_strlen($t) <= 60) {
                $cleaned_tags[] = $t;
            }
        }

        // Fallback: if AI provided fewer than 3 tags, augment with categories
        if (count($cleaned_tags) < 3 && !empty($news['categories'])) {
            foreach ((array) $news['categories'] as $cat) {
                $cleaned_tags[] = trim($cat);
            }
        }

        if (!in_array('kstnews24', $cleaned_tags, true)) {
            $cleaned_tags[] = 'kstnews24';
        }
        $tags = array_values(array_unique($cleaned_tags));

        $content .= '<hr/><p><strong>ট্যাগসমূহ:</strong><br/>' . esc_html(implode(', ', $tags)) . '</p>';

        $author_name = !empty($settings['author_name']) ? $settings['author_name'] : 'ওপেন সোর্স প্রতিনিধি';
        $author_id   = $this->get_or_create_ai_author_id($author_name);

        $post_id = wp_insert_post([
            'post_title'     => wp_strip_all_tags($news['title']),
            'post_name'      => sanitize_title($news['englishSlug'] ?? $news['title']),
            'post_excerpt'   => sanitize_text_field($news['metaDescription'] ?? ''),
            'post_content'   => $content,
            'post_status'    => $status,
            'post_author'    => $author_id,
            'post_category'  => $cat_ids,
            'tags_input'     => $tags,
            'ping_status'    => 'closed',
            'comment_status' => 'closed',
        ]);

        if ($post_id && !is_wp_error($post_id)) {
            // Explicitly assign tags using wp_set_post_tags to bypass unauthenticated cron context permissions
            wp_set_post_tags($post_id, $tags, false);

            if ($featured_id) {
                set_post_thumbnail($post_id, $featured_id);
            }

            update_post_meta($post_id, '_kst_telegram_msg_id', $msg_id);
            update_option($this->state_key, max($last_id, $msg_id));
            $this->record_published_post_timestamp();

            $used_model = $news['used_model'] ?? $model;
            $model_note = ($used_model !== $model) ? " (fallback: " . esc_html($used_model) . ")" : "";

            $permalink = get_permalink($post_id);
            $this->add_log($msg_id, 'Published' . $model_note, '<a href="' . esc_url($permalink) . '" target="_blank">' . esc_html($news['title']) . '</a>');
            $published_posts[] = [
                'id'    => $post_id,
                'title' => $news['title'],
                'link'  => $permalink,
                'msg'   => $msg_id,
                'model' => $used_model,
            ];
        }

        if (!$is_manual) {
            delete_transient('kstnews_ai_pipeline_lock');
        }

        $used_model  = $news['used_model'] ?? $model;
        $success_msg = 'Article generated and saved successfully via OpenRouter!' . (($used_model !== $model) ? " (Used fallback model: {$used_model})" : "");

        return [
            'status'    => 'success',
            'message'   => $success_msg,
            'published' => $published_posts,
            'last_id'   => max($last_id, $msg_id ?? $last_id),
        ];
    }

    private function call_openrouter_raw(string $api_key, string $model, string $prompt, int $max_tokens = 2200, int $timeout = 45, bool $allow_fallback = true): array {
        $clean_model = trim($model) ?: 'google/gemma-4-31b-it:free';
        $endpoint    = 'https://openrouter.ai/api/v1/chat/completions';

        if ($allow_fallback) {
            $free_pool = array_keys($this->get_free_models_list());
            $free_pool = array_filter($free_pool, fn($m) => $m !== 'custom');
            $candidate_models = array_values(array_filter(array_unique(array_merge(
                [$clean_model],
                $free_pool
            ))));
        } else {
            $candidate_models = [$clean_model];
        }

        $last_error = 'Empty output from all candidate models.';

        foreach ($candidate_models as $try_model) {
            // If another candidate model was rate-limited in the past 90 seconds, skip to a fresh one
            if ($try_model !== $clean_model && get_transient('kst_rl_' . md5($try_model))) {
                continue;
            }

            $payload = [
                'model'       => $try_model,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => 'You are a professional Bengali news editor API that outputs only valid raw JSON objects without markdown formatting or explanations.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.25,
                'max_tokens'  => $max_tokens,
                // Limit reasoning tokens so reasoning models do not exhaust the total max_tokens budget
                'reasoning'   => [
                    'max_tokens' => 350,
                ],
            ];

            $json_payload = wp_json_encode($payload);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $endpoint,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json_payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json; charset=utf-8',
                    'Authorization: Bearer ' . trim($api_key),
                    'HTTP-Referer: ' . home_url('/'),
                    'X-Title: KSTNews24 AI AutoPilot',
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);

            $raw_body   = curl_exec($ch);
            $curl_errno = curl_errno($ch);
            $curl_error = curl_error($ch);
            $http_code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curl_errno !== 0) {
                $last_error = "cURL error ({$curl_errno}) on {$try_model}: {$curl_error}";
                continue;
            }

            $data = json_decode($raw_body, true);

            if ($http_code !== 200 || isset($data['error']) || isset($data['choices'][0]['error'])) {
                $err_msg = $data['error']['message']
                    ?? $data['choices'][0]['error']['message']
                    ?? ($data['error'] ?? "HTTP {$http_code}");
                if (is_array($err_msg)) {
                    $err_msg = wp_json_encode($err_msg);
                }
                $last_error = "OpenRouter ({$try_model} - {$http_code}): {$err_msg}";

                // If rate limited or quota exceeded, temporarily cool down this model for 90 seconds
                if ($http_code === 429 || $http_code === 402 || stripos((string)$err_msg, 'rate limit') !== false || stripos((string)$err_msg, 'quota') !== false || stripos((string)$err_msg, 'exceeded') !== false) {
                    set_transient('kst_rl_' . md5($try_model), 1, 90);
                }
                continue;
            }

            $msg_obj = $data['choices'][0]['message'] ?? [];
            $text    = trim((string) ($msg_obj['content'] ?? ''));

            if ($text === '') {
                $text = trim((string) ($data['output_text'] ?? ($data['output'][0]['content'][0]['text'] ?? '')));
            }

            // Fallback: extract JSON from reasoning chain if model put answer there
            if ($text === '') {
                $reasoning_candidates = [];
                if (!empty($msg_obj['reasoning'])) {
                    $reasoning_candidates[] = (string) $msg_obj['reasoning'];
                }
                if (!empty($msg_obj['reasoning_details'])) {
                    foreach ((array) $msg_obj['reasoning_details'] as $rd) {
                        if (!empty($rd['text'])) {
                            $reasoning_candidates[] = (string) $rd['text'];
                        }
                    }
                }
                foreach ($reasoning_candidates as $rc) {
                    $rc = trim($rc);
                    if (strpos($rc, '{') !== false && strpos($rc, '}') !== false) {
                        $text = $rc;
                        break;
                    }
                }
            }

            if ($text !== '') {
                return [
                    'text'  => $text,
                    'model' => $data['model'] ?? $try_model,
                ];
            }

            $finish_reason = $data['choices'][0]['finish_reason'] ?? 'unknown';
            $last_error    = "Empty output from {$try_model} (finish_reason: {$finish_reason})";
            if ($finish_reason === 'length') {
                set_transient('kst_rl_' . md5($try_model), 1, 60);
            }
        }

        return ['error' => $last_error];
    }

    private function polish_with_openrouter(string $raw_text, string $api_key, string $model): ?array {
        $safe_text = mb_substr(trim($raw_text), 0, 3500);

        $prompt = "You are the Senior Bengali News Editor for kstnews24.com.\n"
            . "Read the following raw Telegram post and rewrite it into a real, professional 250-300 word Bengali news article.\n\n"
            . "EDITORIAL RULES:\n"
            . "1. Write the actual news based on the post. Do NOT output placeholder text like 'আকর্ষণীয় বাংলা শিরোনাম' or 'ঢাকা: ...'.\n"
            . "2. The raw post may contain anti-censorship apostrophes (e.g. আ'গুন, দু'র্বৃ'ত্তরা). Normalize these words into correct standard Bengali (e.g. আগুন, দুর্বৃত্তরা).\n"
            . "3. If the post is an advertisement, promotion, betting/gambling link, or spam: set isGenuineNews to false and provide skipReason.\n"
            . "4. If the post contains graphic blood, dead bodies, or severe hate speech violating Google AdSense policies: set isAdSenseSafe to false and provide skipReason.\n"
            . "5. Otherwise, set isGenuineNews to true, isAdSenseSafe to true, and generate the full news article.\n"
            . "6. TAGS REQUIREMENT: Generate 5 to 8 specific, highly relevant Bengali tags for SEO (covering mentioned people, organizations, political parties, locations, and topic keywords). Always include 'kstnews24' as one of the tags.\n\n"
            . "RAW TELEGRAM POST TO REWRITE:\n\"\"\"\n" . $safe_text . "\n\"\"\"\n\n"
            . "OUTPUT FORMAT REQUIREMENT:\n"
            . "Return ONLY one valid raw JSON object. Do not wrap in markdown fences or quotes. Keep all HTML tags inside articleHtml on a single escaped line with double quotes escaped as \\\" or use single quotes for HTML attributes.\n"
            . "Format:\n"
            . "{\n"
            . "  \"isGenuineNews\": true,\n"
            . "  \"isAdSenseSafe\": true,\n"
            . "  \"skipReason\": \"\",\n"
            . "  \"title\": \"Specific Bengali headline based on post\",\n"
            . "  \"englishSlug\": \"3-5-word-english-slug\",\n"
            . "  \"metaDescription\": \"130-150 character Bengali meta summary\",\n"
            . "  \"categories\": [\"জাতীয়\"],\n"
            . "  \"articleHtml\": \"<p>ঢাকা: [Full Bengali news content...]</p><h2>[Subheading 1]</h2><p>[Paragraph...]</p><h2>[Subheading 2]</h2><p>[Paragraph...]</p>\",\n"
            . "  \"tags\": [\"ঢাকা\", \"বাংলাদেশ\", \"ঘটনা\", \"প্রশাসন\", \"জাতীয় সংবাদ\", \"kstnews24\"]\n"
            . "}";

        $res = $this->call_openrouter_raw($api_key, $model, $prompt, 2200, 45, true);
        if (isset($res['error'])) {
            return ['error' => $res['error']];
        }

        $parsed = $this->extract_clean_json($res['text']);

        if (!$parsed) {
            return ['error' => 'AI returned invalid JSON formatting. Try again or check post text.'];
        }

        // If flagged as not genuine news or not AdSense safe, return cleanly for proper logging
        if ((isset($parsed['isGenuineNews']) && empty($parsed['isGenuineNews'])) ||
            (isset($parsed['isAdSenseSafe']) && empty($parsed['isAdSenseSafe']))) {
            return $parsed;
        }

        if (empty($parsed['title']) || $parsed['title'] === 'আকর্ষণীয় বাংলা শিরোনাম') {
            return ['error' => 'AI returned placeholder or empty title. Try again or check post text.'];
        }

        $parsed['used_model'] = $res['model'] ?? $model;
        return $parsed;
    }

    private function extract_clean_json(string $raw): ?array {
        // 1. Direct decode attempt
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded) && (isset($decoded['isGenuineNews']) || isset($decoded['title']))) {
            return $decoded;
        }

        // 2. Strip think tags and code blocks
        $cleaned = preg_replace('/<think>.*?<\/think>/is', '', $raw);
        $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/im', '', trim($cleaned));
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);
        if (is_array($decoded) && (isset($decoded['isGenuineNews']) || isset($decoded['title']))) {
            return $decoded;
        }

        // 3. Scan all candidate '{' positions
        $len = strlen($cleaned);
        $brace_positions = [];
        for ($i = 0; $i < $len; $i++) {
            if ($cleaned[$i] === '{') {
                $brace_positions[] = $i;
            }
        }

        $last_brace = strrpos($cleaned, '}');
        if ($last_brace === false || empty($brace_positions)) {
            return null;
        }

        // Check each { starting point against last_brace (in reverse order first)
        $reversed = array_reverse($brace_positions);
        foreach ($reversed as $start) {
            if ($start >= $last_brace) continue;
            $candidate = substr($cleaned, $start, $last_brace - $start + 1);

            $p = json_decode($candidate, true);
            if (is_array($p) && (isset($p['isGenuineNews']) || isset($p['title']))) {
                return $p;
            }

            // Sanitized control characters (newlines, tabs inside unescaped strings)
            $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $candidate);
            $p = json_decode($sanitized, true);
            if (is_array($p) && (isset($p['isGenuineNews']) || isset($p['title']))) {
                return $p;
            }
        }

        // 4. Also check balanced brace pairs
        foreach ($brace_positions as $start) {
            $depth = 0;
            for ($j = $start; $j < $len; $j++) {
                if ($cleaned[$j] === '{') {
                    $depth++;
                } elseif ($cleaned[$j] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $candidate = substr($cleaned, $start, $j - $start + 1);
                        $p = json_decode($candidate, true);
                        if (is_array($p) && (isset($p['isGenuineNews']) || isset($p['title']))) {
                            return $p;
                        }
                        $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $candidate);
                        $p = json_decode($sanitized, true);
                        if (is_array($p) && (isset($p['isGenuineNews']) || isset($p['title']))) {
                            return $p;
                        }
                        break;
                    }
                }
            }
        }

        return null;
    }
    
    private function parse_telegram_html(string $html): array {
        $results = [];
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);

        $nodes = $xpath->query("//div[contains(@class, 'tgme_widget_message') and @data-post]");
        foreach ($nodes as $node) {
            $data_post = $node->getAttribute('data-post');
            $parts     = explode('/', $data_post);
            $msg_id    = isset($parts[1]) ? (int) $parts[1] : 0;
            if (!$msg_id) continue;

            $text_nodes = $xpath->query(".//div[contains(@class, 'tgme_widget_message_text')]", $node);
            $text       = '';
            if ($text_nodes->length > 0) {
                $text = trim($text_nodes->item(0)->textContent);
            }

            $images      = [];
            $photo_nodes = $xpath->query(".//a[contains(@class, 'tgme_widget_message_photo_wrap')]", $node);
            foreach ($photo_nodes as $p_node) {
                $style = $p_node->getAttribute('style');
                if (preg_match('/background-image:\s*url\([\'"]?([^\'"\)]+)[\'"]?\)/i', $style, $m)) {
                    $images[] = $m[1];
                }
            }

            if (empty($images)) {
                $vthumb_nodes = $xpath->query(".//i[contains(@class, 'tgme_widget_message_video_thumb')]", $node);
                foreach ($vthumb_nodes as $vt) {
                    $style = $vt->getAttribute('style');
                    if (preg_match('/background-image:\s*url\([\'"]?([^\'"\)]+)[\'"]?\)/i', $style, $m)) {
                        $images[] = $m[1];
                    }
                }
            }

            $video_url   = '';
            $video_nodes = $xpath->query(".//video[@src]", $node);
            if ($video_nodes->length > 0) {
                $video_url = $video_nodes->item(0)->getAttribute('src');
            }

            $results[] = [
                'id'     => $msg_id,
                'text'   => $text,
                'images' => $images,
                'video'  => $video_url,
            ];
        }
        return $results;
    }

    private function is_spam_or_ad(string $text): bool {
        $keywords = ['#বিজ্ঞাপন', '#ad', '#sponsored', 'প্রমোশন', 'ইনবক্স করুন', 'অর্ডার করতে', '1xbet', 'melbet', 'বেকআপ চ্যানেল'];
        $lower    = mb_strtolower($text);
        foreach ($keywords as $kw) {
            if (mb_strpos($lower, mb_strtolower($kw)) !== false) {
                return true;
            }
        }
        return false;
    }

    private function download_and_resize_image(string $img_url, string $title, bool $is_featured = true): int {
        $res = wp_remote_get($img_url, ['timeout' => 25]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            return 0;
        }

        $raw_bytes = wp_remote_retrieve_body($res);
        $src       = @imagecreatefromstring($raw_bytes);
        if (!$src) return 0;

        $src_w = imagesx($src);
        $src_h = imagesy($src);

        if ($is_featured) {
            $dst_w  = 1200;
            $dst_h  = 630;
            $canvas = imagecreatetruecolor($dst_w, $dst_h);
            $bg     = imagecolorallocate($canvas, 18, 24, 38);
            imagefill($canvas, 0, 0, $bg);

            $src_ratio = $src_w / max($src_h, 1);

            if ($src_ratio > 1.25) {
                $scale    = max($dst_w / $src_w, $dst_h / $src_h);
                $new_w    = (int) ($src_w * $scale);
                $new_h    = (int) ($src_h * $scale);
                $offset_x = (int) (($dst_w - $new_w) / 2);
                $offset_y = (int) (($dst_h - $new_h) / 2);
                imagecopyresampled($canvas, $src, $offset_x, $offset_y, 0, 0, $new_w, $new_h, $src_w, $src_h);
            } else {
                imagecopyresampled($canvas, $src, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);
                for ($i = 0; $i < 12; $i++) {
                    imagefilter($canvas, IMG_FILTER_GAUSSIAN_BLUR);
                }
                imagefilter($canvas, IMG_FILTER_BRIGHTNESS, -35);

                $scale_contain = min($dst_w / $src_w, $dst_h / $src_h);
                $fg_w          = (int) ($src_w * $scale_contain);
                $fg_h          = (int) ($src_h * $scale_contain);
                $fg_x          = (int) (($dst_w - $fg_w) / 2);
                $fg_y          = (int) (($dst_h - $fg_h) / 2);
                imagecopyresampled($canvas, $src, $fg_x, $fg_y, 0, 0, $fg_w, $fg_h, $src_w, $src_h);
            }
        } else {
            $max_w  = 1200;
            $ratio  = $src_w > $max_w ? ($max_w / $src_w) : 1;
            $dst_w  = (int) ($src_w * $ratio);
            $dst_h  = (int) ($src_h * $ratio);
            $canvas = imagecreatetruecolor($dst_w, $dst_h);
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);
        }

        ob_start();
        imagejpeg($canvas, null, 85);
        $jpeg_data = ob_get_clean();
        imagedestroy($src);
        imagedestroy($canvas);

        return $this->save_to_media_library($jpeg_data, 'kstnews24-' . time() . '-' . wp_rand(100, 999) . '.jpg', $title);
    }

    private function generate_fallback_banner(string $title): int {
        $canvas = imagecreatetruecolor(1200, 630);
        $bg     = imagecolorallocate($canvas, 139, 0, 0);
        imagefill($canvas, 0, 0, $bg);
        ob_start();
        imagejpeg($canvas, null, 85);
        $jpeg_data = ob_get_clean();
        imagedestroy($canvas);
        return $this->save_to_media_library($jpeg_data, 'kstnews24-breaking-' . time() . '.jpg', $title);
    }

    private function save_to_media_library(string $buffer, string $filename, string $alt_title): int {
        $upload = wp_upload_bits($filename, null, $buffer);
        if (!empty($upload['error'])) return 0;

        $attachment = [
            'post_mime_type' => 'image/jpeg',
            'post_title'     => sanitize_text_field($alt_title),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);
        update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($alt_title));
        return $attach_id;
    }

    private function get_related_posts_html(int $cat_id): string {
        $q = get_posts(['category' => $cat_id, 'numberposts' => 2, 'post_status' => 'publish']);
        if (empty($q)) return '';
        $items = '';
        foreach ($q as $p) {
            $items .= '<li><a href="' . esc_url(get_permalink($p->ID)) . '"><strong>' . esc_html($p->post_title) . '</strong></a></li>';
        }
        return '<div style="background:#f8f9fa;border-left:4px solid #e63946;padding:12px 16px;margin:20px 0;"><p style="margin:0 0 6px 0;"><strong>আরও পড়ুন:</strong></p><ul style="margin:0;padding-left:20px;">' . $items . '</ul></div>';
    }

    public function render_admin_ui(): void {
        $settings   = $this->get_settings();
        $logs       = get_option($this->log_key, []);
        $last_id    = get_option($this->state_key, 0);
        $ajax_nonce = wp_create_nonce('kst_ai_ajax_nonce');
        $count_24h  = $this->get_24h_post_count();
        $limit_24h  = (int) ($settings['daily_limit'] ?? 150);
        $last_cron  = (int) get_option('kstnews_ai_last_cron_run', 0);
        $worker_url = add_query_arg('kstnews_auto_worker', '1', home_url('/'));
        ?>
        <div class="wrap">
            <h1>KSTNews24 AI Telegram AutoPilot (OpenRouter Edition)</h1>
            <?php settings_errors('kst_msg'); ?>

            <div style="display:flex; gap:24px; flex-wrap:wrap; margin-top:20px;">
                <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:6px; flex:1; min-width:340px;">
                    <h2>1. OpenRouter & Telegram Configuration</h2>
                    <form method="post">
                        <?php wp_nonce_field('kst_ai_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th>OpenRouter API Key</th>
                                <td>
                                    <input type="password" id="kst-or-key" name="or_api_key" value="<?php echo esc_attr($settings['or_api_key']); ?>" class="regular-text" required />
                                    <button type="button" id="kst-test-api-btn" class="button" style="margin-top:6px; display:block;">Test OpenRouter Connection</button>
                                    <span id="kst-test-api-result" style="display:block; margin-top:6px; font-size:13px; font-weight:600;"></span>
                                </td>
                            </tr>
                            <tr>
                                <th>Free Text Model</th>
                                <td>
                                    <?php
                                    $free_models   = $this->get_free_models_list();
                                    $current_model = !empty($settings['or_model']) ? $settings['or_model'] : 'google/gemma-4-31b-it:free';
                                    $is_predefined = array_key_exists($current_model, $free_models);
                                    ?>
                                    <select id="kst-or-model-select" class="regular-text" style="max-width:420px; display:block; margin-bottom:8px;">
                                        <?php foreach ($free_models as $slug => $label): ?>
                                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($is_predefined && $current_model === $slug); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="custom" <?php selected(!$is_predefined); ?>>Custom / Other Model Slug...</option>
                                    </select>

                                    <div id="kst-custom-model-wrap" style="<?php echo $is_predefined ? 'display:none;' : 'display:block;'; ?> margin-bottom:8px;">
                                        <input type="text" id="kst-or-model" name="or_model" value="<?php echo esc_attr($current_model); ?>" class="regular-text" placeholder="e.g. google/gemma-4-31b-it:free" />
                                        <span class="description" style="display:block; font-size:12px; color:#64748b; margin-top:4px;">Enter any valid OpenRouter model slug.</span>
                                    </div>

                                    <p class="description">
                                        ⚡ <strong>Smart Token Auto-Cascade:</strong> If this model reaches its rate/token limit after 4-5 posts, the plugin automatically rotates through the other free models (Gemma 4, Nemotron Lightning/Ultra, Dots3-Note, LiquidAI, etc.) so publishing never stops!
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>Telegram Channel</th>
                                <td>
                                    <input type="text" name="tg_channel" value="<?php echo esc_attr($settings['tg_channel']); ?>" class="regular-text" />
                                    <p class="description">Public channel without @ (e.g., <code>basherkella</code>)</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Daily Post Limit (24h)</th>
                                <td>
                                    <input type="number" name="daily_limit" min="1" max="1000" value="<?php echo esc_attr($settings['daily_limit'] ?? 150); ?>" class="small-text" />
                                    <p class="description">Maximum posts to publish in a 24-hour window (Default: <code>150</code>). Autopilot pauses automatically when this limit is reached.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Post Author Name</th>
                                <td>
                                    <input type="text" name="author_name" value="<?php echo esc_attr($settings['author_name'] ?? 'ওপেন সোর্স প্রতিনিধি'); ?>" class="regular-text" />
                                    <p class="description">Default: <code>ওপেন সোর্স প্রতিনিধি</code>. Posts will be attributed to this author.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Default Post Status</th>
                                <td>
                                    <select name="post_status">
                                        <option value="draft" <?php selected($settings['post_status'], 'draft'); ?>>Save as Draft (Review First)</option>
                                        <option value="publish" <?php selected($settings['post_status'], 'publish'); ?>>Publish Immediately</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Auto-Check</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="auto_cron" value="1" <?php checked($settings['auto_cron'], 1); ?> />
                                        Check channel automatically every 10 minutes in the background
                                    </label>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" name="kst_save_settings" class="button button-primary">Save Settings</button>
                        </p>
                    </form>
                </div>

                <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:6px; flex:1; min-width:340px;">
                    <h2>2. Manual Trigger & State</h2>
                    <p><strong>Last Processed Telegram ID:</strong> <code><?php echo esc_html($last_id ?: 'None yet'); ?></code></p>
                    <p><strong>24-Hour Published Count:</strong> <code><?php echo esc_html($count_24h); ?> / <?php echo esc_html($limit_24h); ?> posts</code></p>
                    <p><strong>Post Author:</strong> <code><?php echo esc_html($settings['author_name'] ?? 'ওপেন সোর্স প্রতিনিধি'); ?></code></p>
                    <p><strong>Last Automated Run:</strong>
                        <code><?php
                            echo $last_cron ? esc_html(human_time_diff($last_cron, time()) . ' ago (' . date_i18n('Y-m-d H:i:s', $last_cron) . ')') : 'None recorded yet';
                        ?></code>
                    </p>
                    <p><strong>Scheduler Status:</strong>
                        <code><?php
                            $next = wp_next_scheduled('kstnews_ai_cron_hook');
                            echo $next ? 'Active (Next WP-Cron check: ' . date_i18n('Y-m-d H:i:s', $next) . ')' : 'Manual Mode Only';
                        ?></code>
                    </p>
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; margin-top:14px;">
                        <strong style="display:block; margin-bottom:4px; font-size:13px; color:#1e293b;">⚡ Guaranteed 10-Min Execution (External Webhook / Cron):</strong>
                        <p style="margin:0 0 6px; font-size:12px; color:#64748b;">If LiteSpeed Cache prevents WordPress internal cron from triggering, set an external cron (cPanel Cron, Cron-Job.org, or UptimeRobot) every 10 minutes targeting:</p>
                        <input type="text" readonly value="<?php echo esc_url($worker_url); ?>" style="width:100%; font-family:monospace; font-size:12px; background:#fff;" onclick="this.select();" />
                        <p style="margin:6px 0 0; font-size:11px; color:#64748b;">cPanel Cron Command: <code>curl -s "<?php echo esc_url($worker_url); ?>" > /dev/null 2>&1</code></p>
                    </div>
                    <div style="margin-top:20px; display:flex; gap:12px;">
                        <button type="button" id="kst-open-modal-btn" class="button button-secondary button-hero">Fetch & Publish Now</button>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('kst_ai_nonce'); ?>
                            <button type="submit" name="kst_reset_state" class="button" onclick="return confirm('Reset state and clear logs?');">Reset State</button>
                        </form>
                    </div>
                </div>
            </div>

            <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:6px; margin-top:24px;">
                <h2>3. Recent Activity Log</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Telegram Msg ID</th>
                            <th>Status</th>
                            <th>Article / Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="4">No activity recorded yet. Click "Fetch & Publish Now" above to test.</td></tr>
                        <?php else: foreach ($logs as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row['time']); ?></td>
                                <td><code>#<?php echo esc_html($row['msg_id']); ?></code></td>
                                <td><strong><?php echo esc_html($row['status']); ?></strong></td>
                                <td><?php echo wp_kses_post($row['details']); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="kst-modal" style="display:none; position:fixed; z-index:999999; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); backdrop-filter:blur(3px); align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:12px; width:520px; max-width:92%; padding:32px 36px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); text-align:center;">
                <h3 id="kst-modal-title" style="margin:0 0 12px; font-size:22px; font-weight:700; color:#0f172a;">Processing News Article</h3>

                <div style="display:flex; justify-content:space-between; align-items:center; font-size:14px; font-weight:600; color:#475569; margin-bottom:8px;">
                    <span id="kst-modal-pct">0%</span>
                    <span id="kst-modal-timer" style="color:#0284c7;">Estimated time remaining: 18s</span>
                </div>

                <div style="width:100%; background:#e2e8f0; height:12px; border-radius:6px; overflow:hidden; margin-bottom:18px;">
                    <div id="kst-modal-bar" style="width:0%; height:100%; background:linear-gradient(90deg, #3b82f6, #06b6d4); transition:width 0.3s ease, background-color 0.3s ease;"></div>
                </div>

                <p id="kst-modal-desc" style="color:#64748b; font-size:14px; min-height:22px; margin:0 0 24px;">
                    Connecting to Telegram feed...
                </p>

                <div id="kst-modal-result" style="display:none; margin-bottom:24px; text-align:left; background:#f8fafc; border:1px solid #e2e8f0; padding:14px 18px; border-radius:8px; font-size:14px; color:#1e293b;"></div>

                <div style="display:flex; gap:12px; justify-content:center;">
                    <button type="button" id="kst-modal-cancel" class="button button-secondary" style="padding:4px 20px; font-size:14px; border-color:#cbd5e1; color:#475569;">Cancel</button>
                    <button type="button" id="kst-modal-close" class="button button-primary" style="display:none; padding:4px 24px; font-size:14px;" onclick="location.reload();">Done & Refresh Log</button>
                </div>
            </div>
        </div>

        <script>
        (function() {
            const ajaxNonce = '<?php echo esc_js($ajax_nonce); ?>';

            function parseSafeJson(rawText) {
                try {
                    return JSON.parse(rawText);
                } catch (e) {
                    const firstBrace = rawText.indexOf('{');
                    const lastBrace = rawText.lastIndexOf('}');
                    if (firstBrace !== -1 && lastBrace > firstBrace) {
                        return JSON.parse(rawText.substring(firstBrace, lastBrace + 1));
                    }
                    throw new Error('Server returned invalid response: ' + rawText.replace(/<[^>]*>?/gm, '').trim().substring(0, 140));
                }
            }

            const modelSelect = document.getElementById('kst-or-model-select');
            const modelWrap   = document.getElementById('kst-custom-model-wrap');
            const modelInput  = document.getElementById('kst-or-model');

            if (modelSelect && modelInput) {
                modelSelect.addEventListener('change', function() {
                    if (this.value === 'custom') {
                        if (modelWrap) modelWrap.style.display = 'block';
                        modelInput.focus();
                    } else {
                        if (modelWrap) modelWrap.style.display = 'none';
                        modelInput.value = this.value;
                    }
                });
            }

            const testBtn = document.getElementById('kst-test-api-btn');
            const testRes = document.getElementById('kst-test-api-result');
            testBtn.addEventListener('click', function() {
                testBtn.disabled = true;
                testBtn.innerText = 'Testing OpenRouter...';
                testRes.innerText = '';

                const formData = new FormData();
                formData.append('action', 'kst_test_api_ajax');
                formData.append('security', ajaxNonce);
                formData.append('or_api_key', document.getElementById('kst-or-key').value);
                formData.append('or_model', document.getElementById('kst-or-model').value);

                fetch(ajaxurl, { method: 'POST', body: formData })
                .then(r => r.text())
                .then(raw => {
                    const data = parseSafeJson(raw);
                    testBtn.disabled = false;
                    testBtn.innerText = 'Test OpenRouter Connection';
                    if (data.success) {
                        testRes.style.color = '#15803d';
                        testRes.innerHTML = '&#10004; ' + data.data.message;
                    } else {
                        testRes.style.color = '#b91c1c';
                        testRes.innerHTML = '&#10008; ' + (data.data && data.data.message ? data.data.message : 'Test failed');
                    }
                })
                .catch(err => {
                    testBtn.disabled = false;
                    testBtn.innerText = 'Test OpenRouter Connection';
                    testRes.style.color = '#b91c1c';
                    testRes.innerHTML = '&#10008; ' + err.message;
                });
            });

            let abortController = null;
            let progressInterval = null;
            let currentPct = 0;
            let secondsRemaining = 18;

            const modal = document.getElementById('kst-modal');
            const bar = document.getElementById('kst-modal-bar');
            const pctLabel = document.getElementById('kst-modal-pct');
            const timerLabel = document.getElementById('kst-modal-timer');
            const desc = document.getElementById('kst-modal-desc');
            const resultBox = document.getElementById('kst-modal-result');
            const cancelBtn = document.getElementById('kst-modal-cancel');
            const closeBtn = document.getElementById('kst-modal-close');
            const title = document.getElementById('kst-modal-title');

            document.getElementById('kst-open-modal-btn').addEventListener('click', function() {
                modal.style.display = 'flex';
                currentPct = 0;
                secondsRemaining = 18;
                bar.style.width = '0%';
                bar.style.background = 'linear-gradient(90deg, #3b82f6, #06b6d4)';
                pctLabel.innerText = '0%';
                timerLabel.innerText = 'Estimated time remaining: 18s';
                timerLabel.style.display = 'inline';
                desc.innerText = 'Scraping Telegram channel post & media...';
                resultBox.style.display = 'none';
                cancelBtn.style.display = 'inline-block';
                cancelBtn.disabled = false;
                cancelBtn.innerText = 'Cancel';
                closeBtn.style.display = 'none';
                title.innerText = 'AutoPilot In Progress';

                abortController = new AbortController();

                progressInterval = setInterval(() => {
                    if (secondsRemaining > 1) {
                        secondsRemaining--;
                        timerLabel.innerText = `Estimated time remaining: ${secondsRemaining}s`;
                    } else {
                        timerLabel.innerText = 'Finalizing WordPress post...';
                    }

                    if (currentPct < 90) {
                        currentPct += (currentPct < 40) ? 10 : (currentPct < 75 ? 6 : 3);
                        if (currentPct > 90) currentPct = 90;
                        bar.style.width = currentPct + '%';
                        pctLabel.innerText = currentPct + '%';

                        if (currentPct > 20 && currentPct <= 55) {
                            desc.innerText = 'Rewriting Bengali article with OpenRouter AI...';
                        } else if (currentPct > 55 && currentPct <= 80) {
                            desc.innerText = 'Downloading Telegram media & resizing to 1200x630...';
                        } else if (currentPct > 80) {
                            desc.innerText = 'Saving post & attaching Featured Thumbnail...';
                        }
                    }
                }, 1000);

                const formData = new FormData();
                formData.append('action', 'kst_run_pipeline_ajax');
                formData.append('security', ajaxNonce);

                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData,
                    signal: abortController.signal
                })
                .then(res => res.text())
                .then(raw => {
                    const data = parseSafeJson(raw);
                    clearInterval(progressInterval);
                    timerLabel.style.display = 'none';
                    cancelBtn.style.display = 'none';

                    if (data.success && data.data.status === 'success') {
                        currentPct = 100;
                        bar.style.width = '100%';
                        pctLabel.innerText = '100%';
                        bar.style.background = '#10b981';
                        title.innerText = 'Article Published Successfully!';
                        desc.innerText = data.data.message;

                        if (data.data.published && data.data.published.length > 0) {
                            const p = data.data.published[0];
                            resultBox.innerHTML = `<strong>Headline:</strong> ${p.title}<br/><a href="${p.link}" target="_blank" style="display:inline-block; margin-top:8px; color:#2563eb; font-weight:600;">View Published Draft &rarr;</a>`;
                            resultBox.style.display = 'block';
                        }
                    } else if (data.success && data.data.status === 'info') {
                        currentPct = 100;
                        bar.style.width = '100%';
                        pctLabel.innerText = '100%';
                        bar.style.background = '#3b82f6';
                        title.innerText = 'All Caught Up!';
                        desc.innerText = data.data.message;
                    } else {
                        bar.style.background = '#ef4444';
                        title.innerText = 'Pipeline Stopped';
                        const msg = (data.data && data.data.message) ? data.data.message : 'Unknown response from server.';
                        desc.innerText = msg;
                    }
                    closeBtn.style.display = 'inline-block';
                })
                .catch(err => {
                    clearInterval(progressInterval);
                    timerLabel.style.display = 'none';
                    cancelBtn.style.display = 'none';
                    closeBtn.style.display = 'inline-block';

                    if (err.name === 'AbortError') {
                        bar.style.background = '#94a3b8';
                        title.innerText = 'Execution Canceled';
                        desc.innerText = 'Operation was stopped by user.';
                    } else {
                        bar.style.background = '#ef4444';
                        title.innerText = 'Error';
                        desc.innerText = err.message;
                    }
                });
            });

            cancelBtn.addEventListener('click', function() {
                if (abortController) {
                    abortController.abort();
                    cancelBtn.disabled = true;
                    cancelBtn.innerText = 'Canceling...';
                }
            });
        })();
        </script>
        <?php
    }
}

register_activation_hook(__FILE__, ['KSTNews_AI_AutoPilot', 'on_activate']);
register_deactivation_hook(__FILE__, ['KSTNews_AI_AutoPilot', 'on_deactivate']);

new KSTNews_AI_AutoPilot();