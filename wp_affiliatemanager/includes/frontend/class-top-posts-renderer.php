<?php
/**
 * Top Posts Renderer — única fuente de lógica de presentación para el widget de Top Posts.
 *
 * Usada por Shortcode_Top_Posts y Widget_Top_Posts.
 * Ninguno de los dos duplica lógica de HTML ni de validación de imágenes.
 *
 * @package WP_AffiliateManager\Frontend
 * @since   1.0.0
 */

namespace WP_AffiliateManager\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Top_Posts_Renderer {

	// -------------------------------------------------------------------------
	// Imagen
	// -------------------------------------------------------------------------

	/**
	 * Devuelve todos los tamaños de imagen registrados en WordPress.
	 *
	 * Incluye los tamaños del núcleo + los registrados por tema/plugins.
	 * Usado por el formulario del widget para construir el <select> dinámico.
	 *
	 * @return string[]
	 */
	public static function get_registered_image_sizes(): array {
		$core = array( 'thumbnail', 'medium', 'medium_large', 'large', 'full' );
		return array_unique( array_merge( $core, get_intermediate_image_sizes() ) );
	}

	/**
	 * Valida un thumbnail_size contra los tamaños registrados en WordPress.
	 *
	 * Si el tamaño no existe cae a 'thumbnail' (siempre disponible).
	 *
	 * @param  string $size  Tamaño solicitado.
	 * @return string  Tamaño válido garantizado.
	 */
	public static function resolve_thumbnail_size( string $size ): string {
		if ( '' === $size ) {
			return 'medium';
		}

		if ( in_array( $size, self::get_registered_image_sizes(), true ) ) {
			return $size;
		}

		return 'thumbnail';
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Renderiza el widget de Top Posts y devuelve HTML.
	 *
	 * @param  array  $posts    Resultado de Top_Posts_Query::get_cached().
	 * @param  array  $options {
	 *     @type string $title          Título del widget. Default ''.
	 *     @type bool   $show_title     Mostrar título. Default true.
	 *     @type string $layout         'horizontal'|'vertical'. Default 'horizontal'.
	 *     @type string $thumbnail_size Tamaño de imagen WordPress. Default 'medium'.
	 *     @type string $max_width      CSS max-width inline. Default ''.
	 *     @type bool   $show_thumbnail Mostrar thumbnail. Default true.
	 * }
	 * @return string  HTML completo del widget.
	 */
	public static function render( array $posts, array $options ): string {
		$title          = (string)  ( $options['title']          ?? '' );
		$show_title     = (bool)    ( $options['show_title']     ?? true );
		$layout         = (string)  ( $options['layout']         ?? 'horizontal' );
		$thumbnail_size = (string)  ( $options['thumbnail_size'] ?? 'medium' );
		$max_width      = (string)  ( $options['max_width']      ?? '' );
		$show_thumbnail = (bool)    ( $options['show_thumbnail'] ?? true );

		$style_attr   = $max_width ? ' style="max-width:' . esc_attr( $max_width ) . '"' : '';
		$layout_class = 'wpam-tp-layout--' . esc_attr( $layout );

		ob_start();
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
		return ob_get_clean();
	}
}
