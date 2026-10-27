( function ( $ ) {
	'use strict';

	var data = window.wpamAgeGateSelectors || {};
	var i18n = data.i18n || {};

	function addTerm( $picker, term ) {
		var termId = String( term.id );
		var $chips = $picker.find( '.wpam-age-gate-chips' );

		if ( $chips.find( '.wpam-age-gate-chip' ).filter( function () {
			return String( $( this ).data( 'termId' ) ) === termId;
		} ).length ) {
			return;
		}

		var $chip = $( '<span />', {
			'class': 'wpam-age-gate-chip',
			'data-term-id': termId
		} );
		var $name = $( '<span />', { 'class': 'wpam-age-gate-chip-name' } ).text( term.name );
		var removeLabel = ( i18n.remove || 'Remove %s' ).replace( '%s', term.name );
		var $remove = $( '<button />', {
			type: 'button',
			'class': 'wpam-age-gate-chip-remove'
		} ).attr( 'aria-label', removeLabel ).html( '&times;' );
		var $hidden = $( '<input />', {
			type: 'hidden',
			name: $picker.data( 'fieldName' ),
			value: termId
		} );

		$chip.append( $name, $hidden, $remove );
		$chips.append( $chip );
	}

	$( function () {
		$( '.wpam-age-gate-picker' ).each( function () {
			var $picker = $( this );
			var $input = $picker.find( '.wpam-age-gate-search' );
			var $status = $picker.find( '.wpam-age-gate-search-status' );

			$input.autocomplete( {
				minLength: 2,
				delay: 250,
				open: function () {
					$input.autocomplete( 'widget' ).addClass( 'wpam-age-gate-suggestions' );
				},
				source: function ( request, respond ) {
					$status.text( '' );
					$.getJSON( data.ajaxUrl || window.ajaxurl, {
						action: 'wpam_search_protection_terms',
						nonce: data.nonce,
						taxonomy: $picker.data( 'taxonomy' ),
						term: request.term
					} ).done( function ( result ) {
						if ( ! result || ! result.success || ! Array.isArray( result.data ) ) {
							$status.text( ( result && result.data && result.data.message ) || i18n.error || 'Search failed.' );
							respond( [] );
							return;
						}

						var selected = {};
						$picker.find( '.wpam-age-gate-chip' ).each( function () {
							selected[ String( $( this ).data( 'termId' ) ) ] = true;
						} );

						var matches = result.data.filter( function ( term ) {
							return term && ! selected[ String( term.id ) ] && typeof term.name === 'string';
						} ).map( function ( term ) {
							return { id: term.id, label: term.name, value: term.name };
						} );

						if ( ! matches.length ) {
							$status.text( i18n.noResults || 'No terms found.' );
						}
						respond( matches );
					} ).fail( function () {
						$status.text( i18n.error || 'Search failed.' );
						respond( [] );
					} );
				},
				select: function ( event, ui ) {
					event.preventDefault();
					addTerm( $picker, { id: ui.item.id, name: ui.item.label } );
					$input.val( '' );
					$status.text( '' );
					return false;
				}
			} );

			$picker.on( 'click', '.wpam-age-gate-chip-remove', function () {
				$( this ).closest( '.wpam-age-gate-chip' ).remove();
			} );
		} );
	} );
} )( jQuery );
