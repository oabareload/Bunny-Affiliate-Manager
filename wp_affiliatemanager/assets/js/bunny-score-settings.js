( function ( $ ) {
	'use strict';

	var i18nNewFactorLabel = ( window.wpamAdminData && window.wpamAdminData.i18n && window.wpamAdminData.i18n.factor_label_placeholder ) || 'Nombre del factor';

	/**
	 * Genera un slug tipo `sanitize_key()` de WP a partir de la etiqueta
	 * (minusculas, solo a-z0-9_ , espacios/guiones -> guion bajo).
	 *
	 * @since 1.7.0
	 */
	function slugify( str ) {
		return String( str || '' )
			.toLowerCase()
			.trim()
			.replace( /[^a-z0-9_\s-]/g, '' )
			.replace( /[\s-]+/g, '_' )
			.replace( /^_+|_+$/g, '' );
	}

	function buildRowHtml( idx, data ) {
		data = data || {};
		var id = data.id || '';
		var label = data.label || '';
		var type = data.type || 'boolean';
		var enabled = data.enabled ? 'checked' : '';
		var optional = data.optional ? 'checked' : '';
		var max_percent = data.max_percent || '';
		var scale_min = data.scale_min || '';
		var scale_max = data.scale_max || '';
		var labels_json = data.labels_json || '';

		var html = '<tr class="wpam-bunny-factor-row" data-id-locked="0">';
		html += '<td><input type="hidden" class="wpam-factor-id" name="wpam_settings[bunny_score][factors]['+idx+'][id]" value="'+escapeHtml(id)+'" />' +
			'<input type="text" name="wpam_settings[bunny_score][factors]['+idx+'][label]" value="'+escapeHtml(label)+'" class="regular-text wpam-factor-label-input" placeholder="'+escapeHtml(i18nNewFactorLabel)+'" /></td>';
		html += '<td>' +
			'<select name="wpam_settings[bunny_score][factors]['+idx+'][type]" class="wpam-factor-type">' +
			'<option value="boolean"'+(type==='boolean'?' selected':'')+'>Boolean</option>' +
			'<option value="numeric"'+(type==='numeric'?' selected':'')+'>Numeric</option>' +
			'<option value="label"'+(type==='label'?' selected':'')+'>Label</option>' +
			'</select>' +
			'</td>';
		html += '<td><input type="checkbox" name="wpam_settings[bunny_score][factors]['+idx+'][enabled]" value="1" '+enabled+' /></td>';
		html += '<td><input type="checkbox" name="wpam_settings[bunny_score][factors]['+idx+'][optional]" value="1" '+optional+' /></td>';
		html += '<td><input type="number" min="0" step="0.1" name="wpam_settings[bunny_score][factors]['+idx+'][max_percent]" value="'+escapeHtml(max_percent)+'" style="width:80px;" /></td>';
		html += '<td class="wpam-factor-extra">';
		html += '<div class="wpam-factor-numeric" style="display:'+(type==='numeric'?'block':'none')+'">';
		html += '<input type="text" name="wpam_settings[bunny_score][factors]['+idx+'][scale_min]" value="'+escapeHtml(scale_min)+'" style="width:70px;" placeholder="Min" />';
		html += '<input type="text" name="wpam_settings[bunny_score][factors]['+idx+'][scale_max]" value="'+escapeHtml(scale_max)+'" style="width:70px; margin-left:.5rem;" placeholder="Max" />';
		html += '</div>';
		html += '<div class="wpam-factor-label" style="display:'+(type==='label'?'block':'none')+'">';
		html += '<textarea name="wpam_settings[bunny_score][factors]['+idx+'][labels_json]" class="large-text" placeholder="{""key"": 10}">'+escapeHtml(labels_json)+'</textarea>';
		html += '<p class="description">Enter JSON object of label=>percent pairs, e.g. {"A":10,"B":5}</p>';
		html += '</div>';
		html += '</td>';
		html += '<td><button type="button" class="button wpam-remove-factor">Remove</button></td>';
		html += '</tr>';
		return html;
	}

	function escapeHtml( str ) {
		if ( str === null || typeof str === 'undefined' ) return '';
		return String( str ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	$( function () {
		var $wrap = $( '.wpam-bunny-factors-wrap' );
		if ( ! $wrap.length ) { return; }
		var $table = $wrap.find( '.wpam-bunny-factors-table' );

		$wrap.on( 'click', '.wpam-add-factor', function ( e ) {
			e.preventDefault();
			$table.find( 'tr.wpam-no-factors-row' ).remove();
			var idx = 'new_' + Date.now();
			var row = buildRowHtml( idx );
			$table.find( 'tbody' ).append( row );
		} );

		$wrap.on( 'click', '.wpam-remove-factor', function ( e ) {
			e.preventDefault();
			$( this ).closest( 'tr' ).remove();
		} );

		$wrap.on( 'change', '.wpam-factor-type', function () {
			var $sel = $( this );
			var type = $sel.val();
			var $row = $sel.closest( 'tr' );
			$row.find( '.wpam-factor-numeric' ).toggle( type === 'numeric' );
			$row.find( '.wpam-factor-label' ).toggle( type === 'label' );
		} );

		$wrap.on( 'input blur', '.wpam-factor-label-input', function () {
			var $row = $( this ).closest( 'tr' );
			if ( '1' === $row.attr( 'data-id-locked' ) ) {
				// Existing factor: never touch its id when the label is edited,
				// or any existing reference to this factor id would break.
				return;
			}
			var slug = slugify( $( this ).val() );
			$row.find( '.wpam-factor-id' ).val( slug );
		} );

		// initialize existing rows
		$table.find( 'tbody tr' ).each( function () {
			var $row = $( this );
			var type = $row.find( '.wpam-factor-type' ).val();
			$row.find( '.wpam-factor-numeric' ).toggle( type === 'numeric' );
			$row.find( '.wpam-factor-label' ).toggle( type === 'label' );
		} );
	} );

} )( jQuery );

( function ( $ ) {
	'use strict';

	var data = window.wpamAdminData || {};

	$( document ).on( 'submit', '#wpam-bunny-score-form', function ( e ) {
		e.preventDefault();

		var $form = $( e.currentTarget );
		var $result = $( '#wpam-bunny-score-result' );
		var $error = $( '#wpam-bunny-score-error' );
		var $button = $form.find( 'button[type="submit"]' );

		$button.prop( 'disabled', true );
		$error.empty();
		$result.html( '<p>' + ( ( data.i18n && data.i18n.loading ) ? data.i18n.loading : 'Loading...' ) + '</p>' );

		$.post( $form.attr( 'action' ), $form.serialize(), function ( response ) {
			$button.prop( 'disabled', false );

			if ( ! response || ! response.success ) {
				$result.empty();
				$error.text( ( response && response.data ) ? response.data : ( ( data.i18n && data.i18n.error_generic ) ? data.i18n.error_generic : 'An error occurred.' ) );
				return;
			}

			var payload = response.data;
			var html = '<div class="wpam-bunny-score-summary">';
		html += '<p><strong>' + ( ( data.i18n && data.i18n.global_avg ) ? data.i18n.global_avg : 'Historical average:' ) + '</strong> ' + ( payload.historical.global_avg !== null ? payload.historical.global_avg.toFixed( 2 ) : ( ( data.i18n && data.i18n.not_available ) ? data.i18n.not_available : 'Not available' ) ) + '</p>';
		html += '<p><strong>' + ( ( data.i18n && data.i18n.total_posts ) ? data.i18n.total_posts : 'Posts scored:' ) + '</strong> ' + ( payload.historical.total_posts || 0 ) + '</p>';
		html += '<p><strong>' + ( ( data.i18n && data.i18n.total_percent_add ) ? data.i18n.total_percent_add : 'Total bonus:' ) + '</strong> ' + ( payload.factors.total_percent_add !== null ? payload.factors.total_percent_add.toFixed( 2 ) + '%' : '0.00%' ) + '</p>';
		html += '<p><strong>' + ( ( data.i18n && data.i18n.final_bunny_score ) ? data.i18n.final_bunny_score : 'Bunny Score:' ) + '</strong> ' + ( payload.final.bunny_score !== null ? payload.final.bunny_score.toFixed( 2 ) : ( ( data.i18n && data.i18n.not_available ) ? data.i18n.not_available : 'Not available' ) ) + '</p>';

		if ( payload.factors.per_factor && Object.keys( payload.factors.per_factor ).length ) {
			html += '<h3>' + ( ( data.i18n && data.i18n.factors ) ? data.i18n.factors : 'Factors' ) + '</h3><ul>';
			Object.keys( payload.factors.per_factor ).forEach( function ( key ) {
				var factor = payload.factors.per_factor[ key ];
				html += '<li><strong>' + ( factor.config.label || key ) + ':</strong> ' + ( factor.percent !== null ? factor.percent.toFixed( 2 ) + '%' : ( ( data.i18n && data.i18n.not_applicable ) ? data.i18n.not_applicable : 'N/A' ) ) + '</li>';
			} );
			html += '</ul>';
		}

		if ( payload.historical.per_term && payload.historical.per_term.length ) {
			html += '<h3>' + ( ( data.i18n && data.i18n.terms ) ? data.i18n.terms : 'Term break-down' ) + '</h3><ul>';
			payload.historical.per_term.forEach( function ( term ) {
				html += '<li>' + term.group + ': ' + ( term.count || 0 ) + ' posts, ' + ( term.valid ? ( term.avg_score !== null ? term.avg_score.toFixed( 2 ) : ( ( data.i18n && data.i18n.not_available ) ? data.i18n.not_available : 'Not available' ) ) : ( ( data.i18n && data.i18n.skipped ) ? data.i18n.skipped : 'Skipped' ) ) + '</li>';
			} );
			html += '</ul>';
		}

		html += '</div>';
		$result.html( html );
		}, 'json' ).fail( function () {
			$button.prop( 'disabled', false );
			$result.empty();
			$error.text( ( data.i18n && data.i18n.error_generic ) ? data.i18n.error_generic : 'An error occurred.' );
		} );
	} );

} )( jQuery );
