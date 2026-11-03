# Milestone 4 Report: Gemini 2.5 Flash Client & Schema Enforcement

**Date:** 2026-11-03
**Status:** Completed
**Component:** `KSTNews_AI_Gemini_Client` (`includes/class-gemini-client.php`)

---

## 1. What Was Built

1. **Gemini 2.5 Flash REST Integration:**
   - Implemented `test_connection()` for quick verification from the admin dashboard.
   - Built `generate_article()` using Gemini 2.5 Flash with strict `responseMimeType: application/json` and `responseSchema`.
   - Structured JSON Schema enforced on API level:
     - `isGenuineNews` (bool)
     - `isAdSenseSafe` (bool)
     - `rejectReason` (string)
     - `title` (Bengali, max 70 chars)
     - `contentHtml` (300+ words, `<p>` and `<h2>` subheadings)
     - `englishSlug` (SEO URL slug, max 60 chars)
     - `metaDescription` (summary, max 155 chars)
     - `category` (taxonomy category)
     - `tags` (array of strings)
     - `imageAltText` (descriptive alt text)

2. **Dual-Layer Gate (Layer 2 - Gemini Safety Gate):**
   - Automatically blocks articles if `isGenuineNews` is false or `isAdSenseSafe` is false.
   - Evaluates AdSense compliance (detecting graphic violence, blood, adult content, vulgarity, hate speech, and commercial promotions).

3. **Validation & Quality Assurance:**
   - Enforces 300+ Bengali words (Unicode word counting after stripping HTML).
   - Enforces presence of at least two `<h2>` subheadings.
   - Enforces lowercase hyphenated English URL slug.
   - Content sanitization with `wp_kses()` allowing only approved semantic tags (`p, h2, h3, ul, ol, li, strong, em, a, br`).
   - Automated retry with corrective prompt if initial output is under 300 words.

4. **Resilience & Rate Limit Handling:**
   - Exponential backoff retry loop on HTTP 429 and 5xx responses (up to 3 retries).

---

## 2. Tests Run & Results

- Command: `php tests/test-gemini.php`
- Assertions executed: **18**
- Result: **All 18 assertions passed (100%)**
  - Empty API key rejection verified.
  - Valid API key test connection verified.
  - Invalid API key error extraction verified.
  - AI Safety Gate (Layer 2) verified on graphic violence (blocked with reason).
  - AI Safety Gate (Layer 2) verified on commercial promo (blocked with reason).
  - 300+ word Bengali article generation with H2 subheadings, slug, and meta verified.
  - HTML tag sanitization verified (dangerous tags like `<script>` and `<iframe>` stripped).
- PHP Syntax Linting: Passed without warnings.

---

## 3. Known Issues & Notes

- None. Ready to proceed to **Milestone 5: Image processor**.
