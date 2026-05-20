# Bunny Affiliate Manager

A modular and scalable affiliate link management plugin for WordPress that allows creators and publishers to register affiliates, assign affiliate links to posts, and render them automatically — with support for templates, category filtering, and future extensibility toward statistics, automation, and Gutenberg blocks.

## Plugin metadata

- Plugin Name: Bunny Affiliate Manager
- Plugin URI: https://bunnychase.net/bunny-affiliate-manager/
- Author: BunnyChase
- Author URI: https://bunnychase.net/
- Text Domain: wp_affiliatemanager
- Domain Path: /languages
- License: GPLv2 or later
- License URI: https://www.gnu.org/licenses/gpl-2.0.html

## Requirements

- WordPress 6.0 or newer.
- PHP 8.0 or newer.

## Current scope (v0.1.0)

- **Post Affiliates board:** new admin screen to manage affiliate links per post — visual rows with thumbnail, status, date, and affiliate chips. Inline editor (expand/collapse, no modal). Incremental loading (20 initial, +10 Load More). Search by title, filter by category and tag.
- Affiliate logo picker via WordPress Media Library (inline CRUD).
- Affiliate fields: `domains` (informational) and `visible` (checkbox).


- Full affiliate CRUD: create, edit, delete, activate, deactivate.
- Per-affiliate fields: name, slug, URL parameter, value, logo, brand color.
- Admin affiliate table with logo, status, parameter, and value columns.
- Meta box "Affiliate Links" on posts with multi-link support.
- Per-link fields: affiliate (provider), original URL, custom label, order.
- Real-time preview of the generated affiliate URL (no page reload).
- Save pipeline with nonce, sanitization, and strict URL validation (http/https only).
- Orphan detection for links whose provider has been deleted or deactivated.
- Correct incremental order (0, 1, 2...) guaranteed on every save.
- **Render Engine frontend:** automatic injection via `the_content` filter or shortcode `[wpam_links]`.
- **Render modes:** `disabled`, `after_content`, `before_content`, `shortcode_only`.
- **Templates:** `vertical` (stacked list) and `horizontal` (row with wrap).
- **Theme override support:** drop templates in `/wp-content/themes/THEME/wpam/`.
- **Public helpers:** `wpam_render_links()` and `wpam_get_rendered_links()`.
- **Conditional asset loading:** CSS/JS only enqueued when post has active links.
- Brand color CSS variable (`--wpam-brand-color`) per affiliate for accent styling.
- URL generator functions available globally for use in themes and plugins.
- Helper functions for affiliates and per-post link queries.
- WordPress Settings API with per-field sanitization.
- Template system with theme-override support.
- REST API status endpoint at `/wp-json/wpam/v1/status`.
- Dashboard with live affiliate counters.

## File structure

```text
Bunny-Affiliate-Manager/
├── readme.md
├── wp_affiliatemanager/
│   ├── assets/
│   │   ├── css/
│   │   │   ├── admin.css
│   │   │   ├── bunny-admin.css
│   │   │   └── frontend.css
│   │   ├── js/
│   │   └── images/
│   ├── includes/
│   │   ├── admin/
│   │   ├── affiliates/
│   │   ├── api/
│   │   ├── frontend/
│   │   │   ├── class-frontend.php
│   │   │   ├── class-frontend-assets.php
│   │   │   ├── class-render-engine.php
│   │   │   └── helpers-render.php
│   │   ├── posts/
│   │   ├── settings/
│   │   ├── templates/
│   │   │   ├── class-templates.php
│   │   │   └── views/
│   │   │       ├── affiliate-card.php
│   │   │       ├── link-item.php
│   │   │       └── links-wrapper.php
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

- The plugin uses a modular class-oriented architecture with PHP 8 namespaces.
- Each module has a single responsibility; no cross-module direct dependencies.
- Hooks (actions and filters) are registered centrally through the Loader class.
- A singleton pattern is applied only where a single shared instance is strictly appropriate.
- WordPress Settings API, Meta Boxes, and `WP_Query` are preferred over custom solutions.
- AJAX is used only where it provides genuine UX value; server rendering is the default.
- Affiliate URLs are generated at runtime via `wpam_generate_affiliate_url()` — final URLs are never stored in the database, so changing an affiliate parameter propagates to all posts automatically.
- The Render Engine uses an in-memory cache per `post_id + style` to avoid duplicate renders within the same request.
- No React or JavaScript framework dependency; the admin UI is intentionally lightweight.

## Shortcode

```
[wpam_links]
[wpam_links style="horizontal"]
[wpam_links style="vertical" post_id="42"]
```

The shortcode renders the affiliate links assigned to the current post (or the specified `post_id`). Orphan links (whose provider has been deleted or deactivated) are silently omitted.

## Render modes

Configured in **Settings -> Modo de renderizado**:

- `disabled` — nothing is rendered automatically; use shortcode or helpers manually.
- `after_content` — block appended after post content (default).
- `before_content` — block prepended before post content.
- `shortcode_only` — automatic injection disabled; only `[wpam_links]` works.

## Theme override

Drop files in `/wp-content/themes/YOUR-THEME/wpam/` to override any template:

- `link-item.php` — individual link row.
- `links-wrapper.php` — outer wrapper with style class and data attributes.
- `affiliate-card.php` — legacy card template.

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
wpam_get_post_link( int $post_id, int $index ): ?array
wpam_post_has_links( int $post_id, bool $active_only = false ): bool
wpam_get_post_links_count( int $post_id, bool $active_only = false ): int
wpam_post_link_is_orphan( int $post_id, int $index ): bool
wpam_normalize_link_item( array $item ): array
```

**Render Engine**

```php
// Print affiliate links of a post
wpam_render_links( int $post_id = 0, string $style = '' ): void

// Return HTML string of affiliate links (without printing)
wpam_get_rendered_links( int $post_id = 0, string $style = '' ): string
```

## Implementation steps

1. Copy `wp_affiliatemanager` into `wp-content/plugins/`.
2. Activate **Bunny Affiliate Manager** from the WordPress Plugins screen.
3. Open **Bunny Affiliates -> Affiliates** to register your first affiliates.
4. Edit any post to find the **Affiliate Links** meta box.
5. Add one or more links by selecting a provider, entering the original URL, and optionally a custom label.
6. The real-time preview shows the final affiliate URL before saving.
7. Save the post; links are validated, sanitized, and stored with correct order.
8. Visit the post on the frontend — affiliate links appear automatically (based on **Settings -> Modo de renderizado**).
9. Alternatively, add `[wpam_links]` or `[wpam_links style="horizontal"]` anywhere in the post content.

## Frequently asked questions

**Does it require any additional plugin?**
No. Bunny Affiliate Manager works standalone on standard WordPress.

**What happens if I delete an affiliate that already has links on posts?**
Existing links are detected as orphans and flagged visually in the meta box. Posts are not broken. You can reassign the link to another affiliate or delete it from the post editor.

**Can I use affiliate links on pages or custom post types?**
Currently only on posts. You can extend support to other post types using the filter `wpam_post_links_post_types`.

**Are final URLs stored in the database?**
No. Final URLs are generated at runtime. Changing an affiliate's parameter automatically reflects across all posts without editing each one.

**Can I customize the appearance without editing plugin files?**
Yes. Copy `link-item.php` and/or `links-wrapper.php` into `/wp-content/themes/YOUR-THEME/wpam/` and edit freely. Plugin updates will not overwrite them.

**Can I control the brand color per affiliate?**
Yes. Set the **Brand Color** field in each affiliate's settings. The CSS variable `--wpam-brand-color` is injected inline on each link item, controlling the button color and left border accent.

## Localization

- English strings are the default fallback.
- Translation files are prepared in `languages/`.
- All user-facing strings use the `wp_affiliatemanager` text domain.

## Changelog

### 0.1.0 — Post Affiliates Board

- New screen: **Post Affiliates** (`wpam-post-affiliates`) — visual board to manage affiliate links per post from a single place.
- New file: `includes/admin/class-post-affiliates-screen.php` — renders the board, AJAX handlers.
- New file: `assets/js/post-affiliates.js` — toolbar search/filter with debounce, load more (append), inline editor expand/collapse, save via AJAX, replace row on response.
- New file: `assets/css/post-affiliates.css` — board styles: row card with thumbnail, chips, inline editor.
- New AJAX action: `wpam_load_posts` (unified: initial load + load more + search/filter). Params: `offset`, `limit`, `search`, `category`, `tag`. Returns HTML + `has_more`.
- New AJAX action: `wpam_save_post_links` — receives full links array from client, validates/sanitizes, writes to `_wpam_links`, returns updated row HTML.
- `class-admin-menu.php`: added `wpam-post-affiliates` submenu page and nav item.
- `class-admin-assets.php`: registers and enqueues `post-affiliates.js` + `post-affiliates.css` only on the new screen.
- `class-plugin.php`: `require_once` for new screen class + two AJAX hooks registered.
- Fix: removed counter badge `<span class="wpam-count-badge">` from Affiliates screen title (cosmetic zero display).
- Performance: `query_posts()` uses `fields => ids`, `no_found_rows => true`, `update_post_meta_cache => false`, `update_post_term_cache => false` — safe for 500-1000+ posts.
- Reutilization: editor inline reuses `.wpam-edit-form`, `.wpam-edit-grid`, `.wpam-input`, `.wpam-saving-indicator` from existing `admin.css`.
- Version bumped to `0.1.0` in plugin header and `WPAM_VERSION` constant.

### 0.0.7 — Bunny Admin UI Homologation

- **Bunny Admin UI system:** adopted the shared `bunny-*` admin UI convention used across all Bunny plugins. The admin header, tab navigation, wrappers, badges, and spacing now use `.bunny-*` classes and `--bunny-*` CSS custom properties.
- **New `bunny-admin.css`:** added a plugin-agnostic stylesheet containing only shared admin chrome: sticky header, horizontal tab nav, version badge, page-content wrapper, and responsive breakpoints. Loaded as a WordPress style dependency before `admin.css`.
- **Sticky admin header:** the header is now `position: sticky; top: 32px`, keeping the plugin name, tabs, and version badge visible while scrolling any admin page.
- **Page subtitle:** each admin page now shows the current section name (Dashboard, Affiliates, Settings) as a small uppercase label below the plugin name, using `.bunny-page-subtitle`.
- **`admin.css` refactored:** the header, nav, wrap, and page-content sections were removed. Plugin-specific `--wpam-*` variables are now declared as aliases of `--bunny-*` tokens so all downstream WPAM styles continue to work without changes.
- **`class-admin-assets.php`:** `bunny-admin.css` is now enqueued before `admin.css` with an explicit dependency declaration.
- **`class-admin-menu.php`:** `render_admin_header()` and `render_admin_nav()` updated to emit `bunny-*` classes exclusively; `wpam-admin-wrap` class retained alongside `bunny-wrap` for backward-compatible specificity.
- **`class-affiliates-screen.php`:** `wpam-page-content` replaced with `bunny-page-content`.
- **No functional changes:** all plugin logic, AJAX handlers, REST endpoints, affiliate CRUD, meta boxes, and frontend rendering are unchanged.

### 0.0.6 — Inline CRUD + Bug Fix Dashboard

- New: Inline affiliate creation — "Add Affiliate" now inserts an editable row at the top of the table without leaving the page.
- New: Inline affiliate editing — the ✏️ button replaces the row with an editable form in-place; no separate screen.
- New: Affiliate field `domains` — free-text field to note associated domains (e.g. `amazon.com, amzn.to`). Informational only.
- New: Affiliate field `visible` — checkbox to mark affiliate visibility, separate from active status.
- New: AJAX actions `wpam_save_affiliate` and `wpam_get_edit_row` with nonce `wpam_inline_crud`.
- New: inline notice area `#wpam-ajax-notice` for save feedback without page reload.
- New: CSS animation flash on newly saved rows.
- Fix: Dashboard "Posts with Affiliates" counter now correctly queries `_wpam_links` meta key joined against `wp_posts`, filtering by `post_type = 'post'` and `post_status = 'publish'`. Previously it was counting `_wpam_active` records (affiliate CPT meta), not actual posts with links.
- Fix: Dashboard "Add New Affiliate" button now points to the Affiliates screen instead of the native CPT editor.
- Improvement: Affiliates table gains two new columns: Domains and Flags (visible indicator).
- Improvement: `class-repository.php` `save()` and `normalize()` updated to include `domains` and `visible` fields.
- Improvement: `class-meta.php` adds `KEY_DOMAINS` and `KEY_VISIBLE` constants.
- Improvement: `wpamAdminData` JS object gains `crudNonce` property.

### 0.0.4 — FASE 4: Render Engine

- New: `Render_Engine` class — central frontend rendering module with in-memory cache.
- New: `the_content` filter integration supporting `after_content` and `before_content` modes.
- New: shortcode `[wpam_links]` with `style` (`vertical`/`horizontal`) and `post_id` attributes.
- New: setting `render_mode` with four options: `disabled`, `after_content`, `before_content`, `shortcode_only`.
- New: setting `link_style` for global default template style (`vertical` / `horizontal`).
- New: template `link-item.php` — individual link row with logo, name, and CTA button.
- New: template `links-wrapper.php` — outer wrapper with style class and data attributes.
- New: theme override support: drop templates in `/wp-content/themes/THEME/wpam/`.
- New: public helpers `wpam_render_links()` and `wpam_get_rendered_links()`.
- New: `frontend.css` — clean, lightweight styles for vertical and horizontal layouts.
- New: CSS variable `--wpam-brand-color` injected per affiliate for accent styling and left border.
- Improvement: `Frontend_Assets` now loads CSS/JS only when post has active links and `render_mode` is not `disabled`.
- Improvement: `Render_Engine` uses in-memory cache per `post_id + style` to avoid duplicate renders.
- Improvement: orphan links silently omitted from frontend render (no warnings, no broken HTML).
- Improvement: `maybe_enqueue_assets()` handles late shortcode rendering outside `wp_enqueue_scripts`.
- Improvement: `Frontend` class exposes `get_render_engine()` for external access.
- Fix: explicit `null` check added in `build_html()` when `link-item` template is not found — avoids silent null-to-string coercion on concatenation.
- Fix: `// Already escaped above.` comments replaced with inline `phpcs:ignore` directives in all templates.
- Fix: escaping in `link-item.php` hardened — each output point uses its own escape function instead of relying on pre-escaped variables.
- Fix: `wrapper_class` and `style` re-escaped with `esc_attr()` at the output point in `links-wrapper.php`.
- Fix: dead include `class-post-affiliates.php` removed from `class-plugin.php` (FASE 1 placeholder superseded by `class-post-links.php` in FASE 3).
- Docs: architectural note added to `Render_Engine::register()` explaining why hooks bypass the Loader.

### 0.0.3 — Polish & Stability

- Fix: `order` field now always saves with correct incremental values (0, 1, 2...) in both PHP and JS.
- Fix: URL validation upgraded to `filter_var( FILTER_VALIDATE_URL )` plus scheme verification (http/https only).
- Fix: orphan providers no longer generate PHP warnings; `get_links()` returns `_orphan => true` with `_orphan_title` for the UI.
- Fix: orphan rows display a visual warning (yellow background) and a bordered provider select.
- Fix: orphan row preview shows the original URL with a "no affiliate applied" indicator.
- Improvement: "Add Link" button is disabled automatically when no active affiliates exist, with a notice and a direct link to the affiliates screen.
- Improvement: empty list placeholder improved with icon and better visual spacing.
- Improvement: `wpam_get_post_links()` accepts `$active_only` to filter orphans easily.
- Improvement: `wpam_normalize_link_item()` guarantees all array keys for safe access.
- Improvement: `wpam_post_link_is_orphan()` added as a direct helper for templates.
- Improvement: client-side URL validation via the URL API with immediate visual feedback.
- Improvement: `updateCounter` now targets `#wpam-links-count` (specific ID, more robust).

### 0.0.2 — FASE 3: Per-Post Link System

- New: "Affiliate Links" meta box on posts.
- New: per-post link system (provider, URL, label, order).
- New: real-time affiliate URL preview without AJAX.
- New: save pipeline with nonce, sanitization, and provider validation.
- New: helpers `wpam_get_post_links()`, `wpam_get_post_link()`, `wpam_post_has_links()`, `wpam_get_post_links_count()`.
- New: JavaScript for row management (add, remove, preview).
- New: dedicated CSS for the meta box (loaded only on post screens).

### 0.0.1 — FASE 1 & 2: Base Architecture + Affiliate System

- Modular class-oriented architecture with PHP 8 namespaces.
- Central hook Loader (actions and filters).
- Activation/deactivation hooks with requirements validation.
- Custom Post Type `wpam_affiliate` (private, visible in admin).
- Affiliate meta boxes: Details, Appearance, Status.
- Affiliate repository with full CRUD.
- Admin affiliate screen with table, toggle, and deletion.
- URL generator: `wpam_generate_affiliate_url()`.
- WordPress Settings API with per-field sanitization.
- Template system with theme-override support.
- REST API endpoint `/wp-json/wpam/v1/status`.
- Dashboard with live affiliate counters.

## Future extensibility notes

- Add click statistics per link and per affiliate.
- Build Gutenberg blocks for inline affiliate link insertion.
- Add drag-and-drop reordering of links within the meta box.
- Extend support to additional post types via `wpam_post_links_post_types` filter.
- Consider WooCommerce integration for product affiliate links.
- Keep statistics, automation, and notifications separate from core affiliate services.
