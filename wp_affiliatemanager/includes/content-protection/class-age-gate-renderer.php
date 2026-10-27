<?php
/**
 * Renderer for the NSFW Age Gate confirmation screen.
 *
 * Emits an isolated, accessible age verification card without exposing
 * any post metadata (title, excerpt, content, featured image).
 *
 * @package WP_AffiliateManager\Content_Protection
 * @since   2.1.0
 */

namespace WP_AffiliateManager\Content_Protection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Age_Gate_Renderer
 */
class Age_Gate_Renderer {

	/**
	 * Renders the Age Gate confirmation screen and terminates execution.
	 *
	 * @param array $data {
	 *     Context data for rendering.
	 *
	 *     @type string $action_url   URL to submit the form (current post permalink).
	 *     @type string $nonce        Security nonce tied to the action and post ID.
	 *     @type string $title        Card headline.
	 *     @type string $message      Card body message.
	 *     @type string $confirm_text Confirm button label (18+).
	 *     @type string $reject_text  Reject button label (under 18 / SFW fallback).
	 * }
	 * @return void
	 */
	public function render( array $data ): void {
		$site_name    = get_bloginfo( 'name' );
		$action_url   = esc_url( $data['action_url'] ?? home_url( '/' ) );
		$nonce        = esc_attr( $data['nonce'] ?? '' );
		$title        = esc_html( $data['title'] ?? __( 'Contenido para mayores de 18 años', 'wp-affiliatemanager' ) );
		$message      = esc_html( $data['message'] ?? __( 'Esta publicación contiene material clasificado para adultos. Debes ser mayor de edad para acceder.', 'wp-affiliatemanager' ) );
		$confirm_text = esc_html( $data['confirm_text'] ?? __( 'Tengo 18 años o más — Entrar', 'wp-affiliatemanager' ) );
		$reject_text  = esc_html( $data['reject_text'] ?? __( 'Soy menor de 18 años — Salir a contenido seguro', 'wp-affiliatemanager' ) );

		$css_url = defined( 'WPAM_PLUGIN_URL' )
			? WPAM_PLUGIN_URL . 'assets/css/interstitial.css?v=' . WPAM_VERSION
			: '';

		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?php echo esc_html( sprintf( '%s — %s', $title, $site_name ) ); ?></title>
<?php if ( $css_url ) : ?>
<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
<?php endif; ?>
<style>
	:root {
		--wpam-brand: #dc2626;
	}
	.wpam-age-gate-badge {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 64px;
		height: 64px;
		border-radius: 50%;
		background: #fee2e2;
		color: #dc2626;
		font-weight: 800;
		font-size: 24px;
		letter-spacing: -0.02em;
		margin-bottom: 8px;
		border: 2px solid #fca5a5;
	}
	.wpam-age-gate-title {
		font-size: 22px;
		font-weight: 700;
		color: #0f172a;
		line-height: 1.3;
		margin: 0;
	}
	.wpam-age-gate-message {
		font-size: 14px;
		line-height: 1.6;
		color: #475569;
		margin: 0;
		max-width: 380px;
	}
	.wpam-age-gate-form {
		width: 100%;
		display: flex;
		flex-direction: column;
		gap: 12px;
		margin-top: 8px;
	}
	.wpam-age-gate-btn {
		display: block;
		width: 100%;
		padding: 14px 20px;
		font-size: 15px;
		font-weight: 700;
		border-radius: 10px;
		cursor: pointer;
		border: none;
		transition: transform 0.15s ease, opacity 0.15s ease, background-color 0.15s ease;
		text-align: center;
		font-family: inherit;
		box-sizing: border-box;
	}
	.wpam-age-gate-btn--confirm {
		background: #dc2626;
		color: #ffffff;
	}
	.wpam-age-gate-btn--confirm:hover,
	.wpam-age-gate-btn--confirm:focus {
		background: #b91c1c;
		transform: translateY(-1px);
	}
	.wpam-age-gate-btn--confirm:active {
		transform: translateY(0);
	}
	.wpam-age-gate-btn--reject {
		background: #f1f5f9;
		color: #334155;
		border: 1px solid #cbd5e1;
		font-weight: 600;
	}
	.wpam-age-gate-btn--reject:hover,
	.wpam-age-gate-btn--reject:focus {
		background: #e2e8f0;
		color: #0f172a;
	}
	.wpam-age-gate-footer {
		font-size: 11px;
		color: #94a3b8;
		line-height: 1.5;
		margin-top: 4px;
	}
</style>
</head>
<body class="wpam-interstitial-body">

<div class="wpam-interstitial-card wpam-card--w460" role="dialog" aria-modal="true" aria-labelledby="wpam-age-gate-heading">

	<div class="wpam-age-gate-badge" aria-hidden="true">18+</div>

	<h1 id="wpam-age-gate-heading" class="wpam-age-gate-title">
		<?php echo $title; ?>
	</h1>

	<p class="wpam-age-gate-message">
		<?php echo $message; ?>
	</p>

	<form class="wpam-age-gate-form" method="POST" action="<?php echo $action_url; ?>">
		<input type="hidden" name="wpam_age_gate_nonce" value="<?php echo $nonce; ?>">
		<button
			type="submit"
			name="wpam_age_gate_action"
			value="confirm"
			class="wpam-age-gate-btn wpam-age-gate-btn--confirm"
		>
			<?php echo $confirm_text; ?>
		</button>
		<button
			type="submit"
			name="wpam_age_gate_action"
			value="reject"
			class="wpam-age-gate-btn wpam-age-gate-btn--reject"
		>
			<?php echo $reject_text; ?>
		</button>
	</form>

	<p class="wpam-age-gate-footer">
		<?php esc_html_e( 'Al confirmar, se guardará una cookie en tu navegador para recordar tu verificación.', 'wp-affiliatemanager' ); ?>
	</p>

</div>

</body>
</html>
<?php
		exit;
	}
}
