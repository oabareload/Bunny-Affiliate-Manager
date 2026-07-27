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
			var collectionScore = payload.historical.collection_score !== null ? payload.historical.collection_score : payload.historical.selected_tags_avg;
			var siteAverage = payload.site && payload.site.avg !== null ? payload.site.avg.toFixed( 2 ) : ( data.i18n && data.i18n.not_available ? data.i18n.not_available : 'Not available' );
			var bunnyScore = payload.final.bunny_score !== null ? payload.final.bunny_score.toFixed( 2 ) : ( data.i18n && data.i18n.not_available ? data.i18n.not_available : 'Not available' );
			var diffToSite = payload.final.diff_vs_global;
			var diffIcon = '🟡';
			var diffText = ( data.i18n && data.i18n.not_available ) ? data.i18n.not_available : 'N/A';
			var diffClass = 'wpam-top-pct';
			if ( diffToSite !== null ) {
				if ( diffToSite > 0 ) {
					diffIcon = '🟢';
					diffText = '+' + diffToSite.toFixed( 2 );
				} else if ( diffToSite < 0 ) {
					diffIcon = '🔴';
					diffText = diffToSite.toFixed( 2 );
				} else {
					diffIcon = '🟡';
					diffText = diffToSite.toFixed( 2 );
				}
			}

			var html = '<div class="wpam-bunny-score-report">';

			html += '<section class="wpam-analytics-card">';
			html += '<h4 class="wpam-analytics-card-title">🐰 ' + ( data.i18n && data.i18n.final_bunny_score ? data.i18n.final_bunny_score : 'Bunny Score obtenido' ) + '</h4>';
			html += '<div class="wpam-stat-value">' + bunnyScore + '</div>';
			html += '<div class="wpam-top-list" style="margin-top:18px; gap:12px; display:grid;">';
			html += '<div><strong>🌎 ' + ( data.i18n && data.i18n.site_global_avg ? data.i18n.site_global_avg : 'Promedio del sitio' ) + '</strong><br>' + siteAverage + '</div>';
			html += '<div><strong>📊 ' + ( data.i18n && data.i18n.diff_vs_global ? data.i18n.diff_vs_global : 'Diferencia vs sitio' ) + '</strong><br><span class="' + diffClass + '">' + diffIcon + ' ' + diffText + '</span></div>';
			html += '</div>';
			html += '</section>';

			html += '<section class="wpam-analytics-card">';
			html += '<h4 class="wpam-analytics-card-title">📈 Modelos de cálculo</h4>';
			html += '<div class="wpam-quick-access-grid">';
			html += '<div class="wpam-quick-access-card">';
			html += '<span class="wpam-quick-access-icon">📦</span>';
			html += '<div class="wpam-quick-access-label">Collection Score</div>';
			html += '<div class="wpam-stat-value">' + ( collectionScore !== null ? collectionScore.toFixed( 2 ) : ( data.i18n && data.i18n.not_available ? data.i18n.not_available : 'N/A' ) ) + '</div>';
			html += '<div class="wpam-top-pct">vs global ' + ( payload.final.diff_collection_vs_global !== null ? ( payload.final.diff_collection_vs_global >= 0 ? '+' : '' ) + payload.final.diff_collection_vs_global.toFixed( 2 ) : ( data.i18n && data.i18n.not_available ? data.i18n.not_available : 'N/A' ) ) + '</div>';
			html += '</div>';
			html += '<div class="wpam-quick-access-card">';
			html += '<span class="wpam-quick-access-icon">⚖️</span>';
			html += '<div class="wpam-quick-access-label">Weighted Tag Score</div>';
			html += '<div class="wpam-stat-value">' + ( payload.historical.weighted_tag_score !== null ? payload.historical.weighted_tag_score.toFixed( 2 ) : ( data.i18n && data.i18n.not_available ? data.i18n.not_available : 'N/A' ) ) + '</div>';
			html += '<div class="wpam-top-pct">vs global ' + ( payload.final.diff_weighted_vs_global !== null ? ( payload.final.diff_weighted_vs_global >= 0 ? '+' : '' ) + payload.final.diff_weighted_vs_global.toFixed( 2 ) : ( data.i18n && data.i18n.not_available ? data.i18n.not_available : 'N/A' ) ) + '</div>';
			html += '</div>';
			html += '<div class="wpam-quick-access-card">';
			html += '<span class="wpam-quick-access-icon">🧮</span>';
			html += '<div class="wpam-quick-access-label">Log Weighted Tag Score</div>';
			html += '<div class="wpam-stat-value">' + ( payload.historical.log_weighted_tag_score !== null ? payload.historical.log_weighted_tag_score.toFixed( 2 ) : ( data.i18n && data.i18n.not_available ? data.i18n.not_available : 'N/A' ) ) + '</div>';
			html += '<div class="wpam-top-pct">vs global ' + ( payload.final.diff_log_vs_global !== null ? ( payload.final.diff_log_vs_global >= 0 ? '+' : '' ) + payload.final.diff_log_vs_global.toFixed( 2 ) : ( data.i18n && data.i18n.not_available ? data.i18n.not_available : 'N/A' ) ) + '</div>';
			html += '</div>';
			html += '</div>';
			html += '</section>';

			html += '<section class="wpam-analytics-card">';
			html += '<h4 class="wpam-analytics-card-title">📝 Datos utilizados</h4>';
			html += '<div class="wpam-quick-access-grid">';
			html += '<div class="wpam-quick-access-card">';
			html += '<span class="wpam-quick-access-icon">📝</span>';
			html += '<div class="wpam-quick-access-label">Publicaciones analizadas</div>';
			html += '<div class="wpam-stat-value">' + ( payload.historical.total_posts || 0 ) + '</div>';
			html += '<div class="wpam-top-pct">' + ( payload.historical.total_posts === 1 ? '1 post histórico' : ( payload.historical.total_posts || 0 ) + ' posts históricos' ) + '</div>';
			html += '</div>';
			html += '</div>';
			html += '</section>';

			html += '<section class="wpam-analytics-card">';
			html += '<h4 class="wpam-analytics-card-title">🎯 Factores manuales</h4>';
			if ( payload.factors.per_factor && Object.keys( payload.factors.per_factor ).length ) {
				html += '<ul class="wpam-top-list">';
				Object.keys( payload.factors.per_factor ).forEach( function ( key ) {
					var factor = payload.factors.per_factor[ key ];
					var status = 'No aplicado';
					if ( factor.percent !== null && typeof factor.percent !== 'undefined' ) {
						status = factor.percent === 0 ? 'No aplicado' : ( factor.percent > 0 ? '+' : '' ) + factor.percent.toFixed( 2 ) + '%';
					}
					html += '<li class="wpam-top-item">';
					html += '<div class="wpam-top-item-lead">';
					html += '<span class="wpam-top-thumb-placeholder">⚪</span>';
					html += '<div>'; 
					html += '<strong>' + ( factor.config && factor.config.label ? factor.config.label : key ) + '</strong><br>';
					html += '<span class="wpam-top-pct">' + status + '</span>';
					html += '</div>';
					html += '</div>';
					html += '</li>';
				} );
				html += '</ul>';
			} else {
				html += '<p class="wpam-analytics-empty">' + ( data.i18n && data.i18n.no_manual_factors ? data.i18n.no_manual_factors : 'No hay factores manuales aplicados.' ) + '</p>';
			}
			html += '</section>';

			html += '<section class="wpam-analytics-card">';
			html += '<h4 class="wpam-analytics-card-title">🏷️ Rendimiento por TAG</h4>';
			if ( payload.historical.per_tag && payload.historical.per_tag.length ) {
				html += '<table class="widefat fixed striped">';
				html += '<thead><tr>';
				html += '<th>TAG</th>';
				html += '<th>Posts</th>';
				html += '<th>Score promedio</th>';
				html += '<th>Peso sqrt</th>';
				html += '<th>Peso log</th>';
				html += '<th>Aporte</th>';
				html += '</tr></thead><tbody>';
				payload.historical.per_tag.forEach( function ( term ) {
					var avgScore = term.avg_score !== null ? term.avg_score.toFixed( 2 ) : '—';
					var weightSqrt = term.weight_sqrt !== null ? term.weight_sqrt.toFixed( 2 ) : '—';
					var weightLog = term.weight_log !== null ? term.weight_log.toFixed( 2 ) : '—';
					var contribution = term.contribution_log !== null ? term.contribution_log.toFixed( 2 ) : '—';
					html += '<tr>';
					html += '<td><strong>' + ( term.name || ( '#' + term.term_id ) ) + '</strong></td>';
					html += '<td>' + ( term.count || 0 ) + '</td>';
					html += '<td>' + avgScore + '</td>';
					html += '<td>' + weightSqrt + '</td>';
					html += '<td>' + weightLog + '</td>';
					html += '<td>' + contribution + '</td>';
					html += '</tr>';
				} );
				html += '</tbody></table>';
			} else {
				html += '<p class="wpam-analytics-empty">' + ( data.i18n && data.i18n.no_tag_data ? data.i18n.no_tag_data : 'No hay datos por tag disponibles.' ) + '</p>';
			}
			html += '</section>';

			html += '</div>';
			$result.html( html );
		}, 'json' ).fail( function () {
			$button.prop( 'disabled', false );
			$result.empty();
			$error.text( ( data.i18n && data.i18n.error_generic ) ? data.i18n.error_generic : 'An error occurred.' );
		} );
	} );

} )( jQuery );
