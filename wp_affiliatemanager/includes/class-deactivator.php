<?php
/**
 * Clase de desactivación del plugin.
 *
 * Se ejecuta cuando el usuario desactiva el plugin desde el panel de WordPress.
 * NO elimina datos (eso es responsabilidad de uninstall.php).
 *
 * @package WP_AffiliateManager
 * @since   1.0.0
 */

namespace WP_AffiliateManager;

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deactivator
 *
 * Responsabilidades (solo al desactivar, NO al desinstalar):
 * - Limpiar tareas programadas (cron jobs).
 * - Limpiar transients del plugin.
 * - Limpiar rewrite rules.
 * - NO eliminar datos de usuario (eso va en uninstall.php).
 *
 * @since 1.0.0
 */
class Deactivator {

	/**
	 * Método principal de desactivación.
	 *
	 * WordPress lo invoca automáticamente vía register_deactivation_hook().
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function deactivate(): void {
		// Limpiar cron jobs del plugin.
		self::clear_scheduled_events();

		// Limpiar transients del plugin.
		self::clear_transients();

		// Limpiar rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Elimina los eventos programados (cron) del plugin.
	 * PLACEHOLDER — descomentar cuando se registren cron jobs en fases posteriores.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function clear_scheduled_events(): void {
		// Ejemplo de limpieza de cron:
		// $timestamp = wp_next_scheduled( 'wpam_daily_cleanup' );
		// if ( $timestamp ) {
		// 	wp_unschedule_event( $timestamp, 'wpam_daily_cleanup' );
		// }

		// Por ahora no hay cron jobs registrados.
		// Este método queda preparado para FASE de estadísticas/automatizaciones.
	}

	/**
	 * Elimina los transients del plugin de la base de datos.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private static function clear_transients(): void {
		global $wpdb;

		// Eliminar todos los transients con prefijo wpam_.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_wpam_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_wpam_' ) . '%'
			)
		);
	}
}
