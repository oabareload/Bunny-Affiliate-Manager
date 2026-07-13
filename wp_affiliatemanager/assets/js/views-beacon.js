/**
 * Bunny Affiliate Manager — Views Beacon
 *
 * Registra una vista en el servidor. Fetch nativo, sin dependencias (no
 * jQuery). No lee ni escribe cookies — la deduplicación es responsabilidad
 * exclusiva del servidor (ver Views::ajax_track()).
 *
 * Solo se carga cuando Views::maybe_enqueue_beacon() decide encolarlo
 * (is_singular('post') + post publicado), así que no necesita comprobar
 * nada sobre la página actual.
 *
 * window.wpamViews se define vía wp_add_inline_script() antes de este
 * archivo: { ajaxUrl, action, postId, nonce }.
 *
 * @package WP_AffiliateManager
 * @since   1.2.0
 */

( function () {
	'use strict';

	if ( ! window.wpamViews || ! window.wpamViews.ajaxUrl ) {
		return;
	}

	var config = window.wpamViews;
	var body   = new URLSearchParams( {
		action:  config.action,
		post_id: config.postId,
		nonce:   config.nonce
	} );

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
