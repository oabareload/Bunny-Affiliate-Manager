<?php
/**
 * Módulo de asignación de afiliados a posts.
 *
 * Gestiona la relación entre posts y sus afiliados asignados.
 *
 * @package WP_AffiliateManager\Posts
 * @since   1.0.0
 */

namespace WP_AffiliateManager\Posts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Post_Affiliates
 *
 * En FASE 1: estructura base preparada.
 * En FASE 2: implementar meta boxes, guardado de relaciones post<->afiliado.
 *
 * @since 1.0.0
 */
class Post_Affiliates {

	/**
	 * Meta key que almacena los IDs de afiliados asignados al post.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const META_KEY = '_wpam_assigned_affiliates';

	/**
	 * Retorna los afiliados asignados a un post específico.
	 *
	 * @since  1.0.0
	 * @param  int $post_id ID del post.
	 * @return array Lista de IDs de afiliados asignados.
	 */
	public function get_for_post( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Asigna afiliados a un post.
	 *
	 * @since  1.0.0
	 * @param  int   $post_id       ID del post.
	 * @param  int[] $affiliate_ids IDs de afiliados a asignar.
	 * @return bool True si se guardó correctamente.
	 */
	public function assign( int $post_id, array $affiliate_ids ): bool {
		$sanitized = array_map( 'absint', $affiliate_ids );
		return (bool) update_post_meta( $post_id, self::META_KEY, $sanitized );
	}

	/**
	 * Elimina todos los afiliados asignados a un post.
	 *
	 * @since  1.0.0
	 * @param  int $post_id ID del post.
	 * @return bool
	 */
	public function clear( int $post_id ): bool {
		return (bool) delete_post_meta( $post_id, self::META_KEY );
	}

	/**
	 * Verifica si un post tiene afiliados asignados.
	 *
	 * @since  1.0.0
	 * @param  int $post_id ID del post.
	 * @return bool
	 */
	public function has_affiliates( int $post_id ): bool {
		return ! empty( $this->get_for_post( $post_id ) );
	}
}
