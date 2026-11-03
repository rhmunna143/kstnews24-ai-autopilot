# Development & Deployment Plan: KSTNews24 Telegram-to-WordPress AI AutoPilot

This document outlines the complete architectural, implementation, and operational plan for automating **kstnews24.com** using the **KSTNews24 AI Telegram AutoPilot** WordPress plugin powered by Gemini 2.5 Flash.

---

## 1. Project Overview & Objectives

* **Goal:** Fully automate news gathering, SEO polishing, media processing, and publishing from target Telegram channels (e.g., `basherkella`) to the WordPress news portal `kstnews24.com`.
* **Zero Cost Strategy:** Runs 100% inside existing Hostinger shared WordPress hosting with **zero VPS or server cost**.
* **AdSense & SEO Compliance:** Incorporates strict pre-filters and Gemini AI gates to screen out advertisements and policy-violating content, while generating 300+ word structured articles, English slugs, meta descriptions, and resized 1200×630 open-graph thumbnails.

---

## 2. Technical Stack

* **Backend Platform:** WordPress REST API & Custom Plugin Architecture (PHP 8.x)
* **AI Engine:** Google Gemini 2.5 Flash via REST (`responseMimeType: application/json` with strict schema enforcement)
* **Media Processing:** Native PHP GD Library (Image cropping, 1200×630 background blur/padding for vertical photos, and JPEG compression)
* **Trigger Mechanism:** WordPress built-in background scheduler (`wp_schedule_event`) triggered by natural site traffic

---

## 3. Step-by-Step Implementation Roadmap

### Phase 1: Environment & Credentials Setup

1. **Google AI Studio:** Obtain a free Gemini API key.
2. **WordPress Admin (`[kstnews24.com/wp-admin](https://kstnews24.com/wp-admin)`):** Ensure administrator access.
3. **Telegram Source:** Identify target public channel usernames (e.g., `basherkella`).

### Phase 2: Plugin Deployment (`kstnews-ai-autopilot.zip`)

1. Create the plugin file (`kstnews-ai-autopilot.php`) containing:
* **Admin UI:** Sidebar menu with settings (`gemini_key`, `tg_channel`, `post_status`, `auto_cron`) and manual action buttons.
* **Web Scraper:** Public Telegram web preview parser (`t.me/s/channel`) extracting message IDs, raw text, photo URLs, video thumbnails, and direct MP4 links.
* **Dual-Layer Ad/Spam Filter:** Quick keyword/inline-button pre-filter + Gemini AI safety check (`isGenuineNews`, `isAdSenseSafe`).
* **SEO Content Expander:** Generates 300+ word structured articles with `h2` subheadings, English slugs (`englishSlug`), and meta descriptions (`metaDescription`).
* **Image Resizer & Watermark Prep:** Automatically resizes featured images to `1200×630` using PHP GD with a smart portrait-padding/landscape-crop algorithm.
* **Internal Linking Engine:** Automatically injects "আরও পড়ুন" (Read More) links from the same category.


2. Compress into `kstnews-ai-autopilot.zip`, upload via **Plugins → Add New**, and activate.

### Phase 3: Configuration & Initial Test Run

1. Navigate to **KSTNews AI Bot** in the WordPress admin sidebar.
2. Enter your **Gemini API Key**, set channel to `basherkella`, and choose `Publish` or `Draft`.
3. Click **Save Settings**.
4. Click **Fetch & Publish Now** to run an immediate test and review the output table in the Activity Log.

---

## 4. Google AdSense & SEO Optimization Checklist

| Requirement | Implementation Details in Plugin |
| --- | --- |
| **Content Length (300+ words)** | Gemini expands breaking notes into structured multi-paragraph articles with H2 subheadings. |
| **AdSense Policy Safety** | AI blocks graphic violence, blood, adult themes, and commercial promotions. |
| **Clean English URLs** | Generates SEO-friendly lowercase URL slugs (`englishSlug`) instead of encoded characters. |
| **Google Discover Ready** | Resizes all featured thumbnails to `1200 × 630` pixels to qualify for large image preview cards. |
| **Trust & Policy Pages** | Requires manual setup of *About Us*, *Contact*, *Privacy Policy*, *Terms*, and *Editorial Policy* in the footer. |

---

## 5. Maintenance & Monitoring

* **Activity Log:** Check the **KSTNews AI Bot** dashboard table daily to monitor published articles and blocked spam posts.
* **State Reset:** Use the **Reset State** button if you ever need to clear history or re-sync recent channel posts.