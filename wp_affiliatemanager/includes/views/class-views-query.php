<?php
/**
 * Views Query — lógica de lectura sobre la tabla wpam_views.
 *
 * Equivalente completo de Frontend\Top_Posts_Query, pero sobre vistas en vez
 * de clicks. Misma interfaz pública (get / get_cached), misma filosofía de
 * caché (wp_cache, grupo 'wpam', TTL 300s), misma organización de código.
 * La única diferencia real es la fuente de datos: SUM(count) sobre
 * wpam_views en vez de COUNT(*) sobre wpam_clicks.
 *
 * Preparada para que WPAM_API::get_top_viewed_posts() reutilice
 * self::get_cached() exactamente como WPAM_API::get_top_posts() reutiliza
 * hoy Top_Posts_Query::get_cached() — sin rediseñar nada cuando llegue ese
 * momento.
 *
 * @package WP_AffiliateManager\Views
 * @since   1.2.0-alpha1
 */

namespace WP_AffiliateManager\Views;

use WP_AffiliateManager\Frontend\Top_Posts_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Views_Query
 *
 * @since 1.2.0-alpha1
 */
class Views_Query {

	// -------------------------------------------------------------------------
	// Top Viewed Posts — equivalente a Top_Posts_Query::get() / get_cached()
	// -------------------------------------------------------------------------

	/**
	 * Retorna los posts con más vistas para el rango solicitado,
	 * opcionalmente filtrados por taxonomías, autores y post_type.
	 *
	 * Estructuralmente idéntico a Top_Posts_Query::get(): mismos parámetros,
	 * mismo filtrado vía apply_filters_to_ids(), mismo formato de retorno
	 * (view_count en vez de click_count).
	 *
	 * @since  1.2.0-alpha1
	 *
	 * @param  string $range   today|week|month|total
	 * @param  int    $limit   Número máximo de resultados. Default 10.
	 * @param  array  $filters {
	 *     Filtros opcionales. Ver Top_Posts_Query::get() para la estructura completa.
	 * }
	 * @return array[] Cada elemento: [ id, title, view_count, permalink ]
	 */
	public static function get( string $range = 'total', int $limit = 10, array $filters = array() ): array {
		global $wpdb;

		$table     = Views_Table::table_name();
		$sql_limit = ! empty( $filters ) ? min( 500, $limit * 10 ) : max( 1, min( 100, $limit ) );

		$where = '';
		if ( 'total' !== $range ) {
			$since = self::range_to_period_since( $range );
			$where = $wpdb->prepare( ' WHERE period >= %s', $since );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, SUM(count) AS view_count FROM %i{$where} GROUP BY post_id ORDER BY view_count DESC LIMIT %d",
				$table,
				$sql_limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		// Mapa post_id => view_count para no perder el conteo al filtrar.
		$view_map = array();
		foreach ( $rows as $row ) {
			$view_map[ (int) $row['post_id'] ] = (int) $row['view_count'];
		}

		$post_ids = array_keys( $view_map );

		if ( empty( $post_ids ) ) {
			return array();
		}

		$post_ids = self::apply_filters_to_ids( $post_ids, $filters );

		if ( empty( $post_ids ) ) {
			return array();
		}

		$post_ids = array_slice( $post_ids, 0, $limit );

		$result = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$result[] = array(
				'id'         => $post_id,
				'title'      => $post->post_title ?: __( '(no title)', 'wp-affiliatemanager' ),
				'view_count' => $view_map[ $post_id ],
				'permalink'  => (string) get_permalink( $post_id ),
			);
		}

		return $result;
	}

	/**
	 * Retorna los posts con más vistas, con caché de objeto.
	 *
	 * Mismo TTL (300s) y mismo grupo de caché ('wpam') que
	 * Top_Posts_Query::get_cached(), para que ambos módulos compartan
	 * exactamente la misma filosofía de invalidación.
	 *
	 * @since  1.2.0-alpha1
	 *
	 * @param  string $range
	 * @param  int    $limit
	 * @param  array  $filters
	 * @return array[]
	 */
	public static function get_cached( string $range = 'total', int $limit = 10, array $filters = array() ): array {
		$cache_key = self::build_cache_key( $range, $limit, $filters );
		$cached    = wp_cache_get( $cache_key, 'wpam' );

		if ( false !== $cached ) {
			return $cached;
		}

		$posts = self::get( $range, $limit, $filters );
		wp_cache_set( $cache_key, $posts, 'wpam', 300 );

		return $posts;
	}

	// -------------------------------------------------------------------------
	// Dashboard stat cards
	// -------------------------------------------------------------------------

	/**
	 * Retorna contadores de vistas agrupados por rango de tiempo, para las
	 * tarjetas del Dashboard.
	 *
	 * Sin equivalente directo en Top_Posts_Query (Admin_Menu::get_click_stats()
	 * vive fuera de esa clase, inline). Aquí sí se centraliza en la clase
	 * porque necesita comparar directamente contra `period`, el mismo campo
	 * que usa get().
	 *
	 * @since  1.2.0-alpha1
	 * @return array{ today: int, week: int, month: int, total: int }
	 */
	public static function get_stats(): array {
		global $wpdb;
		$table = Views_Table::table_name();

		$today = self::range_to_period_since( 'today' );
		$week  = self::range_to_period_since( 'week' );
		$month = self::range_to_period_since( 'month' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$today_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(count) FROM %i WHERE period >= %s', $table, $today ) );
		$week_count  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(count) FROM %i WHERE period >= %s', $table, $week ) );
		$month_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(count) FROM %i WHERE period >= %s', $table, $month ) );
		$total_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(count) FROM %i', $table ) );
		// phpcs:enable

		return array(
			'today' => $today_count,
			'week'  => $week_count,
			'month' => $month_count,
			'total' => $total_count,
		);
	}

	/**
	 * get_stats() con caché de objeto. Mismo TTL/grupo que el resto de la clase.
	 *
	 * @since  1.2.0-alpha1
	 * @return array{ today: int, week: int, month: int, total: int }
	 */
	public static function get_stats_cached(): array {
		$cached = wp_cache_get( 'wpam_views_stats', 'wpam' );

		if ( false !== $cached ) {
			return $cached;
		}

		$stats = self::get_stats();
		wp_cache_set( 'wpam_views_stats', $stats, 'wpam', 300 );

		return $stats;
	}

	// -------------------------------------------------------------------------
	// Recent Views
	// -------------------------------------------------------------------------

	/**
	 * Retorna las filas más recientes de wpam_views, sin caché (dato "vivo").
	 *
	 * wpam_views es un agregado diario, no un log de eventos: no existe una
	 * columna de timestamp exacto. "Reciente" se ordena por `period` (día) y
	 * `id` como desempate dentro del mismo día. Cada fila representa el
	 * conteo de UN día para UN post, no un evento individual.
	 *
	 * @since 1.2.0
	 * @param  int $limit Número máximo de filas. Default 20.
	 * @return array[] Cada elemento: [ post_id, period, count ] (crudo, sin enriquecer).
	 */
	public static function get_recent( int $limit = 20 ): array {
		global $wpdb;
		$table = Views_Table::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT post_id, period, count FROM %i ORDER BY period DESC, id DESC LIMIT %d',
				$table,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// -------------------------------------------------------------------------
	// Filtros — idéntico a Top_Posts_Query::apply_filters_to_ids()
	// -------------------------------------------------------------------------

	/**
	 * Filtra un array de post IDs aplicando los filtros de taxonomía,
	 * autor y post_type. Preserva el orden original (por vistas).
	 *
	 * Duplicado intencional de Top_Posts_Query::apply_filters_to_ids(): misma
	 * lógica y comportamiento, para que ambos módulos permanezcan
	 * independientes y no queden acoplados entre sí más allá de
	 * range_to_since() (ver range_to_period_since() más abajo).
	 *
	 * @since  1.2.0-alpha1
	 * @param  int[]  $post_ids IDs ordenados por view_count DESC.
	 * @param  array  $filters  Ver get() para la estructura.
	 * @return int[]  IDs que superan todos los filtros, en el mismo orden.
	 */
	private static function apply_filters_to_ids( array $post_ids, array $filters ): array {
		if ( empty( $filters ) ) {
			return $post_ids;
		}

		$post_type = ! empty( $filters['post_type'] ) ? (string) $filters['post_type'] : 'any';

		$query_args = array(
			'post__in'            => $post_ids,
			'post_type'           => $post_type,
			'post_status'         => 'publish',
			'posts_per_page'      => count( $post_ids ),
			'orderby'             => 'post__in', // Preservar orden original.
			'fields'              => 'ids',       // Solo necesitamos IDs.
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,        // Optimización: sin paginación.
		);

		// Taxonomía: categorías.
		if ( ! empty( $filters['categories_include'] ) || ! empty( $filters['categories_exclude'] ) ) {
			$tax_query = array();

			if ( ! empty( $filters['categories_include'] ) ) {
				$tax_query[] = array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => array_map( 'intval', (array) $filters['categories_include'] ),
					'operator' => 'IN',
				);
			}

			if ( ! empty( $filters['categories_exclude'] ) ) {
				$tax_query[] = array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => array_map( 'intval', (array) $filters['categories_exclude'] ),
					'operator' => 'NOT IN',
				);
			}

			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}

			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		// Taxonomía: tags.
		if ( ! empty( $filters['tags_include'] ) || ! empty( $filters['tags_exclude'] ) ) {
			$tag_query = array();

			if ( ! empty( $filters['tags_include'] ) ) {
				$tag_query[] = array(
					'taxonomy' => 'post_tag',
					'field'    => 'term_id',
					'terms'    => array_map( 'intval', (array) $filters['tags_include'] ),
					'operator' => 'IN',
				);
			}

			if ( ! empty( $filters['tags_exclude'] ) ) {
				$tag_query[] = array(
					'taxonomy' => 'post_tag',
					'field'    => 'term_id',
					'terms'    => array_map( 'intval', (array) $filters['tags_exclude'] ),
					'operator' => 'NOT IN',
				);
			}

			// Combinar con tax_query existente si ya hay categorías.
			if ( isset( $query_args['tax_query'] ) ) {
				$query_args['tax_query']['relation'] = 'AND';
				foreach ( $tag_query as $clause ) {
					$query_args['tax_query'][] = $clause;
				}
			} else {
				if ( count( $tag_query ) > 1 ) {
					$tag_query['relation'] = 'AND';
				}
				$query_args['tax_query'] = $tag_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			}
		}

		// Autores.
		if ( ! empty( $filters['authors_include'] ) ) {
			$query_args['author__in'] = array_map( 'intval', (array) $filters['authors_include'] );
		}

		if ( ! empty( $filters['authors_exclude'] ) ) {
			$query_args['author__not_in'] = array_map( 'intval', (array) $filters['authors_exclude'] );
		}

		$filtered_ids = get_posts( $query_args );

		return array_map( 'intval', $filtered_ids );
	}

	// -------------------------------------------------------------------------
	// Caché — idéntico a Top_Posts_Query::build_cache_key(), prefijo distinto
	// -------------------------------------------------------------------------

	/**
	 * Genera la clave de caché para la combinación de parámetros dada.
	 *
	 * Prefijo 'wpam_top_viewed_posts_' para no colisionar con las claves de
	 * Top_Posts_Query ('wpam_top_posts_') dentro del mismo grupo 'wpam'.
	 *
	 * @since  1.2.0-alpha1
	 * @param  string $range
	 * @param  int    $limit
	 * @param  array  $filters
	 * @return string
	 */
	private static function build_cache_key( string $range, int $limit, array $filters ): string {
		$base = 'wpam_top_viewed_posts_' . $range . '_' . $limit;

		if ( empty( $filters ) ) {
			return $base;
		}

		// Ordenar para que arrays con los mismos elementos en distinto orden
		// produzcan la misma clave.
		$normalized  = array();
		$filter_keys = array(
			'categories_include',
			'categories_exclude',
			'tags_include',
			'tags_exclude',
			'authors_include',
			'authors_exclude',
			'post_type',
		);

		foreach ( $filter_keys as $key ) {
			if ( ! isset( $filters[ $key ] ) || '' === $filters[ $key ] || array() === $filters[ $key ] ) {
				continue;
			}
			$value = is_array( $filters[ $key ] ) ? $filters[ $key ] : array( $filters[ $key ] );
			sort( $value );
			$normalized[ $key ] = $value;
		}

		if ( empty( $normalized ) ) {
			return $base;
		}

		return $base . '_' . md5( serialize( $normalized ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	// -------------------------------------------------------------------------
	// Rango de fechas — reutiliza Top_Posts_Query::range_to_since()
	// -------------------------------------------------------------------------

	/**
	 * Convierte un rango a un valor de `period` (YYYYMMDD) usable en WHERE.
	 *
	 * Reutiliza Top_Posts_Query::range_to_since() como fuente única de verdad
	 * para la lógica de "cuántos días atrás" (-7, -30). Solo adapta el
	 * formato de salida: wpam_clicks compara contra `ts` (DATETIME) y
	 * wpam_views compara contra `period` (CHAR(8) YYYYMMDD).
	 *
	 * @since  1.2.0-alpha1
	 * @param  string $range  today|week|month
	 * @return string  YYYYMMDD
	 */
	private static function range_to_period_since( string $range ): string {
		$since_datetime = Top_Posts_Query::range_to_since( $range );
		return gmdate( 'Ymd', strtotime( $since_datetime ) );
	}
}
