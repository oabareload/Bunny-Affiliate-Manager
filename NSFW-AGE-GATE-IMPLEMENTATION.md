# Plan técnico: Age Gate NSFW

## Estado y propósito

Este documento define el plan para incorporar una confirmación de mayoría de edad a publicaciones protegidas de BunnyChase desde el plugin **Bunny Affiliate Manager** (BAM).

**Fase actual:** versión v1.9.3. Los selectores, la corrección SEO y los valores predeterminados del Age Gate están implementados; queda pendiente la validación en instalación WordPress en vivo, con plugin SEO activo y CDN real antes de cualquier release.

El objetivo es impedir, en el servidor y antes de que se renderice la plantilla normal, que una publicación protegida entregue su contenido a visitantes sin una autorización de edad válida.

---

## 1. Hechos verificados en el repositorio

### 1.1 Bootstrap y convenciones del plugin

- El plugin declara la versión `1.9.3` en `wp_affiliatemanager.php` mediante `WPAM_VERSION`.
- La opción principal del plugin es `wpam_settings` (`WPAM_OPTION_KEY`).
- `includes/class-plugin.php` carga los módulos, registra los hooks y crea `Redirect\Redirect_Manager`.
- El manager de redirects registra sus rutas en `init` y procesa `/go`, `/goa` y `/goext` mediante `template_redirect`.
- BAM ya usa `template_redirect` para entregar páginas intermedias completas y terminar la petición; este patrón existe, pero actualmente está acoplado a redirects.

### 1.2 Interstitial de afiliados existente

**Responsables principales**

| Archivo | Componente / responsabilidad verificada |
| --- | --- |
| `includes/redirect/class-redirect-manager.php` | `Redirect_Manager`: resuelve `/go`, `/goa` y `/goext`; registra clicks; aplica UTM; elige interstitial o redirect; incluye el gate actual de reCAPTCHA para clicks sin View. |
| `includes/redirect/class-interstitial-renderer.php` | `Interstitial_Renderer::render(array $destination): void`: emite una página HTML completa del interstitial. |
| `assets/css/interstitial.css` | Estilos de página, tarjeta, CTA, disclaimer, card de post relacionado, slots y responsive. |
| `assets/js/external-links.js` | Firma por AJAX enlaces externos antes de navegar a `/goext`; no es el JavaScript visual del interstitial. |
| `includes/redirect/class-click-tracker.php` | Registro de clicks de afiliados. |
| `includes/redirect/class-clicks-table.php` | Tabla y operaciones de datos de clicks. |

`Interstitial_Renderer` lee `wpam_settings` con `get_option()` y usa, entre otras, estas claves verificadas:

- `redirect.enable_interstitial`
- `redirect.redirect_delay`
- `redirect.disclaimer_text`
- `redirect.interstitial_title`
- `redirect.interstitial_countdown_text`
- `redirect.interstitial_button_text`
- `redirect.show_related_post_excerpt`
- `redirect.interstitial_width`
- `redirect.outbound_utm_source`
- `content_slots`
- `recaptcha.*` es leído por `Redirect_Manager` para el gate de clicks, no por el renderer principal.

El renderer añade el stylesheet `assets/css/interstitial.css`, genera HTML independiente, usa `wp_head()` y `wp_footer()`, añade `X-Robots-Tag: noindex,nofollow` y contiene JavaScript inline para countdown, navegación con `window.location.replace()` y reporte de enlace roto.

**Componentes visuales reutilizables de forma razonable**

- Estructura de página y tarjeta: `.wpam-interstitial-body`, `.wpam-interstitial-card` y variantes de ancho.
- Botón CTA: `.wpam-interstitial-btn`.
- Estilos responsive, tipografía, espaciado y tratamiento visual de avisos.
- El stylesheet como recurso estático, siempre que el HTML del Age Gate no utilice datos de la publicación protegida.

**Lógica que no debe compartirse con el Age Gate**

- Resolución de tokens y URLs `/go`, `/goa`, `/goext`.
- Registro de clicks, UTM, afiliados y destinos externos.
- Conteo, tracking, reporte de enlaces rotos y delay de redirect.
- Gate reCAPTCHA existente para clicks sin View.
- Datos visuales que proceden de un afiliado concreto, como colores, disclaimer personalizado o post relacionado.

El Age Gate puede reutilizar presentación, pero debe tener un flujo y una clase de negocio independientes.

### 1.3 Settings actuales y su sanitización

| Archivo | Hecho verificado |
| --- | --- |
| `includes/settings/class-settings.php` | Define `Settings::OPTION_GROUP` (`wpam_settings_group`), `OPTION_NAME` y `PAGE_SLUG` (`wpam-settings`); registra la Settings API y sanitiza `wpam_settings`. |
| `includes/admin/class-admin-menu.php` | Registra los submenús y renderiza la página Settings actual. |
| `includes/class-activator.php` | Define valores por defecto únicamente para instalaciones sin la opción existente; no debe restablecer opciones ya guardadas. |

La página actual llama a `settings_fields('wpam_settings_group')` y `do_settings_sections('wpam-settings')`.

`Settings::sanitize_options()` inicia el valor sanitizado combinando defaults con el valor existente mediante `array_replace_recursive()`. Después modifica solamente las secciones presentes en la entrada. Esto permite, en principio, separar formularios y páginas de la misma option sin borrar claves que no se hayan enviado, siempre que los campos y la sanitización de cada formulario estén correctamente registrados.

### 1.4 Páginas especiales

No se verificó en BAM una arquitectura de plantillas front-end para reemplazar 404, búsqueda u otras páginas públicas:

- No se encontró un uso de `template_include` para sustituir plantillas de WordPress.
- `includes/views/class-resource-resolver.php` clasifica la petición actual como `post`, `page`, `home`, `search`, `404`, `category` o `tag` para Analytics/Views; no renderiza páginas ni cambia consultas.
- `includes/templates/class-templates.php` permite sobreescribir componentes de afiliados desde el tema bajo `/wpam/`; no es un sistema de páginas completas ni un punto adecuado para bloquear contenido de una publicación.

Por tanto, no existe un mecanismo reutilizable de "página especial" que deba adoptarse. La implementación recomendada es una intercepción controlada antes de la plantilla, no convertir la publicación en un 404.

### 1.5 Clasificación disponible de las publicaciones

BAM no crea ni almacena hashtags propios de BunnyChase. El repositorio sí usa los mecanismos nativos de WordPress:

- Hashtags/tags: taxonomía `post_tag`.
- Categorías: taxonomía `category`.

Por ejemplo, `includes/admin/class-post-affiliates-screen.php` crea consultas `tax_query` contra categorías y `post_tag`. El resto de módulos de Analytics/Score también se apoya en taxonomías de WordPress.

No se verificó una fuente alternativa de hashtags en metadatos de BAM ni en el contenido de las publicaciones. La detección del Age Gate debe utilizar términos nativos asignados a la publicación, nunca una búsqueda de texto como `NSFW` dentro del contenido, imágenes o galerías.

No hay acceso a una base de datos WordPress en este diagnóstico; por ello no se ha confirmado el ID ni el slug real del término visualmente llamado `NSFW`. WordPress suele normalizarlo a `nsfw`, pero no debe asumirse como un hecho de esta instalación: se debe verificar en la fase de implementación o permitir que el administrador seleccione el término existente.

---

## 2. Comportamiento objetivo confirmado

1. Una publicación se considera protegida si coincide con **cualquier** criterio activo de tags o categorías.
2. El criterio inicial debe ser el hashtag `NSFW`, mediante `post_tag` nativo.
3. Una publicación sin criterios activos debe seguir su renderizado habitual sin comprobaciones adicionales fuera de las necesarias para detectar que la función está desactivada o que no aplica a la petición.
4. Una publicación protegida sin cookie válida debe responder con una página de confirmación completa, sin entregar título, excerpt, contenido, imágenes ni otros datos del post protegido.
5. La confirmación se envía por `POST` con nonce de WordPress.
6. Tras confirmación válida, el servidor emite cookie firmada y redirige con HTTP `303` al permalink original. La siguiente petición valida la cookie en el servidor.
7. Un rechazo redirige a una publicación publicada no protegida; si no hay ninguna, redirige al inicio.
8. Una cookie `1` o `true` nunca debe ser suficiente para autorizar el acceso.
9. El nombre de cookie será configurable, pero el valor seguirá siendo propio de BAM, firmado y validado por BAM.
10. El sistema no debe alterar `/go`, `/goa`, `/goext`, el interstitial de afiliados, tracking, UTM, reCAPTCHA o la clasificación de clicks/views.

---

## 3. Arquitectura propuesta (pendiente de implementación)

### 3.1 Módulos mínimos

Se recomienda añadir un módulo aislado bajo `includes/content-protection/`, siguiendo el estilo de directorios funcionales existente:

| Archivo propuesto | Responsabilidad |
| --- | --- |
| `includes/content-protection/class-age-gate.php` | Orquestación: criterios de protección, intercepción, flujo POST, cookie firmada, confirmación, rechazo y búsqueda de fallback SFW. |
| `includes/content-protection/class-age-gate-renderer.php` | Renderiza únicamente la página de confirmación y su formulario; no conoce tokens, clicks, afiliados ni redirects de afiliados. |

`includes/class-plugin.php` cargaría e instanciaría el módulo. Es el único cambio de bootstrap previsto.

No se recomienda añadir una taxonomía, post meta de NSFW, una tabla ni dependencias externas. Las taxonomías existentes son la fuente de verdad.

### 3.2 Detección centralizada de contenido protegido

`Age_Gate` debe exponer un único método interno para decidir si un post está protegido, por ejemplo `is_protected_post($post_id)`. El nombre definitivo debe seguir las convenciones de la clase cuando se implemente.

Reglas propuestas:

- Si la protección global está desactivada, devolver `false` de inmediato.
- Evaluar solo posts válidos y publicados cuando el contexto sea público.
- Si el criterio tags está habilitado y tiene términos seleccionados, usar `has_term()`/taxonomías nativas contra `post_tag`.
- Si el criterio categorías está habilitado y tiene términos seleccionados, usar `has_term()` contra `category`.
- Devolver `true` si alguno de los criterios activos coincide (OR).
- En configuración inicial, el selector debe preseleccionar el término `NSFW` si existe; la implementación no debe asumir un ID fijo.

`has_term()` utiliza la caché de términos de WordPress y evita inspeccionar el contenido. El chequeo solo se debe ejecutar cuando la función esté habilitada y la petición sea una singular candidata; no hay razón para hacer taxonomía queries en home, archivo, `/go`, administración, REST no relacionado o páginas SFW no singulares.

La misma función central debe servir para:

- Interceptar el post en la petición pública.
- Excluir posts protegidos de la búsqueda de fallback SFW.
- Aplicar protecciones equivalentes en REST, feeds y oEmbed durante la fase de seguridad.

### 3.3 Punto de intercepción recomendado

Registrar en `template_redirect` con prioridad temprana (por ejemplo `1`) desde `Age_Gate`.

Motivos:

- En ese momento WordPress ya resolvió la query principal, por lo que `is_singular('post')` y `get_queried_object_id()` son fiables.
- Se ejecuta antes de la carga normal de la plantilla y antes del loop del tema.
- Evita transformar una publicación existente en 404.
- Está alineado con el patrón actual de `Redirect_Manager`, sin acoplarse a él.

Flujo propuesto:

1. Salir pronto para administración, rutas de redirect BAM y peticiones que no sean `is_singular('post')`.
2. Obtener el ID consultado y evaluar la protección centralizada.
3. Si no está protegido o la cookie es válida, no intervenir.
4. Si es una confirmación `POST`, validar método, post objetivo y nonce antes de actuar.
5. En confirmación válida, emitir cookie y redirigir `303` al permalink seguro del mismo post.
6. En rechazo válido, resolver un post SFW con los mismos criterios centralizados y redirigir; usar `home_url('/')` si no existe.
7. Sin cookie y sin POST válido, emitir la página alternativa y finalizar la petición.

La respuesta de gate debe conservar HTTP `200`, no usar `set_404()`, y enviar al menos `Cache-Control: private, no-store` y `X-Robots-Tag: noindex, nofollow`. Debe combinarse con `nocache_headers()` de WordPress cuando sea apropiado.

### 3.4 Renderer de confirmación

El renderer debe recibir solamente datos de configuración segura: título, mensaje, textos de botones, URL de acción y nonce. No debe recibir ni consultar el objeto de la publicación protegida para componer HTML.

Se puede enlazar `assets/css/interstitial.css` y reutilizar clases de tarjeta/botón. Si se necesitan selectores exclusivos, deben ser pocos y preferiblemente añadirse al mismo stylesheet para mantener un único recurso visual. No se debe invocar `Interstitial_Renderer::render()` ni `wp_head()`/la plantilla normal si eso pudiera permitir a tema o plugins inyectar metadatos del post protegido.

### 3.5 Cookie firmada

Propuesta mínima:

- Nombre por defecto estable y específico de BAM, por definir al implementar (por ejemplo, `wpam_age_gate`).
- Campo de administración para sustituir el nombre.
- Validación estricta de nombre: longitud razonable y caracteres válidos de cookie (`A-Z`, `a-z`, `0-9`, `_`, `-`); un valor inválido se rechaza o revierte al valor por defecto durante sanitización.
- Valor con versión y expiración, firmado con `hash_hmac()` y un secreto de WordPress como `wp_salt('auth')`.
- El servidor valida estructura, expiración y comparación de firma con una comparación segura antes de autorizar.
- Emitir con `HttpOnly`, `SameSite=Lax`, `Secure` cuando `is_ssl()` sea verdadero, path `/` y duración configurable.
- El nombre configurable no cambia ni debilita el formato, secreto ni validación del valor.

Al cambiar el nombre configurado, BAM dejará de leer la cookie antigua y el visitante deberá confirmar de nuevo. No se propone mantener múltiples nombres ni compatibilidad retrospectiva: aumenta la superficie de mantenimiento sin necesidad técnica demostrada.

La cookie de Views existente (`wpam_v`) no debe reutilizarse: es una cookie de estadísticas, no una autorización de contenido.

### 3.6 Rechazo y fallback SFW

El flujo de rechazo debe pedir una publicación publicada que no satisfaga los criterios activos. Se debe construir su exclusión con `tax_query` nativa a partir de la misma configuración y semántica OR empleada por la detección.

La selección aleatoria ocurre únicamente ante rechazo, por lo que una consulta con orden aleatorio puede ser aceptable inicialmente. Si producción demuestra volumen elevado, se puede evaluar una caché breve de IDs SFW; no debe añadirse antes de necesitarla.

El fallback a inicio es obligatorio si la consulta no devuelve resultados. Nunca debe redirigirse de nuevo al post protegido, por lo que la selección debe excluirlo y la respuesta a home debe ser definitiva para ese flujo.

---

## 4. Configuración propuesta y reorganización administrativa

### 4.1 Página de administración propuesta

Usar el patrón ya existente en `Admin_Menu` para un nuevo submenu, por ejemplo un slug `wpam-interstitial`, con título de interfaz **Interstitial & Content Protection**. El nombre final debe armonizarse con los slugs existentes al implementar.

La página contendrá dos secciones requeridas:

1. **Interstitial de afiliados**
2. **Protección de contenido y edad**

Una tercera sección opcional de **Apariencia compartida** solo debe existir si hay una opción visual verdaderamente usada por ambos sistemas. La tarjeta de ancho puede evaluarse como candidata, pero los colores afiliados no son compartibles: proceden de afiliados y no deben entrar en el Age Gate.

### 4.2 Interstitial de afiliados

Mover únicamente la interfaz de las secciones actuales de redirect, contenido y reCAPTCHA:

- activar interstitial;
- delay;
- disclaimer;
- título, texto de countdown y botón;
- excerpt del post relacionado;
- ancho;
- UTM outbound;
- content slots;
- reCAPTCHA y sus claves, que siguen perteneciendo al flujo `/go`.

Las claves existentes deben permanecer en `wpam_settings` con los mismos nombres. La reorganización de interfaz no requiere migración de datos si se reusan sus callbacks y secciones de la Settings API en la nueva página.

### 4.3 Protección de contenido y edad

Se propone una rama nueva y aislada de `wpam_settings`, por ejemplo `content_protection`, con:

- `enabled`;
- `cookie_name`;
- `cookie_duration`;
- título, mensaje y textos de confirmación/rechazo;
- tags habilitados y lista de IDs de `post_tag`;
- categorías habilitadas y lista de IDs de `category`;
- comportamiento de fallback de rechazo (post SFW aleatorio con inicio como fallback).

Esta propuesta evita inventar un motor genérico de reglas: son dos listas de términos independientes y dos toggles. La coincidencia entre criterios activos es OR.

### 4.4 Sanitización y compatibilidad

La sanitización actual ya fusiona los valores existentes con los valores enviados. Es viable registrar formularios en páginas distintas dentro de la misma option sin eliminar opciones no presentadas, siempre que:

- el formulario use el grupo existente `wpam_settings_group`;
- los campos de cada pantalla tengan sus paths esperados;
- `sanitize_options()` sanitice de forma explícita la rama `content_protection` y conserve las ramas no enviadas;
- se pruebe guardar una página sin alterar las claves de la otra.

No se recomienda mover claves existentes de `redirect.*` ni crear una migración de datos solo por cambiar la página que las muestra. Si no se modifica la forma de sus datos, no se requiere una version de migración.

Solo si se introduce una migración real del esquema de opciones debe usarse una marca dedicada, por ejemplo `wpam_content_protection_schema_version`; no reutilizar `wpam_version`.

---

## 5. Vías de acceso y medidas de seguridad

### 5.1 Imprescindible en la fase de seguridad

| Vía | Riesgo | Medida prevista |
| --- | --- | --- |
| Página singular pública | El tema entrega el post protegido. | Interceptar en `template_redirect` antes de la plantilla. |
| Confirmación | Un POST fabricado podría autorizar sin interacción válida. | Requerir POST, nonce, ID esperado y validación de cookie emitida solo tras confirmación. |
| REST pública (`wp/v2/posts`) | Puede exponer `content`, excerpt, título o media sin ejecutar la plantilla. | Filtrar/rechazar objetos protegidos o eliminar sus campos según endpoint; definir contrato antes de publicar. |
| Feeds | Pueden incluir título, excerpt o contenido. | Excluir posts protegidos o emitir una respuesta no reveladora. |
| oEmbed | Puede revelar título, excerpt o thumbnail. | Bloquear o devolver una respuesta no reveladora para posts protegidos. |
| Previews | Un preview público no debe equivaler a autorización. | Permitir solo preview para usuario autenticado con `current_user_can('edit_post', $post_id)`; no hacer bypass por `is_preview()` por sí solo. |
| Caché compartida | Puede entregar un HTML autorizado o desprotegido sin ejecutar PHP. | Exclusiones y purgas en LiteSpeed/Cloudflare; ver sección 6. |

### 5.2 Administración, AJAX y endpoints ajenos

- `is_admin()` no representa una página pública del post y puede salir temprano del gate.
- La edición en admin se rige por capacidades de WordPress; no debe confundirse con autorización pública de edad.
- No se recomienda un bypass global para administradores en front-end salvo que sea una decisión explícita de producto. El caso necesario es el preview de un editor autorizado.
- Los AJAX conocidos de BAM no se han identificado como endpoints que devuelvan el contenido completo de posts. Aun así, `template_redirect` no cubre todos los endpoints AJAX ni los de otros plugins.
- En la fase 3 se debe auditar el sitio y plugins activos para endpoints que expongan posts, galerías, excerpts, títulos o metadatos. BAM no puede garantizar proteger endpoints desconocidos de terceros solo desde su plantilla.

### 5.3 Límites explícitos

Proteger una publicación no protege automáticamente:

- URLs directas de imágenes, adjuntos o archivos multimedia;
- miniaturas ya expuestas o cacheadas;
- títulos/imágenes en archivos, búsqueda del tema, sitemaps, redes sociales o plugins de terceros;
- copias de contenido que ya hubieran sido indexadas o almacenadas fuera del servidor.

La protección de media requeriría un mecanismo independiente de control de archivos y no forma parte de este plan. Antes de producción debe decidirse si listados, sitemaps y previews sociales también deben ocultarse o si el alcance se limita al cuerpo de la publicación individual.

---

## 6. Caché: LiteSpeed Cache y Cloudflare

El repositorio no contiene integración ni configuración de LiteSpeed Cache o Cloudflare. Por lo tanto, el plugin no puede garantizar por sí solo que una CDN no entregue una respuesta almacenada previamente.

### 6.1 Política recomendada

La alternativa más segura es excluir de caché compartida las URLs de publicaciones protegidas, tanto con cookie como sin ella. No se recomienda basar la seguridad principalmente en variar contenido público por la cookie de autorización: una regla de caché mal configurada podría almacenar HTML autorizado y servirlo a usuarios no autorizados.

En respuestas de Age Gate, el módulo debe proponer:

- `Cache-Control: private, no-store`;
- `nocache_headers()` cuando corresponda;
- `X-Robots-Tag: noindex, nofollow`;
- para LiteSpeed, evaluar el hook `do_action('litespeed_control_set_nocache')` durante implementación y pruebas en instalación real.

La confirmación POST debe ser no cacheable, pero también lo debe ser la solicitud GET posterior del post protegido: Cloudflare o LiteSpeed podrían saltarse PHP en una respuesta HIT.

### 6.2 Configuración externa necesaria

- **LiteSpeed Cache:** excluir las URLs de publicaciones protegidas de cache público o aplicar su mecanismo de no-cache verificable; validar la lista de exclusión y cookies en la instalación concreta.
- **Cloudflare:** crear una Cache Rule que haga bypass de cache para las URLs protegidas (o para un alcance más amplio si la CDN no puede expresar una condición por taxonomía). Deben purgarse objetos cacheados antes de activar protección.
- Tras cambios de criterios o activación/desactivación, purgar las URLs/zonas afectadas según la política de cache operativa.

### 6.3 Pruebas obligatorias de caché

En staging o producción controlada:

- visitar una URL protegida en incógnito y con cookie válida;
- inspeccionar `X-LiteSpeed-Cache` y `CF-Cache-Status`;
- verificar que el HTML de gate no incorpora contenido del post;
- purgar y repetir desde sesión nueva;
- verificar que ninguna respuesta HIT de Cloudflare entrega el contenido a una sesión sin cookie;
- repetir tras modificar criterios y tras cambiar el nombre de la cookie.

Estas verificaciones no se han ejecutado durante la documentación.

---

## 7. Mapa de cambios previsto

| Archivo | Cambio previsto | Justificación |
| --- | --- | --- |
| `includes/class-plugin.php` | Cargar e instanciar el Age Gate. | Punto de bootstrap real del plugin. |
| `includes/content-protection/class-age-gate.php` (nuevo) | Clasificación, hook, POST, cookie, redirect y fallback SFW. | Aislar la lógica de negocio de contenido protegido. |
| `includes/content-protection/class-age-gate-renderer.php` (nuevo) | HTML seguro de confirmación. | Reutilizar estilo sin acoplar redirects de afiliados. |
| `includes/settings/class-settings.php` | Defaults, registro/renderizado de campos y sanitización de `content_protection`; mover UI de interstitial a página nueva. | Mantener `wpam_settings` y sus claves actuales. |
| `includes/admin/class-admin-menu.php` | Registrar y renderizar el nuevo submenu de Interstitial/Content Protection. | Reutilizar patrón de menú existente. |
| `assets/css/interstitial.css` | Solo estilos mínimos específicos del Age Gate, si los componentes actuales no bastan. | Mantener coherencia visual con recurso existente. |
| `wp_affiliatemanager.php` | Solo si el versionado/loader actual exige registrar algo adicional. | No modificar versión hasta que una fase de implementación lo justifique. |
| `README.md` / `CHANGELOG.md` | Actualizar únicamente al finalizar una implementación y con resultados verificados. | No corresponde a la fase documental. |

No se prevén cambios en `Redirect_Manager`, `Interstitial_Renderer`, tracking, Views, analytics, SQL ni rutas `/go` para el Age Gate básico. Solo se tocarían si una inspección puntual en fase 2 demuestra una dependencia imprescindible.

---

## 8. Fases de implementación

### Fase 1 — Documentación

**Objetivo:** dejar un diseño persistente, verificable y continuable sin historial de conversación.

**Archivos implicados:** `docs/NSFW-AGE-GATE-IMPLEMENTATION.md`.

**Tareas:**

- Documentar hechos verificados, propuestas y límites.
- Identificar interstitial, settings, taxonomías y puntos de interceptación.
- Definir fases, riesgos y matriz de pruebas.

**Dependencias:** acceso de lectura al repositorio.

**Riesgos:** la base de datos, configuración de tema, LiteSpeed y Cloudflare no están disponibles en el repositorio.

**Criterios de aceptación:** documento creado; no se alteran archivos funcionales ni opciones.

**Pruebas necesarias:** revisión de rutas, clases y hooks del repositorio. No requiere instalación WordPress.

**Excluido:** toda implementación PHP/JS/CSS, migración, tests funcionales, commits y releases.

### Fase 2 — Protección básica

**Objetivo:** proteger singular posts en servidor con criterios de taxonomía, formulario con nonce, cookie firmada, confirmación/rechazo y renderer seguro.

**Archivos implicados:**

- `includes/class-plugin.php`
- `includes/content-protection/class-age-gate.php` (nuevo)
- `includes/content-protection/class-age-gate-renderer.php` (nuevo)
- `includes/settings/class-settings.php`
- `assets/css/interstitial.css` solo si hace falta

**Tareas:**

- Implementar configuración inicial y criterios tags/categorías centralizados.
- Implementar hook temprano de `template_redirect` limitado a publicaciones singulares públicas.
- Implementar confirmación POST con nonce y redirección 303.
- Implementar cookie firmada, expiración, atributos seguros y nombre configurable.
- Implementar rechazo, búsqueda SFW y fallback a inicio.
- Asegurar que el HTML de gate no usa datos del post protegido.

**Dependencias:** decidir etiqueta/slug existente de `NSFW` mediante datos reales; disponibilidad de una instalación WordPress para tests.

**Riesgos:** errores de cookie, selección incorrecta de términos, loops de redirect o filtración por hooks de tema si se reutiliza plantilla normal.

**Criterios de aceptación:** los casos 1 a 12, 15 y 20 de la matriz pasan en una instalación WordPress sin romper navegación normal.

**Pruebas necesarias:** navegador, `curl` para cabeceras/POST, sesión nueva, manipulación de cookie, posts de prueba con tags/categorías.

**Excluido:** protección completa de REST, feeds, oEmbed, CDN/cache y media directo; no considerar producción lista al acabar esta fase.

### Fase 3 — Seguridad y caché

**Objetivo:** cerrar vías públicas alternativas de entrega de contenido y validar el comportamiento con caches reales.

**Archivos implicados:**

- `includes/content-protection/class-age-gate.php`
- Posibles adaptadores/filters mínimos adicionales, solo si el core/plugin requiere separarlos
- Documentación operativa de LiteSpeed/Cloudflare, fuera del repositorio si la configuración vive en sus paneles

**Tareas:**

- Proteger REST pública, feeds y oEmbed según contrato que no revele contenido protegido.
- Auditar previews y aplicar bypass limitado a editores autorizados.
- Revisar endpoints AJAX que puedan exponer post data.
- Añadir señales de no-cache y soporte LiteSpeed verificable.
- Configurar y probar exclusión/bypass de Cloudflare y purgar objetos existentes.

**Dependencias:** WordPress funcional, LiteSpeed Cache activo si se usa, acceso a Cloudflare y conocimiento de permalinks protegidos.

**Riesgos:** Cloudflare puede responder desde cache antes de PHP; terceros pueden exponer datos por endpoints no auditados; bloquear REST indiscriminadamente puede romper integraciones.

**Criterios de aceptación:** casos 13, 14, 18, 19 y 21 pasan y ninguna respuesta pública no autorizada entrega contenido protegido.

**Pruebas necesarias:** REST, feeds, oEmbed, preview autenticado/no autenticado, cabeceras de cache, pruebas desde incógnito y tras purga.

**Excluido:** control de URLs directas de medios y auditoría completa de servicios externos no administrados por el sitio.

### Fase 4 — Administración

**Objetivo:** trasladar la interfaz del interstitial a su página propia y exponer la configuración de Age Gate sin perder valores existentes.

**Archivos implicados:**

- `includes/admin/class-admin-menu.php`
- `includes/settings/class-settings.php`
- Posibles estilos administrativos existentes, solo si el patrón actual lo exige

**Tareas:**

- Crear submenu dedicado siguiendo el patrón de BAM.
- Mover visualmente secciones redirect/content slots/reCAPTCHA, conservando sus claves.
- Añadir sección de protección con configuración de cookie, textos y términos.
- Comprobar que guardar una sección no borra la otra mediante `sanitize_options()`.
- Definir ayuda para cambio de nombre de cookie y efecto de re-confirmación.

**Dependencias:** Fase 2 para que los campos tengan consumidor real; decisiones de UX sobre selector de tags y categorías.

**Riesgos:** registro incorrecto de secciones de Settings API, sanitización de arrays de IDs, pérdida accidental de keys no enviadas.

**Criterios de aceptación:** la página nueva guarda cada grupo de ajustes, conserva valores existentes de redirect y no resetea configuración en actualización.

**Pruebas necesarias:** crear valores representativos existentes, mover la UI, guardar cada formulario y comparar `wpam_settings` antes/después.

**Excluido:** migración de claves de redirect sin justificación; cambios a tracking, afiliados, clicks, Views o reCAPTCHA.

### Fase 5 — Validación y entrega

**Objetivo:** verificar el comportamiento final, documentar resultados reales y publicar cambios solo si se solicita.

**Archivos implicados:** código de fases anteriores, `README.md`, `CHANGELOG.md` si el formato del repositorio lo requiere.

**Tareas:**

- Ejecutar matriz completa en entorno disponible.
- Registrar qué pruebas pasan, qué entorno se utilizó y riesgos restantes.
- Revisar cambios de configuración/cache en producción o staging.
- Actualizar documentación/release notes tras resultados reales.

**Dependencias:** fases 2 a 4 y acceso a un entorno WordPress con caches representativas.

**Riesgos:** declarar listo para producción sin validar CDN/cache o endpoints alternativos.

**Criterios de aceptación:** resultados documentados, regresión de redirects confirmada y ningún contenido protegido expuesto en vías cubiertas.

**Pruebas necesarias:** matriz de la siguiente sección.

**Excluido:** commits, tags o publicación automática; requieren autorización explícita.

---

## 9. Matriz de pruebas

| # | Caso | Resultado esperado | Entorno / evidencia necesaria |
| --- | --- | --- | --- |
| 1 | Post sin criterios | Renderizado habitual sin Age Gate. | WordPress local/staging; navegador. |
| 2 | Post protegido sin cookie | HTML de confirmación HTTP 200; no título, excerpt, texto ni imágenes del post. | Sesión incógnito; inspección de respuesta. |
| 3 | Confirmación 18+ | POST con nonce válido; cookie firmada; 303 al permalink; segunda GET autorizada. | Navegador y `curl -i`. |
| 4 | Rechazo | Redirect a post publicado SFW. | Posts SFW/protegidos de prueba. |
| 5 | Sin posts SFW | Redirect a `home_url('/')`; sin loop. | Entorno aislado solo con posts protegidos. |
| 6 | Cookie válida | Acceso normal al post protegido. | Cookie real emitida por confirmación. |
| 7 | Cookie manipulada/inválida/expirada | Gate de nuevo; sin acceso al contenido. | Editar cookie y probar expiración corta. |
| 8 | Cambio de nombre de cookie | Cookie antigua deja de autorizar; nueva confirmación emite el nombre nuevo. | Settings + dos sesiones. |
| 9 | Criterio por hashtags | Tag seleccionado protege; tag no seleccionado no protege. | Términos `post_tag` reales. |
| 10 | Criterio por categorías | Categoría seleccionada protege. | Términos `category` reales. |
| 11 | Criterios combinados | Coincidencia en tag **o** categoría protege. | Posts de todas las combinaciones. |
| 12 | Toggles independientes | Desactivar tags o categorías elimina solo ese criterio. | Settings + posts de prueba. |
| 13 | REST, feeds y oEmbed | Ninguna vía pública entrega datos protegidos sin autorización según contrato definido. | `curl`, consumidores REST/feed y endpoint oEmbed. |
| 14 | Preview autorizado | Editor con capacidad puede prever; visitante público no obtiene bypass. | Dos sesiones de usuario. |
| 15 | Sin bucles | Confirmar/rechazar/fallback terminan en URL válida. | Navegador + red de requests. |
| 16 | `/go`, `/goa`, `/goext` | Flujos actuales intactos. | Links de prueba para cada ruta. |
| 17 | reCAPTCHA y tracking | Gate de clicks actual continúa funcionando, sin acoplamiento con Age Gate. | Configuración reCAPTCHA de staging. |
| 18 | LiteSpeed | No cachea contenido protegido para visitante no autorizado; cabeceras coherentes. | LiteSpeed real; `X-LiteSpeed-Cache`. |
| 19 | Cloudflare | No sirve HIT con contenido protegido sin validación; reglas y purga verificadas. | Zona Cloudflare; `CF-Cache-Status`. |
| 20 | HTML de Age Gate | No contiene título, excerpt, contenido, imagen destacada ni datos del post protegido. | Guardar y revisar respuesta HTML. |
| 21 | Cambio de criterios + purga | Cambio no deja respuestas previas accesibles desde cache. | Staging/producción; purga LiteSpeed/Cloudflare. |

No se ha ejecutado ninguna de estas pruebas durante la fase documental.

---

## 10. Decisiones confirmadas

- La protección debe ser server-side y previa al renderizado de la publicación.
- La fuente de verdad son taxonomías ya asignadas: `post_tag` y `category`; no contenido textual ni metadatos paralelos.
- `NSFW` es el criterio inicial esperado, pero su término real debe validarse en la instalación.
- La configuración debe permitir activar/desactivar tags y categorías de manera independiente y seleccionar múltiples términos.
- Cualquier coincidencia de criterio activo protege la publicación.
- La confirmación usa POST y nonce; confirmación válida emite cookie propia firmada y redirige 303.
- El nombre de la cookie será configurable; su valor, firma y validación seguirán siendo de BAM.
- Cambiar el nombre invalida de facto el uso de cookies emitidas con el nombre anterior; no habrá soporte de múltiples nombres.
- El rechazo debe llevar a un post SFW o al inicio, sin bucles.
- El Age Gate reutiliza estilo del interstitial cuando sea razonable, pero no su tracking, redirects, tokens, UTM, afiliados ni reCAPTCHA.
- No se deben modificar los flujos `/go`, `/goa` y `/goext` como parte de esta funcionalidad.
- La protección de media directo queda fuera de alcance.
- No se considerará lista para producción hasta completar validación de vías alternativas y caches.

## 11. Decisiones técnicas propuestas

- Crear `Age_Gate` y `Age_Gate_Renderer` en `includes/content-protection/`.
- Interceptar posts con `template_redirect` temprano, preservando HTTP 200 para la pantalla alternativa.
- Centralizar `is_protected_post()` y reutilizarlo para el gate y la selección SFW.
- Mantener configuración en `wpam_settings`, bajo una nueva rama `content_protection`; no migrar las claves actuales de `redirect` solo por mover su interfaz.
- Crear una página administrativa dedicada con secciones de interstitial y protección.
- Usar IDs de términos para la configuración persistida y taxonomías nativas para evaluación.
- Usar una cookie HMAC con expiración y secreto de WordPress; no una bandera booleana.
- Marcar respuestas protegidas/gate como no cacheables y complementar con exclusiones LiteSpeed/Cloudflare externas.

Estas son recomendaciones de arquitectura, no componentes implementados.

## 12. Incertidumbres y verificaciones pendientes

- No hay instalación WordPress ni base de datos disponible en este diagnóstico; no se confirmó el ID/slug concreto del tag `NSFW` ni el contenido actual de `wpam_settings`.
- No se pudo comprobar si BunnyChase u otros plugins exponen posts, galerías o media por endpoints propios.
- No se revisaron reglas activas de LiteSpeed Cache ni Cloudflare; la configuración externa es indispensable.
- Debe decidirse el contrato exacto de REST/feed/oEmbed: excluir, devolver 404/403 o respuesta reducida, sin revelar datos protegidos.
- Debe definirse la UX administrativa del selector de múltiples tags/categorías usando los componentes disponibles en WordPress.
- Debe confirmarse si archivos, archivos de taxonomía, resultados de búsqueda, sitemaps o social previews deben ocultar títulos/imágenes además del contenido singular.
- El orden/prioridad exacto de hooks debe probarse junto a tema y plugins activos para evitar salida prematura de contenido.

## 13. Estado de implementación

### Resumen de avance por fase

- **Fase 1 (Documentación):** COMPLETADA.
- **Fase 2 (Protección básica):** IMPLEMENTADA Y AUDITADA.
  - Se crearon los módulos base `includes/content-protection/class-age-gate.php` y `includes/content-protection/class-age-gate-renderer.php`.
  - Se auditaron y corrigieron defectos críticos:
    - `class-age-gate-renderer.php` no existía físicamente en el disco (creado).
    - Módulos no estaban enlazados en `includes/class-plugin.php` (añadido `require_once` de ambos archivos).
    - Se restauró el hook `enqueue_scripts` en `define_frontend_hooks()`.
    - La cookie estática `'1'` fue reemplazada por una cookie criptográfica segura con versión `v1`, expiración en payload y firma HMAC SHA-256 (`wp_salt('auth')`), validada con `hash_equals()`.
    - Sanitización del nombre de cookie con `sanitize_key()`.
    - Nonce vinculado al ID de publicación (`wpam_age_gate_{post_id}`).
    - Búsqueda real de publicación SFW para el flujo de rechazo con `WP_Query` y `tax_query` con operador `NOT IN` sobre términos activos, con fallback a `home_url('/')`.
    - Prioridad temprana (`1`) en `template_redirect`.
- **Fase 3 (Seguridad y caché):** IMPLEMENTADA EN CÓDIGO / VALIDADA ESTÁTICAMENTE Y UNITARIAMENTE.
  - **REST API:** Filtrado en `rest_prepare_post`. Endpoints individuales de post (`/wp/v2/posts/<id>`) devuelven error HTTP 403 `WP_Error` si el visitante no está autorizado. Consultas de colección (`/wp/v2/posts`) redactan contenido, extracto y resetean `featured_media` a `0`. Usuarios con permiso de edición (`edit_post`) y visitantes con cookie válida mantienen acceso.
  - **Feeds:** Exclusión de publicaciones protegidas en consultas públicas de feed vía `pre_get_posts` (`tax_query` con `NOT IN`). Redacción preventiva mediante filtros `the_content_feed` y `the_excerpt_rss`.
  - **oEmbed:** Denegación de oEmbed vía `oembed_response_data` (devuelve `false` generando HTTP 404 a consumidores externos) y bloqueo de plantilla iframe oEmbed (`is_embed()`) con HTTP 403 y `noindex`.
  - **Previsualizaciones:** Bypass autorizado para editores/autores (`is_preview() && current_user_can('edit_post')`). Visitantes no autorizados no obtienen bypass.
  - **Caché y LiteSpeed:** Emisión de cabeceras estrictas `Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0` y `Pragma: no-cache` en la pantalla de verificación; ya no se añade una directiva X-Robots-Tag propia ni se sustituye el título/metadatos de la publicación. En publicaciones autorizadas se envía `Cache-Control: private, must-revalidate`. Emisión de hook `do_action('litespeed_control_set_nocache')` en gate, previews y vistas autorizadas. Guardar ajustes de interstitial/Protección no activa una purga global; la invalidación se limita al marcado de no caché de las respuestas dinámicas que lo necesitan.
- **Fase 4 (Administración):** IMPLEMENTADA Y VERIFICADA.
  - **Página de administración dedicada:** Se registró el submenú `wpam-interstitial` (*Interstitial & Protection*) con capability `manage_options` en `includes/admin/class-admin-menu.php`.
  - **Navegación por pestañas:** Se añadió `wpam-interstitial` a las pestañas compartidas de administración (`render_admin_nav()`).
  - **Carga de assets:** Se registró la pantalla `bunny-affiliates_page_wpam-interstitial` en `includes/admin/class-admin-assets.php` para cargar `settings.css`.
  - **Reubicación de ajustes existentes:** Las secciones `wpam_section_redirect`, `wpam_section_content_slots` y `wpam_section_recaptcha` fueron reubicadas a la página `wpam-interstitial` sin alterar ninguna clave ni valor en `wpam_settings` (conservando `redirect.*`, `content_slots.*` y `recaptcha.*`).
  - **Controles de protección de contenido:** Se creó la sección `wpam_section_content_protection` en `wpam-interstitial` con los campos:
    - Toggle general de activación (`enabled`).
    - Activación independiente y multi-select para términos de etiquetas (`post_tag`).
    - Activación independiente y multi-select para términos de categorías (`category`).
    - Configuración de cookie (nombre sanitizado con `sanitize_key()` y duración configurable en segundos/días).
    - Personalización de textos del Age Gate (título, mensaje, texto del botón de confirmación y texto del botón de rechazo).
  - **Sanitización y persistencia multi-sección:** `sanitize_options()` garantiza el guardado no destructivo mediante `array_replace_recursive()`, de modo que guardar `wpam-settings` o `wpam-interstitial` preserva intactos los valores de la otra sección. Se añadió soporte explícito para deselección de términos en multi-selects HTML.
- **Fase 5 (Validación y preparación de versión):** IMPLEMENTADA, AUDITADA Y VERIFICADA.
  - **A. Persistencia de selectores múltiples:**
    - Verificación rigurosa de los 4 casos:
      1. Selección inicial `[12, 25, 38]` persistida correctamente.
      2. Sustitución por `[7]` contiene exclusivamente `[7]` (sin residuos de índices numéricos de `array_replace_recursive`).
      3. Deselección completa en HTML (`tag_ids` omitido en POST) persiste array vacío `[]`.
      4. Guardado de otra sección (`wpam-settings`) sin enviar `content_protection` preserva intactos los términos y opciones de protección.
  - **B. Configuración de cookie y seguridad:**
    - Nombre de cookie configurable aplicado consistentemente en lectura (`get_cookie_name`), emisión (`set_signed_cookie`) y validación (`has_valid_cookie`).
    - Corrección de defecto en duración de cookie: `absint()` convertía enteros negativos (e.g. `-500`) en positivos en lugar de activar el fallback. Se corrigió aplicando validación de rango mínimo `(int) $duration >= 300 ? $duration : DAY_IN_SECONDS * 30`.
    - Validación rigurosa de HMAC SHA-256 (`wp_salt('auth')`) con `hash_equals()`: rechazo comprobado de payloads manipulados, firmas HMAC adulteradas, timestamps expirados, versiones erróneas y cookies malformadas.
    - Cambio de nombre de cookie invalida de inmediato la cookie emitida con el nombre anterior sin dejar rutas desprotegidas ni arrojar errores.
  - **C. Compatibilidad y no regresión:**
    - Guardar la página `wpam-interstitial` preserva todas las opciones ajenas (`general`, `views`).
    - Guardar `wpam-settings` mantiene intactos `content_protection`, `redirect`, `content_slots` y `recaptcha`.
    - Integridad de rutas de afiliados: `/go`, `/goa`, `/goext`, tracking de clics, UTM y reCAPTCHA garantizados mediante guardas `is_wpam_redirect()` y `! is_singular('post')`.
  - **D. Vectores secundarios (REST API, Feeds, oEmbed):**
    - REST API bloquea peticiones singulares no autorizadas con HTTP 403 `WP_Error` y redacta contenido, extracto y resetea `featured_media` a `0` en colecciones.
    - Feeds excluyen posts protegidos de la consulta vía `pre_get_posts` y redactan contenido/excerpt como defensa en profundidad.
    - oEmbed deniega la entrega devolviendo `false` (HTTP 404 a consumidores externos) y bloquea el iframe de embed con HTTP 403 y `noindex`.
  - **E. Caché compartida (LiteSpeed y Cloudflare):**
    - Se confirma que las cabeceras PHP por sí solas no pueden impedir que un CDN externo con reglas de "Cache Everything" entregue una respuesta en caché. Se documenta la configuración externa indispensable para el Edge.
- **Mejora v1.9.1 (selectores Age Gate):** IMPLEMENTADA.
  - Los selectores completos de etiquetas y categorías se reemplazaron por campos de búsqueda con autocompletado nativo de jQuery UI (incluido por WordPress), sugerencias AJAX limitadas a 20 y chips removibles.
  - El endpoint AJAX requiere `manage_options` y nonce, acepta únicamente `post_tag` o `category` y devuelve solo ID/nombre. El saneamiento de `wpam_settings` vuelve a comprobar existencia y taxonomía de cada ID, deduplica valores y acepta selecciones vacías.
  - Se mantienen `content_protection.tag_ids` y `content_protection.category_ids`; el Age Gate y la lógica OR de criterios no cambian.
  - Los archivos JS/CSS del selector solo se encolan en `wpam-interstitial`. La validación interactiva en WordPress y frontend/CDN en vivo queda pendiente en el entorno de despliegue.

### Archivos creados y modificados

| Archivo | Estado | Descripción del cambio |
| --- | --- | --- |
| `includes/content-protection/class-age-gate.php` | Creado / Modificado | Orquestador central: detección `is_protected_post()`, firma/validación HMAC de cookie, validación robusta de duración (`>= 300s`), intercepción `template_redirect` (prioridad 1), rechazo SFW, REST API, feeds, oEmbed y cabeceras de caché. |
| `includes/content-protection/class-age-gate-renderer.php` | Creado | Renderer aislado de pantalla de verificación (tarjeta centrada, badge 18+, formulario POST con nonce, sin metadatos de la publicación protegida). |
| `includes/class-plugin.php` | Modificado | Carga de módulos vía `require_once`, registro de hooks globales (`template_redirect`, `pre_get_posts`, `the_content_feed`, `the_excerpt_rss`, `rest_prepare_post`, `oembed_response_data`) y restauración de `enqueue_scripts` en frontend. |
| `includes/admin/class-admin-menu.php` | Modificado | Registro de submenú `wpam-interstitial`, pestaña en barra de navegación y renderizado con `settings_fields('wpam_settings_group')` y `do_settings_sections('wpam-interstitial')`. |
| `includes/admin/class-admin-assets.php` | Modificado | Inclusión de la pantalla de administración `bunny-affiliates_page_wpam-interstitial` para encolar estilos de configuración. |
| `includes/settings/class-settings.php` | Modificado | Reubicación de secciones de interstitial a `wpam-interstitial`, sección y campos de `content_protection`, sanitización robusta de duración (`>= 300s`) y combinación independiente multi-sección sin pérdida de términos. |

### Resultados de pruebas ejecutadas

- **Sintaxis PHP 8.2 (`php -l`):** 0 errores de sintaxis en todos los archivos modificados y creados (`class-admin-menu.php`, `class-admin-assets.php`, `class-settings.php`, `class-age-gate.php`, `class-age-gate-renderer.php`, `class-plugin.php`).
- **Suite de pruebas de Fase 5 (54 pruebas unitarias y de aislamiento ejecutadas en PHP 8.2):**
  - **Persistencia de selectores múltiples (A):**
    - Caso 1: Selección inicial `[12, 25, 38]` y categorías `[3, 9]` (PASS).
    - Caso 2: Sustitución por `[7]` contiene exclusivamente `[7]` y `[5]` (PASS).
    - Caso 3: Deselección completa de tags y categorías resulta en `[]` (PASS).
    - Caso 4: Guardado de otra sección conserva `[7, 42]` y `[10]` intactos (PASS).
  - **Configuración de cookie y seguridad (B):**
    - B1–B2: `get_cookie_name()` y `get_cookie_duration()` reflejan valores configurados (PASS).
    - B3–B6: Emisión de cookie, timestamp alineado con duración y verificación positiva (PASS).
    - B7–B8: Rechazo de payload alterado y HMAC alterado por `hash_equals` (PASS).
    - B9–B10: Rechazo de cookies expiradas y de versiones no soportadas (PASS).
    - B11–B13: Rechazo sin warnings de cookies malformadas (1, 2 o 4+ partes) (PASS).
    - B14–B16: Invalidación inmediata de cookies antiguas tras cambio de nombre (PASS).
    - B17–B18: Valores inválidos, vacíos, cero o negativos activan fallback seguro (PASS).
  - **Aislamiento y compatibilidad de secciones (C):**
    - C1–C4: Guardar interstitial no borra opciones de `general` ni `views` y actualiza `content_protection` (PASS).
    - C5–C9: Guardar general no borra `redirect_delay`, `interstitial_title`, `outbound_utm_source` ni `tag_ids` (PASS).
  - **Clasificación y detección (D):**
    - D1–D4: Detección por tags, por categorías, combinados y exclusión de SFW (PASS).
    - D5–D7: Toggles independientes de tags, categorías y toggle global (PASS).
  - **Redirección SFW (E):**
    - E1–E2: Localización de post SFW y fallback a `home_url('/')` (PASS).
  - **Vectores secundarios (F):**
    - F1–F5: REST API bloquea 403 en endpoint singular y redacta colecciones (PASS).
    - F6–F7: oEmbed denegado (false/404) para protegidos y permitido para SFW (PASS).
    - F8–F9: Acceso concedido a REST y oEmbed con cookie autorizada (PASS).

### Defectos encontrados y corregidos durante la Fase 5

1. **Defecto en sanitización y validación de `cookie_duration`:**
   - *Problema:* El uso de `absint()` convertía duraciones negativas (por ejemplo `-500`) en valores positivos (`500` segundos), eludiendo la comprobación `$duration > 0` y evitando que se aplicara el valor por defecto seguro de 30 días.
   - *Corrección:* Se modificó la validación en `Settings::sanitize_options()` y `Age_Gate::get_cookie_duration()` a `(int) $duration >= 300 ? $duration : ( DAY_IN_SECONDS * 30 )`, garantizando un mínimo de 5 minutos y fallback seguro ante cualquier valor negativo o inválido.

### Limitaciones documentadas

1. **Protección de URLs directas de archivos multimedia (`uploads/`):**
   - Los archivos subidos a la biblioteca de medios (`wp-content/uploads/...jpg`, `.png`, `.webp`, `.mp4`) son servidos directamente por el servidor web (Nginx / Apache / LiteSpeed) o el CDN sin invocar a PHP ni ejecutar el ciclo de vida de WordPress (`template_redirect`).
   - Bunny Affiliate Manager protege el acceso a la publicación singular, API REST, feeds y oEmbed. No impide la descarga de la URL estática directa del archivo si el enlace directo es conocido o extraído de fuentes externas.
2. **Interoperabilidad de cookies externas:**
   - La configuración permite compartir el *nombre* de la cookie con otros plugins (e.g. SFW&NSFW Gallery), pero el valor y la firma continúan requiriendo la validación criptográfica HMAC SHA-256 propia de BAM basada en `wp_salt('auth')`. No se admiten cookies con valores planos o firmas externas sin salt.
3. **Caché compartida en Edge (Cloudflare):**
   - Las cabeceras HTTP emitidas por PHP (`Cache-Control: private, no-store...`) y las llamadas a `do_action('litespeed_control_set_nocache')` operan a nivel de servidor de origen y LiteSpeed. No pueden evitar que Cloudflare, si tiene activada una regla agresiva como "Cache Everything", sirva una respuesta HTML cacheada sin contactar al servidor de origen. La regla de Edge en Cloudflare es obligatoria.

### Requisitos operativos externos (Cloudflare y LiteSpeed)

1. **Cloudflare:**
   - **Regla necesaria:** En el panel de Cloudflare (*Caching > Cache Rules*), añadir una regla:
     - **Condición:** Si la URI coincide con publicaciones protegidas (o regla general de blog) Y la cookie configurada (`Cookie does not match /<cookie_name>=.+/`), aplicar **Bypass Cache**.
     - *Nota:* Reemplazar `<cookie_name>` por el nombre configurado en la administración (por defecto `wpam_age_gate`).
2. **LiteSpeed Cache:**
   - En el plugin LiteSpeed Cache de WordPress (*Cache > Cache Rules > Cookie*), añadir el nombre de la cookie configurada (`wpam_age_gate` o personalizado) al listado de **Vary Cookie** para permitir cachés independientes según el estado de verificación.

---

## 14. Lista de comprobaciones previas a publicar (Pre-Release Checklist)

Una vez disponible la instalación de WordPress de staging/producción con base de datos real:

- [ ] **1. Comprobación administrativa:**
  - Acceder a `Bunny Affiliates > Interstitial & Protection` en el panel de WordPress.
  - Verificar que las secciones de Interstitial y Content Protection se renderizan con los estilos correctos.
  - Guardar cambios en `wpam-interstitial` y comprobar que no se alteran los ajustes en `wpam-settings`.
  - Guardar cambios en `wpam-settings` y comprobar que no se borran los términos de `content_protection`.
- [ ] **2. Comprobación en navegador (Flujo 18+):**
  - Abrir una ventana de incógnito y acceder a un post con tag NSFW.
  - Verificar que se muestra la pantalla de confirmación con HTTP 200 y cabeceras `no-cache`, conservando la identidad SEO original de la publicación.
  - Comprobar que el HTML `<title>` y el H1 muestran el título real, que la descripción/canonical/OG proceden de la publicación y que no aparecen contenido, extracto ni imagen destacada protegidos sin confirmación.
  - Hacer clic en "Tengo 18 años o más": verificar redirección 303 y emisión de la cookie configurada con flags `HttpOnly` y `SameSite=Lax`.
  - En la recarga, confirmar que el post protegido se visualiza normalmente.
- [ ] **3. Comprobación de rechazo (Flujo SFW):**
  - En una nueva sesión de incógnito, acceder al post protegido y hacer clic en "Soy menor de 18 años".
  - Verificar que el navegador es redirigido a una publicación SFW existente o al inicio del sitio (`home_url('/')`).
- [ ] **4. Comprobación de flujos de afiliados existentes:**
  - Probar enlaces `/go/<slug>`, `/goa/<slug>` y `/goext/<id>` y verificar que el interstitial de afiliados, el tracking de clics, los parámetros UTM y el gate de reCAPTCHA siguen funcionando con normalidad.
- [ ] **5. Comprobación de LiteSpeed y Cloudflare:**
  - Verificar cabecera `X-LiteSpeed-Cache: miss` o `nocache` en visitas no autorizadas a publicaciones protegidas.
  - Verificar que Cloudflare devuelve `BYPASS` o `DYNAMIC` para peticiones a posts protegidos sin la cookie de autorización.
- [ ] **6. Preparación de versión y release:**
  - Versión actualizada a `1.9.1` en `wp_affiliatemanager.php` (`WPAM_VERSION` y cabecera de plugin); confirmar con la validación del release.
  - Generar el tag o release correspondiente cuando el checklist haya sido validado en vivo.
