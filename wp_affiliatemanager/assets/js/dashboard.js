/**
 * Dashboard Analytics Filter — v0.2.8
 *
 * Makes stat cards act as time-range filters. Generalizado en v1.3.0 para
 * soportar múltiples grupos de filtro (clicks + views) con una sola
 * implementación (initFilterGroup), sin duplicar AJAX/loading-state code.
 *
 * @package WP_AffiliateManager
 * @since   0.2.8
 * @since  1.2.0 Refactor a initFilterGroup() reutilizable, segundo grupo para Views.
 */

( function ( $ ) {
	'use strict';

	var RANGES = [ 'today', 'week', 'month', 'total' ];

	/**
	 * Inicializa un grupo de cards-filtro.
	 *
	 * @param {Object} config
	 * @param {string} config.cardsSelector Selector de las 4 cards del grupo.
	 * @param {Array}  config.columns       [{ selector, dataKey }] columnas a reemplazar via AJAX.
	 * @param {string} config.ajaxAction    Acción AJAX ('wpam_dashboard_filter' para ambos grupos).
	 * @param {string} config.storageKey    Clave de localStorage para persistir el filtro.
	 * @param {Object} [config.extraData]   Datos POST adicionales (p.ej. { source: 'views' }).
	 */
	function initFilterGroup( config ) {
		var $cards  = $( config.cardsSelector );
		var $cols   = {};
		var missing = false;

		config.columns.forEach( function ( col ) {
			var $col = $( col.selector );
			if ( ! $col.length ) { missing = true; }
			$cols[ col.dataKey ] = $col;
		} );

		if ( ! $cards.length || missing ) {
			return; // Grupo no presente en esta pantalla.
		}

		$cards.each( function ( i ) {
			$( this ).data( 'range', RANGES[ i ] ).css( 'cursor', 'pointer' );
		} );

		function applyFilter( range, save ) {
			$cards.each( function () {
				var $c = $( this );
				if ( $c.data( 'range' ) === range ) {
					$c.removeClass( 'wpam-stat-card--inactive' ).addClass( 'wpam-stat-card--active' );
				} else {
					$c.removeClass( 'wpam-stat-card--active' ).addClass( 'wpam-stat-card--inactive' );
				}
			} );

			if ( save ) {
				try { localStorage.setItem( config.storageKey, range ); } catch ( e ) {}
			}

			config.columns.forEach( function ( col ) {
				$cols[ col.dataKey ].html( '<p class="wpam-analytics-empty wpam-loading">' + wpamDashboard.i18n.loading + '</p>' );
			} );

			$.ajax( {
				url:  wpamDashboard.ajaxUrl,
				type: 'POST',
				data: $.extend(
					{ action: config.ajaxAction, nonce: wpamDashboard.nonce, range: range },
					config.extraData || {}
				),
				success: function ( response ) {
					if ( response.success ) {
						config.columns.forEach( function ( col ) {
							$cols[ col.dataKey ].html( response.data[ col.dataKey ] || '' );
						} );
					} else {
						config.columns.forEach( function ( col ) {
							$cols[ col.dataKey ].html( '<p class="wpam-analytics-empty">' + wpamDashboard.i18n.error + '</p>' );
						} );
					}
				},
				error: function () {
					config.columns.forEach( function ( col ) {
						$cols[ col.dataKey ].html( '<p class="wpam-analytics-empty">' + wpamDashboard.i18n.error + '</p>' );
					} );
				},
			} );
		}

		$cards.on( 'click', function () {
			applyFilter( $( this ).data( 'range' ), true );
		} );

		var saved = 'total';
		try { saved = localStorage.getItem( config.storageKey ) || 'total'; } catch ( e ) {}
		if ( RANGES.indexOf( saved ) === -1 ) { saved = 'total'; }

		applyFilter( saved, false );
	}

	$( function () {
		// Grupo Clicks (existente).
		initFilterGroup( {
			cardsSelector: '.wpam-stats-grid--clicks .wpam-stat-card',
			columns: [
				{ selector: '.wpam-filter-affiliates-col', dataKey: 'affiliates_html' },
				{ selector: '.wpam-filter-posts-col',      dataKey: 'posts_html' },
			],
			ajaxAction: 'wpam_dashboard_filter',
			storageKey: 'wpam_dashboard_filter',
		} );

		// Grupo Views (v1.3.0) — mismo initFilterGroup(), sin JS duplicado.
		initFilterGroup( {
			cardsSelector: '.wpam-stats-grid--views .wpam-stat-card',
			columns: [
				{ selector: '.wpam-filter-top-viewed-col', dataKey: 'posts_html' },
			],
			ajaxAction: 'wpam_dashboard_filter',
			storageKey: 'wpam_views_dashboard_filter',
			extraData: { source: 'views' },
		} );
	} );

} )( jQuery );
