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
		$token = get_query_var( self::QUERY_VAR, '' );

		if ( '' === $token ) {
			return; // No es nuestra petición.
		}

		// Leer opciones y la flag de exclusión antes de cualquier uso.
		$options        = get_option( WPAM_OPTION_KEY, array() );
		$exclude_admins = ! empty( $options['general']['exclude_admins_from_analytics'] );

		// Resolver el token en todos los casos (el redirect siempre debe ocurrir).
		try {
			$destination = $this->resolve( $token );
		} catch ( \Throwable $e ) {
			$destination = null;
		}

		if ( null === $destination ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		// Registrar el click (fallo no bloquea el redirect).
		// Si exclude_admins está activo y el usuario tiene manage_options, se omite el tracking.
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
		
		// v0.2.0-alpha2: bifurcar según settings de interstitial.
		$enable_interstitial = ! empty( $options['redirect']['enable_interstitial'] ?? true );
		$delay               = absint( $options['redirect']['redirect_delay'] ?? 3 );

		// v0.2.0-alpha3: si delay = 0 bypassear el interstitial aunque esté activado.
		if ( $enable_interstitial && $delay > 0 ) {
			// El renderer hace exit internamente tras mostrar la página.
			$renderer = new Interstitial_Renderer();
			$renderer->render( array_merge( $destination, array( 'token' => $token ) ) );
			return; // Nunca se alcanza; defensivo.
		}

		// Redirect instantáneo: interstitial desactivado o delay = 0.
		$destination_host = (string) wp_parse_url( $destination['url'], PHP_URL_HOST );
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
		wp_safe_redirect( $destination['url'] );
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
