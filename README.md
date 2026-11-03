# KSTNews24 Telegram-to-WordPress AI AutoPilot

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://php.net)
[![AI Engine](https://img.shields.io/badge/AI-Gemini%202.5%20Flash-4285F4.svg)](https://aistudio.google.com)
[![License](https://img.shields.io/badge/License-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A production-grade, self-contained WordPress plugin that automates the news pipeline from public Telegram channels (e.g. `basherkella`) to WordPress news portals ([kstnews24.com](https://kstnews24.com)).

The plugin runs **100% on standard shared hosting** (e.g. Hostinger) with **zero VPS or external infrastructure cost**. It screens content for spam/ads and Google AdSense safety, rewrites brief news notes into 300+ word structured Bengali articles using Google Gemini 2.5 Flash, formats featured images to Discover-ready 1200×630 dimensions using PHP GD, and publishes SEO-ready posts.

---

## Architecture Overview

```
                      ┌────────────────────────────────────────┐
                      │    Public Telegram Web Channel         │
                      │       (https://t.me/s/{channel})       │
                      └───────────────────┬────────────────────┘
                                          │ wp_remote_get
                                          ▼
                      ┌────────────────────────────────────────┐
                      │      DOM / XPath Web Scraper           │
                      │  - Text, photos, video thumbs, MP4     │
                      │  - Watermark & deduplication check     │
                      └───────────────────┬────────────────────┘
                                          │
                                          ▼
                      ┌────────────────────────────────────────┐
                      │   Layer 1: Pre-Filter (Zero API Cost)  │
                      │  - Blocks CTA buttons, short snippets  │
                      │  - Filter betting, casino & ad keywords│
                      └───────────────────┬────────────────────┘
                                          │ Passed
                                          ▼
                      ┌────────────────────────────────────────┐
                      │   Layer 2: Gemini 2.5 Flash AI Gate    │
                      │  - Policy check (violence, ads, adult) │
                      │  - 300+ word Bengali news expansion    │
                      │  - H2 structure, English slug, meta    │
                      └───────────────────┬────────────────────┘
                                          │ Structured JSON
                                          ▼
                      ┌────────────────────────────────────────┐
                      │      PHP GD 1200×630 Image Engine      │
                      │  - Landscape: 1.905:1 center-crop      │
                      │  - Portrait: blurred background + fit  │
                      └───────────────────┬────────────────────┘
                                          │
                                          ▼
                      ┌────────────────────────────────────────┐
                      │        WordPress Publishing Engine     │
                      │  - wp_insert_post (Draft / Publish)    │
                      │  - SEO meta (Yoast, RankMath, AIOSEO)  │
                      │  - "আরও পড়ুন" Internal Linking         │
                      │  - Activity Log & State Persistence    │
                      └────────────────────────────────────────┘
```

---

## Key Features

* **Zero-Cost Telegram Ingestion:** Scrapes public web previews (`t.me/s/{channel}`) without requiring Telegram Bot tokens, phone numbers, or MTProto servers.
* **Dual-Layer Ad/Spam Filtering:**
  * **Layer 1 (Local):** Instant heuristic filtering of inline buttons, betting/casino keywords (`ক্যাসিনো`, `বেটিং`, `1xbet`), affiliate links, and short snippets at zero API cost.
  * **Layer 2 (Gemini Safety Gate):** Structured AI policy gate blocking graphic violence, blood, adult themes, and commercial promotions.
* **AdSense & SEO Optimized Bengali News:**
  * Strict 300+ word minimum length with automatic corrective retry.
  * Semantic HTML structure (2–4 `<h2>` subheadings, paragraphs, lists).
  * Factual guardrails preventing hallucinated quotes, fictional names, or invented numbers.
  * SEO-friendly lowercase English URL slug (`englishSlug`).
  * Bengali meta description (≤ 155 chars) synced with **Yoast SEO**, **Rank Math**, and **All in One SEO (AIOSEO)**.
* **Google Discover Ready 1200×630 Thumbnails:**
  * Native PHP GD processing without external dependencies.
  * **Landscape images:** Automatically center-cropped to 1.905:1 ratio.
  * **Portrait/vertical images:** Canvas filled with an ultra-smooth blurred version of the photo as background, with the original photo centered and scaled to fit.
  * Graceful fallback if PHP GD extension is missing.
* **Internal Linking Engine:** Automatically attaches a styled *"আরও পড়ুন"* (Read More) block linking to 2–3 recent articles from the same category.
* **Governance & Reliability:**
  * 10-minute TTL run lock preventing overlapping cron and manual executions.
  * Execution time budget guard (`max_execution_time - 10s`) preventing 504 timeouts on shared hosting.
  * Daily post cap (default 20) with automatic calendar date reset.
  * Multi-tier deduplication via rolling 500-item processed cache and post meta (`_kstnews_source_msg_id`).

---

## Directory Structure

```
kstnews24-ai-bot-wp-plugin/
├── PRD.md                            # Complete Product Requirement Document
├── README.md                         # This repository guide
├── kstnews-ai-autopilot.zip          # Production-ready, installable plugin zip
├── kstnews-ai-autopilot/             # WordPress plugin directory
│   ├── kstnews-ai-autopilot.php      # Main bootstrap & activation/deactivation
│   ├── readme.txt                    # Standard WordPress plugin readme
│   ├── uninstall.php                 # Safe cleanup routine on deletion
│   ├── assets/
│   │   ├── admin.css                 # Admin dashboard styles & badges
│   │   └── admin.js                  # AJAX handlers (fetch, test, reset)
│   └── includes/
│       ├── class-settings.php        # Settings manager & API key masking
│       ├── class-admin-ui.php        # WordPress admin menu & settings UI
│       ├── class-telegram-scraper.php# Telegram web parser & SSRF protection
│       ├── class-prefilter.php       # Layer 1 zero-cost ad/spam filter
│       ├── class-gemini-client.php   # Gemini 2.5 Flash client & JSON schema
│       ├── class-image-processor.php # PHP GD 1200x630 image resizer & blur
│       ├── class-publisher.php       # Post publishing & deduplication engine
│       ├── class-internal-linker.php # "আরও পড়ুন" related news linker
│       ├── class-logger.php          # Custom table activity logger
│       └── class-cron.php            # WP-Cron scheduling manager
├── docs/                             # Milestone implementation reports
│   ├── milestone-1-scaffold.md
│   ├── milestone-2-scraper.md
│   ├── milestone-3-prefilter.md
│   ├── milestone-4-gemini.md
│   ├── milestone-5-image-processor.md
│   ├── milestone-6-publisher.md
│   ├── milestone-7-cron-governance.md
│   ├── milestone-8-hardening.md
│   └── milestone-9-packaging.md
└── tests/                            # Zero-dependency test harness & fixtures
    ├── bootstrap.php                 # WordPress core test doubles
    ├── run-tests.php                 # Master test suite runner
    ├── test-acceptance-all.php       # All 13 PRD acceptance criteria
    ├── test-scaffold.php
    ├── test-scraper.php
    ├── test-prefilter.php
    ├── test-gemini.php
    ├── test-image.php
    ├── test-publisher.php
    ├── test-pipeline-governance.php
    ├── test-hardening.php
    └── fixtures/
        └── live_basherkella.html     # Real Telegram channel feed snapshot
```

---

## Installation & Deployment

### 1. Requirements
* **WordPress:** 6.0 or higher
* **PHP:** 8.0 or higher
* **PHP Extensions:** `curl`, `dom`, `libxml`, `gd` (recommended for 1200×630 resizing)
* **Google AI Studio API Key:** Free API key from [Google AI Studio](https://aistudio.google.com/app/apikey).

### 2. Upload & Activate
1. Download [`kstnews-ai-autopilot.zip`](file:///c:/Projects/kstnews24-ai-bot-wp-plugin/kstnews-ai-autopilot.zip).
2. In your WordPress admin panel, go to **Plugins → Add New → Upload Plugin**.
3. Choose the `kstnews-ai-autopilot.zip` file, click **Install Now**, and then **Activate Plugin**.

### 3. Configuration
1. Open **KSTNews AI Bot** in the WordPress admin sidebar.
2. In the **Settings & Configuration** tab:
   * **Gemini API Key:** Paste your Gemini API key (it will be securely stored and masked on save).
   * **Telegram Channels:** Enter target public channel username(s), e.g. `basherkella`.
   * **Default Post Status:** Select **Draft** for the initial rollout (recommended so you can review output quality before going live).
   * **Default Category:** Choose the fallback news category (e.g. `জাতীয়`).
   * **Max Posts Per Run:** Set batch limit (default `3`).
   * **Daily Cap:** Set daily article limit (default `20`).
3. Click **Save Settings**.
4. Click **Test Gemini Connection** to verify API connectivity.
5. Click **Fetch & Publish Now** to run an immediate ingestion cycle.

### 4. Enable Background Automation
* Check **Enable Auto-Cron** and choose an interval (e.g. *Every 30 Minutes*).
* **Optional Hostinger Server Cron:** For low-traffic hours, configure a cPanel/Hostinger cron job to call `wp-cron.php` directly:
  ```bash
  wget -q -O - https://kstnews24.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
  ```

---

## Testing & Verification

The repository includes a comprehensive, zero-dependency test suite that runs directly via CLI:

### Run All Test Suites
```bash
php -d extension=gd tests/run-tests.php
```

### Run Acceptance Test Suite (13 PRD Criteria)
```bash
php -d extension=gd tests/test-acceptance-all.php
```

### Run Individual Test Suites
```bash
php tests/test-scaffold.php
php tests/test-scraper.php
php tests/test-prefilter.php
php tests/test-gemini.php
php -d extension=gd tests/test-image.php
php tests/test-publisher.php
php tests/test-pipeline-governance.php
php tests/test-hardening.php
```

### Rebuild ZIP Archive
```powershell
Compress-Archive -Path kstnews-ai-autopilot -DestinationPath kstnews-ai-autopilot.zip -Force
```

---

## AdSense & SEO Compliance Guidelines

1. **Content Quality:** Gemini expands brief Telegram notes into detailed, 300+ word journalistic reports with proper context and neutral tone. It is instructed never to invent quotes or fictional names.
2. **Review Mode:** Use **Draft** mode during the first week to verify rewrite quality and image formatting.
3. **Attribution:** The plugin automatically appends a source attribution line (e.g. *"সূত্র: Telegram"*) to respect journalistic ethics.
4. **Mandatory Trust Pages:** To ensure Google AdSense approval, the site owner should manually publish and link standard trust pages in the site footer:
   * About Us
   * Contact Us
   * Privacy Policy
   * Terms of Service
   * Editorial & Fact-Checking Policy

---

## License

This project is licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).
