<?php
/**
 * Shortcode [wpam_top_posts] — Widget de posts más clicados.
 *
 * Datos:    Top_Posts_Query::get_cached()
 * Render:   Top_Posts_Renderer::render()
 * Caché:    Top_Posts_Query::get_cached()
 *
 * Esta clase se ocupa únicamente de parsear y validar los atributos
 * del shortcode y delegar a la infraestructura compartida.
 *
 * Atributos:
 *   title           — Encabezado del widget. Vacío = sin encabezado.
 *   show_title      — true|false. Default: true.
 *   period          — today|week|month|total. Default: total.
 *   layout          — horizontal|vertical. Default: horizontal.
 *   thumbnail_size  — Cualquier tamaño WordPress registrado. Default: medium.
 *   limit           — Número de posts (1-100). Default: 10.
 *   max_width       — CSS max-width inline. Default: vacío.
 *   show_thumbnail  — yes|no. Default: yes.
 *
 * @package WP_AffiliateManager\Frontend
 * @since   1.0.0
 */

namespace WP_AffiliateManager\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shortcode_Top_Posts {

	/** Rangos válidos para el atributo period. */
	private const VALID_PERIODS = array( 'today', 'week', 'month', 'total' );

	/** Layouts válidos. */
	private const VALID_LAYOUTS = array( 'horizontal', 'vertical' );

	// -------------------------------------------------------------------------
	// Registro
	// -------------------------------------------------------------------------

	/**
	 * Registra el shortcode.
	 *
	 * Llamado desde class-plugin.php vía add_shortcode() directo
	 * (los shortcodes no se pueden registrar a través del Loader).
	 */
	public static function register(): void {
		add_shortcode( 'wpam_top_posts', array( __CLASS__, 'render' ) );
	}

	// -------------------------------------------------------------------------
	// Callback principal
	// -------------------------------------------------------------------------

	/**
	 * Callback del shortcode — retorna HTML.
	 *
	 * @param  array|string $atts  Atributos del shortcode.
	 * @return string
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'title'          => '',
				'show_title'     => 'true',
				'period'         => 'total',
				'layout'         => 'horizontal',
				'thumbnail_size' => 'medium',
				'limit'          => '10',
				'max_width'      => '',
				'show_thumbnail' => 'yes',
			),
			$atts,
			'wpam_top_posts'
		);

		// Sanitizar y validar atributos.
		$title          = sanitize_text_field( $atts['title'] );
		$show_title     = ( 'false' !== strtolower( trim( $atts['show_title'] ) ) );
		$period         = in_array( $atts['period'], self::VALID_PERIODS, true ) ? $atts['period'] : 'total';
		$layout         = in_array( $atts['layout'], self::VALID_LAYOUTS, true ) ? $atts['layout'] : 'horizontal';
		$limit          = max( 1, min( 100, (int) $atts['limit'] ) );
		$max_width      = sanitize_text_field( $atts['max_width'] );
		$show_thumbnail = ( 'no' !== strtolower( trim( $atts['show_thumbnail'] ) ) );

		// Validar thumbnail_size — delegado al renderer (fuente única de esta lógica).
		$thumbnail_size = Top_Posts_Renderer::resolve_thumbnail_size( sanitize_key( $atts['thumbnail_size'] ) );

		// Obtener posts — caché gestionada por Top_Posts_Query (fuente única).
		$posts = Top_Posts_Query::get_cached( $period, $limit );

		// Encolar CSS del widget si no está ya encolado.
		if ( ! wp_style_is( 'wpam-top-posts-widget', 'enqueued' ) ) {
			wp_enqueue_style( 'wpam-top-posts-widget' );
		}

		// Delegar render al renderer (fuente única de HTML de salida).
		return Top_Posts_Renderer::render( $posts, array(
			'title'          => $title,
			'show_title'     => $show_title,
			'layout'         => $layout,
			'thumbnail_size' => $thumbnail_size,
			'max_width'      => $max_width,
			'show_thumbnail' => $show_thumbnail,
		) );
	}
}
