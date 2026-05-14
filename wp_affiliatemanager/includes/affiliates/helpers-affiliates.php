<?php
/**
 * Helpers globales de afiliados — FASE 2.
 *
 * Funciones públicas y reutilizables para interactuar con el sistema de afiliados
 * desde cualquier parte del plugin (templates, shortcodes, hooks, etc.).
 *
 * @package WP_AffiliateManager
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Helpers de consulta
// ---------------------------------------------------------------------------

/**
 * Retorna un afiliado normalizado por ID.
 *
 * @since  2.0.0
 * @param  int $affiliate_id ID del afiliado (wpam_affiliate post ID).
 * @return array|null Array normalizado o null si no existe / tipo incorrecto.
 */
function wpam_get_affiliate( int $affiliate_id ): ?array {
	$repo = new WP_AffiliateManager\Affiliates\Repository();
	return $repo->find( $affiliate_id );
}

/**
 * Retorna todos los afiliados registrados.
 *
 * @since  2.0.0
 * @param  array $args {
 *     Opciones de filtrado.
 *     @type bool   $active   Si true, solo afiliados activos. Default: false.
 *     @type int    $per_page Número de resultados. Default: -1 (todos).
 *     @type string $orderby  Campo de orden. Default: 'title'.
 *     @type string $order    'ASC' | 'DESC'. Default: 'ASC'.
 * }
 * @return array[] Lista de afiliados normalizados.
 */
function wpam_get_affiliates( array $args = array() ): array {
	$repo   = new WP_AffiliateManager\Affiliates\Repository();
	$result = $repo->find_all( $args );
	return $result['items'];
}

/**
 * Verifica si un afiliado está activo.
 *
 * @since  2.0.0
 * @param  int $affiliate_id ID del afiliado.
 * @return bool True si está activo, false si no existe o está inactivo.
 */
function wpam_is_affiliate_active( int $affiliate_id ): bool {
	$affiliate = wpam_get_affiliate( $affiliate_id );

	if ( null === $affiliate ) {
		return false;
	}

	return (bool) $affiliate['active'];
}

// ---------------------------------------------------------------------------
// URL Generator
// ---------------------------------------------------------------------------

/**
 * Genera una URL afiliada agregando el parámetro del afiliado.
 *
 * Detecta correctamente si la URL base ya tiene query params y agrega
 * el parámetro del afiliado sin romper la estructura existente.
 *
 * Ejemplos:
 *   wpam_generate_affiliate_url( 42, 'https://amazon.com/product' )
 *   → 'https://amazon.com/product?tag=bunny-20'
 *
 *   wpam_generate_affiliate_url( 42, 'https://amazon.com/product?color=red' )
 *   → 'https://amazon.com/product?color=red&tag=bunny-20'
 *
 *   wpam_generate_affiliate_url( 42, 'https://amazon.com/product?tag=other' )
 *   → 'https://amazon.com/product?tag=bunny-20'  (sobrescribe si ya existe)
 *
 * @since  2.0.0
 * @param  int    $affiliate_id ID del afiliado.
 * @param  string $url          URL base a la que se añadirá el parámetro.
 * @return string URL afiliada o URL original si el afiliado no es válido/activo.
 */
function wpam_generate_affiliate_url( int $affiliate_id, string $url ): string {
	// Validar URL de entrada.
	$url = esc_url_raw( trim( $url ) );
	if ( ! $url ) {
		return '';
	}

	// Obtener afiliado.
	$affiliate = wpam_get_affiliate( $affiliate_id );
	if ( null === $affiliate ) {
		return $url;
	}

	// Si el afiliado está inactivo, devolver URL sin modificar.
	if ( ! $affiliate['active'] ) {
		return $url;
	}

	$param = $affiliate['param'];
	$value = $affiliate['value'];

	// Si no tiene parámetro configurado, devolver URL sin modificar.
	if ( ! $param || ! $value ) {
		return $url;
	}

	// Permitir que otros módulos filtren la URL generada.
	$generated_url = add_query_arg( rawurlencode( $param ), rawurlencode( $value ), $url );

	/**
	 * Filtra la URL afiliada generada.
	 *
	 * @since 2.0.0
	 * @param string $generated_url URL con parámetro afiliado añadido.
	 * @param string $url           URL base original.
	 * @param array  $affiliate     Datos normalizados del afiliado.
	 */
	return (string) apply_filters( 'wpam_affiliate_url', $generated_url, $url, $affiliate );
}

/**
 * Genera una URL afiliada a partir del slug del afiliado (en lugar del ID).
 *
 * @since  2.0.0
 * @param  string $slug Slug interno del afiliado.
 * @param  string $url  URL base.
 * @return string URL afiliada o URL original si el slug no corresponde a ningún afiliado activo.
 */
function wpam_generate_affiliate_url_by_slug( string $slug, string $url ): string {
	$slug = sanitize_title( $slug );

	if ( ! $slug ) {
		return esc_url_raw( $url );
	}

	// Buscar el afiliado por meta slug.
	$posts = get_posts( array(
		'post_type'      => WP_AffiliateManager\Affiliates\CPT::POST_TYPE,
		'posts_per_page' => 1,
		'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'   => WP_AffiliateManager\Affiliates\Meta::KEY_SLUG,
				'value' => $slug,
			),
		),
		'fields'         => 'ids',
	) );

	if ( empty( $posts ) ) {
		return esc_url_raw( $url );
	}

	return wpam_generate_affiliate_url( (int) $posts[0], $url );
}
