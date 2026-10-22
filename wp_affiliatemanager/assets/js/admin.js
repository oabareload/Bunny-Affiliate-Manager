/**
 * Bunny Affiliate Manager — Admin Scripts
 *
 * @package WP_AffiliateManager
 * @since   1.0.0
 * @version 0.0.6
 */

/* global wpamAdminData, jQuery, wp */

( function ( $, data ) {
	'use strict';

	// =========================================================================
	// Módulo de CRUD inline (v0.0.6)
	// =========================================================================

	const InlineCRUD = {

		/**
		 * ID del afiliado que está actualmente en modo edición (0 = ninguno).
		 */
		_editingId: 0,

		/**
		 * TR guardado para restaurar en cancel de edición.
		 */
		_originalRow: null,

		init() {
			this.bindAdd();
			this.bindEdit();
			this.bindSave();
			this.bindCancel();
			this.bindLogoPicker();
		},

		// ------------------------------------------------------------------
		// Botón "Add Affiliate" — inserta fila nueva al inicio del tbody
		// ------------------------------------------------------------------

		bindAdd() {
			$( document ).on( 'click', '#wpam-add-affiliate-btn', () => {
				// Si ya hay una fila nueva abierta, ignorar.
				if ( $( '#wpam-new-row' ).length ) {
					$( '#wpam-new-row .wpam-ef-title' ).focus();
					return;
				}

				// Solicitar la fila de edición vacía al servidor.
				this.fetchEditRow( 0, ( html ) => {
					const $tbody = $( '#wpam-affiliates-tbody' );
					// Ocultar fila vacía si existe.
					$( '#wpam-empty-row' ).hide();
					$tbody.prepend( html );
					$( '#wpam-new-row .wpam-ef-title' ).focus();
				} );
			} );
		},

		// ------------------------------------------------------------------
		// Botón "Edit" (✏️) en cada fila — reemplaza fila por formulario
		// ------------------------------------------------------------------

		bindEdit() {
			$( document ).on( 'click', '.wpam-action-btn--edit', ( e ) => {
				const $btn = $( e.currentTarget );
				const id   = parseInt( $btn.data( 'id' ), 10 );

				// Si ya está editando este mismo, cancelar.
				if ( this._editingId === id ) {
					this.cancelEdit( id );
					return;
				}

				// Cancelar edición previa si la hay.
				if ( this._editingId ) {
					this.cancelEdit( this._editingId );
				}

				const $row = $( '#wpam-row-' + id );
				if ( ! $row.length ) { return; }

				// Guardar la fila original para poder restaurar al cancelar.
				this._originalRow = $row.clone( true );
				this._editingId   = id;

				this.fetchEditRow( id, ( html ) => {
					$row.replaceWith( html );
					$( '#wpam-row-' + id + ' .wpam-ef-title' ).focus();
				} );
			} );
		},

		// ------------------------------------------------------------------
		// Botón "Save" dentro de la fila de edición
		// ------------------------------------------------------------------

		bindSave() {
			$( document ).on( 'click', '.wpam-save-inline-btn', ( e ) => {
				const $btn  = $( e.currentTarget );
				const id    = parseInt( $btn.data( 'id' ), 10 );
				const $row  = id === 0 ? $( '#wpam-new-row' ) : $( '#wpam-row-' + id );
				const $form = $row.find( '.wpam-edit-form' );

				const title = $form.find( '.wpam-ef-title' ).val().trim();
				if ( ! title ) {
					$form.find( '.wpam-ef-title' ).addClass( 'wpam-input--error' ).focus();
					return;
				}

				const payload = {
					action:      'wpam_save_affiliate',
					nonce:       data.crudNonce,
					id:          id,
					title:       title,
					slug:        $form.find( '.wpam-ef-slug' ).val().trim(),
					param:       $form.find( '.wpam-ef-param' ).val().trim(),
					value:       $form.find( '.wpam-ef-value' ).val().trim(),
					logo_url:    $form.find( '.wpam-ef-logo' ).val().trim(),
					brand_color: $form.find( '.wpam-ef-color' ).val(),
					domains:     $form.find( '.wpam-ef-domains' ).val().trim(),
					default_url_template: $form.find( '.wpam-ef-default-url-template' ).val(),
					active:      $form.find( '.wpam-ef-active' ).is( ':checked' ) ? '1' : '',
					visible:     $form.find( '.wpam-ef-visible' ).is( ':checked' ) ? '1' : '',
					use_global_disclaimer: $form.find( '.wpam-ef-use-global-disclaimer' ).is( ':checked' ) ? '1' : '',
					custom_disclaimer:     $form.find( '.wpam-ef-custom-disclaimer' ).val().trim(),
					related_post_id:       $form.find( '.wpam-ef-related-post' ).val(),
				};

				// UI de carga.
				$btn.prop( 'disabled', true );
				$form.find( '.wpam-saving-indicator' ).show();
				$form.find( '.wpam-cancel-inline-btn' ).prop( 'disabled', true );

				$.post( data.ajaxUrl, payload, ( res ) => {
					$btn.prop( 'disabled', false );
					$form.find( '.wpam-saving-indicator' ).hide();
					$form.find( '.wpam-cancel-inline-btn' ).prop( 'disabled', false );

					if ( ! res.success ) {
						this.showNotice( res.data || data.i18n.error_generic, 'error' );
						return;
					}

					const isNew = res.data.is_new;
					const newId = res.data.affiliate.id;
					const html  = res.data.row_html;

					if ( isNew ) {
						// Reemplazar fila nueva por la fila real.
						$( '#wpam-new-row' ).replaceWith( html );
						// Actualizar contador.
						this.incrementCount( 1 );
					} else {
						// Reemplazar fila de edición por la fila actualizada.
						$( '#wpam-row-' + id ).replaceWith( html );
						this._editingId   = 0;
						this._originalRow = null;
					}

					// Destacar la fila guardada brevemente.
					$( '#wpam-row-' + newId ).addClass( 'wpam-row--saved' );
					setTimeout( () => $( '#wpam-row-' + newId ).removeClass( 'wpam-row--saved' ), 1800 );

					this.showNotice( data.i18n.saved, 'success' );
				} ).fail( () => {
					$btn.prop( 'disabled', false );
					$form.find( '.wpam-saving-indicator' ).hide();
					$form.find( '.wpam-cancel-inline-btn' ).prop( 'disabled', false );
					this.showNotice( data.i18n.error_generic, 'error' );
				} );
			} );
		},

		// ------------------------------------------------------------------
		// Botón "Cancel"
		// ------------------------------------------------------------------

		bindCancel() {
			$( document ).on( 'click', '.wpam-cancel-inline-btn', ( e ) => {
				const id = parseInt( $( e.currentTarget ).data( 'id' ), 10 );
				this.cancelEdit( id );
			} );
		},

		cancelEdit( id ) {
			if ( id === 0 ) {
				// Era una fila nueva: simplemente eliminarla.
				$( '#wpam-new-row' ).remove();
				// Si el tbody quedó vacío, mostrar fila vacía.
				if ( ! $( '#wpam-affiliates-tbody tr:visible' ).length ) {
					$( '#wpam-empty-row' ).show();
				}
				return;
			}

			// Era edición de existente: restaurar fila original.
			const $editRow = $( '#wpam-row-' + id );
			if ( $editRow.length && this._originalRow ) {
				$editRow.replaceWith( this._originalRow );
			}

			this._editingId   = 0;
			this._originalRow = null;
		},

		// ------------------------------------------------------------------
		// Logo Picker — Media Library (v0.0.6)
		// ------------------------------------------------------------------

		bindLogoPicker() {
			// Abre wp.media() al hacer click en el botón "Select logo" o en el preview.
			$( document ).on(
				'click',
				'.wpam-logo-picker-btn, .wpam-logo-picker-preview',
				( e ) => {
					e.preventDefault();
					const $picker = $( e.currentTarget ).closest( '.wpam-logo-picker' );
					this.openMediaFrame( $picker );
				}
			);

			// Botón "Remove": borra preview y URL, vuelve al estado vacío.
			$( document ).on( 'click', '.wpam-logo-picker-remove', ( e ) => {
				e.preventDefault();
				const $picker = $( e.currentTarget ).closest( '.wpam-logo-picker' );
				this.clearLogo( $picker );
			} );
		},

		/**
		 * Abre la Media Library de WordPress y conecta la selección al picker.
		 * Cada apertura crea un frame fresco para evitar referencias obsoletas
		 * cuando se abren distintas filas de edición en la misma sesión.
		 */
		openMediaFrame( $picker ) {
			const frame = window.wp.media( {
				title:    'Select Affiliate Logo',
				button:   { text: 'Use this image' },
				multiple: false,
				library:  { type: 'image' },
			} );

			frame.on( 'select', () => {
				const attachment = frame.state().get( 'selection' ).first().toJSON();
				this.setLogo( $picker, attachment.url );
			} );

			frame.open();
		},

		/**
		 * Establece el logo en el picker: actualiza input hidden + muestra preview.
		 */
		setLogo( $picker, url ) {
			// 1. Actualizar el input hidden.
			$picker.find( '.wpam-ef-logo' ).val( url );

			// 2. Quitar el botón vacío si existía.
			$picker.find( '.wpam-logo-picker-btn' ).remove();

			// 3. Insertar o reemplazar el bloque preview.
			const previewHtml =
				'<div class="wpam-logo-picker-preview">' +
					'<img src="' + $( '<div>' ).text( url ).html() + '" alt="" />' +
					'<div class="wpam-logo-picker-overlay"><span>Edit logo</span></div>' +
				'</div>';

			if ( $picker.find( '.wpam-logo-picker-preview' ).length ) {
				$picker.find( '.wpam-logo-picker-preview' ).replaceWith( previewHtml );
			} else {
				$picker.find( '.wpam-ef-logo' ).after( previewHtml );
			}

			// 4. Añadir botón "Remove" si no existía.
			if ( ! $picker.find( '.wpam-logo-picker-remove' ).length ) {
				$picker.append( '<button type="button" class="wpam-logo-picker-remove">Remove</button>' );
			}

			$picker.attr( 'data-has-logo', '1' );
		},

		/**
		 * Limpia el logo: borra URL, elimina preview, vuelve al botón vacío.
		 */
		clearLogo( $picker ) {
			$picker.find( '.wpam-ef-logo' ).val( '' );
			$picker.find( '.wpam-logo-picker-preview' ).remove();
			$picker.find( '.wpam-logo-picker-remove' ).remove();

			const emptyBtn =
				'<button type="button" class="wpam-logo-picker-btn">' +
					'<span class="wpam-logo-picker-icon">🖼️</span> Select logo' +
				'</button>';

			$picker.find( '.wpam-ef-logo' ).after( emptyBtn );
			$picker.attr( 'data-has-logo', '0' );
		},

		// ------------------------------------------------------------------
		// Fetch de la fila de edición desde el servidor
		// ------------------------------------------------------------------

		fetchEditRow( id, callback ) {
			$.post(
				data.ajaxUrl,
				{ action: 'wpam_get_edit_row', nonce: data.crudNonce, id: id },
				( res ) => {
					if ( res.success ) {
						callback( res.data.row_html );
					} else {
						this.showNotice( res.data || data.i18n.error_generic, 'error' );
					}
				}
			).fail( () => this.showNotice( data.i18n.error_generic, 'error' ) );
		},

		// ------------------------------------------------------------------
		// Helpers UI
		// ------------------------------------------------------------------

		/**
		 * Muestra un notice temporal en el contenedor #wpam-ajax-notice.
		 */
		showNotice( message, type ) {
			const $notice = $( '#wpam-ajax-notice' );
			$notice
				.removeClass( 'wpam-ajax-notice--success wpam-ajax-notice--error' )
				.addClass( 'wpam-ajax-notice--' + type )
				.text( message )
				.fadeIn( 200 );

			clearTimeout( this._noticeTimer );
			this._noticeTimer = setTimeout( () => $notice.fadeOut( 400 ), 3500 );
		},

		/**
		 * Actualiza el badge de conteo de afiliados.
		 */
		incrementCount( delta ) {
			const $badge = $( '#wpam-affiliates-count' );
			const current = parseInt( $badge.text(), 10 ) || 0;
			$badge.text( current + delta );
		},

		_noticeTimer: null,
	};

	// =========================================================================
	// Modal "Construir URL" — dynamic Default URL template (v1.8.14)
	//
	// One modal for the whole screen. It edits the JSON kept in the hidden
	// `.wpam-ef-default-url-template` input of the affiliate row being edited;
	// nothing is persisted until the row's own Save button is pressed, and the
	// server re-validates everything (URL_Template::sanitize()).
	// =========================================================================

	const UrlBuilder = {

		/** `.wpam-edit-field--default-url` of the row being edited. */
		$field: null,

		_suggestTimer: null,
		_suggestSeq:   0,
		_suggestCache: {},

		init() {
			this.$overlay = $( '#wpam-urlb-overlay' );
			if ( ! this.$overlay.length ) { return; } // Not the Affiliates screen.

			this.$params  = $( '#wpam-urlb-params' );
			this.$error   = $( '#wpam-urlb-error' );
			this.$base    = $( '#wpam-urlb-base' );
			this.$preview = $( '#wpam-urlb-preview' );

			$( document ).on( 'click', '.wpam-urlb-open', ( e ) => {
				e.preventDefault();
				this.open( $( e.currentTarget ).closest( '.wpam-edit-field--default-url' ) );
			} );

			$( '#wpam-urlb-add' ).on( 'click', () => this.addRow() );
			$( '#wpam-urlb-apply' ).on( 'click', () => this.apply() );
			$( '#wpam-urlb-remove-template' ).on( 'click', () => this.removeTemplate() );
			$( '#wpam-urlb-close, #wpam-urlb-cancel' ).on( 'click', () => this.close() );

			this.$overlay.on( 'click', ( e ) => {
				if ( e.target === this.$overlay.get( 0 ) ) { this.close(); }
			} );
			$( document ).on( 'keydown', ( e ) => {
				if ( 'Escape' === e.key && this.$overlay.is( ':visible' ) ) { this.close(); }
			} );

			this.$base.on( 'input', () => this.refreshPreview() );

			// Parameter rows (delegated on the container; rows are cloned from a <template>).
			this.$params.on( 'click', '.wpam-urlb-remove', ( e ) => {
				$( e.currentTarget ).closest( '.wpam-urlb-row' ).remove();
				this.refreshPreview();
			} );
			this.$params.on( 'change', '.wpam-urlb-source', ( e ) => {
				this.applySource( $( e.currentTarget ).closest( '.wpam-urlb-row' ) );
				this.refreshPreview();
			} );
			this.$params.on( 'input change', '.wpam-urlb-name, .wpam-urlb-value, .wpam-urlb-required', () => this.refreshPreview() );

			// Meta-key search (only for rows whose source is "meta").
			this.$params.on( 'input focus', '.wpam-urlb-value', ( e ) => {
				const $row = $( e.currentTarget ).closest( '.wpam-urlb-row' );
				if ( 'meta' !== $row.find( '.wpam-urlb-source' ).val() ) {
					this.hideSuggest( $row );
					return;
				}
				clearTimeout( this._suggestTimer );
				this._suggestTimer = setTimeout( () => this.fetchKeys( $row ), 250 );
			} );
			this.$params.on( 'blur', '.wpam-urlb-value', ( e ) => {
				const $row = $( e.currentTarget ).closest( '.wpam-urlb-row' );
				setTimeout( () => this.hideSuggest( $row ), 150 );
			} );
			this.$params.on( 'keydown', '.wpam-urlb-value', ( e ) => this.onSuggestKey( e ) );
			// mousedown (not click) so the pick lands before the input's blur hides the list.
			this.$params.on( 'mousedown', '.wpam-urlb-suggest li[data-key]', ( e ) => {
				e.preventDefault();
				this.pick( $( e.currentTarget ) );
			} );
		},

		open( $field ) {
			this.$field = $field;

			let tpl = null;
			try {
				tpl = JSON.parse( $field.find( '.wpam-ef-default-url-template' ).val() || 'null' );
			} catch ( err ) {
				tpl = null;
			}

			this.$params.empty();
			this.$base.val( tpl && tpl.base_url ? tpl.base_url : '' );
			( tpl && Array.isArray( tpl.params ) ? tpl.params : [] ).forEach( ( p ) => this.addRow( p ) );
			if ( ! this.$params.children().length ) { this.addRow(); }

			$( '#wpam-urlb-remove-template' ).toggle( !! ( tpl && tpl.base_url ) );
			this.$error.hide().text( '' );
			this.refreshPreview();

			this.$overlay.css( 'display', 'flex' );
			$( 'body' ).addClass( 'wpam-modal-open' );
			this.$base.trigger( 'focus' );
		},

		close() {
			this.$overlay.hide();
			$( 'body' ).removeClass( 'wpam-modal-open' );
			this.$params.find( '.wpam-urlb-suggest' ).prop( 'hidden', true );
			this.$field = null;
		},

		addRow( param ) {
			param = param || { name: '', source: 'text', value: '', required: false };

			const tpl  = document.getElementById( 'wpam-urlb-row-template' );
			const $row = $( tpl.content.firstElementChild.cloneNode( true ) );

			$row.find( '.wpam-urlb-name' ).val( param.name || '' );
			$row.find( '.wpam-urlb-source' ).val( 'meta' === param.source ? 'meta' : 'text' );
			$row.find( '.wpam-urlb-value' ).val( param.value || '' );
			$row.find( '.wpam-urlb-required' ).val( param.required ? '1' : '0' );

			this.applySource( $row );
			this.$params.append( $row );
			this.refreshPreview();
			return $row;
		},

		/** "Requisito" only applies to meta; fixed text is always "No aplica". */
		applySource( $row ) {
			const isMeta = 'meta' === $row.find( '.wpam-urlb-source' ).val();
			const $req   = $row.find( '.wpam-urlb-required' );

			$row.find( '.wpam-urlb-value' ).attr( 'placeholder', isMeta ? 'figure_series' : 'figure' );

			if ( isMeta ) {
				$req.prop( 'disabled', false );
				if ( 'na' === $req.val() ) { $req.val( '1' ); }
			} else {
				$req.val( 'na' ).prop( 'disabled', true );
				this.hideSuggest( $row );
			}
		},

		collect() {
			const params = [];

			this.$params.children( '.wpam-urlb-row' ).each( ( i, el ) => {
				const $r     = $( el );
				const source = $r.find( '.wpam-urlb-source' ).val();
				params.push( {
					name:     $r.find( '.wpam-urlb-name' ).val().trim(),
					source:   source,
					value:    $r.find( '.wpam-urlb-value' ).val().trim(),
					required: 'meta' === source && '1' === $r.find( '.wpam-urlb-required' ).val(),
				} );
			} );

			return {
				base_url: this.$base.val().trim(),
				params:   params.filter( ( p ) => p.name || p.value ), // drop fully empty rows
			};
		},

		/** Same rules as URL_Template::sanitize(); the server is still the authority. */
		validate( tpl ) {
			const i18n = data.i18n || {};

			if ( ! /^https?:\/\/[^\s\/?#]+/i.test( tpl.base_url ) ) { return i18n.urlb_err_base; }

			const seen = new Set();
			for ( const p of tpl.params ) {
				if ( ! /^[A-Za-z0-9_-]{1,64}$/.test( p.name ) ) { return i18n.urlb_err_name; }
				if ( seen.has( p.name ) ) { return i18n.urlb_err_dup; }
				seen.add( p.name );
				if ( ! p.value ) { return i18n.urlb_err_value; }
			}

			return '';
		},

		apply() {
			const tpl = this.collect();
			const err = this.validate( tpl );

			if ( err ) {
				this.$error.text( err ).show();
				return;
			}

			this.$field.find( '.wpam-ef-default-url-template' ).val( JSON.stringify( tpl ) );
			this.refreshField( this.$field, tpl );
			this.close();
		},

		removeTemplate() {
			this.$field.find( '.wpam-ef-default-url-template' ).val( '' );
			this.refreshField( this.$field, null );
			this.close();
		},

		/** Summary line shown next to the "Construir URL" button. */
		refreshField( $field, tpl ) {
			const $summary = $field.find( '.wpam-urlb-summary' );

			if ( ! tpl ) {
				$summary.prop( 'hidden', true ).text( '' );
				return;
			}

			$summary
				.text(
					( ( data.i18n && data.i18n.urlb_summary ) || '' )
						.replace( '%1$s', () => tpl.base_url )
						.replace( '%2$s', () => String( tpl.params.length ) )
				)
				.prop( 'hidden', false );
		},

		/** Shape of the URL (not resolved values): {key} = required meta, {key}? = optional meta. */
		refreshPreview() {
			const tpl   = this.collect();
			const pairs = tpl.params.filter( ( p ) => p.name ).map( ( p ) => {
				let v = p.value;
				if ( 'meta' === p.source ) {
					v = '{' + ( p.value || '\u2026' ) + '}' + ( p.required ? '' : '?' );
				}
				return p.name + '=' + v;
			} );

			const base = tpl.base_url || 'https://\u2026';
			if ( ! pairs.length ) {
				this.$preview.text( base );
				return;
			}

			const sep = -1 === base.indexOf( '?' ) ? '?' : ( /[?&]$/.test( base ) ? '' : '&' );
			this.$preview.text( base + sep + pairs.join( '&' ) );
		},

		// ------------------------------------------------------------------
		// Meta-key picker
		// ------------------------------------------------------------------

		fetchKeys( $row ) {
			const term = $row.find( '.wpam-urlb-value' ).val().trim();

			if ( Object.prototype.hasOwnProperty.call( this._suggestCache, term ) ) {
				this.showSuggest( $row, this._suggestCache[ term ] );
				return;
			}

			const seq = ++this._suggestSeq;

			$.post(
				data.ajaxUrl,
				{ action: 'wpam_search_meta_keys', nonce: data.crudNonce, search: term },
				( res ) => {
					const keys = ( res && res.success && res.data && Array.isArray( res.data.keys ) ) ? res.data.keys : [];
					this._suggestCache[ term ] = keys;
					// A newer search superseded this one: keep the cache, skip the render.
					if ( seq === this._suggestSeq ) { this.showSuggest( $row, keys ); }
				}
			);
		},

		showSuggest( $row, keys ) {
			const $input = $row.find( '.wpam-urlb-value' );
			if ( document.activeElement !== $input.get( 0 ) ) { return; } // user already left the field

			const $list = $row.find( '.wpam-urlb-suggest' ).empty();

			if ( ! keys.length ) {
				$list.append( $( '<li class="wpam-urlb-suggest-empty">' ).text( ( data.i18n && data.i18n.urlb_no_keys ) || '' ) );
			} else {
				keys.forEach( ( key ) => {
					$list.append( $( '<li role="option">' ).attr( 'data-key', key ).text( key ) );
				} );
			}

			$list.prop( 'hidden', false );
		},

		hideSuggest( $row ) {
			$row.find( '.wpam-urlb-suggest' ).prop( 'hidden', true );
		},

		pick( $li ) {
			const $row = $li.closest( '.wpam-urlb-row' );
			$row.find( '.wpam-urlb-value' ).val( $li.attr( 'data-key' ) );
			this.hideSuggest( $row );
			this.refreshPreview();
		},

		onSuggestKey( e ) {
			const $row  = $( e.currentTarget ).closest( '.wpam-urlb-row' );
			const $list = $row.find( '.wpam-urlb-suggest' );
			if ( $list.prop( 'hidden' ) ) { return; }

			if ( 'Escape' === e.key ) {
				e.stopPropagation(); // close the list, not the modal
				this.hideSuggest( $row );
				return;
			}

			const $items = $list.children( 'li[data-key]' );
			const $cur   = $items.filter( '.is-active' );
			if ( ! $items.length ) { return; }

			if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
				e.preventDefault();
				let i = $items.index( $cur ); // -1 when nothing is highlighted
				i = 'ArrowDown' === e.key ? ( i + 1 ) % $items.length : ( i <= 0 ? $items.length - 1 : i - 1 );
				$items.removeClass( 'is-active' ).eq( i ).addClass( 'is-active' ).get( 0 ).scrollIntoView( { block: 'nearest' } );
			} else if ( 'Enter' === e.key && $cur.length ) {
				e.preventDefault();
				this.pick( $cur );
			}
		},
	};

	// =========================================================================
	// Módulo original (sin cambios relevantes)
	// =========================================================================

	const WPAM_Admin = {

		init() {
			this.bindDeleteConfirm();
			this.bindMediaUpload();
			this.bindColorSync();
			this.bindToggleStatusText();
			this.initNotices();
		},

		bindDeleteConfirm() {
			$( document ).on( 'click', '.wpam-action-btn--delete', function ( e ) {
				const message = $( this ).data( 'confirm' ) || data.i18n.confirm_delete;
				if ( ! window.confirm( message ) ) {
					e.preventDefault();
				}
			} );
		},

		bindMediaUpload() {
			$( document ).on( 'click', '.wpam-media-upload-btn', function ( e ) {
				e.preventDefault();

				const $btn     = $( this );
				const $input   = $( $btn.data( 'target' ) );
				const $preview = $btn.closest( '.wpam-logo-field' ).find( '.wpam-logo-preview' );

				if ( WPAM_Admin._mediaFrame ) {
					WPAM_Admin._mediaFrame.open();
					return;
				}

				WPAM_Admin._mediaFrame = wp.media( {
					title:    'Select Affiliate Logo',
					button:   { text: 'Use this image' },
					multiple: false,
					library:  { type: 'image' },
				} );

				WPAM_Admin._mediaFrame.on( 'select', function () {
					const attachment = WPAM_Admin._mediaFrame.state().get( 'selection' ).first().toJSON();
					const url        = attachment.url;

					$input.val( url );

					if ( $preview.length ) {
						$preview.find( 'img' ).remove();
						$preview.append( $( '<img>' ).attr( 'src', url ).attr( 'alt', 'Logo preview' ) );
					} else {
						$btn.closest( '.wpam-logo-field' ).prepend(
							$( '<div class="wpam-logo-preview">' ).append(
								$( '<img>' ).attr( 'src', url ).attr( 'alt', 'Logo preview' )
							)
						);
					}
				} );

				WPAM_Admin._mediaFrame.open();
			} );
		},

		bindColorSync() {
			$( document ).on( 'input change', '#wpam_brand_color', function () {
				$( '#wpam_brand_color_text' ).val( $( this ).val() );
			} );
		},

		bindToggleStatusText() {
			$( document ).on( 'change', '#wpam_active', function () {
				const $text = $( this ).closest( '.wpam-toggle-label' ).find( '.wpam-toggle-text' );
				$text.text( this.checked ? data.i18n.active : data.i18n.inactive );
			} );
		},

		initNotices() {
			setTimeout( function () {
				$( '.wpam-notice.notice-success' ).fadeOut( 400 );
			}, 4000 );
		},

		ajax( action, payload, callback ) {
			$.post(
				data.ajaxUrl,
				Object.assign( { action: 'wpam_' + action, nonce: data.nonce }, payload ),
				function ( response ) {
					if ( response.success ) {
						callback( null, response.data );
					} else {
						callback( response.data || data.i18n.error_generic, null );
					}
				}
			).fail( function () {
				callback( data.i18n.error_generic, null );
			} );
		},

		_mediaFrame: null,
	};

	$( function () {
		WPAM_Admin.init();
		InlineCRUD.init();
		UrlBuilder.init();
	} );

	window.WPAM_Admin   = WPAM_Admin;
	window.WPAM_InlineCRUD = InlineCRUD;

} )( jQuery, window.wpamAdminData || {
	crudNonce: '',
	i18n: {
		confirm_delete: 'Delete this affiliate permanently?',
		error_generic:  'An error occurred.',
		active:         'Active',
		inactive:       'Inactive',
		saving:         'Saving...',
		saved:          'Saved!',
		cancel:         'Cancel',
	},
} );
