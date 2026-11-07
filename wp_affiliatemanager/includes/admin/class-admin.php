<?php
/**
 * Módulo de administración — clase principal.
 *
 * Coordina las funcionalidades del área wp-admin del plugin.
 *
 * @package WP_AffiliateManager\Admin
 * @since   1.0.0
 */

namespace WP_AffiliateManager\Admin;

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 *
 * Punto de entrada del módulo de administración.
 * En FASE 1 actúa como placeholder con init() preparado para crecer.
 *
 * @since 1.0.0
 */
class Admin {

	/**
	 * Versión del plugin (para cache-busting de assets).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	private string $version;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $version Versión del plugin.
	 */
	public function __construct( string $version ) {
		$this->version = $version;
	}

	/**
	 * Inicialización del módulo admin.
	 * Se ejecuta en el hook 'admin_init'.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function init(): void {
		// v1.5.0: si la versión guardada difiere de la actual, forzar un flush de
		// rewrite rules una única vez. Necesario porque esta versión añade la
		// rewrite rule /goa/{post_id}/{affiliate_id}/ (Redirect_Manager) y las
		// instalaciones ya activas no la registran hasta que se hace flush.
		$stored_version = get_option( 'wpam_version', '' );
		if ( $stored_version !== WPAM_VERSION ) {
			flush_rewrite_rules( false );

			// v1.8.9: crear la tabla auxiliar wpam_views_utm en sitios ya
			// migrados a v1.8.0 (donde migrate_legacy_schema() ya no vuelve a
			// ejecutarse por el gate de wpam_views_schema_version). dbDelta()
			// es idempotente y create_table() no toca las tablas existentes,
			// así que reutilizar el bloque de version-bump ya existente aquí
			// evita crear un nuevo option gate solo para esto.
			\WP_AffiliateManager\Views\Views_Table::create_table();

			update_option( 'wpam_version', WPAM_VERSION );
		}
	}

	/**
	 * Retorna la versión.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_version(): string {
		return $this->version;
	}

	/**
	 * Muestra una advertencia en el panel de administración si reCAPTCHA v3 está activado
	 * pero la configuración de claves está incompleta.
	 *
	 * Evita un bypass silencioso y alerta al administrador de que el sistema opera en modo degradado.
	 *
	 * @since  2.3.0
	 * @return void
	 */
	public function render_recaptcha_configuration_notice(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! class_exists( '\WP_AffiliateManager\Captcha\Recaptcha_V3' ) ) {
			return;
		}

		if ( ! \WP_AffiliateManager\Captcha\Recaptcha_V3::is_enabled() || \WP_AffiliateManager\Captcha\Recaptcha_V3::is_configured() ) {
			return;
		}

		$status = \WP_AffiliateManager\Captcha\Recaptcha_V3::get_status();
		$reason = '';
		switch ( $status ) {
			case 'missing_site_key':
				$reason = __( 'Falta la Clave del Sitio (Site Key).', 'wp-affiliatemanager' );
				break;
			case 'missing_secret_key':
				$reason = __( 'Falta la Clave Secreta (Secret Key).', 'wp-affiliatemanager' );
				break;
			case 'missing_keys':
			default:
				$reason = __( 'Faltan tanto la Clave del Sitio como la Clave Secreta.', 'wp-affiliatemanager' );
				break;
		}

		$settings_url = admin_url( 'admin.php?page=wpam-settings' );
		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s <em>%3$s</em><br>%4$s <a href="%5$s">%6$s</a></p></div>',
			esc_html__( 'Bunny Affiliate Manager — Advertencia de Configuración reCAPTCHA v3:', 'wp-affiliatemanager' ),
			esc_html__( 'Google reCAPTCHA v3 está activado pero su configuración está incompleta:', 'wp-affiliatemanager' ),
			esc_html( $reason ),
			esc_html__( 'Para no bloquear a los visitantes, la protección de reCAPTCHA permanecerá inactiva hasta que se configuren las claves en Ajustes.', 'wp-affiliatemanager' ),
			esc_url( $settings_url ),
			esc_html__( 'Completar configuración en Ajustes', 'wp-affiliatemanager' )
		);
	}
}
