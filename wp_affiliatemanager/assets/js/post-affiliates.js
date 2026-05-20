/**
 * Bunny Affiliate Manager — Post Affiliates Board
 *
 * Responsabilidades:
 * - Búsqueda/filtros con debounce → reemplaza board completo.
 * - Load More → append de rows al board.
 * - Expand/collapse del editor inline por post.
 * - Click en chip → expande editor y resalta el link.
 * - Click en "+" → expande editor y muestra formulario de nuevo link.
 * - Guardar: recoge links del DOM → JSON → AJAX → reemplaza row.
 * - Eliminar item de link: lo marca como eliminado en el DOM.
 *
 * No hay dependencias externas. Solo jQuery (ya disponible en WP admin).
 *
 * @package WP_AffiliateManager
 * @since   0.1.0
 */

/* global wpamPAData, jQuery */

( function ( $, cfg ) {
	'use strict';

	// =========================================================================
	// Estado
	// =========================================================================

	const State = {
		offset:   0,          // posts ya cargados
		hasMore:  false,      // hay más posts disponibles
		loading:  false,      // petición en curso
		search:   '',
		category: 0,
		tag:      0,
	};

	// =========================================================================
	// Init
	// =========================================================================

	$( function () {
		const $board = $( '#wpam-pa-board' );
		if ( ! $board.length ) { return; }

		// Leer estado inicial del board (renderizado por PHP).
		State.offset  = parseInt( $board.data( 'offset' ), 10 )   || 0;
		State.hasMore = '1' === String( $board.data( 'has-more' ) );

		Toolbar.init();
		Board.init();
		LoadMore.init();
	} );

	// =========================================================================
	// Módulo: Toolbar (búsqueda + filtros)
	// =========================================================================

	const Toolbar = {
		_timer: null,

		init() {
			$( '#wpam-pa-search' ).on( 'input', () => {
				clearTimeout( this._timer );
				this._timer = setTimeout( () => this.applyFilters(), 400 );
			} );

			$( '#wpam-pa-filter-cat, #wpam-pa-filter-tag' ).on( 'change', () => {
				this.applyFilters();
			} );
		},

		applyFilters() {
			State.search   = $( '#wpam-pa-search' ).val().trim();
			State.category = parseInt( $( '#wpam-pa-filter-cat' ).val(), 10 ) || 0;
			State.tag      = parseInt( $( '#wpam-pa-filter-tag' ).val(), 10 ) || 0;
			State.offset   = 0;

			// Reset board y cargar desde cero.
			$( '#wpam-pa-board' ).empty();
			LoadMore.reset();
			Board.load( true );
		},
	};

	// =========================================================================
	// Módulo: Board (carga de rows)
	// =========================================================================

	const Board = {

		init() {
			// Si no hay rows y has_more, cargar de entrada
			// (normalmente el PHP ya renderizó el bloque inicial).
		},

		/**
		 * Llama al AJAX unificado wpam_load_posts.
		 *
		 * @param {boolean} replace Si true reemplaza el board; si false hace append.
		 */
		load( replace = false ) {
			if ( State.loading ) { return; }
			State.loading = true;

			const $board     = $( '#wpam-pa-board' );
			const $loadWrap  = $( '#wpam-pa-load-more-wrap' );
			const $loadBtn   = $( '#wpam-pa-load-more-btn' );

			$loadBtn.prop( 'disabled', true ).text( cfg.i18n.loading );

			$.post( cfg.ajaxUrl, {
				action:   'wpam_load_posts',
				nonce:    $board.data( 'nonce' ),
				offset:   State.offset,
				limit:    State.offset === 0 ? 20 : cfg.moreLimit,
				search:   State.search,
				category: State.category,
				tag:      State.tag,
			}, ( res ) => {
				State.loading = false;
				$loadBtn.prop( 'disabled', false ).text( 'Load more posts' );

				if ( ! res.success ) {
					Notice.show( cfg.i18n.error, 'error' );
					return;
				}

				const html     = res.data.html     || '';
				const hasMore  = !! res.data.has_more;
				const count    = res.data.count    || 0;

				if ( replace ) {
					$board.html( html );
				} else {
					$board.append( html );
				}

				State.offset += count;
				State.hasMore = hasMore;

				if ( hasMore ) {
					$loadWrap.show();
				} else {
					$loadWrap.hide();
				}
			} ).fail( () => {
				State.loading = false;
				$loadBtn.prop( 'disabled', false ).text( 'Load more posts' );
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

		reset() {
			$( '#wpam-pa-load-more-wrap' ).hide();
		},
	};

	// =========================================================================
	// Módulo: Editor inline
	// =========================================================================

	const Editor = {

		// ------------------------------------------------------------------
		// Apertura / cierre
		// ------------------------------------------------------------------

		open( postId ) {
			const $editor = $( '#wpam-pa-editor-' + postId );
			if ( ! $editor.length ) { return; }

			// Cerrar cualquier otro editor abierto.
			$( '.wpam-pa-editor:visible' ).each( function () {
				const otherPost = $( this ).closest( '.wpam-pa-row' ).data( 'post-id' );
				if ( otherPost && otherPost !== postId ) {
					Editor.close( otherPost );
				}
			} );

			$editor.slideDown( 180 ).attr( 'aria-hidden', 'false' );
			$( '#wpam-pa-row-' + postId ).addClass( 'wpam-pa-row--editing' );
		},

		close( postId ) {
			const $editor = $( '#wpam-pa-editor-' + postId );
			$editor.slideUp( 150 ).attr( 'aria-hidden', 'true' );
			$( '#wpam-pa-row-' + postId ).removeClass( 'wpam-pa-row--editing' );
		},

		// ------------------------------------------------------------------
		// Click en chip → abrir editor (el chip ya está en el DOM)
		// ------------------------------------------------------------------

		bindChipClick() {
			$( document ).on( 'click', '.wpam-pa-chip', function () {
				const postId = $( this ).data( 'post-id' );
				Editor.open( postId );
			} );
		},

		// ------------------------------------------------------------------
		// Click en "+" → abrir editor y mostrar formulario nuevo link
		// ------------------------------------------------------------------

		bindAddBtn() {
			$( document ).on( 'click', '.wpam-pa-add-btn', function () {
				const postId = $( this ).data( 'post-id' );
				Editor.open( postId );
				Editor.showNewLinkForm( postId );
			} );
		},

		// ------------------------------------------------------------------
		// Botón "Add Link" dentro del editor → muestra formulario vacío
		// ------------------------------------------------------------------

		bindAddLinkBtn() {
			$( document ).on( 'click', '.wpam-pa-add-link-btn', function () {
				const postId = $( this ).data( 'post-id' );
				Editor.showNewLinkForm( postId );
			} );
		},

		showNewLinkForm( postId ) {
			const $wrap = $( '#wpam-pa-new-wrap-' + postId );
			if ( $wrap.length ) {
				$wrap.show();
				$wrap.find( '.wpam-pa-provider-select' ).first().focus();
			}
		},

		// ------------------------------------------------------------------
		// Cerrar editor (botón ✕ o Cancel)
		// ------------------------------------------------------------------

		bindCloseBtn() {
			$( document ).on( 'click', '.wpam-pa-editor-close', function () {
				const postId = $( this ).data( 'post-id' );
				Editor.close( postId );
			} );
		},

		// ------------------------------------------------------------------
		// Eliminar un link item del DOM
		// ------------------------------------------------------------------

		bindRemoveItem() {
			$( document ).on( 'click', '.wpam-pa-remove-item-btn', function () {
				const $item = $( this ).closest( '.wpam-pa-link-item' );
				$item.fadeOut( 150, function () { $( this ).remove(); } );
			} );
		},

		// ------------------------------------------------------------------
		// Init de todos los bindings del editor
		// ------------------------------------------------------------------

		init() {
			this.bindChipClick();
			this.bindAddBtn();
			this.bindAddLinkBtn();
			this.bindCloseBtn();
			this.bindRemoveItem();
			Save.init();
		},
	};

	// =========================================================================
	// Módulo: Save
	// =========================================================================

	const Save = {

		init() {
			$( document ).on( 'click', '.wpam-pa-save-btn', function () {
				const postId = $( this ).data( 'post-id' );
				Save.save( postId );
			} );
		},

		/**
		 * Recoge todos los link items del editor, los serializa y envía al servidor.
		 * El servidor devuelve el row HTML completo actualizado.
		 */
		save( postId ) {
			const $editor   = $( '#wpam-pa-editor-' + postId );
			const $saveBtn  = $editor.find( '.wpam-pa-save-btn' );
			const $spinner  = $editor.find( '.wpam-saving-indicator' );
			const $board    = $( '#wpam-pa-board' );
			const nonce     = $board.data( 'nonce' );

			// Recoger links del DOM: lista existente + formulario nuevo (si visible).
			const links = [];

			// Items existentes (en la lista).
			$( '#wpam-pa-link-list-' + postId )
				.find( '.wpam-pa-link-item' )
				.each( function () {
					const link = Save.readItem( $( this ) );
					if ( link ) { links.push( link ); }
				} );

			// Ítem nuevo (en el formulario "new wrap") si está visible.
			const $newWrap = $( '#wpam-pa-new-wrap-' + postId );
			if ( $newWrap.is( ':visible' ) ) {
				const link = Save.readItem( $newWrap.find( '.wpam-pa-link-item' ) );
				if ( link ) { links.push( link ); }
			}

			// UI: deshabilitar.
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

				// Reemplazar el row completo con el HTML actualizado.
				const $oldRow = $( '#wpam-pa-row-' + postId );
				$oldRow.replaceWith( res.data.row_html );

				// Flash breve en el nuevo row.
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

		/**
		 * Lee los datos de un .wpam-pa-link-item y devuelve un objeto o null.
		 */
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
			// Reutilizar el notice global si existe, si no crear uno temporal.
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
	// Inicialización global
	// =========================================================================

	$( function () {
		if ( $( '#wpam-pa-board' ).length ) {
			Editor.init();
		}
	} );

} )( jQuery, window.wpamPAData || {
	ajaxUrl:   '',
	moreLimit: 10,
	i18n: {
		saving:      'Saving…',
		saved:       'Saved!',
		error:       'Error. Please try again.',
		loading:     'Loading…',
		no_more:     'No more posts.',
		confirm_del: 'Remove this link?',
	},
} );
