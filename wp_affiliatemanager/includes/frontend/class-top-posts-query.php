<?php
/**
 * Top Posts Query — lógica compartida entre el Dashboard y el shortcode frontend.
 *
 * Extraída de Admin_Menu::get_top_posts() en v1.0.0 para que tanto el
 * dashboard de analytics como el shortcode [wpam_top_posts] consuman
 * exactamente la misma fuente de datos sin duplicar SQL.
 *
 * v1.1.0: Añadido soporte de filtros opcionales (categories, tags, authors,
 * post_type) vía tercer parámetro $filters. Compatibilidad total hacia atrás
 * — callers existentes sin $filters no se ven afectados.
 *
 * @package WP_AffiliateManager\Frontend
 * @since   1.0.0
 */

namespace WP_AffiliateManager\Frontend;

use WP_AffiliateManager\Redirect\Clicks_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Top_Posts_Query {

	/**
	 * Retorna los posts con más clicks para el rango solicitado,
	 * opcionalmente filtrados por taxonomías, autores y post_type.
	 *
	 * Cuando $filters está vacío el comportamiento es idéntico al original.
	 *
	 * @param  string $range    today|week|month|total
	 * @param  int    $limit    Número máximo de resultados. Default 10.
	 * @param  array  $filters {
	 *     Filtros opcionales. Todos son arrays de enteros. Vacío = sin filtro.
	 *     @type int[]  $categories_include  IDs de categorías que deben estar presentes.
	 *     @type int[]  $categories_exclude  IDs de categorías que deben estar ausentes.
	 *     @type int[]  $tags_include        IDs de tags que deben estar presentes.
	 *     @type int[]  $tags_exclude        IDs de tags que deben estar ausentes.
	 *     @type int[]  $authors_include     IDs de autores que deben estar presentes.
	 *     @type int[]  $authors_exclude     IDs de autores que deben estar ausentes.
	 *     @type string $post_type           Post type a filtrar. Default 'any'.
	 * }
	 * @return array[]  Cada elemento: [ id, title, click_count, permalink ]
	 */
	public static function get( string $range = 'total', int $limit = 10, array $filters = array() ): array {
		global $wpdb;

		$table = Clicks_Table::table_name();
		// Pedimos más resultados de los necesarios si hay filtros activos,
		// para compensar los posts que serán descartados tras el filtrado.
		$sql_limit = ! empty( $filters ) ? min( 500, $limit * 10 ) : max( 1, min( 100, $limit ) );

		$where = '';
		if ( 'total' !== $range ) {
			$since = self::range_to_since( $range );
			$where = $wpdb->prepare( ' WHERE ts >= %s', $since );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, COUNT(*) AS click_count FROM %i{$where} GROUP BY post_id ORDER BY click_count DESC LIMIT %d",
				$table,
				$sql_limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		// Construir mapa post_id → click_count para no perder el conteo al filtrar.
		$click_map = array();
		foreach ( $rows as $row ) {
			$click_map[ (int) $row['post_id'] ] = (int) $row['click_count'];
		}

		$post_ids = array_keys( $click_map );

		if ( empty( $post_ids ) ) {
			return array();
		}

		// Aplicar filtros de taxonomía, autor y post_type via get_posts().
		// get_posts() acepta arrays de IDs como 'include', lo que evita
		// cualquier SQL manual adicional y delega la lógica a WordPress core.
		$post_ids = self::apply_filters_to_ids( $post_ids, $filters );

		if ( empty( $post_ids ) ) {
			return array();
		}

		// Respetar el límite solicitado ahora que ya filtramos.
		$post_ids = array_slice( $post_ids, 0, $limit );

		$result = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$result[] = array(
				'id'          => $post_id,
				'title'       => $post->post_title ?: __( '(no title)', 'wp-affiliatemanager' ),
				'click_count' => $click_map[ $post_id ],
				'permalink'   => (string) get_permalink( $post_id ),
			);
		}

		return $result;
	}

	/**
	 * Retorna los posts con más clicks, con caché de objeto.
	 *
	 * Fuente única de caché para shortcode, widget y WPAM_API.
	 * TTL: 300 segundos.
	 * La clave incluye un hash de $filters para que distintas combinaciones
	 * nunca colisionen entre sí ni con las llamadas sin filtros.
	 *
	 * @param  string $range
	 * @param  int    $limit
	 * @param  array  $filters  Ver get() para la estructura completa.
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
	 * Filtra un array de post IDs aplicando los filtros de taxonomía,
	 * autor y post_type. Preserva el orden original (por clicks).
	 *
	 * Usa get_posts() con 'post__in' para delegar toda la lógica
	 * de taxonomías y autores a WordPress core sin SQL manual.
	 *
	 * @param  int[]  $post_ids  IDs ordenados por click_count DESC.
	 * @param  array  $filters   Ver get() para la estructura.
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

		// get_posts() con fields=ids devuelve strings; normalizar a int.
		return array_map( 'intval', $filtered_ids );
	}

	/**
	 * Genera la clave de caché para la combinación de parámetros dada.
	 *
	 * Para llamadas sin filtros la clave es idéntica al formato original
	 * (wpam_top_posts_{range}_{limit}), garantizando compatibilidad total.
	 *
	 * @param  string $range
	 * @param  int    $limit
	 * @param  array  $filters
	 * @return string
	 */
	private static function build_cache_key( string $range, int $limit, array $filters ): string {
		$base = 'wpam_top_posts_' . $range . '_' . $limit;

		if ( empty( $filters ) ) {
			return $base;
		}

		// Ordenar para que arrays con los mismos elementos en distinto orden
		// produzcan la misma clave.
		$normalized = array();
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

	/**
	 * Convierte un rango a un datetime UTC usable en cláusulas WHERE.
	 *
	 * @param  string $range  today|week|month
	 * @return string  Y-m-d H:i:s en UTC
	 */
	public static function range_to_since( string $range ): string {
		switch ( $range ) {
			case 'today':
				return gmdate( 'Y-m-d' ) . ' 00:00:00';
			case 'week':
				return gmdate( 'Y-m-d', strtotime( '-7 days' ) ) . ' 00:00:00';
			case 'month':
				return gmdate( 'Y-m-d', strtotime( '-30 days' ) ) . ' 00:00:00';
			default:
				return '1970-01-01 00:00:00';
		}
	}
}
