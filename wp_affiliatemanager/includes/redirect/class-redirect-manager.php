<?php
/**
 * Redirect Manager — gestiona el endpoint /go/{token}.
 *
 * Responsabilidades:
 *  - Registrar la rewrite rule y la query var de WordPress.
 *  - Reconstruir el mapa de tokens cuando se guarda un post.
 *  - Resolver el token → post_id + link_index.
 *  - Registrar el click vía Click_Tracker.
 *  - Ejecutar wp_redirect() al destino real.
 *  - Fallback seguro a home_url() en cualquier caso de error.
 *
 * Lo que NO hace todavía:
 *  - No renderiza HTML (ni disclaimer, ni countdown, ni ads).
 *  - No crea páginas WordPress.
 *  - No usa plantillas custom.
 *
 * @package WP_AffiliateManager\Redirect
 * @since   0.2.0-alpha1
 */

namespace WP_AffiliateManager\Redirect;

use WP_AffiliateManager\Posts\Post_Links;
use WP_AffiliateManager\Affiliates\Repository;
use WP_AffiliateManager\Views\Views;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Redirect_Manager
 *
 * @since 0.2.0-alpha1
 */
class Redirect_Manager {

	/** Nombre de la query var de WordPress para el token. */
	const QUERY_VAR = 'wpam_go';

	/** Option key donde se almacena el mapa token => [ post_id, link_index ]. */
	const TOKEN_MAP_OPTION = 'wpam_redirect_tokens';

	/** Prefijo del slug de la rewrite rule. */
	const SLUG = 'go';

	/**
	 * Prefijo de la rewrite rule para links de fallback (Default URL).
	 * Ruta corta, sin estado: /goa/{post_id}/{affiliate_id}/
	 *
	 * @since 1.5.0
	 */
	const SLUG_DEFAULT = 'goa';

	/** @since 1.5.0 Query var para el post_id en la ruta /goa/. */
	const QUERY_VAR_DEFAULT_POST = 'wpam_goa_post';

	/** @since 1.5.0 Query var para el affiliate_id en la ruta /goa/. */
	const QUERY_VAR_DEFAULT_AFFILIATE = 'wpam_goa_affiliate';

	/**
	 * Prefijo del slug para el interstitial de enlaces externos genéricos
	 * dentro del contenido (no ligados a un afiliado). Ruta: /goext/{sig}/?u={url}
	 *
	 * La URL de destino viaja en el query string estándar (?u=), no en el
	 * path — no requiere registrarse como query var de WordPress: PHP la
	 * expone siempre en $_GET independientemente del rewrite, igual que
	 * ocurre con cualquier parámetro adicional anexado a una URL reescrita.
	 *
	 * @since 1.8.9
	 */
	const SLUG_EXTERNAL = 'goext';

	/** @since 1.8.9 Query var para la firma HMAC en la ruta /goext/. */
	const QUERY_VAR_EXTERNAL_SIG = 'wpam_goext_sig';

	/**
	 * Longitud en caracteres hex de la firma HMAC de /goext/.
	 *
	 * @since 1.8.9
	 */
	const EXTERNAL_SIG_LENGTH = 16;

	// -------------------------------------------------------------------------
	// Registro de rewrite rule y query var
	// -------------------------------------------------------------------------

	/**
	 * Registra la rewrite rule /go/{token} y la query var.
	 * Hook: init
	 *
	 * @since  0.2.0-alpha1
	 * @return void
	 */
	public function register_rewrite(): void {
		add_rewrite_rule(
			'^' . self::SLUG . '/([a-f0-9]{8})/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);

		// v1.5.0: ruta corta y sin estado para links de fallback (Default URL).
		// No usa el mapa de tokens: post_id y affiliate_id van en claro porque
		// resolve_default() valida en vivo contra Post_Links::get_links(), no
		// hay información sensible que ocultar (mismos hosts que allow_redirect_hosts()
		// ya permite para los links explícitos).
		add_rewrite_rule(
			'^' . self::SLUG_DEFAULT . '/([0-9]+)/([0-9]+)/?$',
			'index.php?' . self::QUERY_VAR_DEFAULT_POST . '=$matches[1]&' . self::QUERY_VAR_DEFAULT_AFFILIATE . '=$matches[2]',
			'top'
		);

		// v1.8.9: interstitial genérico para enlaces externos dentro del
		// contenido. La firma va en el path (validada server-side contra HMAC),
		// la URL de destino viaja en el query string (?u=) y no necesita
		// query var propia (ver nota en QUERY_VAR_EXTERNAL_SIG).
		add_rewrite_rule(
			'^' . self::SLUG_EXTERNAL . '/([a-f0-9]{' . self::EXTERNAL_SIG_LENGTH . '})/?$',
			'index.php?' . self::QUERY_VAR_EXTERNAL_SIG . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Registra la query var para que WordPress la reconozca.
	 * Hook: query_vars
	 *
	 * @since  0.2.0-alpha1
	 * @param  string[] $vars Query vars existentes.
	 * @return string[]
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::QUERY_VAR_DEFAULT_POST;
		$vars[] = self::QUERY_VAR_DEFAULT_AFFILIATE;
		$vars[] = self::QUERY_VAR_EXTERNAL_SIG;
		return $vars;
	}

	// -------------------------------------------------------------------------
	// Manejo del redirect
	// -------------------------------------------------------------------------

	/**
	 * Intercepta la petición si contiene el query var del plugin.
	 * Hook: template_redirect
	 *
	 * Flujo:
	 *  1. Leer token de la query var.
	 *  2. Resolver token => [ post_id, link_index ] desde el mapa en options.
	 *  3. Obtener el link del post y la URL final.
	 *  4. Registrar el click.
	 *  5. Redirigir.
	 *
	 * Cualquier fallo en cualquier paso => fallback a home_url().
	 *
	 * @since  0.2.0-alpha1
	 * @return void
	 */
	public function handle(): void {
		$token            = get_query_var( self::QUERY_VAR, '' );
		$goa_post_id      = absint( get_query_var( self::QUERY_VAR_DEFAULT_POST, 0 ) );
		$goa_affiliate_id = absint( get_query_var( self::QUERY_VAR_DEFAULT_AFFILIATE, 0 ) );
		$is_goa           = ( $goa_post_id > 0 && $goa_affiliate_id > 0 );

		// v1.8.9: interstitial genérico para enlaces externos del contenido.
		// La sig viaja como query var (path), la URL destino como $_GET['u']
		// crudo (nunca se registra como query var de WP, ver constante).
		$goext_sig = (string) get_query_var( self::QUERY_VAR_EXTERNAL_SIG, '' );
		$goext_url = isset( $_GET['u'] ) ? esc_url_raw( wp_unslash( $_GET['u'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_goext  = ( '' !== $goext_sig && '' !== $goext_url );

		if ( '' === $token && ! $is_goa && ! $is_goext ) {
			return; // No es nuestra petición.
		}

		$options        = get_option( WPAM_OPTION_KEY, array() );
		$exclude_admins = ! empty( $options['general']['exclude_admins_from_analytics'] );

		try {
			if ( $is_goext ) {
				$destination = $this->resolve_external( $goext_url, $goext_sig );
			} elseif ( $is_goa ) {
				$destination = $this->resolve_default( $goa_post_id, $goa_affiliate_id );
			} else {
				$destination = $this->resolve( $token );
			}
		} catch ( \Throwable $e ) {
			$destination = null;
		}

		if ( null === $destination ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		// Los enlaces externos genéricos no pasan por el view-gate de
		// reCAPTCHA/cookie (es una protección específica del flujo de
		// afiliados) ni registran Click en wpam_clicks (no hay affiliate_id
		// real al que asociar la fila) — solo reutilizan el interstitial y el
		// redirect seguro existentes.
		if ( $is_goext ) {
			$this->continue_external_redirect( $destination );
			return;
		}

		$resource_type = 'post';
		$resource_id   = (int) $destination['post_id'];
		$has_valid_cookie = Views::has_valid_view_cookie( $resource_type, $resource_id );

		if ( ! $has_valid_cookie ) {
			$this->handle_missing_view_gate( $destination, $token, $goa_post_id, $goa_affiliate_id );
			return;
		}

		$this->continue_authorized_redirect( $destination, $token, $goa_post_id, $goa_affiliate_id );
	}

	/**
	 * Continúa el flujo normal del redirect una vez que el navegador ya está
	 * autorizado por cookie o por validación CAPTCHA.
	 *
	 * @param  array  $destination
	 * @param  string $token
	 * @param  int    $goa_post_id
	 * @param  int    $goa_affiliate_id
	 * @return void
	 */
	private function continue_authorized_redirect( array $destination, string $token, int $goa_post_id = 0, int $goa_affiliate_id = 0 ): void {
		$options        = get_option( WPAM_OPTION_KEY, array() );
		$exclude_admins = ! empty( $options['general']['exclude_admins_from_analytics'] );

		if ( ! ( $exclude_admins && current_user_can( 'manage_options' ) ) ) {
			try {
				$tracker = new Click_Tracker();
				$tracker->record(
					$destination['post_id'],
					$destination['affiliate_id'],
					$destination['url']
				);
			} catch ( \Throwable $e ) {
				// Silenciar: el tracking no puede impedir el redirect.
			}
		}

		// v1.8.11 (fix): el UTM se aplica aquí, una sola vez, ANTES de decidir
		// entre interstitial o redirect directo. El interstitial construye su
		// propio destino (botón + JS) a partir de $destination['url'] y nunca
		// pasaba por redirect_to_destination(), por lo que el UTM se perdía
		// cuando enable_interstitial + delay > 0 (el caso por defecto).
		$destination['url'] = $this->append_outbound_utm( $destination['url'] );

		$enable_interstitial = ! empty( $options['redirect']['enable_interstitial'] ?? true );
		$delay               = absint( $options['redirect']['redirect_delay'] ?? 3 );

		if ( $enable_interstitial && $delay > 0 ) {
			$renderer = new Interstitial_Renderer();
			$renderer->render( array_merge( $destination, array( 'token' => $token ) ) );
			return;
		}

		$this->redirect_to_destination( $destination['url'] );
	}

	/**
	 * Continúa el flujo de un enlace externo genérico (no afiliado) hacia el
	 * interstitial existente. A diferencia de continue_authorized_redirect():
	 * no registra Click (no hay affiliate_id real) y no pasa por el view-gate
	 * de reCAPTCHA/cookie (protección específica del flujo de afiliados).
	 *
	 * @since  1.8.9
	 * @param  array $destination { @type int $post_id 0, @type int $affiliate_id 0, @type string $url }
	 * @return void
	 */
	private function continue_external_redirect( array $destination ): void {
		$options              = get_option( WPAM_OPTION_KEY, array() );

		// v1.8.11 (fix): mismo criterio que continue_authorized_redirect() —
		// aplicar el UTM antes de la bifurcación, para que también llegue al
		// destino usado por el interstitial (botón + JS), no solo al redirect directo.
		$destination['url']   = $this->append_outbound_utm( $destination['url'] );
		$enable_interstitial = ! empty( $options['redirect']['enable_interstitial'] ?? true );
		$delay                = absint( $options['redirect']['redirect_delay'] ?? 3 );

		if ( $enable_interstitial && $delay > 0 ) {
			$renderer = new Interstitial_Renderer();
			$renderer->render( array_merge( $destination, array( 'token' => '' ) ) );
			return;
		}

		$this->redirect_to_destination( $destination['url'] );
	}

	/**
	 * Gestiona el caso en que el navegador no tiene una View válida para este
	 * recurso. El comportamiento seguro es bloquear el redirect y exigir
	 * validación reCAPTCHA antes de registrar View o Click.
	 *
	 * @param  array  $destination
	 * @param  string $token
	 * @param  int    $goa_post_id
	 * @param  int    $goa_affiliate_id
	 * @return void
	 */
	private function handle_missing_view_gate( array $destination, string $token, int $goa_post_id = 0, int $goa_affiliate_id = 0 ): void {
		$options = get_option( WPAM_OPTION_KEY, array() );
		$recaptcha_enabled = ! empty( $options['recaptcha']['enabled'] ?? false );

		if ( ! $recaptcha_enabled ) {
			status_header( 403 );
			echo '<!doctype html><html><head><meta charset="UTF-8"><title>Access denied</title></head><body><p>' . esc_html__( 'A valid page view is required before this affiliate link may be followed.', 'wp-affiliatemanager' ) . '</p></body></html>';
			exit;
		}

		if ( 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$token_response = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
			if ( '' !== $token_response && $this->verify_recaptcha( $token_response ) ) {
				$views = new Views();
				$views->record_valid_view( 'post', (int) $destination['post_id'] );
				$this->continue_authorized_redirect( $destination, $token, $goa_post_id, $goa_affiliate_id );
				return;
			}
		}

		$this->render_recaptcha_page( $destination, $token, $goa_post_id, $goa_affiliate_id );
	}

	/**
	 * Renderiza una pantalla con reCAPTCHA v2 Checkbox antes de permitir el redirect.
	 *
	 * @param  array  $destination
	 * @param  string $token
	 * @param  int    $goa_post_id
	 * @param  int    $goa_affiliate_id
	 * @return void
	 */
	private function render_recaptcha_page( array $destination, string $token, int $goa_post_id = 0, int $goa_affiliate_id = 0 ): void {
		$options = get_option( WPAM_OPTION_KEY, array() );
		$site_key = sanitize_text_field( $options['recaptcha']['site_key'] ?? '' );
		$action_url = add_query_arg(
			array(
				self::QUERY_VAR => $token,
				self::QUERY_VAR_DEFAULT_POST => $goa_post_id,
				self::QUERY_VAR_DEFAULT_AFFILIATE => $goa_affiliate_id,
			),
			home_url( '/' )
		);

		if ( '' === $site_key ) {
			status_header( 403 );
			echo '<!doctype html><html><head><meta charset="UTF-8"><title>reCAPTCHA not configured</title></head><body><p>' . esc_html__( 'Google reCAPTCHA is enabled but not configured. Please contact the site administrator.', 'wp-affiliatemanager' ) . '</p></body></html>';
			exit;
		}

		header( 'Content-Type: text/html; charset=UTF-8' );
		?>
		<!doctype html>
		<html lang="<?php echo esc_attr( get_locale() ); ?>">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Verify before continuing', 'wp-affiliatemanager' ); ?></title>
			<style>
				body{font-family:Arial,sans-serif;background:#f3f4f6;color:#1f2937;padding:32px 16px;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0} 
				.card{max-width:460px;background:#fff;border-radius:12px;padding:24px;box-shadow:0 12px 30px rgba(0,0,0,.08)}
				h1{margin-top:0;font-size:1.4rem} p{color:#4b5563;line-height:1.5} .g-recaptcha{margin:20px 0}.btn{display:inline-block;padding:10px 16px;border-radius:8px;background:#111827;color:#fff;text-decoration:none}
			</style>
			<script src="https://www.google.com/recaptcha/api.js" async defer></script>
		</head>
		<body>
			<div class="card">
				<h1><?php esc_html_e( 'Confirm you are human', 'wp-affiliatemanager' ); ?></h1>
				<p><?php esc_html_e( 'A valid page view is required before this affiliate link can be opened.', 'wp-affiliatemanager' ); ?></p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>">
					<?php if ( '' !== $token ) : ?><input type="hidden" name="wpam_go" value="<?php echo esc_attr( $token ); ?>" /><?php endif; ?>
					<?php if ( $goa_post_id > 0 ) : ?><input type="hidden" name="wpam_goa_post" value="<?php echo esc_attr( (string) $goa_post_id ); ?>" /><?php endif; ?>
					<?php if ( $goa_affiliate_id > 0 ) : ?><input type="hidden" name="wpam_goa_affiliate" value="<?php echo esc_attr( (string) $goa_affiliate_id ); ?>" /><?php endif; ?>
					<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $site_key ); ?>"></div>
					<p><button type="submit" class="btn"><?php esc_html_e( 'Continue', 'wp-affiliatemanager' ); ?></button></p>
				</form>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Verifica el token de Google reCAPTCHA v2 Checkbox server-side.
	 *
	 * @param  string $response
	 * @return bool
	 */
	private function verify_recaptcha( string $response ): bool {
		$options = get_option( WPAM_OPTION_KEY, array() );
		$secret = sanitize_text_field( $options['recaptcha']['secret_key'] ?? '' );
		if ( '' === $secret || '' === $response ) {
			return false;
		}

		$body = array(
			'secret'   => $secret,
			'response' => $response,
			'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		);

		$resp = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 10,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $resp ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $data ) && ! empty( $data['success'] );
	}

	/**
	 * Añade (o reemplaza) el parámetro `utm_source` configurable al destino
	 * final externo. Punto único para los tres flujos (/go/, /goa/, /goext/),
	 * ya que todos convergen en redirect_to_destination() antes del
	 * wp_safe_redirect().
	 *
	 * add_query_arg() extrae el fragment (#...) antes de tocar la query
	 * string y lo reañade al final, así que preserva correctamente URLs con
	 * fragment sin necesidad de parsing manual. También urlencodea el valor
	 * y reemplaza (no duplica) un `utm_source` ya presente en la URL.
	 *
	 * @since  1.8.11
	 * @param  string $url URL de destino externa.
	 * @return string URL con `utm_source` añadido/reemplazado.
	 */
	private function append_outbound_utm( string $url ): string {
		$options    = get_option( WPAM_OPTION_KEY, array() );
		$utm_source = trim( (string) ( $options['redirect']['outbound_utm_source'] ?? '' ) );

		if ( '' === $utm_source ) {
			$utm_source = 'bunnychase';
		}

		return (string) add_query_arg( 'utm_source', $utm_source, $url );
	}

	/**
	 * Redirige de forma segura al destino del afiliado.
	 *
	 * El UTM ya se aplicó antes de llegar aquí (en continue_authorized_redirect()
	 * / continue_external_redirect(), justo antes de la bifurcación
	 * interstitial/directo) para que también alcance al destino usado por el
	 * interstitial. No volver a llamar a append_outbound_utm() aquí —
	 * duplicaría el parámetro.
	 *
	 * @since  1.8.11
	 * @param  string $url URL de destino, ya con `utm_source` aplicado.
	 * @return void
	 */
	private function redirect_to_destination( string $url ): void {
		$destination_host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		add_filter(
			'allowed_redirect_hosts',
			function( array $hosts ) use ( $destination_host ): array {
				$hosts = $this->allow_redirect_hosts( $hosts );
				if ( $destination_host ) {
					$hosts[] = $destination_host;
				}
				return array_unique( $hosts );
			}
		);

		status_header( 302 );
		wp_safe_redirect( $url );
		exit;
	}

	// -------------------------------------------------------------------------
	// Hosts permitidos para wp_safe_redirect()
	// -------------------------------------------------------------------------

	/**
	 * Añade los hosts de los afiliados activos a la lista de hosts permitidos
	 * por wp_safe_redirect(), evitando que bloquee URLs externas legítimas.
	 *
	 * Incluye:
	 *  - Todos los dominios configurados en el campo `domains` de cada afiliado.
	 *  - El host de la URL final del link resuelto (doble seguridad).
	 *
	 * @since  0.2.0-alpha1
	 * @param  string[] $hosts Lista actual de hosts permitidos.
	 * @return string[]
	 */
	public function allow_redirect_hosts( array $hosts ): array {
		$repo   = new Repository();
		$result = $repo->find_all( array( 'active' => true, 'per_page' => -1 ) );

		foreach ( $result['items'] as $affiliate ) {
			$raw_domains = trim( $affiliate['domains'] ?? '' );

			if ( ! $raw_domains ) {
				continue;
			}

			foreach ( explode( ',', $raw_domains ) as $entry ) {
				$normalized = wpam_normalize_domain( $entry );
				if ( $normalized ) {
					$hosts[] = $normalized;
				}
			}
		}

		return array_unique( $hosts );
	}

	// -------------------------------------------------------------------------
	// Resolución de token
	// -------------------------------------------------------------------------

	/**
	 * Resuelve un token a sus datos de destino.
	 *
	 * @since  0.2.0-alpha1
	 * @param  string $token Token de 8 caracteres hex.
	 * @return array|null {
	 *     @type int    $post_id      ID del post.
	 *     @type int    $link_index   Índice del link en el post.
	 *     @type int    $affiliate_id ID del afiliado.
	 *     @type string $url          URL final de destino.
	 * } o null si no se puede resolver.
	 */
	private function resolve( string $token ): ?array {
		// Validar formato básico del token.
		if ( ! preg_match( '/^[a-f0-9]{8}$/', $token ) ) {
			return null;
		}

		// Buscar en el mapa.
		$map = get_option( self::TOKEN_MAP_OPTION, array() );

		if ( ! is_array( $map ) || ! isset( $map[ $token ] ) ) {
			return null;
		}

		$entry      = $map[ $token ];
		$post_id    = absint( $entry['post_id']    ?? 0 );
		$link_index = absint( $entry['link_index'] ?? 0 );

		if ( ! $post_id ) {
			return null;
		}

		// Verificar que el post existe.
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		// Obtener los links del post.
		$handler = new Post_Links();
		$links   = $handler->get_links( $post_id );

		// Buscar el link por su campo 'order' (= link_index).
		$link = null;
		foreach ( $links as $l ) {
			if ( (int) $l['order'] === $link_index ) {
				$link = $l;
				break;
			}
		}

		if ( null === $link ) {
			return null;
		}

		// Verificar que el link no es huérfano.
		if ( ! empty( $link['_orphan'] ) ) {
			return null;
		}

		$url = $link['final_url'] ?? '';

		if ( '' === $url ) {
			return null;
		}

		// Validar esquema de la URL.
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		return array(
			'post_id'      => $post_id,
			'link_index'   => $link_index,
			'affiliate_id' => (int) $link['provider_id'],
			'url'          => $url,
		);
	}

	/**
	 * Resuelve un destino de fallback (Default URL) para la ruta /goa/{post_id}/{affiliate_id}/.
	 *
	 * A diferencia de resolve(), no usa el mapa de tokens: post_id y affiliate_id
	 * vienen directamente en la URL. La validación de negocio (afiliado activo,
	 * tiene default_url, y —sobre todo— el post no tiene ya un link específico
	 * para ese afiliado, que siempre gana) se delega enteramente a
	 * Post_Links::get_links() con 'include_defaults' => true: el mismo punto
	 * único de resolución que usa Render_Engine. Cero lógica de negocio duplicada.
	 *
	 * Efecto colateral positivo: si entre el momento en que se renderizó la
	 * página y el click se agregó un link específico para ese afiliado, esta
	 * llamada ya lo prioriza automáticamente sin necesidad de invalidar nada.
	 *
	 * @since  1.5.0
	 * @param  int $post_id      ID del post.
	 * @param  int $affiliate_id ID del afiliado.
	 * @return array|null Misma forma que resolve(), o null si no se puede resolver.
	 */
	private function resolve_default( int $post_id, int $affiliate_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$handler = new Post_Links();
		$links   = $handler->get_links( $post_id, array(
			'active_only'      => true,
			'include_defaults' => true,
		) );

		$link = null;
		foreach ( $links as $l ) {
			if ( (int) $l['provider_id'] === $affiliate_id ) {
				$link = $l;
				break;
			}
		}

		if ( null === $link ) {
			return null;
		}

		$url = $link['final_url'] ?? '';
		if ( '' === $url ) {
			return null;
		}

		// Validar esquema de la URL.
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		return array(
			'post_id'      => $post_id,
			'link_index'   => (int) $link['order'],
			'affiliate_id' => $affiliate_id,
			'url'          => $url,
		);
	}

	/**
	 * Resuelve un destino de enlace externo genérico para la ruta
	 * /goext/{sig}/?u={url}.
	 *
	 * A diferencia de resolve() y resolve_default(), no depende del mapa de
	 * tokens ni de Post_Links: la URL de destino viaja en claro en el query
	 * string, protegida únicamente por la firma HMAC (ver
	 * generate_external_signature()). Esta firma es la única razón por la que
	 * /goext/ no es un open redirect: nadie puede producir una firma válida
	 * para una URL arbitraria sin conocer wp_salt(), así que solo las URLs
	 * que el propio sitio firmó previamente (ver External_Links, que las
	 * firma a partir de los href externos ya presentes en el post_content)
	 * pueden resolverse aquí.
	 *
	 * @since  1.8.9
	 * @param  string $url URL de destino cruda (ya pasada por esc_url_raw() en handle()).
	 * @param  string $sig Firma HMAC recibida en el path.
	 * @return array|null { @type int $post_id 0, @type int $affiliate_id 0, @type string $url } o null si inválido.
	 */
	private function resolve_external( string $url, string $sig ): ?array {
		if ( '' === $url || 1 !== preg_match( '/^[a-f0-9]{' . self::EXTERNAL_SIG_LENGTH . '}$/', $sig ) ) {
			return null;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		// Verificar la firma ANTES de cualquier otra cosa: si no coincide, la
		// URL no fue emitida por este sitio y se descarta sin más validaciones.
		$expected = $this->generate_external_signature( $url );
		if ( ! hash_equals( $expected, $sig ) ) {
			return null;
		}

		// Defensa adicional: nunca redirigir "externamente" al propio host.
		$host      = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host || $host === $site_host ) {
			return null;
		}

		return array(
			'post_id'      => 0,
			'affiliate_id' => 0,
			'url'          => $url,
		);
	}

	/**
	 * Genera la firma HMAC estable para una URL de enlace externo genérico.
	 *
	 * Público porque ajax_sign_external() (mismo objetivo, bajo demanda) y el
	 * helper wpam_goext_url() la reutilizan.
	 *
	 * @since  1.8.9
	 * @param  string $url URL absoluta (http/https) ya normalizada.
	 * @return string Firma hex de EXTERNAL_SIG_LENGTH caracteres.
	 */
	public function generate_external_signature( string $url ): string {
		return substr( hash_hmac( 'sha256', $url, wp_salt() ), 0, self::EXTERNAL_SIG_LENGTH );
	}

	// -------------------------------------------------------------------------
	// External Links — firma bajo demanda (solo en el momento del click)
	// -------------------------------------------------------------------------

	/**
	 * Nombre de la acción del nonce / AJAX de firma de enlaces externos.
	 *
	 * @since 1.8.9
	 */
	const AJAX_ACTION_SIGN_EXTERNAL = 'wpam_sign_external';

	/**
	 * Encola el script mínimo que detecta clicks en enlaces externos del
	 * contenido y pide la firma solo para la URL realmente clickeada.
	 *
	 * Deliberadamente barato: ningún escaneo del post_content aquí — el JS
	 * decide en el propio navegador si un link es externo (comparando host),
	 * y solo entonces llama a ajax_sign_external() con esa única URL. El
	 * costo de firmar/validar ocurre exclusivamente cuando hay un click real,
	 * nunca en cada carga de página.
	 *
	 * Colgado de 'wp_enqueue_scripts'.
	 *
	 * @since  1.8.9
	 * @return void
	 */
	public function maybe_enqueue_external_links_script(): void {
		if ( is_preview() || is_feed() || ! is_singular() ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}

		wp_enqueue_script(
			'wpam-external-links',
			WPAM_PLUGIN_URL . 'assets/js/external-links.js',
			array(),
			WPAM_VERSION,
			true
		);

		$config = array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'action'   => self::AJAX_ACTION_SIGN_EXTERNAL,
			'postId'   => $post_id,
			'nonce'    => wp_create_nonce( self::AJAX_ACTION_SIGN_EXTERNAL ),
			'siteHost' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
		);

		// Mismo patrón que Views::maybe_enqueue_beacon(): objeto global
		// inyectado ANTES del script principal, sin wp_localize_script().
		wp_add_inline_script(
			'wpam-external-links',
			'window.wpamExternalLinks = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Handler de 'wp_ajax_wpam_sign_external' / 'wp_ajax_nopriv_wpam_sign_external'.
	 *
	 * Firma UNA sola URL, solo cuando el navegador reporta un click real
	 * sobre ella. Dos capas de protección contra open redirect, ambas
	 * necesarias:
	 *  1. La URL debe aparecer literalmente en el post_content del post_id
	 *     indicado (comprobación ligera con str_contains(), sin parser HTML:
	 *     es la prueba de que el enlace realmente existe en el contenido
	 *     publicado, no algo que el cliente inventó).
	 *  2. La firma HMAC devuelta (generate_external_signature(), la misma
	 *     que valida resolve_external() en handle()) es lo que impide que
	 *     alguien reutilice /goext/ con una URL distinta sin pasar por aquí.
	 *
	 * @since  1.8.9
	 * @return void
	 */
	public function ajax_sign_external(): void {
		check_ajax_referer( self::AJAX_ACTION_SIGN_EXTERNAL, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		if ( $post_id <= 0 || '' === $url ) {
			wp_send_json_error();
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			wp_send_json_error();
		}

		$host      = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host || $host === $site_host ) {
			wp_send_json_error(); // Enlace interno: nada que firmar.
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			wp_send_json_error();
		}

		// Comprobación ligera de pertenencia: la URL (o su variante
		// protocol-relative "//host/...") debe existir literalmente en el
		// post_content. Sin parser HTML — un post_content típico no supera
		// unos pocos KB, y str_contains() sobre eso es una operación trivial
		// que solo se paga en el click real, no en cada carga de página.
		$content       = (string) $post->post_content;
		$without_https = preg_replace( '#^https?:#i', '', $url );

		if ( ! str_contains( $content, $url ) && ! str_contains( $content, $without_https ) ) {
			wp_send_json_error();
		}

		$sig = $this->generate_external_signature( $url );

		wp_send_json_success(
			array(
				'redirectUrl' => home_url( '/' . self::SLUG_EXTERNAL . '/' . $sig . '/?u=' . rawurlencode( $url ) ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Gestión del mapa de tokens
	// -------------------------------------------------------------------------

	/**
	 * Reconstruye las entradas del mapa de tokens para un post dado.
	 * Llamado desde Post_Links::save() tras guardar los links.
	 *
	 * El token se genera con:
	 *   substr( wp_hash( "{post_id}:{link_index}:wpam" ), 0, 8 )
	 *
	 * Esto garantiza:
	 *  - No predecible externamente (HMAC con la secret key del sitio).
	 *  - Determinista: mismo post_id + link_index = mismo token.
	 *  - Corto: 8 caracteres hex.
	 *  - Sin colisiones entre posts o entre links del mismo post.
	 *
	 * @since  0.2.0-alpha1
	 * @param  int $post_id ID del post cuyos tokens se deben reconstruir.
	 * @return void
	 */
	public function rebuild_token_map( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$handler = new Post_Links();
		$links   = $handler->get_links( $post_id );

		$map = get_option( self::TOKEN_MAP_OPTION, array() );

		if ( ! is_array( $map ) ) {
			$map = array();
		}

		// Eliminar entradas anteriores de este post para evitar tokens huérfanos.
		foreach ( $map as $tok => $entry ) {
			if ( isset( $entry['post_id'] ) && (int) $entry['post_id'] === $post_id ) {
				unset( $map[ $tok ] );
			}
		}

		// Añadir entradas nuevas para cada link activo.
		foreach ( $links as $link ) {
			if ( ! empty( $link['_orphan'] ) ) {
				continue; // No mapear links huérfanos.
			}

			$link_index = (int) $link['order'];
			$token      = $this->generate_token( $post_id, $link_index );

			$map[ $token ] = array(
				'post_id'    => $post_id,
				'link_index' => $link_index,
			);
		}

		update_option( self::TOKEN_MAP_OPTION, $map, false );
	}

	/**
	 * Genera un token estable de 8 caracteres hex para un link específico.
	 *
	 * @since  0.2.0-alpha1
	 * @param  int $post_id    ID del post.
	 * @param  int $link_index Índice (order) del link.
	 * @return string Token de 8 caracteres hex (a-f0-9).
	 */
	public function generate_token( int $post_id, int $link_index ): string {
		return substr( wp_hash( $post_id . ':' . $link_index . ':wpam' ), 0, 8 );
	}
}
