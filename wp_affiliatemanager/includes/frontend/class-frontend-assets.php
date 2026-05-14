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
 * Solo carga assets cuando son necesarios (single posts con afiliados).
 *
 * @since 1.0.0
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
	 * @return void
	 */
	public function enqueue_styles(): void {
		// FASE 1: Carga condicional preparada.
		// En FASE 2: solo cargar si el post actual tiene afiliados asignados.
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
	 * @return void
	 */
	public function enqueue_scripts(): void {
		if ( ! $this->should_load_assets() ) {
			return;
		}

		wp_enqueue_script(
			'wpam-frontend-scripts',
			WPAM_PLUGIN_URL . 'assets/js/frontend.js',
			array(),  // Sin jQuery en el frontend para mantener bajo el peso.
			$this->version,
			true
		);
	}

	/**
	 * Determina si se deben cargar los assets del plugin en la página actual.
	 *
	 * FASE 1: Solo carga en single posts (preparado para lógica real en FASE 2).
	 * FASE 2: Verificar también si el post tiene afiliados asignados.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	private function should_load_assets(): bool {
		// Por ahora, solo cargamos en single posts/pages.
		// En FASE 2 esto verificará si el post tiene afiliados reales.
		return is_singular();
	}
}
