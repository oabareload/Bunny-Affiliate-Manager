<?php
/**
 * Registro de Layouts del bloque de affiliate links.
 *
 * Punto único donde se resuelve qué clase de Layout corresponde a un
 * identificador. Agregar un layout nuevo en el futuro es: crear la clase
 * que implemente Layout_Interface y añadirla aquí (o via el filtro
 * 'wpam_render_layouts' desde un plugin externo) — sin tocar Render_Engine.
 *
 * @package WP_AffiliateManager\Frontend\Layouts
 * @since   1.6.0
 */

namespace WP_AffiliateManager\Frontend\Layouts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Layout_Registry
 *
 * @since 1.6.0
 */
final class Layout_Registry {

	/**
	 * Identificador del layout usado por defecto y como fallback si el
	 * valor guardado en Settings no corresponde a ningún layout registrado.
	 *
	 * @since 1.6.0
	 */
	const DEFAULT_LAYOUT = 'card';

	/**
	 * Retorna el mapa completo id => nombre de clase.
	 *
	 * @since  1.6.0
	 * @return array<string,class-string<Layout_Interface>>
	 */
	public static function get_layouts(): array {
		$layouts = array(
			'card'     => Layout_Card::class,
			'showcase' => Layout_Showcase::class,
		);

		/**
		 * Filtra el mapa de layouts disponibles para el bloque de affiliate links.
		 *
		 * Permite a otros plugins o al propio tema registrar layouts adicionales
		 * sin modificar Render_Engine. Cada clase debe implementar Layout_Interface.
		 *
		 * @since 1.6.0
		 * @param array<string,class-string<Layout_Interface>> $layouts
		 */
		$layouts = (array) apply_filters( 'wpam_render_layouts', $layouts );

		return $layouts;
	}

	/**
	 * Instancia el layout correspondiente a $id.
	 * Si $id no existe en el registro, cae de vuelta a DEFAULT_LAYOUT.
	 *
	 * @since  1.6.0
	 * @param  string $id Identificador del layout (ej: 'card', 'showcase').
	 * @return Layout_Interface
	 */
	public static function get( string $id ): Layout_Interface {
		$layouts = self::get_layouts();

		$class = $layouts[ $id ] ?? $layouts[ self::DEFAULT_LAYOUT ] ?? Layout_Card::class;

		if ( ! class_exists( $class ) || ! is_subclass_of( $class, Layout_Interface::class ) ) {
			return new Layout_Card();
		}

		return new $class();
	}

	/**
	 * Lista de ids válidos, usada por Settings para sanitizar `appearance.layout`.
	 *
	 * @since  1.6.0
	 * @return string[]
	 */
	public static function get_ids(): array {
		return array_keys( self::get_layouts() );
	}
}
