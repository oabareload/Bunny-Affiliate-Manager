# Changelog — Bunny Affiliate Manager

All notable changes to this project are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [0.2.7] — 2025-08-14

### Added

- **Broken Link Reporting system** — visitors on the interstitial page can now click "Report broken link" to flag a redirect token as broken.
- **Dashboard section: Broken Link Reports** — new card at the bottom of the WPAM Dashboard showing all reported tokens with count, originating post, and last reported date. Per-entry and bulk clear actions included.
- **AJAX endpoint `wpam_report_broken_link`** — accepts both logged-in and logged-out users (`wp_ajax_nopriv` + `wp_ajax`). Registered in `define_global_hooks()` so it fires on all requests including `admin-ajax.php`.
- **Storage option `wpam_broken_link_reports`** — single WordPress option storing a token-keyed array: `{ count, post_id, last_reported }` per entry. No custom table required.
- **Admin-post handlers** — `wpam_clear_broken_report` and `wpam_clear_all_broken_reports` for individual and bulk clearing, both secured with nonce + `manage_options` capability.

### Fixed

- **Token propagation** — `Redirect_Manager::handle()` now forwards the resolved token into the `$destination` array passed to `Interstitial_Renderer::render()` via `array_merge`. Previously `$destination['token']` was always empty, making the report button non-functional.
- **AJAX nonce** — `wp_create_nonce( 'wpam_report_nonce' )` is now generated server-side in the renderer and embedded in `data-nonce` on the report button. The handler verifies it via `check_ajax_referer()`. Eliminates the unauthenticated endpoint.
- **Token format validation** — handler now rejects any `token` value that doesn't match `/^[a-f0-9]{8}$/`, matching the format enforced by `generate_token()`.
- **Anti-spam throttle** — handler checks `last_reported` timestamp and silently skips (HTTP 200, no increment) if the same token was reported within the last 10 minutes.
- **Dashboard data consistency (FIX 4)** — if a prior report entry stored `post_id = 0` (e.g. from a race condition during early token resolution), subsequent reports for the same token backfill `post_id` when a valid one is available.

### Changed

- `class-interstitial-renderer.php`: added `$nonce` variable; added `data-nonce` attribute to report button; JS XHR body now includes `&nonce=...`.
- `class-admin-menu.php`: `handle_report_broken_link()` method hardened with nonce check, regex token validation, throttle guard, and post_id backfill. Three new methods added: `render_broken_reports_section()`, `handle_clear_broken_report()`, `handle_clear_all_broken_reports()`. New constant `REPORTS_OPTION`.
- `class-redirect-manager.php`: `render()` call now uses `array_merge( $destination, [ 'token' => $token ] )`.
- `class-plugin.php`: four new hook registrations — two AJAX (global) and two admin-post (admin).
- `readme.md`: added **Broken Link Reporting** section documenting the feature, storage structure, dashboard, and security model.

### Notes

- No database migrations required. The option is created on first report.
- No changes to redirect token generation, analytics, or the clicks table.
- The report button is non-blocking: countdown and redirect run independently.
- No personal data (IP, user agent) is stored in reports.

---

## [0.2.6] — Interstitial Content Slots & Layout Controls

### Added

- **Interstitial Width** setting with 5 sizes: 460px (default), 600px, 800px, 1000px, Full Width.
- **Content Slots** section in Settings for configurable promotional content inside the interstitial page.
- Supported slot types: Custom HTML, Image + Link.
- Available slot positions: Before Disclaimer, After Disclaimer, Before Related Post, After Related Post.
- Scalable `content_slots` indexed array structure designed to support multiple slots in future releases.

### Changed

- `class-interstitial-renderer.php`: dynamic width classes; `render_content_slots()` private method.
- `class-settings.php`: new fields and sanitization for width and content slots.
- `interstitial.css`: responsive width classes, slot styles.
- Admin footer overlap fix applied to all WPAM admin pages.

---

## [0.2.5] — Interstitial Improvements & Analytics Controls

### Added

- Affiliate-specific disclaimer support.
- Affiliate-related post card on interstitial.
- Optional related post excerpt display.
- Setting to exclude administrators from analytics (enabled by default).
- "Clear Analytics" maintenance tool in Dashboard.

### Fixed

- Administrator exclusion logic in analytics tracking.
- Redirect flow when admin analytics exclusion is enabled.
- Undefined variable warning in `Redirect_Manager`.

---

## [0.2.4] — Maintenance: Rebuild Token Map

### Added

- "Rebuild Token Map" tool in Dashboard Maintenance card.
- `admin_post_wpam_rebuild_token_map` handler: clears `wpam_redirect_tokens`, scans all posts with `_wpam_links`, calls `rebuild_token_map()` for each, redirects with success notice.

---

## [0.2.3] — Dashboard Analytics MVP

### Added

- Analytics cards: Clicks Today, Last 7 Days, Last 30 Days, Total Clicks.
- Top Affiliates table (top 10 by clicks) with logo, bar, and percentage.
- Top Posts table (top 10 by clicks) with thumbnail and edit link.
- Recent Clicks table (last 20) with timestamp, affiliate, post, and destination host.

---

## [0.2.1] — Click Tracking SQL

### Added

- `{prefix}wpam_clicks` table via `dbDelta()`.
- `Click_Tracker::record()` — inserts clicks; IP stored as HMAC-SHA256 hash only.
- Legacy meta migration (`maybe_migrate_legacy_clicks()`), idempotent.

---

## [0.2.0-alpha] — Redirect System

### Added

- `/go/{token}` rewrite rule endpoint.
- `Redirect_Manager` — resolves token → post_id + link_index → destination URL.
- `Interstitial_Renderer` — standalone HTML page with countdown, affiliate info, disclaimer, and button.
- Token map stored in `wpam_redirect_tokens` option, rebuilt on `save_post`.
- `wp_safe_redirect()` for instant redirects; `allowed_redirect_hosts` filter for external domains.

---

## [0.1.4] — Auto-detect Affiliate in Post Editor

### Added

- `domain-detector.js` shared module (`window.WPAMDomainDetector`).
- Auto-detection by domain with 500ms debounce in the post meta box.
- Visual chip preview (logo + name + brand color) on detected affiliate.

---

## [0.1.3] — Auto-detect Affiliate by Domain

### Added

- `wpam_normalize_domain()` and `wpam_extract_domain_from_url()` helpers.
- `Repository::find_by_domain()` for exact + suffix matching.
- Auto-detection in Post Affiliates board (client + server side).

---

## [0.1.2] — Post Affiliates State Fixes

### Fixed

- Cancel/Remove no longer permanently removes rows from the visual board.
- Snapshot now stores serialized HTML string, not live jQuery references.
- Flex layout fix for the board (removed broken CSS Grid approach).

---

## [0.1.1] — Post Affiliates UX Fixes

### Fixed

- "Add Link" always creates a new row (no reuse of existing DOM node).
- Cancel/Save correctly destroy temporary state.

### Added

- Post status filter (All / Published / Draft / Scheduled).

---

## [0.1.0] — Post Affiliates Board

### Added

- New admin screen: Post Affiliates — manage affiliate links per post from one place.
- AJAX actions: `wpam_load_posts`, `wpam_save_post_links`.
- Inline editor (expand/collapse, no modal).

---

## [0.0.7] — Bunny Admin UI

### Added

- Shared `bunny-*` admin UI system across all Bunny plugins.
- Sticky admin header, tab nav, version badge.
- `bunny-admin.css` loaded as dependency before `admin.css`.

---

## [0.0.6] — Inline CRUD + Dashboard Fix

### Added

- Inline affiliate create/edit (no page reload).
- Affiliate fields: `domains`, `visible`.
- AJAX actions: `wpam_save_affiliate`, `wpam_get_edit_row`.

### Fixed

- Dashboard "Posts with Affiliates" counter now queries `_wpam_links` correctly.

---

## [0.0.4] — Render Engine

### Added

- `Render_Engine` with `the_content` filter and `[wpam_links]` shortcode.
- Render modes: `disabled`, `after_content`, `before_content`, `shortcode_only`.
- Templates `link-item.php` and `links-wrapper.php` with theme-override support.
- CSS variable `--wpam-brand-color` per affiliate.

---

## [0.0.2] — Per-Post Link System

### Added

- "Affiliate Links" meta box on posts.
- Per-link fields: provider, URL, label, order.
- Real-time affiliate URL preview.
- Save pipeline with nonce, sanitization, and URL validation.

---

## [0.0.1] — Base Architecture + Affiliate System

### Added

- Modular PHP 8 namespace architecture with central Loader.
- Custom Post Type `wpam_affiliate`.
- Affiliate CRUD: create, edit, delete, activate, deactivate.
- WordPress Settings API with per-field sanitization.
- REST API endpoint `/wp-json/wpam/v1/status`.
- Dashboard with live affiliate counters.
