<?php
/**
 * Layout: Card.
 *
 * El layout original del plugin (única implementación real hasta 1.6.0,
 * antes vivía inline en Render_Engine::build_html()). Lista de botones de
 * afiliado envuelta en links-wrapper.php, orientación vertical u horizontal.
 *
 * Comportamiento sin cambios respecto a versiones anteriores: mismas
 * opciones, mismo HTML, mismos templates (link-item.php, links-wrapper.php).
 * Solo cambió DÓNDE vive el código, no QUÉ hace.
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
 * Class Layout_Card
 *
 * @since 1.6.0
 */
class Layout_Card implements Layout_Interface {

	/**
	 * @since  1.6.0
	 * @return string
	 */
	public static function id(): string {
		return 'card';
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

		$style = in_array( $options['link_style'] ?? '', array( 'vertical', 'horizontal' ), true )
			? $options['link_style']
			: 'vertical';

		$button_row = new Button_Row();
		$items_html = $button_row->render( $post_id, $links, $options );

		if ( '' === $items_html ) {
			return '';
		}

		$wrapper_class = 'wpam-links-wrapper wpam-style-' . esc_attr( $style );

		$template_engine = new \WP_AffiliateManager\Templates\Templates();
		$wrapper_html    = $template_engine->render(
			'links-wrapper',
			array(
				'items_html'    => $items_html,
				'style'         => $style,
				'wrapper_class' => $wrapper_class,
				'post_id'       => $post_id,
			),
			true
		);

		// Fallback si el template wrapper no existe.
		if ( null === $wrapper_html || '' === $wrapper_html ) {
			return '<div class="' . esc_attr( $wrapper_class ) . '">' . $items_html . '</div>';
		}

		return $wrapper_html;
	}
}
