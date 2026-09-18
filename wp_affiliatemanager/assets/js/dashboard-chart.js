/**
 * Dashboard — Daily Activity chart (v1.8.12).
 *
 * Vanilla wiring around Chart.js (loaded as a separate vendor script on this
 * screen only). All data for the full 30-day window, every resource type,
 * arrives once via `window.wpamDashboardChartData` (injected server-side by
 * Admin_Menu::render_daily_activity_chart()). The metric selector and the
 * resource-type <select> are purely client-side from here on — no AJAX.
 *
 * Data shape:
 *   {
 *     labels: [ '20250101', ... ],           // 30 entries, YYYYMMDD, WP-local days
 *     clicks: [ 3, 0, 5, ... ],               // one flat series — clicks has no resource_type
 *     views:  { all: [...], post: [...], page: [...], ... }
 *   }
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var canvas = document.getElementById( 'wpam-dashboard-chart' );
		if ( ! canvas || typeof window.Chart === 'undefined' || ! window.wpamDashboardChartData ) {
			return;
		}

		var data       = window.wpamDashboardChartData;
		var i18n       = window.wpamDashboardChartI18n || { clicks: 'Clicks', views: 'Views' };
		var typeSelect = document.getElementById( 'wpam-dashboard-chart-resource-type' );
		var metricPills = document.querySelectorAll( '.wpam-chart-pill' );

		var CLICKS_COLOR = '#d63638'; // rojo WP admin — distinguible de Views.
		var VIEWS_COLOR  = '#2271b1'; // azul WP admin.

		var currentMetric = 'views'; // default pedido: Views.

		function formatLabel( ymd ) {
			if ( ! ymd || ymd.length !== 8 ) {
				return ymd;
			}
			return ymd.substr( 6, 2 ) + '/' + ymd.substr( 4, 2 );
		}

		function viewsSeriesForType( type ) {
			return data.views[ type ] || data.views.all || [];
		}

		function buildDatasets( metric, resourceType ) {
			var datasets = [];

			if ( 'clicks' === metric || 'both' === metric ) {
				datasets.push( {
					label: i18n.clicks,
					data: data.clicks,
					borderColor: CLICKS_COLOR,
					backgroundColor: CLICKS_COLOR,
					yAxisID: 'yClicks',
					tension: 0.25,
					pointRadius: 2,
				} );
			}

			if ( 'views' === metric || 'both' === metric ) {
				datasets.push( {
					label: i18n.views,
					data: viewsSeriesForType( resourceType ),
					borderColor: VIEWS_COLOR,
					backgroundColor: VIEWS_COLOR,
					yAxisID: 'both' === metric ? 'yViews' : 'yClicks',
					tension: 0.25,
					pointRadius: 2,
				} );
			}

			return datasets;
		}

		function buildScales( metric ) {
			if ( 'both' === metric ) {
				return {
					yClicks: {
						type: 'linear',
						position: 'left',
						beginAtZero: true,
						title: { display: true, text: i18n.clicks },
						ticks: { color: CLICKS_COLOR },
					},
					yViews: {
						type: 'linear',
						position: 'right',
						beginAtZero: true,
						title: { display: true, text: i18n.views },
						ticks: { color: VIEWS_COLOR },
						grid: { drawOnChartArea: false },
					},
				};
			}

			// Un solo eje cuando se muestra una sola métrica.
			return {
				yClicks: {
					type: 'linear',
					position: 'left',
					beginAtZero: true,
				},
			};
		}

		var chart = new window.Chart( canvas.getContext( '2d' ), {
			type: 'line',
			data: {
				labels: data.labels.map( formatLabel ),
				datasets: buildDatasets( currentMetric, typeSelect ? typeSelect.value : 'all' ),
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { display: true },
					tooltip: { mode: 'index', intersect: false },
				},
				scales: buildScales( currentMetric ),
			},
		} );

		function refresh() {
			var resourceType = typeSelect ? typeSelect.value : 'all';
			chart.data.datasets = buildDatasets( currentMetric, resourceType );
			chart.options.scales = buildScales( currentMetric );
			chart.update();

			if ( typeSelect ) {
				typeSelect.disabled = ( 'clicks' === currentMetric );
			}
		}

		metricPills.forEach( function ( pill ) {
			pill.addEventListener( 'click', function () {
				currentMetric = pill.getAttribute( 'data-metric' );

				metricPills.forEach( function ( p ) {
					p.classList.toggle( 'wpam-chart-pill--active', p === pill );
				} );

				refresh();
			} );
		} );

		if ( typeSelect ) {
			typeSelect.addEventListener( 'change', refresh );
		}
	} );
} )();
