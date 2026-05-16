<?php
/**
 * Módulo frontend — clase principal.
 *
 * Gestiona la lógica pública del plugin (renderizado de links, shortcodes, etc.)
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
 * Class Frontend
 *
 * Punto de entrada del módulo público del plugin.
 * Delega todo el renderizado a Render_Engine.
 *
 * @since 1.0.0
 * @since 4.0.0 Integra Render_Engine.
 */
class Frontend {

	/**
	 * Instancia del motor de renderizado.
	 *
	 * @since 4.0.0
	 * @var   Render_Engine
	 */
	private Render_Engine $render_engine;

	/**
	 * Inicialización del módulo frontend.
	 * Se ejecuta en el hook 'wp'.
	 *
	 * @since  1.0.0
	 * @since  4.0.0 Instancia y registra el Render_Engine.
	 * @return void
	 */
	public function init(): void {
		$this->render_engine = new Render_Engine();
		$this->render_engine->register();
	}

	/**
	 * Expone el Render_Engine para uso externo (helpers globales).
	 *
	 * @since  4.0.0
	 * @return Render_Engine
	 */
	public function get_render_engine(): Render_Engine {
		return $this->render_engine;
	}
}
