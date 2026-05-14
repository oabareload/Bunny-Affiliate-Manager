<?php
/**
 * Módulo de Afiliados — clase principal.
 *
 * Gestiona el CRUD y lógica de negocio de los afiliados.
 *
 * @package WP_AffiliateManager\Affiliates
 * @since   1.0.0
 */

namespace WP_AffiliateManager\Affiliates;

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Affiliates
 *
 * En FASE 1: clase base preparada con estructura y contratos de métodos.
 * En FASE 2: implementación completa de CRUD sobre CPT o tabla custom.
 *
 * @since 1.0.0
 */
class Affiliates {

	/**
	 * Meta key para los links de afiliados en posts.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const META_KEY_POST_LINKS = '_wpam_affiliate_links';

	/**
	 * Retorna todos los afiliados registrados.
	 *
	 * PLACEHOLDER — implementar en FASE 2.
	 *
	 * @since  1.0.0
	 * @param  array $args Argumentos de filtrado.
	 * @return array Lista de afiliados.
	 */
	public function get_all( array $args = array() ): array {
		// FASE 2: consultar CPT o tabla custom.
		return array();
	}

	/**
	 * Retorna un afiliado por ID.
	 *
	 * PLACEHOLDER — implementar en FASE 2.
	 *
	 * @since  1.0.0
	 * @param  int $id ID del afiliado.
	 * @return array|null Datos del afiliado o null si no existe.
	 */
	public function get_by_id( int $id ): ?array {
		return null;
	}

	/**
	 * Crea o actualiza un afiliado.
	 *
	 * PLACEHOLDER — implementar en FASE 2.
	 *
	 * @since  1.0.0
	 * @param  array $data Datos del afiliado (name, url, category, etc.).
	 * @return int|\WP_Error ID del afiliado creado/actualizado o WP_Error.
	 */
	public function save( array $data ): int|\WP_Error {
		// FASE 2: validar, sanitizar y persistir.
		return new \WP_Error( 'not_implemented', __( 'No implementado en FASE 1.', 'wp-affiliatemanager' ) );
	}

	/**
	 * Elimina un afiliado por ID.
	 *
	 * PLACEHOLDER — implementar en FASE 2.
	 *
	 * @since  1.0.0
	 * @param  int $id ID del afiliado.
	 * @return bool True si fue eliminado correctamente.
	 */
	public function delete( int $id ): bool {
		return false;
	}

	/**
	 * Retorna el total de afiliados registrados.
	 *
	 * PLACEHOLDER — implementar en FASE 2.
	 *
	 * @since  1.0.0
	 * @return int
	 */
	public function get_count(): int {
		return 0;
	}
}
