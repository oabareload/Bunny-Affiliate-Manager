/**
 * Bunny Affiliate Manager — External Links interceptor.
 *
 * Intercepta clicks en enlaces externos "normales" dentro del contenido de
 * una publicación y los enruta a través del interstitial existente
 * (/goext/{sig}/?u=...), reutilizando el mismo flujo que ya usan los links
 * de afiliado.
 *
 * A propósito NO trae ningún mapa de URLs/firmas precalculado: la detección
 * de "¿es externo?" ocurre enteramente en el navegador (comparando el host
 * del link contra window.wpamExternalLinks.siteHost, sin tocar el servidor).
 * Solo cuando el usuario hace click de verdad en un enlace externo se pide
 * al servidor —una única vez, para esa única URL— la firma HMAC que permite
 * pasar por /goext/ (ajax_sign_external() en Redirect_Manager). Así el costo
 * de red/servidor ocurre únicamente cuando la funcionalidad realmente se usa,
 * nunca en cada carga de página.
 *
 * Si la petición de firma falla o no llega a tiempo, el link navega de forma
 * normal (fail-open): nunca se bloquea ni se rompe el enlace por esto.
 *
 * @package WP_AffiliateManager
 * @since   1.8.9
 */

( function () {
	'use strict';

	if ( ! window.wpamExternalLinks || ! window.wpamExternalLinks.ajaxUrl ) {
		return;
	}

	var config = window.wpamExternalLinks;

	document.addEventListener( 'click', function ( event ) {
		// Solo click primario, sin modificadores (dejar pasar ctrl/cmd/shift/
		// middle-click para que el usuario pueda abrir en pestaña nueva, etc.).
		if ( event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		var link = event.target && event.target.closest ? event.target.closest( 'a[href]' ) : null;
		if ( ! link ) {
			return;
		}

		// No interceptar nuestros propios bloques (links de afiliado, cards
		// del interstitial, etc.) — ya tienen su propio flujo.
		if ( link.closest( '.wpam-links, .wpam-interstitial-card, [data-wpam-skip]' ) ) {
			return;
		}

		var rawHref = link.getAttribute( 'href' ) || '';

		if ( '' === rawHref || rawHref.charAt( 0 ) === '#' ) {
			return;
		}

		if ( /^(mailto:|tel:|javascript:)/i.test( rawHref ) ) {
			return;
		}

		// link.href siempre es absoluto y normalizado por el navegador,
		// incluso si el atributo original era protocol-relative o relativo.
		var absoluteHref = link.href;
		var linkHost;

		try {
			linkHost = new URL( absoluteHref ).host;
		} catch ( e ) {
			return; // href no parseable como URL: dejar pasar.
		}

		if ( linkHost === config.siteHost ) {
			return; // Enlace interno: comportamiento normal.
		}

		// A partir de aquí, y solo aquí, se paga el costo de red: un único
		// fetch por click externo real, nunca por carga de página.
		event.preventDefault();

		var body = new URLSearchParams();
		body.set( 'action', config.action );
		body.set( 'nonce', config.nonce );
		body.set( 'post_id', config.postId );
		body.set( 'url', absoluteHref );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( response ) { return response.json(); } )
			.then( function ( json ) {
				window.location.href = ( json && json.success && json.data && json.data.redirectUrl )
					? json.data.redirectUrl
					: absoluteHref; // Servidor no pudo firmar: navegar normal.
			} )
			.catch( function () {
				window.location.href = absoluteHref; // Fail-open: nunca bloquear el link.
			} );
	}, false );
} )();
