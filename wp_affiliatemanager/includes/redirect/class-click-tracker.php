<?php
/**
 * Click Tracker — registra cada click en la tabla SQL {prefix}wpam_clicks.
 *
 * Todo el storage es SQL nativo. No existe fallback a post meta.
 *
 * Datos registrados por click:
 *  - ts              DATETIME (DEFAULT CURRENT_TIMESTAMP en DB)
 *  - post_id         ID del post donde estaba el link
 *  - affiliate_id    ID del afiliado (wpam_affiliate post ID)
 *  - destination_url URL de destino del redirect
 *  - referer         Referer HTTP sanitizado (puede ser null)
 *  - ip_hash         HMAC-SHA256 de la IP con wp_salt() — nunca la IP real
 *  - user_agent      User agent sanitizado (puede ser null)
 *
 * @package WP_AffiliateManager\Redirect
 * @since   0.2.1
 * @version 0.2.2
 */

namespace WP_AffiliateManager\Redirect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Click_Tracker
 *
 * @since 0.2.1
 */
class Click_Tracker {

	// -------------------------------------------------------------------------
	// Registro de click
	// -------------------------------------------------------------------------

	/**
	 * Registra un click insertando una fila en la tabla SQL.
	 *
	 * La IP nunca se guarda en texto plano. Se usa HMAC-SHA256 con wp_salt()
	 * para que el hash sea único por instalación y no reversible externamente.
	 *
	 * El campo ts usa DEFAULT CURRENT_TIMESTAMP de la DB para evitar
	 * dependencia de la timezone configurada en PHP.
	 *
	 * @since  0.2.1
	 *
	 * @param  int    $post_id      ID del post donde estaba el link.
	 * @param  int    $affiliate_id ID del afiliado (post ID de wpam_affiliate).
	 * @param  string $url          URL de destino del redirect.
	 * @return bool   True si el insert fue exitoso.
	 */
	public function record( int $post_id, int $affiliate_id, string $url ): bool {
		if ( $post_id <= 0 || $affiliate_id <= 0 || '' === $url ) {
			return false;
		}

		global $wpdb;

		// Referer — wp_get_raw_referer() ya sanitiza contra inyecciones básicas.
		$referer = wp_get_raw_referer();
		$referer = $referer ? sanitize_url( $referer ) : null;

		// IP hasheada con HMAC-SHA256 + wp_salt(). Nunca se guarda la IP real.
		$raw_ip  = $_SERVER['REMOTE_ADDR'] ?? '';
		$ip_hash = '' !== $raw_ip
			? hash_hmac( 'sha256', $raw_ip, wp_salt() )
			: null;

		// User agent sanitizado.
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: null;

		$inserted = $wpdb->insert(
			Clicks_Table::table_name(),
			array(
				'post_id'         => $post_id,
				'affiliate_id'    => $affiliate_id,
				'destination_url' => $url,
				'referer'         => $referer,
				'ip_hash'         => $ip_hash,
				'user_agent'      => $user_agent,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return false !== $inserted;
	}

	// -------------------------------------------------------------------------
	// Lectura de clicks
	// -------------------------------------------------------------------------

	/**
	 * Retorna todos los clicks de un afiliado, ordenados por fecha descendente.
	 *
	 * Sin UI todavía. Punto de entrada para estadísticas futuras.
	 *
	 * @since  0.2.1
	 *
	 * @param  int $affiliate_id ID del afiliado.
	 * @return array[] Lista de clicks como arrays asociativos. Vacío si no hay.
	 */
	public function get_clicks( int $affiliate_id ): array {
		if ( $affiliate_id <= 0 ) {
			return array();
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, ts, post_id, affiliate_id, destination_url, referer, ip_hash, user_agent
				 FROM %i
				 WHERE affiliate_id = %d
				 ORDER BY ts DESC',
				Clicks_Table::table_name(),
				$affiliate_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Retorna el total de clicks de un afiliado.
	 *
	 * @since  0.2.1
	 *
	 * @param  int $affiliate_id ID del afiliado.
	 * @return int
	 */
	public function count( int $affiliate_id ): int {
		if ( $affiliate_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE affiliate_id = %d',
				Clicks_Table::table_name(),
				$affiliate_id
			)
		);
	}
}
