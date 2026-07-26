# Bunny Affiliate Manager

A modular and scalable affiliate link management plugin for WordPress. Lets creators and publishers register affiliates, assign affiliate links to posts, redirect clicks through a trackable `/go/` endpoint with an optional interstitial page, and measure performance through a built-in Views + Clicks + Score analytics system — all exposed to companion plugins (e.g. Bunny Magazine) via an internal read-only API.

## Plugin metadata

- Plugin Name: Bunny Affiliate Manager
- Plugin URI: https://bunnychase.net/bunny-affiliate-manager/
- Author: BunnyChase
- Author URI: https://bunnychase.net/
- Text Domain: wp-affiliatemanager
- Domain Path: /languages
- License: GPLv2 or later
- License URI: https://www.gnu.org/licenses/gpl-2.0.html
- Current version: **1.7.0**

## Requirements

- WordPress 6.0 or newer.
- PHP 8.0 or newer.

## Current scope (v1.7.0)

### Affiliates

- Full affiliate CRUD (create, edit, delete, activate, deactivate) via inline admin UI — no page reload, no modal.
- Per-affiliate fields: name, slug, URL parameter, value, logo (Media Library picker), brand color, domains (used for auto-detection and redirect host allow-listing), **Default URL (fallback)**, visible flag, disclaimer settings, related post.
- **Default URL (fallback):** an optional URL used automatically on any post that doesn't have a specific link for that affiliate. Priority is always post-specific link → Default URL → not shown. No extra toggle — setting the field is what enables the fallback.
- Admin affiliate table with logo, status, parameter, value, domains, and flags columns.

### Post Affiliate Links

- Meta box **"Affiliate Links"** on posts with multi-link support: affiliate (provider), original URL, custom label, order.
- **Automatic affiliate detection:** pasting a URL auto-detects the affiliate by domain (both in the post editor and in the Post Affiliates board). No manual provider selection required. Inline error if no active affiliate matches. Duplicate URL detection per post. Save is blocked until all URLs resolve to a valid affiliate.
- Real-time preview of the generated affiliate URL (no page reload).
- Save pipeline with nonce, sanitization, and strict URL validation (http/https only).
- Orphan detection for links whose provider has been deleted or deactivated.
- **Final URLs are never stored in the database** — they're generated at runtime, so changing an affiliate's parameter propagates to every post automatically.
- **Post Affiliates board:** dedicated admin screen to manage affiliate links across posts — visual rows with thumbnail, status, date, and affiliate chips. Inline editor (expand/collapse, no modal). Incremental loading (20 initial, +10 Load More). Search by title, filter by category and tag.

### Rendering & Layouts

- The affiliate links block supports two predefined **Layouts**, chosen once in **Settings → Appearance → Layout**: **Card** (the original layout — vertical or horizontal list of affiliate buttons) and **Showcase** (a single large card: image, title, description, and the same buttons row underneath — image left / text right on desktop, stacked on mobile).
- Layouts are implemented behind a small `Layout_Interface` contract and a `Layout_Registry`, extensible via the `wpam_render_layouts` filter — adding a future layout doesn't require touching `Render_Engine`.
- Button-display options (logo/name, CTA text, hide CTA, frontend order) are shared by both Layouts — configured once, not duplicated per Layout. Settings only shows the fields relevant to the currently selected Layout.
- An optional **section heading** (enable/disable, text, HTML tag H1–H6 or `div`, default H2) is shared across every Layout and rendered once, above the block, regardless of which Layout is active.
- Showcase's image/title/description can each be sourced independently: Featured Image or a custom URL; the post title, custom text, or hidden; the post's manual excerpt, custom text, or hidden. Like the interstitial's related-post excerpt, **only the manual `post_excerpt` field is ever used — content is never auto-trimmed.**

### Redirect & Click Tracking

- Every affiliate link is served through a trackable, non-guessable endpoint: `/go/{token}`, where `{token}` is a deterministic 8-character HMAC hash (`post_id` + `link_index` + site secret) — same link always produces the same token, but it can't be reverse-engineered externally.
- The token map (`token → post_id + link_index`) is rebuilt automatically whenever a post is saved, and can be rebuilt manually from **Settings → Maintenance**.
- **Fallback (Default URL) links use a separate, stateless route: `/goa/{post_id}/{affiliate_id}/`.** It doesn't use the token map at all — it resolves live, on each click, against the same canonical link-resolution method the frontend uses (`Post_Links::get_links()`), so a post-specific link added after the page was rendered/cached is honored automatically, and there's no map to keep in sync when an affiliate's Default URL changes.
- On each redirect: the click is recorded (post, affiliate, destination URL, timestamp) before the visitor is sent onward. Recording failure never blocks the redirect.
- Optional **interstitial page**: a branded countdown page shown before the final redirect (configurable delay, or `0` to redirect instantly). Includes a **"Report broken link"** button on `/go/` links (see Broken Link Reporting below); not shown on `/goa/` fallback links, which don't have a reportable token.
- `allowed_redirect_hosts` is populated dynamically from every active affiliate's configured domains, so `wp_safe_redirect()` never blocks a legitimate destination.
- Admins can be excluded from click analytics via **Settings → General → Exclude admins from analytics**.

### Views Tracking

- Native page-view counter, independent of any third-party plugin: one row per `post_id + day`, incremented via an atomic upsert.
- Tracked via a lightweight AJAX beacon (native `Fetch` API, config injected with `wp_add_inline_script()`, never `wp_localize_script()`) — fully compatible with full-page caching, since the count happens after the cached HTML is served.
- Deduplicated per visitor per day via an HttpOnly cookie (`wpam_v`) — the server, never the client, decides whether a view already counted.
- Eligibility rules (bot filtering, whether to count admins, whether to count logged-in users) are governed by **Settings → Views** and evaluated through a single source of truth (`Views::is_eligible()`), used both when deciding whether to enqueue the beacon and when validating the AJAX request server-side.
- **One-time importer** from the *Post Views Counter* plugin (**Settings → Maintenance → Import Views**): additive upsert (adds to existing counts, never overwrites), never modifies the source table, and can only run once per site.

### Recently Viewed Posts

- Per-visitor "Recently Viewed" history via its own 30-day cookie (`wpam_rv`), independent of the daily views-dedup cookie — reuses the same beacon/AJAX pipeline, no separate tracking endpoint.
- Auto-inserted at the end of post content (configurable in **Settings → Recently Viewed**: enable/disable, auto-insert, title, number of items shown).
- Visual card list (image-only, Contextual-Related-Posts-inspired layout); posts without a featured image are omitted.

### Broken Link Reporting

- A "Report broken link" button on the interstitial page lets visitors flag a redirect as broken, with a 10-minute per-token throttle to prevent spam.
- Reports are stored in a single option (`wpam_broken_link_reports`), no custom table required.
- Reviewed and cleared from the dedicated **Broken Reports** admin page.

### Top Posts / Top Viewed Posts / Top Scored Posts

- Shared query infrastructure (`Top_Posts_Query`, `Views_Query`, `Score_Query`) powers three independent rankings, each with its own object cache (300s TTL):
  - **Top Clicked Posts** — ranked by redirect clicks.
  - **Top Viewed Posts** — ranked by page views.
  - **Top Scored Posts** — ranked by a combined weighted score: `score = views × 1 + clicks × 25` (weights defined as public constants on `Score_Query`, ready to move to Settings in a future version).
- All three support optional filtering by categories, tags, authors, and post type.
- **Shortcode** `[wpam_top_posts]` and **Widget** ("Top Posts (Bunny Affiliate)") render the Top Clicked Posts ranking on the frontend, with configurable period, layout, thumbnail size, and item count.

### Analytics & Dashboard (admin)

- **Dashboard** — executive summary: total/active affiliate counts, posts-with-affiliates count, an overview row (Total Score / Views / Clicks / Affiliates), recent activity (last 20 clicks, last 20 views), a Top 10 Overall list (by Score), and quick-access links to the other screens.
- **Analytics** — dedicated screen with three horizontal tabs (**Score**, **Clicks**, **Views**), each with its own Today / Last 7 Days / Last 30 Days / All Time filter cards and ranking tables (Top Affiliates, Top Clicked Posts, Top Viewed Posts, Top Scored Posts, Recent Clicks, Recent Views). All three tabs share a single AJAX endpoint and a single client-side filter implementation.
- **Settings** — plugin configuration (render mode, disclaimer, views, recently viewed, general options) plus **Maintenance** actions: rebuild the redirect token map, clear analytics, one-time Post Views Counter import.
- **Broken Reports** — dedicated page listing every reported broken link, with per-report and clear-all actions.

### Internal API (`WPAM_API`)

Read-only API intended for consumption by companion plugins (e.g. Bunny Magazine). No direct SQL, no HTML output — pure data, sourced exclusively from the same cached Query classes used by the admin screens:

```php
WPAM_API::get_top_posts( array $args = [] ): \WP_Post[]          // by clicks
WPAM_API::get_top_viewed_posts( array $args = [] ): \WP_Post[]   // by views
WPAM_API::get_top_scored_posts( array $args = [] ): \WP_Post[]   // by score
```

All three accept the same `$args` shape (`period`, `limit`, `post_type`, `categories_include/exclude`, `tags_include/exclude`, `authors_include/exclude`) and return enriched `WP_Post[]` objects with a dynamic count property (`wpam_click_count` / `wpam_view_count` / `wpam_score`) and `wpam_thumbnail`. Each exposes its own filter hook (`wpam_api_top_posts`, `wpam_api_top_viewed_posts`, `wpam_api_top_scored_posts`) for final adjustments by consuming plugins.

### General

- Public helpers: `wpam_render_links()`, `wpam_get_rendered_links()`, plus affiliate/post-link query helpers (see below).
- REST API status endpoint at `/wp-json/wpam/v1/status`.
- Conditional asset loading: CSS/JS only enqueued on the screens/posts that actually need them.
- Brand color CSS variable (`--wpam-brand-color`) per affiliate for accent styling.
- No React or JavaScript framework dependency; the admin UI is intentionally lightweight (vanilla JS + jQuery, WordPress Settings API, Meta Boxes).

## File structure

```text
Bunny-Affiliate-Manager/
├── readme.md
├── CHANGELOG.md
├── wp_affiliatemanager/
│   ├── assets/
│   │   ├── css/
│   │   │   ├── admin.css
│   │   │   ├── bunny-admin.css
│   │   │   ├── frontend.css
│   │   │   ├── interstitial.css
│   │   │   ├── post-affiliates.css
│   │   │   ├── post-links.css
│   │   │   ├── settings.css
│   │   │   ├── showcase.css                  — Showcase Layout only (v1.6.0)
│   │   │   └── top-posts-widget.css
│   │   ├── js/
│   │   │   ├── admin.js
│   │   │   ├── analytics.js
│   │   │   ├── domain-detector.js
│   │   │   ├── frontend.js
│   │   │   ├── post-affiliates.js
│   │   │   ├── post-links.js
│   │   │   ├── settings.js                   — Layout field toggling (v1.6.0)
│   │   │   └── views-beacon.js
│   │   └── images/
│   ├── includes/
│   │   ├── admin/
│   │   │   ├── class-admin.php
│   │   │   ├── class-admin-assets.php
│   │   │   ├── class-admin-menu.php          — Dashboard, Settings, Broken Reports
│   │   │   ├── class-analytics-screen.php    — Analytics page (Score/Clicks/Views tabs)
│   │   │   ├── class-analytics-renderer.php  — shared render-only helpers
│   │   │   ├── class-affiliates-screen.php
│   │   │   └── class-post-affiliates-screen.php
│   │   ├── affiliates/
│   │   │   ├── class-affiliates.php
│   │   │   ├── class-cpt.php
│   │   │   ├── class-meta.php
│   │   │   ├── class-repository.php
│   │   │   └── helpers-affiliates.php
│   │   ├── analytics/
│   │   │   └── class-score-query.php         — combined views+clicks score ranking
│   │   ├── api/
│   │   │   ├── class-api.php                 — REST status endpoint
│   │   │   └── class-wpam-api.php            — public read-only API
│   │   ├── frontend/
│   │   │   ├── class-frontend.php
│   │   │   ├── class-frontend-assets.php
│   │   │   ├── class-render-engine.php       — orchestrator only since v1.6.0
│   │   │   ├── layouts/                      — Layout system (v1.6.0)
│   │   │   │   ├── interface-layout.php
│   │   │   │   ├── class-layout-registry.php
│   │   │   │   ├── class-layout-card.php
│   │   │   │   └── class-layout-showcase.php
│   │   │   ├── components/                   — shared building blocks (v1.6.0)
│   │   │   │   └── class-button-row.php
│   │   │   ├── class-top-posts-query.php     — Top Clicked Posts data source
│   │   │   ├── class-top-posts-renderer.php
│   │   │   ├── class-shortcode-top-posts.php — [wpam_top_posts]
│   │   │   ├── class-widget-top-posts.php
│   │   │   └── helpers-render.php
│   │   ├── posts/
│   │   │   ├── class-post-links.php
│   │   │   └── helpers-post-links.php
│   │   ├── redirect/
│   │   │   ├── class-clicks-table.php
│   │   │   ├── class-click-tracker.php
│   │   │   ├── class-redirect-manager.php    — /go/{token} endpoint
│   │   │   ├── class-interstitial-renderer.php
│   │   │   └── helpers-redirect.php
│   │   ├── settings/
│   │   │   └── class-settings.php
│   │   ├── templates/
│   │   │   ├── class-templates.php
│   │   │   └── views/
│   │   │       ├── affiliate-card.php
│   │   │       ├── link-item.php
│   │   │       ├── links-wrapper.php
│   │   │       ├── recently-viewed.php
│   │   │       └── showcase-block.php        — Showcase Layout markup (v1.6.0)
│   │   ├── views/
│   │   │   ├── class-views.php               — eligibility + AJAX orchestration
│   │   │   ├── class-view-tracker.php        — atomic upsert
│   │   │   ├── class-views-table.php
│   │   │   ├── class-views-query.php         — Top Viewed Posts data source
│   │   │   ├── class-views-importer.php      — one-time Post Views Counter import
│   │   │   └── class-recently-viewed.php
│   │   ├── class-activator.php
│   │   ├── class-deactivator.php
│   │   ├── class-loader.php
│   │   ├── class-plugin.php
│   │   └── helpers.php
│   ├── languages/
│   ├── uninstall.php
│   └── wp_affiliatemanager.php
```

## Architecture decisions

- Modular class-oriented architecture with PHP 8 namespaces; each module has a single responsibility, no cross-module direct dependencies.
- Hooks (actions and filters) are registered centrally through the `Loader` class, except where technically impossible (e.g. shortcodes, or `Render_Engine`'s content-dependent filter).
- Singleton pattern applied only where a single shared instance is strictly appropriate.
- WordPress Settings API, Meta Boxes, and `WP_Query` are preferred over custom solutions. AJAX is used only where it provides genuine UX value; server rendering is the default.
- **Single source of truth per concern**: each data domain (clicks, views, score) has exactly one Query class (`Top_Posts_Query`, `Views_Query`, `Score_Query`) that owns its SQL and its object cache. Admin screens and the public API consume these classes directly — they never query the database themselves.
- **Layouts as predefined, extensible building blocks (v1.6.0)**: `Render_Engine` never renders layout-specific markup itself. It resolves links, resolves the active Layout via `Layout_Registry`, delegates rendering, then prepends the shared section heading. Each Layout (`Layout_Card`, `Layout_Showcase`) implements a small `Layout_Interface` contract and owns only its own markup/options; a shared `Button_Row` component renders the affiliate buttons for both, so button logic is never duplicated. New Layouts register in `Layout_Registry` or via the `wpam_render_layouts` filter — `Render_Engine` itself never needs to change. Deliberately not a page builder: Layouts are fixed, predefined structures, not composable blocks.
- **Render vs. data separation**: `Analytics_Renderer` and `Top_Posts_Renderer` produce HTML exclusively from data already fetched by a Query class (plus safe WordPress helper calls like `get_post()`, `get_edit_post_link()`); they never run SQL.
- Affiliate URLs are generated at runtime via `wpam_generate_affiliate_url()` — final URLs are never stored in the database.
- The Render Engine uses an in-memory cache per `post_id + style` to avoid duplicate renders within the same request.
- Some low-level query logic (e.g. taxonomy/author filtering) is intentionally duplicated across `Top_Posts_Query`, `Views_Query`, and `Score_Query` rather than abstracted into a shared base class — this keeps each module fully independent and safe to evolve separately.
- No React or JavaScript framework dependency; the admin UI is intentionally lightweight.

## Shortcode

```
[wpam_links]
[wpam_links style="horizontal"]
[wpam_links style="vertical" post_id="42"]

[wpam_top_posts]
[wpam_top_posts period="week" layout="vertical" limit="5"]
[wpam_top_posts title="Most Read" show_thumbnail="no" thumbnail_size="large"]
```

`[wpam_links]` renders the affiliate links assigned to the current post (or the specified `post_id`). Orphan links (whose provider has been deleted or deactivated) are silently omitted.

`[wpam_top_posts]` renders the Top Clicked Posts ranking. Attributes: `title`, `show_title`, `period` (today|week|month|total), `layout` (horizontal|vertical), `thumbnail_size`, `limit` (1–100), `max_width`, `show_thumbnail`.

## Render modes

Configured in **Settings → Render mode**:

- `disabled` — nothing is rendered automatically; use shortcode or helpers manually.
- `after_content` — block appended after post content (default).
- `before_content` — block prepended before post content.
- `shortcode_only` — automatic injection disabled; only `[wpam_links]` works.

## Theme override

Drop files in `/wp-content/themes/YOUR-THEME/wpam/` to override any template:

- `link-item.php` — individual link row (shared by both Layouts).
- `links-wrapper.php` — Card Layout's outer wrapper with style class and data attributes.
- `showcase-block.php` — Showcase Layout's markup (image, title, description, buttons).
- `affiliate-card.php` — legacy card template.
- `recently-viewed.php` — Recently Viewed block wrapper.

## URL generator API

```php
// Generate an affiliate URL by affiliate ID
wpam_generate_affiliate_url( int $affiliate_id, string $url ): string

// Generate an affiliate URL by affiliate slug
wpam_generate_affiliate_url_by_slug( string $slug, string $url ): string
```

Both functions return the unmodified URL if the affiliate is inactive.

## Helper functions

**Affiliates**

```php
wpam_get_affiliate( int $id ): ?array
wpam_get_affiliates( array $args = [] ): array
wpam_is_affiliate_active( int $id ): bool
```

**Per-post links**

```php
wpam_get_post_links( int $post_id, bool $active_only = false ): array
wpam_get_resolved_post_links( int $post_id, bool $active_only = true ): array
wpam_get_post_link( int $post_id, int $index ): ?array
wpam_post_has_links( int $post_id, bool $active_only = false ): bool
wpam_get_post_links_count( int $post_id, bool $active_only = false ): int
wpam_post_link_is_orphan( int $post_id, int $index ): bool
wpam_normalize_link_item( array $item ): array
```

`wpam_get_post_links()` returns only links stored explicitly in `_wpam_links` — used by the "Affiliate Links" meta box and the Post Affiliates board. `wpam_get_resolved_post_links()` additionally merges in fallback links generated from each active affiliate's Default URL (see Affiliates section above) for any affiliate the post doesn't already have a specific link for; `Render_Engine` uses this one to paint the frontend. Every link item carries a `'_wpam_is_default'` key indicating whether it's an explicit or a synthetic fallback entry.

**Render Engine**

```php
// Print affiliate links of a post
wpam_render_links( int $post_id = 0, string $style = '' ): void

// Return HTML string of affiliate links (without printing)
wpam_get_rendered_links( int $post_id = 0, string $style = '' ): string
```

**Internal API (for companion plugins)**

```php
WPAM_API::get_top_posts( array $args = [] ): \WP_Post[]
WPAM_API::get_top_viewed_posts( array $args = [] ): \WP_Post[]
WPAM_API::get_top_scored_posts( array $args = [] ): \WP_Post[]
```

## Implementation steps

1. Copy `wp_affiliatemanager` into `wp-content/plugins/`.
2. Activate **Bunny Affiliate Manager** from the WordPress Plugins screen.
3. Open **Bunny Affiliates → Affiliates** to register your first affiliates.
4. Edit any post to find the **Affiliate Links** meta box (or use the **Post Affiliates** board for bulk management).
5. Add one or more links by pasting the affiliate URL. The system detects the affiliate automatically by domain. Optionally add a custom label.
6. The real-time preview shows the final affiliate URL before saving.
7. Save the post; links are validated, sanitized, and stored with correct order. The redirect token map is rebuilt automatically.
8. Visit the post on the frontend — affiliate links appear automatically (based on **Settings → Render mode**) and route through `/go/{token}`, tracked in Clicks and (on single post views) in Views.
9. Alternatively, add `[wpam_links]` or `[wpam_links style="horizontal"]` anywhere in the post content.
10. Check **Bunny Affiliates → Analytics** for Score/Clicks/Views breakdowns, or **Dashboard** for the executive summary.

## Frequently asked questions

**Does it require any additional plugin?**
No. Bunny Affiliate Manager works standalone on standard WordPress.

**What happens if I delete an affiliate that already has links on posts?**
Existing links are detected as orphans and flagged visually in the meta box. Posts are not broken. You can reassign the link to another affiliate or delete it from the post editor.

**Can I use affiliate links on pages or custom post types?**
Currently only on posts. You can extend support to other post types using the filter `wpam_post_links_post_types`.

**Are final URLs stored in the database?**
No. Final URLs are generated at runtime. Changing an affiliate's parameter automatically reflects across all posts without editing each one.

**How is the redirect token generated, and is it guessable?**
`substr( wp_hash( "{post_id}:{link_index}:wpam" ), 0, 8 )` — an HMAC keyed with the site's secret salts. It's deterministic (same link always produces the same token) but not predictable externally.

**How is the Score calculated?**
`score = views × 1 + clicks × 25` by default (weights are public constants on `Score_Query`, planned to move into Settings in a future version).

**Does the Views counter work with full-page caching?**
Yes. The view is recorded via a client-side AJAX beacon after the cached HTML has already been served, so it's unaffected by page caching.

**Can I customize the appearance without editing plugin files?**
Yes. Copy the relevant template into `/wp-content/themes/YOUR-THEME/wpam/` and edit freely. Plugin updates will not overwrite them.

**Can I control the brand color per affiliate?**
Yes. Set the **Brand Color** field in each affiliate's settings. The CSS variable `--wpam-brand-color` is injected inline on each link item, controlling the button color and left border accent.

**How do I switch between Card and Showcase?**
**Settings → Appearance → Layout.** Only the fields relevant to the selected Layout are shown. Button-display options (logo/name, CTA, order) apply to both and aren't duplicated.

## Localization

- English strings are the default fallback.
- Translation files are prepared in `languages/`.
- All user-facing strings use the `wp-affiliatemanager` text domain.
