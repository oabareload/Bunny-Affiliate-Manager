<?php
/**
 * Template: link-item
 *
 * Renderiza un único affiliate link dentro del bloque de links de un post.
 *
 * La card completa es un elemento <a>, lo que la hace 100% clicable sin
 * nested anchors inválidos. El botón CTA es un <span> visual, no un <a>.
 *
 * Variables disponibles en este template (inyectadas por Templates::render()):
 *
 * @var string     $final_url       URL afiliada completa (generada dinámicamente).
 * @var string     $label           Etiqueta del link (custom_label o nombre del afiliado).
 * @var string     $link_target     Atributo target del enlace ('_blank' | '_self').
 * @var string     $rel             Atributo rel completo ('nofollow sponsored noopener noreferrer', etc.).
 * @var int        $provider_id     ID del afiliado (wpam_affiliate post ID).
 * @var array|null $affiliate       Array normalizado del afiliado (puede ser null en race condition).
 * @var string     $display_content Qué mostrar: 'show_logo_and_name' | 'show_logo_only' | 'show_name_only'.
 * @var string     $cta_text        Texto del botón CTA.
 * @var bool       $cta_hidden      Si true, no se renderiza el botón CTA.
 *
 * NOTA: Los temas pueden sobreescribir este template creando:
 * /wp-content/themes/{tu-tema}/wpam/link-item.php
 *
 * @package WP_AffiliateManager
 * @since   4.0.0
 * @since   0.0.5 Card completamente clicable, display_content, CTA configurable.
 */

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Sanitizar y establecer valores por defecto para todas las variables.
// El escaping se realiza aquí, en la capa de presentación, una sola vez.
// Las variables resultantes son seguras para echo directo en el template.
// ---------------------------------------------------------------------------

$final_url       = isset( $final_url )       ? esc_url( $final_url )        : '';
$label           = isset( $label )           ? esc_html( $label )            : '';
$link_target     = isset( $link_target )     ? esc_attr( $link_target )      : '_blank';
$rel             = isset( $rel )             ? esc_attr( $rel )              : 'nofollow sponsored noopener noreferrer';
$provider_id     = isset( $provider_id )     ? absint( $provider_id )        : 0;
$display_content = isset( $display_content ) ? (string) $display_content     : 'show_logo_and_name';
$cta_text        = isset( $cta_text ) && '' !== $cta_text ? esc_html( $cta_text ) : esc_html__( 'Ver oferta', 'wp-affiliatemanager' );
$cta_hidden      = isset( $cta_hidden )      ? (bool) $cta_hidden            : false;

// Datos del afiliado: logo y color de marca.
$logo_url    = '';
$brand_color = '';
$aff_name    = $label; // Fallback: usar el label ya escapado.

if ( ! empty( $affiliate ) && is_array( $affiliate ) ) {
	$logo_url    = isset( $affiliate['logo_url'] )    ? esc_url( $affiliate['logo_url'] )     : '';
	$brand_color = isset( $affiliate['brand_color'] ) ? esc_attr( $affiliate['brand_color'] ) : '';
	// Si no hay label explícito, usar el título del afiliado.
	if ( '' === $label && isset( $affiliate['title'] ) ) {
		$aff_name = esc_html( $affiliate['title'] );
	}
}

// Omitir el template completo si no hay URL válida (guard clause).
if ( '' === $final_url ) {
	return;
}

// Determinar qué mostrar según display_content.
$show_logo    = in_array( $display_content, array( 'show_logo_and_name', 'show_logo_only' ), true );
$show_name    = in_array( $display_content, array( 'show_logo_and_name', 'show_name_only' ), true );
$logo_only    = ( 'show_logo_only' === $display_content );

// ---------------------------------------------------------------------------
// Accesibilidad: cuando no hay texto visible en la card (modo logo_only o
// cuando show_name es false y cta_hidden es true), el <a> necesita un
// aria-label para que lectores de pantalla entiendan el destino del enlace.
//
// Regla: si la card no va a renderizar texto legible → añadir aria-label.
// Se considera texto legible: el nombre del afiliado o el botón CTA.
// ---------------------------------------------------------------------------
$has_visible_text = ( $show_name && '' !== $aff_name ) || ( ! $cta_hidden );
$aria_label_attr  = '';
if ( ! $has_visible_text && '' !== $aff_name ) {
	$aria_label_attr = ' aria-label="' . esc_attr( $aff_name ) . '"';
}

// Atributo style inline con CSS custom property para el color de marca.
// La variable $brand_color ya está escapada con esc_attr() arriba.
$brand_style = '' !== $brand_color
	? ' style="--wpam-brand-color:' . $brand_color . ';"'
	: '';

// Clase modificadora para modo logo-only: centra el contenido de la card.
$item_class = 'wpam-link-item';
if ( $logo_only ) {
	$item_class .= ' wpam-link-item--logo-only';
}

// ---------------------------------------------------------------------------
// HTML — La card completa es el <a> principal.
//
// Estructura:
//   <a .wpam-link-item>          ← anchor único, card 100% clicable
//     <div .wpam-link-logo>      ← condicional según display_content
//     <div .wpam-link-info>      ← condicional según display_content
//     <span .wpam-link-btn>      ← span visual, NO un <a> anidado
//                                   condicional según cta_hidden
//
// Esto evita nested anchors inválidos en HTML y mantiene rel/target
// correctos en un único elemento semántico.
// ---------------------------------------------------------------------------
?>
<a
	class="<?php echo esc_attr( $item_class ); ?>"
	href="<?php echo esc_url( $final_url ); ?>"
	target="<?php echo esc_attr( $link_target ); ?>"
	rel="<?php echo esc_attr( $rel ); ?>"
	data-provider="<?php echo esc_attr( $provider_id ); ?>"
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- construidos con esc_attr(), ver líneas superiores.
	echo $brand_style . $aria_label_attr;
	?>
>

	<?php if ( $show_logo && '' !== $logo_url ) : ?>
		<div class="wpam-link-logo">
			<img
				src="<?php echo esc_url( $logo_url ); ?>"
				alt="<?php echo esc_attr( $aff_name ); ?>"
				loading="lazy"
				width="80"
				height="40"
			/>
		</div>
	<?php endif; ?>

	<?php if ( $show_name && '' !== $aff_name ) : ?>
		<div class="wpam-link-info">
			<span class="wpam-link-name"><?php echo esc_html( $aff_name ); ?></span>
		</div>
	<?php endif; ?>

	<?php if ( ! $cta_hidden ) : ?>
		<div class="wpam-link-action">
			<span class="wpam-link-btn" aria-hidden="true"><?php echo $cta_text; // Already escaped above. ?></span>
		</div>
	<?php endif; ?>

</a>
