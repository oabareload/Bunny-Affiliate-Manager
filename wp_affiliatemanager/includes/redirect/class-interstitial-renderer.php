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
 *  - No tiene templates intercambiables.
 *  - No tiene override por afiliado.
 *
 * @package WP_AffiliateManager\Redirect
 * @since   0.2.0-alpha2
 * @version 0.2.6
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
		$width_key     = sanitize_text_field( $options['redirect']['interstitial_width'] ?? '460' );
		$allowed_widths = array( '460', '600', '800', '1000', 'full' );
		$width_key     = in_array( $width_key, $allowed_widths, true ) ? $width_key : '460';
		$width_class   = 'full' === $width_key ? 'wpam-card--full' : 'wpam-card--w' . $width_key;
		$content_slots = $options['content_slots'] ?? array();
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

		$css_url  = WPAM_PLUGIN_URL . 'assets/css/interstitial.css?v=' . WPAM_VERSION;
		$token    = sanitize_text_field( $destination['token'] ?? '' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'wpam_report_nonce' );

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
<?php wp_head(); ?>
</head>
<body class="wpam-interstitial-body">

<div class="wpam-interstitial-card <?php echo esc_attr( $width_class ); ?>">

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

	<?php echo $this->render_content_slots( 'before_disclaimer', $content_slots ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

	<?php if ( $disclaimer ) : ?>
		<p class="wpam-interstitial-disclaimer">
			<?php echo $disclaimer; // Already sanitized via wp_kses_post above. ?>
		</p>
	<?php endif; ?>

	<?php echo $this->render_content_slots( 'after_disclaimer', $content_slots ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

	<?php echo $this->render_content_slots( 'before_related', $content_slots ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

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

	<?php echo $this->render_content_slots( 'after_related', $content_slots ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

	<div class="wpam-interstitial-report-wrap">
		<button
			type="button"
			class="wpam-interstitial-report-btn"
			id="wpam-report-btn"
			data-token="<?php echo esc_attr( $token ); ?>"
			data-post="<?php echo esc_attr( (string) ( $destination['post_id'] ?? 0 ) ); ?>"
			data-ajax="<?php echo esc_url( $ajax_url ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
		>
			<?php esc_html_e( 'Report broken link', 'wp-affiliatemanager' ); ?>
		</button>
	</div>

</div>

<script>
( function () {
	'use strict';

	// Broken link report handler.
	var reportBtn = document.getElementById( 'wpam-report-btn' );
	if ( reportBtn ) {
		reportBtn.addEventListener( 'click', function () {
			var btn     = this;
			var token   = btn.getAttribute( 'data-token' );
			var postId  = btn.getAttribute( 'data-post' );
			var ajaxUrl = btn.getAttribute( 'data-ajax' );
			var nonce   = btn.getAttribute( 'data-nonce' );

			btn.disabled    = true;
			btn.textContent = btn.textContent.replace( /.*/, '<?php echo esc_js( __( 'Sending…', 'wp-affiliatemanager' ) ); ?>' );

			var body = 'action=wpam_report_broken_link&token=' + encodeURIComponent( token ) + '&post_id=' + encodeURIComponent( postId ) + '&nonce=' + encodeURIComponent( nonce );

			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', ajaxUrl, true );
			xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
			xhr.onreadystatechange = function () {
				if ( 4 !== xhr.readyState ) { return; }
				btn.textContent = ( 200 === xhr.status )
					? '<?php echo esc_js( __( 'Report sent. Thank you!', 'wp-affiliatemanager' ) ); ?>'
					: '<?php echo esc_js( __( 'Error — please try again.', 'wp-affiliatemanager' ) ); ?>';
			};
			xhr.send( body );
		} );
	}

	// Countdown + redirect.
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

<?php wp_footer(); ?>
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
	 * Renderiza los content slots activos para una posición dada.
	 *
	 * Itera sobre todos los slots configurados y devuelve el HTML
	 * de los que coincidan con la posición solicitada y no sean de tipo 'none'.
	 * Preparado para múltiples slots futuros: el bucle ya los soporta.
	 *
	 * @since  0.2.6
	 * @param  string $position      Posición a renderizar ('before_disclaimer', etc.).
	 * @param  array  $slots         Array indexado de slots desde las opciones.
	 * @return string HTML listo para imprimir (ya sanitizado en cada rama).
	 */
	private function render_content_slots( string $position, array $slots ): string {
		if ( empty( $slots ) ) {
			return '';
		}

		$output = '';

		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$type     = $slot['type']     ?? 'none';
			$slot_pos = $slot['position'] ?? 'after_disclaimer';

			if ( 'none' === $type || $slot_pos !== $position ) {
				continue;
			}

			if ( 'custom_html' === $type ) {
				$html = trim( $slot['html'] ?? '' );
				if ( '' !== $html ) {
					// wp_kses_post ya aplicado en sanitize_options; re-escapar sería doble.
					$output .= '<div class="wpam-interstitial-slot wpam-slot--html">' . $html . '</div>';
				}
			} elseif ( 'image_link' === $type ) {
				$image_url = esc_url( $slot['image_url'] ?? '' );
				$dest_url  = esc_url( $slot['dest_url']  ?? '' );
				$alt_text  = esc_attr( $slot['alt_text']  ?? '' );

				if ( $image_url && $dest_url ) {
					$output .= sprintf(
						'<div class="wpam-interstitial-slot wpam-slot--image-link"><a href="%s" rel="nofollow noopener sponsored" target="_blank"><img src="%s" alt="%s" class="wpam-slot-image" /></a></div>',
						$dest_url,
						$image_url,
						$alt_text
					);
				}
			}
		}

		return $output;
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
