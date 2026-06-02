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
