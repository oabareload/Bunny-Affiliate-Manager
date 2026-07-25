# Changelog — Bunny Affiliate Manager

All notable changes to this project are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.6.0] — Layout system (Card / Showcase) + Default URL fixes

### Added

- **Layout system**: new `Frontend\Layouts` namespace with `Layout_Interface`, `Layout_Registry`, `Layout_Card`, `Layout_Showcase`, and a shared `Frontend\Components\Button_Row`. `Render_Engine::build_html()` no longer contains any layout-specific markup — it resolves the active layout via `Layout_Registry::get()` and delegates entirely. Adding a future layout is: implement `Layout_Interface`, register it in `Layout_Registry::get_layouts()` (or via the new `wpam_render_layouts` filter), done — zero changes to `Render_Engine`.
- **`Layout_Card`**: the plugin's original (and, until now, only real) rendering path, moved out of `Render_Engine` verbatim — same templates (`link-item.php`, `links-wrapper.php`), same options, same HTML output. No behavior change for existing sites.
- **`Layout_Showcase`** (new): single large "product card" — image left / text right on desktop, stacked on mobile — with the same affiliate buttons row at the bottom (via `Button_Row`, not a separate button system). New dedicated stylesheet `assets/css/showcase.css`, enqueued only when the Showcase layout is active. Options (all under `appearance.showcase`): `image_source` (Featured Image | custom URL), `title_source` (post title | custom | hide), `desc_source` (excerpt | custom | hide — **only the manual post excerpt is ever used, same principle as the interstitial's related-post excerpt; content is never auto-trimmed**).
- **Shared section heading**: one single heading (`appearance.section_heading`: enabled / text / HTML tag H1–H6/div, default H2), rendered once by `Render_Engine` before delegating to whichever layout is active. Not duplicated per layout — Card and Showcase (and any future layout) get it for free.
- **Settings**: new "Layout" radio selector (Card / Showcase) replaces the old, non-functional "Estilo de botón" field. Layout-specific fields (`link_style` for Card; the three `showcase.*` fields) are marked with `data-wpam-layout-only` and shown/hidden client-side by the new `assets/js/settings.js`, enqueued only on the plugin's Settings screen. Button-display options (`display_content`, `cta_text`, `cta_hidden`, `frontend_order`) are explicitly shared by both layouts — not duplicated.

### Fixed

- **Root cause of "Card / Minimal / Banner always render the same"**: `appearance.button_style` (the old Minimal/Card/Banner selector) was saved to the database but never read anywhere — not in `Render_Engine`, not in any template, not in any CSS file. There was only ever one real visual implementation (what users perceived as "Card"). Confirmed via static analysis before writing any code, per request.
- **Default URL fallback no longer creates a block on posts that never had any affiliate configured.** `Post_Links::get_links()` now only merges Default URL fallback links when the post has at least one explicit entry ever saved in `_wpam_links` (`$raw_valid_count > 0`, checked before any `active_only` filtering) — a post with zero saved links returns an empty array regardless of how many affiliates have a Default URL. `Frontend_Assets::should_load_assets()` updated to match this exact rule (checks raw link presence, not `active_only`), so CSS/JS enqueueing and rendering now agree in every case, including a post whose only explicit link is orphaned but another affiliate would otherwise qualify as fallback.

### Removed (dead code, confirmed unused before deletion)

- **`appearance.button_style`** setting (Minimal/Card/Banner) and its sanitization — never connected to any rendering logic (see Fixed, above).
- **`appearance.template`** default key (`'default'`) — present in `Settings::get_defaults()` but never read anywhere in the codebase.

### Notes

- `templates/views/affiliate-card.php` and the `[data-wpam-link]` tracking code in `assets/js/frontend.js` were identified as likely dead code from a pre-`Render_Engine` version (nothing in the current render path calls or emits either), but were **not** removed — out of scope for this change, flagged separately for a future cleanup pass.
- No SQL migration required. `appearance.layout` defaults to `card`, so existing installations keep their exact current output after updating.
- Compatibility verified for: shortcodes (`[wpam_links]` unchanged), automatic render (`the_content` filter unchanged), public helpers (`wpam_render_links()`, `wpam_get_rendered_links()`, `wpam_get_post_links()`, `wpam_get_resolved_post_links()` all unchanged), `WPAM_API` (untouched), Recently Viewed (untouched), Default URLs / `/goa/` redirects (untouched beyond the Fixed item above).

---

## [1.5.0] — Affiliate Default URL (fallback links)

### Added

- **Default URL field per affiliate** (`_wpam_default_url` post meta, `Meta::KEY_DEFAULT_URL`): optional fallback URL used automatically on any post that does NOT have a specific link for that affiliate. Validated as URL or empty on save (both the native CPT meta box and the inline Affiliates screen). No checkboxes: the mere presence of a Default URL makes the affiliate eligible as a fallback — priority is always: post-specific link > Default URL > not rendered.
- **`Post_Links::get_links( int $post_id, array $options = [] )`** — single internal entry point for reading a post's links, now accepting `'active_only'` and `'include_defaults'` options (both default `false`, fully backward compatible with the previous no-args signature). `'include_defaults' => true` merges in synthetic fallback links for every active affiliate with a Default URL that doesn't already have a valid link on the post, generated via the same `wpam_generate_affiliate_url()` used by explicit links. A new filter hook `wpam_resolved_links` fires only when defaults are included.
- **`wpam_get_resolved_post_links( int $post_id, bool $active_only = true )`** — new public helper, separate from `wpam_get_post_links()` on purpose: it's the one that includes Default URL fallback, delegating to `Post_Links::get_links()` with `include_defaults = true`. `Render_Engine` calls this one; `wpam_get_post_links()` (explicit links only, unchanged signature) keeps being used by the "Affiliate Links" meta box and the Post Affiliates board.
- **`/goa/{post_id}/{affiliate_id}/` redirect route** (`Redirect_Manager::SLUG_DEFAULT`): a second, stateless rewrite rule for fallback links only. Unlike `/go/{token}`, it does **not** use the `wpam_redirect_tokens` map — `resolve_default()` resolves live against `Post_Links::get_links( ..., ['active_only' => true, 'include_defaults' => true] )`, the exact same canonical method `Render_Engine` uses (via `wpam_get_resolved_post_links()`), so there is zero duplicated business logic and the post-specific link always wins automatically even if it was added after the page was rendered/cached. No map to keep in sync means no proactive rebuild is needed when an affiliate's Default URL changes, and no writes happen during render (Render_Engine only renders; Redirect_Manager only resolves).
- **`wpam_go_default_url( int $post_id, int $affiliate_id )`** helper, mirroring `wpam_go_url()` for the new route.
- **Version-triggered `flush_rewrite_rules()`** in `Admin::init()`: compares the stored `wpam_version` option against `WPAM_VERSION` and flushes once per version bump — required so already-active installations pick up the new `/goa/` rewrite rule without deactivating/reactivating the plugin.

### Changed

- `Post_Links::get_links()`: internal `order` numbering for synthetic fallback links is now based on the raw count of valid items in `_wpam_links` (before any `active_only` filtering), preventing an `order` collision with an orphaned explicit link that active_only had excluded.
- Every link item (explicit or synthetic) now carries a `'_wpam_is_default'` key (`wpam_normalize_link_item()` updated to guarantee it). `Render_Engine::build_html()` uses it to choose between `wpam_go_url()` and `wpam_go_default_url()`.
- `Interstitial_Renderer`: the "Report broken link" button is now hidden when no `/go/{token}` token is present (i.e. for `/goa/` fallback redirects), since the broken-report AJAX handler only accepts the 8-hex token format. No change for existing `/go/` links.

### Notes

- No SQL migration: affiliates are CPT + post meta, so the new field is just a meta key. Existing installs are unaffected — an affiliate without a Default URL behaves exactly as before.
- `Post_Affiliates_Screen` board and the "Affiliate Links" post meta box are unchanged: both call `wpam_get_post_links()` / `get_links()` with default options (no fallback merging), since they manage explicit links only.
- `WPAM_API` is unchanged — it doesn't currently expose per-post links.

---

## [1.4.0] — Analytics reorganization: Score system + Analytics screen

### Added

- **Score system**: `WP_AffiliateManager\Analytics\Score_Query` (`includes/analytics/class-score-query.php`), nuevo servicio que combina `wpam_views` y `wpam_clicks` en un ranking ponderado (`score = views * FACTOR_VIEWS + clicks * FACTOR_CLICKS`, factores por defecto 1 y 25 respectivamente, expuestos como constantes públicas `Score_Query::DEFAULT_FACTOR_VIEWS` / `DEFAULT_FACTOR_CLICKS` para una futura migración a Settings). Mismo patrón público que `Top_Posts_Query` / `Views_Query`: `get()`, `get_cached()`, `get_stats()`, `get_stats_cached()`.
- **`WPAM_API::get_top_scored_posts( array $args = array() ): \WP_Post[]`** — espejo exacto de `get_top_posts()` / `get_top_viewed_posts()`, vía `build_top_posts_response()`. Nuevo filter hook `wpam_api_top_scored_posts`.
- **Página Analytics** (`wpam-analytics`): nueva pantalla `Admin\Analytics_Screen` con tabs horizontales Score / Clicks / Views. Cada tab reutiliza el mismo mecanismo de cards-filtro (Today / Last 7 Days / Last 30 Days / All Time) sobre un único endpoint AJAX (`wp_ajax_wpam_analytics_filter`), diferenciado por el parámetro `source`.
- **Página Broken Reports** (`wpam-broken-reports`): Broken Link Reports se separa del Dashboard a su propia página. Misma tabla, mismos handlers, mismos nonces — sin cambios de comportamiento.
- **`Admin\Analytics_Renderer`** (`includes/admin/class-analytics-renderer.php`): nueva clase de renderizado puro (sin SQL propio) compartida entre Dashboard y Analytics — `render_stat_card()`, `render_top_affiliates_section()`, `render_top_clicked_posts_section()`, `render_top_viewed_posts_section()`, `render_top_scored_posts_section()`, `render_recent_clicks_section()`, `render_recent_views_section()`.
- `Top_Posts_Query::get_stats()`, `get_stats_cached()`, `get_top_affiliates()`, `get_recent()` — la clase crece para quedar simétrica a `Views_Query`, centralizando toda la obtención de datos de clicks que antes vivía suelta en `Admin_Menu`.
- `assets/js/analytics.js` — reemplaza a `dashboard.js`. Mismo mecanismo de filtro (`initFilterGroup()`) reutilizado sin cambios para los 3 tabs de Analytics, más el toggle de tabs.

### Changed

- **Dashboard** simplificado a un resumen ejecutivo: cards de Total/Active Affiliates + Posts with Affiliates, cards de Overview (Total Score/Views/Clicks/Affiliates), Actividad Reciente (Recent Clicks + Recent Views), Top 10 Overall (por Score) y Accesos Rápidos. Ya no ejecuta queries filtrables por rango ni AJAX propio.
- **Settings**: la card de Maintenance (Rebuild Token Map, Clear Analytics, Import Post Views Counter) se mueve aquí desde el Dashboard. Mismos handlers, mismos nonces, solo cambia dónde se renderiza y a qué página redirige.
- **"Top Posts" → "Top Clicked Posts"** en toda la interfaz de administración (texto visible, `Analytics_Renderer::render_top_clicked_posts_section()`, clave AJAX `clicked_posts_html`, clase CSS `.wpam-analytics-clicked-posts-col`). **Sin cambios** en la API pública: `WPAM_API::get_top_posts()`, la clase `Top_Posts_Query`, el shortcode `[wpam_top_posts]`, `Widget_Top_Posts` y los prefijos de caché existentes permanecen idénticos — son contrato público consumido por Bunny Magazine y por contenido ya publicado en bunnychase.net.
- AJAX action `wp_ajax_wpam_dashboard_filter` reemplazado por `wp_ajax_wpam_analytics_filter`, registrado sobre `Analytics_Screen` en vez de `Admin_Menu`.

### Removed

- `assets/js/dashboard.js` — sin uso tras la reorganización (el Dashboard ya no filtra nada por AJAX).
- Métodos de datos y de render de analítica que vivían en `Admin_Menu` (`get_click_stats`, `get_top_affiliates`, `get_top_posts`, `get_recent_clicks`, `get_top_viewed_posts`, `get_recent_views`, `render_top_affiliates_section`, `render_top_posts_section`, `render_top_viewed_posts_section`, `render_top_list`, `render_recent_clicks_section`, `render_recent_views_section`, `ajax_dashboard_filter`) — movidos a `Top_Posts_Query`, `Views_Query`, `Score_Query` y `Analytics_Renderer`.

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

### 1.0.0 — Top Posts Shortcode

**New feature:** shortcode `[wpam_top_posts]` para mostrar los posts con más clicks en el frontend, reutilizando exactamente la misma fuente de datos que el Dashboard de Analytics.

#### Atributos

| Atributo | Valores | Default | Descripción |
|---|---|---|---|
| `title` | cualquier texto | vacío | Encabezado del widget. Si está vacío no se muestra. |
| `period` | `today` `week` `month` `total` | `total` | Rango temporal de los clicks. |
| `layout` | `horizontal` `vertical` | `horizontal` | Layout del widget. |
| `thumbnail_size` | cualquier tamaño WordPress registrado | `medium` | Tamaño de la imagen. |
| `limit` | entero 1-100 | `10` | Cantidad de posts a mostrar. |
| `max_width` | valor CSS (`400px`, `100%`) | vacío | Ancho máximo del widget. |
| `show_thumbnail` | `yes` `no` | `yes` | Mostrar o no la imagen del post. |

#### Ejemplos

```
[wpam_top_posts]
[wpam_top_posts period="month"]
[wpam_top_posts period="week" layout="vertical"]
[wpam_top_posts period="total" limit="20"]
[wpam_top_posts title="Popular Figures" period="month" thumbnail_size="medium"]
[wpam_top_posts layout="horizontal" max_width="800px" show_thumbnail="yes"]
```

## 📘 WPAM Top Posts API

### Uso básico

- Permite obtener los posts más populares como objetos `WP_Post`.

```php
$posts = \WP_AffiliateManager\API\WPAM_API::get_top_posts([
    'period'    => 'week',   // day | week | month | total
    'limit'     => 10,
    'post_type' => 'post'    // post | page | any
]);
```

### Resultado

- Devuelve un array de WP_Post con campos adicionales:

```php
$post->wpam_click_count; // int
$post->wpam_thumbnail;   // string|null
```

### Verificación opcional

```php
if ( class_exists( '\WP_AffiliateManager\API\WPAM_API' ) ) {
    $posts = \WP_AffiliateManager\API\WPAM_API::get_top_posts([
        'period' => 'week',
        'limit'  => 10
    ]);
}
```

### Notas

- API de solo lectura
- No requiere configuración adicional
- Usa la misma fuente de datos que el dashboard del plugin
- Compatible con widgets, dashboard y extensiones externas

#### Comportamiento responsive

- **Desktop / Tablet — `layout=horizontal`:** fila adaptativa de cards (imagen + título debajo). Las cards hacen `flex-wrap` cuando no caben; sin overflow horizontal.
- **Desktop / Tablet — `layout=vertical`:** lista apilada con imagen a la izquierda y título a la derecha.
- **Móvil (≤ 640px):** ambos layouts usan tarjetas compactas con imagen a la izquierda y título a la derecha con ellipsis. Mismo patrón visual que el board Post Affiliates del plugin.

#### Notas técnicas

- El CSS del widget (`top-posts-widget.css`) se registra en `wp_enqueue_scripts` pero solo se encola cuando el shortcode es ejecutado en la página. Cero impacto en páginas que no usan el shortcode.
- La query SQL se encapsula en `Frontend\Top_Posts_Query::get()`, clase compartida entre el Dashboard y el shortcode — sin duplicación de consultas.
- `Admin_Menu::get_top_posts()` delega en `Top_Posts_Query::get()` y añade `thumb_url` + `edit_url` que solo necesita el dashboard.

**Archivos nuevos:**
- `includes/frontend/class-top-posts-query.php` — query compartida (rango + límite).
- `includes/frontend/class-shortcode-top-posts.php` — shortcode `[wpam_top_posts]`.
- `assets/css/top-posts-widget.css` — estilos del widget (horizontal, vertical, móvil).

**Archivos modificados:**
- `wp_affiliatemanager.php` — versión `0.2.8 → 1.0.0`, `WPAM_VERSION` actualizado.
- `includes/class-plugin.php` — `require_once` de las dos clases nuevas; registro del shortcode vía `Shortcode_Top_Posts::register()`; `wp_register_style` del CSS del widget.
- `includes/admin/class-admin-menu.php` — `get_top_posts()` ahora delega en `Top_Posts_Query::get()` en vez de ejecutar su propio SQL.

### 0.2.8 — Dashboard Analytics Filters

**New feature:** the four click-stat metric cards at the top of the analytics dashboard are now interactive time-range filters.

**How it works:**

Clicking any of the four cards — 📈 Clicks Today, 📅 Last 7 Days, 🗓️ Last 30 Days, 🖱️ Total Clicks — immediately updates both **Top Affiliates** and **Top Posts** to reflect that time range, without a page reload.

- The active card remains fully visible; inactive cards appear grayed out.
- Hovering an inactive card signals it is clickable.
- The selected filter is persisted in `localStorage` and automatically restored when returning to the dashboard.
- Percentage bars in Top Affiliates are calculated relative to the total clicks within the selected range.

**Files changed:**

- `wp_affiliatemanager.php`: version bumped to `0.2.8`, `WPAM_VERSION` updated.
- `includes/admin/class-admin-menu.php`:
  - Top Affiliates and Top Posts column wrappers now carry `wpam-filter-affiliates-col` / `wpam-filter-posts-col` CSS classes as stable AJAX targets.
  - `get_top_affiliates()` and `get_top_posts()` accept an optional `$range` param (`today|week|month|total`); a `WHERE ts >= ?` clause is applied when range is not `total`.
  - New `ajax_dashboard_filter()` public method — AJAX handler secured with nonce + `manage_options` capability check.
  - New private helpers `get_range_total()` and `range_to_since()`.
- `includes/class-plugin.php`: registered `wp_ajax_wpam_dashboard_filter` hook.
- `includes/admin/class-admin-assets.php`: enqueues `assets/js/dashboard.js` and localizes `wpamDashboard` object only on the dashboard screen.
- `assets/js/dashboard.js` *(new)*: card click handler, active/inactive state toggling, AJAX request, `localStorage` persistence and restore on load.
- `assets/css/admin.css`: added scoped styles for card `--active`, `--inactive`, and `--inactive:hover` states (opacity + grayscale filter), scoped to `body.toplevel_page_wpam-dashboard`.

### 0.2.7 — Security Hardening & Broken Link Reporting

**`includes/settings/class-settings.php`:**

* Added a new **Interstitial Width** setting with 5 predefined sizes:

  * 460px (default)
  * 600px
  * 800px
  * 1000px
  * Full Width
* Added a new **Content Slots** section for custom content inside the interstitial page.
* Supported slot types:

  * Custom HTML
  * Image + Link
* Available slot positions:

  * Before Disclaimer
  * After Disclaimer
  * Before Related Post
  * After Related Post
* Introduced a scalable `content_slots` structure to support multiple slots in future releases.

**`includes/redirect/class-interstitial-renderer.php`:**

* Added dynamic Content Slot rendering based on configured position.
* Added configurable width classes for the interstitial card.
* Improved extensibility for future promotional content, embeds, banners, and custom layouts.

**`assets/css/interstitial.css`:**

* Added responsive width classes for configurable interstitial layouts.
* Added styling for Custom HTML slots.
* Added styling for Image + Link promotional blocks.
* Improved support for wider layouts and future monetization features.

**Admin UI Fixes:**

* Fixed a WordPress admin footer overlap issue that could cover controls at the bottom of plugin settings pages.
* Improved compatibility with custom content rendered inside the interstitial page.

## 0.2.5 — Interstitial Improvements & Analytics Controls

### Added

* Affiliate-specific disclaimer support.
* Affiliate-related post support.
* Optional related post excerpt display.
* Setting to exclude administrators from analytics (enabled by default).
* "Clear Analytics" maintenance tool.
* Maintenance section in Dashboard for analytics and token map utilities.

### Improved

* Interstitial can now display related content cards.
* Better flexibility for affiliate-specific messaging.
* Cleaner maintenance workflow from the Dashboard.

### Fixed

* Fixed administrator exclusion logic in analytics tracking.
* Fixed redirect flow when admin analytics exclusion is enabled.
* Fixed undefined variable warning in `Redirect_Manager`.
* Improved interstitial stability and redirect handling.

### Notes

* No database migrations required.
* No changes to redirect tokens.
* No changes to the `wpam_clicks` table structure.
* Fully compatible with existing 0.2.x installations.

### 0.2.4 — Maintenance: Rebuild Token Map

**`includes/admin/class-admin-menu.php`:**
- Nueva card "Maintenance" al final del Dashboard.
- Botón "Rebuild Token Map": vacía completamente `wpam_redirect_tokens`,
  busca todos los posts con `_wpam_links` via SQL, llama
  `Redirect_Manager::rebuild_token_map()` para cada uno (reutiliza
  la lógica existente sin duplicarla), redirige al dashboard con un
  notice que muestra posts procesados y tokens generados.
- Seguridad: nonce + `manage_options` capability check.

**`includes/class-plugin.php`:**
- Registrado hook `admin_post_wpam_rebuild_token_map`.


### 0.2.3 — Dashboard Analytics MVP

* Nuevo dashboard de analytics integrado directamente en la pantalla principal del plugin.
* Visualización de métricas reales obtenidas desde la tabla SQL `wpam_clicks`.

**`includes/admin/class-admin-menu.php`:**

* 4 stat cards nuevas en el dashboard: Clicks Today, Last 7 Days, Last 30 Days y Total Clicks.
* Queries SQL directas sobre `wpam_clicks`.
* Top Affiliates: top 10 por clicks con logo, nombre, barra de progreso y porcentaje respecto al total.
* Top Posts: top 10 por clicks con thumbnail, título y acceso rápido al editor.
* Recent Clicks: tabla compacta con los últimos 20 clicks registrados.
* Muestra fecha/hora (timezone local), afiliado, post y dominio destino.
* No muestra IP, user agent ni referer.
* Layout reorganizado con métricas superiores, columnas para rankings y sección de actividad reciente.
* Todas las queries limitadas (Top 10 / Recent 20) para mantener rendimiento óptimo.

**`assets/css/admin.css`:**

* Nuevas clases para analytics dashboard.
* Grid responsive para métricas y rankings.
* Barras de progreso visuales para Top Affiliates.
* Tabla moderna para actividad reciente.
* Chips visuales para dominios de destino.
* Responsive automático a una columna en pantallas menores a 900px.

### 0.2.1 — Tracking SQL + migración de clicks legacy

**Nuevo archivo `includes/redirect/class-clicks-table.php`:**
- `create_table()`: crea `{prefix}wpam_clicks` con dbDelta(). Columnas:
  id, ts (DATETIME DEFAULT CURRENT_TIMESTAMP), post_id, affiliate_id,
  destination_url (TEXT), referer (TEXT), ip_hash (CHAR 64), user_agent (TEXT).
  Índices en affiliate_id, post_id, ts.
- `maybe_migrate_legacy_clicks()`: idempotente via option `wpam_clicks_migrated`.
  Sale inmediatamente si ya existe. Itera afiliados, inserta clicks legacy en SQL,
  borra el meta _wpam_clicks solo si todos los inserts fueron exitosos.
- `has_legacy_meta()`: query ligera para detectar si hay datos legacy sin iterar posts.
- `migrate_affiliate_clicks()`: migra un afiliado específico. Normaliza timestamps
  del formato legacy (Unix int) a DATETIME para SQL.

**`includes/redirect/class-click-tracker.php`:**
- `record()`: inserta en SQL. IP nunca guardada en texto plano;
  se usa `hash_hmac('sha256', $ip, wp_salt())`. Registra también referer
  (via `wp_get_raw_referer()`) y user_agent sanitizado.
- `get_clicks()`: SELECT con ORDER BY ts DESC.
- `count()`: SELECT COUNT(*).

**`includes/class-activator.php`:**
- `activate()` llama `Clicks_Table::create_table()` y
  `Clicks_Table::maybe_migrate_legacy_clicks()` tras registrar la rewrite rule
  y antes del flush_rewrite_rules().

**`includes/class-plugin.php`:**
- `require_once` de `class-clicks-table.php` añadido antes de `class-click-tracker.php`.

### 0.2.0-alpha3.2 — Texto del botón interstitial configurable

**`includes/settings/class-settings.php`:**
- Nuevo campo `interstitial_button_text` en la sección "Redirect / Interstitial".
- Sanitización con `sanitize_text_field()`. Fallback automático a "Continuar"
  si el campo queda vacío.
- Default añadido en `get_defaults()`.

**`includes/redirect/class-interstitial-renderer.php`:**
- El botón principal del interstitial ya no tiene texto hardcoded.
- Lee `interstitial_button_text` de las settings del plugin.
- Fallback a "Continuar" si el setting no existe.

### 0.2.0-alpha3.1 — Settings UI fixes

**`assets/css/settings.css`:**
- Fix del toggle/switch: el checkmark nativo de WordPress ya no aparece encima
  del toggle custom. Se añadieron `border:none`, `box-shadow:none`,
  `color:transparent`, `overflow:hidden` y `::before{display:none}` para
  suprimir cualquier pseudo-elemento o decoración nativa del browser.
- Foco del toggle ahora usa `outline` en lugar de `box-shadow` para mantener
  accesibilidad por teclado sin interferir con el thumb del switch.
- Botón Save restaurado y visible: selectores ampliados a
  `input[type="submit"]` además de `.button-primary`. Añadidos
  `display:block !important` y `visibility:visible !important` al contenedor
  `.submit` y `p.submit` para evitar herencia del admin de WordPress.

**`includes/settings/class-settings.php`:**
- Campo `redirect_delay` cambiado de `<input type="number">` a `<select>`
  con opciones fijas: 0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60 segundos.
- Máximo permitido actualizado de 30s a 60s.
- Sanitización actualizada: valida contra la lista de valores permitidos.
  Valores fuera de lista hacen fallback a 5s. Clamp absoluto a 60s para
  valores enviados manualmente fuera del select.
- La opción 0s muestra "0s — Redirect instantáneo" para dejar claro el comportamiento.

### 0.1.4 — Auto-detección de afiliado en el editor de posts

**Nuevo archivo `assets/js/domain-detector.js`:**
- Módulo compartido `window.WPAMDomainDetector` con `normalizeDomain()`,
  `extractDomain()` y `findByDomain()`.
- Elimina la duplicación de lógica de detección entre el board Post Affiliates
  y el editor de posts. Un solo archivo, dos consumidores.

**`assets/js/post-links.js`:**
- Eliminado el select manual de afiliado del formulario.
- Auto-detección por dominio con debounce 500ms usando `WPAMDomainDetector`.
- Chip preview visual al detectar afiliado (logo + nombre + color).
- Preview de URL final generada client-side con param/value del afiliado detectado.
- Error inline si no hay afiliado para el dominio.
- Detección inicial en filas ya cargadas al abrir el editor.

**`assets/js/post-affiliates.js`:**
- `DomainDetector` local reemplazado por alias de `window.WPAMDomainDetector`.
- Sin cambios funcionales; comportamiento idéntico a v0.1.3.

**`includes/posts/class-post-links.php`:**
- Eliminado el `<select>` de afiliado del formulario del meta box.
- `render_link_item()`: ahora muestra URL + `.wpam-detect-preview` + `.wpam-detect-error` + Label.
- Nuevo método `render_detect_chip()` para el chip inicial en items existentes.
- `save()`: ya no recibe `provider_id` del formulario. Detecta el afiliado
  automáticamente por dominio usando `Repository::find_by_domain()` +
  `wpam_extract_domain_from_url()`, exactamente igual que `ajax_save_post_links()`.
  Links sin afiliado coincidente se descartan silenciosamente.
- `render_meta_box()`: añade `data-affiliates` con dominios pre-normalizados
  para matching JS sin AJAX.

**`includes/admin/class-admin-assets.php`:**
- Encola `domain-detector.js` como dependencia de `post-links.js` en el editor de posts.
- Encola `domain-detector.js` como dependencia de `post-affiliates.js` en el board.
- Añade campo `affiliates` a `wpamPostLinksData` (afiliados activos con dominios
  pre-normalizados, param y value).
- Nuevo método privado `get_affiliates_for_js()`.
- Actualizado string `preview_placeholder` en i18n.

**`assets/css/post-links.css`:**
- `.wpam-link-row--detected`: borde verde cuando el afiliado es detectado.
- `.wpam-link-row--error`: borde rojo cuando no hay coincidencia.
- `.wpam-detect-preview` / `.wpam-detect-chip`: chip visual del afiliado detectado.
- `.wpam-detect-error`: mensaje de error inline animado.

**Compatibilidad:**
- Links existentes en DB siguen funcionando sin migración.
- `provider_id` se sigue guardando en meta; ahora lo asigna el backend por dominio.
- Compatible con editor clásico y Gutenberg (meta box estándar de WordPress).
- No interfiere con el flujo de publicación nativo de WordPress.

### 0.1.3 — Auto-detección de afiliado por dominio

**Nuevas funciones en `helpers.php`:**
- `wpam_normalize_domain( string $domain ): string`
  Normaliza cualquier dominio o URL a su forma canónica (lowercase, sin www., sin
  protocolo, sin trailing slash). Usa `wp_parse_url()` internamente.
- `wpam_extract_domain_from_url( string $url ): string`
  Extrae y normaliza el dominio del host de una URL completa.

**`class-repository.php`:**
- Nuevo método `find_by_domain( string $domain ): ?array`
  Recorre todos los afiliados activos y compara sus `domains` (campo separado por
  comas) con el dominio dado. Soporta matching exacto y por sufijo. Case-insensitive.

**`class-post-affiliates-screen.php`:**
- Eliminado el `<select>` de afiliado/proveedor del editor inline.
- Nuevo método `render_detect_chip()` para el chip de preview en items existentes.
- `render_link_item()` ahora muestra solo: URL + preview de detección + Label opcional.
- `render_post_row()` pre-normaliza los dominios de cada afiliado como array JSON en
  `data-affiliates`, listo para matching JS sin AJAX.
- `ajax_save_post_links()`: valida la detección en PHP de forma independiente al JS.
  Detecta el afiliado por dominio, guarda `provider_id` automáticamente, rechaza URLs
  sin coincidencia y URLs duplicadas.
- Nuevo método privado `normalize_url_for_comparison()`.

**`post-affiliates.js`:**
- Nuevo módulo `DomainDetector` con `normalizeDomain()`, `extractDomain()` y
  `findByDomain()`. Espejo de las funciones PHP para consistencia cliente/servidor.
- `Editor.detectAffiliate()`: detección con debounce 500ms al escribir en el campo URL.
- `Editor.setDetectSuccess()`: renderiza el chip visual del afiliado detectado.
- `Editor.setDetectError()`: muestra error inline sin `alert()`.
- `Editor.clearDetectState()`: limpia el estado durante la espera del debounce.
- `Editor.refreshSaveBtn()`: habilita/deshabilita Save según estado de todos los items.
- `Save.readItem()`: ya no lee `provider_id` del DOM; solo envía `original_url` + `custom_label`.
- `normalizeUrlForComparison()`: normaliza URLs para detección de duplicados en cliente.

**`post-affiliates.css`:**
- `.wpam-pa-detect-preview` / `.wpam-pa-detect-chip`: chip visual del afiliado detectado.
- `.wpam-pa-link-item--detected`: borde verde cuando el afiliado es detectado.
- `.wpam-pa-link-item--error`: borde rojo cuando no hay coincidencia.
- `.wpam-pa-url-error`: mensaje de error inline animado.
- `.wpam-pa-save-btn:disabled`: botón Save bloqueado con opacidad reducida.

**Compatibilidad:**
- Los links existentes en DB no se modifican.
- El campo `provider_id` se sigue guardando en meta; ahora lo asigna el backend.
- Afiliados con `domains` vacío no participan en la detección automática.

### 0.1.2 — Post Affiliates State Fixes & Visual Polish

- Fix: Remove + Cancel ya no elimina afiliados visualmente de forma permanente. El botón X ahora solo afecta el estado temporal del editor abierto; Cancel restaura el estado original desde un snapshot HTML serializado inmutable capturado en `open()`, garantizando que los nodos eliminados en sesión reaparezcen exactamente como estaban.
- Fix: El snapshot anterior guardaba referencias jQuery vivas (`$item: $( this )`) al DOM; cuando el nodo era eliminado por `.remove()`, la referencia quedaba huérfana y Cancel no podía restaurarlo. El snapshot ahora es `{ listHtml: string, emptyVisible: bool }` — una cadena de texto serializada con `$list.html()` que `.remove()` no puede mutar.
- Fix: `cancel()` reconstruye la lista completa con `$list.html( snap.listHtml )` en lugar de iterar nodos vivos con índices desincronizados. Esto garantiza restauración exacta aunque se hayan eliminado, reordenado o añadido filas temporales durante la sesión.
- Fix: El snapshot se elimina con `delete Editor._snapshots[ postId ]` tanto en `cancel()` como en `save()`, evitando snapshots huérfanos que podrían contaminar aperturas posteriores del editor.
- Fix: Layout horizontal del board corregido de `display: grid` con `grid-template-areas` a `display: flex; flex-wrap: wrap`. El título del post usa `flex: 1 1 200px; max-width: 340px` y la área de chips usa `flex: 1 1 180px` sin `max-width` fijo, eliminando el gran hueco vacío a la derecha.
- Fix: El editor inline usa `width: 100%; flex-basis: 100%` para ocupar fila completa dentro del contenedor flex, reemplazando el `grid-area: editor` que ya no aplica.
- Mejora visual: Chips de afiliados cambian de pill ultra-redondeada (`border-radius: 20px`) a mini-card compacta (`border-radius: 6px`) con borde sutil `rgba(0,0,0,0.07)`, eliminando el aspecto de badge/tag genérico.
- Mejora visual: Logo/inicial dentro del chip aumenta de 16px a 18px y cambia de `border-radius: 50%` (círculo) a `border-radius: 3px` (cuadrado suave), más legible y consistente con el estilo card.
- Mejora visual: Hover de chips simplificado a `filter: brightness(0.94)` + `box-shadow` suave, eliminando `transform: translateY(-1px)` innecesario.
- Mejora visual: Botón "+" adopta `border-radius: 6px` coherente con los chips, altura 30px, sin animación de elevar en hover.
- Version bumped to `0.1.2`.

### 0.1.1 — Post Affiliates UX/UI Fixes

- Fix: "Add Link" ahora siempre inyecta una fila nueva clonada dinámicamente desde JS. Eliminado el contenedor reutilizable `#wpam-pa-new-wrap-{id}` — cada clic crea un nódo `<div>` único con ID basado en `Date.now()`. Resuelve el problema donde clics adicionales no producían nueva fila.
- Fix: Cancel y Save ahora destruyen el estado temporal completamente. Save reemplaza el row completo con HTML fresco del servidor (editor colapsado). Cancel elimina todas las filas marcadas como `.wpam-pa-link-item--new` y restaura los valores originales de las filas existentes desde atributos `data-orig-*`.
- Fix: El editor ya no reaparece con datos stale al reabrir después de Cancel, porque el DOM fue limpiado antes de cerrar.
- New: Filtro de status en toolbar (segmented control: All / Published / Draft / Scheduled). La selección activa aplica `post_status` a la query AJAX `wpam_load_posts`. El PHP valida el valor contra `VALID_STATUSES` whitelist.
- New: `query_posts()` acepta parámetro `$status` y aplica `post_status` dinámico; `ajax_load_posts()` acepta `status` POST var.
- Mejora: Chips de afiliados rediseñados como mini-cards visuales: logo 16px circular + label + color del afiliado via `--chip-color`/`--chip-bg` CSS custom properties. PHP calcula el `rgba()` del `brand_color` con `hex_to_rgba()`. Hover con `filter: brightness + transform + box-shadow`.
- Mejora: Botón "+" rediseñado como chip dashed con icono y label “Add”, coherente con la galía de chips.
- Mejora: Toolbar rediseñado: todos los controles tienen la misma altura (36px), icono de lupa en el search, `background: var(--wpam-gray-100)`, `box-shadow` suave, transición al foco.
- Mejora: Load More rediseñado como botón pill con borde `var(--wpam-primary)`, flecha animada en hover.
- Mejora: Filas nuevas aparecen con animación `wpam-pa-item-appear` (fade + slide down 4px).
- Mejora: Clase `wpam-pa-status--draft` ahora tiene borde `var(--wpam-gray-200)` para mejor contraste en fondo blanco.
- `class-admin-assets.php`: añadidas strings i18n para el constructor dinámico de filas JS (`label_affiliate`, `label_url`, `label_label`, `label_optional`, `label_placeholder`, `select_placeholder`, `remove_link`).
- Version bumped to `0.1.1`.

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
