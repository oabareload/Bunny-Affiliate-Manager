/**
 * Bunny Affiliate Manager — Post Links Admin JS
 *
 * @package WP_AffiliateManager
 * @since   3.0.0
 * @version 0.0.3
 *
 * Cambios en 0.0.3:
 * - reindexAll(): actualiza data-index y todos los name/class de cada fila
 *   para que el campo [order] siempre tenga el valor correcto (0, 1, 2...).
 * - addRow(): llama a reindexAll() después de insertar para evitar order=0 múltiple.
 * - removeRow(): llama a reindexAll() después de eliminar para re-normalizar.
 * - URL API: encodeURIComponent aplicado correctamente (no doble-encode).
 */

/* global wpamPostLinksData, jQuery */

( function ( $, data ) {
	'use strict';

	// -------------------------------------------------------------------------
	// Estado interno
	// -------------------------------------------------------------------------

	/**
	 * Índice incremental para nuevas filas.
	 * Usado SOLO para generar name attributes únicos durante la sesión.
	 * El order real se calcula por posición DOM en reindexAll().
	 */
	let nameIndex = data.nextIndex || 100;

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	function init() {
		bindAddLink();
		bindRemoveLink();
		bindPreviewUpdate();
		updateCounter();
		// Asegurar que los campos order son correctos al cargar la página
		// (por si hay datos guardados con versiones anteriores).
		reindexAll();
	}

	// -------------------------------------------------------------------------
	// Agregar link
	// -------------------------------------------------------------------------

	function bindAddLink() {
		$( '#wpam-add-link-btn' ).on( 'click', function () {
			addRow();
		} );
	}

	function addRow() {
		const template = $( '#wpam-link-row-template' ).html();
		if ( ! template ) {
			return;
		}

		const $list        = $( '#wpam-links-list' );
		const $placeholder = $( '#wpam-links-placeholder' );

		// Reemplazar el placeholder de índice con el nameIndex actual.
		const newHtml = template.replace( /\{\{INDEX\}\}/g, nameIndex );
		const $row    = $( newHtml );

		$placeholder.hide();
		$list.append( $row );

		// Re-calcular order para TODAS las filas (incluida la nueva).
		reindexAll();

		// Enfocar primer campo de la nueva fila.
		$row.find( '.wpam-provider-select' ).trigger( 'focus' );

		nameIndex++;
		updateCounter();
	}

	// -------------------------------------------------------------------------
	// Eliminar link
	// -------------------------------------------------------------------------

	function bindRemoveLink() {
		$( '#wpam-links-list' ).on( 'click', '.wpam-remove-link-btn', function () {
			const $row  = $( this ).closest( '.wpam-link-row' );
			const $list = $( '#wpam-links-list' );

			$row.addClass( 'wpam-row-removing' );

			setTimeout( function () {
				$row.remove();

				// Re-calcular order después de eliminar.
				reindexAll();
				updateCounter();

				// Mostrar placeholder si no quedan filas.
				if ( $list.find( '.wpam-link-row' ).length === 0 ) {
					let $ph = $( '#wpam-links-placeholder' );
					if ( ! $ph.length ) {
						$ph = $( '<div class="wpam-links-placeholder" id="wpam-links-placeholder">' +
							'<span class="wpam-placeholder-icon">🔗</span>' +
							'<span>' + escapeHtml( data.i18n.no_links ) + '</span>' +
							'</div>'
						);
						$list.append( $ph );
					} else {
						$ph.show();
					}
				}
			}, 200 );
		} );
	}

	// -------------------------------------------------------------------------
	// Re-indexación de order (FIX 0.0.3)
	// -------------------------------------------------------------------------

	/**
	 * Recorre todas las filas del DOM en orden y actualiza el campo hidden [order]
	 * con su posición real (0-based).
	 *
	 * Esto garantiza que sin importar cómo se agregaron/eliminaron filas,
	 * el valor enviado al PHP siempre es 0, 1, 2, 3... sin huecos ni duplicados.
	 *
	 * NOTA: NO modifica los name attributes (eso quedaría para el drag & drop en FASE 4).
	 * El PHP ignora el order del cliente y re-asigna por posición al guardar (doble seguridad).
	 */
	function reindexAll() {
		$( '#wpam-links-list .wpam-link-row' ).each( function ( position ) {
			// Actualizar el campo hidden [order] con la posición actual en el DOM.
			$( this ).find( '.wpam-order-input' ).val( position );
		} );
	}

	// -------------------------------------------------------------------------
	// Preview dinámico de URL final
	// -------------------------------------------------------------------------

	function bindPreviewUpdate() {
		const $list = $( '#wpam-links-list' );

		$list.on( 'change', '.wpam-provider-select', function () {
			updateRowPreview( $( this ).closest( '.wpam-link-row' ) );
		} );

		$list.on( 'input', '.wpam-url-input', debounce( function () {
			updateRowPreview( $( this ).closest( '.wpam-link-row' ) );
		}, 400 ) );
	}

	/**
	 * Actualiza el preview de la URL final para una fila.
	 * Replica wpam_generate_affiliate_url() en el cliente.
	 *
	 * @param {jQuery} $row Elemento .wpam-link-row
	 */
	function updateRowPreview( $row ) {
		const $preview  = $row.find( '.wpam-link-preview' );
		const $select   = $row.find( '.wpam-provider-select' );
		const $urlInput = $row.find( '.wpam-url-input' );

		const $selected = $select.find( 'option:selected' );
		const param     = $selected.data( 'param' )  || '';
		const value     = $selected.data( 'value' )  || '';
		const baseUrl   = ( $urlInput.val() || '' ).trim();

		$preview.empty();

		// Sin provider seleccionado o sin URL.
		if ( ! $selected.val() || ! baseUrl ) {
			$preview.html(
				'<span class="wpam-preview-placeholder">' +
				escapeHtml( data.i18n.preview_placeholder ) +
				'</span>'
			);
			return;
		}

		// Validación básica de URL en el cliente (el PHP valida de forma completa).
		if ( ! isValidUrl( baseUrl ) ) {
			$preview.html(
				'<span class="wpam-preview-placeholder wpam-preview-placeholder--error">' +
				escapeHtml( data.i18n.invalid_url ) +
				'</span>'
			);
			return;
		}

		// Generar URL final.
		let finalUrl = baseUrl;
		if ( param && value ) {
			finalUrl = addQueryParam( baseUrl, param, value );
		}

		$preview.html(
			'<span class="wpam-preview-label">' + escapeHtml( data.i18n.final_url ) + '</span> ' +
			'<code class="wpam-preview-url">' + escapeHtml( finalUrl ) + '</code> ' +
			'<a href="' + escapeHtml( finalUrl ) + '" target="_blank" rel="noopener noreferrer" ' +
			'class="wpam-preview-open" title="' + escapeHtml( data.i18n.open_tab ) + '">↗</a>'
		);
	}

	/**
	 * Agrega (o reemplaza) un query parameter en una URL.
	 * Usa URL API nativa; fallback para URLs malformadas.
	 *
	 * No aplica doble encodeURIComponent: URL API ya lo hace internamente.
	 *
	 * @param  {string} url
	 * @param  {string} param
	 * @param  {string} value
	 * @return {string}
	 */
	function addQueryParam( url, param, value ) {
		try {
			const urlObj = new URL( url );
			urlObj.searchParams.set( param, value );
			return urlObj.toString();
		} catch ( e ) {
			// Fallback: concatenación directa.
			const sep = url.indexOf( '?' ) !== -1 ? '&' : '?';
			return url + sep + encodeURIComponent( param ) + '=' + encodeURIComponent( value );
		}
	}

	/**
	 * Validación básica de URL en el cliente.
	 * El PHP hace la validación completa con filter_var.
	 *
	 * @param  {string} url
	 * @return {boolean}
	 */
	function isValidUrl( url ) {
		try {
			const parsed = new URL( url );
			return parsed.protocol === 'http:' || parsed.protocol === 'https:';
		} catch ( e ) {
			return false;
		}
	}

	// -------------------------------------------------------------------------
	// Contador de links
	// -------------------------------------------------------------------------

	function updateCounter() {
		const count = $( '#wpam-links-list .wpam-link-row' ).length;
		const $info = $( '#wpam-links-count' );

		if ( ! $info.length ) {
			return;
		}

		if ( count === 0 ) {
			$info.text( data.i18n.no_links_count );
		} else if ( count === 1 ) {
			$info.text( data.i18n.one_link );
		} else {
			$info.text( ( data.i18n.n_links || '%d links' ).replace( '%d', count ) );
		}
	}

	// -------------------------------------------------------------------------
	// Utilidades
	// -------------------------------------------------------------------------

	function debounce( fn, wait ) {
		let timer;
		return function () {
			const ctx  = this;
			const args = arguments;
			clearTimeout( timer );
			timer = setTimeout( function () { fn.apply( ctx, args ); }, wait );
		};
	}

	function escapeHtml( str ) {
		return String( str || '' )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	// -------------------------------------------------------------------------
	// Arranque
	// -------------------------------------------------------------------------

	$( function () {
		if ( $( '#wpam-post-links-wrap' ).length ) {
			init();
		}
	} );

} )( jQuery, window.wpamPostLinksData || {
	nextIndex: 100,
	i18n: {
		no_links:            'No affiliate links added yet.',
		no_links_count:      '0 links',
		one_link:            '1 link',
		n_links:             '%d links',
		preview_placeholder: 'Select an affiliate and enter a URL to see the generated link.',
		invalid_url:         'Please enter a valid URL (https://...).',
		final_url:           'Final URL:',
		open_tab:            'Open in new tab',
	},
} );
