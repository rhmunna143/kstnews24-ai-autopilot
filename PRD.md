# PRD: KSTNews24 Telegram-to-WordPress AI AutoPilot

**Product:** `kstnews-ai-autopilot` WordPress plugin
**Target site:** kstnews24.com (Hostinger shared hosting, WordPress)
**Build tool:** Google Antigravity agent
**Version:** 1.0
**Date:** 2026-11-03

---

## 1. Purpose & Instructions for the Antigravity Agent

Build a single, self-contained WordPress plugin that automatically fetches posts from public Telegram channels (e.g. `basherkella`), screens them for spam/ads and AdSense safety using Gemini 2.5 Flash, rewrites them into SEO-ready Bengali news articles, processes images to 1200×630, and publishes them to WordPress.

**Agent working rules**

1. Deliver a plugin folder `kstnews-ai-autopilot/` and a ready-to-upload `kstnews-ai-autopilot.zip`.
2. Use only PHP 8.x, WordPress core APIs, and native PHP GD. No Composer dependencies, no VPS, no external services other than the Gemini REST API and `t.me`.
3. Follow WordPress coding standards: nonces, capability checks (`manage_options`), sanitization on input, escaping on output, `$wpdb->prepare` for any SQL.
4. Never hardcode the API key. Store it in `wp_options`, mask it in the admin UI.
5. Work in the milestone order in Section 11. After each milestone, run the verification checks listed and report results before continuing.
6. Do not publish anything to the live site during development; use Draft status for all tests.

---

## 2. Goals & Non-Goals

### Goals
- Fully automated pipeline: scrape → filter → AI process → image process → publish.
- Zero extra infrastructure cost (runs on existing shared hosting).
- AdSense-compliant and SEO-optimized output (300+ words, H2 structure, English slugs, meta descriptions, 1200×630 thumbnails).
- Transparent operation: admin dashboard with activity log, manual controls.

### Non-Goals
- No VPS, no Telegram Bot API/MTProto login, no private-channel access.
- No video re-hosting or transcoding (link/embed or thumbnail only).
- No multi-language UI (admin UI in English; article output in Bengali).
- No social auto-posting in v1.

---

## 3. Users & Roles

| Role | Needs |
|---|---|
| Site admin (primary) | Configure keys/channels, run manually, review log, reset state |
| Visitors / Google | Receive well-structured, policy-safe articles |

Only users with `manage_options` can access the plugin.

---

## 4. Tech Stack

- **Platform:** WordPress 6.x, PHP 8.0+
- **AI:** Gemini 2.5 Flash via REST, `responseMimeType: application/json` with `responseSchema`
- **HTTP:** `wp_remote_get` / `wp_remote_post`
- **HTML parsing:** `DOMDocument` + `DOMXPath` (libxml errors suppressed)
- **Images:** PHP GD (JPEG output, quality ~82)
- **Scheduling:** WP-Cron (`wp_schedule_event`), plus manual trigger
- **Storage:** `wp_options` for settings/state; custom table (or CPT meta) for activity log

---

## 5. Architecture

```
kstnews-ai-autopilot/
├── kstnews-ai-autopilot.php      # Bootstrap, hooks, activation/deactivation
├── includes/
│   ├── class-settings.php        # Options get/set, defaults
│   ├── class-admin-ui.php        # Menu, settings page, log table, actions
│   ├── class-telegram-scraper.php
│   ├── class-prefilter.php       # Keyword / inline-button pre-filter
│   ├── class-gemini-client.php   # API call, schema, retries
│   ├── class-image-processor.php # GD resize/pad/crop
│   ├── class-publisher.php       # wp_insert_post, media, taxonomy, meta
│   ├── class-internal-linker.php # "আরও পড়ুন" block
│   ├── class-logger.php          # Activity log
│   └── class-cron.php
├── assets/admin.css, admin.js
└── uninstall.php
```

**Pipeline per run:**
1. Load settings; acquire run lock (transient, 10-minute TTL) to prevent overlap.
2. For each configured channel, scrape `https://t.me/s/{channel}`.
3. Skip message IDs ≤ `last_message_id` for that channel or already in the processed-ID list.
4. Pre-filter → Gemini gate → Gemini article generation.
5. Process image → upload to Media Library → create post.
6. Log result, update state, release lock.

---

## 6. Functional Requirements

### FR-1 Admin UI
- Top-level menu **KSTNews AI Bot**.
- Settings: `gemini_key` (password field), `tg_channel` (one or more usernames, comma/newline separated), `post_status` (`publish` | `draft`), `auto_cron` (on/off), cron interval (15 / 30 / 60 min), max posts per run (default 3), default category.
- Buttons: **Save Settings**, **Fetch & Publish Now**, **Reset State**, **Test Gemini Connection**.
- Activity Log table: time, channel, message ID, status (Published / Draft / Blocked-Prefilter / Blocked-AI / Skipped-Duplicate / Error), reason, link to post. Paginated, latest 100 retained by default.
- All forms use nonces; actions check capability.

### FR-2 Telegram Web Scraper
- Fetch `t.me/s/{channel}` with a realistic User-Agent, 15s timeout.
- Extract per message: ID (`data-post`), raw text (preserve line breaks), photo URLs (from `tgme_widget_message_photo_wrap` background-image style), video thumbnail, direct MP4 `src` if present, message date, presence of inline buttons.
- Handle: empty channel, 404, rate limiting (back off, log error), message grouping (albums → use first image).
- Process oldest-new to newest, limited by max posts per run.

### FR-3 Dual-Layer Filter
**Layer 1: Pre-filter (no API cost)**
- Reject if text is under a minimum length (default 40 chars) and has no media.
- Reject on configurable keyword list (e.g. promo, "join", "subscribe", "বিজ্ঞাপন", "অফার", betting/casino terms, referral links).
- Reject messages with inline buttons/URL buttons typical of ads.
- Reject if text is mostly links/hashtags.

**Layer 2: Gemini gate** (returned as part of the single structured call, see FR-4)
- `isGenuineNews` (bool), `isAdSenseSafe` (bool), `rejectReason` (string).
- Block graphic violence/blood, adult content, hate, commercial promotion, misinformation-prone unverified claims about named individuals.

### FR-4 Gemini Content Generation
Single call per message, JSON output enforced by schema:

```json
{
  "isGenuineNews": true,
  "isAdSenseSafe": true,
  "rejectReason": "",
  "title": "string (Bengali, <=70 chars)",
  "contentHtml": "string (300+ words, <p> and <h2> only)",
  "englishSlug": "lowercase-hyphenated-max-60-chars",
  "metaDescription": "string (<=155 chars)",
  "category": "string (one of configured categories)",
  "tags": ["string"],
  "imageAltText": "string"
}
```

Requirements:
- Prompt must instruct: write in Bengali, expand only on facts present in the source; **do not invent quotes, figures, or names**; neutral news tone; 300+ words; 2–4 `h2` subheadings; no promotional language.
- Validate output: parse JSON, check word count ≥ 300 (strip tags), slug matches `^[a-z0-9-]+$`, meta ≤ 160 chars. On failure retry once with a corrective prompt, then log Error.
- Handle HTTP 429/5xx with exponential backoff (max 3 tries).
- Sanitize `contentHtml` with `wp_kses` allowing only `p, h2, h3, ul, ol, li, strong, em, a`.

### FR-5 Image Processing
- Download the largest photo (or video thumbnail) via `wp_remote_get`.
- Output exactly **1200×630** JPEG.
- **Landscape/near-ratio:** center-crop to 1.905:1, then resize.
- **Portrait/square:** create 1200×630 canvas; draw a blurred, scaled-to-cover copy as background; place the original scaled-to-fit-height, centered.
- Guard: skip GD ops gracefully if `gd` extension is missing (log and publish with original image); cap source size (e.g. 8 MB) and memory use.
- Upload via `media_handle_sideload`; set alt text from `imageAltText`; set as featured image.
- Optional watermark hook (filter `kstnews_apply_watermark`), disabled by default.

### FR-6 Publishing
- `wp_insert_post` with title, content, `post_name` = `englishSlug` (made unique by WP), status per setting, excerpt = `metaDescription`.
- Assign category (create if missing) and tags.
- Write meta description to Yoast/RankMath/AIOSEO keys if those plugins are active; always also store `_kstnews_meta_description`.
- Store post meta: `_kstnews_source_channel`, `_kstnews_source_msg_id`, `_kstnews_source_url`.
- Append a source attribution line: "সূত্র: Telegram" (configurable text) at the end of the content.
- Embed original MP4 only if setting enabled; otherwise omit.

### FR-7 Internal Linking
- After the article body, append "আরও পড়ুন" block with 2–3 links to recent posts in the same category (excluding the current post). Skip if fewer than 1 match.

### FR-8 Scheduler
- Register custom cron intervals; schedule `kstnews_ai_cron_event` when `auto_cron` is on; clear on deactivation/disable.
- Note in UI: WP-Cron depends on site traffic; show next scheduled run time and a hint about using a real server cron hitting `wp-cron.php` for reliability.

### FR-9 State & Deduplication
- Per-channel `last_message_id` and a rolling processed-ID list (last 500).
- Also check for existing posts by `_kstnews_source_msg_id` meta before publishing.
- **Reset State** clears last IDs and processed list (not posts) after a confirm dialog.

---

## 7. Non-Functional Requirements

- **Performance:** one run must finish within typical shared-hosting limits (aim ≤ 60 s for 3 posts); process posts sequentially and stop early if time budget (`max_execution_time` − 10 s) is nearly used.
- **Reliability:** a failure on one message must never abort the run; all errors logged.
- **Security:** nonces, capability checks, input sanitization, output escaping, API key never echoed or logged; SSRF guard (only `t.me`/Telegram CDN hosts for scraping and image download).
- **Compatibility:** WordPress 6.0+, PHP 8.0+, works without WP-CLI.
- **Cost control:** Gemini free-tier friendly; max posts per run and daily cap (default 20) settings.
- **Uninstall:** `uninstall.php` removes options and log table.

---

## 8. AdSense & SEO Compliance

| Requirement | Implementation |
|---|---|
| 300+ words | Enforced by validation + retry |
| Policy safety | Layer 1 + Layer 2 filters; blocked items logged |
| Clean URLs | `englishSlug` as `post_name` |
| Discover-ready images | 1200×630 featured image |
| Original value | Rewritten, structured content with H2s; attribution to source |
| Trust pages | Manual: About, Contact, Privacy Policy, Terms, Editorial Policy (see Section 12) |

**Content and legal caution (for the site owner):** Rewriting other channels' content at scale can raise copyright and "scaled/low-value content" concerns with Google and AdSense. Mitigations: prefer channels you have permission to republish, keep attribution, set `post_status` to Draft initially and review, and add an editorial review step before relying on full auto-publish.

---

## 9. Data Model

**Options (`kstnews_ai_settings`):** gemini_key, channels[], post_status, auto_cron, interval, max_per_run, daily_cap, default_category, blocked_keywords[], source_label, embed_video.
**State (`kstnews_ai_state`):** per-channel last_message_id, processed_ids[].
**Log table `{prefix}kstnews_ai_log`:** id, created_at, channel, message_id, status, reason, post_id.

---

## 10. Acceptance Criteria & Test Plan

1. Plugin zip installs and activates on a clean WordPress 6.x/PHP 8.x with no warnings (`WP_DEBUG` on).
2. Settings save, persist, and the API key is masked on reload.
3. **Test Gemini Connection** returns success with a valid key and a clear error with an invalid one.
4. Scraper correctly parses a saved fixture of `t.me/s/basherkella` HTML (text, photo, video thumb, MP4, buttons).
5. Pre-filter blocks fixtures containing ad keywords/inline buttons; logs "Blocked-Prefilter".
6. Gemini gate blocks a graphic-violence sample and a promo sample; logs "Blocked-AI" with reason.
7. A normal news sample produces: Bengali article ≥ 300 words, ≥ 2 H2s, valid slug, meta ≤ 160 chars.
8. Landscape and portrait test images both output exactly 1200×630 JPEG, with visible blurred background for portrait.
9. Running **Fetch & Publish Now** twice does not create duplicate posts.
10. Internal "আরও পড়ুন" links appear only when same-category posts exist.
11. Concurrent runs are prevented by the lock.
12. Reset State clears IDs without deleting posts.
13. Deactivation clears the cron event; uninstall removes data.

---

## 11. Milestones (Agent Execution Order)

1. **Scaffold:** plugin bootstrap, settings class, admin menu/UI shell, nonces/caps.
2. **Scraper + fixtures:** parser with unit-style tests against saved HTML.
3. **Pre-filter + logger + state/dedup.**
4. **Gemini client:** schema, prompt, validation, retries, test-connection button.
5. **Image processor:** portrait/landscape logic with test images.
6. **Publisher + internal linker + SEO plugin meta.**
7. **Cron + run lock + time budget + daily cap.**
8. **Hardening:** security review, error paths, uninstall, readme.
9. **Packaging:** build `kstnews-ai-autopilot.zip`; final acceptance run in Draft mode.

Each milestone ends with a short report: what was built, tests run, known issues. store the report in /docs directory.

---

## 12. Deployment & Operations

1. Get a Gemini API key from Google AI Studio.
2. Upload zip via **Plugins → Add New → Upload Plugin**; activate.
3. Open **KSTNews AI Bot**; enter key, channel `basherkella`, set status to **Draft** for the first week.
4. Click **Test Gemini Connection**, then **Fetch & Publish Now**; review the log and the drafts.
5. Switch to **Publish** and enable auto cron once output quality is verified.
6. Create the trust pages manually: About Us, Contact, Privacy Policy, Terms, Editorial Policy; link them in the footer.
7. Monitor the Activity Log daily; use **Reset State** only to re-sync.

---

## 13. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Telegram changes HTML markup | Isolated parser class, fixtures, clear error logging |
| WP-Cron unreliable on low traffic | Show next-run info; recommend server cron on `wp-cron.php` |
| Gemini free-tier rate limits | Backoff, per-run and daily caps |
| AI hallucination in expanded text | Prompt forbids invention; Draft-first review; attribution |
| AdSense rejection for scaled/duplicate content | Quality validation, filters, manual review, trust pages |
| Copyright of source material | Use permitted sources, attribute, avoid copying media you lack rights to |
| Shared-hosting timeouts / memory | Sequential processing, time budget, image size caps |

---

## 14. Future Enhancements (Out of Scope for v1)

- Editorial approval queue and one-click publish
- Watermark/logo overlay
- Social auto-sharing (Facebook/X)
- Multi-source RSS ingestion
- Duplicate-story detection via title similarity
