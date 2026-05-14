<?php
/**
 * Template: affiliate-card
 *
 * Renderiza un afiliado en formato card.
 *
 * Variables disponibles en este template:
 * @var string $affiliate_name     Nombre del afiliado.
 * @var string $affiliate_url      URL del afiliado (ya escapada).
 * @var string $affiliate_category Categoría del afiliado.
 * @var string $link_target        Atributo target del enlace.
 * @var bool   $nofollow           Si se debe añadir rel="nofollow".
 *
 * NOTA: Los temas pueden sobreescribir este template creando:
 * /wp-content/themes/{tu-tema}/wpam/affiliate-card.php
 *
 * @package WP_AffiliateManager
 * @since   1.0.0
 */

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Valores por defecto seguros.
$affiliate_name     = isset( $affiliate_name )     ? esc_html( $affiliate_name )     : '';
$affiliate_url      = isset( $affiliate_url )      ? esc_url( $affiliate_url )       : '#';
$affiliate_category = isset( $affiliate_category ) ? esc_html( $affiliate_category ) : '';
$link_target        = isset( $link_target )        ? esc_attr( $link_target )        : '_blank';
$nofollow           = isset( $nofollow )           ? (bool) $nofollow               : true;
$affiliate_id       = isset( $affiliate_id )       ? absint( $affiliate_id )         : 0;

// Construir atributo rel.
$rel = $nofollow ? 'nofollow noopener noreferrer' : 'noopener noreferrer';
?>
<div class="wpam-affiliate-card">
	<div class="wpam-affiliate-card-info">
		<?php if ( $affiliate_name ) : ?>
			<span class="wpam-affiliate-card-name"><?php echo $affiliate_name; // Already escaped above. ?></span>
		<?php endif; ?>
		<?php if ( $affiliate_category ) : ?>
			<span class="wpam-affiliate-card-category"><?php echo $affiliate_category; // Already escaped above. ?></span>
		<?php endif; ?>
	</div>
	<a
		href="<?php echo $affiliate_url; // Already escaped above. ?>"
		class="wpam-affiliate-card-btn"
		target="<?php echo $link_target; // Already escaped above. ?>"
		rel="<?php echo esc_attr( $rel ); ?>"
		data-wpam-link="<?php echo esc_attr( $affiliate_id ); ?>"
	>
		<?php esc_html_e( 'Ver oferta', 'wp-affiliatemanager' ); ?>
	</a>
</div>
