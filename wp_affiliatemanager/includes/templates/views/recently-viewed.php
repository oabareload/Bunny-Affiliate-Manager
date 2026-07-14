<?php
/**
 * Template: recently-viewed
 *
 * Bloque "Recently Viewed" — historial de posts vistos por el visitante.
 * Estructura visual inspirada en Contextual Related Posts (figure > img
 * enlazado, sin titulo de post visible), con namespace propio wpam-rv-*.
 * No copia clases ni CSS de CRP.
 *
 * Variables disponibles en este template (inyectadas por Templates::render()):
 *
 * @var string $title      Titulo del bloque (Settings 'recently_viewed.title').
 * @var string $items_html HTML concatenado de todos los <li> ya renderizados
 *                          por Recently_Viewed::render_item().
 *
 * NOTA: Los temas pueden sobreescribir este template creando:
 * /wp-content/themes/{tu-tema}/wpam/recently-viewed.php
 *
 * @package WP_AffiliateManager
 * @since   1.3.0
 */

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$title      = isset( $title ) ? (string) $title : '';
$items_html = isset( $items_html ) ? $items_html : '';

// Guard clause: no renderizar si no hay items.
if ( '' === $items_html ) {
	return;
}
?>
<div class="wpam-recently-viewed">
	<?php if ( '' !== $title ) : ?>
	<h2 class="wpam-rv-title"><?php echo esc_html( $title ); ?></h2>
	<?php endif; ?>
	<ul class="wpam-rv-list">
		<?php
		// $items_html contiene HTML generado internamente por
		// Recently_Viewed::render_item(), que ya escapa cada valor individual.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $items_html;
		?>
	</ul>
	<div class="wpam-rv-clear"></div>
</div>
