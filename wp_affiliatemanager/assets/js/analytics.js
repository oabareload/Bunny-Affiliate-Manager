/**
 * Analytics Filter + Tabs — v1.4.0
 *
 * Reemplaza a dashboard.js (el Dashboard ya no tiene contenido filtrable).
 * Reutiliza exactamente la misma initFilterGroup() de dashboard.js, sin
 * tocar su lógica interna — Score, Clicks y Views comparten un único
 * sistema de filtros, diferenciados solo por `source`.
 *
 * @package WP_AffiliateManager
 * @since   1.4.0
 */

( function ( $ ) {
	'use strict';

	var RANGES = [ 'today', 'week', 'month', 'total' ];

	/**
	 * Inicializa un grupo de cards-filtro. Idéntica a la función de
	 * dashboard.js — sin cambios de comportamiento.
	 *
	 * @param {Object} config
	 * @param {string} config.cardsSelector Selector de las 4 cards del grupo.
	 * @param {Array}  config.columns       [{ selector, dataKey }] columnas a reemplazar via AJAX.
	 * @param {string} config.ajaxAction    Acción AJAX ('wpam_analytics_filter' para los 3 grupos).
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

		// v1.8.0: selector opcional adicional (usado por Views para
		// resource_type). Cuando cambia, se re-aplica el filtro con el mismo
		// range activo pero extraData actualizado, y además refresca los 4
		// números de las stat cards (antes de v1.8.0 esos números eran fijos
		// para toda la vida del tab — con resource_type variable, cada tipo
		// tiene sus propios totales).
		var $extraSelect = config.extraSelector ? $( config.extraSelector ) : $();

		$cards.each( function ( i ) {
			$( this ).data( 'range', RANGES[ i ] ).css( 'cursor', 'pointer' );
		} );

		function updateStatCards( stats ) {
			if ( ! stats ) { return; }
			$cards.each( function () {
				var $c     = $( this );
				var range  = $c.data( 'range' );
				if ( Object.prototype.hasOwnProperty.call( stats, range ) ) {
					$c.find( '.wpam-stat-value' ).text( stats[ range ] );
				}
			} );
		}

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
				$cols[ col.dataKey ].html( '<p class="wpam-analytics-empty wpam-loading">' + wpamAnalytics.i18n.loading + '</p>' );
			} );

			var extraData = $.extend( {}, config.extraData || {} );
			if ( $extraSelect.length ) {
				extraData.resource_type = $extraSelect.val();
			}

			$.ajax( {
				url:  wpamAnalytics.ajaxUrl,
				type: 'POST',
				data: $.extend(
					{ action: config.ajaxAction, nonce: wpamAnalytics.nonce, range: range },
					extraData
				),
				success: function ( response ) {
					if ( response.success ) {
						config.columns.forEach( function ( col ) {
							$cols[ col.dataKey ].html( response.data[ col.dataKey ] || '' );
						} );
						updateStatCards( response.data.stats );
					} else {
						config.columns.forEach( function ( col ) {
							$cols[ col.dataKey ].html( '<p class="wpam-analytics-empty">' + wpamAnalytics.i18n.error + '</p>' );
						} );
					}
				},
				error: function () {
					config.columns.forEach( function ( col ) {
						$cols[ col.dataKey ].html( '<p class="wpam-analytics-empty">' + wpamAnalytics.i18n.error + '</p>' );
					} );
				},
			} );
		}

		$cards.on( 'click', function () {
			applyFilter( $( this ).data( 'range' ), true );
		} );

		if ( $extraSelect.length ) {
			$extraSelect.on( 'change', function () {
				var current = $cards.filter( '.wpam-stat-card--active' ).data( 'range' ) || 'total';
				applyFilter( current, false );
			} );
		}

		var saved = 'total';
		try { saved = localStorage.getItem( config.storageKey ) || 'total'; } catch ( e ) {}
		if ( RANGES.indexOf( saved ) === -1 ) { saved = 'total'; }

		applyFilter( saved, false );
	}

	/**
	 * Tabs horizontales — solo show/hide entre paneles ya renderizados
	 * server-side. Sin AJAX propio: el filtro de cada tab ya trae sus datos.
	 */
	function initTabs() {
		var $tabs   = $( '.wpam-tab-item' );
		var $panels = $( '.wpam-tab-panel' );
		var storageKey = 'wpam_analytics_active_tab';

		if ( ! $tabs.length || ! $panels.length ) {
			return;
		}

		function activateTab( tab, save ) {
			$tabs.each( function () {
				var $t = $( this );
				var isActive = $t.data( 'tab' ) === tab;
				$t.toggleClass( 'wpam-tab-item--active', isActive ).attr( 'aria-selected', isActive ? 'true' : 'false' );
			} );

			$panels.each( function () {
				var $p = $( this );
				var isActive = $p.data( 'tab-panel' ) === tab;
				$p.toggleClass( 'wpam-tab-panel--active', isActive );
				$p.css( 'display', isActive ? '' : 'none' );
			} );

			if ( save ) {
				try { localStorage.setItem( storageKey, tab ); } catch ( e ) {}
			}
		}

		$tabs.on( 'click', function () {
			activateTab( $( this ).data( 'tab' ), true );
		} );

		var saved   = '';
		var validTabs = $tabs.map( function () { return $( this ).data( 'tab' ); } ).get();
		try { saved = localStorage.getItem( storageKey ) || ''; } catch ( e ) {}

		if ( saved && validTabs.indexOf( saved ) !== -1 ) {
			activateTab( saved, false );
		}
	}

	$( function () {
		initTabs();

		// Tab Score.
		initFilterGroup( {
			cardsSelector: '.wpam-analytics-cards--score .wpam-stat-card',
			columns: [
				{ selector: '.wpam-analytics-scored-posts-col', dataKey: 'scored_posts_html' },
			],
			ajaxAction: 'wpam_analytics_filter',
			storageKey: 'wpam_analytics_filter_score',
			extraData: { source: 'score' },
		} );

		// Tab Clicks.
		initFilterGroup( {
			cardsSelector: '.wpam-analytics-cards--clicks .wpam-stat-card',
			columns: [
				{ selector: '.wpam-analytics-affiliates-col',    dataKey: 'affiliates_html' },
				{ selector: '.wpam-analytics-clicked-posts-col', dataKey: 'clicked_posts_html' },
			],
			ajaxAction: 'wpam_analytics_filter',
			storageKey: 'wpam_analytics_filter_clicks',
			extraData: { source: 'clicks' },
		} );

		// Tab Views.
		initFilterGroup( {
			cardsSelector: '.wpam-analytics-cards--views .wpam-stat-card',
			columns: [
				{ selector: '.wpam-analytics-viewed-posts-col', dataKey: 'viewed_posts_html' },
			],
			ajaxAction: 'wpam_analytics_filter',
			storageKey: 'wpam_analytics_filter_views',
			extraData: { source: 'views' },
			extraSelector: '#wpam-views-resource-type',
		} );
	} );

} )( jQuery );
