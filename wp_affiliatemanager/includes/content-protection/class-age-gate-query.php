<?php
/**
 * Age Gate Query — capa de consultas, agregación y métricas del Interstitial NSFW (Age Gate).
 *
 * Responsabilidades:
 *  - Consultar datos agregados de la tabla {prefix}wpam_age_gate_events mediante SQL eficiente.
 *  - Calcular métricas derivadas: tasas de confirmación, rechazo y abandono estimado.
 *  - Desglosar eventos por período, por origen de tráfico (utm_source) y por publicación.
 *  - Proporcionar caché transparente vía Object Cache de WordPress ('wpam', TTL 300s).
 *
 * Nota metodológica sobre el "abandono estimado":
 *  El protocolo HTTP y la arquitectura sin estado de los interstitials no permiten registrar un
 *  evento activo cuando el visitante simplemente cierra la pestaña o pulsa el botón 'Atrás' del navegador.
 *  Por lo tanto, los abandonos se calculan de manera analítica estimada como:
 *      abandoned_estimated = max( 0, shown - (confirmed + rejected) )
 *  Esta métrica representa con fiabilidad los interstitials servidos que no terminaron en una decisión explícita.
 *
 * @package WP_AffiliateManager\Content_Protection
 * @since   2.2.0
 */

namespace WP_AffiliateManager\Content_Protection;

use WP_AffiliateManager\Frontend\Top_Posts_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Age_Gate_Query
 *
 * @since 2.2.0
 */
class Age_Gate_Query {

	/**
	 * Grupo de caché para las consultas del Age Gate.
	 * Alineado con el grupo global del plugin.
	 */
	const CACHE_GROUP = 'wpam';

	/**
	 * Tiempo de vida de la caché en segundos (5 minutos).
	 */
	const CACHE_TTL = 300;

	// -------------------------------------------------------------------------
	// Métricas y totales agregados por rango
	// -------------------------------------------------------------------------

	/**
	 * Retorna los totales y las tasas calculadas del Age Gate para el rango solicitado.
	 *
	 * Ejecuta una única consulta SQL agregada condicional directamente en base de datos.
	 *
	 * @since  2.2.0
	 * @param  string $range today|week|month|year|total. Default 'total'.
	 * @return array{
	 *     shown: int,
	 *     confirmed: int,
	 *     rejected: int,
	 *     abandoned_estimated: int,
	 *     confirmation_rate: float,
	 *     rejection_rate: float,
	 *     abandonment_rate: float
	 * }
	 */
	public static function get_stats( string $range = 'total' ): array {
		global $wpdb;

		$table = Age_Gate_Events_Table::table_name();
		$where = '';

		if ( 'total' !== $range ) {
			$since = self::range_to_period_since( $range );
			$where = $wpdb->prepare( ' WHERE period >= %s', $since );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN event_type = 'shown' THEN count ELSE 0 END), 0) AS total_shown,
					COALESCE(SUM(CASE WHEN event_type = 'confirmed' THEN count ELSE 0 END), 0) AS total_confirmed,
					COALESCE(SUM(CASE WHEN event_type = 'rejected' THEN count ELSE 0 END), 0) AS total_rejected
				 FROM %i{$where}",
				$table
			),
			ARRAY_A
		);
		// phpcs:enable

		$shown     = isset( $row['total_shown'] ) ? (int) $row['total_shown'] : 0;
		$confirmed = isset( $row['total_confirmed'] ) ? (int) $row['total_confirmed'] : 0;
		$rejected  = isset( $row['total_rejected'] ) ? (int) $row['total_rejected'] : 0;

		return self::calculate_rates( $shown, $confirmed, $rejected );
	}

	/**
	 * Versión cacheada de get_stats().
	 *
	 * @since  2.2.0
	 * @param  string $range today|week|month|year|total.
	 * @return array
	 */
	public static function get_stats_cached( string $range = 'total' ): array {
		$cache_key = 'wpam_age_gate_stats_' . $range;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$stats = self::get_stats( $range );
		wp_cache_set( $cache_key, $stats, self::CACHE_GROUP, self::CACHE_TTL );

		return $stats;
	}

	/**
	 * Retorna las métricas globales del Age Gate para todos los rangos estándar
	 * (today, week, month, total) en una única consulta SQL consolidada.
	 *
	 * @since  2.2.0
	 * @return array{
	 *     today: array,
	 *     week: array,
	 *     month: array,
	 *     total: array
	 * }
	 */
	public static function get_global_stats(): array {
		global $wpdb;

		$table = Age_Gate_Events_Table::table_name();
		$today = self::range_to_period_since( 'today' );
		$week  = self::range_to_period_since( 'week' );
		$month = self::range_to_period_since( 'month' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN event_type = 'shown' AND period >= %s THEN count ELSE 0 END), 0) AS today_shown,
					COALESCE(SUM(CASE WHEN event_type = 'confirmed' AND period >= %s THEN count ELSE 0 END), 0) AS today_confirmed,
					COALESCE(SUM(CASE WHEN event_type = 'rejected' AND period >= %s THEN count ELSE 0 END), 0) AS today_rejected,

					COALESCE(SUM(CASE WHEN event_type = 'shown' AND period >= %s THEN count ELSE 0 END), 0) AS week_shown,
					COALESCE(SUM(CASE WHEN event_type = 'confirmed' AND period >= %s THEN count ELSE 0 END), 0) AS week_confirmed,
					COALESCE(SUM(CASE WHEN event_type = 'rejected' AND period >= %s THEN count ELSE 0 END), 0) AS week_rejected,

					COALESCE(SUM(CASE WHEN event_type = 'shown' AND period >= %s THEN count ELSE 0 END), 0) AS month_shown,
					COALESCE(SUM(CASE WHEN event_type = 'confirmed' AND period >= %s THEN count ELSE 0 END), 0) AS month_confirmed,
					COALESCE(SUM(CASE WHEN event_type = 'rejected' AND period >= %s THEN count ELSE 0 END), 0) AS month_rejected,

					COALESCE(SUM(CASE WHEN event_type = 'shown' THEN count ELSE 0 END), 0) AS total_shown,
					COALESCE(SUM(CASE WHEN event_type = 'confirmed' THEN count ELSE 0 END), 0) AS total_confirmed,
					COALESCE(SUM(CASE WHEN event_type = 'rejected' THEN count ELSE 0 END), 0) AS total_rejected
				 FROM %i",
				$today,
				$today,
				$today,
				$week,
				$week,
				$week,
				$month,
				$month,
				$month,
				$table
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'today' => self::calculate_rates(
				(int) ( $row['today_shown'] ?? 0 ),
				(int) ( $row['today_confirmed'] ?? 0 ),
				(int) ( $row['today_rejected'] ?? 0 )
			),
			'week'  => self::calculate_rates(
				(int) ( $row['week_shown'] ?? 0 ),
				(int) ( $row['week_confirmed'] ?? 0 ),
				(int) ( $row['week_rejected'] ?? 0 )
			),
			'month' => self::calculate_rates(
				(int) ( $row['month_shown'] ?? 0 ),
				(int) ( $row['month_confirmed'] ?? 0 ),
				(int) ( $row['month_rejected'] ?? 0 )
			),
			'total' => self::calculate_rates(
				(int) ( $row['total_shown'] ?? 0 ),
				(int) ( $row['total_confirmed'] ?? 0 ),
				(int) ( $row['total_rejected'] ?? 0 )
			),
		);
	}

	/**
	 * Versión cacheada de get_global_stats().
	 *
	 * @since  2.2.0
	 * @return array
	 */
	public static function get_global_stats_cached(): array {
		$cache_key = 'wpam_age_gate_global_stats';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$stats = self::get_global_stats();
		wp_cache_set( $cache_key, $stats, self::CACHE_GROUP, self::CACHE_TTL );

		return $stats;
	}

	// -------------------------------------------------------------------------
	// Top Publicaciones Protegidas con más Interstitials
	// -------------------------------------------------------------------------

	/**
	 * Retorna las publicaciones con más eventos 'shown' del Age Gate en el rango solicitado.
	 *
	 * Para optimizar el rendimiento y evitar el antipatrón N+1, la consulta agrupa
	 * directamente por post_id y enriquece los títulos e URLs en un único batch
	 * con get_posts().
	 *
	 * @since  2.2.0
	 * @param  string $range today|week|month|year|total. Default 'total'.
	 * @param  int    $limit Número máximo de publicaciones a retornar.
	 * @return array<int, array{
	 *     post_id: int,
	 *     title: string,
	 *     permalink: string,
	 *     shown: int,
	 *     confirmed: int,
	 *     rejected: int,
	 *     abandoned_estimated: int,
	 *     confirmation_rate: float,
	 *     rejection_rate: float,
	 *     abandonment_rate: float
	 * }>
	 */
	public static function get_top_posts( string $range = 'total', int $limit = 10 ): array {
		global $wpdb;

		$table     = Age_Gate_Events_Table::table_name();
		$sql_limit = max( 1, min( 100, $limit ) );
		$where     = '';

		if ( 'total' !== $range ) {
			$since = self::range_to_period_since( $range );
			$where = $wpdb->prepare( ' WHERE period >= %s', $since );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					post_id,
					COALESCE(SUM(CASE WHEN event_type = 'shown' THEN count ELSE 0 END), 0) AS shown,
					COALESCE(SUM(CASE WHEN event_type = 'confirmed' THEN count ELSE 0 END), 0) AS confirmed,
					COALESCE(SUM(CASE WHEN event_type = 'rejected' THEN count ELSE 0 END), 0) AS rejected
				 FROM %i{$where}
				 GROUP BY post_id
				 HAVING shown > 0
				 ORDER BY shown DESC
				 LIMIT %d",
				$table,
				$sql_limit
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$post_ids = array_map(
			static function ( array $r ): int {
				return (int) $r['post_id'];
			},
			$rows
		);

		// Batch fetch de posts para evitar N+1 queries.
		$posts = get_posts(
			array(
				'post__in'            => $post_ids,
				'post_type'           => 'any',
				'post_status'         => 'any',
				'posts_per_page'      => count( $post_ids ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		$post_titles = array();
		$permalinks  = array();
		foreach ( $posts as $p ) {
			$post_titles[ $p->ID ] = $p->post_title;
			$permalinks[ $p->ID ]  = get_permalink( $p->ID );
		}

		$results = array();
		foreach ( $rows as $row ) {
			$post_id   = (int) $row['post_id'];
			$shown     = (int) $row['shown'];
			$confirmed = (int) $row['confirmed'];
			$rejected  = (int) $row['rejected'];

			$rates = self::calculate_rates( $shown, $confirmed, $rejected );

			$title     = $post_titles[ $post_id ] ?? sprintf(
				/* translators: %d: ID de la publicación */
				__( '(Publicación eliminada #%d)', 'wp-affiliatemanager' ),
				$post_id
			);
			$permalink = $permalinks[ $post_id ] ?? '';

			$results[] = array_merge(
				array(
					'post_id'   => $post_id,
					'title'     => $title,
					'permalink' => $permalink,
				),
				$rates
			);
		}

		return $results;
	}

	/**
	 * Versión cacheada de get_top_posts().
	 *
	 * @since  2.2.0
	 * @param  string $range today|week|month|year|total.
	 * @param  int    $limit
	 * @return array
	 */
	public static function get_top_posts_cached( string $range = 'total', int $limit = 10 ): array {
		$cache_key = 'wpam_age_gate_top_posts_' . $range . '_' . $limit;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$posts = self::get_top_posts( $range, $limit );
		wp_cache_set( $cache_key, $posts, self::CACHE_GROUP, self::CACHE_TTL );

		return $posts;
	}

	// -------------------------------------------------------------------------
	// Desglose por UTM Source
	// -------------------------------------------------------------------------

	/**
	 * Retorna el desglose de actividad del Age Gate clasificado por utm_source.
	 *
	 * Permite auditar el comportamiento de los visitantes según su procedencia (redes sociales,
	 * tráfico directo, buscadores, campañas específicas).
	 *
	 * @since  2.2.0
	 * @param  string $range today|week|month|year|total. Default 'total'.
	 * @param  int    $limit Número máximo de orígenes UTM. Default 20.
	 * @return array<int, array{
	 *     utm_source: string,
	 *     shown: int,
	 *     confirmed: int,
	 *     rejected: int,
	 *     abandoned_estimated: int,
	 *     confirmation_rate: float,
	 *     rejection_rate: float,
	 *     abandonment_rate: float
	 * }>
	 */
	public static function get_utm_breakdown( string $range = 'total', int $limit = 20 ): array {
		global $wpdb;

		$table     = Age_Gate_Events_Table::table_name();
		$sql_limit = max( 1, min( 100, $limit ) );
		$where     = '';

		if ( 'total' !== $range ) {
			$since = self::range_to_period_since( $range );
			$where = $wpdb->prepare( ' WHERE period >= %s', $since );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					utm_source,
					COALESCE(SUM(CASE WHEN event_type = 'shown' THEN count ELSE 0 END), 0) AS shown,
					COALESCE(SUM(CASE WHEN event_type = 'confirmed' THEN count ELSE 0 END), 0) AS confirmed,
					COALESCE(SUM(CASE WHEN event_type = 'rejected' THEN count ELSE 0 END), 0) AS rejected
				 FROM %i{$where}
				 GROUP BY utm_source
				 ORDER BY shown DESC
				 LIMIT %d",
				$table,
				$sql_limit
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$utm_source = (string) $row['utm_source'];
			$shown      = (int) $row['shown'];
			$confirmed  = (int) $row['confirmed'];
			$rejected   = (int) $row['rejected'];

			$rates = self::calculate_rates( $shown, $confirmed, $rejected );

			$results[] = array_merge(
				array( 'utm_source' => $utm_source ),
				$rates
			);
		}

		return $results;
	}

	/**
	 * Versión cacheada de get_utm_breakdown().
	 *
	 * @since  2.2.0
	 * @param  string $range today|week|month|year|total.
	 * @param  int    $limit
	 * @return array
	 */
	public static function get_utm_breakdown_cached( string $range = 'total', int $limit = 20 ): array {
		$cache_key = 'wpam_age_gate_utm_' . $range . '_' . $limit;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$breakdown = self::get_utm_breakdown( $range, $limit );
		wp_cache_set( $cache_key, $breakdown, self::CACHE_GROUP, self::CACHE_TTL );

		return $breakdown;
	}

	// -------------------------------------------------------------------------
	// Totales Diarios / Serie Cronológica
	// -------------------------------------------------------------------------

	/**
	 * Retorna la serie cronológica diaria de eventos entre dos fechas YYYYMMDD.
	 *
	 * Especialmente diseñado para gráficos temporales de evolución de interstitials.
	 *
	 * @since  2.2.0
	 * @param  string $since Fecha inicial en formato YYYYMMDD.
	 * @param  string $until Fecha final en formato YYYYMMDD.
	 * @return array<string, array{
	 *     period: string,
	 *     shown: int,
	 *     confirmed: int,
	 *     rejected: int,
	 *     abandoned_estimated: int,
	 *     confirmation_rate: float,
	 *     rejection_rate: float,
	 *     abandonment_rate: float
	 * }> Indexado por period (YYYYMMDD).
	 */
	public static function get_daily_totals( string $since, string $until ): array {
		global $wpdb;

		$table = Age_Gate_Events_Table::table_name();
		$since = preg_replace( '/[^0-9]/', '', $since );
		$until = preg_replace( '/[^0-9]/', '', $until );

		if ( 8 !== strlen( $since ) || 8 !== strlen( $until ) ) {
			return array();
		}

		if ( $since > $until ) {
			$tmp   = $since;
			$since = $until;
			$until = $tmp;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					period,
					COALESCE(SUM(CASE WHEN event_type = 'shown' THEN count ELSE 0 END), 0) AS shown,
					COALESCE(SUM(CASE WHEN event_type = 'confirmed' THEN count ELSE 0 END), 0) AS confirmed,
					COALESCE(SUM(CASE WHEN event_type = 'rejected' THEN count ELSE 0 END), 0) AS rejected
				 FROM %i
				 WHERE period >= %s AND period <= %s
				 GROUP BY period
				 ORDER BY period ASC",
				$table,
				$since,
				$until
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$period    = (string) $row['period'];
			$shown     = (int) $row['shown'];
			$confirmed = (int) $row['confirmed'];
			$rejected  = (int) $row['rejected'];

			$rates = self::calculate_rates( $shown, $confirmed, $rejected );

			$results[ $period ] = array_merge(
				array( 'period' => $period ),
				$rates
			);
		}

		return $results;
	}

	/**
	 * Versión cacheada de get_daily_totals().
	 *
	 * @since  2.2.0
	 * @param  string $since Fecha inicial YYYYMMDD.
	 * @param  string $until Fecha final YYYYMMDD.
	 * @return array
	 */
	public static function get_daily_totals_cached( string $since, string $until ): array {
		$cache_key = 'wpam_age_gate_daily_' . $since . '_' . $until;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$daily = self::get_daily_totals( $since, $until );
		wp_cache_set( $cache_key, $daily, self::CACHE_GROUP, self::CACHE_TTL );

		return $daily;
	}

	// -------------------------------------------------------------------------
	// Helpers de cálculo y rangos de fecha
	// -------------------------------------------------------------------------

	/**
	 * Calcula de forma segura las tasas porcentuales y el abandono estimado,
	 * garantizando protección estricta contra división por cero.
	 *
	 * @since  2.2.0
	 * @param  int $shown     Total de veces que el interstitial fue mostrado.
	 * @param  int $confirmed Total de confirmaciones (mayor de edad).
	 * @param  int $rejected  Total de rechazos (menor de edad).
	 * @return array{
	 *     shown: int,
	 *     confirmed: int,
	 *     rejected: int,
	 *     abandoned_estimated: int,
	 *     confirmation_rate: float,
	 *     rejection_rate: float,
	 *     abandonment_rate: float
	 * }
	 */
	public static function calculate_rates( int $shown, int $confirmed, int $rejected ): array {
		$shown     = max( 0, $shown );
		$confirmed = max( 0, $confirmed );
		$rejected  = max( 0, $rejected );

		// El abandono es una estimación de visitas no resueltas (shown - decisióń explícita).
		$abandoned_estimated = max( 0, $shown - ( $confirmed + $rejected ) );

		if ( $shown > 0 ) {
			$confirmation_rate = round( ( $confirmed / $shown ) * 100, 2 );
			$rejection_rate    = round( ( $rejected / $shown ) * 100, 2 );
			$abandonment_rate  = round( ( $abandoned_estimated / $shown ) * 100, 2 );
		} else {
			$confirmation_rate = 0.0;
			$rejection_rate    = 0.0;
			$abandonment_rate  = 0.0;
		}

		return array(
			'shown'               => $shown,
			'confirmed'           => $confirmed,
			'rejected'            => $rejected,
			'abandoned_estimated' => $abandoned_estimated,
			'confirmation_rate'   => $confirmation_rate,
			'rejection_rate'      => $rejection_rate,
			'abandonment_rate'    => $abandonment_rate,
		);
	}

	/**
	 * Convierte un rango de texto al valor 'period' (YYYYMMDD) en la zona horaria del sitio.
	 *
	 * Reutiliza Top_Posts_Query::range_to_period_since() para today/week/month
	 * e implementa 'year' acorde al timezone de WordPress.
	 *
	 * @since  2.2.0
	 * @param  string $range today|week|month|year|total.
	 * @return string YYYYMMDD.
	 */
	public static function range_to_period_since( string $range ): string {
		if ( 'year' === $range ) {
			$timezone = wp_timezone();
			$now      = current_datetime()->setTimezone( $timezone )->setTime( 0, 0, 0 );
			$since    = $now->modify( '-365 days' );
			return wp_date( 'Ymd', $since->getTimestamp() );
		}

		if ( in_array( $range, array( 'today', 'week', 'month' ), true ) ) {
			return Top_Posts_Query::range_to_period_since( $range );
		}

		return '19700101';
	}
}
