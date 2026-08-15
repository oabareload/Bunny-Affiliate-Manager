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
 * @since   1.8.0 Añadido parámetro $resource_type (default 'post') a get(),
 *               get_cached(), get_stats(), get_stats_cached() y get_recent().
 *               El default 'post' preserva exactamente el comportamiento
 *               previo — WPAM_API::get_top_viewed_posts() y todos los demás
 *               consumidores existentes siguen funcionando sin cambios.
 *               Añadidos get_search_terms() / get_404_urls() para las 2
 *               tablas auxiliares de contexto agregado.
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
	 * Retorna los recursos con más vistas para el rango solicitado,
	 * opcionalmente filtrados por taxonomías, autores y post_type.
	 *
	 * Los filtros de taxonomía/autor/post_type (apply_filters_to_ids) solo
	 * tienen sentido para resource_type='post' — para el resto de tipos se
	 * ignoran automáticamente porque get_posts() con esos IDs simplemente
	 * no devolvería resultados fuera de ese contexto; en la práctica
	 * Analytics solo los pasa para 'post'.
	 *
	 * @since  1.2.0-alpha1
	 * @since  1.8.0 Añadido $resource_type (default 'post').
	 *
	 * @param  string $range         today|week|month|total
	 * @param  int    $limit         Número máximo de resultados. Default 10.
	 * @param  array  $filters       Ver Top_Posts_Query::get() para la estructura completa.
	 * @param  string $resource_type Uno de Resource_Resolver::TYPES. Default 'post'.
	 * @return array[] Cada elemento: [ id, title, view_count, permalink ]
	 */
	public static function get( string $range = 'total', int $limit = 10, array $filters = array(), string $resource_type = 'post' ): array {
		global $wpdb;

		$table     = Views_Table::table_name();
		$sql_limit = ! empty( $filters ) ? min( 500, $limit * 10 ) : max( 1, min( 100, $limit ) );

		$where = $wpdb->prepare( ' WHERE resource_type = %s', $resource_type );
		if ( 'total' !== $range ) {
			$since  = self::range_to_period_since( $range );
			$where .= $wpdb->prepare( ' AND period >= %s', $since );
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

		// Mapa resource_id => view_count para no perder el conteo al filtrar.
		$view_map = array();
		foreach ( $rows as $row ) {
			$view_map[ (int) $row['post_id'] ] = (int) $row['view_count'];
		}

		$resource_ids = array_keys( $view_map );

		if ( empty( $resource_ids ) ) {
			return array();
		}

		if ( 'post' === $resource_type ) {
			$resource_ids = self::apply_filters_to_ids( $resource_ids, $filters );
		}

		if ( empty( $resource_ids ) ) {
			return array();
		}

		$resource_ids = array_slice( $resource_ids, 0, $limit );

		$result = array();
		foreach ( $resource_ids as $resource_id ) {
			$item = self::resolve_display_item( $resource_type, $resource_id );

			if ( null === $item ) {
				continue;
			}

			$item['view_count'] = $view_map[ $resource_id ];
			$result[]            = $item;
		}

		return $result;
	}

	/**
	 * Retorna los recursos con más vistas, con caché de objeto.
	 *
	 * Mismo TTL (300s) y mismo grupo de caché ('wpam') que
	 * Top_Posts_Query::get_cached().
	 *
	 * @since  1.2.0-alpha1
	 * @since  1.8.0 Añadido $resource_type (default 'post').
	 *
	 * @param  string $range
	 * @param  int    $limit
	 * @param  array  $filters
	 * @param  string $resource_type
	 * @return array[]
	 */
	public static function get_cached( string $range = 'total', int $limit = 10, array $filters = array(), string $resource_type = 'post' ): array {
		$cache_key = self::build_cache_key( $range, $limit, $filters, $resource_type );
		$cached    = wp_cache_get( $cache_key, 'wpam' );

		if ( false !== $cached ) {
			return $cached;
		}

		$posts = self::get( $range, $limit, $filters, $resource_type );
		wp_cache_set( $cache_key, $posts, 'wpam', 300 );

		return $posts;
	}

	/**
	 * Resuelve un [id, title, permalink] genérico según el resource_type,
	 * sin SQL adicional — solo funciones nativas de WordPress.
	 *
	 * post/page: get_post(). category/tag: get_term(). home/search/404: no
	 * tienen identidad individual, así que get()/get_cached() para esos 3
	 * tipos no produce una lista "top" útil (siempre hay como mucho 1 fila,
	 * resource_id=0) — Analytics los muestra solo vía get_stats(), no vía
	 * esta lista.
	 *
	 * @since  1.8.0
	 * @param  string $resource_type
	 * @param  int    $resource_id
	 * @return array{id:int,title:string,permalink:string}|null
	 */
	private static function resolve_display_item( string $resource_type, int $resource_id ): ?array {
		switch ( $resource_type ) {
			case 'post':
			case 'page':
				$post = get_post( $resource_id );
				if ( ! $post instanceof \WP_Post ) {
					return null;
				}
				return array(
					'id'        => $resource_id,
					'title'     => $post->post_title ?: __( '(no title)', 'wp-affiliatemanager' ),
					'permalink' => (string) get_permalink( $resource_id ),
				);

			case 'category':
			case 'tag':
				$taxonomy = 'category' === $resource_type ? 'category' : 'post_tag';
				$term     = get_term( $resource_id, $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					return null;
				}
				return array(
					'id'        => $resource_id,
					'title'     => $term->name,
					'permalink' => (string) get_term_link( $term ),
				);

			default:
				return null;
		}
	}

	// -------------------------------------------------------------------------
	// Dashboard stat cards
	// -------------------------------------------------------------------------

	/**
	 * Retorna contadores de vistas agrupados por rango de tiempo, para las
	 * tarjetas del Dashboard/Analytics.
	 *
	 * @since  1.2.0-alpha1
	 * @since  1.8.0 Añadido $resource_type (default 'post').
	 * @param  string $resource_type
	 * @return array{ today: int, week: int, month: int, total: int }
	 */
	public static function get_stats( string $resource_type = 'post' ): array {
		global $wpdb;
		$table = Views_Table::table_name();

		$today = self::range_to_period_since( 'today' );
		$week  = self::range_to_period_since( 'week' );
		$month = self::range_to_period_since( 'month' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$today_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(count) FROM %i WHERE resource_type = %s AND period >= %s', $table, $resource_type, $today ) );
		$week_count  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(count) FROM %i WHERE resource_type = %s AND period >= %s', $table, $resource_type, $week ) );
		$month_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(count) FROM %i WHERE resource_type = %s AND period >= %s', $table, $resource_type, $month ) );
		$total_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(count) FROM %i WHERE resource_type = %s', $table, $resource_type ) );
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
	 * @since  1.8.0 Añadido $resource_type (default 'post').
	 * @param  string $resource_type
	 * @return array{ today: int, week: int, month: int, total: int }
	 */
	public static function get_stats_cached( string $resource_type = 'post' ): array {
		$cache_key = 'wpam_views_stats_' . $resource_type;
		$cached    = wp_cache_get( $cache_key, 'wpam' );

		if ( false !== $cached ) {
			return $cached;
		}

		$stats = self::get_stats( $resource_type );
		wp_cache_set( $cache_key, $stats, 'wpam', 300 );

		return $stats;
	}

	// -------------------------------------------------------------------------
	// Recent Views
	// -------------------------------------------------------------------------

	/**
	 * Retorna las filas más recientes de wpam_views para un resource_type
	 * dado, sin caché (dato "vivo").
	 *
	 * wpam_views es un agregado diario, no un log de eventos: no existe una
	 * columna de timestamp exacto. "Reciente" se ordena por `period` (día) y
	 * `id` como desempate dentro del mismo día. Cada fila representa el
	 * conteo de UN día para UN recurso, no un evento individual.
	 *
	 * @since 1.2.0
	 * @since 1.8.0 Añadido $resource_type (default 'post').
	 * @param  int    $limit Número máximo de filas. Default 20.
	 * @param  string $resource_type
	 * @return array[] Cada elemento: [ post_id, period, count ] (crudo, sin enriquecer).
	 */
	public static function get_recent( int $limit = 20, string $resource_type = 'post' ): array {
		global $wpdb;
		$table = Views_Table::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT post_id, period, count FROM %i WHERE resource_type = %s ORDER BY period DESC, id DESC LIMIT %d',
				$table,
				$resource_type,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// -------------------------------------------------------------------------
	// Search terms / 404 URLs — tablas auxiliares (v1.8.0)
	// -------------------------------------------------------------------------

	/**
	 * Retorna los términos de búsqueda más frecuentes para el rango dado.
	 *
	 * Sin filtros de taxonomía/autor (no aplican: un término de búsqueda no
	 * pertenece a ningún post). Listado simple, sin caché — volumen de datos
	 * pequeño por diseño (agregado diario, términos normalizados).
	 *
	 * @since  1.8.0
	 * @param  string $range today|week|month|total
	 * @param  int    $limit
	 * @return array[] Cada elemento: [ term, count ]
	 */
	public static function get_search_terms( string $range = 'total', int $limit = 10 ): array {
		global $wpdb;
		$table = Views_Table::search_terms_table_name();

		$where = '';
		if ( 'total' !== $range ) {
			$since = self::range_to_period_since( $range );
			$where = $wpdb->prepare( ' WHERE period >= %s', $since );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_normalized, SUM(count) AS total_count FROM %i{$where} GROUP BY term_normalized ORDER BY total_count DESC LIMIT %d",
				$table,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'term'  => $row['term_normalized'],
					'count' => (int) $row['total_count'],
				);
			},
			$rows
		);
	}

	/**
	 * Retorna las URLs 404 más frecuentes para el rango dado.
	 *
	 * @since  1.8.0
	 * @param  string $range today|week|month|total
	 * @param  int    $limit
	 * @return array[] Cada elemento: [ url, count ]
	 */
	public static function get_404_urls( string $range = 'total', int $limit = 10 ): array {
		global $wpdb;
		$table = Views_Table::table_404_name();

		$where = '';
		if ( 'total' !== $range ) {
			$since = self::range_to_period_since( $range );
			$where = $wpdb->prepare( ' WHERE period >= %s', $since );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url_normalized, SUM(count) AS total_count FROM %i{$where} GROUP BY url_normalized ORDER BY total_count DESC LIMIT %d",
				$table,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'url'   => $row['url_normalized'],
					'count' => (int) $row['total_count'],
				);
			},
			$rows
		);
	}

	/**
	 * Stats agregados (today/week/month/total) para términos de búsqueda.
	 *
	 * @since  1.8.0
	 * @return array{ today: int, week: int, month: int, total: int }
	 */
	public static function get_search_terms_stats(): array {
		return self::get_aux_table_stats( Views_Table::search_terms_table_name() );
	}

	/**
	 * Stats agregados (today/week/month/total) para URLs 404.
	 *
	 * @since  1.8.0
	 * @return array{ today: int, week: int, month: int, total: int }
	 */
	public static function get_404_stats(): array {
		return self::get_aux_table_stats( Views_Table::table_404_name() );
	}

	/**
	 * Lógica compartida de get_search_terms_stats() / get_404_stats(): ambas
	 * tablas auxiliares tienen exactamente la misma forma (period, count),
	 * solo cambia el nombre de tabla.
	 *
	 * @since  1.8.0
	 * @param  string $table Nombre completo de tabla (con prefijo).
	 * @return array{ today: int, week: int, month: int, total: int }
	 */
	private static function get_aux_table_stats( string $table ): array {
		global $wpdb;

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
	 * @since  1.8.0 Añadido $resource_type a la clave (evita colisión entre
	 *               tipos, ej. 'post' vs 'category' con el mismo range/limit).
	 * @param  string $range
	 * @param  int    $limit
	 * @param  array  $filters
	 * @param  string $resource_type
	 * @return string
	 */
	private static function build_cache_key( string $range, int $limit, array $filters, string $resource_type = 'post' ): string {
		$base = 'wpam_top_viewed_posts_' . $resource_type . '_' . $range . '_' . $limit;

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
