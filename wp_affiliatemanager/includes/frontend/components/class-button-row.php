<?php
/**
 * Componente: Button_Row.
 *
 * Único punto donde se itera la lista de links resueltos de un post y se
 * renderiza cada uno como botón/card individual (template link-item.php).
 *
 * Usado por Layout_Card (todo el bloque es esto) y por Layout_Showcase (la
 * fila de botones bajo la imagen/título). Las opciones de visualización de
 * botón (logo, nombre, CTA, texto CTA, orden) son compartidas entre ambos
 * layouts a propósito — no existen configuraciones duplicadas por layout.
 *
 * @package WP_AffiliateManager\Frontend\Components
 * @since   1.6.0
 */

namespace WP_AffiliateManager\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Button_Row
 *
 * @since 1.6.0
 */
class Button_Row {

	/**
	 * Renderiza el HTML concatenado de todos los link-item válidos.
	 *
	 * Lógica movida sin cambios desde Render_Engine::build_html() (pre-1.6.0):
	 * mismo orden (preserve_post_order | alphabetical), misma omisión de
	 * orphans/URLs vacías, mismas variables pasadas al template link-item.
	 *
	 * @since  1.6.0
	 * @param  int     $post_id      ID del post (para wpam_go_url()/wpam_go_default_url()).
	 * @param  array[] $links        Links ya resueltos y normalizados.
	 * @param  array   $button_opts  Subconjunto de `appearance`: 'display_content',
	 *                                'cta_text', 'cta_hidden', 'frontend_order',
	 *                                más 'link_target'/'nofollow' desde `general`.
	 * @return string HTML concatenado (sin wrapper), o '' si no hay items válidos.
	 */
	public function render( int $post_id, array $links, array $button_opts ): string {
		$link_target = $button_opts['link_target'] ?? '_blank';
		$nofollow    = ! empty( $button_opts['nofollow'] );
		$rel         = $nofollow ? 'nofollow sponsored noopener noreferrer' : 'sponsored noopener noreferrer';

		$display_content = $button_opts['display_content'] ?? 'show_logo_and_name';
		$cta_text        = $button_opts['cta_text'] ?? 'Ver oferta';
		$cta_hidden      = ! empty( $button_opts['cta_hidden'] );
		$frontend_order  = $button_opts['frontend_order'] ?? 'preserve_post_order';

		// Reordenar solo en memoria para el render — NO toca DB ni drag/drop.
		if ( 'alphabetical' === $frontend_order ) {
			$site_locale = get_locale();
			usort(
				$links,
				function ( array $a, array $b ) use ( $site_locale ): int {
					$name_a = mb_strtolower( $this->get_affiliate_name( $a['provider_id'] ), 'UTF-8' );
					$name_b = mb_strtolower( $this->get_affiliate_name( $b['provider_id'] ), 'UTF-8' );
					return strcmp( $name_a, $name_b );
				}
			);
		}

		$template_engine = new \WP_AffiliateManager\Templates\Templates();
		$items_html      = '';

		foreach ( $links as $link ) {
			// Doble verificación: omitir orphans silenciosamente.
			if ( $link['_orphan'] || '' === $link['final_url'] ) {
				continue;
			}

			// Links de fallback (Default URL) usan /goa/{post_id}/{affiliate_id}/,
			// sin entrada en el mapa de tokens — ver Redirect_Manager::resolve_default().
			$go_url = ! empty( $link['_wpam_is_default'] )
				? wpam_go_default_url( $post_id, (int) $link['provider_id'] )
				: wpam_go_url( $post_id, (int) $link['order'] );
			if ( '' === $go_url ) {
				continue;
			}

			$label = '' !== $link['custom_label']
				? $link['custom_label']
				: $this->get_affiliate_name( $link['provider_id'] );

			$item_html = $template_engine->render(
				'link-item',
				array(
					'final_url'       => $go_url,
					'label'           => $label,
					'link_target'     => $link_target,
					'rel'             => $rel,
					'provider_id'     => $link['provider_id'],
					'affiliate'       => wpam_get_affiliate( $link['provider_id'] ),
					'display_content' => $display_content,
					'cta_text'        => $cta_text,
					'cta_hidden'      => $cta_hidden,
				),
				true
			);

			// Si el template no existe o retornó null, omitir este link
			// silenciosamente en lugar de concatenar null como string vacío.
			if ( null === $item_html ) {
				continue;
			}

			if ( '' !== $item_html ) {
				$items_html .= $item_html;
			}
		}

		return $items_html;
	}

	/**
	 * Retorna el nombre del afiliado por ID.
	 *
	 * @since  1.6.0
	 * @param  int $affiliate_id ID del afiliado.
	 * @return string Nombre o cadena vacía.
	 */
	private function get_affiliate_name( int $affiliate_id ): string {
		$affiliate = wpam_get_affiliate( $affiliate_id );
		return $affiliate['title'] ?? '';
	}
}
