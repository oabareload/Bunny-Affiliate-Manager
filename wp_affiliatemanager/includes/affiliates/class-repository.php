<?php
/**
 * CRUD y consultas de afiliados — capa de datos.
 *
 * Toda la lógica de lectura/escritura de afiliados pasa por esta clase.
 * No contiene UI ni hooks directos.
 *
 * @package WP_AffiliateManager\Affiliates
 * @since   2.0.0
 */

namespace WP_AffiliateManager\Affiliates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Repository
 *
 * Encapsula todas las consultas WP_Query relacionadas con el CPT wpam_affiliate.
 * Devuelve arrays asociativos normalizados (no WP_Post crudos) para desacoplar
 * la UI de la estructura interna de WordPress.
 *
 * @since 2.0.0
 */
class Repository {

	/**
	 * Retorna un afiliado normalizado por ID.
	 *
	 * @since  2.0.0
	 * @param  int $id ID del post (wpam_affiliate).
	 * @return array|null Array normalizado o null si no existe.
	 */
	public function find( int $id ): ?array {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || CPT::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return $this->normalize( $post );
	}

	/**
	 * Retorna todos los afiliados, con opciones de filtrado.
	 *
	 * @since  2.0.0
	 * @param  array $args {
	 *     Argumentos opcionales.
	 *     @type string $status   'any' | 'publish' | 'draft'. Default: 'any'.
	 *     @type bool   $active   Si true, solo afiliados activos. Default: false.
	 *     @type int    $per_page Número de resultados. Default: -1 (todos).
	 *     @type int    $paged    Página actual. Default: 1.
	 *     @type string $orderby  Campo de orden. Default: 'title'.
	 *     @type string $order    'ASC' | 'DESC'. Default: 'ASC'.
	 * }
	 * @return array{items: array[], total: int}
	 */
	public function find_all( array $args = array() ): array {
		$defaults = array(
			'status'   => 'any',
			'active'   => false,
			'per_page' => -1,
			'paged'    => 1,
			'orderby'  => 'title',
			'order'    => 'ASC',
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'post_type'      => CPT::POST_TYPE,
			'post_status'    => $args['status'],
			'posts_per_page' => $args['per_page'],
			'paged'          => $args['paged'],
			'orderby'        => $args['orderby'],
			'order'          => $args['order'],
			'no_found_rows'  => ( -1 === $args['per_page'] ), // Optimización: omitir COUNT si no pagina.
		);

		// Filtro por activo/inactivo via meta query.
		if ( $args['active'] ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => Meta::KEY_ACTIVE,
					'value' => '1',
				),
			);
		}

		$query = new \WP_Query( $query_args );
		$items = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$items[] = $this->normalize( $post );
			}
		}

		return array(
			'items' => $items,
			'total' => $query->found_posts,
		);
	}

	/**
	 * Retorna el total de afiliados registrados.
	 *
	 * @since  2.0.0
	 * @param  bool $only_active Si true, cuenta solo los activos.
	 * @return int
	 */
	public function count( bool $only_active = false ): int {
		$result = $this->find_all( array(
			'active'   => $only_active,
			'per_page' => 1,
		) );

		return $result['total'];
	}

	/**
	 * Busca el primer afiliado activo cuyo campo `domains` contenga el dominio dado.
	 *
	 * Reglas de matching (sin PSL):
	 *   - Exacto:  "amazon.com"     matches "amazon.com"
	 *   - Sufijo:  "shop.genki.com" matches "genki.com" (termina en ".genki.com")
	 *   - NO:      "amazon.com.mx"  NO matches "amazon.com"
	 *
	 * El campo `domains` es una cadena separada por comas, ej: "amazon.com, amzn.to".
	 * Cada entry se normaliza con wpam_normalize_domain() antes de comparar.
	 *
	 * @since  0.1.3
	 * @param  string $domain Dominio ya normalizado (sin www., sin protocolo).
	 *                        Usar wpam_extract_domain_from_url() para obtenerlo de una URL.
	 * @return array|null Afiliado normalizado o null si no hay coincidencia.
	 */
	public function find_by_domain( string $domain ): ?array {
		$domain = strtolower( trim( $domain ) );

		if ( ! $domain ) {
			return null;
		}

		$result = $this->find_all( array( 'active' => true, 'per_page' => -1 ) );

		foreach ( $result['items'] as $affiliate ) {
			$raw_domains = trim( $affiliate['domains'] ?? '' );

			if ( ! $raw_domains ) {
				continue;
			}

			foreach ( explode( ',', $raw_domains ) as $entry ) {
				$aff_domain = wpam_normalize_domain( $entry );

				if ( ! $aff_domain ) {
					continue;
				}

				// Match exacto.
				if ( $domain === $aff_domain ) {
					return $affiliate;
				}

				// Match por sufijo: "shop.genki.com" termina en ".genki.com".
				if ( str_ends_with( $domain, '.' . $aff_domain ) ) {
					return $affiliate;
				}
			}
		}

		return null;
	}

	/**
	 * Crea o actualiza un afiliado.
	 *
	 * @since  2.0.0
	 * @param  array $data {
	 *     @type int    $id          ID del post a actualizar (0 para crear).
	 *     @type string $title       Nombre del afiliado. Requerido.
	 *     @type string $slug        Slug interno.
	 *     @type string $param       Parámetro URL.
	 *     @type string $value       Valor del parámetro.
	 *     @type string $logo_url    URL del logo.
	 *     @type string $brand_color Color hex de la marca.
	 *     @type bool   $active      Estado activo.
	 * }
	 * @return int|\WP_Error ID del post creado/actualizado o WP_Error.
	 */
	public function save( array $data ): int|\WP_Error {
		$id    = absint( $data['id'] ?? 0 );
		$title = sanitize_text_field( $data['title'] ?? '' );

		if ( ! $title ) {
			return new \WP_Error( 'wpam_missing_title', __( 'Affiliate name is required.', 'wp-affiliatemanager' ) );
		}

		$post_data = array(
			'post_type'   => CPT::POST_TYPE,
			'post_title'  => $title,
			'post_status' => 'publish',
		);

		if ( $id > 0 ) {
			$post_data['ID'] = $id;
			$result          = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = (int) $result;

		// Guardar metadata.
		update_post_meta( $post_id, Meta::KEY_SLUG,        sanitize_title( $data['slug'] ?? '' ) );
		update_post_meta( $post_id, Meta::KEY_PARAM,       sanitize_key( $data['param'] ?? '' ) );
		update_post_meta( $post_id, Meta::KEY_VALUE,       sanitize_text_field( $data['value'] ?? '' ) );
		update_post_meta( $post_id, Meta::KEY_LOGO_URL,    esc_url_raw( $data['logo_url'] ?? '' ) );
		update_post_meta( $post_id, Meta::KEY_BRAND_COLOR, sanitize_hex_color( $data['brand_color'] ?? '#6c47ff' ) ?? '#6c47ff' );
		update_post_meta( $post_id, Meta::KEY_ACTIVE,      ! empty( $data['active'] ) ? '1' : '0' );
		update_post_meta( $post_id, Meta::KEY_DOMAINS,     sanitize_textarea_field( $data['domains'] ?? '' ) );
		update_post_meta( $post_id, Meta::KEY_VISIBLE,     ! empty( $data['visible'] ) ? '1' : '0' );
		update_post_meta( $post_id, Meta::KEY_USE_GLOBAL_DISCLAIMER, ! empty( $data['use_global_disclaimer'] ) ? '1' : '0' );
		update_post_meta( $post_id, Meta::KEY_CUSTOM_DISCLAIMER,     wp_kses_post( $data['custom_disclaimer'] ?? '' ) );

		$related_post_id = absint( $data['related_post_id'] ?? 0 );
		if ( $related_post_id > 0 && 'post' !== get_post_type( $related_post_id ) ) {
			$related_post_id = 0;
		}
		update_post_meta( $post_id, Meta::KEY_RELATED_POST_ID, $related_post_id );

		return $post_id;
	}

	/**
	 * Elimina un afiliado permanentemente.
	 *
	 * @since  2.0.0
	 * @param  int $id ID del afiliado.
	 * @return bool True si fue eliminado.
	 */
	public function delete( int $id ): bool {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || CPT::POST_TYPE !== $post->post_type ) {
			return false;
		}

		return (bool) wp_delete_post( $id, true ); // true = borrado permanente (sin papelera).
	}

	/**
	 * Activa o desactiva un afiliado.
	 *
	 * @since  2.0.0
	 * @param  int  $id     ID del afiliado.
	 * @param  bool $active True para activar, false para desactivar.
	 * @return bool
	 */
	public function set_active( int $id, bool $active ): bool {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || CPT::POST_TYPE !== $post->post_type ) {
			return false;
		}

		return (bool) update_post_meta( $id, Meta::KEY_ACTIVE, $active ? '1' : '0' );
	}

	/**
	 * Normaliza un WP_Post de tipo wpam_affiliate a un array estandarizado.
	 *
	 * @since  2.0.0
	 * @param  \WP_Post $post Objeto post de WordPress.
	 * @return array Array normalizado con todos los campos del afiliado.
	 */
	public function normalize( \WP_Post $post ): array {
		$is_active = get_post_meta( $post->ID, Meta::KEY_ACTIVE, true );
		$use_global_disclaimer = get_post_meta( $post->ID, Meta::KEY_USE_GLOBAL_DISCLAIMER, true );

		return array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'slug'        => get_post_meta( $post->ID, Meta::KEY_SLUG,        true ),
			'param'       => get_post_meta( $post->ID, Meta::KEY_PARAM,       true ),
			'value'       => get_post_meta( $post->ID, Meta::KEY_VALUE,       true ),
			'logo_url'    => get_post_meta( $post->ID, Meta::KEY_LOGO_URL,    true ),
			'brand_color' => get_post_meta( $post->ID, Meta::KEY_BRAND_COLOR, true ) ?: '#6c47ff',
			'active'      => '' === $is_active ? true : '1' === $is_active,
			'domains'     => get_post_meta( $post->ID, Meta::KEY_DOMAINS,     true ) ?: '',
			'visible'     => '' === get_post_meta( $post->ID, Meta::KEY_VISIBLE, true ) ? true : '1' === get_post_meta( $post->ID, Meta::KEY_VISIBLE, true ),
			'use_global_disclaimer' => '' === $use_global_disclaimer ? true : '1' === $use_global_disclaimer,
			'custom_disclaimer'     => get_post_meta( $post->ID, Meta::KEY_CUSTOM_DISCLAIMER, true ) ?: '',
			'related_post_id'       => absint( get_post_meta( $post->ID, Meta::KEY_RELATED_POST_ID, true ) ),
			'edit_url'    => get_edit_post_link( $post->ID, 'raw' ),
			'created_at'  => $post->post_date,
		);
	}
}
