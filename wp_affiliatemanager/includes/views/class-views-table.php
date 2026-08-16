<?php
/**
 * Views Table — gestión de las tablas SQL de conteo diario de vistas.
 *
 * Responsabilidades:
 *  - Crear/actualizar la tabla {prefix}wpam_views via dbDelta().
 *  - Crear/actualizar las 2 tablas auxiliares de contexto agregado
 *    (search terms / 404 URLs) — ver nota de resource_id abajo.
 *  - Migrar el esquema legacy (pre-v1.8.0) eliminando el índice
 *    UNIQUE KEY `post_period` que dbDelta() nunca borra por sí solo.
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
 * La UNIQUE KEY (resource_type, post_id, period) — nombrada `resource_period`
 * — es lo que permite el upsert atómico en View_Tracker::record() (INSERT
 * ... ON DUPLICATE KEY UPDATE) sin necesidad de un SELECT previo, ahora
 * distinguiendo también por tipo de recurso. Es la ÚNICA combinación
 * índice-único que debe existir en esta tabla: permite que coexistan en el
 * mismo `period` un `post_id`, un `term_id` de categoría y un `term_id` de
 * tag numéricamente iguales (ej. post:123, category:123, tag:123, home:0,
 * search:0, 404:0, todos el mismo día) sin colisionar entre sí.
 *
 * MIGRACIÓN DE ESQUEMA (v1.8.0 — fix de índice legacy): dbDelta() añade la
 * columna resource_type y el nuevo índice `resource_period` de forma no
 * destructiva, pero **nunca elimina** el índice legacy `post_period`
 * (UNIQUE KEY sobre post_id+period a secas) que existía antes de v1.8.0 —
 * dbDelta() solo agrega, jamás borra. Si `post_period` sobrevive, sigue
 * activo como restricción UNIQUE independiente y bloquea exactamente el
 * caso que resource_type fue diseñado para permitir: un post_id=123 y un
 * term_id=123 el mismo día violarían igual `post_period`, aunque
 * `resource_period` los distinga correctamente. self::migrate_legacy_schema()
 * es el punto de entrada correcto para sitios que actualizan desde una
 * versión anterior: elimina `post_period` explícitamente ANTES de
 * dbDelta(), y verifica el resultado final. Las filas existentes (100%
 * Posts hasta v1.8.0) se reclasifican solas como resource_type='post' por
 * el DEFAULT de columna — no requieren ningún UPDATE manual.
 *
 * @package WP_AffiliateManager\Views
 * @since   1.2.0
 * @since   1.8.0 Añadida resource_type + tablas auxiliares de search/404.
 * @since   1.8.0 migrate_legacy_schema() — fix del índice legacy post_period
 *               que dbDelta() nunca eliminaba por sí solo.
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

	/**
	 * Nombre del índice UNIQUE legacy (pre-v1.8.0), sobre (post_id, period).
	 * dbDelta() nunca lo elimina por sí solo — debe borrarse explícitamente.
	 *
	 * @since 1.8.0
	 */
	const LEGACY_UNIQUE_INDEX = 'post_period';

	/**
	 * Nombre del índice UNIQUE actual (v1.8.0+), sobre (resource_type, post_id, period).
	 *
	 * @since 1.8.0
	 */
	const CURRENT_UNIQUE_INDEX = 'resource_period';

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

	// -------------------------------------------------------------------------
	// Migración de esquema v1.8.0 — fix del índice legacy `post_period`
	// -------------------------------------------------------------------------

	/**
	 * Punto de entrada de la migración de esquema a v1.8.0.
	 *
	 * Orden estricto (importante): el índice legacy se elimina ANTES de
	 * dbDelta(), no después — dbDelta() nunca borra índices por sí solo, así
	 * que si se hiciera al revés el índice legacy seguiría activo (y
	 * bloqueando inserts legítimos de resource_type distinto de 'post') entre
	 * el momento de crear/actualizar la tabla y el momento de limpiarlo.
	 *
	 * No es un framework de migraciones: es el paso concreto y único que
	 * necesita esta versión. Llamado por Plugin::maybe_upgrade_views_schema()
	 * (sitios que actualizan) y por Activator::activate() (sitios nuevos, donde
	 * es un no-op seguro porque el índice legacy nunca existió).
	 *
	 * @since  1.8.0
	 * @return bool True si al finalizar el esquema quedó exactamente como se
	 *              espera (ver schema_is_correct()). False si algo falló — en
	 *              ese caso el llamador NO debe marcar la migración como
	 *              completada, para que se reintente en la siguiente carga.
	 */
	public static function migrate_legacy_schema(): bool {
		self::drop_legacy_unique_index();
		self::create_table();

		return self::schema_is_correct();
	}

	/**
	 * Elimina el índice UNIQUE legacy `post_period` si existe. No-op seguro en
	 * instalaciones nuevas (donde ese índice nunca existió) y en sitios que ya
	 * fueron migrados (donde ya no existe).
	 *
	 * Detecta la existencia consultando information_schema.STATISTICS en vez de
	 * intentar el DROP a ciegas — un DROP INDEX contra un índice inexistente
	 * produce un error de SQL que quedaría registrado en $wpdb->last_error
	 * innecesariamente en el caso (mayoritario, tras la primera migración
	 * exitosa) de que ya no exista.
	 *
	 * @since  1.8.0
	 * @return void
	 */
	private static function drop_legacy_unique_index(): void {
		global $wpdb;

		if ( ! self::index_exists( self::table_name(), self::LEGACY_UNIQUE_INDEX ) ) {
			return;
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, self::LEGACY_UNIQUE_INDEX ) );
	}

	/**
	 * Verifica que el esquema final de wpam_views sea exactamente el esperado:
	 * columna resource_type presente, índice resource_period presente, índice
	 * legacy post_period ausente.
	 *
	 * Llamado al final de migrate_legacy_schema() para decidir si la migración
	 * puede marcarse como completada. No modifica nada, solo lee.
	 *
	 * @since  1.8.0
	 * @return bool
	 */
	public static function schema_is_correct(): bool {
		$table = self::table_name();

		if ( ! self::column_exists( $table, 'resource_type' ) ) {
			return false;
		}

		if ( ! self::column_default_is( $table, 'resource_type', 'post' ) ) {
			return false;
		}

		if ( ! self::index_exists( $table, self::CURRENT_UNIQUE_INDEX ) ) {
			return false;
		}

		if ( self::index_exists( $table, self::LEGACY_UNIQUE_INDEX ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Determina si una columna existe en una tabla, consultando
	 * information_schema.COLUMNS (no falla si la tabla no existe: devuelve false).
	 *
	 * @since  1.8.0
	 * @param  string $table  Nombre completo de tabla (con prefijo).
	 * @param  string $column
	 * @return bool
	 */
	private static function column_exists( string $table, string $column ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		);

		return (int) $found > 0;
	}

	/**
	 * Determina si el DEFAULT de una columna coincide con el valor esperado,
	 * consultando information_schema.COLUMNS.COLUMN_DEFAULT. Usado para
	 * verificar específicamente que `resource_type` quede con `DEFAULT 'post'`
	 * tras la migración — es lo que garantiza que las filas preexistentes
	 * (100% Posts antes de v1.8.0) se reclasifiquen solas sin ningún UPDATE.
	 *
	 * @since  1.8.0
	 * @param  string $table
	 * @param  string $column
	 * @param  string $expected_default
	 * @return bool
	 */
	private static function column_default_is( string $table, string $column, string $expected_default ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$default = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		);

		return $expected_default === $default;
	}

	/**
	 * Determina si un índice existe en una tabla, consultando
	 * information_schema.STATISTICS (no falla si la tabla no existe: devuelve false).
	 *
	 * @since  1.8.0
	 * @param  string $table Nombre completo de tabla (con prefijo).
	 * @param  string $index_name
	 * @return bool
	 */
	private static function index_exists( string $table, string $index_name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
				$table,
				$index_name
			)
		);

		return (int) $found > 0;
	}
}
