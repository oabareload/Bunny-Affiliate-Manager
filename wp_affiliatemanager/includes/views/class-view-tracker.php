<?php
/**
 * View Tracker — registra una vista en las tablas SQL del módulo Views.
 *
 * Responsabilidad única: escribir. No conoce elegibilidad, cookies, AJAX ni
 * nonces — eso vive en Views (class-views.php). Este tracker solo hace el
 * upsert atómico contra las tablas.
 *
 * @package WP_AffiliateManager\Views
 * @since   1.2.0
 * @since   1.8.0 record() generalizado a resource_type/resource_id. Añadidos
 *               record_search_term() / record_404_url() para las 2 tablas
 *               auxiliares de contexto agregado.
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
	 * Longitud máxima de un término de búsqueda normalizado.
	 *
	 * @since 1.8.0
	 */
	const SEARCH_TERM_MAX_LENGTH = 100;

	/**
	 * Longitud máxima de un path 404 normalizado.
	 *
	 * @since 1.8.0
	 */
	const URL_404_MAX_LENGTH = 255;

	/**
	 * Registra una vista para el resource_type + resource_id + período (día) actual.
	 *
	 * Usa INSERT ... ON DUPLICATE KEY UPDATE sobre la UNIQUE KEY
	 * (resource_type, post_id, period) de Views_Table: una única query,
	 * atómica, sin SELECT previo y sin condición de carrera entre lecturas
	 * y escrituras.
	 *
	 * @since  1.2.0
	 * @since  1.8.0 Generalizado de record(int $post_id) a resource_type +
	 *               resource_id. Para resource_type='post' el comportamiento
	 *               y el resultado en DB son exactamente los mismos que antes
	 *               (misma tabla, misma columna post_id, mismo period/count).
	 *
	 * @param  string $resource_type Uno de Resource_Resolver::TYPES.
	 * @param  int    $resource_id   ID del recurso visitado (0 para home/search/404).
	 * @return bool True si la query se ejecutó sin error.
	 */
	public function record( string $resource_type, int $resource_id ): bool {
		if ( $resource_id < 0 ) {
			return false;
		}

		global $wpdb;

		$period = gmdate( 'Ymd' );
		$table  = Views_Table::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (post_id, period, count, resource_type)
				 VALUES (%d, %s, 1, %s)
				 ON DUPLICATE KEY UPDATE count = count + 1',
				$table,
				$resource_id,
				$period,
				$resource_type
			)
		);

		return false !== $result;
	}

	/**
	 * Registra un término de búsqueda en la tabla auxiliar agregada.
	 *
	 * Normaliza y trunca ANTES de escribir — nunca guarda HTML ni el texto
	 * tal como llegó del cliente. Modelo agregado idéntico a record(): una
	 * fila por término único por día, upsert atómico.
	 *
	 * @since  1.8.0
	 * @param  string $raw_term Término de búsqueda sin sanitizar.
	 * @return bool True si se registró (false si el término quedó vacío tras normalizar).
	 */
	public function record_search_term( string $raw_term ): bool {
		$term = self::normalize_search_term( $raw_term );

		if ( '' === $term ) {
			return false;
		}

		global $wpdb;

		$period = gmdate( 'Ymd' );
		$table  = Views_Table::search_terms_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (term_normalized, period, count)
				 VALUES (%s, %s, 1)
				 ON DUPLICATE KEY UPDATE count = count + 1',
				$table,
				$term,
				$period
			)
		);

		return false !== $result;
	}

	/**
	 * Registra una URL 404 en la tabla auxiliar agregada.
	 *
	 * Normaliza a solo-path (sin host, sin query string) y trunca ANTES de
	 * escribir. Nunca guarda HTML, dominios ni parámetros.
	 *
	 * @since  1.8.0
	 * @param  string $raw_url URL/path solicitado, sin sanitizar.
	 * @return bool True si se registró (false si quedó vacío tras normalizar).
	 */
	public function record_404_url( string $raw_url ): bool {
		$path = self::normalize_404_path( $raw_url );

		if ( '' === $path ) {
			return false;
		}

		global $wpdb;

		$period = gmdate( 'Ymd' );
		$table  = Views_Table::table_404_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (url_normalized, period, count)
				 VALUES (%s, %s, 1)
				 ON DUPLICATE KEY UPDATE count = count + 1',
				$table,
				$path,
				$period
			)
		);

		return false !== $result;
	}

	// -------------------------------------------------------------------------
	// Normalización — nunca HTML, siempre acotado en longitud
	// -------------------------------------------------------------------------

	/**
	 * Normaliza un término de búsqueda: sin HTML, minúsculas, trim, longitud
	 * acotada. No usa esc_html/wp_kses porque el valor nunca se renderiza
	 * como HTML — se guarda como texto plano puro.
	 *
	 * @since  1.8.0
	 * @param  string $raw
	 * @return string
	 */
	public static function normalize_search_term( string $raw ): string {
		$term = sanitize_text_field( $raw );
		$term = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term ) : strtolower( $term );
		$term = trim( $term );

		if ( function_exists( 'mb_substr' ) ) {
			$term = mb_substr( $term, 0, self::SEARCH_TERM_MAX_LENGTH );
		} else {
			$term = substr( $term, 0, self::SEARCH_TERM_MAX_LENGTH );
		}

		return $term;
	}

	/**
	 * Normaliza una URL 404 a únicamente su path: sin protocolo, sin host,
	 * sin query string (puede contener datos sensibles), sin HTML, longitud
	 * acotada.
	 *
	 * @since  1.8.0
	 * @param  string $raw
	 * @return string
	 */
	public static function normalize_404_path( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		// Si llega como URL completa, quedarnos solo con el path.
		$parsed = wp_parse_url( $raw );
		$path   = is_array( $parsed ) && isset( $parsed['path'] ) ? $parsed['path'] : $raw;

		$path = sanitize_text_field( $path );
		$path = wp_strip_all_tags( $path );

		if ( '' === $path ) {
			return '';
		}

		if ( ! str_starts_with( $path, '/' ) ) {
			$path = '/' . $path;
		}

		return substr( $path, 0, self::URL_404_MAX_LENGTH );
	}
}
