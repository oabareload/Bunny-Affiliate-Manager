<?php
/**
 * Helpers globales del sistema de links por post.
 *
 * @package WP_AffiliateManager
 * @since   3.0.0
 * @version 0.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retorna todos los affiliate links de un post, normalizados y seguros.
 *
 * Cada link del array incluye las siguientes claves garantizadas:
 *   'provider_id'   (int)    ID del afiliado (wpam_affiliate post ID).
 *   'original_url'  (string) URL base sin parámetros afiliados.
 *   'custom_label'  (string) Etiqueta personalizada. Vacía si no se definió.
 *   'order'         (int)    Posición incremental desde 0.
 *   'final_url'     (string) URL con parámetro afiliado. Vacía si provider inactivo/eliminado.
 *   '_orphan'       (bool)   true si el provider no existe o está inactivo.
 *   '_orphan_title' (string) Nombre del provider huérfano (vacío si fue borrado de la DB).
 *
 * @since  3.0.0
 * @since  0.0.3 Garantiza todas las claves; filtra orphans opcionalemente.
 *
 * @param  int  $post_id      ID del post.
 * @param  bool $active_only  Si true, excluye links con provider huérfano/inactivo.
 * @return array[] Lista de links normalizados. Array vacío si no hay links.
 */
function wpam_get_post_links( int $post_id, bool $active_only = false ): array {
	if ( $post_id <= 0 ) {
		return array();
	}

	$handler = new WP_AffiliateManager\Posts\Post_Links();
	$links   = $handler->get_links( $post_id );

	if ( $active_only ) {
		$links = array_values(
			array_filter( $links, fn( $link ) => ! ( $link['_orphan'] ?? false ) )
		);
	}

	// Garantizar todas las claves en cada item para evitar undefined index en templates.
	return array_map( 'wpam_normalize_link_item', $links );
}

/**
 * Retorna un link específico de un post por su índice (posición base 0).
 *
 * @since  3.0.0
 * @since  0.0.3 Garantiza todas las claves del item retornado.
 *
 * @param  int $post_id ID del post.
 * @param  int $index   Índice del link (base 0).
 * @return array|null   Link normalizado con todas las claves, o null si el índice no existe.
 */
function wpam_get_post_link( int $post_id, int $index ): ?array {
	if ( $post_id <= 0 || $index < 0 ) {
		return null;
	}

	$links = wpam_get_post_links( $post_id );

	if ( ! isset( $links[ $index ] ) ) {
		return null;
	}

	return wpam_normalize_link_item( $links[ $index ] );
}

/**
 * Verifica si un post tiene al menos un affiliate link guardado.
 *
 * @since  3.0.0
 * @param  int  $post_id     ID del post.
 * @param  bool $active_only Si true, solo cuenta links con provider activo.
 * @return bool
 */
function wpam_post_has_links( int $post_id, bool $active_only = false ): bool {
	return wpam_get_post_links_count( $post_id, $active_only ) > 0;
}

/**
 * Retorna el número de affiliate links de un post.
 *
 * @since  3.0.0
 * @since  0.0.3 Acepta parámetro $active_only.
 *
 * @param  int  $post_id     ID del post.
 * @param  bool $active_only Si true, solo cuenta links con provider activo.
 * @return int
 */
function wpam_get_post_links_count( int $post_id, bool $active_only = false ): int {
	return count( wpam_get_post_links( $post_id, $active_only ) );
}

/**
 * Verifica si un link específico tiene un provider huérfano (eliminado o inactivo).
 *
 * @since  0.0.3
 * @param  int $post_id ID del post.
 * @param  int $index   Índice del link (base 0).
 * @return bool true si es huérfano, false si es válido o no existe.
 */
function wpam_post_link_is_orphan( int $post_id, int $index ): bool {
	$link = wpam_get_post_link( $post_id, $index );

	if ( null === $link ) {
		return false;
	}

	return (bool) ( $link['_orphan'] ?? false );
}

/**
 * Normaliza un item de link garantizando que todas las claves estén presentes.
 *
 * Función interna usada por los helpers para hacer seguros los accesos a arrays
 * en templates y shortcodes sin riesgo de undefined index.
 *
 * @since  0.0.3
 * @param  array $item Item de link (parcialmente poblado o completo).
 * @return array Item con todas las claves garantizadas y tipadas correctamente.
 */
function wpam_normalize_link_item( array $item ): array {
	return array(
		'provider_id'   => absint( $item['provider_id']   ?? 0 ),
		'original_url'  => (string) ( $item['original_url']  ?? '' ),
		'custom_label'  => (string) ( $item['custom_label']  ?? '' ),
		'order'         => absint( $item['order']          ?? 0 ),
		'final_url'     => (string) ( $item['final_url']     ?? '' ),
		'_orphan'       => (bool)   ( $item['_orphan']       ?? false ),
		'_orphan_title' => (string) ( $item['_orphan_title'] ?? '' ),
	);
}
