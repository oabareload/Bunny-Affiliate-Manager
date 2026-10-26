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

	var config   = window.wpamViews;
	var dedupKey = config.resourceType + ':' + ( config.resourceId || 0 );

	// Comprobación de deduplicación en el cliente: si el navegador ya cuenta con la clave en
	// la cookie 'wpam_v' para el período actual, no enviamos la petición a admin-ajax.php,
	// ya que el servidor la descartaría de todas formas (counted: false).
	// La cookie expira a medianoche del timezone del sitio (WordPress), por lo que visitas en días
	// posteriores se vuelven a contabilizar con normalidad.
	if ( document.cookie && document.cookie.indexOf( 'wpam_v=' ) !== -1 ) {
		var match = document.cookie.match( /(?:^|;\s*)wpam_v=([^;]+)/ );
		if ( match ) {
			try {
				var ids = decodeURIComponent( match[1] ).split( ',' );
				for ( var i = 0; i < ids.length; i++ ) {
					if ( ids[i].trim() === dedupKey ) {
						return;
					}
				}
			} catch ( e ) {}
		}
	}

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
	} ).catch( function () {} );
} )();
