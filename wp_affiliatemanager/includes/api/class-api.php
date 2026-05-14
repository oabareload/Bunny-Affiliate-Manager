<?php
/**
 * Módulo de API REST.
 *
 * Registra los endpoints REST del plugin en la WP REST API.
 *
 * @package WP_AffiliateManager\API
 * @since   1.0.0
 */

namespace WP_AffiliateManager\API;

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class API
 *
 * En FASE 1: estructura base preparada con namespace y rutas placeholder.
 * En FASE 3: implementar endpoints reales para afiliados, links y estadísticas.
 *
 * Namespace de la API: /wp-json/wpam/v1/
 *
 * @since 1.0.0
 */
class API {

	/**
	 * Namespace de la REST API del plugin.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const REST_NAMESPACE = 'wpam/v1';

	/**
	 * Constructor.
	 * Registra el hook de inicialización de la API.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registra las rutas REST del plugin.
	 * Se ejecuta en el hook 'rest_api_init'.
	 *
	 * FASE 1: Solo rutas placeholder para verificar que la API está disponible.
	 * FASE 3: Añadir rutas completas para CRUD de afiliados y estadísticas.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_routes(): void {

		// GET /wp-json/wpam/v1/status — health check.
		register_rest_route(
			self::REST_NAMESPACE,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => '__return_true', // Endpoint público de diagnóstico.
			)
		);

		// Rutas futuras (FASE 3) — placeholder comentado para referencia:
		//
		// GET    /wpam/v1/affiliates          → Listar afiliados.
		// POST   /wpam/v1/affiliates          → Crear afiliado.
		// GET    /wpam/v1/affiliates/{id}     → Obtener afiliado.
		// PUT    /wpam/v1/affiliates/{id}     → Actualizar afiliado.
		// DELETE /wpam/v1/affiliates/{id}     → Eliminar afiliado.
		// GET    /wpam/v1/posts/{id}/links    → Links de un post.
		// GET    /wpam/v1/stats               → Estadísticas globales.
	}

	/**
	 * Handler del endpoint de status/health-check.
	 *
	 * @since  1.0.0
	 * @return \WP_REST_Response
	 */
	public function handle_status(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'plugin'  => 'Bunny Affiliate Manager',
				'version' => WPAM_VERSION,
				'status'  => 'active',
				'api'     => self::REST_NAMESPACE,
			),
			200
		);
	}
}
