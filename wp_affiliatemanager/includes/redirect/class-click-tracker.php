<?php
/**
 * Click Tracker — registra cada click en un affiliate link.
 *
 * Almacenamiento actual: post meta serializada en el post del afiliado.
 * Cada registro es un array completo con todos los datos relevantes para
 * facilitar la migración futura a una tabla SQL sin pérdida de información.
 *
 * Estructura de cada registro:
 * [
 *   'ts'           => (int)    Unix timestamp del click.
 *   'post_id'      => (int)    ID del post donde estaba el link.
 *   'affiliate_id' => (int)    ID del afiliado (wpam_affiliate post ID).
 *   'url'          => (string) URL de destino del redirect.
 * ]
 *
 * Los registros se guardan en la meta '_wpam_clicks' del post del afiliado.
 * Esto mantiene los datos cerca del objeto correcto y evita overhead
 * en los posts de contenido.
 *
 * Lo que NO hace todavía:
 *  - No limita el número de registros por afiliado.
 *  - No genera informes ni estadísticas.
 *  - No tiene UI.
 *  - No desduplicada clicks por IP o sesión.
 *
 * @package WP_AffiliateManager\Redirect
 * @since   0.2.0-alpha1
 */

namespace WP_AffiliateManager\Redirect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Click_Tracker
 *
 * @since 0.2.0-alpha1
 */
class Click_Tracker {

	/** Meta key donde se almacenan los clicks del afiliado. */
	const META_KEY = '_wpam_clicks';

	/**
	 * Registra un click en el meta del afiliado.
	 *
	 * El registro es atómico desde el punto de vista de WordPress:
	 * get_post_meta + update_post_meta dentro del mismo request.
	 * Para un volumen bajo/medio es suficiente. En fases futuras se
	 * reemplazará por INSERT en tabla propia con índices adecuados.
	 *
	 * @since  0.2.0-alpha1
	 * @param  int    $post_id      ID del post donde estaba el link.
	 * @param  int    $affiliate_id ID del afiliado (post ID de wpam_affiliate).
	 * @param  string $url          URL de destino del redirect.
	 * @return bool   True si el registro fue guardado correctamente.
	 */
	public function record( int $post_id, int $affiliate_id, string $url ): bool {
		if ( $post_id <= 0 || $affiliate_id <= 0 || '' === $url ) {
			return false;
		}

		$click = array(
			'ts'           => time(),
			'post_id'      => $post_id,
			'affiliate_id' => $affiliate_id,
			'url'          => $url,
		);

		// Obtener clicks existentes del afiliado.
		$clicks = get_post_meta( $affiliate_id, self::META_KEY, true );

		if ( ! is_array( $clicks ) ) {
			$clicks = array();
		}

		$clicks[] = $click;

		return (bool) update_post_meta( $affiliate_id, self::META_KEY, $clicks );
	}

	/**
	 * Retorna todos los clicks registrados de un afiliado.
	 *
	 * Usado internamente. Sin UI todavía.
	 * En fases futuras este método será el punto de entrada para
	 * las estadísticas y se reemplazará por una consulta SQL.
	 *
	 * @since  0.2.0-alpha1
	 * @param  int $affiliate_id ID del afiliado.
	 * @return array[] Lista de clicks. Array vacío si no hay registros.
	 */
	public function get_clicks( int $affiliate_id ): array {
		if ( $affiliate_id <= 0 ) {
			return array();
		}

		$clicks = get_post_meta( $affiliate_id, self::META_KEY, true );

		return is_array( $clicks ) ? $clicks : array();
	}

	/**
	 * Retorna el total de clicks de un afiliado.
	 *
	 * @since  0.2.0-alpha1
	 * @param  int $affiliate_id ID del afiliado.
	 * @return int
	 */
	public function count( int $affiliate_id ): int {
		return count( $this->get_clicks( $affiliate_id ) );
	}
}
