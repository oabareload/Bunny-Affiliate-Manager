<?php
/**
 * Render Engine — motor de renderizado frontend.
 *
 * Centraliza toda la lógica de generación HTML de los affiliate links
 * para el frontend público. Soporta:
 *
 *  - Inyección automática vía filtro the_content (after/before_content).
 *  - Shortcode [wpam_links] con parámetros style y post_id.
 *  - Helpers públicos wpam_render_links() / wpam_get_rendered_links().
 *  - Templates vertical / horizontal con override desde el tema.
 *  - Respeto completo de providers huérfanos (se omiten silenciosamente).
 *
 * @package WP_AffiliateManager\Frontend
 * @since   4.0.0
 */

namespace WP_AffiliateManager\Frontend;

use WP_AffiliateManager\Frontend\Layouts\Layout_Registry;

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Render_Engine
 *
 * Motor de renderizado. No tiene estado de instancia más allá del
 * caché en memoria para el request actual. Se instancia una sola vez
 * desde Plugin::define_frontend_hooks().
 *
 * @since 4.0.0
 */
class Render_Engine {

	/**
	 * Caché en memoria de HTML ya generado por post_id + style.
	 * Evita recalcular si the_content se ejecuta varias veces.
	 *
	 * @since 4.0.0
	 * @var   array<string, string>
	 */
	private array $cache = array();

	// ---------------------------------------------------------------------------
	// Registro de hooks
	// ---------------------------------------------------------------------------

	/**
	 * Registra el filtro the_content y el shortcode según las settings.
	 * Llamado desde Frontend::init() en el hook 'wp'.
	 *
	 * Nota de arquitectura: add_shortcode() y add_filter() se llaman directamente
	 * aquí en lugar de pasar por el Loader centralizado del plugin. Esto es
	 * intencional: register() se ejecuta dentro del hook 'wp', que ocurre después
	 * de que Loader::run() ya se ejecutó en Plugin::__construct(). En ese punto
	 * del ciclo de WordPress no es posible encolar nuevos hooks al Loader.
	 * Ver Plugin::define_frontend_hooks() → Loader::add_action('wp', $frontend, 'init').
	 *
	 * @since  4.0.0
	 * @return void
	 */
	public function register(): void {
		// Shortcode siempre registrado (independiente del render_mode).
		add_shortcode( 'wpam_links', array( $this, 'shortcode' ) );

		$render_mode = wpam_get_option( 'general.render_mode', 'after_content' );

		if ( 'after_content' === $render_mode ) {
			add_filter( 'the_content', array( $this, 'inject_after_content' ), 20 );
		} elseif ( 'before_content' === $render_mode ) {
			add_filter( 'the_content', array( $this, 'inject_before_content' ), 20 );
		}
		// 'disabled' y 'shortcode_only' no registran filtros sobre the_content.
	}

	// ---------------------------------------------------------------------------
	// Filtros the_content
	// ---------------------------------------------------------------------------

	/**
	 * Inyecta el bloque de links DESPUÉS del contenido del post.
	 *
	 * @since  4.0.0
	 * @param  string $content Contenido original del post.
	 * @return string Contenido con el bloque de afiliados añadido al final.
	 */
	public function inject_after_content( string $content ): string {
		$html = $this->get_html_for_current_post();

		if ( '' === $html ) {
			return $content;
		}

		return $content . $html;
	}

	/**
	 * Inyecta el bloque de links ANTES del contenido del post.
	 *
	 * @since  4.0.0
	 * @param  string $content Contenido original del post.
	 * @return string Contenido con el bloque de afiliados añadido al principio.
	 */
	public function inject_before_content( string $content ): string {
		$html = $this->get_html_for_current_post();

		if ( '' === $html ) {
			return $content;
		}

		return $html . $content;
	}

	// ---------------------------------------------------------------------------
	// Shortcode [wpam_links]
	// ---------------------------------------------------------------------------

	/**
	 * Callback del shortcode [wpam_links].
	 *
	 * Atributos soportados:
	 *   style   = 'vertical' | 'horizontal'  (default: setting global)
	 *   post_id = int                         (default: post actual)
	 *
	 * Ejemplo de uso:
	 *   [wpam_links]
	 *   [wpam_links style="horizontal"]
	 *   [wpam_links post_id="42" style="vertical"]
	 *
	 * @since  4.0.0
	 * @param  array  $atts    Atributos del shortcode.
	 * @param  string $content Contenido interior (no usado).
	 * @return string HTML del bloque de afiliados.
	 */
	public function shortcode( array $atts = array(), string $content = '' ): string {
		$default_style = wpam_get_option( 'appearance.link_style', 'vertical' );

		$atts = shortcode_atts(
			array(
				'style'   => $default_style,
				'post_id' => 0,
			),
			$atts,
			'wpam_links'
		);

		$post_id = absint( $atts['post_id'] );
		$style   = in_array( $atts['style'], array( 'vertical', 'horizontal' ), true )
			? $atts['style']
			: $default_style;

		// Si no se especificó post_id, usar el post actual.
		if ( 0 === $post_id ) {
			$post_id = get_the_ID() ?: 0;
		}

		if ( $post_id <= 0 ) {
			return '';
		}

		return $this->get_html( $post_id, $style );
	}

	// ---------------------------------------------------------------------------
	// API pública de renderizado
	// ---------------------------------------------------------------------------

	/**
	 * Renderiza e imprime los links de afiliado de un post.
	 *
	 * Helper público para usar en temas y templates:
	 *   wpam_render_links( get_the_ID() );
	 *   wpam_render_links( get_the_ID(), 'horizontal' );
	 *
	 * @since  4.0.0
	 * @param  int    $post_id ID del post.
	 * @param  string $style   'vertical' | 'horizontal'.
	 * @return void
	 */
	public function render( int $post_id, string $style = '' ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->get_html( $post_id, $style );
	}

	/**
	 * Retorna el HTML de los links de afiliado de un post (sin imprimir).
	 *
	 * Helper público para usar en temas, plugins y shortcodes externos:
	 *   $html = wpam_get_rendered_links( get_the_ID() );
	 *
	 * @since  4.0.0
	 * @param  int    $post_id ID del post.
	 * @param  string $style   'vertical' | 'horizontal'.
	 * @return string HTML listo para imprimir, o cadena vacía si no hay links.
	 */
	public function get_html( int $post_id, string $style = '' ): string {
		if ( $post_id <= 0 ) {
			return '';
		}

		// Normalizar style.
		if ( ! in_array( $style, array( 'vertical', 'horizontal' ), true ) ) {
			$style = wpam_get_option( 'appearance.link_style', 'vertical' );
		}

		// Caché en memoria para el request actual.
		$cache_key = $post_id . '|' . $style;
		if ( isset( $this->cache[ $cache_key ] ) ) {
			return $this->cache[ $cache_key ];
		}

		// Obtener links activos del post, incluyendo fallback por Default URL
		// (excluir orphans; Post_Links::get_links() prioriza siempre el link
		// específico del post sobre el Default URL del afiliado).
		$links = wpam_get_resolved_post_links( $post_id, true );

		if ( empty( $links ) ) {
			$this->cache[ $cache_key ] = '';
			return '';
		}

		// Cargar assets si aún no fueron encolados (ej: render tardío por shortcode).
		$this->maybe_enqueue_assets();

		// Generar HTML.
		$html = $this->build_html( $post_id, $links, $style );

		/**
		 * Filtra el HTML final del bloque de afiliados.
		 *
		 * @since 4.0.0
		 * @param string $html    HTML generado.
		 * @param int    $post_id ID del post.
		 * @param string $style   Estilo aplicado ('vertical' | 'horizontal').
		 * @param array  $links   Links activos del post.
		 */
		$html = (string) apply_filters( 'wpam_rendered_links_html', $html, $post_id, $style, $links );

		$this->cache[ $cache_key ] = $html;
		return $html;
	}

	// ---------------------------------------------------------------------------
	// Construcción de HTML
	// ---------------------------------------------------------------------------

	/**
	 * Construye el HTML del bloque completo de links de un post.
	 *
	 * v1.6.0: esta clase ya NO conoce cómo se ve un Card ni un Showcase.
	 * Su única responsabilidad aquí es: (1) preparar las opciones planas que
	 * necesitan los layouts, (2) resolver cuál layout está activo vía
	 * Layout_Registry, (3) delegar el render, y (4) anteponer el título de
	 * sección compartido (ver build_section_heading()) si está activado.
	 * Agregar un layout nuevo en el futuro no requiere tocar este método.
	 *
	 * @since  4.0.0
	 * @since  1.6.0 Delega a Layout_Registry en lugar de renderizar Card inline.
	 * @param  int    $post_id ID del post.
	 * @param  array  $links   Links activos normalizados.
	 * @param  string $style   'vertical' | 'horizontal' (solo relevante para Layout_Card).
	 * @return string HTML del bloque.
	 */
	private function build_html( int $post_id, array $links, string $style ): string {
		$options = get_option( WPAM_OPTION_KEY, array() );

		// Opciones planas compartidas por todos los layouts: opciones de botón
		// (display_content, cta_text, cta_hidden, frontend_order, showcase, etc.
		// desde `appearance`) + link_target/nofollow desde `general` + el style
		// resuelto por get_html()/shortcode (solo lo usa Layout_Card).
		$render_options = array_merge(
			$options['appearance'] ?? array(),
			array(
				'link_target' => $options['general']['link_target'] ?? '_blank',
				'nofollow'    => ! empty( $options['general']['nofollow'] ),
				'link_style'  => $style,
			)
		);

		$layout_id = wpam_get_option( 'appearance.layout', Layout_Registry::DEFAULT_LAYOUT );
		$layout    = Layout_Registry::get( $layout_id );

		$layout_html = $layout->render( $post_id, $links, $render_options );

		if ( '' === $layout_html ) {
			return '';
		}

		return $this->build_section_heading( $options ) . $layout_html;
	}

	/**
	 * Construye el título de sección, compartido por TODOS los layouts.
	 *
	 * Único punto de renderizado del heading — así Card y Showcase (y
	 * cualquier layout futuro) lo obtienen gratis sin duplicar 3 campos de
	 * Settings por layout. Ver Settings: appearance.section_heading.
	 *
	 * @since  1.6.0
	 * @param  array $options Opciones completas del plugin (get_option( WPAM_OPTION_KEY )).
	 * @return string HTML del heading, o '' si está desactivado o sin texto.
	 */
	private function build_section_heading( array $options ): string {
		$heading = $options['appearance']['section_heading'] ?? array();

		if ( empty( $heading['enabled'] ) ) {
			return '';
		}

		$text = trim( (string) ( $heading['text'] ?? '' ) );
		if ( '' === $text ) {
			return '';
		}

		$allowed_tags = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div' );
		$tag          = in_array( $heading['tag'] ?? '', $allowed_tags, true ) ? $heading['tag'] : 'h2';

		return sprintf(
			'<%1$s class="wpam-section-heading">%2$s</%1$s>',
			esc_attr( $tag ),
			esc_html( $text )
		);
	}

	// ---------------------------------------------------------------------------
	// Helpers internos
	// ---------------------------------------------------------------------------

	/**
	 * Obtiene el HTML para el post actual (usado por inject_after/before_content).
	 * Solo renderiza en singular posts para evitar loops de archivo.
	 *
	 * @since  4.0.0
	 * @return string HTML o cadena vacía.
	 */
	private function get_html_for_current_post(): string {
		// Solo en entradas/páginas individuales.
		if ( ! is_singular() ) {
			return '';
		}

		// Evitar el loop de archive o feeds.
		if ( ! in_the_loop() ) {
			return '';
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$style = wpam_get_option( 'appearance.link_style', 'vertical' );
		return $this->get_html( $post_id, $style );
	}

	/**
	 * Encola los assets del frontend si aún no están encolados.
	 * Útil cuando el shortcode se ejecuta fuera del hook wp_enqueue_scripts.
	 *
	 * @since  4.0.0
	 * @since  1.6.0 Encola también showcase.css cuando el layout activo es Showcase.
	 * @return void
	 */
	private function maybe_enqueue_assets(): void {
		if ( ! wp_style_is( 'wpam-frontend-styles', 'enqueued' ) ) {
			wp_enqueue_style(
				'wpam-frontend-styles',
				WPAM_PLUGIN_URL . 'assets/css/frontend.css',
				array(),
				WPAM_VERSION
			);
		}

		$layout_id = wpam_get_option( 'appearance.layout', Layout_Registry::DEFAULT_LAYOUT );
		if ( 'showcase' === $layout_id && ! wp_style_is( 'wpam-showcase-styles', 'enqueued' ) ) {
			wp_enqueue_style(
				'wpam-showcase-styles',
				WPAM_PLUGIN_URL . 'assets/css/showcase.css',
				array( 'wpam-frontend-styles' ),
				WPAM_VERSION
			);
		}
	}
}
