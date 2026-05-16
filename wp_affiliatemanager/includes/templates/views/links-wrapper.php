<?php
/**
 * Template: links-wrapper
 *
 * Wrapper exterior del bloque de affiliate links de un post.
 * Contiene todos los link-item ya renderizados e inyectados como string.
 *
 * Variables disponibles en este template (inyectadas por Templates::render()):
 *
 * @var string $items_html    HTML concatenado de todos los link-item válidos.
 * @var string $style         Estilo activo: 'vertical' | 'horizontal'.
 * @var string $wrapper_class Clase CSS completa del wrapper (ya incluye el estilo).
 * @var int    $post_id       ID del post al que pertenecen los links.
 *
 * NOTA: Los temas pueden sobreescribir este template creando:
 * /wp-content/themes/{tu-tema}/wpam/links-wrapper.php
 *
 * @package WP_AffiliateManager
 * @since   4.0.0
 */

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Sanitizar variables.
// $items_html es HTML generado internamente por el plugin — NO se escapa
// porque contiene markup válido producido por link-item.php, que ya aplica
// su propio escaping sobre cada valor individual.
// ---------------------------------------------------------------------------

$items_html    = isset( $items_html )    ? $items_html            : '';
$style         = isset( $style )         ? esc_attr( $style )     : 'vertical';
$wrapper_class = isset( $wrapper_class ) ? esc_attr( $wrapper_class ) : 'wpam-links-wrapper wpam-style-vertical';
$post_id       = isset( $post_id )       ? absint( $post_id )     : 0;

// Guard clause: no renderizar si no hay items.
if ( '' === $items_html ) {
	return;
}
?>
<div
	class="<?php echo esc_attr( $wrapper_class ); ?>"
	data-post-id="<?php echo esc_attr( $post_id ); ?>"
	data-style="<?php echo esc_attr( $style ); ?>"
>
	<div class="wpam-links-inner">
		<?php
		// $items_html contiene HTML generado por link-item.php.
		// Cada valor individual fue escapado en el template de origen.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $items_html;
		?>
	</div>
</div>
