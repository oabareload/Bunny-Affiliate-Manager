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

		if ( '' === $token && ! $is_goa ) {
			return; // No es nuestra petición.
		}

		$options        = get_option( WPAM_OPTION_KEY, array() );
		$exclude_admins = ! empty( $options['general']['exclude_admins_from_analytics'] );

		try {
			$destination = $is_goa
				? $this->resolve_default( $goa_post_id, $goa_affiliate_id )
				: $this->resolve( $token );
		} catch ( \Throwable $e ) {
			$destination = null;
		}

		if ( null === $destination ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$resource_type = 'post';
		$resource_id   = (int) $destination['post_id'];
		$has_valid_cookie = Views::has_valid_view_cookie( $resource_type, $resource_id );

		if ( ! $has_valid_cookie ) {
			$this->handle_missing_view_gate( $destination, $token, $goa_post_id, $goa_affiliate_id );
			return;
		}

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

				if ( ! ( ! empty( $options['general']['exclude_admins_from_analytics'] ) && current_user_can( 'manage_options' ) ) ) {
					$tracker = new Click_Tracker();
					$tracker->record( $destination['post_id'], $destination['affiliate_id'], $destination['url'] );
				}

				$this->redirect_to_destination( $destination['url'] );
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
	 * Redirige de forma segura al destino del afiliado.
	 *
	 * @param  string $url
	 * @return void
	 */
	private function redirect_to_destination( string $url ): void {
		$destination_host = (string) wp_parse_url( $url, PHP_URL_HOST );
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
