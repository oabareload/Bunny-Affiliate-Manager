<?php
/**
 * Contrato para los Layouts del bloque de affiliate links.
 *
 * Un Layout recibe los links ya resueltos de un post (explícitos + fallback
 * por Default URL, ver Post_Links::get_links()) y produce el HTML completo
 * del bloque, sin el título de sección (ese lo antepone Render_Engine, es
 * compartido por todos los layouts — ver class-render-engine.php).
 *
 * Layouts predefinidos, NO un page builder: cada Layout conoce su propia
 * estructura fija y lee únicamente las opciones de `appearance` que le
 * corresponden, ignorando el resto.
 *
 * @package WP_AffiliateManager\Frontend\Layouts
 * @since   1.6.0
 */

namespace WP_AffiliateManager\Frontend\Layouts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Layout_Interface
 *
 * @since 1.6.0
 */
interface Layout_Interface {

	/**
	 * Identificador único del layout. Debe coincidir con la clave usada en
	 * Layout_Registry y con el valor guardado en `appearance.layout`.
	 *
	 * @since  1.6.0
	 * @return string
	 */
	public static function id(): string;

	/**
	 * Renderiza el bloque completo del layout.
	 *
	 * @since  1.6.0
	 * @param  int      $post_id  ID del post al que pertenecen los links.
	 * @param  array[]  $links    Links ya resueltos y normalizados (ver
	 *                            wpam_get_resolved_post_links()). Puede estar vacío;
	 *                            el layout debe devolver '' en ese caso.
	 * @param  array    $options  Opciones completas de `appearance` (ver Settings::get_defaults()).
	 *                            El layout lee solo las claves que le corresponden.
	 * @return string HTML del bloque, o cadena vacía si no hay nada que mostrar.
	 */
	public function render( int $post_id, array $links, array $options ): string;
}
