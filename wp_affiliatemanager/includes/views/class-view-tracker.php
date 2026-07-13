<?php
/**
 * View Tracker — registra una vista en la tabla SQL {prefix}wpam_views.
 *
 * Responsabilidad única: escribir. No conoce elegibilidad, cookies, AJAX ni
 * nonces — eso vive en Views (class-views.php). Este tracker solo hace el
 * upsert atómico contra la tabla.
 *
 * @package WP_AffiliateManager\Views
 * @since   1.2.0
 */

namespace WP_AffiliateManager\Views;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class View_Tracker
 *
 * @since 1.2.0
 */
class View_Tracker {

	/**
	 * Registra una vista para el post_id + período (día) actual.
	 *
	 * Usa INSERT ... ON DUPLICATE KEY UPDATE sobre la UNIQUE KEY
	 * (post_id, period) de Views_Table: una única query, atómica, sin SELECT
	 * previo y sin condición de carrera entre lecturas y escrituras.
	 *
	 * @since  1.2.0
	 *
	 * @param  int $post_id ID del post visitado.
	 * @return bool True si la query se ejecutó sin error.
	 */
	public function record( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		global $wpdb;

		$period = gmdate( 'Ymd' );
		$table  = Views_Table::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (post_id, period, count)
				 VALUES (%d, %s, 1)
				 ON DUPLICATE KEY UPDATE count = count + 1',
				$table,
				$post_id,
				$period
			)
		);

		return false !== $result;
	}
}
