<?php
/**
 * Template: showcase/showcase-block
 *
 * Renderiza el bloque completo del Layout Showcase: imagen + título +
 * descripción + fila de botones de afiliado, como una sola card grande.
 * Responsive: imagen a la izquierda / texto a la derecha en desktop,
 * apilado (imagen, título, texto, botones) en mobile — ver showcase.css.
 *
 * Variables disponibles en este template (inyectadas por Templates::render()):
 *
 * @var int    $post_id     ID del post.
 * @var string $image_url   URL de la imagen, o '' si no hay ninguna disponible.
 * @var string $title       Título del showcase, o '' si está oculto.
 * @var string $description Descripción/excerpt, o '' si está oculta.
 * @var string $items_html  HTML ya renderizado de los botones de afiliado
 *                          (mismo componente que usa Layout_Card — Button_Row).
 *
 * NOTA: Los temas pueden sobreescribir este template creando:
 * /wp-content/themes/{tu-tema}/wpam/showcase-block.php
 *
 * @package WP_AffiliateManager
 * @since   1.6.0
 */

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Sanitizar variables. $items_html es HTML generado internamente por el
// plugin (Button_Row → link-item.php) — no se re-escapa, ya viene escapado
// campo por campo desde su template de origen.
// ---------------------------------------------------------------------------

$post_id     = isset( $post_id )     ? absint( $post_id )       : 0;
$image_url   = isset( $image_url )   ? esc_url( $image_url )    : '';
$title       = isset( $title )       ? esc_html( $title )       : '';
$description = isset( $description ) ? esc_html( $description ) : '';
$items_html  = isset( $items_html )  ? $items_html               : '';

// Guard clause: sin botones no hay nada que promocionar.
if ( '' === $items_html ) {
	return;
}

$has_text = ( '' !== $title ) || ( '' !== $description );
?>
<div class="wpam-showcase" data-post-id="<?php echo esc_attr( $post_id ); ?>">
	<div class="wpam-showcase-body">

		<?php if ( '' !== $image_url ) : ?>
			<div class="wpam-showcase-media">
				<img
					src="<?php echo esc_url( $image_url ); ?>"
					alt="<?php echo esc_attr( $title ); ?>"
					loading="lazy"
				/>
			</div>
		<?php endif; ?>

		<?php if ( $has_text ) : ?>
			<div class="wpam-showcase-content">
				<?php if ( '' !== $title ) : ?>
					<h3 class="wpam-showcase-title"><?php echo esc_html( $title ); ?></h3>
				<?php endif; ?>
				<?php if ( '' !== $description ) : ?>
					<p class="wpam-showcase-description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

	</div>

	<div class="wpam-showcase-buttons">
		<?php
		// $items_html contiene HTML generado por link-item.php (vía Button_Row),
		// cada valor individual ya fue escapado en su template de origen.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $items_html;
		?>
	</div>
</div>
