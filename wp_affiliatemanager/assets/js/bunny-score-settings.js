( function ( $ ) {
	'use strict';

	var data = window.wpamAdminData || {};

	function escapeHtml( str ) {
		if ( str === null || typeof str === 'undefined' ) return '';
		return String( str ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	/**
	 * v1.7.5: oculta/deshabilita el input de valor de un factor cuando el
	 * estado elegido no es "Tiene valor" — evita mandar un valor irrelevante
	 * cuando el admin marcó "No aplica" o "Sin datos" (aunque el backend ya
	 * lo ignora en esos casos, ver Bunny_Score_Factors::extract_state()).
	 */
	function applyFactorStateVisibility( $row ) {
		var state = $row.find( '.wpam-factor-state' ).val();
		var $cell = $row.find( '.wpam-factor-value-cell' );
		$cell.find( 'input, select' ).prop( 'disabled', 'has_value' !== state );
		$cell.css( 'opacity', 'has_value' === state ? '1' : '.4' );
	}

	/**
	 * v1.7.6 — Selector de TAG asociado a un factor externo. Reutiliza EL
	 * MISMO backend de búsqueda que usa WordPress core en el editor de posts
	 * (`action=ajax-tag-search`, la misma fuente de datos que alimenta
	 * `tags-suggest.js`) — pero cableado directamente con jQuery UI
	 * Autocomplete en vez de `wpTagsSuggest()`, porque ese plugin está
	 * acoplado a la caja multi-tag (tagchecklist/CSV textarea) y aquí se
	 * necesita selección ÚNICA resuelta a `term_id` (nunca texto libre, nunca
	 * el nombre se guarda como valor final).
	 *
	 * Aislamiento: cada `.wpam-factor-tag-picker` (una por fila de factor) se
	 * inicializa una sola vez (`data('wpam-tag-picker-init')`) y toda su
	 * delegación de eventos está scoped a su propio contenedor — nunca a
	 * `document` — así que múltiples factores con TAG asociado nunca
	 * interfieren entre sí.
	 */
	function initFactorTagPickers() {
		$( '.wpam-factor-tag-picker' ).each( function () {
			var $picker = $( this );
			if ( $picker.data( 'wpamTagPickerInit' ) ) { return; }
			$picker.data( 'wpamTagPickerInit', true );

			var $input = $picker.find( '.wpam-factor-tag-input' );
			var $hiddenId = $picker.find( '.wpam-factor-tag-id' );
			var $chip = $picker.find( '.wpam-factor-tag-chip' );
			var $chipName = $picker.find( '.wpam-factor-tag-chip-name' );
			var $preview = $picker.find( '.wpam-factor-tag-preview' );
			var $autocompleteWrap = $picker.find( '.wpam-factor-tag-autocomplete-wrap' );
			var minPostsSelector = $picker.data( 'minPostsInput' ) || '#wpam-bunny-score-min-posts-input';

			if ( $.fn.autocomplete ) {
				$input.autocomplete( {
					minLength: 2,
					source: function ( request, response ) {
						$.get( ( data.ajaxUrl || ajaxurl ), {
							action: 'ajax-tag-search',
							tax: 'post_tag',
							q: request.term
						} ).done( function ( result ) {
							var names = ( result || '' ).split( '\n' ).filter( function ( n ) { return n.length > 0; } );
							response( names );
						} ).fail( function () { response( [] ); } );
					},
					select: function ( event, ui ) {
						event.preventDefault();
						$input.val( '' );
						selectTag( ui.item.value );
						return false;
					}
				} );
			}

			/**
			 * Resuelve el nombre elegido en el autocomplete a `term_id` vía el
			 * mismo endpoint que alimenta el preview — nunca se guarda el nombre
			 * como valor final, solo se usa para la resolución inicial.
			 */
			function selectTag( name ) {
				var minPosts = parseInt( $( minPostsSelector ).val(), 10 ) || 1;
				$preview.html( '<span class="wpam-top-pct">' + ( ( data.i18n && data.i18n.loading ) || 'Loading...' ) + '</span>' );

				$.post( data.ajaxUrl || ajaxurl, {
					action: 'wpam_get_tag_preview',
					nonce: data.crudNonce,
					term_id: 0,
					name: name,
					min_posts: minPosts
				}, function ( response ) {
					if ( ! response || ! response.success ) {
						$preview.html( '<span class="wpam-top-pct">' + ( ( response && response.data ) || 'TAG no encontrado.' ) + '</span>' );
						return;
					}
					var p = response.data;
					$hiddenId.val( p.term_id );
					$chipName.text( p.name );
					$chip.css( 'display', 'inline-flex' );
					$autocompleteWrap.hide();
					renderPreview( p );
				}, 'json' ).fail( function () {
					$preview.html( '<span class="wpam-top-pct">' + ( ( data.i18n && data.i18n.error_generic ) || 'An error occurred.' ) + '</span>' );
				} );
			}

			/**
			 * Muestra posts históricos + Score Histórico si cumple
			 * `minimum_posts_per_tag`, o el aviso de que se usará el Score Global
			 * en caso contrario — exactamente lo que pediste.
			 */
			function renderPreview( p ) {
				var html = '<div class="wpam-top-pct">' + p.count + ( 1 === p.count ? ' post histórico' : ' posts históricos' ) + '</div>';
				if ( p.uses_global ) {
					html += '<div class="wpam-top-pct">Se usará el Score Global (' + ( p.site_avg !== null ? p.site_avg.toFixed( 2 ) : 'N/A' ) + ') — no hay suficiente histórico para este TAG (mínimo ' + p.min_posts + ' posts).</div>';
				} else {
					html += '<div class="wpam-top-pct">Score Histórico: ' + p.historical_score.toFixed( 2 ) + '</div>';
				}
				$preview.html( html );
			}

			$picker.on( 'click', '.wpam-factor-tag-remove', function () {
				$hiddenId.val( '' );
				$chip.hide();
				$chipName.text( '' );
				$preview.empty();
				$autocompleteWrap.show();
				$input.val( '' ).trigger( 'focus' );
			} );
		} );
	}

	function saveMinPostsValue( value ) {
		var $input = $( '#wpam-bunny-score-min-posts-input' );
		if ( ! $input.length ) {
			return;
		}

		var sanitizedValue = Math.max( 1, parseInt( value, 10 ) || 1 );
		$input.prop( 'disabled', true );

		$.post(
			data.ajaxUrl,
			{
				action: 'wpam_save_bunny_score_min_posts',
				nonce: data.crudNonce,
				value: sanitizedValue,
			},
			function ( response ) {
				$input.prop( 'disabled', false );
				if ( ! response || ! response.success ) {
					notifyFactorAjax( 'error', response && response.data ? response.data : ( data.i18n && data.i18n.error_generic ? data.i18n.error_generic : 'An error occurred.' ) );
					return;
				}
				// Mismo aviso visual (".wpam-ajax-notice") que usa el resto del
				// CRUD de Factores Externos — un solo mecanismo de confirmación
				// en toda la pantalla, no uno distinto por campo.
				notifyFactorAjax( 'success', data.i18n && data.i18n.saved ? data.i18n.saved : 'Saved!' );
		}, 'json' ).fail( function () {
			$input.prop( 'disabled', false );
			notifyFactorAjax( 'error', data.i18n && data.i18n.error_generic ? data.i18n.error_generic : 'An error occurred.' );
		} );
	}

	/**
	 * Aviso de éxito/error compartido por TODO el CRUD AJAX de esta pantalla
	 * (factores Y min_posts_per_tag) — mismo patrón visual existente del
	 * plugin (`.wpam-ajax-notice`, `--success`/`--error`), un solo punto de
	 * verdad en vez de reimplementarlo por separado en `FactorModal`.
	 */
	function notifyFactorAjax( type, message ) {
		var $notice = $( '#wpam-factor-ajax-notice' );
		if ( ! $notice.length ) { return; }
		$notice
			.removeClass( 'wpam-ajax-notice--success wpam-ajax-notice--error' )
			.addClass( 'wpam-ajax-notice--' + type )
			.text( message )
			.show();
		clearTimeout( notifyFactorAjax._t );
		notifyFactorAjax._t = setTimeout( function () { $notice.fadeOut(); }, 4000 );
	}

	$( function () {
		$( '.wpam-bunny-factor-value-row' ).each( function () {
			applyFactorStateVisibility( $( this ) );
		} );
		initFactorTagPickers();
	} );

	$( document ).on( 'change', '#wpam-bunny-score-min-posts-input', function () {
		saveMinPostsValue( $( this ).val() );
	} );

	$( document ).on( 'change', '.wpam-factor-state', function () {
		applyFactorStateVisibility( $( this ).closest( 'tr' ) );
	} );

	/**
	 * Sección "Posición histórica": para cada uno de los 3 modelos, muestra
	 * score / percentil / z-score / diferencia vs promedio global + semáforo,
	 * y una gráfica SVG ligera de la distribución real del sitio (sin Chart.js,
	 * sin librerías — SVG puro construido como string, igual que el resto de
	 * este panel).
	 *
	 * @since 1.7.5
	 */
	function buildPositionSection( position ) {
		if ( ! position || ! position.stats || ! position.stats.total_posts ) {
			return '<section class="wpam-analytics-card">' +
				'<h4 class="wpam-analytics-card-title">📐 Posición histórica</h4>' +
				'<p class="wpam-analytics-empty">Aún no hay estadísticas históricas generadas. Usa "Regenerar estadísticas ahora" más abajo.</p>' +
				'</section>';
		}

		var stats = position.stats;
		var pos = position.models ? position.models.bunny_score : null;

		var html = '<section class="wpam-analytics-card">';
		html += '<h4 class="wpam-analytics-card-title">📐 Posición histórica</h4>';
		html += '<p class="description">Comparado contra ' + stats.total_posts + ' publicaciones históricas del sitio.</p>';

		if ( pos ) {
			html += '<div class="wpam-quick-access-grid">';
			html += '<div class="wpam-quick-access-card">';
			html += '<div class="wpam-quick-access-label">Bunny Score</div>';
			html += '<div class="wpam-stat-value">' + pos.score.toFixed( 2 ) + '</div>';
			html += '<div class="wpam-top-pct">' + ( pos.semaphore ? pos.semaphore.icon + ' ' : '' ) + 'Percentil ' + Math.round( pos.percentile ) + ( pos.semaphore ? ' · ' + pos.semaphore.label : '' ) + '</div>';
			html += '<div class="wpam-top-pct">Z-Score ' + ( pos.z_score !== null ? ( pos.z_score >= 0 ? '+' : '' ) + pos.z_score.toFixed( 2 ) : 'N/A' ) + '</div>';
			html += '<div class="wpam-top-pct">vs promedio global ' + ( pos.diff_vs_global !== null ? ( pos.diff_vs_global >= 0 ? '+' : '' ) + pos.diff_vs_global.toFixed( 2 ) : 'N/A' ) + '</div>';
			html += '</div>';
			html += '</div>';
		}

		html += buildDistributionSvg( stats, pos );

		html += '</section>';
		return html;
	}

	/**
	 * Gráfica SVG pura de la distribución histórica REAL (los bins ya vienen
	 * calculados por el servidor con Freedman–Diaconis/Sturges — aquí solo se
	 * dibujan tal cual, sin asumir ninguna forma de campana). Incluye mínimo,
	 * máximo, promedio, mediana, y un marcador vertical único (Bunny Score —
	 * v2 solo tiene un modelo, ya no 3).
	 *
	 * @since 1.7.5
	 * @since 1.7.6 Un solo marcador (Bunny Score) en vez de 3 modelos.
	 */
	function buildDistributionSvg( stats, pos ) {
		var dist = stats.distribution || [];
		if ( ! dist.length || stats.min === null || stats.max === null ) {
			return '';
		}

		var width = 640;
		var height = 180;
		var padding = 24;
		var chartW = width - padding * 2;
		var chartH = height - padding * 2;

		var min = stats.min;
		var max = stats.max;
		var range = ( max - min ) > 0 ? ( max - min ) : 1;

		var maxCount = 0;
		dist.forEach( function ( bin ) { if ( bin.count > maxCount ) { maxCount = bin.count; } } );
		if ( maxCount <= 0 ) { maxCount = 1; }

		function xForValue( v ) {
			return padding + ( ( v - min ) / range ) * chartW;
		}

		var barGap = 1;
		var barW = ( chartW / dist.length ) - barGap;

		var svg = '<svg viewBox="0 0 ' + width + ' ' + height + '" width="100%" height="' + height + '" role="img" aria-label="Distribución histórica de scores">';

		// Barras del histograma real.
		dist.forEach( function ( bin, i ) {
			var barH = ( bin.count / maxCount ) * chartH;
			var x = padding + i * ( chartW / dist.length );
			var y = padding + chartH - barH;
			svg += '<rect x="' + x.toFixed( 1 ) + '" y="' + y.toFixed( 1 ) + '" width="' + Math.max( 0, barW ).toFixed( 1 ) + '" height="' + barH.toFixed( 1 ) + '" fill="#c7d2fe" />';
		} );

		// Línea base.
		svg += '<line x1="' + padding + '" y1="' + ( padding + chartH ) + '" x2="' + ( width - padding ) + '" y2="' + ( padding + chartH ) + '" stroke="#d1d5db" stroke-width="1" />';

		// Mediana (línea discreta gris).
		if ( stats.median !== null ) {
			var xm = xForValue( stats.median );
			svg += '<line x1="' + xm.toFixed( 1 ) + '" y1="' + padding + '" x2="' + xm.toFixed( 1 ) + '" y2="' + ( padding + chartH ) + '" stroke="#9ca3af" stroke-width="1" stroke-dasharray="3,3" />';
		}

		// Promedio (línea discreta más oscura).
		if ( stats.avg !== null ) {
			var xa = xForValue( stats.avg );
			svg += '<line x1="' + xa.toFixed( 1 ) + '" y1="' + padding + '" x2="' + xa.toFixed( 1 ) + '" y2="' + ( padding + chartH ) + '" stroke="#6b7280" stroke-width="1" stroke-dasharray="1,2" />';
		}

		// Marcador único: Bunny Score, en su posición exacta según el percentil real.
		if ( pos ) {
			var clamped = Math.max( min, Math.min( max, pos.score ) );
			var xv = xForValue( clamped );
			svg += '<line x1="' + xv.toFixed( 1 ) + '" y1="' + ( padding - 6 ) + '" x2="' + xv.toFixed( 1 ) + '" y2="' + ( padding + chartH ) + '" stroke="#6366f1" stroke-width="2" />';
			svg += '<circle cx="' + xv.toFixed( 1 ) + '" cy="' + ( padding - 6 ) + '" r="3" fill="#6366f1" />';
		}

		svg += '</svg>';

		var legend = '<div class="wpam-bunny-score-chart-legend">';
		legend += '<span>Mín ' + stats.min.toFixed( 2 ) + '</span>';
		legend += '<span>Mediana ' + ( stats.median !== null ? stats.median.toFixed( 2 ) : 'N/A' ) + '</span>';
		legend += '<span>Promedio ' + ( stats.avg !== null ? stats.avg.toFixed( 2 ) : 'N/A' ) + '</span>';
		legend += '<span>Máx ' + stats.max.toFixed( 2 ) + '</span>';
		if ( pos ) {
			legend += '<span><i style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#6366f1;margin-right:4px;"></i>Bunny Score</span>';
		}
		legend += '</div>';

		return '<div class="wpam-bunny-score-chart">' + svg + '</div>' + legend;
	}

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
			html += '<h4 class="wpam-analytics-card-title">🐰 ' + ( data.i18n && data.i18n.final_bunny_score ? data.i18n.final_bunny_score : 'Bunny Score' ) + '</h4>';
			html += '<div class="wpam-stat-value">' + bunnyScore + '</div>';
			html += '<div class="wpam-top-list" style="margin-top:18px; gap:12px; display:grid;">';
			html += '<div><strong>🌎 ' + ( data.i18n && data.i18n.site_global_avg ? data.i18n.site_global_avg : 'Score Global' ) + '</strong><br>' + siteAverage + '</div>';
			html += '<div><strong>📊 ' + ( data.i18n && data.i18n.diff_vs_global ? data.i18n.diff_vs_global : 'Diferencia vs Score Global' ) + '</strong><br><span class="' + diffClass + '">' + diffIcon + ' ' + diffText + '</span></div>';
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

			html += buildPositionSection( payload.position, data );

			html += '<section class="wpam-analytics-card">';
			html += '<h4 class="wpam-analytics-card-title">🎯 Factores manuales</h4>';
			if ( payload.factors.per_factor && Object.keys( payload.factors.per_factor ).length ) {
				html += '<ul class="wpam-top-list">';
				Object.keys( payload.factors.per_factor ).forEach( function ( key ) {
					var factor = payload.factors.per_factor[ key ];
					var status = ( data.i18n && data.i18n.not_applicable ) ? data.i18n.not_applicable : 'No aplica';
					if ( factor.percent !== null && typeof factor.percent !== 'undefined' ) {
						// v1.7.7: 0.00% es un valor calculado válido (ej. un tramo de
						// range_table en 0, o un boolean en false) — NO es lo mismo que
						// "No aplica" (que solo corresponde a percent === null). Antes se
						// mostraban ambos casos igual, ocultando que el factor sí participó.
						status = ( factor.percent > 0 ? '+' : '' ) + factor.percent.toFixed( 2 ) + '%';
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
				html += '<th>Score Histórico</th>';
				html += '<th>Score Ajustado</th>';
				html += '</tr></thead><tbody>';
				payload.historical.per_tag.forEach( function ( term ) {
					var historicalScore = term.historical_score !== null ? term.historical_score.toFixed( 2 ) : '—';
					var adjustedScore = term.adjusted_score !== null ? term.adjusted_score.toFixed( 2 ) : '—';
					// v1.7.7 (ajuste): distinguir visualmente cuando el Score Histórico
					// mostrado NO proviene del propio TAG sino del fallback al Score
					// Global — evita que el admin piense que ese número sale del TAG.
					var sourceBadge = '';
					if ( term.used_global ) {
						sourceBadge = ' <span class="wpam-top-pct">(Global)</span>';
					} else if ( 'factor' === term.source ) {
						sourceBadge = ' <span class="wpam-top-pct">(factor)</span>';
					}
					html += '<tr>';
					html += '<td><strong>' + ( term.name || '—' ) + '</strong>' + sourceBadge + '</td>';
					html += '<td>' + ( term.count || 0 ) + '</td>';
					html += '<td>' + historicalScore + '</td>';
					html += '<td>' + adjustedScore + '</td>';
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

	/* =====================================================================
	 * v1.7.5 — FactorModal: administración de Factores Externos.
	 *
	 * Un único modal, reutilizado para crear y editar (nunca dos formularios
	 * distintos). Aislamiento: todo el estado (incluida la tabla de rangos)
	 * vive DENTRO del DOM del modal y se reconstruye por completo en cada
	 * apertura — como solo existe una instancia del modal en toda la
	 * página, no hay forma de que un segundo factor range_table "herede"
	 * filas del anterior. Toda la delegación de eventos está scoped a
	 * `$modal`/`$typeFields`, nunca a `document` globalmente para nada que
	 * pueda chocar entre factores.
	 * ===================================================================== */
	var FactorModal = ( function () {
		var $overlay, $modal, $typeFields, $error, $saveBtn, $savingIndicator;
		var factorTypes = ( window.wpamAdminData && window.wpamAdminData.factorTypes ) || [
			{ id: 'boolean', label: 'Boolean' },
			{ id: 'numeric', label: 'Numeric' },
			{ id: 'label', label: 'Label' },
			{ id: 'range_table', label: 'Range table' }
		];

		function init() {
			$overlay = $( '#wpam-factor-modal-overlay' );
			if ( ! $overlay.length ) { return; } // pantalla sin Bunny Score (defensivo).

			$modal = $( '#wpam-factor-modal' );
			$typeFields = $( '#wpam-factor-type-fields' );
			$error = $( '#wpam-factor-modal-error' );
			$saveBtn = $( '#wpam-factor-modal-save' );
			$savingIndicator = $( '#wpam-factor-modal-saving' );

			$( '#wpam-add-factor-btn' ).on( 'click', function () {
				openForCreate();
			} );

			// Delegado sobre la tabla, no sobre document — solo esta tabla dispara esto.
			$( '#wpam-bunny-factors-table-wrap' ).on( 'click', '.wpam-factor-edit-btn', function () {
				openForEdit( $( this ).data( 'id' ) );
			} );

			$( '#wpam-bunny-factors-table-wrap' ).on( 'click', '.wpam-factor-delete-btn', function () {
				var id = $( this ).data( 'id' );
				var label = $( this ).data( 'label' );
				handleDelete( id, label );
			} );

			$( '#wpam-factor-modal-close, #wpam-factor-modal-cancel' ).on( 'click', close );
			$overlay.on( 'click', function ( e ) {
				if ( e.target === $overlay.get( 0 ) ) { close(); }
			} );
			$( document ).on( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && $overlay.is( ':visible' ) ) { close(); }
			} );

			$( '#wpam-factor-type' ).on( 'change', function () {
				renderTypeFields( $( this ).val(), null );
			} );

			// Editor de rangos: delegado sobre $typeFields (único contenedor
			// vivo dentro del modal), nunca sobre document.
			$typeFields.on( 'click', '.wpam-range-add-row', function ( e ) {
				e.preventDefault();
				addRangeRow( null );
			} );
			$typeFields.on( 'click', '.wpam-range-remove-row', function ( e ) {
				e.preventDefault();
				$( this ).closest( 'tr' ).remove();
				if ( ! $typeFields.find( 'tr.wpam-range-row' ).length ) {
					$typeFields.find( 'tbody' ).append( '<tr class="wpam-range-no-rows"><td colspan="4"><em>Sin rangos configurados.</em></td></tr>' );
				}
			} );

			$saveBtn.on( 'click', save );
		}

		function resetForm() {
			$( '#wpam-factor-original-id' ).val( '' );
			$( '#wpam-factor-label' ).val( '' );
			$( '#wpam-factor-type' ).val( 'boolean' );
			$( '#wpam-factor-source' ).val( '' );
			$( '#wpam-factor-max-positive' ).val( '0' );
			$( '#wpam-factor-max-negative' ).val( '0' );
			$( '#wpam-factor-no-data-penalty' ).val( '0' );
			$( '#wpam-factor-supports-na' ).prop( 'checked', false );
			$( '#wpam-factor-enabled' ).prop( 'checked', true );
			$( '#wpam-factor-optional' ).prop( 'checked', false );
			$error.hide().text( '' );
			renderTypeFields( 'boolean', null );
		}

		function openForCreate() {
			resetForm();
			$( '#wpam-factor-modal-title' ).text( ( data.i18n && data.i18n.add_factor_title ) || 'Agregar factor' );
			show();
		}

		function openForEdit( id ) {
			if ( ! id ) { return; }
			resetForm();
			$( '#wpam-factor-modal-title' ).text( ( data.i18n && data.i18n.edit_factor_title ) || 'Editar factor' );
			show();
			$typeFields.html( '<p>' + ( ( data.i18n && data.i18n.loading ) || 'Loading...' ) + '</p>' );
			$saveBtn.prop( 'disabled', true );

			$.post( data.ajaxUrl || ajaxurl, {
				action: 'wpam_get_bunny_score_factor',
				nonce: data.crudNonce,
				id: id
			}, function ( response ) {
				$saveBtn.prop( 'disabled', false );
				if ( ! response || ! response.success ) {
					showError( ( response && response.data ) || 'No se pudo cargar el factor.' );
					return;
				}
				populateForm( response.data.factor );
			}, 'json' ).fail( function () {
				$saveBtn.prop( 'disabled', false );
				showError( ( data.i18n && data.i18n.error_generic ) || 'An error occurred.' );
			} );
		}

		function populateForm( factor ) {
			factor = factor || {};
			$( '#wpam-factor-original-id' ).val( factor.id || '' );
			$( '#wpam-factor-label' ).val( factor.label || '' );
			$( '#wpam-factor-type' ).val( factor.type || 'boolean' );
			$( '#wpam-factor-source' ).val( factor.source_label || '' );
			$( '#wpam-factor-max-positive' ).val( factor.max_percent_positive !== undefined ? factor.max_percent_positive : ( factor.max_percent || 0 ) );
			$( '#wpam-factor-max-negative' ).val( factor.max_percent_negative || 0 );
			$( '#wpam-factor-no-data-penalty' ).val( factor.no_data_penalty_ratio || 0 );
			$( '#wpam-factor-supports-na' ).prop( 'checked', !! factor.supports_not_applicable );
			$( '#wpam-factor-enabled' ).prop( 'checked', factor.enabled !== false );
			$( '#wpam-factor-optional' ).prop( 'checked', !! factor.optional );
			renderTypeFields( factor.type || 'boolean', factor );
		}

		/**
		 * Reconstruye por completo `#wpam-factor-type-fields` según el tipo.
		 * Recibe el factor completo (o null, para un factor nuevo/al cambiar
		 * de tipo) para poder prellenar en modo edición.
		 */
		function renderTypeFields( type, factor ) {
			factor = factor || {};
			var html = '';

			if ( 'numeric' === type ) {
				html += '<div class="wpam-modal-grid">';
				html += '<div class="wpam-modal-field"><label>Escala m\u00ednima</label><input type="text" id="wpam-factor-scale-min" class="wpam-input" value="' + escapeHtml( factor.scale_min !== undefined ? factor.scale_min : 0 ) + '" /></div>';
				html += '<div class="wpam-modal-field"><label>Escala m\u00e1xima</label><input type="text" id="wpam-factor-scale-max" class="wpam-input" value="' + escapeHtml( factor.scale_max !== undefined ? factor.scale_max : 100 ) + '" /></div>';
				html += '</div>';
			} else if ( 'label' === type ) {
				var labelsJson = factor.labels_json || ( factor.labels ? JSON.stringify( factor.labels ) : '' );
				html += '<div class="wpam-modal-field"><label>Etiquetas (JSON: {"clave": porcentaje})</label>';
				html += '<textarea id="wpam-factor-labels-json" class="wpam-input large-text" rows="3" placeholder=\'{"A":10,"B":5}\'>' + escapeHtml( labelsJson ) + '</textarea></div>';
			} else if ( 'range_table' === type ) {
				html += '<div class="wpam-modal-field">';
				html += '<label>Tabla de rangos (valores de -100 a 100; el \u00faltimo tramo puede quedar sin l\u00edmite superior = "o m\u00e1s")</label>';
				html += '<div class="wpam-range-table-wrap">';
				html += '<table class="wpam-range-table"><thead><tr><th>Min</th><th>Max (vac\u00edo = sin l\u00edmite)</th><th>% del m\u00e1ximo (-100..100)</th><th></th></tr></thead>';
				html += '<tbody></tbody></table>';
				html += '<p><button type="button" class="button wpam-range-add-row">Agregar rango</button></p>';
				html += '</div></div>';
			}

			$typeFields.html( html );

			if ( 'range_table' === type ) {
				var ranges = ( factor && factor.range_table && factor.range_table.length ) ? factor.range_table : [];
				if ( ranges.length ) {
					ranges.forEach( function ( r ) { addRangeRow( r ); } );
				} else {
					$typeFields.find( 'tbody' ).append( '<tr class="wpam-range-no-rows"><td colspan="4"><em>Sin rangos configurados.</em></td></tr>' );
				}
			}
		}

		/**
		 * Agrega una fila al editor de rangos DEL MODAL ACTUALMENTE ABIERTO.
		 * No usa índices en el `name` (a diferencia de la vieja tabla
		 * inline) — al guardar, `save()` serializa el estado del DOM
		 * directamente, así que no hace falta renumerar nada al eliminar.
		 */
		function addRangeRow( range ) {
			range = range || { min: 0, max: '', percent_of_max: 0 };
			$typeFields.find( 'tr.wpam-range-no-rows' ).remove();
			var row = '<tr class="wpam-range-row">' +
				'<td><input type="number" step="1" min="0" class="wpam-range-min" value="' + escapeHtml( range.min ) + '" style="width:80px;" /></td>' +
				'<td><input type="number" step="1" min="0" class="wpam-range-max" value="' + escapeHtml( range.max === null ? '' : range.max ) + '" style="width:80px;" placeholder="sin l\u00edmite" /></td>' +
				'<td><input type="number" step="0.1" min="-100" max="100" class="wpam-range-percent" value="' + escapeHtml( range.percent_of_max ) + '" style="width:80px;" />%</td>' +
				'<td><button type="button" class="button wpam-range-remove-row">Eliminar</button></td>' +
				'</tr>';
			$typeFields.find( 'tbody' ).append( row );
		}

		function collectRangeTable() {
			var rows = [];
			$typeFields.find( 'tr.wpam-range-row' ).each( function () {
				var $r = $( this );
				rows.push( {
					min: $r.find( '.wpam-range-min' ).val(),
					max: $r.find( '.wpam-range-max' ).val(),
					percent_of_max: $r.find( '.wpam-range-percent' ).val()
				} );
			} );
			return rows;
		}

		function show() {
			$overlay.css( 'display', 'flex' );
			$( 'body' ).addClass( 'wpam-modal-open' );
			setTimeout( function () { $( '#wpam-factor-label' ).trigger( 'focus' ); }, 50 );
		}

		function close() {
			$overlay.hide();
			$( 'body' ).removeClass( 'wpam-modal-open' );
		}

		function showError( msg ) {
			$error.text( msg ).show();
		}

		function handleDelete( id, label ) {
			if ( ! id ) { return; }
			/* eslint-disable no-alert */
			var confirmed = window.confirm(
				( ( data.i18n && data.i18n.confirm_delete_factor ) || '¿Eliminar el factor "%s"? Esta acción no se puede deshacer.' ).replace( '%s', label || id )
			);
			/* eslint-enable no-alert */
			if ( ! confirmed ) { return; }

			$.post( data.ajaxUrl || ajaxurl, {
				action: 'wpam_delete_bunny_score_factor',
				nonce: data.crudNonce,
				id: id
			}, function ( response ) {
				if ( ! response || ! response.success ) {
					notifyFactorAjax( 'error', ( response && response.data ) || 'No se pudo eliminar el factor.' );
					return;
				}
				$( '#wpam-factor-row-' + id ).remove();
				if ( ! $( '#wpam-bunny-factors-tbody tr.wpam-table-row' ).length ) {
					$( '#wpam-bunny-factors-tbody' ).html( '<tr id="wpam-bunny-factors-empty-row"><td colspan="6" class="wpam-table-empty">No hay factores configurados todav\u00eda.</td></tr>' );
				}
				notifyFactorAjax( 'success', ( data.i18n && data.i18n.factor_deleted ) || 'Factor eliminado.' );
			}, 'json' ).fail( function () {
				notifyFactorAjax( 'error', ( data.i18n && data.i18n.error_generic ) || 'An error occurred.' );
			} );
		}

		function save() {
			var label = $( '#wpam-factor-label' ).val().trim();
			if ( ! label ) {
				showError( ( data.i18n && data.i18n.factor_label_required ) || 'El nombre del factor es obligatorio.' );
				return;
			}
			$error.hide();

			var type = $( '#wpam-factor-type' ).val();
			var payload = {
				action: 'wpam_save_bunny_score_factor',
				nonce: data.crudNonce,
				original_id: $( '#wpam-factor-original-id' ).val(),
				label: label,
				type: type,
				source_label: $( '#wpam-factor-source' ).val(),
				max_percent: $( '#wpam-factor-max-positive' ).val(),
				max_percent_negative: $( '#wpam-factor-max-negative' ).val(),
				no_data_penalty_ratio: $( '#wpam-factor-no-data-penalty' ).val(),
				supports_not_applicable: $( '#wpam-factor-supports-na' ).is( ':checked' ) ? 1 : 0,
				enabled: $( '#wpam-factor-enabled' ).is( ':checked' ) ? 1 : 0,
				optional: $( '#wpam-factor-optional' ).is( ':checked' ) ? 1 : 0
			};

			if ( 'numeric' === type ) {
				payload.scale_min = $( '#wpam-factor-scale-min' ).val();
				payload.scale_max = $( '#wpam-factor-scale-max' ).val();
			} else if ( 'label' === type ) {
				payload.labels_json = $( '#wpam-factor-labels-json' ).val();
			} else if ( 'range_table' === type ) {
				payload.range_table = collectRangeTable();
			}

			$saveBtn.prop( 'disabled', true );
			$savingIndicator.show();

			$.post( data.ajaxUrl || ajaxurl, payload, function ( response ) {
				$saveBtn.prop( 'disabled', false );
				$savingIndicator.hide();

				if ( ! response || ! response.success ) {
					showError( ( response && response.data ) || 'No se pudo guardar el factor.' );
					return;
				}

				var $emptyRow = $( '#wpam-bunny-factors-empty-row' );
				if ( $emptyRow.length ) { $emptyRow.remove(); }

				var $existingRow = $( '#wpam-factor-row-' + response.data.factor.id );
				if ( $existingRow.length ) {
					$existingRow.replaceWith( response.data.row_html );
				} else {
					$( '#wpam-bunny-factors-tbody' ).append( response.data.row_html );
				}

				close();
				notifyFactorAjax( 'success', response.data.is_new ? ( ( data.i18n && data.i18n.factor_created ) || 'Factor creado.' ) : ( ( data.i18n && data.i18n.factor_updated ) || 'Factor actualizado.' ) );
			}, 'json' ).fail( function () {
				$saveBtn.prop( 'disabled', false );
				$savingIndicator.hide();
				showError( ( data.i18n && data.i18n.error_generic ) || 'An error occurred.' );
			} );
		}

		return { init: init };
	} )();

	$( FactorModal.init );

} )( jQuery );
