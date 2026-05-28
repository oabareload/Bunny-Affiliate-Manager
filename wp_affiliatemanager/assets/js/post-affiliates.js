/**
 * Bunny Affiliate Manager — Post Affiliates Board
 *
 * v0.1.4 — DomainDetector migrado a módulo compartido (domain-detector.js).
 *           Ahora tanto post-links.js como post-affiliates.js usan
 *           window.WPAMDomainDetector sin duplicar lógica.
 *
 * v0.1.3 — Auto-detección de afiliado por dominio:
 * - Eliminado el select manual de "Affiliate/Provider".
 * - El usuario solo pega la URL; JS detecta el afiliado por dominio (debounce 500ms).
 * - Preview chip: logo + nombre + color del afiliado detectado.
 * - Estado de error inline si no se detecta ningún afiliado.
 * - Botón Save bloqueado si algún item tiene error o URL sin detectar.
 * - Validación de duplicados en el cliente (misma URL normalizada en el mismo editor).
 * - La validación definitiva ocurre en PHP (ajax_save_post_links).
 *
 * v0.1.1 — Fixes y mejoras:
 * - FIX: "Add Link" ahora siempre inyecta una fila nueva clonada desde plantilla.
 * - FIX: Cancel/Save destruyen completamente el estado del editor y recargan
 *        desde el HTML fresco del servidor (o restauran desde snapshot del DOM).
 * - NEW: Filtro de status (segmented control) integrado en Toolbar.
 * - MEJORA: chips visuales con logo + color por brand_color.
 *
 * @package WP_AffiliateManager
 * @since   0.1.0
 * @version 0.1.4
 */

/* global wpamPAData, jQuery */

( function ( $, cfg ) {
	'use strict';

	// =========================================================================
	// Estado global de filtros/paginación
	// =========================================================================

	const State = {
		offset:   0,
		hasMore:  false,
		loading:  false,
		search:   '',
		category: 0,
		tag:      0,
		status:   'all',   // v0.1.1
	};

	// =========================================================================
	// Init
	// =========================================================================

	$( function () {
		const $board = $( '#wpam-pa-board' );
		if ( ! $board.length ) { return; }

		State.offset  = parseInt( $board.data( 'offset' ), 10 ) || 0;
		State.hasMore = '1' === String( $board.data( 'has-more' ) );

		Toolbar.init();
		LoadMore.init();
		Editor.init();
	} );

	// =========================================================================
	// Módulo: Toolbar
	// =========================================================================

	const Toolbar = {
		_timer: null,

		init() {
			// Búsqueda con debounce.
			$( '#wpam-pa-search' ).on( 'input', () => {
				clearTimeout( this._timer );
				this._timer = setTimeout( () => this.applyFilters(), 400 );
			} );

			// Selects de categoría y tag.
			$( document ).on( 'change', '#wpam-pa-filter-cat, #wpam-pa-filter-tag', () => {
				this.applyFilters();
			} );

			// Filtro de status (segmented control) — v0.1.1.
			$( document ).on( 'click', '.wpam-pa-status-pill', function () {
				const $btn = $( this );
				$( '.wpam-pa-status-pill' ).removeClass( 'wpam-pa-status-pill--active' );
				$btn.addClass( 'wpam-pa-status-pill--active' );
				State.status = $btn.data( 'status' ) || 'all';
				Toolbar.applyFilters();
			} );
		},

		applyFilters() {
			State.search   = $( '#wpam-pa-search' ).val().trim();
			State.category = parseInt( $( '#wpam-pa-filter-cat' ).val(), 10 ) || 0;
			State.tag      = parseInt( $( '#wpam-pa-filter-tag' ).val(), 10 ) || 0;
			State.offset   = 0;

			$( '#wpam-pa-board' ).empty();
			$( '#wpam-pa-load-more-wrap' ).hide();
			Board.load( true );
		},
	};

	// =========================================================================
	// Módulo: Board
	// =========================================================================

	const Board = {

		load( replace = false ) {
			if ( State.loading ) { return; }
			State.loading = true;

			const $board    = $( '#wpam-pa-board' );
			const $loadWrap = $( '#wpam-pa-load-more-wrap' );
			const $loadBtn  = $( '#wpam-pa-load-more-btn' );

			$loadBtn.prop( 'disabled', true ).text( cfg.i18n.loading );

			$.post( cfg.ajaxUrl, {
				action:   'wpam_load_posts',
				nonce:    $board.data( 'nonce' ),
				offset:   State.offset,
				limit:    State.offset === 0 ? 20 : cfg.moreLimit,
				search:   State.search,
				category: State.category,
				tag:      State.tag,
				status:   State.status,   // v0.1.1
			}, ( res ) => {
				State.loading = false;
				$loadBtn.prop( 'disabled', false );
				$loadBtn.html( 'Load more posts <span class="wpam-pa-load-more-arrow">↓</span>' );

				if ( ! res.success ) {
					Notice.show( cfg.i18n.error, 'error' );
					return;
				}

				const html    = res.data.html     || '';
				const hasMore = !! res.data.has_more;
				const count   = parseInt( res.data.count, 10 ) || 0;

				if ( replace ) {
					$board.html( html );
				} else {
					$board.append( html );
				}

				State.offset += count;
				State.hasMore = hasMore;
				hasMore ? $loadWrap.show() : $loadWrap.hide();

			} ).fail( () => {
				State.loading = false;
				$loadBtn.prop( 'disabled', false );
				$loadBtn.html( 'Load more posts <span class="wpam-pa-load-more-arrow">↓</span>' );
				Notice.show( cfg.i18n.error, 'error' );
			} );
		},
	};

	// =========================================================================
	// Módulo: Load More
	// =========================================================================

	const LoadMore = {
		init() {
			$( document ).on( 'click', '#wpam-pa-load-more-btn', () => {
				Board.load( false );
			} );
		},
	};

	// =========================================================================
	// Módulo: DomainDetector — alias del módulo compartido (v0.1.4)
	// La lógica real vive en domain-detector.js (window.WPAMDomainDetector).
	// No se duplica nada aquí: post-links.js usa el mismo objeto.
	// =========================================================================
	const DomainDetector = window.WPAMDomainDetector;

	// =========================================================================
	// Módulo: Editor inline
	// =========================================================================

	const Editor = {

		/**
		 * Snapshots inmutables por post_id.
		 * Se capturan en open() como HTML serializado — NO referencias a nodos vivos.
		 * Esto garantiza que remove() no puede mutar el snapshot.
		 * Formato: { postId: { listHtml: string, emptyVisible: bool } }
		 */
		_snapshots: {},

		/** Timers de debounce de detección por item ID. — v0.1.3 */
		_detectTimers: {},

		init() {
			// Chip click → abrir editor.
			$( document ).on( 'click', '.wpam-pa-chip', function () {
				Editor.open( String( $( this ).data( 'post-id' ) ) );
			} );

			// Botón "+" en la chips area → abrir editor + añadir fila.
			$( document ).on( 'click', '.wpam-pa-add-btn', function () {
				const postId = String( $( this ).data( 'post-id' ) );
				Editor.open( postId );
				Editor.addNewLinkRow( postId );
			} );

			// "Add Link" dentro del editor → siempre añade fila nueva.
			$( document ).on( 'click', '.wpam-pa-add-link-btn', function () {
				const postId = String( $( this ).data( 'post-id' ) );
				Editor.addNewLinkRow( postId );
			} );

			// Botón X del header del editor.
			$( document ).on( 'click', '.wpam-pa-editor-close', function () {
				Editor.cancel( String( $( this ).data( 'post-id' ) ) );
			} );

			// Botón "Cancel" en acciones.
			$( document ).on( 'click', '.wpam-pa-cancel-btn', function () {
				Editor.cancel( String( $( this ).data( 'post-id' ) ) );
			} );

			// Eliminar fila de link.
			$( document ).on( 'click', '.wpam-pa-remove-item-btn', function () {
				const $item = $( this ).closest( '.wpam-pa-link-item' );
				$item.fadeOut( 150, function () {
					$( this ).remove();
					const $list  = $item.closest( '.wpam-pa-link-list' );
					const postId = $list.attr( 'id' )?.replace( 'wpam-pa-link-list-', '' );
					if ( postId && ! $list.find( '.wpam-pa-link-item' ).length ) {
						const $empty = $( '#wpam-pa-empty-msg-' + postId );
						if ( $empty.length ) { $empty.show(); }
					}
					if ( postId ) { Editor.refreshSaveBtn( postId ); }
				} );
			} );

			// v0.1.3: Detección con debounce al escribir en el campo URL.
			$( document ).on( 'input', '.wpam-pa-url-input', function () {
				const $input = $( this );
				const $item  = $input.closest( '.wpam-pa-link-item' );
				const itemId = $item.attr( 'id' ) || '';
				const postId = $item.closest( '.wpam-pa-editor' ).attr( 'id' )?.replace( 'wpam-pa-editor-', '' ) || '';

				clearTimeout( Editor._detectTimers[ itemId ] );
				Editor.clearDetectState( $item );

				Editor._detectTimers[ itemId ] = setTimeout( function () {
					Editor.detectAffiliate( $item, postId );
				}, 500 );
			} );

			Save.init();
		},

		open( postId ) {
			const $editor = $( '#wpam-pa-editor-' + postId );
			if ( ! $editor.length ) { return; }

			$( '.wpam-pa-editor:visible' ).each( function () {
				const otherId = String( $( this ).closest( '.wpam-pa-row' ).data( 'post-id' ) );
				if ( otherId && otherId !== postId ) {
					$( this ).hide();
					$( '#wpam-pa-row-' + otherId ).removeClass( 'wpam-pa-row--editing' );
				}
			} );

			if ( ! Editor._snapshots[ postId ] ) {
				const $list     = $editor.find( '#wpam-pa-link-list-' + postId );
				const $emptyMsg = $( '#wpam-pa-empty-msg-' + postId );
				Editor._snapshots[ postId ] = {
					listHtml:     $list.html(),
					emptyVisible: $emptyMsg.is( ':visible' ),
				};
			}

			$editor.slideDown( 180 ).attr( 'aria-hidden', 'false' );
			$( '#wpam-pa-row-' + postId ).addClass( 'wpam-pa-row--editing' );

			$editor.find( '.wpam-pa-link-item' ).each( function () {
				const $item = $( this );
				const url   = $item.find( '.wpam-pa-url-input' ).val().trim();
				if ( url && ! $item.find( '.wpam-pa-detect-chip' ).length ) {
					Editor.detectAffiliate( $item, postId );
				}
			} );

			Editor.refreshSaveBtn( postId );
		},

		cancel( postId ) {
			const $editor   = $( '#wpam-pa-editor-' + postId );
			const $list     = $editor.find( '#wpam-pa-link-list-' + postId );
			const $emptyMsg = $( '#wpam-pa-empty-msg-' + postId );
			const snap      = Editor._snapshots[ postId ];

			if ( snap ) {
				$list.html( snap.listHtml );
				snap.emptyVisible ? $emptyMsg.show() : $emptyMsg.hide();
				delete Editor._snapshots[ postId ];
			}

			$editor.slideUp( 150 ).attr( 'aria-hidden', 'true' );
			$( '#wpam-pa-row-' + postId ).removeClass( 'wpam-pa-row--editing' );
		},

		addNewLinkRow( postId ) {
			const $list     = $( '#wpam-pa-link-list-' + postId );
			const $emptyMsg = $( '#wpam-pa-empty-msg-' + postId );

			$emptyMsg.hide();

			const tmpId = 'wpam-pa-item-' + postId + '-new-' + Date.now();

			const $newItem = $( `
				<div class="wpam-pa-link-item wpam-pa-link-item--new" id="${ escAttr( tmpId ) }">
					<div class="wpam-edit-grid wpam-pa-link-grid">
						<div class="wpam-edit-field wpam-edit-field--url wpam-pa-url-field">
							<label>${ escHtml( cfg.i18n.label_url || 'URL' ) }</label>
							<input type="url" class="wpam-input wpam-pa-url-input" value="" placeholder="https://..." />
							<div class="wpam-pa-detect-preview"></div>
							<div class="wpam-pa-url-error" style="display:none;"></div>
						</div>
						<div class="wpam-edit-field">
							<label>${ escHtml( cfg.i18n.label_label || 'Label' ) } <span class="wpam-optional">(${ escHtml( cfg.i18n.label_optional || 'opt.' ) })</span></label>
							<input type="text" class="wpam-input wpam-pa-label-input" value="" placeholder="${ escAttr( cfg.i18n.label_placeholder || 'e.g. Buy on Amazon' ) }" />
						</div>
						<div class="wpam-edit-field wpam-pa-item-remove-wrap">
							<label>&nbsp;</label>
							<button type="button" class="button wpam-pa-remove-item-btn" title="${ escAttr( cfg.i18n.remove_link || 'Remove' ) }">X</button>
						</div>
					</div>
				</div>
			` );

			$list.append( $newItem );
			$newItem.find( '.wpam-pa-url-input' ).focus();
			Editor.refreshSaveBtn( postId );
		},

		detectAffiliate( $item, postId ) {
			const url = $item.find( '.wpam-pa-url-input' ).val().trim();

			if ( ! url ) {
				Editor.clearDetectState( $item );
				Editor.refreshSaveBtn( postId );
				return;
			}

			if ( ! isValidUrl( url ) ) {
				Editor.setDetectError( $item, cfg.i18n.url_invalid || 'Enter a valid URL (https://...).' );
				Editor.refreshSaveBtn( postId );
				return;
			}

			const $editor  = $( '#wpam-pa-editor-' + postId );
			let affiliates = [];
			try {
				affiliates = JSON.parse( $editor.attr( 'data-affiliates' ) || '[]' );
			} catch ( _ ) {
				affiliates = [];
			}

			const domain   = DomainDetector.extractDomain( url );
			const detected = domain ? DomainDetector.findByDomain( domain, affiliates ) : null;

			if ( detected ) {
				Editor.setDetectSuccess( $item, detected );
			} else {
				Editor.setDetectError( $item, cfg.i18n.no_affiliate_found || 'No active affiliate found for this URL.' );
			}

			Editor.refreshSaveBtn( postId );
		},

		clearDetectState( $item ) {
			$item.removeClass( 'wpam-pa-link-item--detected wpam-pa-link-item--error' );
			$item.find( '.wpam-pa-detect-preview' ).empty();
			$item.find( '.wpam-pa-url-error' ).hide().text( '' );
			$item.removeData( 'detected-affiliate-id' );
		},

		setDetectSuccess( $item, aff ) {
			$item.removeClass( 'wpam-pa-link-item--error' ).addClass( 'wpam-pa-link-item--detected' );
			$item.find( '.wpam-pa-url-error' ).hide().text( '' );
			$item.data( 'detected-affiliate-id', aff.id );

			const color   = aff.brand_color || '#6c47ff';
			const bgColor = hexToRgba( color, 0.10 );
			const style   = '--chip-color:' + escAttr( color ) + ';--chip-bg:' + escAttr( bgColor ) + ';';

			const logoHtml = aff.logo_url
				? '<img class="wpam-pa-chip-logo" src="' + escAttr( aff.logo_url ) + '" alt="" />'
				: '<span class="wpam-pa-chip-initial">' + escHtml( aff.title.charAt( 0 ).toUpperCase() ) + '</span>';

			$item.find( '.wpam-pa-detect-preview' ).html(
				'<div class="wpam-pa-detect-chip" style="' + escAttr( style ) + '">' +
				logoHtml +
				'<span class="wpam-pa-detect-chip-name">' + escHtml( aff.title ) + '</span>' +
				'</div>'
			);
		},

		setDetectError( $item, message ) {
			$item.removeClass( 'wpam-pa-link-item--detected' ).addClass( 'wpam-pa-link-item--error' );
			$item.find( '.wpam-pa-detect-preview' ).empty();
			$item.find( '.wpam-pa-url-error' ).show().text( message );
			$item.removeData( 'detected-affiliate-id' );
		},

		refreshSaveBtn( postId ) {
			const $editor  = $( '#wpam-pa-editor-' + postId );
			const $saveBtn = $editor.find( '.wpam-pa-save-btn' );
			let blocked    = false;

			$editor.find( '.wpam-pa-link-item' ).each( function () {
				const $item = $( this );
				const url   = $item.find( '.wpam-pa-url-input' ).val().trim();
				if ( ! url ) { return true; }
				if ( $item.hasClass( 'wpam-pa-link-item--error' ) ) { blocked = true; return false; }
				if ( ! $item.hasClass( 'wpam-pa-link-item--detected' ) ) { blocked = true; return false; }
			} );

			$saveBtn.prop( 'disabled', blocked );
			if ( blocked ) {
				$saveBtn.attr( 'title', cfg.i18n.save_blocked || 'Fix errors before saving.' );
			} else {
				$saveBtn.removeAttr( 'title' );
			}
		},
	};

	// =========================================================================
	// Módulo: Save
	// =========================================================================

	const Save = {

		init() {
			$( document ).on( 'click', '.wpam-pa-save-btn', function () {
				if ( $( this ).prop( 'disabled' ) ) { return; }
				Save.save( String( $( this ).data( 'post-id' ) ) );
			} );
		},

		save( postId ) {
			const $editor  = $( '#wpam-pa-editor-' + postId );
			const $saveBtn = $editor.find( '.wpam-pa-save-btn' );
			const $spinner = $editor.find( '.wpam-saving-indicator' );
			const nonce    = $( '#wpam-pa-board' ).data( 'nonce' );

			const links        = [];
			const seenUrlsNorm = [];
			let hasDuplicate   = false;

			$editor.find( '.wpam-pa-link-list .wpam-pa-link-item' ).each( function () {
				const link = Save.readItem( $( this ) );
				if ( ! link ) { return; }

				const normUrl = normalizeUrlForComparison( link.original_url );
				if ( seenUrlsNorm.includes( normUrl ) ) {
					hasDuplicate = true;
					return false;
				}
				seenUrlsNorm.push( normUrl );
				links.push( link );
			} );

			if ( hasDuplicate ) {
				Notice.show( cfg.i18n.duplicate_url || 'Duplicate URL detected.', 'error' );
				return;
			}

			$saveBtn.prop( 'disabled', true );
			$spinner.show();

			$.post( cfg.ajaxUrl, {
				action:  'wpam_save_post_links',
				nonce:   nonce,
				post_id: postId,
				links:   JSON.stringify( links ),
			}, ( res ) => {
				$saveBtn.prop( 'disabled', false );
				$spinner.hide();

				if ( ! res.success ) {
					Notice.show( res.data?.message || cfg.i18n.error, 'error' );
					return;
				}

				delete Editor._snapshots[ postId ];

				const $oldRow = $( '#wpam-pa-row-' + postId );
				$oldRow.replaceWith( res.data.row_html );

				const $newRow = $( '#wpam-pa-row-' + postId );
				$newRow.addClass( 'wpam-pa-row--saved' );
				setTimeout( () => $newRow.removeClass( 'wpam-pa-row--saved' ), 1800 );

				Notice.show( cfg.i18n.saved, 'success' );

			} ).fail( () => {
				$saveBtn.prop( 'disabled', false );
				$spinner.hide();
				Notice.show( cfg.i18n.error, 'error' );
			} );
		},

		readItem( $item ) {
			if ( ! $item.length ) { return null; }
			const url   = $item.find( '.wpam-pa-url-input' ).val().trim();
			const label = $item.find( '.wpam-pa-label-input' ).val().trim();
			if ( ! url ) { return null; }
			if ( $item.hasClass( 'wpam-pa-link-item--error' ) ) { return null; }
			return { original_url: url, custom_label: label };
		},
	};

	// =========================================================================
	// Módulo: Notice
	// =========================================================================

	const Notice = {
		_timer: null,

		show( message, type ) {
			let $notice = $( '#wpam-ajax-notice' );
			if ( ! $notice.length ) {
				$notice = $( '<div id="wpam-ajax-notice" class="wpam-ajax-notice"></div>' )
					.prependTo( '.wpam-page-content' );
			}
			$notice
				.removeClass( 'wpam-ajax-notice--success wpam-ajax-notice--error' )
				.addClass( 'wpam-ajax-notice--' + type )
				.text( message )
				.fadeIn( 200 );

			clearTimeout( this._timer );
			this._timer = setTimeout( () => $notice.fadeOut( 400 ), 3500 );
		},
	};

	// =========================================================================
	// Utilidades
	// =========================================================================

	function normalizeUrlForComparison( url ) {
		try {
			const parsed = new URL( url.trim() );
			return parsed.protocol + '//' + parsed.hostname.toLowerCase() + parsed.pathname.replace( /\/+$/, '' ) + parsed.search;
		} catch ( _ ) {
			return url.trim().toLowerCase();
		}
	}

	function isValidUrl( url ) {
		try {
			const p = new URL( url.trim() );
			return p.protocol === 'http:' || p.protocol === 'https:';
		} catch ( _ ) {
			return false;
		}
	}

	function hexToRgba( hex, alpha ) {
		hex = String( hex ).replace( /^#/, '' );
		if ( hex.length === 3 ) { hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2]; }
		if ( hex.length !== 6 ) { return 'rgba(108,71,255,' + alpha + ')'; }
		return 'rgba(' + parseInt(hex.slice(0,2),16) + ',' + parseInt(hex.slice(2,4),16) + ',' + parseInt(hex.slice(4,6),16) + ',' + alpha + ')';
	}

	function escHtml( str ) {
		return String( str ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#039;' );
	}

	function escAttr( str ) { return escHtml( str ); }

} )( jQuery, window.wpamPAData || {
	ajaxUrl:   '',
	moreLimit: 10,
	i18n: {
		saving:             'Saving...',
		saved:              'Saved!',
		error:              'Error. Please try again.',
		loading:            'Loading...',
		no_more:            'No more posts.',
		remove_link:        'Remove this link',
		label_url:          'URL',
		label_label:        'Label',
		label_optional:     'opt.',
		label_placeholder:  'e.g. Buy on Amazon',
		url_invalid:        'Enter a valid URL (https://...).',
		no_affiliate_found: 'No active affiliate found for this URL.',
		save_blocked:       'Fix errors before saving.',
		duplicate_url:      'Duplicate URL detected. Remove the duplicate before saving.',
	},
} );
