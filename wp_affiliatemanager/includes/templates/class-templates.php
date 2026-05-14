<?php
/**
 * Módulo de Templates.
 *
 * Gestiona los templates de renderizado de los links de afiliados en el frontend.
 *
 * @package WP_AffiliateManager\Templates
 * @since   1.0.0
 */

namespace WP_AffiliateManager\Templates;

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Templates
 *
 * Sistema de templates con soporte para override desde el tema activo.
 * Convención: el tema puede sobreescribir templates en /wp-content/themes/{theme}/wpam/
 *
 * @since 1.0.0
 */
class Templates {

	/**
	 * Directorio de templates dentro del plugin.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	private string $templates_dir;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->templates_dir = WPAM_PLUGIN_PATH . 'includes/templates/views/';
	}

	/**
	 * Carga y renderiza un template.
	 *
	 * Busca primero en el tema activo (override), luego en el plugin.
	 * Permite pasar variables al template de forma segura.
	 *
	 * @since  1.0.0
	 * @param  string $template_name Nombre del archivo template (ej: 'affiliate-card').
	 * @param  array  $args          Variables a exponer en el template.
	 * @param  bool   $return        Si true, retorna el HTML en lugar de imprimirlo.
	 * @return string|void HTML del template o void si $return es false.
	 */
	public function render( string $template_name, array $args = array(), bool $return = false ): ?string {
		$template_file = $this->locate_template( $template_name );

		if ( ! $template_file ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			trigger_error(
				sprintf( 'WPAM Template no encontrado: %s', esc_html( $template_name ) ),
				E_USER_NOTICE
			);
			return null;
		}

		// Extraer $args al scope del template de forma controlada.
		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			extract( $args, EXTR_SKIP );
		}

		if ( $return ) {
			ob_start();
			include $template_file;
			return ob_get_clean() ?: '';
		}

		include $template_file;
		return null;
	}

	/**
	 * Localiza el archivo de template.
	 * Prioridad: tema activo > plugin.
	 *
	 * @since  1.0.0
	 * @param  string $template_name Nombre del template (sin extensión .php).
	 * @return string|null Ruta absoluta al archivo o null si no existe.
	 */
	private function locate_template( string $template_name ): ?string {
		$template_name = sanitize_file_name( $template_name ) . '.php';

		// 1. Buscar override en el tema activo.
		$theme_template = get_stylesheet_directory() . '/wpam/' . $template_name;
		if ( file_exists( $theme_template ) ) {
			return $theme_template;
		}

		// 2. Buscar en el tema padre (si existe).
		$parent_theme_template = get_template_directory() . '/wpam/' . $template_name;
		if ( file_exists( $parent_theme_template ) ) {
			return $parent_theme_template;
		}

		// 3. Usar template del plugin.
		$plugin_template = $this->templates_dir . $template_name;
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return null;
	}

	/**
	 * Retorna el directorio de templates del plugin.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_templates_dir(): string {
		return $this->templates_dir;
	}
}
