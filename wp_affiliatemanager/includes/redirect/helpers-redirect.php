<?php
/**
 * Helpers globales del sistema de redirect.
 *
 * @package WP_AffiliateManager\Redirect
 * @since   0.2.0-alpha1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retorna la URL interna /go/{token} para un link específico de un post.
 *
 * Uso en templates:
 *   $url = wpam_go_url( get_the_ID(), $link['order'] );
 *
 * Si el token no puede generarse (post_id o link_index inválidos),
 * retorna cadena vacía. El llamador debe verificar antes de usarla.
 *
 * @since  0.2.0-alpha1
 * @param  int $post_id    ID del post.
 * @param  int $link_index Índice (order) del link dentro del post.
 * @return string URL interna tipo https://site.com/go/a3f8c12b, o '' si inválido.
 */
function wpam_go_url( int $post_id, int $link_index ): string {
	if ( $post_id <= 0 || $link_index < 0 ) {
		return '';
	}

	$manager = new WP_AffiliateManager\Redirect\Redirect_Manager();
	$token   = $manager->generate_token( $post_id, $link_index );

	return home_url( '/' . WP_AffiliateManager\Redirect\Redirect_Manager::SLUG . '/' . $token );
}

/**
 * Retorna la URL interna /goa/{post_id}/{affiliate_id}/ para un link de
 * fallback (Default URL) de un post.
 *
 * A diferencia de wpam_go_url(), no genera ni depende de ningún token
 * precalculado: la resolución ocurre en vivo en Redirect_Manager::resolve_default()
 * contra Post_Links::get_links(), así que no hay mapa que mantener sincronizado.
 *
 * Uso en Render_Engine, solo para links donde $link['_wpam_is_default'] === true:
 *   $url = wpam_go_default_url( $post_id, $link['provider_id'] );
 *
 * @since  1.5.0
 * @param  int $post_id      ID del post.
 * @param  int $affiliate_id ID del afiliado.
 * @return string URL interna tipo https://site.com/goa/123/45/, o '' si inválido.
 */
function wpam_go_default_url( int $post_id, int $affiliate_id ): string {
	if ( $post_id <= 0 || $affiliate_id <= 0 ) {
		return '';
	}

	return home_url( '/' . WP_AffiliateManager\Redirect\Redirect_Manager::SLUG_DEFAULT . '/' . $post_id . '/' . $affiliate_id );
}

/**
 * Retorna la URL interna /goext/{sig}/?u={url} para un enlace externo
 * genérico (no ligado a un afiliado).
 *
 * No usado internamente por el flujo de clicks (que firma bajo demanda vía
 * Redirect_Manager::ajax_sign_external(), disparado desde external-links.js
 * solo en el momento del click real). Se expone como helper público por si
 * un template u otro módulo necesita generar en PHP la misma URL firmada
 * para una URL cuya pertenencia al contenido ya se dio por válida.
 *
 * @since  1.8.9
 * @param  string $url URL externa absoluta (http/https).
 * @return string URL interna tipo https://site.com/goext/abcdef0123456789/?u=..., o '' si inválida.
 */
function wpam_goext_url( string $url ): string {
	$url = esc_url_raw( $url );
	if ( '' === $url ) {
		return '';
	}

	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return '';
	}

	$manager = new WP_AffiliateManager\Redirect\Redirect_Manager();
	$sig     = $manager->generate_external_signature( $url );

	return add_query_arg(
		'u',
		rawurlencode( $url ),
		home_url( '/' . WP_AffiliateManager\Redirect\Redirect_Manager::SLUG_EXTERNAL . '/' . $sig )
	);
}
