/**
 * Bunny Affiliate Manager — Post Links Admin JS
 *
 * @package WP_AffiliateManager
 * @since   3.0.0
 * @version 0.1.4
 *
 * v0.1.4:
 * - Eliminado el select de afiliado del formulario.
 * - Auto-detección por dominio usando window.WPAMDomainDetector (domain-detector.js).
 * - Preview chip visual al detectar afiliado (logo + nombre + color).
 * - Error inline si no hay afiliado para el dominio.
 * - Preview de URL final generada client-side con param/value del afiliado detectado.
 * - No se bloquea el botón Publish/Update de WP (flujo nativo intacto).
 * - Validación real en PHP (Post_Links::save via Repository::find_by_domain).
 *
 * v0.0.3:
 * - reindexAll(): actualiza data-index y todos los name/class de cada fila.
 * - addRow(): llama a reindexAll() después de insertar.
 * - removeRow(): llama a reindexAll() después de eliminar.
 */

/* global wpamPostLinksData, jQuery */

( function ( $, data ) {
	'use strict';

	// -------------------------------------------------------------------------
	// Módulo compartido de detección — v0.1.4
	// -------------------------------------------------------------------------

	/**
	 * Alias de window.WPAMDomainDetector (domain-detector.js).
	 * La lógica de matching vive ahí; no se duplica aquí.
	 */
	const Detector = window.WPAMDomainDetector;

	// -------------------------------------------------------------------------
	// Estado interno
	// -------------------------------------------------------------------------

	let nameIndex = data.nextIndex || 100;

	/** Afiliados activos pasados por PHP vía wpamPostLinksData.affiliates. */
	const affiliates = Array.isArray( data.affiliates ) ? data.affiliates : [];

	/** Timers de debounce por fila. */
	const detectTimers = {};

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	function init() {
		bindAddLink();
		bindRemoveLink();
		bindUrlDetection();   // v0.1.4: reemplaza bindPreviewUpdate
		updateCounter();
		reindexAll();

		// Ejecutar detección inicial en filas ya cargadas con URL existente.
		$( '#wpam-links-list .wpam-link-row' ).each( function () {
			const $row = $( this );
			const url  = $row.find( '.wpam-url-input' ).val().trim();
			if ( url ) {
				runDetection( $row, url );
			}
		} );
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
		if ( ! template ) { return; }

		const $list        = $( '#wpam-links-list' );
		const $placeholder = $( '#wpam-links-placeholder' );

		const newHtml = template.replace( /\{\{INDEX\}\}/g, nameIndex );
		const $row    = $( newHtml );

		$placeholder.hide();
		$list.append( $row );
		reindexAll();

		$row.find( '.wpam-url-input' ).trigger( 'focus' );

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
				reindexAll();
				updateCounter();

				if ( $list.find( '.wpam-link-row' ).length === 0 ) {
					let $ph = $( '#wpam-links-placeholder' );
					if ( ! $ph.length ) {
						$ph = $( '<div class="wpam-links-placeholder" id="wpam-links-placeholder">' +
							'<span class="wpam-placeholder-icon">&#128279;</span>' +
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
	// Re-indexación de order
	// -------------------------------------------------------------------------

	function reindexAll() {
		$( '#wpam-links-list .wpam-link-row' ).each( function ( position ) {
			$( this ).find( '.wpam-order-input' ).val( position );
		} );
	}

	// -------------------------------------------------------------------------
	// v0.1.4: Detección por dominio con debounce
	// -------------------------------------------------------------------------

	function bindUrlDetection() {
		$( '#wpam-links-list' ).on( 'input', '.wpam-url-input', function () {
			const $row   = $( this ).closest( '.wpam-link-row' );
			const rowId  = $row.data( 'index' ) || $row.index();
			const url    = $( this ).val().trim();

			// Limpiar estado visual mientras el usuario escribe.
			clearDetectState( $row );

			clearTimeout( detectTimers[ rowId ] );
			detectTimers[ rowId ] = setTimeout( function () {
				runDetection( $row, url );
			}, 500 );
		} );
	}

	/**
	 * Ejecuta la detección de afiliado para una fila dada.
	 *
	 * @param {jQuery} $row
	 * @param {string} url
	 */
	function runDetection( $row, url ) {
		if ( ! url ) {
			clearDetectState( $row );
			return;
		}

		if ( ! isValidUrl( url ) ) {
			setDetectError( $row, data.i18n.url_invalid || 'Enter a valid URL (https://...).' );
			return;
		}

		const domain   = Detector.extractDomain( url );
		const detected = domain ? Detector.findByDomain( domain, affiliates ) : null;

		if ( detected ) {
			setDetectSuccess( $row, detected, url );
		} else {
			setDetectError( $row, data.i18n.no_affiliate_found || 'No active affiliate found for this URL.' );
		}
	}

	/**
	 * Limpia el estado de detección de una fila.
	 *
	 * @param {jQuery} $row
	 */
	function clearDetectState( $row ) {
		$row.removeClass( 'wpam-link-row--detected wpam-link-row--error' );
		$row.find( '.wpam-detect-preview' ).empty();
		$row.find( '.wpam-detect-error' ).hide().text( '' );
		$row.find( '.wpam-link-preview' ).html(
			'<span class="wpam-preview-placeholder">' + escapeHtml( data.i18n.preview_placeholder ) + '</span>'
		);
		$row.removeData( 'detected-affiliate' );
	}

	/**
	 * Establece estado: afiliado detectado.
	 *
	 * @param {jQuery} $row
	 * @param {Object} aff  Afiliado { id, title, logo_url, brand_color, param, value, domains }
	 * @param {string} url  URL original para generar la preview.
	 */
	function setDetectSuccess( $row, aff, url ) {
		$row.removeClass( 'wpam-link-row--error' ).addClass( 'wpam-link-row--detected' );
		$row.find( '.wpam-detect-error' ).hide().text( '' );
		$row.data( 'detected-affiliate', aff );

		// Chip visual.
		const color   = aff.brand_color || '#6c47ff';
		const bgColor = hexToRgba( color, 0.10 );
		const style   = '--chip-color:' + escapeHtml( color ) + ';--chip-bg:' + escapeHtml( bgColor ) + ';';

		const logoHtml = aff.logo_url
			? '<img class="wpam-detect-chip-logo" src="' + escapeHtml( aff.logo_url ) + '" alt="" />'
			: '<span class="wpam-detect-chip-initial">' + escapeHtml( aff.title.charAt( 0 ).toUpperCase() ) + '</span>';

		$row.find( '.wpam-detect-preview' ).html(
			'<div class="wpam-detect-chip" style="' + escapeHtml( style ) + '">' +
			logoHtml +
			'<span class="wpam-detect-chip-name">' + escapeHtml( aff.title ) + '</span>' +
			'</div>'
		);

		// Preview de URL final (si el afiliado tiene param/value configurado).
		updateFinalUrlPreview( $row, aff, url );
	}

	/**
	 * Establece estado de error de detección.
	 *
	 * @param {jQuery} $row
	 * @param {string} message
	 */
	function setDetectError( $row, message ) {
		$row.removeClass( 'wpam-link-row--detected' ).addClass( 'wpam-link-row--error' );
		$row.find( '.wpam-detect-preview' ).empty();
		$row.find( '.wpam-detect-error' ).show().text( message );
		$row.find( '.wpam-link-preview' ).html(
			'<span class="wpam-preview-placeholder">' + escapeHtml( data.i18n.preview_placeholder ) + '</span>'
		);
		$row.removeData( 'detected-affiliate' );
	}

	/**
	 * Actualiza el área de preview de URL final de la fila.
	 *
	 * @param {jQuery} $row
	 * @param {Object} aff
	 * @param {string} url
	 */
	function updateFinalUrlPreview( $row, aff, url ) {
		const $preview = $row.find( '.wpam-link-preview' );

		if ( ! url ) {
			$preview.html( '<span class="wpam-preview-placeholder">' + escapeHtml( data.i18n.preview_placeholder ) + '</span>' );
			return;
		}

		let finalUrl = url;
		if ( aff.param && aff.value ) {
			finalUrl = addQueryParam( url, aff.param, aff.value );
		}

		$preview.html(
			'<span class="wpam-preview-label">' + escapeHtml( data.i18n.final_url ) + '</span> ' +
			'<code class="wpam-preview-url">' + escapeHtml( finalUrl ) + '</code> ' +
			'<a href="' + escapeHtml( finalUrl ) + '" target="_blank" rel="noopener noreferrer" ' +
			'class="wpam-preview-open" title="' + escapeHtml( data.i18n.open_tab ) + '">&#8599;</a>'
		);
	}

	// -------------------------------------------------------------------------
	// Contador de links
	// -------------------------------------------------------------------------

	function updateCounter() {
		const count = $( '#wpam-links-list .wpam-link-row' ).length;
		const $info = $( '#wpam-links-count' );
		if ( ! $info.length ) { return; }

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

	function addQueryParam( url, param, value ) {
		try {
			const urlObj = new URL( url );
			urlObj.searchParams.set( param, value );
			return urlObj.toString();
		} catch ( e ) {
			const sep = url.indexOf( '?' ) !== -1 ? '&' : '?';
			return url + sep + encodeURIComponent( param ) + '=' + encodeURIComponent( value );
		}
	}

	function isValidUrl( url ) {
		try {
			const p = new URL( url );
			return p.protocol === 'http:' || p.protocol === 'https:';
		} catch ( e ) {
			return false;
		}
	}

	function hexToRgba( hex, alpha ) {
		hex = String( hex ).replace( /^#/, '' );
		if ( hex.length === 3 ) { hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2]; }
		if ( hex.length !== 6 ) { return 'rgba(108,71,255,' + alpha + ')'; }
		return 'rgba(' + parseInt(hex.slice(0,2),16) + ',' + parseInt(hex.slice(2,4),16) + ',' + parseInt(hex.slice(4,6),16) + ',' + alpha + ')';
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
	nextIndex:  100,
	affiliates: [],
	i18n: {
		no_links:            'No affiliate links added yet.',
		no_links_count:      '0 links',
		one_link:            '1 link',
		n_links:             '%d links',
		preview_placeholder: 'Paste a URL to detect the affiliate automatically.',
		url_invalid:         'Enter a valid URL (https://...).',
		no_affiliate_found:  'No active affiliate found for this URL.',
		final_url:           'Final URL:',
		open_tab:            'Open in new tab',
	},
} );
