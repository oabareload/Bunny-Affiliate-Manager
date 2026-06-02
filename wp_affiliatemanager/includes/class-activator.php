<?php
/**
 * Clase de activación del plugin.
 *
 * @package WP_AffiliateManager
 * @since   1.0.0 (actualizado en 2.0.0)
 */

namespace WP_AffiliateManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	public static function activate(): void {
		// Verificar requisitos mínimos.
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			deactivate_plugins( plugin_basename( WPAM_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'Bunny Affiliate Manager requires PHP 8.0 or higher.', 'wp-affiliatemanager' ),
				esc_html__( 'Activation Error', 'wp-affiliatemanager' ),
				array( 'back_link' => true )
			);
		}

		if ( version_compare( get_bloginfo( 'version' ), '6.0', '<' ) ) {
			deactivate_plugins( plugin_basename( WPAM_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'Bunny Affiliate Manager requires WordPress 6.0 or higher.', 'wp-affiliatemanager' ),
				esc_html__( 'Activation Error', 'wp-affiliatemanager' ),
				array( 'back_link' => true )
			);
		}

		// Opciones por defecto.
		self::set_default_options();

		// Guardar versión instalada.
		update_option( 'wpam_version', WPAM_VERSION );

		// FASE 2: registrar el CPT antes del flush para que las rewrite rules
		// incluyan el post type desde el primer momento.
		self::register_cpt_for_flush();

		// v0.2.0-alpha1: registrar la rewrite rule del redirect antes del flush.
		$redirect = new Redirect\Redirect_Manager();
		$redirect->register_rewrite();

		// v0.2.1: crear tabla SQL de clicks.
		Redirect\Clicks_Table::create_table();

		// Limpiar rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Registra el CPT durante la activación para que flush_rewrite_rules()
	 * lo incluya correctamente. En uso normal lo registra class-plugin.php vía 'init'.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	private static function register_cpt_for_flush(): void {
		$cpt = new Affiliates\CPT();
		$cpt->register();
	}

	private static function set_default_options(): void {
		$existing = get_option( WPAM_OPTION_KEY, null );

		if ( null !== $existing ) {
			return;
		}

		$defaults = array(
			'general' => array(
				'display_mode' => 'automatic',
				'link_target'  => '_blank',
				'nofollow'     => true,
				'track_clicks' => false,
				'exclude_admins_from_analytics' => true,
			),
			'redirect' => array(
				'enable_interstitial'          => true,
				'redirect_delay'               => 3,
				'disclaimer_text'              => 'Los precios, disponibilidad y contenido son responsabilidad del sitio externo.',
				'interstitial_title'           => 'Estas saliendo de BunnyChase',
				'interstitial_countdown_text'  => 'Redirigiendo en {seconds}s',
				'interstitial_button_text'     => 'Continuar',
				'show_related_post_excerpt'    => false,
			),
			'appearance' => array(
				'template'     => 'default',
				'button_style' => 'minimal',
			),
		);

		add_option( WPAM_OPTION_KEY, $defaults );
	}
}
