<?php
/**
 * Resource_Resolver — determina qué recurso público se está visualizando.
 *
 * Responsabilidad única: traducir los condicionales de WP_Query (is_404(),
 * is_search(), is_front_page(), is_singular(), etc.) a un par
 * [resource_type, resource_id] que el resto del sistema de Views entiende.
 * No decide elegibilidad (eso es Views::is_eligible()), no escribe nada.
 *
 * Debe llamarse en un contexto donde la query principal ya corrió
 * (wp_enqueue_scripts, template_redirect, wp) — nunca en AJAX, donde no hay
 * WP_Query disponible.
 *
 * PRIORIDAD DE RESOLUCIÓN (importante, ver resolve()): 404 y Search primero
 * (son excluyentes de todo lo demás), luego Home/Front Page (incluye el
 * caso de una Page configurada como portada — debe resolver como 'home',
 * no como 'page'), y solo al final los tipos de contenido genéricos
 * (post/page/category/tag).
 *
 * @package WP_AffiliateManager\Views
 * @since   1.8.0
 */

namespace WP_AffiliateManager\Views;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Resource_Resolver
 *
 * @since 1.8.0
 */
class Resource_Resolver {

	/**
	 * Tipos de recurso soportados. Única fuente de verdad para validación
	 * en Views::is_eligible() / ajax_track() y en Settings.
	 *
	 * @since 1.8.0
	 */
	public const TYPES = array( 'post', 'page', 'home', 'search', '404', 'category', 'tag' );

	/**
	 * Resuelve el recurso de la request actual.
	 *
	 * @since  1.8.0
	 * @return array{resource_type: string, resource_id: int}|null Null si el
	 *         contexto actual no corresponde a ningún tipo soportado (ej.
	 *         un CPT distinto de post/page, un archivo de autor, etc.).
	 */
	public static function resolve(): ?array {
		// 404 y Search tienen prioridad absoluta: son excluyentes de
		// cualquier otra resolución posterior.
		if ( is_404() ) {
			return array(
				'resource_type' => '404',
				'resource_id'   => 0,
			);
		}

		if ( is_search() ) {
			return array(
				'resource_type' => 'search',
				'resource_id'   => 0,
			);
		}

		// Home / Front Page — debe resolverse ANTES que is_singular('page'):
		// si el sitio usa una Page como portada estática, is_front_page()
		// es true pero la visita conceptualmente es "home", no "page".
		// is_home() cubre además el caso de una "posts page" separada de
		// la portada (Settings > Reading > "Posts page").
		if ( is_front_page() || is_home() ) {
			return array(
				'resource_type' => 'home',
				'resource_id'   => 0,
			);
		}

		if ( is_singular( 'post' ) ) {
			return array(
				'resource_type' => 'post',
				'resource_id'   => (int) get_the_ID(),
			);
		}

		if ( is_singular( 'page' ) ) {
			return array(
				'resource_type' => 'page',
				'resource_id'   => (int) get_the_ID(),
			);
		}

		if ( is_category() ) {
			return array(
				'resource_type' => 'category',
				'resource_id'   => (int) get_queried_object_id(),
			);
		}

		if ( is_tag() ) {
			return array(
				'resource_type' => 'tag',
				'resource_id'   => (int) get_queried_object_id(),
			);
		}

		return null;
	}

	/**
	 * Verifica que un resource_id corresponda a contenido real y publicado
	 * para el resource_type dado. Usado en ajax_track() para revalidar
	 * server-side lo que el cliente reportó (nunca se confía en el request).
	 *
	 * home/search/404 no tienen identidad propia (resource_id siempre 0),
	 * así que se consideran válidos por definición — el propio Settings +
	 * las reglas de is_eligible() ya gobiernan si cuentan o no.
	 *
	 * @since  1.8.0
	 * @param  string $resource_type Uno de self::TYPES.
	 * @param  int    $resource_id   ID a validar (irrelevante para home/search/404).
	 * @return bool
	 */
	public static function is_valid_resource( string $resource_type, int $resource_id ): bool {
		switch ( $resource_type ) {
			case 'post':
				return Views::is_valid_post( $resource_id );

			case 'page':
				return self::is_valid_published_post( $resource_id, 'page' );

			case 'category':
				return self::is_valid_term( $resource_id, 'category' );

			case 'tag':
				return self::is_valid_term( $resource_id, 'post_tag' );

			case 'home':
			case 'search':
			case '404':
				return true;

			default:
				return false;
		}
	}

	/**
	 * Valida que un ID sea un post publicado de un post_type dado.
	 *
	 * Duplicado intencional de la lógica equivalente en
	 * Views::is_valid_post() (que está fijada a post_type='post') — mismo
	 * criterio de independencia entre módulos que ya se sigue en el resto
	 * del proyecto (ver apply_filters_to_ids() en las distintas Query
	 * classes).
	 *
	 * @since  1.8.0
	 * @param  int    $id
	 * @param  string $post_type
	 * @return bool
	 */
	private static function is_valid_published_post( int $id, string $post_type ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( $post_type !== $post->post_type ) {
			return false;
		}

		return 'publish' === $post->post_status;
	}

	/**
	 * Valida que un term_id exista en la taxonomía indicada.
	 *
	 * @since  1.8.0
	 * @param  int    $id
	 * @param  string $taxonomy
	 * @return bool
	 */
	private static function is_valid_term( int $id, string $taxonomy ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$term = get_term( $id, $taxonomy );

		return $term instanceof \WP_Term;
	}
}
