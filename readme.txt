=== Bunny Affiliate Manager ===
Contributors:       bunnychase
Tags:               affiliates, affiliate links, affiliate marketing, link management
Requires at least:  6.0
Tested up to:       6.7
Requires PHP:       8.0
Stable tag:         0.0.3
License:            GPLv2 or later
License URI:        https://www.gnu.org/licenses/gpl-2.0.html

Sistema modular y escalable para administrar enlaces de afiliados por entrada/post dentro de WordPress.

== Description ==

**Bunny Affiliate Manager** es un plugin profesional para WordPress que permite gestionar
de forma centralizada los enlaces de afiliados asociados a tus entradas y páginas.

= Características actuales (v0.0.3) =

**Sistema de Afiliados**
* Registro y gestión completa de afiliados (crear, editar, eliminar)
* Activar / desactivar afiliados individualmente
* Campos por afiliado: nombre, slug, parámetro URL, valor, logo, color de marca
* Tabla de administración con logo, estado, parámetro y valor visibles
* Custom Post Type privado `wpam_affiliate` para almacenamiento nativo en WordPress

**Sistema de Links por Post**
* Meta box "Affiliate Links" en entradas (post type: post)
* Agregar múltiples links afiliados por post
* Campos por link: afiliado, URL original, etiqueta personalizada (opcional)
* Preview en tiempo real de la URL final generada (sin recargar)
* Guardado con nonce, sanitización y validación de URL completa
* Detección segura de providers huérfanos (afiliados eliminados o desactivados)
* Order correcto e incremental (0, 1, 2...) garantizado al guardar

**URL Generator**
* `wpam_generate_affiliate_url( int $affiliate_id, string $url ): string`
* `wpam_generate_affiliate_url_by_slug( string $slug, string $url ): string`
* Detecta parámetros existentes correctamente
* Si el afiliado está inactivo, retorna la URL sin modificar

**Helpers disponibles**

Afiliados:
* `wpam_get_affiliate( int $id ): ?array`
* `wpam_get_affiliates( array $args = [] ): array`
* `wpam_is_affiliate_active( int $id ): bool`

Links por post:
* `wpam_get_post_links( int $post_id, bool $active_only = false ): array`
* `wpam_get_post_link( int $post_id, int $index ): ?array`
* `wpam_post_has_links( int $post_id, bool $active_only = false ): bool`
* `wpam_get_post_links_count( int $post_id, bool $active_only = false ): int`
* `wpam_post_link_is_orphan( int $post_id, int $index ): bool`
* `wpam_normalize_link_item( array $item ): array`

= Arquitectura =

El plugin sigue una arquitectura modular orientada a clases con namespaces PHP 8.
Cada módulo tiene responsabilidad única y los hooks se registran centralmente
a través del Loader, evitando dependencias directas entre módulos.

= Próximas funcionalidades =

* Render frontend automático de links (FASE 4)
* Templates visuales: minimal, card, banner
* Estadísticas de clics
* Bloques de Gutenberg
* Drag & drop para reordenar links
* Soporte para más post types

== Installation ==

1. Sube la carpeta `wp_affiliatemanager` al directorio `/wp-content/plugins/`.
2. Activa el plugin desde el menú "Plugins" de WordPress.
3. Ve a **Bunny Affiliates → Affiliates** para registrar tus primeros afiliados.
4. Edita cualquier entrada para ver el meta box "Affiliate Links".

== Frequently Asked Questions ==

= ¿Requiere algún plugin adicional? =

No. Bunny Affiliate Manager funciona de forma autónoma sobre WordPress estándar.

= ¿Es compatible con WooCommerce? =

La integración con WooCommerce está planificada para versiones futuras.

= ¿Puedo agregar links afiliados en páginas? =

Actualmente solo en entradas (post). Puedes extender el soporte a otros post types
usando el filtro `wpam_post_links_post_types`.

= ¿Qué pasa si elimino un afiliado que ya tiene links en posts? =

Los links existentes son detectados como "huérfanos" y se muestran con un aviso
en el meta box. Los posts no se rompen. Puedes reasignar el link a otro afiliado
o eliminarlo desde el editor de la entrada.

= ¿Se guardan las URLs finales en la base de datos? =

No. Las URLs finales se generan en tiempo de ejecución usando `wpam_generate_affiliate_url()`.
Esto garantiza que si cambias el parámetro de un afiliado, todos los posts reflejan
el cambio automáticamente sin actualizar cada entrada.

== Changelog ==

= 0.0.3 — Polish & Stability =
* Fix: el campo `order` ahora siempre se guarda con valor incremental correcto (0, 1, 2...)
  tanto en PHP (re-asignación obligatoria al guardar) como en JS (reindexAll() en DOM).
* Fix: validación de URL mejorada con `filter_var(FILTER_VALIDATE_URL)` + verificación
  de esquema (solo http/https). URLs inválidas o con esquemas no permitidos son descartadas.
* Fix: providers huérfanos (afiliados eliminados o desactivados) ya no generan PHP warnings.
  `get_links()` los detecta y retorna `_orphan => true` con `_orphan_title` para el UI.
* Fix: `render_link_row()` muestra aviso visual (fondo amarillo + mensaje) en filas huérfanas.
* Fix: select del provider en filas huérfanas tiene borde de advertencia visual.
* Fix: preview en filas huérfanas muestra la URL original con indicador "sin afiliado aplicado".
* Mejora: botón "Add Link" se deshabilita automáticamente si no hay afiliados activos,
  con aviso y enlace directo a la pantalla de afiliados.
* Mejora: placeholder de lista vacía mejorado con icono 🔗 y mejor separación visual.
* Mejora: `wpam_get_post_links()` acepta `$active_only` para filtrar huérfanos fácilmente.
* Mejora: `wpam_normalize_link_item()` garantiza todas las claves del array (safe array access).
* Mejora: `wpam_post_link_is_orphan()` como helper directo para templates.
* Mejora: validación de URL en cliente (JS) con URL API + feedback visual inmediato.
* Mejora: `updateCounter` apunta a `#wpam-links-count` (ID específico, más robusto).
* Actualización de versión a 0.0.3 en plugin header, constante y assets.

= 0.0.2 — FASE 3: Sistema de Links por Post =
* Nuevo: meta box "Affiliate Links" en entradas.
* Nuevo: sistema de links por post (provider, URL, label, order).
* Nuevo: preview dinámico de URL final en tiempo real (sin AJAX).
* Nuevo: guardado con nonce + sanitización + validación de provider.
* Nuevo: helpers `wpam_get_post_links()`, `wpam_get_post_link()`,
  `wpam_post_has_links()`, `wpam_get_post_links_count()`.
* Nuevo: JS para gestión de filas (agregar, eliminar, preview).
* Nuevo: CSS dedicado para el meta box (cargado solo en post screens).

= 0.0.1 — FASE 1 & 2: Arquitectura base + Sistema de Afiliados =
* Arquitectura modular orientada a clases con namespaces.
* Loader central de hooks (acciones y filtros).
* Hooks de activación/desactivación con validación de requisitos.
* Custom Post Type `wpam_affiliate` (privado, visible en admin).
* Meta boxes de afiliados: Details, Appearance, Status.
* Repositorio de afiliados con CRUD completo.
* Pantalla admin de afiliados con tabla, toggle y eliminación.
* URL Generator: `wpam_generate_affiliate_url()`.
* WordPress Settings API con sanitización por campo.
* Sistema de templates con soporte para override desde el tema.
* REST API endpoint `/wp-json/wpam/v1/status`.
* Dashboard con contadores reales de afiliados.

== Upgrade Notice ==

= 0.0.3 =
Correcciones importantes de estabilidad. Actualización recomendada.
Los links existentes se re-indexarán correctamente al próximo guardado del post.
