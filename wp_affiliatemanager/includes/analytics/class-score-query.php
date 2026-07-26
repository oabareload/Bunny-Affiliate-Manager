<?php
/**
 * Score Query — combina wpam_views y wpam_clicks en un único ranking ponderado.
 *
 * score = views * FACTOR_VIEWS + clicks * FACTOR_CLICKS
 *
 * Estructuralmente sigue el mismo patrón público que Top_Posts_Query y
 * Views_Query (get / get_cached), pero consulta ambas tablas directamente
 * con SQL propio (UNION ALL agrupado por post_id) en vez de reutilizar
 * Top_Posts_Query / Views_Query — decisión explícita para no acoplar
 * Score_Query a la caché o a la forma de agregación de las otras dos
 * Query classes.
 *
 * apply_filters_to_ids() es una copia intencional de la misma lógica que
 * ya existe en Top_Posts_Query y Views_Query — mismo criterio de
 * independencia entre módulos que se sigue en todo el proyecto.
 *
 * @package WP_AffiliateManager\Analytics
 * @since   1.4.0
 */

namespace WP_AffiliateManager\Analytics;

use WP_AffiliateManager\Frontend\Top_Posts_Query;
use WP_AffiliateManager\Redirect\Clicks_Table;
use WP_AffiliateManager\Views\Views_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Score_Query {

	/**
	 * Peso por defecto de cada vista en el cálculo del score.
	 *
	 * Público (no privado) a propósito: cuando estos factores se muevan a
	 * Settings en una versión futura, el resto del código ya tiene un punto
	 * único de referencia al que apuntar sin tener que tocar esta clase.
	 *
	 * @since 1.4.0
	 */
	public const DEFAULT_FACTOR_VIEWS = 1;

	/**
	 * Peso por defecto de cada click en el cálculo del score.
	 *
	 * @since 1.4.0
	 */
	public const DEFAULT_FACTOR_CLICKS = 25;

	// -------------------------------------------------------------------------
	// Top Scored Posts — equivalente a Top_Posts_Query::get() / Views_Query::get()
	// -------------------------------------------------------------------------

	/**
	 * Retorna los posts con más score para el rango solicitado,
	 * opcionalmente filtrados por taxonomías, autores y post_type.
	 *
	 * @since  1.4.0
	 *
	 * @param  string $range   today|week|month|total
	 * @param  int    $limit   Número máximo de resultados. Default 10.
	 * @param  array  $filters Ver Top_Posts_Query::get() para la estructura completa.
	 * @return array[] Cada elemento: [ id, title, score, permalink ]
	 */
	public static function get( string $range = 'total', int $limit = 10, array $filters = array() ): array {
		global $wpdb;

		$views_table  = Views_Table::table_name();
		$clicks_table = Clicks_Table::table_name();

		$sql_limit = ! empty( $filters ) ? min( 500, $limit * 10 ) : max( 1, min( 100, $limit ) );

		$views_where  = '';
		$clicks_where = '';

		if ( 'total' !== $range ) {
			$since_datetime = Top_Posts_Query::range_to_since( $range );
			$since_period   = gmdate( 'Ymd', strtotime( $since_datetime ) );
			$views_where    = $wpdb->prepare( ' WHERE period >= %s', $since_period );
			$clicks_where   = $wpdb->prepare( ' WHERE ts >= %s', $since_datetime );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, SUM(contrib) AS score FROM (
					SELECT post_id, SUM(count) * %d AS contrib FROM %i{$views_where} GROUP BY post_id
					UNION ALL
					SELECT post_id, COUNT(*) * %d AS contrib FROM %i{$clicks_where} GROUP BY post_id
				) AS combined GROUP BY post_id ORDER BY score DESC LIMIT %d",
				self::DEFAULT_FACTOR_VIEWS,
				$views_table,
				self::DEFAULT_FACTOR_CLICKS,
				$clicks_table,
				$sql_limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		// Mapa post_id => score para no perder el conteo al filtrar.
		$score_map = array();
		foreach ( $rows as $row ) {
			$score_map[ (int) $row['post_id'] ] = (int) $row['score'];
		}

		$post_ids = array_keys( $score_map );

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
				'id'        => $post_id,
				'title'     => $post->post_title ?: __( '(no title)', 'wp-affiliatemanager' ),
				'score'     => $score_map[ $post_id ],
				'permalink' => (string) get_permalink( $post_id ),
			);
		}

		return $result;
	}

	/**
	 * Retorna los posts con más score, con caché de objeto.
	 *
	 * Mismo TTL (300s) y mismo grupo de caché ('wpam') que Top_Posts_Query
	 * y Views_Query.
	 *
	 * @since  1.4.0
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

	/**
	 * Retorna un mapa post_id => score para un conjunto dado de post IDs.
	 *
	 * Reutiliza la misma lógica de agregación (vistas + clicks) que el resto
	 * de la clase para mantener una única fuente de verdad del cálculo.
	 *
	 * @since 1.6.0
	 * @param int[] $post_ids
	 * @param string $range today|week|month|total
	 * @return array<int,int> Mapa post_id => score
	 */
	public static function get_scores_for_post_ids( array $post_ids, string $range = 'total' ): array {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return array();
		}

		$views_table  = Views_Table::table_name();
		$clicks_table = Clicks_Table::table_name();

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$views_where  = '';
		$clicks_where = '';

		if ( 'total' !== $range ) {
			$since_datetime = Top_Posts_Query::range_to_since( $range );
			$since_period   = gmdate( 'Ymd', strtotime( $since_datetime ) );
			$views_where    = $wpdb->prepare( ' WHERE period >= %s', $since_period );
			$clicks_where   = $wpdb->prepare( ' WHERE ts >= %s', $since_datetime );
		}

		// Añadir la restricción por post_id al WHERE correspondiente.
		if ( '' === $views_where ) {
			$views_where_post = ' WHERE post_id IN (' . $placeholders . ')';
		} else {
			$views_where_post = $views_where . ' AND post_id IN (' . $placeholders . ')';
		}

		if ( '' === $clicks_where ) {
			$clicks_where_post = ' WHERE post_id IN (' . $placeholders . ')';
		} else {
			$clicks_where_post = $clicks_where . ' AND post_id IN (' . $placeholders . ')';
		}

		// Preparar los argumentos para $wpdb->prepare en el orden correcto.
		$args = array();
		$args[] = self::DEFAULT_FACTOR_VIEWS;
		$args[] = $views_table;

		if ( 'total' !== $range ) {
			$args[] = $since_period;
		}

		foreach ( $post_ids as $id ) {
			$args[] = (int) $id;
		}

		$args[] = self::DEFAULT_FACTOR_CLICKS;
		$args[] = $clicks_table;

		if ( 'total' !== $range ) {
			$args[] = $since_datetime;
		}

		foreach ( $post_ids as $id ) {
			$args[] = (int) $id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, SUM(contrib) AS score FROM (
					SELECT post_id, SUM(count) * %d AS contrib FROM %i{$views_where_post} GROUP BY post_id
					UNION ALL
					SELECT post_id, COUNT(*) * %d AS contrib FROM %i{$clicks_where_post} GROUP BY post_id
				) AS combined GROUP BY post_id",
				$args
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$score_map = array();
		foreach ( $rows as $row ) {
			$score_map[ (int) $row['post_id'] ] = (int) $row['score'];
		}

		return $score_map;
	}

	// -------------------------------------------------------------------------
	// Dashboard / Analytics stat cards
	// -------------------------------------------------------------------------

	/**
	 * Retorna el score total agregado por rango de tiempo, para las cards
	 * de stats (Today / Last 7 Days / Last 30 Days / Total).
	 *
	 * @since  1.4.0
	 * @return array{ today: int, week: int, month: int, total: int }
	 */
	public static function get_stats(): array {
		return array(
			'today' => self::get_total_score( 'today' ),
			'week'  => self::get_total_score( 'week' ),
			'month' => self::get_total_score( 'month' ),
			'total' => self::get_total_score( 'total' ),
		);
	}

	/**
	 * get_stats() con caché de objeto. Mismo TTL/grupo que el resto de la clase.
	 *
	 * @since  1.4.0
	 * @return array{ today: int, week: int, month: int, total: int }
	 */
	public static function get_stats_cached(): array {
		$cached = wp_cache_get( 'wpam_score_stats', 'wpam' );

		if ( false !== $cached ) {
			return $cached;
		}

		$stats = self::get_stats();
		wp_cache_set( 'wpam_score_stats', $stats, 'wpam', 300 );

		return $stats;
	}

	/**
	 * Calcula el score total (sin desglose por post) para un rango dado.
	 *
	 * @since  1.4.0
	 * @param  string $range today|week|month|total
	 * @return int
	 */
	private static function get_total_score( string $range ): int {
		global $wpdb;

		$views_table  = Views_Table::table_name();
		$clicks_table = Clicks_Table::table_name();

		$views_where  = '';
		$clicks_where = '';

		if ( 'total' !== $range ) {
			$since_datetime = Top_Posts_Query::range_to_since( $range );
			$since_period   = gmdate( 'Ymd', strtotime( $since_datetime ) );
			$views_where    = $wpdb->prepare( ' WHERE period >= %s', $since_period );
			$clicks_where   = $wpdb->prepare( ' WHERE ts >= %s', $since_datetime );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(contrib) FROM (
					SELECT SUM(count) * %d AS contrib FROM %i{$views_where}
					UNION ALL
					SELECT COUNT(*) * %d AS contrib FROM %i{$clicks_where}
				) AS combined",
				self::DEFAULT_FACTOR_VIEWS,
				$views_table,
				self::DEFAULT_FACTOR_CLICKS,
				$clicks_table
			)
		);

		return (int) $total;
	}

	// -------------------------------------------------------------------------
	// Filtros — copia intencional de Top_Posts_Query::apply_filters_to_ids()
	// -------------------------------------------------------------------------

	/**
	 * Filtra un array de post IDs aplicando los filtros de taxonomía,
	 * autor y post_type. Preserva el orden original (por score).
	 *
	 * @since  1.4.0
	 * @param  int[] $post_ids IDs ordenados por score DESC.
	 * @param  array $filters  Ver get() para la estructura.
	 * @return int[] IDs que superan todos los filtros, en el mismo orden.
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
			'orderby'             => 'post__in',
			'fields'              => 'ids',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);

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
	// Caché — mismo patrón que Top_Posts_Query::build_cache_key()
	// -------------------------------------------------------------------------

	/**
	 * Genera la clave de caché para la combinación de parámetros dada.
	 *
	 * @since  1.4.0
	 * @param  string $range
	 * @param  int    $limit
	 * @param  array  $filters
	 * @return string
	 */
	private static function build_cache_key( string $range, int $limit, array $filters ): string {
		$base = 'wpam_top_scored_posts_' . $range . '_' . $limit;

		if ( empty( $filters ) ) {
			return $base;
		}

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
}
