=== Serpulix SEO ===
Contributors: serpulix
Tags: seo, schema, structured data, json-ld, content
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 4.11.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync and publish SEO content from the Serpulix platform and deploy schema.org structured data to your WordPress site.

== Description ==

Serpulix SEO connects your WordPress site to the [Serpulix](https://serpulix.com) SEO platform. From the Serpulix dashboard you can:

* Publish and update SEO-optimized articles and pages as native WordPress posts.
* Deploy and manage schema.org (JSON-LD) structured data for your pages.
* Toggle published content between published and draft from the dashboard.
* Apply Technical SEO fixes (SEO title, meta description, canonical, robots,
  content edits, redirects, and cache purge) from the Serpulix dashboard.

The plugin renders deployed JSON-LD in the page footer and exposes a small REST API
(`serpulix/v1`) that the Serpulix platform uses to sync content and schema. A Serpulix
account and API key are required to use this plugin.

== External services ==

This plugin connects to the Serpulix SEO platform to sync content and structured data.

* What it sends/receives: the plugin authenticates to your Serpulix project with an API
  key you provide, receives article/page content and JSON-LD schema to publish, and sends
  back the resulting WordPress post ID/URL so the dashboard can track and manage it.
  Received content can include stylesheet and script assets belonging to the article
  layout; these are stored in your uploads directory and enqueued only on the posts
  they were published with.
* When: when you trigger a sync/publish from the Serpulix dashboard, and when the plugin
  reports a completed sync back to Serpulix.
* Service: Serpulix — https://serpulix.com (Terms: https://serpulix.com/terms, Privacy:
  https://serpulix.com/privacy).

The plugin also asks GitHub whether a newer release of Serpulix SEO exists. That check
runs on WordPress's normal update schedule, not on every page view. A newer GitHub
Release appears on Plugins and Dashboard → Updates like any other plugin update.

* What it sends: the site asks api.github.com for the latest release of
  https://github.com/devserpulix/serpulix-seo. If a personal access token is defined in
  wp-config.php for a private repository, that token is sent only from the server to
  api.github.com and is not placed in the download URL or shown in the admin.
* What it receives: the release version, notes, and plugin ZIP.
* When: during a WordPress plugin update check, when an administrator opens the update
  details, and when an administrator installs the update.
* Service: GitHub — https://github.com (Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service,
  Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement).

== Installation ==

1. Upload and activate the plugin (if you used the legacy "SEOBot Sync" / seobot-sync
   plugin, just install this one — it imports your existing connection and content
   mapping automatically and deactivates the old plugin; then delete the old plugin).
2. In WP admin, open Serpulix SEO settings and enter your Serpulix API URL + API key.
3. Manage content and schema from the Serpulix dashboard.

== Changelog ==

= 4.11.1 =
* Image Alt Text: "Mark decorative" on an image that has no alt attribute now writes alt="" on the tag
  (and an empty alt in the media library). Before, a missing alt and alt="" were read as the same, so the
  write answered "unchanged" and the page stayed as it was. Pages left alone because a person changed
  them are still left alone.

= 4.11.0 =
* Content is saved exactly as sent. Technical SEO content fixes, Image Alt Text writes and Content
  Studio publishing no longer lose backslashes (block settings, FAQ blocks, inline code), and Technical
  SEO content fixes no longer strip embeds (maps, videos, forms). If WordPress would store a content fix
  differently, the page is put back and Serpulix is told.
* Technical SEO: 404 pages can be redirected (the plugin's own redirect list; loops and redirects over a
  published page are refused).
* Image Alt Text, write protocol 2: each write says what alt Serpulix last saw on every page and the
  plugin leaves alone any page where a person changed it since. Images are matched by their media id
  (a different photo with the same file name is never touched), CDN copies are recognised, "$" and
  backslashes in alt text are written as typed, drafts and private posts are skipped, an alt-only save
  does not change the post's modified date, writes are journalled so a retry after a timeout never
  writes twice, the page cache is purged, and Undo restores every page and the media library alt.
* Image Alt Text: the page-content answer also lists WooCommerce gallery images, ACF image fields and
  Elementor layouts.
* Schema: Content Studio's schema prints under its own comment, and the Analyzer's FAQPage is left out
  when Content Studio already prints one (no duplicate FAQ).

= 4.10.0 =
* Technical SEO: pages served at their own address by another post type (Serpulix landing pages at
  the site root, or another plugin's custom type) are found by their path, so their title and heading
  fixes no longer fail with "no post or page at this address". A post whose permalink is a different
  address is never taken instead.
* SEOPress and The SEO Framework are recognised: titles, descriptions, canonicals and noindex are
  written into their own fields, and Serpulix no longer prints a second title and description next
  to theirs.

= 4.9.2 =
* Technical SEO: a title, description, content or redirect change now clears that page from the
  page cache straight away, the same way a schema change does (WordPress, WP Rocket, LiteSpeed,
  W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround, WP-Optimize, Cache Enabler). Before,
  a SiteGround site kept serving the page as it was, so visitors and search engines saw the old title.
* Cache purge (`/cache/purge`) clears one page when given `post_id` or `url`, and the whole site,
  SiteGround included, when given neither.

= 4.9.1 =
* Schema apply: the JSON-LD is saved with `wp_slash()`. Before, a double quote inside a text made
  the stored schema unreadable (the page then printed none of it) and characters such as `’` or `é`
  were printed as `u2019` / `u00e9`. Schema saved by an older version is repaired the next time it
  is deployed from Serpulix.
* Schema apply: the answer is `success` only when the stored schema reads back correctly.
* Schema apply and removal clear that page's cache (`clean_post_cache`) and purge the page in
  WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround
  Optimizer, WP-Optimize and Cache Enabler when one of them is active, so the change shows without
  waiting for the cache to expire. The answer lists what was purged in `cache_purged`.
* New action `serpulix_schema_changed` (`$post_id`, `$url`) for purging any other cache.
* Removing the schema from a blog-index front page deletes the stored option.

= 4.9.0 =
* Bing Optimization: published posts and pages that are updated, unpublished, trashed or deleted
  are reported to Serpulix (`POST /api/indexnow/events`) so they reach IndexNow or Bing. Sent once
  per request on shutdown, non-blocking. Pages pushed by Serpulix itself are not reported twice.
* `GET /status` reports `indexnow_events: true`.

= 4.8.0 =
* Alt tags v1.5: nav menu page list (`GET /alt-tags/menu`), rendered page content by
  URL (`GET /alt-tags/page-content`), image URL to attachment resolve
  (`POST /images/resolve`), and per-place `places` in the write response.
* Attachment lookup also strips `-e123...` edit suffixes.

= 4.7.1 =
* Alt tags: decorative writes keep `alt=""` (empty meta, attribute present).
* Alt tags: write receipt `previous_alt` comes from the page `<img>`, not
  attachment meta, so revert restores block-editor values.

= 4.7.0 =
* Technical SEO: REST endpoints for page resolve, SEO field writes (Yoast,
  Rank Math, AIOSEO, or Serpulix fallback — never changes post_title for a
  title fix), content edits with hash check, redirects, and cache purge.
* Status endpoint advertises `tech_seo: true` for the Serpulix dashboard.
* Front-end output for Serpulix SEO title/meta/canonical/robots when no
  third-party SEO plugin is active; applies stored redirects.

= 4.6.0 =
* Native WordPress update check from GitHub Releases.

= 4.5.0 =
* Show a GitHub update on Plugins and Dashboard → Updates without waiting
  for WordPress's 12-hour update cache.

= 4.4.0 =
* Confirm native WordPress plugin updates from GitHub Releases.

= 4.3.0 =
* GitHub Releases now power updates automatically from
  https://github.com/devserpulix/serpulix-seo. WordPress shows the native
  plugin update banner. No GitHub owner or repository settings are required.

= 4.2.0 =
* Alt tags: list images on published posts and pages (`GET /serpulix/v1/images`),
  write alt into the media library and the matching `<img>` in post content, and
  revert that write. Same API key as the rest of `serpulix/v1`.
* GitHub Releases supply plugin updates automatically (devserpulix/serpulix-seo).
  WordPress.org registration is not required. Drafts and prereleases are ignored.

= 4.1.0 =
* Cleanup release for the WordPress.org directory review.
* Removed legacy REST namespace `seobot/v2` (the platform calls `serpulix/v1` only) and
  the unused `/update-status` route.
* Removed legacy sync callbacks and legacy content-type endpoints that pointed at
  retired platform APIs.
* Removed unused admin JavaScript/CSS and their AJAX endpoints.
* Removed legacy hidden taxonomies (seo_location, seo_keyword) that were no longer
  written or read; existing terms in the database are untouched.
* Constant-time API key comparison everywhere; removed debug logging and
  development-only connection code.

= 4.0.3 =
* Fixed: scheduled and backdated posts now stamp post_date in the blog's local
  timezone (was UTC wall-clock, which shifted the displayed publish time by the
  site's UTC offset and could show the wrong day for backdates near midnight).
  The actual publish moment was always correct (driven by post_date_gmt).

= 4.0.2 =
* Admin menu rebranded to "Serpulix SEO" and decluttered — hid the legacy Locations/Keywords
  taxonomies from the menu (kept registered for backward compatibility).

= 4.0.1 =
* Renamed to "Serpulix SEO" (slug serpulix-seo) for the WordPress.org directory.
* Relicensed GPLv2 or later.
* Seamless migration from the legacy seobot-sync plugin: connection settings and the
  post mapping (_seobot_page_id) carry over automatically; the old plugin is deactivated
  on activation. No reconnect or re-sync required.
* Security: JSON-LD output hardened against script-context breakout (JSON_HEX_TAG); removed
  an unused debug handler.
* Unique function/class/constant prefix (serpulix_seo_*) so the plugin activates cleanly
  alongside the legacy plugin during migration (no redeclaration conflict).

= 3.3.1 =
* Security hardening of JSON-LD output; removed unused debug code. (legacy seobot-sync)
