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
