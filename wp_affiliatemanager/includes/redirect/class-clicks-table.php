<?php
/**
 * Clicks Table — gestión de la tabla SQL de tracking de clicks.
 *
 * Responsabilidades:
 *  - Crear/actualizar la tabla {prefix}wpam_clicks via dbDelta().
 *
 * Estructura de la tabla:
 *   id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   ts              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   post_id         BIGINT UNSIGNED NOT NULL
 *   affiliate_id    BIGINT UNSIGNED NOT NULL
 *   destination_url TEXT NOT NULL
 *   referer         TEXT NULL
 *   ip_hash         CHAR(64) NULL   (HMAC-SHA256 de la IP, nunca la IP real)
 *   user_agent      TEXT NULL
 *
 * @package WP_AffiliateManager\Redirect
 * @since   0.2.1
 * @version 0.2.2
 */

namespace WP_AffiliateManager\Redirect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Clicks_Table
 *
 * @since 0.2.1
 */
class Clicks_Table {

	// -------------------------------------------------------------------------
	// Nombre de tabla
	// -------------------------------------------------------------------------

	/**
	 * Retorna el nombre completo de la tabla (con prefijo de WordPress).
	 *
	 * @since  0.2.1
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpam_clicks';
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
	 * ts usa DEFAULT CURRENT_TIMESTAMP para evitar dependencia de PHP timezones.
	 * destination_url es TEXT (no VARCHAR) para soportar URLs largas con tracking params.
	 * ip_hash es CHAR(64): longitud exacta de SHA-256 en hex.
	 *
	 * @since  0.2.1
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
			ts              DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			post_id         BIGINT UNSIGNED     NOT NULL,
			affiliate_id    BIGINT UNSIGNED     NOT NULL,
			destination_url TEXT                NOT NULL,
			referer         TEXT                NULL,
			ip_hash         CHAR(64)            NULL,
			user_agent      TEXT                NULL,
			PRIMARY KEY (id),
			KEY affiliate_id (affiliate_id),
			KEY post_id (post_id),
			KEY ts (ts)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
