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
		// Placeholder para inicializaciones admin futuras.
		// Ej: registrar meta boxes, validar nonces, etc.
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
}
