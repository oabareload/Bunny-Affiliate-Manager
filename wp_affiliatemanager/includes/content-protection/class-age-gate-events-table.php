<?php
/**
 * Age Gate Events Table — gestión de la tabla SQL de conteo diario de eventos del Age Gate.
 *
 * Responsabilidades:
 *  - Crear/actualizar la tabla {prefix}wpam_age_gate_events via dbDelta().
 *
 * Estructura de la tabla:
 *   id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   event_type VARCHAR(20)     NOT NULL  -- 'shown', 'confirmed', 'rejected'
 *   period     CHAR(8)         NOT NULL  -- YYYYMMDD (fecha en timezone de WP)
 *   post_id    BIGINT UNSIGNED NOT NULL DEFAULT 0
 *   utm_source VARCHAR(50)     NOT NULL DEFAULT 'direct'
 *   count      INT UNSIGNED    NOT NULL DEFAULT 1
 *
 * La UNIQUE KEY (event_type, period, post_id, utm_source) permite el upsert atómico
 * (INSERT ... ON DUPLICATE KEY UPDATE count = count + 1) evitando condiciones de carrera
 * y agregando de forma limpia por día, tipo de evento, publicación y origen UTM.
 *
 * @package WP_AffiliateManager\Content_Protection
 * @since   2.2.0
 */

namespace WP_AffiliateManager\Content_Protection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Age_Gate_Events_Table
 *
 * @since 2.2.0
 */
class Age_Gate_Events_Table {

	/**
	 * Nombre de la opción de WordPress que almacena la versión del esquema instalada.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION_OPTION = 'wpam_age_gate_events_schema_version';

	/**
	 * Versión objetivo del esquema de la tabla de eventos del Age Gate.
	 *
	 * @var string
	 */
	const SCHEMA_TARGET_VERSION = '2.2.0';

	/**
	 * Retorna el nombre completo de la tabla (con prefijo de WordPress).
	 *
	 * @since  2.2.0
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpam_age_gate_events';
	}

	/**
	 * Crea o actualiza la tabla usando dbDelta().
	 *
	 * dbDelta() es idempotente: si la tabla ya existe y la estructura coincide,
	 * no hace nada. Si hay columnas o índices nuevos, los añade.
	 *
	 * @since  2.2.0
	 * @return bool True si la tabla existe y el esquema verifica correctamente.
	 */
	public static function create_table(): bool {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta requiere sintaxis estricta: tipos en mayúsculas, PRIMARY KEY con 2 espacios, etc.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(20) NOT NULL,
			period char(8) NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			utm_source varchar(50) NOT NULL DEFAULT 'direct',
			count int(10) unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY event_period_post_utm (event_type, period, post_id, utm_source),
			KEY period (period),
			KEY post_id (post_id),
			KEY event_type (event_type)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return self::schema_is_correct();
	}

	/**
	 * Verifica que la tabla exista y tenga el índice UNIQUE esperado.
	 *
	 * @since  2.2.0
	 * @return bool
	 */
	public static function schema_is_correct(): bool {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);

		if ( (int) $table_exists === 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$index_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
				$table,
				'event_period_post_utm'
			)
		);

		return (int) $index_exists > 0;
	}
}
