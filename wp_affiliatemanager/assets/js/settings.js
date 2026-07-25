/**
 * Bunny Affiliate Manager — Settings page JS.
 *
 * Muestra/oculta las filas de opciones específicas de cada Layout
 * (Card / Showcase) según el radio seleccionado en "Layout". No usa
 * jQuery — vanilla JS, encolado solo en la página de Settings del plugin.
 *
 * Los campos que aplican solo a un layout se marcan en su template PHP con
 * data-wpam-layout-only="card" o data-wpam-layout-only="showcase" (ver
 * Settings::render_field_link_style() / render_field_showcase_*()). Este
 * script busca esos elementos, sube hasta su <tr> de la Settings API, y
 * alterna la visibilidad de la fila completa.
 *
 * @package WP_AffiliateManager
 * @since   1.6.0
 */

( function () {
	'use strict';

	function getSelectedLayout() {
		var checked = document.querySelector( 'input[name$="[appearance][layout]"]:checked' );
		return checked ? checked.value : 'card';
	}

	function applyLayoutVisibility() {
		var current = getSelectedLayout();
		var markers = document.querySelectorAll( '[data-wpam-layout-only]' );

		markers.forEach( function ( marker ) {
			var only = marker.getAttribute( 'data-wpam-layout-only' );
			var row  = marker.closest( 'tr' );

			if ( ! row ) {
				return;
			}

			row.style.display = ( only === current ) ? '' : 'none';
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var radios = document.querySelectorAll( 'input[name$="[appearance][layout]"]' );

		if ( ! radios.length ) {
			return;
		}

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', applyLayoutVisibility );
		} );

		// Estado inicial al cargar la página.
		applyLayoutVisibility();
	} );
} )();
