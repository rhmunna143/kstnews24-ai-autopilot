# Milestone 3 Report: Pre-Filter, Logger, & State/Deduplication

**Date:** 2026-11-03
**Status:** Completed
**Component:** `KSTNews_AI_Prefilter` (`includes/class-prefilter.php`), `KSTNews_AI_Logger`, `KSTNews_AI_Settings`

---

## 1. What Was Built

1. **Dual-Layer Filter (Layer 1 - Pre-Filter):**
   - Implemented zero-API-cost deterministic screening rules:
     - **Inline CTA Button Filter:** Blocks messages with Telegram inline keyboard/reply buttons (`tgme_widget_message_inline_button`), characteristic of promotional and affiliate campaigns.
     - **Short Text Without Media:** Discards snippets under 40 characters if no image or video is attached.
     - **Configurable Blocked Keywords:** Unicode-aware, case-insensitive substring search matching against English and Bengali gambling, betting, casino, crypto signals, and promotional keywords.
     - **Link / Hashtag Dominance:** Computes character ratio of URLs, handles, and hashtags; flags messages where junk exceeds 50% of the content.
   - Outputs structured result: `['passed' => bool, 'reason' => string]`.

2. **State & Watermark Synchronization:**
   - Pre-filter rejections automatically trigger `KSTNews_AI_Logger::log` with status `Blocked-Prefilter` and detailed reason.
   - Blocked messages immediately advance the channel watermark `last_message_id` and are recorded in the 500-item rolling `processed_ids` list. This guarantees that blocked posts are never repeatedly evaluated or logged.

3. **Test Suite (`tests/test-prefilter.php`):**
   - Tested short text without media (blocked) vs short text with media (permitted).
   - Tested CTA buttons detection.
   - Tested multi-language ad/gambling keyword matches.
   - Tested link/hashtag density calculations.
   - Verified state advancement and activity log insertion on rejections.

---

## 2. Tests Run & Results

- Command: `php tests/test-prefilter.php`
- Assertions executed: **18**
- Result: **All 18 assertions passed (100%)**
  - Short text filter verified.
  - Media override for short news photos verified.
  - CTA button blocking verified.
  - English and Bengali keyword detection verified (`বেটিং`, `ডিসকাউন্ট`, `1xbet`, `casino`).
  - Link/hashtag threshold (>50%) verified.
  - Genuine news acceptance verified.
  - Logging and watermark advancement verified.
- PHP Syntax Linting: Passed without warnings.

---

## 3. Known Issues & Notes

- None. Ready to proceed to **Milestone 4: Gemini client**.
