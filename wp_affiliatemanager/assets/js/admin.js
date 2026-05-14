/**
 * Bunny Affiliate Manager — Admin Scripts
 *
 * @package WP_AffiliateManager
 * @since   1.0.0 (ampliado en 2.0.0)
 */

/* global wpamAdminData, jQuery, wp */

( function ( $, data ) {
	'use strict';

	const WPAM_Admin = {

		init() {
			this.bindDeleteConfirm();
			this.bindMediaUpload();
			this.bindColorSync();
			this.bindToggleStatusText();
			this.initNotices();
		},

		/**
		 * Confirmación antes de eliminar un afiliado.
		 */
		bindDeleteConfirm() {
			$( document ).on( 'click', '.wpam-action-btn--delete', function ( e ) {
				const message = $( this ).data( 'confirm' ) || data.i18n.confirm_delete;
				if ( ! window.confirm( message ) ) {
					e.preventDefault();
				}
			} );
		},

		/**
		 * Media Uploader de WordPress para el campo de logo.
		 * Solo disponible en las páginas del CPT (wp-admin/post.php).
		 */
		bindMediaUpload() {
			$( document ).on( 'click', '.wpam-media-upload-btn', function ( e ) {
				e.preventDefault();

				const $btn        = $( this );
				const targetInput = $btn.data( 'target' );
				const $input      = $( targetInput );
				const $preview    = $btn.closest( '.wpam-logo-field' ).find( '.wpam-logo-preview' );

				// Crear o reutilizar el frame de medios.
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

					// Actualizar preview.
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

		/**
		 * Sincroniza el color picker con el campo de texto hex.
		 */
		bindColorSync() {
			$( document ).on( 'input change', '#wpam_brand_color', function () {
				$( '#wpam_brand_color_text' ).val( $( this ).val() );
			} );
		},

		/**
		 * Actualiza el texto del toggle de status en tiempo real.
		 */
		bindToggleStatusText() {
			$( document ).on( 'change', '#wpam_active', function () {
				const $text = $( this ).closest( '.wpam-toggle-label' ).find( '.wpam-toggle-text' );
				$text.text( this.checked ? data.i18n.active : data.i18n.inactive );
			} );
		},

		/**
		 * Auto-oculta los notices de éxito/error del plugin.
		 */
		initNotices() {
			setTimeout( function () {
				$( '.wpam-notice.notice-success' ).fadeOut( 400 );
			}, 4000 );
		},

		/**
		 * Helper AJAX genérico (preparado para FASE 3).
		 */
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
	} );

	window.WPAM_Admin = WPAM_Admin;

} )( jQuery, window.wpamAdminData || {
	i18n: {
		confirm_delete: 'Are you sure?',
		error_generic:  'An error occurred.',
		active:         'Active',
		inactive:       'Inactive',
	},
} );
