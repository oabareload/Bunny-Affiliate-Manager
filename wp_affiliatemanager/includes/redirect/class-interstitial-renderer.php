<?php
/**
 * Interstitial Renderer — genera la página de salida antes del redirect externo.
 *
 * Muestra una card simple con:
 *  - Nombre + logo del afiliado.
 *  - Mensaje configurable ("Estás saliendo de...").
 *  - Countdown configurable.
 *  - Botón configurable ("Continuar").
 *  - Disclaimer configurable.
 *
 * Al llegar a 0, el JS hace el redirect automático a la URL de destino.
 * El botón también redirige inmediatamente.
 *
 * Lo que NO hace todavía:
 *  - No muestra ads.
 *  - No tiene templates intercambiables.
 *  - No tiene override por afiliado.
 *
 * @package WP_AffiliateManager\Redirect
 * @since   0.2.0-alpha2
 * @version 0.2.0-alpha3.2
 */

namespace WP_AffiliateManager\Redirect;

use WP_AffiliateManager\Affiliates\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Interstitial_Renderer
 *
 * @since 0.2.0-alpha2
 */
class Interstitial_Renderer {

	/**
	 * Renderiza la página interstitial completa y hace exit.
	 *
	 * @since  0.2.0-alpha2
	 * @param  array $destination {
	 *     @type int    $post_id      ID del post origen.
	 *     @type int    $affiliate_id ID del afiliado.
	 *     @type string $url          URL de destino.
	 * }
	 * @return void
	 */
	public function render( array $destination ): void {
		$options       = get_option( WPAM_OPTION_KEY, array() );
		$delay         = absint( $options['redirect']['redirect_delay'] ?? 3 );
		$disclaimer    = wp_kses_post( $options['redirect']['disclaimer_text'] ?? $this->default_disclaimer() );
		$title         = sanitize_text_field( $options['redirect']['interstitial_title'] ?? __( 'Estás saliendo de BunnyChase', 'wp-affiliatemanager' ) );
		$countdown_tpl = sanitize_text_field( $options['redirect']['interstitial_countdown_text'] ?? __( 'Redirigiendo en {seconds}s', 'wp-affiliatemanager' ) );
		$button_text   = sanitize_text_field( $options['redirect']['interstitial_button_text'] ?? __( 'Continuar', 'wp-affiliatemanager' ) );
		$site_name     = get_bloginfo( 'name' );
		$dest_url      = esc_url( $destination['url'] );

		// Obtener datos del afiliado.
		$affiliate = $this->get_affiliate( $destination['affiliate_id'] );
		$aff_name  = $affiliate ? esc_html( $affiliate['title'] ) : esc_html__( 'external site', 'wp-affiliatemanager' );
		$logo_url  = $affiliate ? esc_url( $affiliate['logo_url'] ?? '' ) : '';
		$brand_hex = $affiliate ? ( $affiliate['brand_color'] ?? '#6c47ff' ) : '#6c47ff';
		$brand_hex = preg_match( '/^#[0-9a-fA-F]{3,6}$/', $brand_hex ) ? $brand_hex : '#6c47ff';

		$css_url = WPAM_PLUGIN_URL . 'assets/css/interstitial.css?v=' . WPAM_VERSION;

		// Enviar headers antes de cualquier output.
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php
	printf(
		/* translators: 1: site name, 2: affiliate name */
		esc_html__( 'Leaving %1$s - %2$s', 'wp-affiliatemanager' ),
		esc_html( $site_name ),
		$aff_name
	);
?></title>
<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
<style>:root{--wpam-brand:<?php echo esc_attr( $brand_hex ); ?>;}</style>
</head>
<body class="wpam-interstitial-body">

<div class="wpam-interstitial-card">

	<p class="wpam-interstitial-leaving">
		<?php echo esc_html( $title ); ?>
	</p>

	<div class="wpam-interstitial-affiliate">
		<?php if ( $logo_url ) : ?>
			<img
				class="wpam-interstitial-logo"
				src="<?php echo $logo_url; // Already escaped above. ?>"
				alt="<?php echo $aff_name; // Already escaped above. ?>"
			/>
		<?php endif; ?>
		<span class="wpam-interstitial-aff-name"><?php echo $aff_name; // Already escaped above. ?></span>
	</div>

	<div class="wpam-interstitial-countdown-wrap" aria-live="polite">
		<span class="wpam-interstitial-countdown-text" id="wpam-countdown-text">
			<?php echo esc_html( str_replace( '{seconds}', (string) $delay, $countdown_tpl ) ); ?>
		</span>
	</div>

	<a
		href="<?php echo $dest_url; // Already escaped above. ?>"
		class="wpam-interstitial-btn"
		id="wpam-continue-btn"
		rel="nofollow noopener sponsored"
	>
		<?php echo esc_html( $button_text ); ?> &rarr;
	</a>

	<?php if ( $disclaimer ) : ?>
		<p class="wpam-interstitial-disclaimer">
			<?php echo $disclaimer; // Already sanitized via wp_kses_post above. ?>
		</p>
	<?php endif; ?>

</div>

<script>
( function () {
	'use strict';
	var remaining    = <?php echo (int) $delay; ?>;
	var dest         = <?php echo wp_json_encode( $destination['url'] ); ?>;
	var countdownTpl = <?php echo wp_json_encode( $countdown_tpl ); ?>;
	var el           = document.getElementById( 'wpam-countdown-text' );

	if ( remaining <= 0 ) {
		window.location.replace( dest );
		return;
	}

	var timer = setInterval( function () {
		remaining -= 1;
		if ( el ) { el.textContent = countdownTpl.replace( '{seconds}', remaining ); }
		if ( remaining <= 0 ) {
			clearInterval( timer );
			window.location.replace( dest );
		}
	}, 1000 );
} )();
</script>

</body>
</html><?php
		exit;
	}

	// -------------------------------------------------------------------------
	// Helpers privados
	// -------------------------------------------------------------------------

	/**
	 * Retorna los datos del afiliado por ID.
	 *
	 * @since  0.2.0-alpha2
	 * @param  int $affiliate_id
	 * @return array|null
	 */
	private function get_affiliate( int $affiliate_id ): ?array {
		if ( $affiliate_id <= 0 ) {
			return null;
		}
		$repo = new Repository();
		return $repo->find( $affiliate_id );
	}

	/**
	 * Texto de disclaimer por defecto.
	 *
	 * @since  0.2.0-alpha2
	 * @return string
	 */
	private function default_disclaimer(): string {
		return __( 'Prices, availability and content are the responsibility of the external site.', 'wp-affiliatemanager' );
	}
}
