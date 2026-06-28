<?php
/**
 * Shortcode [wpam_top_posts] — Widget de posts más clicados.
 *
 * Reutiliza Top_Posts_Query::get() que es la misma fuente de datos
 * que alimenta el Dashboard de Analytics.
 *
 * Atributos:
 *   title           — Encabezado del widget. Vacío = sin encabezado.
 *   show_title      — true|false  Muestra u oculta el título. Default: true.
 *   period          — today|week|month|total (default: total)
 *   layout          — horizontal|vertical (default: horizontal)
 *   thumbnail_size  — Cualquier tamaño WordPress registrado (default: medium)
 *   limit           — Número de posts (default: 10)
 *   max_width       — CSS max-width inline, ej. 800px, 100% (default: vacío)
 *   show_thumbnail  — yes|no (default: yes)
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

	/** Prefijo de caché. */
	private const CACHE_PREFIX = 'wpam_top_posts_';

	/** TTL de caché en segundos (5 minutos). */
	private const CACHE_TTL = 300;

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

		// --- Sanitizar y validar cada atributo ---

		$title          = sanitize_text_field( $atts['title'] );
		$show_title     = ( 'false' !== strtolower( trim( $atts['show_title'] ) ) );
		$period         = in_array( $atts['period'], self::VALID_PERIODS, true ) ? $atts['period'] : 'total';
		$layout         = in_array( $atts['layout'], self::VALID_LAYOUTS, true ) ? $atts['layout'] : 'horizontal';
		$limit          = max( 1, min( 100, (int) $atts['limit'] ) );
		$max_width      = sanitize_text_field( $atts['max_width'] );
		$show_thumbnail = ( 'no' !== strtolower( trim( $atts['show_thumbnail'] ) ) );

		// Corrección 3: validar thumbnail_size contra tamaños reales de WordPress.
		// Si el tamaño no existe, cae silenciosamente a 'thumbnail' (siempre disponible).
		$thumbnail_size = self::resolve_thumbnail_size( sanitize_key( $atts['thumbnail_size'] ) );

		// Obtener posts con caché (corrección 2).
		$posts = self::get_cached_posts( $period, $limit );

		// Encolar el CSS del widget si no está ya encolado.
		if ( ! wp_style_is( 'wpam-top-posts-widget', 'enqueued' ) ) {
			wp_enqueue_style( 'wpam-top-posts-widget' );
		}

		// Construir y devolver HTML.
		ob_start();
		self::render_widget( $posts, $title, $show_title, $layout, $thumbnail_size, $max_width, $show_thumbnail );
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Caché (corrección 2)
	// -------------------------------------------------------------------------

	/**
	 * Retorna los posts cacheados para el rango y límite dados.
	 *
	 * TTL: 300 segundos (5 minutos). Caché separada por period+limit.
	 * Se invalida automáticamente al expirar — sin caché permanente.
	 *
	 * @param  string $period  today|week|month|total
	 * @param  int    $limit
	 * @return array[]
	 */
	private static function get_cached_posts( string $period, int $limit ): array {
		$cache_key = self::CACHE_PREFIX . $period . '_' . $limit;
		$cached    = wp_cache_get( $cache_key, 'wpam' );

		if ( false !== $cached ) {
			return $cached;
		}

		$posts = Top_Posts_Query::get( $period, $limit );
		wp_cache_set( $cache_key, $posts, 'wpam', self::CACHE_TTL );

		return $posts;
	}

	// -------------------------------------------------------------------------
	// Validación de thumbnail_size (corrección 3)
	// -------------------------------------------------------------------------

	/**
	 * Resuelve un thumbnail_size a un valor garantizado existente en WordPress.
	 *
	 * Los tamaños del núcleo (thumbnail, medium, medium_large, large, full)
	 * siempre están disponibles. Los tamaños adicionales se detectan via
	 * get_intermediate_image_sizes(). Si el tamaño solicitado no existe,
	 * retorna 'thumbnail' como fallback seguro.
	 *
	 * @param  string $size  Tamaño solicitado.
	 * @return string  Tamaño válido garantizado.
	 */
	private static function resolve_thumbnail_size( string $size ): string {
		if ( '' === $size ) {
			return 'medium';
		}

		// Tamaños del núcleo siempre disponibles.
		$core_sizes = array( 'thumbnail', 'medium', 'medium_large', 'large', 'full' );

		// Tamaños intermedios registrados por el tema/plugins.
		$registered = array_merge( $core_sizes, get_intermediate_image_sizes() );
		$registered = array_unique( $registered );

		if ( in_array( $size, $registered, true ) ) {
			return $size;
		}

		// Fallback seguro: 'thumbnail' siempre existe.
		return 'thumbnail';
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Renderiza el widget completo.
	 *
	 * @param  array   $posts
	 * @param  string  $title
	 * @param  bool    $show_title      Corrección 5: controla visibilidad del título.
	 * @param  string  $layout
	 * @param  string  $thumbnail_size
	 * @param  string  $max_width
	 * @param  bool    $show_thumbnail
	 */
	private static function render_widget(
		array  $posts,
		string $title,
		bool   $show_title,
		string $layout,
		string $thumbnail_size,
		string $max_width,
		bool   $show_thumbnail
	): void {
		$style_attr   = $max_width ? ' style="max-width:' . esc_attr( $max_width ) . '"' : '';
		$layout_class = 'wpam-tp-layout--' . esc_attr( $layout );
		?>
		<div class="wpam-top-posts-widget <?php echo esc_attr( $layout_class ); ?>"<?php echo $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escapado arriba. ?>>

			<?php if ( $show_title && '' !== $title ) : ?>
				<h3 class="wpam-top-posts-widget__title"><?php echo esc_html( $title ); ?></h3>
			<?php endif; ?>

			<?php if ( empty( $posts ) ) : ?>
				<p class="wpam-top-posts-widget__empty"><?php esc_html_e( 'No posts to show yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<ul class="wpam-top-posts-widget__list">
					<?php foreach ( $posts as $post ) : ?>
						<li class="wpam-top-posts-widget__item">
							<a class="wpam-top-posts-widget__link" href="<?php echo esc_url( $post['permalink'] ); ?>">
								<?php if ( $show_thumbnail ) : ?>
									<span class="wpam-top-posts-widget__thumb-wrap">
										<?php
										$thumb = get_the_post_thumbnail(
											$post['id'],
											$thumbnail_size,
											array( 'class' => 'wpam-top-posts-widget__thumb' )
										);
										if ( $thumb ) {
											echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — función WP, segura.
										} else {
											?>
											<span class="wpam-top-posts-widget__thumb-placeholder" aria-hidden="true">📄</span>
											<?php
										}
										?>
									</span>
								<?php endif; ?>
								<span class="wpam-top-posts-widget__info">
									<span class="wpam-top-posts-widget__post-title"><?php echo esc_html( $post['title'] ); ?></span>
								</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

		</div>
		<?php
	}
}
