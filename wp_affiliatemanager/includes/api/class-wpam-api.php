<?php
/**
 * WPAM_API — API interna para exponer Top Posts / Top Viewed Posts como WP_Post[].
 *
 * Pensada para consumo por otros plugins (ej. Bunny Magazine).
 * No ejecuta SQL propio. No genera HTML. No duplica lógica.
 * Reutiliza Top_Posts_Query / Views_Query como fuente única de datos y caché.
 *
 * v1.1.0: Añadido soporte de filtros (categories, tags, authors, post_type).
 * Los filtros se pasan directamente a *_Query::get_cached()
 * como tercer parámetro $filters — sin lógica adicional en esta clase.
 *
 * v1.2.0: get_top_posts() y get_top_viewed_posts() comparten toda su lógica
 * (validación, construcción de $filters, normalización a WP_Post) vía
 * build_top_posts_response(). La única diferencia real entre ambos métodos
 * públicos es la fuente de datos (Top_Posts_Query::get_cached() vs
 * Views_Query::get_cached()) y qué campo del row / propiedad del post se usa
 * para el conteo (click_count/wpam_click_count vs view_count/wpam_view_count).
 *
 * @package WP_AffiliateManager\API
 * @since   1.1.0
 * @since   1.2.0 get_top_viewed_posts() añadido, comparte lógica con get_top_posts().
 */

namespace WP_AffiliateManager\API;

use WP_AffiliateManager\Frontend\Top_Posts_Query;
use WP_AffiliateManager\Views\Views_Query;

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
	 * Obtiene Top Posts (por clicks) como WP_Post[] enriquecidos.
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
		return self::build_top_posts_response(
			$args,
			array( Top_Posts_Query::class, 'get_cached' ),
			'click_count',
			'wpam_click_count',
			'wpam_api_top_posts'
		);
	}

	/**
	 * Obtiene Top Viewed Posts (por vistas) como WP_Post[] enriquecidos.
	 *
	 * Espejo exacto de get_top_posts(): mismos argumentos, misma validación,
	 * mismo $filters, misma normalización. Solo cambia la fuente de datos
	 * (Views_Query::get_cached() en vez de Top_Posts_Query::get_cached()) y el
	 * campo de conteo (view_count / wpam_view_count en vez de click_count /
	 * wpam_click_count).
	 *
	 * @since  1.2.0
	 * @param  array $args Ver get_top_posts() para la estructura completa.
	 * @return \WP_Post[]
	 */
	public static function get_top_viewed_posts( array $args = array() ): array {
		return self::build_top_posts_response(
			$args,
			array( Views_Query::class, 'get_cached' ),
			'view_count',
			'wpam_view_count',
			'wpam_api_top_viewed_posts'
		);
	}

	/**
	 * Lógica compartida entre get_top_posts() y get_top_viewed_posts().
	 *
	 * Cuerpo idéntico al que tenía get_top_posts() antes de v1.4.0, parametrizado
	 * únicamente en la fuente de datos y el nombre de campo de conteo. Validación,
	 * construcción de $filters y normalización a WP_Post no cambian entre proveedores.
	 *
	 * @since  1.2.0
	 *
	 * @param  array    $args           Argumentos públicos (ver get_top_posts()).
	 * @param  callable $query_callback Callable con la firma de *_Query::get_cached(
	 *                                  string $range, int $limit, array $filters ): array[].
	 * @param  string   $count_field    Clave del row devuelto por $query_callback ('click_count' | 'view_count').
	 * @param  string   $count_property Propiedad dinámica a asignar en el WP_Post ('wpam_click_count' | 'wpam_view_count').
	 * @param  string   $filter_hook    Nombre del filtro final aplicado al array de posts.
	 * @return \WP_Post[]
	 */
	private static function build_top_posts_response( array $args, callable $query_callback, string $count_field, string $count_property, string $filter_hook ): array {

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

		// Mapeo API público → nombre interno de *_Query.
		$range = ( 'day' === $period ) ? 'today' : $period;

		// Construir $filters para *_Query.
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
		// DATA SOURCE (sin SQL nuevo) — invocado directamente, no via call_user_func(),
		// ya que ambos proveedores comparten exactamente la misma firma.
		// -------------------------
		$rows = $query_callback( $range, $limit, $filters );

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
			$post->{$count_property} = (int) $row[ $count_field ];
			$post->wpam_thumbnail    = get_the_post_thumbnail_url( $post_id, 'medium' );

			$posts[] = $post;
		}

		// -------------------------
		// FILTER HOOK
		// -------------------------
		return apply_filters( $filter_hook, $posts, $args );
	}

	/**
	 * Helper de disponibilidad del API.
	 */
	public static function is_available(): bool {
		return class_exists( self::class );
	}
}
