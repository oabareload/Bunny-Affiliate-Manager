/**
 * Bunny Affiliate Manager — Views Beacon
 *
 * Registra una vista en el servidor. Fetch nativo, sin dependencias (no
 * jQuery). No lee ni escribe cookies — la deduplicación es responsabilidad
 * exclusiva del servidor (ver Views::ajax_track()).
 *
 * Solo se carga cuando Views::maybe_enqueue_beacon() decide encolarlo (el
 * recurso actual resolvió a un tipo soportado y elegible), así que no
 * necesita comprobar nada sobre la página actual.
 *
 * window.wpamViews se define vía wp_add_inline_script() antes de este
 * archivo: { ajaxUrl, action, resourceType, resourceId, nonce, utmSource?,
 * searchTerm?, requestedUrl? } — utmSource presente si la URL de entrada
 * trajo ?utm_source=..., searchTerm/requestedUrl solo para resource_type
 * 'search' / '404' respectivamente.
 *
 * @package WP_AffiliateManager
 * @since   1.2.0
 * @since   1.8.0 Generalizado de postId a resourceType/resourceId + contexto
 *               opcional de search/404.
 * @since   1.8.9 Añadido utm_source opcional.
 */

( function () {
	'use strict';

	if ( ! window.wpamViews || ! window.wpamViews.ajaxUrl ) {
		return;
	}

	var config = window.wpamViews;
	var params = {
		action:        config.action,
		resource_type: config.resourceType,
		resource_id:   config.resourceId,
		nonce:         config.nonce
	};

	if ( config.utmSource ) {
		params.utm_source = config.utmSource;
	}

	if ( config.searchTerm ) {
		params.search_term = config.searchTerm;
	}

	if ( config.requestedUrl ) {
		params.requested_url = config.requestedUrl;
	}

	var body = new URLSearchParams( params );

	fetch( config.ajaxUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		credentials: 'same-origin',
		body: body,
		keepalive: true
	} ).catch( function () {
		// Silencioso: un fallo al registrar una vista no debe afectar al visitante.
	} );
} )();
