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
 * En FASE 1 actúa como placeholder preparado para crecer.
 *
 * @since 1.0.0
 */
class Frontend {

	/**
	 * Inicialización del módulo frontend.
	 * Se ejecuta en el hook 'wp'.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function init(): void {
		// Placeholder para:
		// - Registro de shortcodes (FASE 2).
		// - Hooks de renderizado en el contenido (FASE 2).
		// - Filtros sobre 'the_content' (FASE 2).
	}
}
