<?php
/**
 * Módulo de assets del frontend.
 *
 * Registra y encola CSS y JS en el área pública del sitio.
 *
 * @package WP_AffiliateManager\Frontend
 * @since   1.0.0
 */

namespace WP_AffiliateManager\Frontend;

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Frontend_Assets
 *
 * Gestiona el enqueue de assets CSS/JS en el frontend público.
 * FASE 4: Carga real y condicional — solo si el post tiene links activos
 * y el render_mode no está desactivado.
 *
 * @since 1.0.0
 * @since 4.0.0 Carga condicional real por post y render_mode.
 */
class Frontend_Assets {

	/**
	 * Versión del plugin (para cache-busting).
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
	 * Encola hojas de estilo del frontend.
	 * Se ejecuta en 'wp_enqueue_scripts'.
	 *
	 * @since  1.0.0
	 * @since  4.0.0 Carga condicional real: solo si el post tiene links activos.
	 * @return void
	 */
	public function enqueue_styles(): void {
		if ( ! $this->should_load_assets() ) {
			return;
		}

		wp_enqueue_style(
			'wpam-frontend-styles',
			WPAM_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			$this->version
		);
	}

	/**
	 * Encola scripts del frontend.
	 * Se ejecuta en 'wp_enqueue_scripts'.
	 *
	 * @since  1.0.0
	 * @since  4.0.0 Carga condicional real.
	 * @return void
	 */
	public function enqueue_scripts(): void {
		if ( ! $this->should_load_assets() ) {
			return;
		}

		wp_enqueue_script(
			'wpam-frontend-scripts',
			WPAM_PLUGIN_URL . 'assets/js/frontend.js',
			array(), // Sin jQuery para mantener bajo el peso.
			$this->version,
			true
		);
	}

	/**
	 * Determina si se deben cargar los assets del plugin en la página actual.
	 *
	 * FASE 4: Verifica render_mode, singularidad y existencia de links activos.
	 *
	 * @since  1.0.0
	 * @since  4.0.0 Verificación real de links activos y render_mode.
	 * @return bool
	 */
	private function should_load_assets(): bool {
		// No cargar si el renderizado está desactivado globalmente.
		$render_mode = wpam_get_option( 'general.render_mode', 'after_content' );
		if ( 'disabled' === $render_mode ) {
			return false;
		}

		// Solo en entradas/páginas individuales.
		if ( ! is_singular() ) {
			return false;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return false;
		}

		// Solo cargar si el post tiene al menos un link activo (excluye orphans).
		return wpam_post_has_links( $post_id, true );
	}
}
