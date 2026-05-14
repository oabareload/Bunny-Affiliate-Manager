/**
 * Bunny Affiliate Manager — Frontend Scripts
 *
 * JavaScript del área pública del sitio.
 * Sin dependencias externas (no jQuery) para máxima performance.
 *
 * @package WP_AffiliateManager
 * @since   1.0.0
 */

( function () {
	'use strict';

	/**
	 * Módulo público del plugin.
	 */
	const WPAM_Frontend = {

		/**
		 * Inicializa el módulo cuando el DOM está listo.
		 */
		init() {
			this.trackOutboundLinks();
		},

		/**
		 * Tracking básico de clics en enlaces de afiliados.
		 *
		 * FASE 1: Solo base preparada.
		 * FASE de estadísticas: enviar evento AJAX al servidor.
		 */
		trackOutboundLinks() {
			const affiliateLinks = document.querySelectorAll( '[data-wpam-link]' );

			if ( ! affiliateLinks.length ) {
				return;
			}

			affiliateLinks.forEach( function ( link ) {
				link.addEventListener( 'click', function () {
					const affiliateId = this.dataset.wpamLink;

					// FASE de estadísticas: descomentar y completar.
					// WPAM_Frontend.recordClick( affiliateId );

					// Por ahora solo log en modo debug.
					if ( window.wpamFrontendData && window.wpamFrontendData.debug ) {
						console.log( '[WPAM] Click en afiliado ID:', affiliateId );
					}
				} );
			} );
		},

		/**
		 * Registra un clic en un enlace de afiliado (vía AJAX).
		 * PLACEHOLDER — implementar en FASE de estadísticas.
		 *
		 * @param {string|number} affiliateId ID del afiliado.
		 */
		recordClick( affiliateId ) {
			if ( ! window.wpamFrontendData ) {
				return;
			}

			const data = window.wpamFrontendData;

			fetch( data.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams( {
					action:       'wpam_record_click',
					nonce:        data.nonce,
					affiliate_id: affiliateId,
					post_id:      data.postId || 0,
				} ),
				// Fire and forget: no bloquea la navegación.
				keepalive: true,
			} ).catch( function () {
				// Silenciar errores de tracking — no afecta la experiencia del usuario.
			} );
		},
	};

	// Arrancar cuando el DOM esté listo.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			WPAM_Frontend.init();
		} );
	} else {
		WPAM_Frontend.init();
	}

} )();
