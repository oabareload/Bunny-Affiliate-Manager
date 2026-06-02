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
		$global_disclaimer = wp_kses_post( $options['redirect']['disclaimer_text'] ?? $this->default_disclaimer() );
		$title         = sanitize_text_field( $options['redirect']['interstitial_title'] ?? __( 'Estás saliendo de BunnyChase', 'wp-affiliatemanager' ) );
		$countdown_tpl = sanitize_text_field( $options['redirect']['interstitial_countdown_text'] ?? __( 'Redirigiendo en {seconds}s', 'wp-affiliatemanager' ) );
		$button_text   = sanitize_text_field( $options['redirect']['interstitial_button_text'] ?? __( 'Continuar', 'wp-affiliatemanager' ) );
		$show_related_excerpt = ! empty( $options['redirect']['show_related_post_excerpt'] ?? false );
		$site_name     = get_bloginfo( 'name' );
		$dest_url      = esc_url( $destination['url'] );

		// Obtener datos del afiliado.
		$affiliate = $this->get_affiliate( $destination['affiliate_id'] );
		$disclaimer = $this->get_disclaimer( $affiliate, $global_disclaimer );
		$related_post = $this->get_related_post( $affiliate );
		$aff_name  = $affiliate ? esc_html( $affiliate['title'] ) : esc_html__( 'external site', 'wp-affiliatemanager' );
		$logo_url  = $affiliate ? esc_url( $affiliate['logo_url'] ?? '' ) : '';
		$brand_hex = $affiliate ? ( $affiliate['brand_color'] ?? '#6c47ff' ) : '#6c47ff';
		$brand_hex = preg_match( '/^#[0-9a-fA-F]{3,6}$/', $brand_hex ) ? $brand_hex : '#6c47ff';

		$related_url     = $related_post ? get_permalink( $related_post ) : '';
		$related_title   = $related_post ? get_the_title( $related_post ) : '';
		$related_image   = $related_post ? get_the_post_thumbnail( $related_post, 'medium', array( 'class' => 'wpam-interstitial-related-img' ) ) : '';
		$related_excerpt = ( $related_post && $show_related_excerpt ) ? trim( (string) $related_post->post_excerpt ) : '';

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

	<?php if ( $related_post && $related_url ) : ?>
		<div class="wpam-interstitial-related-card">
			<?php if ( $related_image ) : ?>
				<a class="wpam-interstitial-related-media" href="<?php echo esc_url( $related_url ); ?>">
					<?php echo $related_image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
			<?php endif; ?>

			<div class="wpam-interstitial-related-body">
				<h2 class="wpam-interstitial-related-title">
					<a href="<?php echo esc_url( $related_url ); ?>"><?php echo esc_html( $related_title ); ?></a>
				</h2>

				<?php if ( '' !== $related_excerpt ) : ?>
					<p class="wpam-interstitial-related-excerpt">
						<?php echo wp_kses_post( $related_excerpt ); ?>
					</p>
				<?php endif; ?>

				<a target="_blank" class="wpam-interstitial-related-link" href="<?php echo esc_url( $related_url ); ?>">
					<?php esc_html_e( 'Ver más...', 'wp-affiliatemanager' ); ?>
				</a>
			</div>
		</div>
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
	 * Retorna el disclaimer efectivo para el afiliado.
	 *
	 * @since  0.2.5
	 * @param  array|null $affiliate Afiliado normalizado.
	 * @param  string     $global_disclaimer Disclaimer global ya sanitizado.
	 * @return string
	 */
	private function get_disclaimer( ?array $affiliate, string $global_disclaimer ): string {
		if ( ! $affiliate ) {
			return $global_disclaimer;
		}

		if ( ! empty( $affiliate['use_global_disclaimer'] ) ) {
			return $global_disclaimer;
		}

		$custom_disclaimer = wp_kses_post( $affiliate['custom_disclaimer'] ?? '' );
		return '' !== trim( $custom_disclaimer ) ? $custom_disclaimer : '';
	}

	/**
	 * Retorna el post relacionado configurado para el afiliado.
	 *
	 * @since  0.2.5
	 * @param  array|null $affiliate Afiliado normalizado.
	 * @return \WP_Post|null
	 */
	private function get_related_post( ?array $affiliate ): ?\WP_Post {
		$related_post_id = absint( $affiliate['related_post_id'] ?? 0 );

		if ( $related_post_id <= 0 ) {
			return null;
		}

		$post = get_post( $related_post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		return $post;
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
