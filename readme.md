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

## Current scope (v0.0.3)

- Custom Post Type `wpam_affiliate` for native WordPress affiliate storage.
- Full affiliate CRUD: create, edit, delete, activate, deactivate.
- Per-affiliate fields: name, slug, URL parameter, value, logo, brand color.
- Admin affiliate table with logo, status, parameter, and value columns.
- Meta box "Affiliate Links" on posts with multi-link support.
- Per-link fields: affiliate (provider), original URL, custom label, order.
- Real-time preview of the generated affiliate URL (no page reload).
- Save pipeline with nonce, sanitization, and strict URL validation (http/https only).
- Orphan detection for links whose provider has been deleted or deactivated.
- Correct incremental order (0, 1, 2…) guaranteed on every save.
- URL generator functions available globally for use in themes and plugins.
- Helper functions for affiliates and per-post link queries.
- WordPress Settings API with per-field sanitization.
- Template system with theme-override support.
- REST API status endpoint at `/wp-json/wpam/v1/status`.
- Dashboard with live affiliate counters.

## File structure

```text
wp_affiliatemanager/
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
├── includes/
│   ├── admin/
│   ├── affiliates/
│   ├── api/
│   ├── frontend/
│   ├── posts/
│   ├── settings/
│   ├── templates/
│   ├── class-activator.php
│   ├── class-deactivator.php
│   ├── class-loader.php
│   ├── class-plugin.php
│   └── helpers.php
├── languages/
├── uninstall.php
└── wp_affiliatemanager.php
```

## Architecture decisions

- The plugin uses a modular class-oriented architecture with PHP 8 namespaces.
- Each module has a single responsibility; no cross-module direct dependencies.
- Hooks (actions and filters) are registered centrally through the Loader class.
- A singleton pattern is applied only where a single shared instance is strictly appropriate.
- WordPress Settings API, Meta Boxes, and `WP_Query` are preferred over custom solutions.
- AJAX is used only where it provides genuine UX value; server rendering is the default.
- Affiliate URLs are generated at runtime via `wpam_generate_affiliate_url()` — final URLs are never stored in the database, so changing an affiliate parameter propagates to all posts automatically.
- No React or JavaScript framework dependency; the admin UI is intentionally lightweight.

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

## Implementation steps

1. Copy `wp_affiliatemanager` into `wp-content/plugins/`.
2. Activate **Bunny Affiliate Manager** from the WordPress Plugins screen.
3. Open **Bunny Affiliates → Affiliates** to register your first affiliates.
4. Edit any post to find the **Affiliate Links** meta box.
5. Add one or more links by selecting a provider, entering the original URL, and optionally a custom label.
6. The real-time preview shows the final affiliate URL before saving.
7. Save the post; links are validated, sanitized, and stored with correct order.

## Frequently asked questions

**Does it require any additional plugin?**
No. Bunny Affiliate Manager works standalone on standard WordPress.

**What happens if I delete an affiliate that already has links on posts?**
Existing links are detected as orphans and flagged visually in the meta box. Posts are not broken. You can reassign the link to another affiliate or delete it from the post editor.

**Can I use affiliate links on pages or custom post types?**
Currently only on posts. You can extend support to other post types using the filter `wpam_post_links_post_types`.

**Are final URLs stored in the database?**
No. Final URLs are generated at runtime. Changing an affiliate's parameter automatically reflects across all posts without editing each one.

## Localization

- English strings are the default fallback.
- Translation files are prepared in `languages/`.
- All user-facing strings use the `wp_affiliatemanager` text domain.

## Changelog

### 0.0.3 — Polish & Stability

- Fix: `order` field now always saves with correct incremental values (0, 1, 2…) in both PHP and JS.
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

- Add frontend automatic rendering of affiliate links (FASE 4).
- Introduce visual templates: minimal, card, banner.
- Add click statistics per link and per affiliate.
- Build Gutenberg blocks for inline affiliate link insertion.
- Add drag-and-drop reordering of links within the meta box.
- Extend support to additional post types via `wpam_post_links_post_types` filter.
- Consider WooCommerce integration for product affiliate links.
- Keep statistics, automation, and notifications separate from core affiliate services.
