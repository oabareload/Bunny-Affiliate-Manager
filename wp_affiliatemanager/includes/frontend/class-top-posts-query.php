<?php
/**
 * Top Posts Query — lógica compartida entre el Dashboard y el shortcode frontend.
 *
 * Extraída de Admin_Menu::get_top_posts() en v1.0.0 para que tanto el
 * dashboard de analytics como el shortcode [wpam_top_posts] consuman
 * exactamente la misma fuente de datos sin duplicar SQL.
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
	 * Retorna los posts con más clicks para el rango solicitado.
	 *
	 * @param  string $range  today|week|month|total
	 * @param  int    $limit  Número máximo de resultados. Default 10.
	 * @return array[]  Cada elemento: [ id, title, click_count, thumb_url, permalink ]
	 */
	public static function get( string $range = 'total', int $limit = 10 ): array {
		global $wpdb;

		$table = Clicks_Table::table_name();
		$limit = max( 1, min( 100, $limit ) );

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
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$result = array();
		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$result[] = array(
				'id'          => $post_id,
				'title'       => $post->post_title ?: __( '(no title)', 'wp-affiliatemanager' ),
				'click_count' => (int) $row['click_count'],
				'permalink'   => (string) get_permalink( $post_id ),
				// thumb_url se resuelve en tiempo de render con el tamaño solicitado.
			);
		}

		return $result;
	}

	/**
	 * Retorna los posts con más clicks, con caché de objeto.
	 *
	 * Fuente única de caché para shortcode y widget.
	 * TTL: 300 segundos. Clave: wpam_top_posts_{range}_{limit}.
	 * Sin object cache externo: vive dentro del request (evita queries duplicadas
	 * si shortcode y widget coexisten en la misma página con los mismos parámetros).
	 * Con object cache externo (Redis/Memcached): persiste entre requests.
	 *
	 * @param  string $range  today|week|month|total
	 * @param  int    $limit
	 * @return array[]
	 */
	public static function get_cached( string $range = 'total', int $limit = 10 ): array {
		$cache_key = 'wpam_top_posts_' . $range . '_' . $limit;
		$cached    = wp_cache_get( $cache_key, 'wpam' );

		if ( false !== $cached ) {
			return $cached;
		}

		$posts = self::get( $range, $limit );
		wp_cache_set( $cache_key, $posts, 'wpam', 300 );

		return $posts;
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
