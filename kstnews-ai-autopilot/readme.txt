=== KSTNews24 AI Telegram AutoPilot ===
Contributors: kstnews24
Tags: telegram, ai news, automated news, gemini, bengali news, seo, autopilot
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fully automated pipeline fetching news from public Telegram channels, screening for spam/AdSense safety, rewriting into SEO-ready Bengali articles with Gemini 2.5 Flash, and publishing to WordPress.

== Description ==

**KSTNews24 AI Telegram AutoPilot** is a production-grade, zero-external-cost WordPress plugin designed specifically for news portals like **kstnews24.com**.

Running 100% within standard shared hosting (e.g., Hostinger) with no VPS, MTProto logins, or Telegram bot dependencies, it continuously scrapes public Telegram channels, applies dual-layer safety screening, generates high-authority 300+ word Bengali articles, resizes images to Google Discover-ready 1200×630 thumbnails, and creates SEO-optimized posts.

### Key Capabilities:
* **Public Telegram Scraper:** Parses public channel feeds (`t.me/s/{channel}`) for text, multi-image albums, video thumbnails, and direct MP4 clips with strict SSRF protection.
* **Layer 1 Pre-Filter (Zero API Cost):** Instantly filters out short snippets, promotional deals, affiliate links, and messages with inline CTA buttons before reaching Gemini.
* **Layer 2 Gemini AI Safety Gate:** Gemini 2.5 Flash screens for AdSense compliance, rejecting graphic violence, blood, adult content, and unverified defamation.
* **SEO Content Expansion:** Generates 300+ word structured articles in flawless standard Bengali with 2 to 4 `<h2>` subheadings, clean English slugs (`post_name`), and meta descriptions.
* **SEO Meta Integration:** Automatically writes meta descriptions for **Yoast SEO**, **Rank Math**, and **All in One SEO (AIOSEO)**.
* **1200×630 Image Engine:** Resizes featured images with PHP GD using 1.905:1 landscape center-cropping and vertical portrait glass-blur backdrop padding.
* **Internal Linking Engine:** Automatically attaches a styled "আরও পড়ুন" widget linking to related news from the same category.
* **Concurrency & Rate Governance:** Features an automated run lock, execution time budget guards, and configurable daily post caps.

== Installation ==

1. Download `kstnews-ai-autopilot.zip`.
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP file and click **Install Now**, then **Activate Plugin**.
4. Navigate to **KSTNews AI Bot** in the WordPress admin menu.
5. Enter your free **Gemini API Key** from [Google AI Studio](https://aistudio.google.com/app/apikey).
6. Set your target Telegram channel (e.g. `basherkella`), keep status as **Draft** for initial verification, and click **Save Settings**.
7. Click **Test Gemini Connection** to verify API connectivity.
8. Click **Fetch & Publish Now** to run an immediate ingestion cycle.

== Frequently Asked Questions ==

= Does this require a paid server, VPS, or Telegram Bot token? =
No. The plugin runs entirely inside your standard WordPress hosting via `wp_remote_get` on public web previews and calls Google Gemini's REST API.

= How does it ensure Google AdSense compliance? =
The plugin implements two safety layers:
1. Fast local pre-filter blocking gambling, promo keywords, affiliate buttons, and spam links.
2. Structured Gemini safety gate evaluating each article for violence, adult themes, or policy violations.

= Does it support vertical photos from Telegram? =
Yes. The plugin uses PHP GD to generate an exact 1200×630 thumbnail. For vertical photos, it creates a softened, blurred backdrop and centers the sharp original photo, qualifying for Google Discover cards.

== Changelog ==

= 1.0.0 =
* Initial production release.
* Automated Telegram scraping, dual-layer filtering, Gemini 2.5 Flash generation, 1200x630 GD image processing, Yoast/RankMath/AIOSEO meta sync, and WP-Cron scheduling.
