<?php
/**
 * Views Table — gestión de la tabla SQL de conteo diario de vistas.
 *
 * Responsabilidades:
 *  - Crear/actualizar la tabla {prefix}wpam_views via dbDelta().
 *
 * Estructura de la tabla:
 *   id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   post_id  BIGINT UNSIGNED NOT NULL
 *   period   CHAR(8)         NOT NULL   -- YYYYMMDD (histórico diario, no acumulado)
 *   count    INT UNSIGNED    NOT NULL DEFAULT 1
 *
 * La UNIQUE KEY (post_id, period) es lo que permite el upsert atómico en
 * View_Tracker::record() (INSERT ... ON DUPLICATE KEY UPDATE) sin necesidad
 * de un SELECT previo. También deja la tabla lista para que Top Posts /
 * Analytics puedan sumar por rango de fechas sin rediseñar el esquema.
 *
 * @package WP_AffiliateManager\Views
 * @since   1.2.0
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
	// Nombre de tabla
	// -------------------------------------------------------------------------

	/**
	 * Retorna el nombre completo de la tabla (con prefijo de WordPress).
	 *
	 * @since  1.2.0
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpam_views';
	}

	// -------------------------------------------------------------------------
	// Creación / actualización de tabla
	// -------------------------------------------------------------------------

	/**
	 * Crea o actualiza la tabla usando dbDelta().
	 *
	 * dbDelta() es idempotente: si la tabla ya existe y la estructura coincide,
	 * no hace nada. Si hay columnas nuevas, las añade. Nunca elimina columnas.
	 *
	 * period es CHAR(8) (YYYYMMDD) en vez de DATE para permitir comparaciones
	 * e índices simples sin conversión de tipo, y para que la generación del
	 * valor en PHP (gmdate('Ymd')) sea trivial y libre de timezone del servidor.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			period  CHAR(8)         NOT NULL,
			count   INT UNSIGNED    NOT NULL DEFAULT 1,
			PRIMARY KEY (id),
			UNIQUE KEY post_period (post_id, period),
			KEY period (period)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
