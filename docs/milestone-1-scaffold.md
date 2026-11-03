# Milestone 1 Report: Scaffold & Foundation

**Date:** 2026-11-03
**Status:** Completed
**Component:** `kstnews-ai-autopilot` WordPress plugin

---

## 1. What Was Built

1. **Plugin Bootstrap (`kstnews-ai-autopilot.php`):**
   - Declared standard WordPress plugin headers (v1.0.0, PHP 8.0+, WP 6.0+).
   - Set up plugin constants (`KSTNEWS_AI_VERSION`, `KSTNEWS_AI_PLUGIN_DIR`, `KSTNEWS_AI_PLUGIN_URL`, `KSTNEWS_AI_PLUGIN_BASENAME`).
   - Defined singleton `KSTNews_AI_Autopilot`.
   - Implemented activation hook creating `{prefix}kstnews_ai_log` table with `dbDelta`, seeding default options and state.
   - Implemented deactivation hook clearing WP-Cron schedules and runtime transients (`kstnews_ai_run_lock`).

2. **Settings & State Management (`includes/class-settings.php`):**
   - Configured `kstnews_ai_settings` option storage with secure defaults:
     - `gemini_key`: blank by default.
     - `channels`: `['basherkella']`.
     - `post_status`: `draft` (safe default mode).
     - `auto_cron`: 0 (off by default).
     - `interval`: `thirty_minutes`.
     - `max_per_run`: 3.
     - `daily_cap`: 20.
     - `default_category`: `জাতীয়`.
     - `blocked_keywords`: comprehensive Bengali and English ad/spam keyword list.
     - `source_label`: `সূত্র: Telegram`.
     - `embed_video`: 0.
   - Built state tracking (`kstnews_ai_state`) for per-channel `last_message_id`, rolling `processed_ids` (capped at 500), and `daily_count` with automatic daily reset.
   - Built input sanitization and API key masking (`••••••••••••XXXX`) to ensure keys are never exposed in UI HTML or logs.

3. **Activity Logger (`includes/class-logger.php`):**
   - Custom table logger supporting all PRD statuses: `Published`, `Draft`, `Blocked-Prefilter`, `Blocked-AI`, `Skipped-Duplicate`, `Error`.
   - `$wpdb->prepare` parameterized queries with pagination, status filtering, and log pruning.
   - Real-time statistics aggregation for dashboard cards.

4. **WP-Cron Scheduling (`includes/class-cron.php`):**
   - Registered custom intervals: `fifteen_minutes` (900s) and `thirty_minutes` (1800s).
   - Dynamic rescheduling and cleanup based on user settings.

5. **Admin UI & Dashboard Shell (`includes/class-admin-ui.php`, `assets/admin.css`, `assets/admin.js`):**
   - Registered top-level menu `KSTNews AI Bot` with `manage_options` capability check.
   - Created responsive dashboard with 4 metric cards: Gemini Status, Auto-Cron Status, Daily Cap counter, and Blocked Ad count.
   - Created tabbed interface:
     - **Settings & Configuration**: Gemini Key, Channels, Post Status, Categories, WP-Cron automation, and Filter Keywords.
     - **Activity Log**: Paginated log table with status badges, message links, and post links.
     - **System & Diagnostics**: Real-time PHP version, GD status, DOMDocument availability, active lock inspection, and tracked channel state.
   - Enqueued isolated admin styles and AJAX script with nonces (`kstnews_ai_ajax_nonce`, `kstnews_ai_settings_action`).

6. **Test Harness (`tests/bootstrap.php`, `tests/test-scaffold.php`):**
   - Standalone WordPress mock environment enabling zero-dependency CLI unit and integration testing.

---

## 2. Tests Run & Results

- Command: `php tests/test-scaffold.php`
- Assertions executed: **28**
- Result: **All 28 assertions passed (100%)**
  - Plugin constants verified.
  - Settings defaults verified against PRD specifications.
  - API key masking verified (short keys, long keys, empty keys).
  - Telegram channel input sanitization verified (t.me URLs, @ handles, invalid chars).
  - Numeric clamps verified (`max_per_run` 1–10, `daily_cap` 1–100).
  - API key preservation verified when form submits masked placeholder.
  - Rolling processed IDs verified (capped at 500 items).
  - Logger insert, count, and filtering verified.
  - WP-Cron custom interval registration and reschedule logic verified.
- PHP Syntax Linting: Passed without warnings (`php -l` on all PHP files).

---

## 3. Known Issues & Notes

- None. Ready to proceed to **Milestone 2: Scraper + fixtures**.
