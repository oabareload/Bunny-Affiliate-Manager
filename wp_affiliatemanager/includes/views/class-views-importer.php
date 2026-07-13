<?php
/**
 * Views Importer — migración única desde Post Views Counter.
 *
 * Lee {$wpdb->prefix}post_views (type = 0, histórico diario) y hace merge
 * aditivo hacia wpam_views. Es una migración de una sola vez: una vez
 * ejecutada con éxito, se marca con la opción self::COMPLETED_OPTION y no
 * vuelve a ejecutarse (ni se muestra el botón) para evitar duplicar counts
 * en corridas accidentales.
 *
 * Nunca escribe en la tabla origen.
 *
 * @package WP_AffiliateManager\Views
 * @since   1.2.0
 */

namespace WP_AffiliateManager\Views;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Views_Importer
 *
 * @since 1.2.0
 */
class Views_Importer {

	/**
	 * Opción que marca la migración como completada.
	 *
	 * @since1.2.0
	 */
	const COMPLETED_OPTION = 'wpam_post_views_import_completed';

	/**
	 * Tamaño de lote para leer la tabla origen.
	 *
	 * @since1.2.0
	 */
	const BATCH_SIZE = 500;

	// -------------------------------------------------------------------------
	// Detección de disponibilidad
	// -------------------------------------------------------------------------

	/**
	 * Nombre de la tabla origen (Post Views Counter).
	 *
	 * @since 1.2.0
	 * @return string
	 */
	private static function source_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'post_views';
	}

	/**
	 * Verifica que la tabla origen exista, sin usar SHOW TABLES: intenta una
	 * lectura directa y comprueba $wpdb->last_error.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	public static function source_table_exists(): bool {
		global $wpdb;
		$table = self::source_table_name();

		$was_suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) );
		$exists = '' === $wpdb->last_error;
		$wpdb->suppress_errors( $was_suppressed );

		return $exists;
	}

	/**
	 * Determina si la migración ya se ejecutó con éxito.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	public static function is_completed(): bool {
		return (bool) get_option( self::COMPLETED_OPTION, false );
	}

	/**
	 * Determina si el botón de importación debe mostrarse: tabla origen
	 * disponible y migración aún no ejecutada.
	 *
	 * @since 1.2.0
	 * @return bool
	 */
	public static function can_run(): bool {
		return self::source_table_exists() && ! self::is_completed();
	}

	// -------------------------------------------------------------------------
	// Migración
	// -------------------------------------------------------------------------

	/**
	 * Ejecuta la migración una sola vez.
	 *
	 * Merge aditivo: INSERT si el par (post_id, period) no existe, SUMA si ya
	 * existe (no es idempotente a propósito — está pensada para correr una
	 * única vez, por eso queda bloqueada por self::COMPLETED_OPTION).
	 *
	 * @since 1.2.0
	 * @return array{ imported: int, updated: int, omitted: int, elapsed_seconds: float }
	 */
	public static function run(): array {
		$start = microtime( true );
		$stats = array(
			'imported' => 0,
			'updated'  => 0,
			'omitted'  => 0,
		);

		if ( ! self::can_run() ) {
			$stats['elapsed_seconds'] = round( microtime( true ) - $start, 3 );
			return $stats;
		}

		global $wpdb;
		$source = self::source_table_name();
		$target = Views_Table::table_name();

		$post_exists_cache = array();
		$offset            = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, period, count FROM %i WHERE type = 0 ORDER BY id, period LIMIT %d OFFSET %d',
					$source,
					self::BATCH_SIZE,
					$offset
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$post_id = absint( $row['id'] );
				$period  = sanitize_text_field( $row['period'] );
				$count   = absint( $row['count'] );

				if ( $post_id <= 0 || ! preg_match( '/^\d{8}$/', $period ) || $count <= 0 ) {
					$stats['omitted']++;
					continue;
				}

				if ( ! array_key_exists( $post_id, $post_exists_cache ) ) {
					$post_exists_cache[ $post_id ] = ( null !== get_post( $post_id ) );
				}

				if ( ! $post_exists_cache[ $post_id ] ) {
					$stats['omitted']++;
					continue;
				}

				// Merge aditivo: insertar si no existe, sumar si ya existe.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (post_id, period, count)
						 VALUES (%d, %s, %d)
						 ON DUPLICATE KEY UPDATE count = count + VALUES(count)',
						$target,
						$post_id,
						$period,
						$count
					)
				);

				switch ( (int) $wpdb->rows_affected ) {
					case 1:
						$stats['imported']++;
						break;
					case 2:
						$stats['updated']++;
						break;
					default:
						$stats['omitted']++;
				}
			}

			$offset += self::BATCH_SIZE;
		} while ( count( $rows ) === self::BATCH_SIZE );

		// Bloquear la migración: no debe volver a correr accidentalmente.
		update_option( self::COMPLETED_OPTION, true, false );

		// Invalidar caché de lecturas (Views_Query) tras el bulk import.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'wpam' );
		}

		$stats['elapsed_seconds'] = round( microtime( true ) - $start, 3 );

		return $stats;
	}
}
