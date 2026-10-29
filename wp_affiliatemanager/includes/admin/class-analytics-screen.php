<?php
/**
 * Analytics Screen — página con tabs Score / Clicks / Views.
 *
 * Toda la analítica pesada vive aquí, fuera del Dashboard. Los 3 tabs
 * comparten exactamente el mismo mecanismo de filtro (cards Today/Last 7
 * Days/Last 30 Days/All Time) y el mismo endpoint AJAX
 * (wp_ajax_wpam_analytics_filter), diferenciados únicamente por el
 * parámetro `source`.
 *
 * Datos: exclusivamente de Top_Posts_Query, Views_Query y Score_Query.
 * Render: exclusivamente vía Analytics_Renderer. Esta clase solo orquesta.
 *
 * @package WP_AffiliateManager\Admin
 * @since   1.4.0
 */

namespace WP_AffiliateManager\Admin;

use WP_AffiliateManager\Analytics\Score_Query;
use WP_AffiliateManager\Content_Protection\Age_Gate_Query;
use WP_AffiliateManager\Frontend\Top_Posts_Query;
use WP_AffiliateManager\Views\Resource_Resolver;
use WP_AffiliateManager\Views\Views_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Analytics_Screen {

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Renderiza la página completa: nav de tabs + los 3 paneles.
	 *
	 * Los 3 paneles se generan enteramente server-side en la misma carga
	 * (sin AJAX por-tab); el JS solo hace show/hide entre ellos. Cada panel
	 * arranca en rango 'total' — analytics.js reaplica inmediatamente el
	 * rango guardado en localStorage, igual que hacía dashboard.js.
	 *
	 * @since 1.4.0
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-affiliatemanager' ) );
		}

		$score_stats     = Score_Query::get_stats_cached();
		$clicks_stats    = Top_Posts_Query::get_stats_cached();
		$views_stats     = Views_Query::get_global_stats_cached();
		$default_views_resource_type = 'global';

		$age_gate_global_stats = Age_Gate_Query::get_global_stats_cached();
		$age_gate_stats        = Age_Gate_Query::get_stats_cached( 'total' );
		$age_gate_utm          = Age_Gate_Query::get_utm_breakdown_cached( 'total', 20 );
		$age_gate_posts        = Age_Gate_Query::get_top_posts_cached( 'total', 10 );

		$top_scored     = Score_Query::get_cached( 'total', 10 );
		$top_affiliates = Top_Posts_Query::get_top_affiliates( 'total', 10 );
		$top_clicked    = self::attach_ctr_to_clicked_posts( Top_Posts_Query::get_cached( 'total', 10 ), 'total' );
		$top_viewed     = Views_Query::get_global_cached( 'total', 10 );
		$utm_sources    = Views_Query::get_utm_sources( 'total', 10 );

		$recent_clicks = Top_Posts_Query::get_recent( 20 );
		$recent_views  = Views_Query::get_recent( 20 );
		?>
		<div class="bunny-page-content wpam-analytics-page">

			<div class="wpam-screen-header">
				<div class="wpam-screen-header-info">
					<h2 class="wpam-screen-title"><?php esc_html_e( 'Analytics', 'wp-affiliatemanager' ); ?></h2>
				</div>
			</div>

			<div class="wpam-tabs" role="tablist">
				<button type="button" class="wpam-tab-item wpam-tab-item--active" data-tab="score" role="tab" aria-selected="true">
					<?php esc_html_e( 'Score', 'wp-affiliatemanager' ); ?>
				</button>
				<button type="button" class="wpam-tab-item" data-tab="clicks" role="tab" aria-selected="false">
					<?php esc_html_e( 'Clicks', 'wp-affiliatemanager' ); ?>
				</button>
				<button type="button" class="wpam-tab-item" data-tab="views" role="tab" aria-selected="false">
					<?php esc_html_e( 'Views', 'wp-affiliatemanager' ); ?>
				</button>
				<button type="button" class="wpam-tab-item" data-tab="age_gate" role="tab" aria-selected="false">
					<?php esc_html_e( 'NSFW Gate', 'wp-affiliatemanager' ); ?>
				</button>
			</div>

			<!-- ============================== Tab: Score ============================== -->
			<div class="wpam-tab-panel wpam-tab-panel--active" data-tab-panel="score">
				<p class="wpam-analytics-formula-note" style="margin: 0 0 16px; font-size: 13px; color: var(--wpam-gray-600, #475569);">
					<?php
					printf(
						/* translators: 1: views factor, 2: clicks factor */
						esc_html__( 'Score formula: Post Views × %1$d + Clicks × %2$d', 'wp-affiliatemanager' ),
						(int) \WP_AffiliateManager\Analytics\Score_Query::DEFAULT_FACTOR_VIEWS,
						(int) \WP_AffiliateManager\Analytics\Score_Query::DEFAULT_FACTOR_CLICKS
					);
					?>
				</p>

				<div class="wpam-stats-grid wpam-analytics-cards--score">
					<?php Analytics_Renderer::render_stat_card( __( 'Today', 'wp-affiliatemanager' ), number_format_i18n( $score_stats['today'] ), '⭐' ); ?>
					<?php Analytics_Renderer::render_stat_card( __( 'Last 7 Days', 'wp-affiliatemanager' ), number_format_i18n( $score_stats['week'] ), '📅' ); ?>
					<?php Analytics_Renderer::render_stat_card( __( 'Last 30 Days', 'wp-affiliatemanager' ), number_format_i18n( $score_stats['month'] ), '🗓️' ); ?>
					<?php Analytics_Renderer::render_stat_card( __( 'All Time', 'wp-affiliatemanager' ), number_format_i18n( $score_stats['total'] ), '📊' ); ?>
				</div>

				<div class="wpam-analytics-scored-posts-col">
					<?php Analytics_Renderer::render_top_scored_posts_section( $top_scored ); ?>
				</div>
			</div>

			<!-- ============================== Tab: Clicks ============================== -->
			<div class="wpam-tab-panel" data-tab-panel="clicks" style="display:none;">
				<div class="wpam-stats-grid wpam-analytics-cards--clicks">
					<?php Analytics_Renderer::render_stat_card( __( 'Today', 'wp-affiliatemanager' ), number_format_i18n( $clicks_stats['today'] ), '📈' ); ?>
					<?php Analytics_Renderer::render_stat_card( __( 'Last 7 Days', 'wp-affiliatemanager' ), number_format_i18n( $clicks_stats['week'] ), '📅' ); ?>
					<?php Analytics_Renderer::render_stat_card( __( 'Last 30 Days', 'wp-affiliatemanager' ), number_format_i18n( $clicks_stats['month'] ), '🗓️' ); ?>
					<?php Analytics_Renderer::render_stat_card( __( 'All Time', 'wp-affiliatemanager' ), number_format_i18n( $clicks_stats['total'] ), '🖱️' ); ?>
				</div>

				<div class="wpam-analytics-cols">
					<div class="wpam-analytics-col wpam-analytics-affiliates-col">
						<?php Analytics_Renderer::render_top_affiliates_section( $top_affiliates, $clicks_stats['total'] ); ?>
					</div>
					<div class="wpam-analytics-col wpam-analytics-clicked-posts-col">
						<?php Analytics_Renderer::render_top_clicked_posts_section( $top_clicked ); ?>
					</div>
				</div>

				<?php Analytics_Renderer::render_recent_clicks_section( $recent_clicks ); ?>
			</div>

			<!-- ============================== Tab: Views ============================== -->
			<div class="wpam-tab-panel" data-tab-panel="views" style="display:none;">
				<div class="wpam-analytics-views-filter">
					<label for="wpam-views-resource-type"><?php esc_html_e( 'Resource type:', 'wp-affiliatemanager' ); ?></label>
					<select id="wpam-views-resource-type">
						<?php foreach ( self::resource_type_labels() as $type => $label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $default_views_resource_type, $type ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="wpam-stats-grid wpam-analytics-cards--views">
					<?php Analytics_Renderer::render_stat_card( __( 'Today', 'wp-affiliatemanager' ), number_format_i18n( $views_stats['today'] ), '👁️' ); ?>
					<?php Analytics_Renderer::render_stat_card( __( 'Last 7 Days', 'wp-affiliatemanager' ), number_format_i18n( $views_stats['week'] ), '📅' ); ?>
					<?php Analytics_Renderer::render_stat_card( __( 'Last 30 Days', 'wp-affiliatemanager' ), number_format_i18n( $views_stats['month'] ), '🗓️' ); ?>
					<?php Analytics_Renderer::render_stat_card( __( 'All Time', 'wp-affiliatemanager' ), number_format_i18n( $views_stats['total'] ), '📊' ); ?>
				</div>

				<div class="wpam-analytics-viewed-posts-col">
					<?php Analytics_Renderer::render_top_viewed_posts_section( $top_viewed, $default_views_resource_type ); ?>
					<?php // v1.8.10: utm_source es independiente del resource_type seleccionado — se agrega desde su propia tabla auxiliar y se muestra siempre dentro de esta misma columna, para heredar automáticamente el refresco de rango/resource_type que ya hace analytics.js sobre .wpam-analytics-viewed-posts-col. ?>
					<?php Analytics_Renderer::render_top_terms_section( __( 'Top UTM Sources', 'wp-affiliatemanager' ), '🔗', $utm_sources, 'source' ); ?>
				</div>

				<?php // v1.8.0: Recent Views ahora es un listado general (los 7 resource_type), no solo Posts. ?>
				<?php Analytics_Renderer::render_recent_views_section( $recent_views ); ?>
			</div>

			<!-- ============================== Tab: NSFW Gate ============================== -->
			<div class="wpam-tab-panel" data-tab-panel="age_gate" style="display:none;">
				<div class="wpam-stats-grid wpam-analytics-cards--age-gate">
					<?php Analytics_Renderer::render_stat_card( __( 'Today', 'wp-affiliatemanager' ), number_format_i18n( $age_gate_global_stats['today']['shown'] ), '🔞' ); ?>
					<?php Analytics_Renderer::render_stat_card( __( 'Last 7 Days', 'wp-affiliatemanager' ), number_format_i18n( $age_gate_global_stats['week']['shown'] ), '📅' ); ?>
					<?php Analytics_Renderer::render_stat_card( __( 'Last 30 Days', 'wp-affiliatemanager' ), number_format_i18n( $age_gate_global_stats['month']['shown'] ), '🗓️' ); ?>
					<?php Analytics_Renderer::render_stat_card( __( 'All Time', 'wp-affiliatemanager' ), number_format_i18n( $age_gate_global_stats['total']['shown'] ), '📊' ); ?>
				</div>

				<div class="wpam-analytics-age-gate-summary-col">
					<?php Analytics_Renderer::render_age_gate_summary( $age_gate_stats, 'total' ); ?>
				</div>

				<div class="wpam-analytics-cols">
					<div class="wpam-analytics-col wpam-analytics-age-gate-utm-col">
						<?php Analytics_Renderer::render_age_gate_utm_section( $age_gate_utm ); ?>
					</div>
					<div class="wpam-analytics-col wpam-analytics-age-gate-posts-col">
						<?php Analytics_Renderer::render_age_gate_top_posts_section( $age_gate_posts ); ?>
					</div>
				</div>
			</div>

		</div>
		<?php
	}

	/**
	 * Etiquetas visibles para el selector de resource_type del tab Views.
	 *
	 * @since  1.8.0
	 * @return array<string,string>
	 */
	private static function resource_type_labels(): array {
		return array(
			'global'   => __( 'Global', 'wp-affiliatemanager' ),
			'post'     => __( 'Posts', 'wp-affiliatemanager' ),
			'page'     => __( 'Pages', 'wp-affiliatemanager' ),
			'category' => __( 'Categories', 'wp-affiliatemanager' ),
			'tag'      => __( 'Tags', 'wp-affiliatemanager' ),
			'home'     => __( 'Home', 'wp-affiliatemanager' ),
			'search'   => __( 'Search', 'wp-affiliatemanager' ),
			'404'      => __( '404', 'wp-affiliatemanager' ),
		);
	}

	// -------------------------------------------------------------------------
	// AJAX — único handler para los 3 tabs, dispatch por `source`
	// -------------------------------------------------------------------------

	/**
	 * action: wpam_analytics_filter
	 *
	 * POST params: range (today|week|month|total), source (score|clicks|views).
	 *
	 * @since 1.4.0
	 */
	public function ajax_filter(): void {
		check_ajax_referer( 'wpam_analytics_filter', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$allowed = array( 'today', 'week', 'month', 'total' );
		$range   = sanitize_text_field( wp_unslash( $_POST['range'] ?? 'total' ) );
		if ( ! in_array( $range, $allowed, true ) ) {
			$range = 'total';
		}

		$source = sanitize_text_field( wp_unslash( $_POST['source'] ?? 'score' ) );

		switch ( $source ) {

			case 'clicks':
				$affiliates  = Top_Posts_Query::get_top_affiliates( $range, 10 );
				$clicked     = self::attach_ctr_to_clicked_posts( Top_Posts_Query::get_cached( $range, 10 ), $range );
				$range_total = Top_Posts_Query::get_range_total( $range );

				ob_start();
				Analytics_Renderer::render_top_affiliates_section( $affiliates, $range_total );
				$affiliates_html = ob_get_clean();

				ob_start();
				Analytics_Renderer::render_top_clicked_posts_section( $clicked );
				$clicked_html = ob_get_clean();

				wp_send_json_success( array(
					'affiliates_html'    => $affiliates_html,
					'clicked_posts_html' => $clicked_html,
				) );
				break;

			case 'views':
				$resource_type = sanitize_key( wp_unslash( $_POST['resource_type'] ?? 'global' ) );

				// Debe abrirse ANTES de cualquier render_*() de esta rama — las 5
				// ramas siguientes (global/tipo específico/search/404/home)
				// comparten el mismo ob_get_clean() al final del case. Sin esto, el
				// HTML se imprime crudo antes del JSON y rompe la respuesta AJAX.
				ob_start();

				if ( 'global' === $resource_type ) {
					$viewed = Views_Query::get_global_cached( $range, 10 );
					$stats  = Views_Query::get_global_stats_cached();
					Analytics_Renderer::render_top_viewed_posts_section( $viewed, 'global' );
				} elseif ( ! in_array( $resource_type, Resource_Resolver::TYPES, true ) ) {
					$resource_type = 'global';
					$viewed = Views_Query::get_global_cached( $range, 10 );
					$stats  = Views_Query::get_global_stats_cached();
					Analytics_Renderer::render_top_viewed_posts_section( $viewed, 'global' );
				} elseif ( in_array( $resource_type, array( 'post', 'page', 'category', 'tag' ), true ) ) {
					$viewed = Views_Query::get_cached( $range, 10, array(), $resource_type );
					$stats  = Views_Query::get_stats_cached( $resource_type );
					Analytics_Renderer::render_top_viewed_posts_section( $viewed, $resource_type );
				} elseif ( 'search' === $resource_type ) {
					$terms = Views_Query::get_search_terms( $range, 10 );
					$stats = Views_Query::get_search_terms_stats();
					Analytics_Renderer::render_top_terms_section( __( 'Top Search Terms', 'wp-affiliatemanager' ), '🔍', $terms, 'term' );
				} elseif ( '404' === $resource_type ) {
					$urls  = Views_Query::get_404_urls( $range, 10 );
					$stats = Views_Query::get_404_stats();
					Analytics_Renderer::render_top_terms_section( __( 'Top 404 URLs', 'wp-affiliatemanager' ), '🚫', $urls, 'url' );
				} else {
					// home: sin identidad individual, un solo agregado — la card de
					// stats ya lo resume, no hace falta ninguna lista debajo.
					$stats = Views_Query::get_stats_cached( 'home' );
				}

				// v1.8.10: utm_source no depende del resource_type seleccionado en
				// el dropdown (una View de tipo 'post' o 'search', etc. puede traer
				// utm_source igual) — se agrega en su propia tabla auxiliar y se
				// muestra siempre dentro del mismo bloque, respetando el $range
				// seleccionado igual que el resto de esta pantalla.
				Analytics_Renderer::render_top_terms_section( __( 'Top UTM Sources', 'wp-affiliatemanager' ), '🔗', Views_Query::get_utm_sources( $range, 10 ), 'source' );

				$viewed_html = ob_get_clean();

				wp_send_json_success( array(
					'viewed_posts_html' => $viewed_html,
					'stats'             => array(
						'today' => number_format_i18n( $stats['today'] ),
						'week'  => number_format_i18n( $stats['week'] ),
						'month' => number_format_i18n( $stats['month'] ),
						'total' => number_format_i18n( $stats['total'] ),
					),
				) );
				break;

			case 'age_gate':
				$stats         = Age_Gate_Query::get_stats( $range );
				$utm_breakdown = Age_Gate_Query::get_utm_breakdown( $range, 20 );
				$top_posts     = Age_Gate_Query::get_top_posts( $range, 10 );
				$global_stats  = Age_Gate_Query::get_global_stats();

				ob_start();
				Analytics_Renderer::render_age_gate_summary( $stats, $range );
				$summary_html = ob_get_clean();

				ob_start();
				Analytics_Renderer::render_age_gate_utm_section( $utm_breakdown );
				$utm_html = ob_get_clean();

				ob_start();
				Analytics_Renderer::render_age_gate_top_posts_section( $top_posts );
				$top_posts_html = ob_get_clean();

				wp_send_json_success( array(
					'summary_html'   => $summary_html,
					'utm_html'       => $utm_html,
					'top_posts_html' => $top_posts_html,
					'stats'          => array(
						'today' => number_format_i18n( $global_stats['today']['shown'] ),
						'week'  => number_format_i18n( $global_stats['week']['shown'] ),
						'month' => number_format_i18n( $global_stats['month']['shown'] ),
						'total' => number_format_i18n( $global_stats['total']['shown'] ),
					),
				) );
				break;

			case 'score':
			default:
				$scored = Score_Query::get_cached( $range, 10 );

				ob_start();
				Analytics_Renderer::render_top_scored_posts_section( $scored );
				$scored_html = ob_get_clean();

				wp_send_json_success( array(
					'scored_posts_html' => $scored_html,
				) );
				break;
		}
	}

	/**
	 * Enriquece las publicaciones más clickeadas con su conteo de vistas y CTR estimado.
	 *
	 * Fórmula: CTR = (clics / vistas) * 100 si vistas > 0, de lo contrario null.
	 *
	 * @since  2.2.0
	 * @param  array[] $clicked Posts devueltos por Top_Posts_Query::get_cached().
	 * @param  string  $range   'today' | 'week' | 'month' | 'total'.
	 * @return array[]
	 */
	private static function attach_ctr_to_clicked_posts( array $clicked, string $range ): array {
		if ( empty( $clicked ) ) {
			return array();
		}

		$post_ids  = wp_list_pluck( $clicked, 'id' );
		$views_map = Views_Query::get_view_counts_for_post_ids( $post_ids, $range );

		foreach ( $clicked as &$item ) {
			$post_id     = (int) ( $item['id'] ?? 0 );
			$views_count = $views_map[ $post_id ] ?? 0;
			$click_count = (int) ( $item['click_count'] ?? 0 );

			$item['view_count'] = $views_count;
			$item['ctr']        = $views_count > 0 ? ( ( $click_count / $views_count ) * 100 ) : null;
		}
		unset( $item );

		return $clicked;
	}
}
