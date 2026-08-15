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
 * @since   1.8.0 Generalizado de post_id a resource_type/resource_id vía
 *               Resource_Resolver. Posts conserva exactamente el mismo
 *               comportamiento (misma validación de contenido, mismas
 *               reglas de Settings, mismo cookie/dedup diario).
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
	 * Contiene una lista CSV de claves "{resource_type}:{resource_id}" ya
	 * contadas en el período actual (antes de v1.8.0 solo eran post_ids
	 * numéricos — el prefijo de tipo evita colisiones entre, por ejemplo,
	 * un post_id=5 y un term_id=5 de una categoría).
	 *
	 * @since 1.2.0
	 */
	const COOKIE_NAME = 'wpam_v';

	/**
	 * Límite de entradas retenidas en la cookie por período, para evitar
	 * crecimiento sin límite en sesiones que visitan muchos recursos el
	 * mismo día.
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
	// Settings — tracking habilitado por tipo de recurso
	// -------------------------------------------------------------------------

	/**
	 * Determina si el tracking está habilitado en Settings para un
	 * resource_type dado. Chequeo deliberadamente barato (un solo
	 * get_option(), sin queries) y es SIEMPRE el primer check dentro de
	 * is_eligible(), para no hacer ningún trabajo adicional (validación de
	 * contenido, reglas de admin/bot, etc.) si el tipo está desactivado.
	 *
	 * Posts queda activado por defecto (preserva el comportamiento previo
	 * a v1.8.0); el resto de tipos arrancan desactivados hasta que se
	 * habiliten explícitamente en Settings.
	 *
	 * @since  1.8.0
	 * @param  string $resource_type Uno de Resource_Resolver::TYPES.
	 * @return bool
	 */
	public static function is_type_enabled( string $resource_type ): bool {
		$options  = get_option( WPAM_OPTION_KEY, array() );
		$defaults = self::default_resource_type_toggles();

		if ( ! array_key_exists( $resource_type, $defaults ) ) {
			return false;
		}

		return (bool) ( $options['views']['resource_types'][ $resource_type ] ?? $defaults[ $resource_type ] );
	}

	/**
	 * Defaults de activación por resource_type. Única fuente de verdad,
	 * reutilizada tanto aquí como en Settings::get_defaults().
	 *
	 * @since  1.8.0
	 * @return array<string,bool>
	 */
	public static function default_resource_type_toggles(): array {
		return array(
			'post'     => true,
			'page'     => false,
			'home'     => false,
			'search'   => false,
			'404'      => false,
			'category' => false,
			'tag'      => false,
		);
	}

	// -------------------------------------------------------------------------
	// Elegibilidad (fuente única de verdad)
	// -------------------------------------------------------------------------

	/**
	 * Determina si un recurso es válido para contar una vista.
	 *
	 * Punto único de decisión: primero el toggle de Settings por
	 * resource_type (barato, corta inmediatamente si está desactivado),
	 * luego la validez de contenido específica del tipo, y por último las
	 * 3 opciones de Settings compartidas por todos los tipos
	 * (count_admin_views, count_logged_in_users, count_bot_traffic) — estas
	 * últimas exactamente con la misma lógica que tenían antes de v1.8.0,
	 * ahora aplicadas de forma genérica.
	 *
	 * @since  1.2.0
	 * @since  1.8.0 Generalizado de is_eligible(int $post_id) a
	 *               resource_type + resource_id. Para 'post' el resultado es
	 *               idéntico al de antes (misma is_valid_post(), mismas
	 *               reglas de admin/logged-in/bot).
	 *
	 * @param  string $resource_type Uno de Resource_Resolver::TYPES.
	 * @param  int    $resource_id   ID del recurso a validar.
	 * @return bool
	 */
	public function is_eligible( string $resource_type, int $resource_id ): bool {
		if ( ! self::is_type_enabled( $resource_type ) ) {
			return false;
		}

		if ( ! Resource_Resolver::is_valid_resource( $resource_type, $resource_id ) ) {
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
	 * Sin cambios desde v1.3.0 — sigue siendo la fuente de verdad exclusiva
	 * para Posts, reutilizada también por Resource_Resolver::is_valid_resource()
	 * y por Recently_Viewed.
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
	 * Encola el beacon JS cuando la request actual resuelve a un recurso
	 * soportado y elegible.
	 *
	 * Colgado de 'wp_enqueue_scripts'. Antes de v1.8.0 solo cubría
	 * is_singular('post'); ahora usa Resource_Resolver para cualquiera de
	 * los 7 tipos soportados, pero el resultado para Posts es idéntico
	 * (misma condición efectiva: post singular, publicado, elegible).
	 *
	 * @since  1.2.0
	 * @since  1.8.0 Usa Resource_Resolver en vez de is_singular('post') fijo.
	 * @return void
	 */
	public function maybe_enqueue_beacon(): void {
		if ( is_preview() || is_feed() ) {
			return;
		}

		$resource = Resource_Resolver::resolve();

		if ( null === $resource ) {
			return;
		}

		// is_type_enabled() ya es el primer check dentro de is_eligible(),
		// así que no hace falta duplicarlo aquí — is_eligible() corta de
		// inmediato si el tipo está desactivado, sin validar contenido ni
		// evaluar las reglas de admin/logged-in/bot.
		if ( ! $this->is_eligible( $resource['resource_type'], $resource['resource_id'] ) ) {
			return;
		}

		wp_enqueue_script(
			'wpam-views-beacon',
			WPAM_PLUGIN_URL . 'assets/js/views-beacon.js',
			array(),
			WPAM_VERSION,
			true
		);

		$config = array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'action'       => self::AJAX_ACTION,
			'resourceType' => $resource['resource_type'],
			'resourceId'   => $resource['resource_id'],
			'nonce'        => wp_create_nonce( self::AJAX_ACTION ),
		);

		// Contexto adicional solo para search/404 — nunca se resuelve en
		// AJAX (no hay WP_Query ahí), así que se captura una vez aquí, en
		// el momento en que la query principal ya corrió.
		if ( 'search' === $resource['resource_type'] ) {
			$config['searchTerm'] = (string) get_search_query( false );
		} elseif ( '404' === $resource['resource_type'] ) {
			$config['requestedUrl'] = isset( $_SERVER['REQUEST_URI'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
				: '';
		}

		// Config del beacon vía objeto global, definido ANTES del script principal
		// con wp_add_inline_script() (sin wp_localize_script()).
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
	 * Flujo: verificar nonce -> validar resource_type -> revalidar
	 * resource_id -> filtrar bots -> chequear cookie de dedup -> upsert ->
	 * actualizar cookie -> responder. Responde inmediatamente en cada punto
	 * de salida, sin trabajo extra.
	 *
	 * IMPORTANTE (search/404): no hay WP_Query disponible en admin-ajax.php,
	 * así que search_term/requested_url no pueden revalidarse contra nada —
	 * se sanitizan y normalizan (View_Tracker::normalize_*) igual que
	 * cualquier otro dato de beacon reportado por el cliente. El resource_id
	 * de esos 2 tipos SIEMPRE se fuerza a 0 en el servidor, nunca se confía
	 * en el valor recibido.
	 *
	 * @since  1.2.0
	 * @since  1.8.0 Generalizado a resource_type/resource_id + contexto de
	 *               search/404.
	 * @return void
	 */
	public function ajax_track(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$resource_type = isset( $_POST['resource_type'] )
			? sanitize_key( wp_unslash( $_POST['resource_type'] ) )
			: '';

		if ( ! in_array( $resource_type, Resource_Resolver::TYPES, true ) ) {
			wp_send_json_success( array( 'counted' => false ) );
		}

		// home/search/404 no tienen identidad propia: se fuerza 0 sin
		// importar lo que envíe el cliente.
		$resource_id = in_array( $resource_type, array( 'home', 'search', '404' ), true )
			? 0
			: absint( $_POST['resource_id'] ?? 0 );

		// Revalidación server-side: nunca se confía en el resource_id del
		// cliente. is_eligible() ya incluye el toggle de Settings, la
		// validez de contenido y las reglas de admin/logged-in/bot.
		if ( ! $this->is_eligible( $resource_type, $resource_id ) ) {
			wp_send_json_success( array( 'counted' => false ) );
		}

		$dedup_key = $resource_type . ':' . $resource_id;

		if ( $this->has_viewed_today( $dedup_key ) ) {
			wp_send_json_success( array( 'counted' => false ) );
		}

		$this->tracker->record( $resource_type, $resource_id );
		$this->mark_viewed_today( $dedup_key );

		// v1.3.0: registrar también en el historial de Recently Viewed, solo
		// para Posts (Recently_Viewed::track() ya filtra internamente vía
		// Views::is_valid_post() — pasar otros tipos sería un no-op, pero se
		// evita la llamada directamente por claridad).
		if ( 'post' === $resource_type ) {
			Recently_Viewed::track( $resource_id );
		}

		// Contexto agregado adicional — search/404. No afecta el modelo
		// agregado principal (tablas separadas), solo suma información para
		// Analytics. Se sanitiza/normaliza en el propio tracker.
		if ( 'search' === $resource_type ) {
			$raw_term = isset( $_POST['search_term'] ) ? sanitize_text_field( wp_unslash( $_POST['search_term'] ) ) : '';
			if ( '' !== $raw_term ) {
				$this->tracker->record_search_term( $raw_term );
			}
		} elseif ( '404' === $resource_type ) {
			$raw_url = isset( $_POST['requested_url'] ) ? sanitize_text_field( wp_unslash( $_POST['requested_url'] ) ) : '';
			if ( '' !== $raw_url ) {
				$this->tracker->record_404_url( $raw_url );
			}
		}

		wp_send_json_success( array( 'counted' => true ) );
	}

	// -------------------------------------------------------------------------
	// Cookie de deduplicación
	// -------------------------------------------------------------------------

	/**
	 * Determina si una clave "{type}:{id}" ya fue contada hoy según la
	 * cookie del visitante.
	 *
	 * @since  1.2.0
	 * @since  1.8.0 La clave pasa de int($post_id) a string("{type}:{id}").
	 * @param  string $dedup_key
	 * @return bool
	 */
	private function has_viewed_today( string $dedup_key ): bool {
		return in_array( $dedup_key, $this->get_cookie_ids(), true );
	}

	/**
	 * Añade la clave "{type}:{id}" a la cookie de deduplicación del período actual.
	 *
	 * La cookie expira a medianoche UTC, alineada con el corte de `period`
	 * (gmdate('Ymd')) usado en Views_Table, así el dedup y el histórico
	 * diario nunca quedan desincronizados por husos horarios.
	 *
	 * @since  1.2.0
	 * @since  1.8.0 La clave pasa de int($post_id) a string("{type}:{id}").
	 * @param  string $dedup_key
	 * @return void
	 */
	private function mark_viewed_today( string $dedup_key ): void {
		if ( headers_sent() ) {
			return;
		}

		$ids   = $this->get_cookie_ids();
		$ids[] = $dedup_key;

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
	 * @since  1.8.0 Devuelve claves string "{type}:{id}" en vez de int post_ids.
	 * @return string[] Lista de claves ya contadas en el período actual.
	 */
	private function get_cookie_ids(): array {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return array();
		}

		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );

		if ( '' === $raw ) {
			return array();
		}

		// sanitize_key() por entrada: una cookie manipulada no puede inyectar
		// nada distinto de [a-z0-9_:-], suficiente para el formato "type:id".
		return array_map( 'sanitize_key', explode( ',', $raw ) );
	}
}
