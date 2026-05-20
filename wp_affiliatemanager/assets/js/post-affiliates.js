/**
 * Bunny Affiliate Manager — Post Affiliates Board
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
 * @version 0.1.1
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

			// Botón ✕ del header del editor.
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
					// Mostrar mensaje vacío si no quedan items.
					const $list = $item.closest( '.wpam-pa-link-list' );
					const postId = $list.attr( 'id' )?.replace( 'wpam-pa-link-list-', '' );
					if ( postId && ! $list.find( '.wpam-pa-link-item' ).length ) {
						const $empty = $( '#wpam-pa-empty-msg-' + postId );
						if ( $empty.length ) { $empty.show(); }
					}
				} );
			} );

			Save.init();
		},

		// ------------------------------------------------------------------
		// Abrir editor — snapshot como HTML serializado (inmutable por definición)
		// ------------------------------------------------------------------

		open( postId ) {
			const $editor = $( '#wpam-pa-editor-' + postId );
			if ( ! $editor.length ) { return; }

			// Cerrar otros editores abiertos.
			$( '.wpam-pa-editor:visible' ).each( function () {
				const otherId = String( $( this ).closest( '.wpam-pa-row' ).data( 'post-id' ) );
				if ( otherId && otherId !== postId ) {
					$( this ).hide();
					$( '#wpam-pa-row-' + otherId ).removeClass( 'wpam-pa-row--editing' );
				}
			} );

			// Snapshot como HTML serializado de la lista — inmutable porque es una
			// cadena de texto, no una referencia al DOM. Remove() no lo afecta.
			// Solo se captura la primera vez (no sobreescribir con estado mutado).
			if ( ! Editor._snapshots[ postId ] ) {
				const $list     = $editor.find( '#wpam-pa-link-list-' + postId );
				const $emptyMsg = $( '#wpam-pa-empty-msg-' + postId );
				Editor._snapshots[ postId ] = {
					listHtml:     $list.html(),           // HTML completo de la lista
					emptyVisible: $emptyMsg.is( ':visible' ),
				};
			}

			$editor.slideDown( 180 ).attr( 'aria-hidden', 'false' );
			$( '#wpam-pa-row-' + postId ).addClass( 'wpam-pa-row--editing' );
		},

		// ------------------------------------------------------------------
		// Cancelar — reconstruir lista desde HTML serializado, destruir temporales
		// ------------------------------------------------------------------

		cancel( postId ) {
			const $editor   = $( '#wpam-pa-editor-' + postId );
			const $list     = $editor.find( '#wpam-pa-link-list-' + postId );
			const $emptyMsg = $( '#wpam-pa-empty-msg-' + postId );
			const snap      = Editor._snapshots[ postId ];

			if ( snap ) {
				// Restaurar la lista completa desde el HTML serializado capturado en open().
				// Esto incluye los nodos eliminados con X, porque el HTML es una cadena
				// que no fue afectada por remove(). También elimina las filas --new.
				$list.html( snap.listHtml );

				// Restaurar visibilidad del mensaje "no links".
				if ( snap.emptyVisible ) {
					$emptyMsg.show();
				} else {
					$emptyMsg.hide();
				}

				// Limpiar snapshot — próxima apertura lo regenerará fresco.
				delete Editor._snapshots[ postId ];
			}

			// Ocultar editor.
			$editor.slideUp( 150 ).attr( 'aria-hidden', 'true' );
			$( '#wpam-pa-row-' + postId ).removeClass( 'wpam-pa-row--editing' );
		},

		// ------------------------------------------------------------------
		// Añadir fila nueva — SIEMPRE crea un nodo nuevo desde cero
		// ------------------------------------------------------------------

		addNewLinkRow( postId ) {
			const $editor  = $( '#wpam-pa-editor-' + postId );
			const $list    = $( '#wpam-pa-link-list-' + postId );
			const $emptyMsg = $( '#wpam-pa-empty-msg-' + postId );

			// Ocultar mensaje vacío.
			$emptyMsg.hide();

			// Obtener afiliados desde data-affiliates del editor.
			let affiliates = [];
			try {
				affiliates = JSON.parse( $editor.attr( 'data-affiliates' ) || '[]' );
			} catch (e) {
				affiliates = [];
			}

			// Construir select de opciones.
			let optionsHtml = '<option value="">' + ( cfg.i18n.select_placeholder || '— Select —' ) + '</option>';
			affiliates.forEach( function ( aff ) {
				optionsHtml += '<option value="' + escAttr( String( aff.id ) ) + '">' + escHtml( aff.title ) + '</option>';
			} );

			// ID único para esta fila temporal.
			const tmpId = 'wpam-pa-item-' + postId + '-new-' + Date.now();

			const $newItem = $( `
				<div class="wpam-pa-link-item wpam-pa-link-item--new" id="${ escAttr( tmpId ) }" data-orig-provider="" data-orig-url="" data-orig-label="">
					<div class="wpam-edit-grid wpam-pa-link-grid">
						<div class="wpam-edit-field">
							<label>${ cfg.i18n.label_affiliate || 'Affiliate' }</label>
							<select class="wpam-select wpam-pa-provider-select">${ optionsHtml }</select>
						</div>
						<div class="wpam-edit-field wpam-edit-field--url">
							<label>${ cfg.i18n.label_url || 'URL' }</label>
							<input type="url" class="wpam-input wpam-pa-url-input" value="" placeholder="https://…" />
						</div>
						<div class="wpam-edit-field">
							<label>${ cfg.i18n.label_label || 'Label' } <span class="wpam-optional">(${cfg.i18n.label_optional || 'opt.'})</span></label>
							<input type="text" class="wpam-input wpam-pa-label-input" value="" placeholder="${ cfg.i18n.label_placeholder || 'e.g. Buy on Amazon' }" />
						</div>
						<div class="wpam-edit-field wpam-pa-item-remove-wrap">
							<label>&nbsp;</label>
							<button type="button" class="button wpam-pa-remove-item-btn" title="${ cfg.i18n.remove_link || 'Remove' }">✕</button>
						</div>
					</div>
				</div>
			` );

			$list.append( $newItem );
			$newItem.find( '.wpam-pa-provider-select' ).focus();
		},
	};

	// =========================================================================
	// Módulo: Save
	// =========================================================================

	const Save = {

		init() {
			$( document ).on( 'click', '.wpam-pa-save-btn', function () {
				Save.save( String( $( this ).data( 'post-id' ) ) );
			} );
		},

		save( postId ) {
			const $editor  = $( '#wpam-pa-editor-' + postId );
			const $saveBtn = $editor.find( '.wpam-pa-save-btn' );
			const $spinner = $editor.find( '.wpam-saving-indicator' );
			const nonce    = $( '#wpam-pa-board' ).data( 'nonce' );

			// Recoger TODOS los link items visibles del editor.
			const links = [];
			$editor.find( '.wpam-pa-link-list .wpam-pa-link-item' ).each( function () {
				const link = Save.readItem( $( this ) );
				if ( link ) { links.push( link ); }
			} );

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
					Notice.show( cfg.i18n.error, 'error' );
					return;
				}

				// Reemplazar el row COMPLETO (editor incluido) con HTML fresco.
				// Esto destruye el estado temporal por definición.
				// Limpiar también el snapshot para que no quede huérfano.
				delete Editor._snapshots[ postId ];

				const $oldRow = $( '#wpam-pa-row-' + postId );
				$oldRow.replaceWith( res.data.row_html );

				// El nuevo row tiene el editor oculto por defecto (PHP lo renderiza así).
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
			const providerId = $item.find( '.wpam-pa-provider-select' ).val();
			const url        = $item.find( '.wpam-pa-url-input' ).val().trim();
			const label      = $item.find( '.wpam-pa-label-input' ).val().trim();
			if ( ! providerId || ! url ) { return null; }
			return {
				provider_id:  parseInt( providerId, 10 ),
				original_url: url,
				custom_label: label,
			};
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
	// Utilidades de escape (seguras para inyección en template strings)
	// =========================================================================

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function escAttr( str ) {
		return escHtml( str );
	}

} )( jQuery, window.wpamPAData || {
	ajaxUrl:   '',
	moreLimit: 10,
	i18n: {
		saving:            'Saving…',
		saved:             'Saved!',
		error:             'Error. Please try again.',
		loading:           'Loading…',
		no_more:           'No more posts.',
		remove_link:       'Remove this link',
		select_placeholder:'— Select —',
		label_affiliate:   'Affiliate',
		label_url:         'URL',
		label_label:       'Label',
		label_optional:    'opt.',
		label_placeholder: 'e.g. Buy on Amazon',
	},
} );
