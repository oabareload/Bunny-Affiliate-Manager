<?php
/**
 * WPAM_API — API interna para exponer Top Posts como WP_Post[].
 *
 * Pensada para consumo por otros plugins (ej. Bunny Magazine).
 * No ejecuta SQL propio. No genera HTML. No duplica lógica.
 * Reutiliza Top_Posts_Query como fuente única de datos.
 *
 * @package WP_AffiliateManager\API
 * @since   1.1.0
 */

namespace WP_AffiliateManager\API;

use WP_AffiliateManager\Frontend\Top_Posts_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAM_API {

	/**
	 * Periodos válidos del API público.
	 */
	const ALLOWED_PERIODS = array( 'day', 'week', 'month', 'total' );

	/**
	 * Obtiene Top Posts como WP_Post[] enriquecidos.
	 *
	 * @param array $args {
	 *   @type string $period    day|week|month|total
	 *   @type int    $limit     1-50
	 *   @type string $post_type post|page|any
	 * }
	 *
	 * @return \WP_Post[]
	 */
	public static function get_top_posts( array $args = array() ): array {

		$args = wp_parse_args( $args, array(
			'period'    => 'total',
			'limit'     => 10,
			'post_type' => 'post',
		) );

		// -------------------------
		// VALIDACIÓN DE INPUTS
		// -------------------------
		$period = in_array( $args['period'], self::ALLOWED_PERIODS, true )
			? $args['period']
			: 'total';

		$limit = max( 1, min( 50, (int) $args['limit'] ) );

		$post_type = (string) $args['post_type'];

		// Mapeo API → sistema interno
		$range = ( 'day' === $period ) ? 'today' : $period;

		// -------------------------
		// DATA SOURCE (sin SQL nuevo)
		// -------------------------
		$rows = Top_Posts_Query::get_cached( $range, $limit );

		if ( empty( $rows ) ) {
			return array();
		}

		$posts = array();

		// -------------------------
		// NORMALIZACIÓN
		// -------------------------
		foreach ( $rows as $row ) {

			$post_id = (int) ( $row['post_id'] ?? 0 );

			if ( $post_id <= 0 ) {
				continue;
			}

			$post = get_post( $post_id );

			if ( ! ( $post instanceof \WP_Post ) ) {
				continue;
			}

			if ( 'any' !== $post_type && $post->post_type !== $post_type ) {
				continue;
			}

			// Enriquecimiento seguro
			$post->wpam_click_count = (int) ( $row['clicks'] ?? 0 );
			$post->wpam_thumbnail   = get_the_post_thumbnail_url( $post_id, 'medium' );

			$posts[] = $post;
		}

		// -------------------------
		// FILTER HOOK (IMPORTANTE)
		// -------------------------
		return apply_filters(
			'wpam_api_top_posts',
			$posts,
			$args
		);
	}

	/**
	 * Helper de disponibilidad del API.
	 */
	public static function is_available(): bool {
		return class_exists( self::class );
	}
}