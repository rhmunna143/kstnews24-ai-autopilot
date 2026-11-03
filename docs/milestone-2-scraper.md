# Milestone 2 Report: Telegram Web Scraper & Fixtures

**Date:** 2026-11-03
**Status:** Completed
**Component:** `KSTNews_AI_Telegram_Scraper` (`includes/class-telegram-scraper.php`)

---

## 1. What Was Built

1. **Telegram Web Scraper (`includes/class-telegram-scraper.php`):**
   - Implemented `fetch_channel_posts()` fetching `https://t.me/s/{channel}` using `wp_remote_get` with:
     - Realistic Chrome 124 desktop User-Agent.
     - 15-second timeout.
     - Accept & language headers.
     - Error handling for 404 (channel not found), 429 (rate limit), non-200 codes, and WP_Error.
   - Built HTML parser using `DOMDocument` and `DOMXPath` with suppressed libxml errors and UTF-8 encoding:
     - Extracts message ID from `data-post` attribute (e.g. `basherkella/88271` -> `88271`).
     - Extracts text while preserving line breaks (`<br>`) and whitespace.
     - Extracts photo URLs from `tgme_widget_message_photo_wrap` background-image style.
     - Extracts direct MP4 video sources (`<video src="...">`) and video thumbnails.
     - Detects inline buttons and keyboard wrappers (`tgme_widget_message_inline_button`, `tgme_widget_message_inline_keyboard`).
     - Extracts message timestamps from `<time datetime="...">`.
     - Selects the first photo in an album/group as the `primary_image`.
   - Watermark & Deduplication:
     - Skips messages with ID `<= last_message_id`.
     - Skips messages present in `processed_ids` list.
     - Sorts messages chronologically (oldest-new to newest) per PRD specs.
     - Caps batch size to `max_per_run`.
   - Security:
     - Strict SSRF protection via `is_allowed_telegram_cdn()` verifying all media URLs belong to verified Telegram CDN domains (`*.telesco.pe`, `*.telegram.org`, `*.t.me`).
     - Channel name validation rejecting invalid characters before sending HTTP requests.

2. **Fixtures Created (`tests/fixtures/`):**
   - `live_basherkella.html`: 134 KB live snapshot of `t.me/s/basherkella` with 19 real posts, multi-photo albums, Bengali text, timestamps, and telesco.pe CDN URLs.
   - `fixture_buttons.html`: Synthetic post with inline call-to-action button keyboard.
   - `fixture_video_mp4.html`: Synthetic post with direct MP4 video and video thumbnail.
   - `fixture_empty.html`: Channel page with no messages.

3. **Test Suite (`tests/test-scraper.php`):**
   - Automated test suite validating all parser capabilities, album handling, button detection, video extraction, empty handling, SSRF validation, watermark filtering, and chronological sorting.

---

## 2. Tests Run & Results

- Command: `php tests/test-scraper.php`
- Assertions executed: **28**
- Result: **All 28 assertions passed (100%)**
  - Real live fixture parsed: 19 posts identified, first post ID `88271` with Bengali text and 5 photos.
  - Multi-photo album selection verified: first photo selected as `primary_image`.
  - Inline button detection verified: button fixture flagged as `has_buttons = true`, normal posts flagged as `false`.
  - Video and thumbnail extraction verified: direct MP4 URL and thumb URL extracted.
  - Empty channel handling verified: cleanly returns 0 messages.
  - SSRF guard verified: Telegram CDNs allowed, external and AWS metadata IPs blocked.
  - Watermark filtering and sorting verified: messages sorted in ascending chronological order and capped at limit.
  - Channel name validation verified: invalid channel characters rejected immediately.
- PHP Syntax Linting: Passed without warnings.

---

## 3. Known Issues & Notes

- None. Ready to proceed to **Milestone 3: Pre-filter + logger + state/dedup**.
