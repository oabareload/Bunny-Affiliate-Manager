<?php
/**
 * Views — orquestador del sistema propio de conteo de vistas.
 *
 * Punto único de elegibilidad (is_eligible) y de decisión: decide si se
 * encola el beacon JS en una página dada, y revalida esa misma elegibilidad
 * dentro del handler AJAX antes de escribir en la tabla. View_Tracker no
 * conoce nada de esto — solo hace el upsert.
 *
 * Deduplicación: cookie propia (self::COOKIE_NAME), sin PHP Session.
 * El JS nunca lee ni escribe la cookie (HttpOnly): solo avisa "hubo visita",
 * el servidor decide si ya se contó.
 *
 * @package WP_AffiliateManager\Views
 * @since   1.2.0
 */

namespace WP_AffiliateManager\Views;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Views
 *
 * @since 1.2.0
 */
class Views {

	/**
	 * Nombre de la cookie de deduplicación.
	 *
	 * Contiene una lista CSV de post_ids ya contados en el período actual.
	 *
	 * @since 1.2.0
	 */
	const COOKIE_NAME = 'wpam_v';

	/**
	 * Límite de post_ids retenidos en la cookie por período, para evitar
	 * crecimiento sin límite en sesiones que visitan muchos posts el mismo día.
	 *
	 * @since 1.2.0
	 */
	const COOKIE_MAX_ENTRIES = 300;

	/**
	 * Nombre de la acción del nonce / AJAX.
	 *
	 * @since 1.2.0
	 */
	const AJAX_ACTION = 'wpam_track_view';

	/**
	 * Instancia del tracker (upsert en DB).
	 *
	 * @since 1.2.0
	 * @var   View_Tracker
	 */
	private View_Tracker $tracker;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		$this->tracker = new View_Tracker();
	}

	// -------------------------------------------------------------------------
	// Elegibilidad (fuente única de verdad)
	// -------------------------------------------------------------------------

	/**
	 * Determina si un post_id es válido para contar una vista.
	 *
	 * Punto único de decisión: además de la validez de contenido, aplica las
	 * 3 opciones de Settings (count_admin_views, count_logged_in_users,
	 * count_bot_traffic). Lee current-user y request context internamente
	 * (sin nuevos parámetros), por lo que es seguro llamarlo tanto al decidir
	 * si encolar el beacon como para revalidar el post_id recibido en el
	 * handler AJAX — en ambos contextos hay usuario/cookies de sesión reales.
	 *
	 * @since  1.2.0
	 * @since  1.2.0 Absorbe el filtro de bots (antes en is_bot_request(), llamado
	 *               aparte desde ajax_track()) y añade los checks de admin/logged-in
	 *               gobernados por Settings.
	 *
	 * @param  int $post_id ID del post a validar.
	 * @return bool
	 */
	public function is_eligible( int $post_id ): bool {
		if ( ! self::is_valid_post( $post_id ) ) {
			return false;
		}

		$options = get_option( WPAM_OPTION_KEY, array() );

		// Bots: heurística por user-agent, gobernada por count_bot_traffic.
		$count_bot_traffic = ! empty( $options['views']['count_bot_traffic'] );
		if ( ! $count_bot_traffic && $this->is_bot_request() ) {
			return false;
		}

		// Administradores: setting más específico, tiene prioridad sobre
		// count_logged_in_users para usuarios que pueden manage_options.
		if ( current_user_can( 'manage_options' ) ) {
			return ! empty( $options['views']['count_admin_views'] );
		}

		// Usuarios logueados no-admin.
		if ( is_user_logged_in() ) {
			$count_logged_in_users = $options['views']['count_logged_in_users'] ?? true;
			return (bool) $count_logged_in_users;
		}

		// Invitados: sin restricción por usuario.
		return true;
	}

	/**
	 * Determina si un post_id corresponde a contenido válido y trackeable:
	 * existe, es post_type='post', y está publicado.
	 *
	 * Extraído de is_eligible() como pieza reutilizable independiente de las
	 * reglas de Settings (count_admin_views/count_logged_in_users/count_bot_traffic).
	 * is_eligible() lo usa como primer filtro; otros consumidores del módulo
	 * Views (como Recently_Viewed) lo usan directamente cuando necesitan saber
	 * "¿este post_id es contenido real y publicado?" sin acoplarse a las reglas
	 * específicas de estadísticas.
	 *
	 * @since  1.3.0
	 * @param  int $post_id ID del post a validar.
	 * @return bool
	 */
	public static function is_valid_post( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( 'post' !== $post->post_type ) {
			return false;
		}

		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		return true;
	}

	/**
	 * Determina si la petición HTTP actual proviene de un bot conocido.
	 *
	 * Heurística simple por user-agent, sin dependencias externas. Es una
	 * defensa adicional para el endpoint AJAX (los bots que no ejecutan JS
	 * ya quedan excluidos porque el beacon nunca llega a dispararse).
	 *
	 * @since  1.2.0
	 * @return bool
	 */
	private function is_bot_request(): bool {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		if ( '' === $user_agent ) {
			// Sin user-agent: tratar como no confiable.
			return true;
		}

		return (bool) preg_match(
			'/bot|crawl|spider|slurp|facebookexternalhit|bingpreview|pingdom|uptimerobot|headless/i',
			$user_agent
		);
	}

	// -------------------------------------------------------------------------
	// Frontend: encolado condicional del beacon
	// -------------------------------------------------------------------------

	/**
	 * Encola el beacon JS únicamente en páginas single de un post publicado.
	 *
	 * Colgado de 'wp_enqueue_scripts'. is_singular('post') ya excluye por sí
	 * mismo admin, REST, cron y AJAX (esos contextos no ejecutan la query
	 * principal del frontend), así que no hace falta repetir esos checks aquí.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public function maybe_enqueue_beacon(): void {
		if ( ! is_singular( 'post' ) || is_preview() || is_feed() ) {
			return;
		}

		$post_id = get_the_ID();

		if ( ! $post_id || ! $this->is_eligible( (int) $post_id ) ) {
			return;
		}

		wp_enqueue_script(
			'wpam-views-beacon',
			WPAM_PLUGIN_URL . 'assets/js/views-beacon.js',
			array(),
			WPAM_VERSION,
			true
		);

		// Config del beacon vía objeto global, definido ANTES del script principal
		// con wp_add_inline_script() (sin wp_localize_script()).
		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::AJAX_ACTION,
			'postId'  => (int) $post_id,
			'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
		);

		wp_add_inline_script(
			'wpam-views-beacon',
			'window.wpamViews = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	// -------------------------------------------------------------------------
	// AJAX: registro de la vista
	// -------------------------------------------------------------------------

	/**
	 * Handler de 'wp_ajax_wpam_track_view' / 'wp_ajax_nopriv_wpam_track_view'.
	 *
	 * Flujo: verificar nonce -> revalidar post_id -> filtrar bots -> chequear
	 * cookie de dedup -> upsert -> actualizar cookie -> responder.
	 * Responde inmediatamente en cada punto de salida, sin trabajo extra.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public function ajax_track(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$post_id = absint( $_POST['post_id'] ?? 0 );

		// Revalidación server-side: nunca se confía en el post_id del cliente.
		// is_eligible() ya incluye el filtro de bots y las reglas de Settings
		// (count_admin_views / count_logged_in_users / count_bot_traffic).
		if ( ! $this->is_eligible( $post_id ) ) {
			wp_send_json_success( array( 'counted' => false ) );
		}

		if ( $this->has_viewed_today( $post_id ) ) {
			wp_send_json_success( array( 'counted' => false ) );
		}

		$this->tracker->record( $post_id );
		$this->mark_viewed_today( $post_id );

		// v1.3.0: registrar también en el historial de Recently Viewed (cookie
		// independiente, propio ciclo de vida). No forma parte de is_eligible():
		// Recently_Viewed::track() usa Views::is_valid_post() internamente, sin
		// las reglas de Settings de estadísticas.
		Recently_Viewed::track( $post_id );

		wp_send_json_success( array( 'counted' => true ) );
	}

	// -------------------------------------------------------------------------
	// Cookie de deduplicación
	// -------------------------------------------------------------------------

	/**
	 * Determina si el post_id ya fue contado hoy según la cookie del visitante.
	 *
	 * @since  1.2.0
	 * @param  int $post_id ID del post.
	 * @return bool
	 */
	private function has_viewed_today( int $post_id ): bool {
		return in_array( $post_id, $this->get_cookie_ids(), true );
	}

	/**
	 * Añade el post_id a la cookie de deduplicación del período actual.
	 *
	 * La cookie expira a medianoche UTC, alineada con el corte de `period`
	 * (gmdate('Ymd')) usado en Views_Table, así el dedup y el histórico
	 * diario nunca quedan desincronizados por husos horarios.
	 *
	 * @since  1.2.0
	 * @param  int $post_id ID del post.
	 * @return void
	 */
	private function mark_viewed_today( int $post_id ): void {
		if ( headers_sent() ) {
			return;
		}

		$ids   = $this->get_cookie_ids();
		$ids[] = $post_id;

		// Limitar tamaño para no dejar crecer la cookie sin límite.
		if ( count( $ids ) > self::COOKIE_MAX_ENTRIES ) {
			$ids = array_slice( $ids, -self::COOKIE_MAX_ENTRIES );
		}

		$expire = ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )
			->setTime( 0, 0, 0 )
			->modify( '+1 day' )
			->getTimestamp();

		setcookie(
			self::COOKIE_NAME,
			implode( ',', $ids ),
			array(
				'expires'  => $expire,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Lee y parsea la cookie de deduplicación actual.
	 *
	 * @since  1.2.0
	 * @return int[] Lista de post_ids ya contados en el período actual.
	 */
	private function get_cookie_ids(): array {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return array();
		}

		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );

		if ( '' === $raw ) {
			return array();
		}

		return array_map( 'absint', explode( ',', $raw ) );
	}
}
