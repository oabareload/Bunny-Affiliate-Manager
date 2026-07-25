<?php
/**
 * Layout: Showcase.
 *
 * Card grande única: imagen a la izquierda (desktop) / arriba (mobile),
 * título + descripción a la derecha / debajo, fila de botones de afiliado
 * al final. Los botones reutilizan exactamente el mismo componente y las
 * mismas opciones que Layout_Card (Button_Row) — no hay un sistema de
 * botones paralelo.
 *
 * @package WP_AffiliateManager\Frontend\Layouts
 * @since   1.6.0
 */

namespace WP_AffiliateManager\Frontend\Layouts;

use WP_AffiliateManager\Frontend\Components\Button_Row;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Layout_Showcase
 *
 * @since 1.6.0
 */
class Layout_Showcase implements Layout_Interface {

	/**
	 * @since  1.6.0
	 * @return string
	 */
	public static function id(): string {
		return 'showcase';
	}

	/**
	 * @since  1.6.0
	 * @param  int     $post_id ID del post.
	 * @param  array[] $links   Links ya resueltos.
	 * @param  array   $options Opciones completas de `appearance` + `general`.
	 * @return string
	 */
	public function render( int $post_id, array $links, array $options ): string {
		if ( empty( $links ) ) {
			return '';
		}

		$button_row = new Button_Row();
		$items_html = $button_row->render( $post_id, $links, $options );

		// Sin botones válidos no hay nada que mostrar — mismo criterio que Card.
		if ( '' === $items_html ) {
			return '';
		}

		$showcase_opts = $options['showcase'] ?? array();

		$image_url = $this->resolve_image( $post_id, $showcase_opts );
		$title     = $this->resolve_title( $post_id, $showcase_opts );
		$excerpt   = $this->resolve_description( $post_id, $showcase_opts );

		$template_engine = new \WP_AffiliateManager\Templates\Templates();
		$html            = $template_engine->render(
			'showcase-block',
			array(
				'post_id'     => $post_id,
				'image_url'   => $image_url,
				'title'       => $title,
				'description' => $excerpt,
				'items_html'  => $items_html,
			),
			true
		);

		return $html ?? '';
	}

	/**
	 * Resuelve la imagen del showcase.
	 *
	 * @since  1.6.0
	 * @param  int   $post_id ID del post.
	 * @param  array $opts    Opciones de `appearance.showcase`.
	 * @return string URL de imagen o '' si no hay ninguna disponible.
	 */
	private function resolve_image( int $post_id, array $opts ): string {
		$source = $opts['image_source'] ?? 'featured';

		if ( 'custom' === $source ) {
			return esc_url_raw( (string) ( $opts['image_url'] ?? '' ) );
		}

		// 'featured' (default): imagen destacada del post. Sin fallback a
		// contenido — si el post no tiene featured image, el showcase se
		// muestra sin imagen (el template lo maneja de forma responsive).
		$thumbnail_url = get_the_post_thumbnail_url( $post_id, 'large' );
		return $thumbnail_url ?: '';
	}

	/**
	 * Resuelve el título del showcase.
	 *
	 * @since  1.6.0
	 * @param  int   $post_id ID del post.
	 * @param  array $opts    Opciones de `appearance.showcase`.
	 * @return string Título o '' si está oculto.
	 */
	private function resolve_title( int $post_id, array $opts ): string {
		$source = $opts['title_source'] ?? 'post';

		if ( 'hide' === $source ) {
			return '';
		}

		if ( 'custom' === $source ) {
			return (string) ( $opts['title_text'] ?? '' );
		}

		// 'post' (default).
		return get_the_title( $post_id );
	}

	/**
	 * Resuelve la descripción del showcase.
	 *
	 * Igual que el excerpt del related post en el interstitial (ver
	 * Settings::render_field_show_related_post_excerpt()): solo se usa el
	 * excerpt manual (post_excerpt). Nunca se genera automáticamente desde
	 * el contenido — mismo principio aplicado en todo el plugin.
	 *
	 * @since  1.6.0
	 * @param  int   $post_id ID del post.
	 * @param  array $opts    Opciones de `appearance.showcase`.
	 * @return string Descripción o '' si está oculta / no hay excerpt manual.
	 */
	private function resolve_description( int $post_id, array $opts ): string {
		$source = $opts['desc_source'] ?? 'excerpt';

		if ( 'hide' === $source ) {
			return '';
		}

		if ( 'custom' === $source ) {
			return (string) ( $opts['desc_text'] ?? '' );
		}

		// 'excerpt' (default): solo post_excerpt manual, nunca contenido automático.
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		return (string) $post->post_excerpt;
	}
}
