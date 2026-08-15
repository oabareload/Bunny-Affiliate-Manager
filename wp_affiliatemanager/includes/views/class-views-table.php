<?php
/**
 * Views Table — gestión de las tablas SQL de conteo diario de vistas.
 *
 * Responsabilidades:
 *  - Crear/actualizar la tabla {prefix}wpam_views via dbDelta().
 *  - Crear/actualizar las 2 tablas auxiliares de contexto agregado
 *    (search terms / 404 URLs) — ver nota de resource_id abajo.
 *
 * Estructura de la tabla principal:
 *   id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   post_id       BIGINT UNSIGNED NOT NULL  -- conceptualmente "resource_id" (ver nota)
 *   period        CHAR(8)         NOT NULL   -- YYYYMMDD (histórico diario, no acumulado)
 *   count         INT UNSIGNED    NOT NULL DEFAULT 1
 *   resource_type VARCHAR(20)     NOT NULL DEFAULT 'post'
 *
 * NOTA (v1.8.0 — generalización a resource_type/resource_id): la columna
 * física sigue llamándose `post_id` para no forzar una migración de todo el
 * código existente (Views_Query, Score_Query, Views_Importer, etc.), pero
 * conceptualmente pasa a representar un `resource_id` genérico: para
 * resource_type='post' es el post_id de siempre; para 'page' es el page_id;
 * para 'category'/'tag' es el term_id; para 'home'/'search'/'404' vale
 * siempre 0 (no tienen identidad propia). NO renombrar esta columna.
 *
 * La UNIQUE KEY (resource_type, post_id, period) es lo que permite el upsert
 * atómico en View_Tracker::record() (INSERT ... ON DUPLICATE KEY UPDATE) sin
 * necesidad de un SELECT previo, ahora distinguiendo también por tipo de
 * recurso (evita colisionar, por ejemplo, un post_id=5 con un term_id=5).
 *
 * MIGRACIÓN (v1.8.0): dbDelta() añade la columna resource_type con
 * DEFAULT 'post' de forma no destructiva — todas las filas ya existentes
 * (100% Posts hasta esta versión) quedan reclasificadas automáticamente
 * como resource_type='post' sin necesidad de ningún UPDATE manual. Es una
 * migración de una sola vez, resuelta enteramente por el propio dbDelta().
 *
 * @package WP_AffiliateManager\Views
 * @since   1.2.0
 * @since   1.8.0 Añadida resource_type + tablas auxiliares de search/404.
 */

namespace WP_AffiliateManager\Views;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Views_Table
 *
 * @since 1.2.0
 */
class Views_Table {

	// -------------------------------------------------------------------------
	// Nombres de tabla
	// -------------------------------------------------------------------------

	/**
	 * Retorna el nombre completo de la tabla principal (con prefijo de WordPress).
	 *
	 * @since  1.2.0
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpam_views';
	}

	/**
	 * Retorna el nombre completo de la tabla auxiliar de términos de búsqueda.
	 *
	 * @since  1.8.0
	 * @return string
	 */
	public static function search_terms_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpam_views_search_terms';
	}

	/**
	 * Retorna el nombre completo de la tabla auxiliar de URLs 404.
	 *
	 * @since  1.8.0
	 * @return string
	 */
	public static function table_404_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpam_views_404';
	}

	// -------------------------------------------------------------------------
	// Creación / actualización de tablas
	// -------------------------------------------------------------------------

	/**
	 * Crea o actualiza las 3 tablas del módulo Views usando dbDelta().
	 *
	 * dbDelta() es idempotente: si una tabla ya existe y la estructura
	 * coincide, no hace nada. Si hay columnas nuevas, las añade. Nunca
	 * elimina columnas ni filas existentes.
	 *
	 * period es CHAR(8) (YYYYMMDD) en vez de DATE para permitir comparaciones
	 * e índices simples sin conversión de tipo, y para que la generación del
	 * valor en PHP (gmdate('Ymd')) sea trivial y libre de timezone del servidor.
	 *
	 * @since  1.2.0
	 * @since  1.8.0 Añade resource_type a la tabla principal y crea las 2
	 *               tablas auxiliares. Se agrupan aquí (un solo punto de
	 *               entrada) porque Activator y el chequeo de upgrade en
	 *               Plugin ya solo conocen esta llamada.
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		// -----------------------------------------------------------------
		// Tabla principal — wpam_views.
		// -----------------------------------------------------------------
		$table = self::table_name();
		$sql   = "CREATE TABLE {$table} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id       BIGINT UNSIGNED NOT NULL,
			period        CHAR(8)         NOT NULL,
			count         INT UNSIGNED    NOT NULL DEFAULT 1,
			resource_type VARCHAR(20)     NOT NULL DEFAULT 'post',
			PRIMARY KEY (id),
			UNIQUE KEY resource_period (resource_type, post_id, period),
			KEY period (period)
		) {$charset_collate};";

		dbDelta( $sql );

		// -----------------------------------------------------------------
		// Auxiliar — términos de búsqueda (agregado diario, no por visita).
		// -----------------------------------------------------------------
		$search_table = self::search_terms_table_name();
		$sql          = "CREATE TABLE {$search_table} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			term_normalized VARCHAR(100)    NOT NULL,
			period          CHAR(8)         NOT NULL,
			count           INT UNSIGNED    NOT NULL DEFAULT 1,
			PRIMARY KEY (id),
			UNIQUE KEY term_period (term_normalized, period),
			KEY period (period)
		) {$charset_collate};";

		dbDelta( $sql );

		// -----------------------------------------------------------------
		// Auxiliar — URLs de 404 (agregado diario, no por visita).
		// -----------------------------------------------------------------
		$table_404 = self::table_404_name();
		$sql       = "CREATE TABLE {$table_404} (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url_normalized VARCHAR(255)    NOT NULL,
			period         CHAR(8)         NOT NULL,
			count          INT UNSIGNED    NOT NULL DEFAULT 1,
			PRIMARY KEY (id),
			UNIQUE KEY url_period (url_normalized(191), period),
			KEY period (period)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
