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
	 * @since  1.8.16 Separa los links específicos de los generados por plantilla
	 *                (`_wpam_is_default`) en dos grupos; ver `template_links_text`.
	 * @param  int     $post_id      ID del post (para wpam_go_url()/wpam_go_default_url()).
	 * @param  array[] $links        Links ya resueltos y normalizados.
	 * @param  array   $button_opts  Subconjunto de `appearance`: 'display_content',
	 *                                'cta_text', 'cta_hidden', 'frontend_order',
	 *                                'template_links_text',
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

		// Origen EFECTIVO de cada link, tal como lo dejó Post_Links::get_links():
		// los links específicos del post no llevan marca y los generados desde la
		// plantilla de un afiliado llevan `_wpam_is_default` (ver merge_default_links(),
		// que ya omite la plantilla de un afiliado que tiene link específico, así que
		// una tienda nunca aparece en ambos grupos). Se particiona DESPUÉS del orden
		// anterior sin reordenar: dentro de cada grupo se conserva el orden actual
		// (alfabético o el del post, según `frontend_order`).
		$specific  = array();
		$generated = array();
		foreach ( $links as $link ) {
			if ( ! empty( $link['_wpam_is_default'] ) ) {
				$generated[] = $link;
			} else {
				$specific[] = $link;
			}
		}

		$template_engine = new \WP_AffiliateManager\Templates\Templates();
		$item_opts       = array(
			'link_target'     => $link_target,
			'rel'             => $rel,
			'display_content' => $display_content,
			'cta_text'        => $cta_text,
			'cta_hidden'      => $cta_hidden,
		);

		// Los grupos se miden por lo que REALMENTE se renderiza (un link orphan, sin
		// URL o sin template no cuenta), para no mostrar separadores huérfanos.
		$specific_html  = $this->render_items( $post_id, $specific, $template_engine, $item_opts );
		$generated_html = $this->render_items( $post_id, $generated, $template_engine, $item_opts );

		if ( '' === $generated_html ) {
			return $specific_html; // Sin grupo de plantillas: apariencia de siempre.
		}

		$text = array_key_exists( 'template_links_text', $button_opts )
			? trim( (string) $button_opts['template_links_text'] )
			: self::default_template_links_text();

		return $specific_html . $this->render_generated_intro( '' !== $specific_html, $text ) . $generated_html;
	}

	/**
	 * Texto por defecto que precede al grupo de links generados por plantillas.
	 *
	 * Única fuente del valor por defecto: lo usan este componente (cuando la opción
	 * `appearance.template_links_text` aún no se guardó) y el campo de Settings.
	 * Una opción guardada como cadena vacía es válida y significa "sin texto".
	 *
	 * @since  1.8.16
	 * @return string
	 */
	public static function default_template_links_text(): string {
		return __( 'También puedes buscar esta figura en estas tiendas:', 'wp-affiliatemanager' );
	}

	/**
	 * Separador discreto + texto antes del grupo de links de plantilla.
	 *
	 *  - Con links específicos delante: línea tenue (+ texto si lo hay).
	 *  - Sin links específicos: solo el texto, sin línea que no separaría nada;
	 *    y si tampoco hay texto, no se emite nada.
	 *
	 * El texto se escapa aquí, en el momento de mostrarlo.
	 *
	 * @since  1.8.16
	 * @param  bool   $has_specific Si hay links específicos renderizados antes.
	 * @param  string $text         Texto ya recortado ('' = sin texto).
	 * @return string HTML o ''.
	 */
	private function render_generated_intro( bool $has_specific, string $text ): string {
		if ( ! $has_specific && '' === $text ) {
			return '';
		}

		$class = 'wpam-links-divider' . ( $has_specific ? '' : ' wpam-links-divider--text-only' );
		$inner = '' !== $text
			? '<p class="wpam-links-divider-text">' . esc_html( $text ) . '</p>'
			: '';

		return '<div class="' . esc_attr( $class ) . '">' . $inner . '</div>';
	}

	/**
	 * Renderiza una lista de links como link-item (bucle original, sin cambios de
	 * lógica; solo se extrajo para poder renderizar cada grupo por separado).
	 *
	 * @since  1.8.16
	 * @param  int                                     $post_id         ID del post.
	 * @param  array[]                                 $links           Links del grupo, ya ordenados.
	 * @param  \WP_AffiliateManager\Templates\Templates $template_engine Motor de templates.
	 * @param  array                                   $item_opts       link_target, rel, display_content, cta_text, cta_hidden.
	 * @return string HTML concatenado o ''.
	 */
	private function render_items( int $post_id, array $links, \WP_AffiliateManager\Templates\Templates $template_engine, array $item_opts ): string {
		$link_target     = $item_opts['link_target'];
		$rel             = $item_opts['rel'];
		$display_content = $item_opts['display_content'];
		$cta_text        = $item_opts['cta_text'];
		$cta_hidden      = $item_opts['cta_hidden'];
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
