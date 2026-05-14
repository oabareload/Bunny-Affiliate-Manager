<?php
/**
 * Funciones helper globales del plugin.
 *
 * Este archivo contiene funciones de utilidad reutilizables en todo el plugin.
 * NO contiene lógica de negocio. Solo helpers puros.
 *
 * @package WP_AffiliateManager
 * @since   1.0.0
 */

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Helpers de rutas y URLs
// ---------------------------------------------------------------------------

/**
 * Retorna la URL pública a un archivo dentro del plugin.
 *
 * @since  1.0.0
 * @param  string $path Ruta relativa dentro del plugin (sin slash inicial).
 * @return string URL completa.
 */
function wpam_url( string $path = '' ): string {
	return WPAM_PLUGIN_URL . ltrim( $path, '/' );
}

/**
 * Retorna la ruta absoluta del sistema a un archivo dentro del plugin.
 *
 * @since  1.0.0
 * @param  string $path Ruta relativa dentro del plugin (sin slash inicial).
 * @return string Ruta absoluta.
 */
function wpam_path( string $path = '' ): string {
	return WPAM_PLUGIN_PATH . ltrim( $path, '/' );
}

// ---------------------------------------------------------------------------
// Helpers de opciones
// ---------------------------------------------------------------------------

/**
 * Obtiene una opción del plugin almacenada en la DB.
 * Soporta dot-notation para opciones anidadas (ej: 'general.display_mode').
 *
 * @since  1.0.0
 * @param  string $key     Clave de la opción. Soporta dot-notation.
 * @param  mixed  $default Valor por defecto si la opción no existe.
 * @return mixed  Valor de la opción o $default.
 */
function wpam_get_option( string $key, mixed $default = null ): mixed {
	$options = get_option( WPAM_OPTION_KEY, array() );

	if ( ! is_array( $options ) ) {
		return $default;
	}

	// Soporte básico para dot-notation.
	if ( str_contains( $key, '.' ) ) {
		$parts  = explode( '.', $key );
		$cursor = $options;

		foreach ( $parts as $part ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $part, $cursor ) ) {
				return $default;
			}
			$cursor = $cursor[ $part ];
		}

		return $cursor;
	}

	return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
}

/**
 * Guarda una opción del plugin en la DB.
 *
 * @since  1.0.0
 * @param  string $key   Clave de la opción.
 * @param  mixed  $value Valor a guardar (se sanitiza antes de almacenar).
 * @return bool   True si la opción fue actualizada.
 */
function wpam_update_option( string $key, mixed $value ): bool {
	$options = get_option( WPAM_OPTION_KEY, array() );

	if ( ! is_array( $options ) ) {
		$options = array();
	}

	$options[ $key ] = $value;

	return update_option( WPAM_OPTION_KEY, $options );
}

// ---------------------------------------------------------------------------
// Helpers de sanitización
// ---------------------------------------------------------------------------

/**
 * Sanitiza un texto plano (sin HTML).
 *
 * @since  1.0.0
 * @param  mixed $value Valor a sanitizar.
 * @return string Texto sanitizado.
 */
function wpam_sanitize_text( mixed $value ): string {
	return sanitize_text_field( (string) $value );
}

/**
 * Sanitiza una URL.
 *
 * @since  1.0.0
 * @param  mixed $value Valor a sanitizar.
 * @return string URL sanitizada.
 */
function wpam_sanitize_url( mixed $value ): string {
	return esc_url_raw( (string) $value );
}

/**
 * Sanitiza un entero.
 *
 * @since  1.0.0
 * @param  mixed $value Valor a sanitizar.
 * @return int Entero sanitizado.
 */
function wpam_sanitize_int( mixed $value ): int {
	return absint( $value );
}

/**
 * Sanitiza contenido HTML básico (permitiendo tags seguros).
 *
 * @since  1.0.0
 * @param  mixed $value Valor a sanitizar.
 * @return string HTML sanitizado.
 */
function wpam_sanitize_html( mixed $value ): string {
	return wp_kses_post( (string) $value );
}

// ---------------------------------------------------------------------------
// Helpers de escape (output)
// ---------------------------------------------------------------------------

/**
 * Escapa un atributo HTML para output seguro.
 *
 * @since  1.0.0
 * @param  mixed $value Valor a escapar.
 * @return string Valor escapado.
 */
function wpam_esc_attr( mixed $value ): string {
	return esc_attr( (string) $value );
}

/**
 * Escapa una URL para output seguro.
 *
 * @since  1.0.0
 * @param  mixed $value Valor a escapar.
 * @return string URL escapada.
 */
function wpam_esc_url( mixed $value ): string {
	return esc_url( (string) $value );
}

/**
 * Escapa HTML para output seguro.
 *
 * @since  1.0.0
 * @param  mixed $value Valor a escapar.
 * @return string Texto escapado.
 */
function wpam_esc_html( mixed $value ): string {
	return esc_html( (string) $value );
}

// ---------------------------------------------------------------------------
// Helpers de utilidad general
// ---------------------------------------------------------------------------

/**
 * Verifica si el usuario actual tiene permisos de administración del plugin.
 *
 * @since  1.0.0
 * @return bool True si el usuario tiene capacidad 'manage_options'.
 */
function wpam_current_user_can_manage(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Verifica si estamos en el área de administración de WordPress.
 *
 * @since  1.0.0
 * @return bool
 */
function wpam_is_admin(): bool {
	return is_admin() && ! wp_doing_ajax();
}

/**
 * Registra un mensaje en el log de WordPress (solo en modo WP_DEBUG).
 *
 * @since  1.0.0
 * @param  mixed  $message Mensaje o variable a loguear.
 * @param  string $prefix  Prefijo identificador del log.
 * @return void
 */
function wpam_log( mixed $message, string $prefix = 'WPAM' ): void {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}

	if ( is_array( $message ) || is_object( $message ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[' . $prefix . '] ' . print_r( $message, true ) );
	} else {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[' . $prefix . '] ' . (string) $message );
	}
}
