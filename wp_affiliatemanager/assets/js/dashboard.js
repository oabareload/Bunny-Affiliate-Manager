/**
 * Dashboard Analytics Filter — v0.2.8
 *
 * Makes the four click-stat cards act as time-range filters.
 * On card click: updates Top Affiliates + Top Posts via AJAX.
 * Persists the selected filter in localStorage.
 *
 * @package WP_AffiliateManager
 * @since   0.2.8
 */

( function ( $ ) {
	'use strict';

	var STORAGE_KEY = 'wpam_dashboard_filter';
	var $cards, $affiliatesCol, $postsCol;

	/**
	 * Apply a filter: highlight the active card and fetch updated sections.
	 *
	 * @param {string} range  'today' | 'week' | 'month' | 'total'
	 * @param {boolean} save  Whether to persist to localStorage.
	 */
	function applyFilter( range, save ) {
		// Visual: toggle active/inactive on cards.
		$cards.each( function () {
			var $c = $( this );
			if ( $c.data( 'range' ) === range ) {
				$c.removeClass( 'wpam-stat-card--inactive' ).addClass( 'wpam-stat-card--active' );
			} else {
				$c.removeClass( 'wpam-stat-card--active' ).addClass( 'wpam-stat-card--inactive' );
			}
		} );

		if ( save ) {
			try { localStorage.setItem( STORAGE_KEY, range ); } catch ( e ) {}
		}

		// Show loading state.
		$affiliatesCol.html( '<p class="wpam-analytics-empty wpam-loading">' + wpamDashboard.i18n.loading + '</p>' );
		$postsCol.html( '<p class="wpam-analytics-empty wpam-loading">' + wpamDashboard.i18n.loading + '</p>' );

		$.ajax( {
			url:  wpamDashboard.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wpam_dashboard_filter',
				nonce:  wpamDashboard.nonce,
				range:  range,
			},
			success: function ( response ) {
				if ( response.success ) {
					$affiliatesCol.html( response.data.affiliates_html );
					$postsCol.html( response.data.posts_html );
				} else {
					$affiliatesCol.html( '<p class="wpam-analytics-empty">' + wpamDashboard.i18n.error + '</p>' );
					$postsCol.html( '' );
				}
			},
			error: function () {
				$affiliatesCol.html( '<p class="wpam-analytics-empty">' + wpamDashboard.i18n.error + '</p>' );
				$postsCol.html( '' );
			},
		} );
	}

	$( function () {
		$cards        = $( '.wpam-stats-grid--clicks .wpam-stat-card' );
		$affiliatesCol = $( '.wpam-filter-affiliates-col' );
		$postsCol      = $( '.wpam-filter-posts-col' );

		if ( ! $cards.length || ! $affiliatesCol.length || ! $postsCol.length ) {
			return; // Not on dashboard, do nothing.
		}

		// Assign data-range attributes in DOM order: today, week, month, total.
		var ranges = [ 'today', 'week', 'month', 'total' ];
		$cards.each( function ( i ) {
			$( this ).data( 'range', ranges[ i ] ).css( 'cursor', 'pointer' );
		} );

		// Card click handler.
		$cards.on( 'click', function () {
			applyFilter( $( this ).data( 'range' ), true );
		} );

		// Restore persisted filter or default to 'total'.
		var saved = 'total';
		try { saved = localStorage.getItem( STORAGE_KEY ) || 'total'; } catch ( e ) {}
		if ( ranges.indexOf( saved ) === -1 ) { saved = 'total'; }

		applyFilter( saved, false );
	} );

} )( jQuery );
