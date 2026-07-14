<?php
/**
 * Recently_Viewed -- historial de posts vistos por el visitante, via cookie.
 *
 * Sin base de datos, sin PHP Session. Cookie propia (self::COOKIE_NAME),
 * independiente de Views::COOKIE_NAME ('wpam_v'): esta ultima es un dedup
 * DIARIO (expira a medianoche), mientras que el historial de "vistos
 * recientemente" necesita sobrevivir dias/semanas -- son dos preguntas
 * distintas con dos politicas de expiracion incompatibles en una sola cookie.
 *
 * Reutiliza el pipeline de tracking ya existente (mismo beacon, mismo
 * endpoint AJAX, mismo nonce) -- Views::ajax_track() llama a self::track()
 * justo despues de registrar la vista en wpam_views. No se registra ningun
 * hook ni endpoint AJAX propio para el tracking.
 *
 * Reutiliza Views::is_valid_post() como unico filtro de contenido -- NO usa
 * Views::is_eligible() para no acoplarse a las reglas de Settings de
 * estadisticas (count_admin_views/count_logged_in_users/count_bot_traffic):
 * el historial de navegacion de un visitante no depende de si esa visita
 * "cuenta" para las analiticas agregadas.
 *
 * @package WP_AffiliateManager\Views
 * @since   1.3.0
 */

namespace WP_AffiliateManager\Views;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Recently_Viewed
 *
 * @since 1.3.0
 */
class Recently_Viewed {

	/**
	 * Nombre de la cookie de historial. Independiente de Views::COOKIE_NAME.
	 *
	 * @since 1.3.0
	 */
	const COOKIE_NAME = 'wpam_rv';

	/**
	 * Cuantos post_ids se retienen en la cookie, independientemente de
	 * cuantos se muestren (Settings 'count'). Asi, subir el numero a
	 * mostrar en Settings no pierde historial ya acumulado.
	 *
	 * @since 1.3.0
	 */
	const MAX_STORED = 20;

	/**
	 * Duracion de la cookie: 30 dias. A diferencia de Views::COOKIE_NAME
	 * (que expira a medianoche por diseno, al ser un dedup diario), este
	 * historial debe sobrevivir varias visitas a lo largo de semanas.
	 *
	 * @since 1.3.0
	 */
	const COOKIE_LIFETIME = 30 * DAY_IN_SECONDS;

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	/**
	 * Determina si la funcionalidad esta activada en Settings.
	 *
	 * @since  1.3.0
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$options = get_option( WPAM_OPTION_KEY, array() );
		return ! empty( $options['recently_viewed']['enabled'] );
	}

	/**
	 * Determina si el bloque debe insertarse automaticamente al final del
	 * contenido (vs. solo estar disponible para insercion manual a futuro).
	 *
	 * @since  1.3.0
	 * @return bool
	 */
	private static function is_auto_insert_enabled(): bool {
		$options = get_option( WPAM_OPTION_KEY, array() );
		return $options['recently_viewed']['auto_insert'] ?? true;
	}

	// -------------------------------------------------------------------------
	// Tracking -- llamado desde Views::ajax_track()
	// -------------------------------------------------------------------------

	/**
	 * Registra un post_id en el historial de "vistos recientemente".
	 *
	 * Mueve el post_id a la cabecera de la lista si ya estaba (dedup + orden
	 * por recencia), y trunca a self::MAX_STORED. No hace nada si la
	 * funcionalidad esta deshabilitada en Settings, o si el post_id no es
	 * contenido valido (Views::is_valid_post()).
	 *
	 * @since  1.3.0
	 * @param  int $post_id ID del post visitado.
	 * @return void
	 */
	public static function track( int $post_id ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( ! Views::is_valid_post( $post_id ) ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		$ids = self::get_cookie_ids();

		// Quitar ocurrencia previa (si existia) y colocar al frente.
		$ids = array_values( array_diff( $ids, array( $post_id ) ) );
		array_unshift( $ids, $post_id );

		$ids = array_slice( $ids, 0, self::MAX_STORED );

		setcookie(
			self::COOKIE_NAME,
			implode( ',', $ids ),
			array(
				'expires'  => time() + self::COOKIE_LIFETIME,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	// -------------------------------------------------------------------------
	// Lectura -- sin base de datos, solo la cookie + get_post() (cacheado por WP)
	// -------------------------------------------------------------------------

	/**
	 * Retorna los posts vistos recientemente, mas reciente primero.
	 *
	 * Descarta $exclude_post_id (normalmente el post actual) y cualquier
	 * post_id que ya no exista o haya dejado de estar publicado desde que
	 * se registro la visita.
	 *
	 * @since  1.3.0
	 * @param  int $exclude_post_id Post a excluir del listado (0 = ninguno).
	 * @param  int $limit           Numero maximo de posts a devolver.
	 * @return \WP_Post[]
	 */
	public static function get_recent( int $exclude_post_id = 0, int $limit = 5 ): array {
		$ids = self::get_cookie_ids();

		if ( $exclude_post_id > 0 ) {
			$ids = array_values( array_diff( $ids, array( $exclude_post_id ) ) );
		}

		$posts = array();

		foreach ( $ids as $id ) {
			if ( count( $posts ) >= $limit ) {
				break;
			}

			if ( ! Views::is_valid_post( $id ) ) {
				continue;
			}

			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * Lee y parsea la cookie de historial actual.
	 *
	 * @since  1.3.0
	 * @return int[] post_ids en orden de recencia (mas reciente primero).
	 */
	private static function get_cookie_ids(): array {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return array();
		}

		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );

		if ( '' === $raw ) {
			return array();
		}

		return array_map( 'absint', explode( ',', $raw ) );
	}

	// -------------------------------------------------------------------------
	// Frontend: insercion automatica al final del contenido
	// -------------------------------------------------------------------------

	/**
	 * Filtro 'the_content' (prioridad 21, un paso despues del bloque de
	 * affiliate links en 20). Registrado directamente via Loader en
	 * Plugin::define_frontend_hooks() -- sin la restriccion de timing que
	 * tiene Render_Engine::register(), porque aqui la condicion de Settings
	 * se evalua dentro del propio callback.
	 *
	 * @since  1.3.0
	 * @param  string $content Contenido original del post.
	 * @return string Contenido con el bloque anadido al final, si corresponde.
	 */
	public function inject_after_content( string $content ): string {
		if ( ! self::is_enabled() || ! self::is_auto_insert_enabled() ) {
			return $content;
		}

		if ( ! is_singular( 'post' ) || is_preview() || is_feed() ) {
			return $content;
		}

		if ( ! in_the_loop() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$html = self::render_block( (int) $post_id );

		if ( '' === $html ) {
			return $content;
		}

		return $content . $html;
	}

	// -------------------------------------------------------------------------
	// Renderizado
	// -------------------------------------------------------------------------

	/**
	 * Construye el HTML completo del bloque para un post dado.
	 *
	 * @since  1.3.0
	 * @param  int $current_post_id Post actual (se excluye del listado).
	 * @return string HTML del bloque, o cadena vacia si no hay nada que mostrar.
	 */
	private static function render_block( int $current_post_id ): string {
		$options = get_option( WPAM_OPTION_KEY, array() );
		$count   = max( 1, min( self::MAX_STORED, (int) ( $options['recently_viewed']['count'] ?? 5 ) ) );
		$title   = (string) ( $options['recently_viewed']['title'] ?? __( 'Recently Viewed', 'wp-affiliatemanager' ) );

		$posts = self::get_recent( $current_post_id, $count );

		if ( empty( $posts ) ) {
			return '';
		}

		$items_html = '';
		foreach ( $posts as $post ) {
			$items_html .= self::render_item( $post );
		}

		if ( '' === $items_html ) {
			return '';
		}

		$template_engine = new \WP_AffiliateManager\Templates\Templates();

		$html = $template_engine->render(
			'recently-viewed',
			array(
				'title'      => $title,
				'items_html' => $items_html,
			),
			true
		);

		// Fallback si el template no existe o retorno null.
		if ( null === $html || '' === $html ) {
			$html = '<div class="wpam-recently-viewed">'
				. ( '' !== $title ? '<h2 class="wpam-rv-title">' . esc_html( $title ) . '</h2>' : '' )
				. '<ul class="wpam-rv-list">' . $items_html . '</ul>'
				. '<div class="wpam-rv-clear"></div>'
				. '</div>';
		}

		/**
		 * Filtra el HTML final del bloque Recently Viewed.
		 *
		 * @since 1.3.0
		 * @param string     $html            HTML generado.
		 * @param \WP_Post[] $posts           Posts incluidos en el bloque.
		 * @param int        $current_post_id Post actual.
		 */
		return (string) apply_filters( 'wpam_recently_viewed_html', $html, $posts, $current_post_id );
	}

	/**
	 * Construye el `<li>` de un post individual.
	 *
	 * Estructura visual inspirada en Contextual Related Posts (figure > img
	 * enlazado, sin titulo de post visible), con namespace propio wpam-rv-*.
	 * Si el post no tiene imagen destacada, se omite (el bloque es
	 * exclusivamente visual, una tarjeta sin imagen rompería la consistencia).
	 *
	 * @since  1.3.0
	 * @param  \WP_Post $post Post a renderizar.
	 * @return string `<li>...</li>` o cadena vacia si no tiene thumbnail.
	 */
	private static function render_item( \WP_Post $post ): string {
		$thumb_html = get_the_post_thumbnail(
			$post->ID,
			'thumbnail',
			array(
				'class' => 'wpam-rv-featured wpam-rv-thumb',
				'alt'   => wp_strip_all_tags( get_the_title( $post ) ),
			)
		);

		if ( '' === $thumb_html ) {
			return '';
		}

		return sprintf(
			'<li class="wpam-rv-item"><a href="%1$s" class="wpam-rv-link wpam-rv-post-%2$d"><figure class="wpam-rv-figure">%3$s</figure></a></li>',
			esc_url( get_permalink( $post ) ),
			(int) $post->ID,
			$thumb_html // Generado por get_the_post_thumbnail() (WP core) -- ya seguro.
		);
	}
}
