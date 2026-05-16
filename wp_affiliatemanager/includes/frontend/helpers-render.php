<?php
/**
 * Helpers globales del Render Engine — FASE 4.
 *
 * Funciones públicas para renderizar links de afiliado desde temas,
 * templates, shortcodes externos u otros plugins.
 *
 * @package WP_AffiliateManager
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imprime el HTML de los affiliate links de un post.
 *
 * Uso en templates de tema:
 *   wpam_render_links( get_the_ID() );
 *   wpam_render_links( get_the_ID(), 'horizontal' );
 *
 * @since  4.0.0
 * @param  int    $post_id ID del post. Si es 0, usa el post actual del loop.
 * @param  string $style   'vertical' | 'horizontal'. Vacío = leer desde settings.
 * @return void
 */
function wpam_render_links( int $post_id = 0, string $style = '' ): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wpam_get_rendered_links( $post_id, $style );
}

/**
 * Retorna el HTML de los affiliate links de un post (sin imprimir).
 *
 * Uso en templates de tema:
 *   $html = wpam_get_rendered_links( get_the_ID() );
 *   $html = wpam_get_rendered_links( get_the_ID(), 'horizontal' );
 *
 * @since  4.0.0
 * @param  int    $post_id ID del post. Si es 0, usa el post actual del loop.
 * @param  string $style   'vertical' | 'horizontal'. Vacío = leer desde settings.
 * @return string HTML listo para imprimir, o cadena vacía si no hay links activos.
 */
function wpam_get_rendered_links( int $post_id = 0, string $style = '' ): string {
	// Resolver post_id si no fue proporcionado.
	if ( 0 === $post_id ) {
		$post_id = get_the_ID() ?: 0;
	}

	if ( $post_id <= 0 ) {
		return '';
	}

	// Instanciar el engine directamente (stateless para llamadas externas).
	$engine = new WP_AffiliateManager\Frontend\Render_Engine();
	return $engine->get_html( $post_id, $style );
}
