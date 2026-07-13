# Changelog — Bunny Affiliate Manager

All notable changes to this project are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.2.0] — WPAM_API: WPAM_API::get_top_viewed_posts()

### Added

- **`WPAM_API::get_top_viewed_posts( array $args = array() ): \WP_Post[]`** — espejo exacto de `get_top_posts()`: mismos argumentos (`period`, `limit`, `post_type`, `categories_include/exclude`, `tags_include/exclude`, `authors_include/exclude`), misma validación, mismo array `$filters`, misma normalización a `WP_Post[]`. Usa `Views_Query::get_cached()` como fuente de datos y asigna `$post->wpam_view_count` (en vez de `Top_Posts_Query::get_cached()` / `$post->wpam_click_count`).
- Nuevo filter hook `wpam_api_top_viewed_posts`, aplicado al array final de posts — análogo a `wpam_api_top_posts` pero independiente, para no mezclar ambos flujos en integraciones externas (Bunny Magazine).

### Changed

- **Refactor interno de `WPAM_API`**: toda la lógica antes contenida en `get_top_posts()` (validación de inputs, construcción de `$filters`, normalización a `WP_Post`) se movió a un método privado `build_top_posts_response( $args, $query_callback, $count_field, $count_property, $filter_hook )`, compartido por `get_top_posts()` y `get_top_viewed_posts()`. Ambos métodos públicos quedan como wrappers de una llamada. `$query_callback` se invoca directamente (`$query_callback( $range, $limit, $filters )`), no vía `call_user_func()`, porque `Top_Posts_Query::get_cached()` y `Views_Query::get_cached()` comparten exactamente la misma firma.
- **Cero cambios de comportamiento en `get_top_posts()`**: misma firma, misma validación, mismo output, mismo hook `wpam_api_top_posts` — es un refactor interno, no una reescritura. Bunny Magazine no requiere ningún cambio.

### Notes

- No se modificó `Top_Posts_Query` ni `Views_Query` — el refactor vive enteramente dentro de `WPAM_API`.
- No se registró ningún hook nuevo en `class-plugin.php`; `WPAM_API` no depende del sistema de hooks del plugin.

---

## [1.2.0] — Views Import + Dashboard: Recent Views & Top Viewed Posts

### Added

- **`Views_Importer`** (`includes/views/class-views-importer.php`) — migración única desde Post Views Counter (`{prefix}post_views`, `type = 0`) hacia `wpam_views`. Merge aditivo (`INSERT ... ON DUPLICATE KEY UPDATE count = count + VALUES(count)`); no destructivo con datos ya trackeados nativamente. Bloqueada por la opción `wpam_post_views_import_completed` tras la primera ejecución exitosa — no vuelve a correr ni a mostrar el botón. Detecta la tabla origen con una lectura directa (`SELECT 1 ... LIMIT 1` + `$wpdb->last_error`), sin `SHOW TABLES`. Nunca escribe en la tabla origen. Ignora posts inexistentes (con caché local de existencia por `post_id` durante la corrida). Invalida el grupo de caché `wpam` al finalizar.
- **Herramienta de Maintenance**: nueva fila condicional "Import from Post Views Counter", visible solo si `Views_Importer::can_run()` es `true` (tabla origen presente y migración aún no ejecutada). Notice de resultado con imported/updated/omitted/segundos.
- **`Views_Query::get_recent( int $limit = 20 )`** — filas crudas más recientes de `wpam_views` (`period DESC, id DESC`). `wpam_views` es un agregado diario, no un log de eventos: no hay timestamp exacto.
- **Dashboard — Recent Views**: nueva sección full-width (Date / Post Title / Views), mismo diseño visual que Recent Clicks. La columna Date muestra el `period` (día), sin hora.
- **Dashboard — Top Viewed Posts**: nueva sección full-width, mismo diseño visual que Top Posts, con el mismo comportamiento de filtro por Today/Last 7 Days/Last 30 Days/Total que las cards de Clicks. Reutiliza `Views_Query::get_cached()` como única fuente de datos.
- **`ajax_dashboard_filter()` extendido** con un parámetro `source` (`'clicks'` default, retrocompatible; `'views'` nuevo) para servir el fragmento de Top Viewed Posts sin un segundo endpoint AJAX.
- **`dashboard.js` refactorizado**: `initFilterGroup()` genérico reemplaza la implementación hardcodeada de un solo grupo; se invoca una vez para el grupo Clicks (existente) y una vez para el grupo Views (nuevo, con `source: 'views'`) — cero JS duplicado entre ambos.
- **Refactor sin duplicación**: `render_top_list()` extraído de `render_top_posts_section()`, reutilizado por `render_top_posts_section()` (sin cambio de output) y por el nuevo `render_top_viewed_posts_section()`.

### Notes

- El importador es intencionalmente **no idempotente**: sumar en vez de tomar el máximo permite fusionar el histórico completo de Post Views Counter incluso si se solapa con días ya trackeados nativamente, a cambio de que **no puede volver a ejecutarse** sin borrar manualmente la opción `wpam_post_views_import_completed` (deliberado, para evitar duplicar counts en corridas accidentales).
- Asunción de nombre de tabla origen: `{$wpdb->prefix}post_views` (default de Post Views Counter). Si la instalación usa un nombre distinto, `can_run()` devuelve `false` y la herramienta queda oculta sin error visible.
- Sigue sin existir `WPAM_API::get_top_viewed_posts()` — la capa de datos (`Views_Query::get()`/`get_cached()`) ya está lista para cuando se decida implementarlo.

---

## [1.2.0] — Views System (Fase 1 — Infraestructura)

### Added

- **Tabla propia `{prefix}wpam_views`** — histórico diario de vistas por post (`post_id`, `period` YYYYMMDD, `count`). No es un contador acumulado: una fila por post y día. `UNIQUE KEY (post_id, period)` permite upsert atómico.
- **`Views_Table::create_table()`** — creación vía `dbDelta()`, llamada desde `Activator::activate()` junto a `Clicks_Table::create_table()`.
- **`View_Tracker::record()`** — un único `INSERT ... ON DUPLICATE KEY UPDATE count = count + 1` por vista contada. Sin SELECT previo, sin condición de carrera.
- **`Views` (orquestador)** — punto único de elegibilidad (`is_eligible()`): solo posts (`post_type = 'post'`), publicados. Excluye páginas, CPTs, previews, feeds, admin, REST, cron y búsquedas/archivos (vía `is_singular('post')` en el enqueue). Filtro adicional de bots conocidos por user-agent en el endpoint AJAX.
- **Beacon AJAX (`wpam_track_view`)** — `wp_ajax_wpam_track_view` + `wp_ajax_nopriv_wpam_track_view`, registrados en `define_global_hooks()`. Compatible con full-page cache: el registro ocurre vía `fetch()` en el navegador, no depende de que PHP corra en la carga de la página.
- **`assets/js/views-beacon.js`** — fetch nativo, sin jQuery, sin lectura/escritura de cookies del lado cliente. Se encola condicionalmente solo en `is_singular('post')` vía `Views::maybe_enqueue_beacon()`.
- **Config del beacon vía `wp_add_inline_script()`** — objeto `window.wpamViews` (`ajaxUrl`, `action`, `postId`, `nonce`) inyectado antes del script, sin `wp_localize_script()`.
- **Deduplicación por cookie (`wpam_v`)** — cookie `HttpOnly` con lista de post_ids ya contados en el período actual, gestionada enteramente en PHP dentro de `Views::ajax_track()`. Expira a medianoche UTC, alineada con el corte de `period`.

### Notes

- Sin dashboard, sin API pública, sin Top Posts ni migración todavía — solo la infraestructura de registro. Estos puntos quedan para fases posteriores.
- El `post_id` recibido por AJAX se revalida siempre server-side contra `is_eligible()`; nunca se confía en el valor del cliente.
- Pendiente conocido: la tabla se crea en `Activator::activate()`. En instalaciones donde el plugin ya está activo, actualizar los archivos sin desactivar/reactivar no crea la tabla automáticamente — no existe todavía una rutina de upgrade por versión en el proyecto. En Local, desactivar y reactivar el plugin tras esta actualización.

---

## [1.2.0] — Views System (Fase 2 — Settings + Dashboard)

### Added

- **3 opciones nuevas en Settings** (sección "Views Tracking"): `count_admin_views` (default `false`), `count_logged_in_users` (default `true`), `count_bot_traffic` (default `false`). Mismo patrón que el resto de checkboxes de Settings.
- **`Views::is_eligible()` absorbe las 3 reglas** como única fuente de verdad: administradores gobernados por `count_admin_views` (prioridad sobre el resto), usuarios logueados no-admin por `count_logged_in_users`, invitados sin restricción. El filtro de bots (antes suelto en `ajax_track()`) se consolidó también dentro de `is_eligible()`, gobernado por `count_bot_traffic`.
- **`Views_Query`** (`includes/views/class-views-query.php`) — equivalente completo de `Frontend\Top_Posts_Query`, misma interfaz pública, misma filosofía de caché (grupo `wpam`, TTL 300s):
  - `get()` / `get_cached()` — Top Viewed Posts (SUM(count) sobre `wpam_views` en vez de COUNT(*) sobre `wpam_clicks`). Preparado para que `WPAM_API::get_top_viewed_posts()` reutilice `get_cached()` sin rediseño cuando se implemente.
  - `get_stats()` / `get_stats_cached()` — agregados por rango (today/week/month/total) para las tarjetas del Dashboard.
  - `range_to_period_since()` reutiliza `Top_Posts_Query::range_to_since()` como fuente única de la lógica de "días atrás", adaptando el formato de salida a `period` (CHAR(8) YYYYMMDD).
- **Dashboard**: nuevo bloque de 4 tarjetas estáticas (Views Today / Last 7 Days / Last 30 Days / Total Views), mismo estilo visual que las tarjetas de Clicks, sin comportamiento de filtro AJAX.

### Notes

- `apply_filters_to_ids()` y `build_cache_key()` de `Views_Query` son duplicado intencional de los de `Top_Posts_Query` (misma lógica, distinto prefijo de caché) — decisión explícita para mantener ambos módulos desacoplados entre sí más allá de `range_to_since()`.
- Sigue sin existir Top Posts por vistas en UI, ni endpoint público — solo la capa de datos (`Views_Query::get()`/`get_cached()`) queda lista para cuando se decida construir eso.

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
