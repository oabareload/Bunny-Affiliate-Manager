<?php
/**
 * WPAM_API — API interna para exponer Top Posts como WP_Post[].
 *
 * Pensada para consumo por otros plugins (ej. Bunny Magazine).
 * No ejecuta SQL propio. No genera HTML. No duplica lógica.
 * Reutiliza Top_Posts_Query como fuente única de datos y caché.
 *
 * v1.1.0: Añadido soporte de filtros (categories, tags, authors, post_type).
 * Los filtros se pasan directamente a Top_Posts_Query::get_cached()
 * como tercer parámetro $filters — sin lógica adicional en esta clase.
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
	 * 'day' se mapea a 'today' internamente (nombre del sistema interno).
	 */
	const ALLOWED_PERIODS = array( 'day', 'week', 'month', 'total' );

	/**
	 * Obtiene Top Posts como WP_Post[] enriquecidos.
	 *
	 * @param array $args {
	 *   @type string $period              day|week|month|total. Default 'total'.
	 *   @type int    $limit               1–50. Default 10.
	 *   @type string $post_type           Default 'any'.
	 *   @type int[]  $categories_include  IDs de categorías requeridas. Vacío = sin filtro.
	 *   @type int[]  $categories_exclude  IDs de categorías excluidas. Vacío = sin filtro.
	 *   @type int[]  $tags_include        IDs de tags requeridos. Vacío = sin filtro.
	 *   @type int[]  $tags_exclude        IDs de tags excluidos. Vacío = sin filtro.
	 *   @type int[]  $authors_include     IDs de autores requeridos. Vacío = sin filtro.
	 *   @type int[]  $authors_exclude     IDs de autores excluidos. Vacío = sin filtro.
	 * }
	 * @return \WP_Post[]
	 */
	public static function get_top_posts( array $args = array() ): array {

		$args = wp_parse_args( $args, array(
			'period'              => 'total',
			'limit'               => 10,
			'post_type'           => 'any',
			'categories_include'  => array(),
			'categories_exclude'  => array(),
			'tags_include'        => array(),
			'tags_exclude'        => array(),
			'authors_include'     => array(),
			'authors_exclude'     => array(),
		) );

		// -------------------------
		// VALIDACIÓN DE INPUTS
		// -------------------------
		$period = in_array( $args['period'], self::ALLOWED_PERIODS, true )
			? $args['period']
			: 'total';

		$limit = max( 1, min( 50, (int) $args['limit'] ) );

		// Mapeo API público → nombre interno de Top_Posts_Query.
		$range = ( 'day' === $period ) ? 'today' : $period;

		// Construir $filters para Top_Posts_Query.
		// Solo se incluyen las claves con valor no vacío para mantener
		// la clave de caché lo más compacta posible.
		$filters = array();

		if ( ! empty( $args['post_type'] ) && 'any' !== $args['post_type'] ) {
			$filters['post_type'] = (string) $args['post_type'];
		}

		$filter_keys = array(
			'categories_include',
			'categories_exclude',
			'tags_include',
			'tags_exclude',
			'authors_include',
			'authors_exclude',
		);

		foreach ( $filter_keys as $key ) {
			if ( ! empty( $args[ $key ] ) && is_array( $args[ $key ] ) ) {
				$filters[ $key ] = array_map( 'intval', $args[ $key ] );
			}
		}

		// -------------------------
		// DATA SOURCE (sin SQL nuevo)
		// -------------------------
		$rows = Top_Posts_Query::get_cached( $range, $limit, $filters );

		if ( empty( $rows ) ) {
			return array();
		}

		// -------------------------
		// NORMALIZACIÓN A WP_POST
		// -------------------------
		$posts = array();

		foreach ( $rows as $row ) {
			$post_id = (int) $row['id'];

			if ( $post_id <= 0 ) {
				continue;
			}

			$post = get_post( $post_id );

			if ( ! ( $post instanceof \WP_Post ) ) {
				continue;
			}

			// Enriquecimiento seguro — propiedades dinámicas, no se alteran
			// campos nativos del objeto WP_Post.
			$post->wpam_click_count = (int) $row['click_count'];
			$post->wpam_thumbnail   = get_the_post_thumbnail_url( $post_id, 'medium' );

			$posts[] = $post;
		}

		// -------------------------
		// FILTER HOOK
		// -------------------------
		return apply_filters( 'wpam_api_top_posts', $posts, $args );
	}

	/**
	 * Helper de disponibilidad del API.
	 */
	public static function is_available(): bool {
		return class_exists( self::class );
	}
}
