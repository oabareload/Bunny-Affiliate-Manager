<?php
/**
 * Módulo de menú de administración.
 *
 * @package WP_AffiliateManager\Admin
 * @since   1.0.0 (actualizado en 2.0.0)
 */

namespace WP_AffiliateManager\Admin;

use WP_AffiliateManager\Affiliates\Repository;
use WP_AffiliateManager\Affiliates\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Menu {

	const PARENT_SLUG    = 'wpam-dashboard';
	const CAPABILITY     = 'manage_options';
	const REPORTS_OPTION = 'wpam_broken_link_reports';

	public function register_menus(): void {
		add_menu_page(
			__( 'Bunny Affiliate Manager', 'wp-affiliatemanager' ),
			__( 'Bunny Affiliates', 'wp-affiliatemanager' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( $this, 'render_dashboard_page' ),
			$this->get_menu_icon(),
			58
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Dashboard — Bunny Affiliate Manager', 'wp-affiliatemanager' ),
			__( 'Dashboard', 'wp-affiliatemanager' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Affiliates — Bunny Affiliate Manager', 'wp-affiliatemanager' ),
			__( 'Affiliates', 'wp-affiliatemanager' ),
			self::CAPABILITY,
			'wpam-affiliates',
			array( $this, 'render_affiliates_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Post Affiliates — Bunny Affiliate Manager', 'wp-affiliatemanager' ),
			__( 'Post Affiliates', 'wp-affiliatemanager' ),
			self::CAPABILITY,
			'wpam-post-affiliates',
			array( $this, 'render_post_affiliates_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Settings — Bunny Affiliate Manager', 'wp-affiliatemanager' ),
			__( 'Settings', 'wp-affiliatemanager' ),
			self::CAPABILITY,
			'wpam-settings',
			array( $this, 'render_settings_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Dashboard
	// -------------------------------------------------------------------------

	public function render_dashboard_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'wp-affiliatemanager' ) );
		}

		// Obtener contadores reales de la DB.
		$repo        = new Repository();
		$total       = $repo->count();
		$active      = $repo->count( true );
		$posts_count = $this->get_posts_with_affiliates_count();

		$this->render_admin_header( __( 'Dashboard', 'wp-affiliatemanager' ) );
		?>
		<div class="bunny-page-content">
			<div class="wpam-dashboard-welcome">
				<div class="wpam-welcome-icon">🐰</div>
				<h2><?php esc_html_e( 'Bunny Affiliate Manager', 'wp-affiliatemanager' ); ?></h2>
				<p><?php esc_html_e( 'Manage your affiliate programs and generate tracked links for any post.', 'wp-affiliatemanager' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpam-affiliates' ) ); ?>" class="button button-primary button-hero">
					+ <?php esc_html_e( 'Manage Affiliates', 'wp-affiliatemanager' ); ?>
				</a>
			</div>

			<div class="wpam-stats-grid">
				<?php $this->render_stat_card( __( 'Total Affiliates', 'wp-affiliatemanager' ), (string) $total, '📦' ); ?>
				<?php $this->render_stat_card( __( 'Active Affiliates', 'wp-affiliatemanager' ), (string) $active, '✅' ); ?>
				<?php $this->render_stat_card( __( 'Posts with Affiliates', 'wp-affiliatemanager' ), (string) $posts_count, '📝' ); ?>
			</div>

			<?php if ( $total > 0 ) : ?>
			<div class="wpam-phase-notice">
			<?php
			printf(
			/* translators: 1: number of affiliates, 2: number of active */
			esc_html__( 'You have %1$d affiliate(s) registered, %2$d active. Go to Affiliates to manage them.', 'wp-affiliatemanager' ),
			absint( $total ),
			absint( $active )
			);
			?>
			</div>
			<?php else : ?>
			<div class="wpam-phase-notice">
			<?php esc_html_e( 'No affiliates yet. Start by adding your first affiliate program.', 'wp-affiliatemanager' ); ?>
			</div>
			<?php endif; ?>

		<?php
		// v0.2.3: Analytics section.
		$click_stats       = $this->get_click_stats();
		$view_stats        = \WP_AffiliateManager\Views\Views_Query::get_stats_cached();
		$top_affiliates    = $this->get_top_affiliates();
		$top_posts         = $this->get_top_posts();
		$top_viewed_posts  = $this->get_top_viewed_posts();
		$recent_clicks     = $this->get_recent_clicks();
		$recent_views      = $this->get_recent_views();
		?>

		<!-- Click stat cards — v0.2.8: each card is a filter trigger -->
		<div class="wpam-stats-grid wpam-stats-grid--clicks">
			<?php $this->render_stat_card( __( 'Clicks Today', 'wp-affiliatemanager' ),   (string) $click_stats['today'],   '📈' ); ?>
			<?php $this->render_stat_card( __( 'Last 7 Days', 'wp-affiliatemanager' ),    (string) $click_stats['week'],    '📅' ); ?>
			<?php $this->render_stat_card( __( 'Last 30 Days', 'wp-affiliatemanager' ),   (string) $click_stats['month'],   '🗓️' ); ?>
			<?php $this->render_stat_card( __( 'Total Clicks', 'wp-affiliatemanager' ),   (string) $click_stats['total'],   '🖱️' ); ?>
		</div>

		<!-- Two-column: Top Affiliates + Top Posts (v0.2.8: AJAX-replaceable containers) -->
		<div class="wpam-analytics-cols">
			<div class="wpam-analytics-col wpam-filter-affiliates-col">
				<?php $this->render_top_affiliates_section( $top_affiliates, $click_stats['total'] ); ?>
			</div>
			<div class="wpam-analytics-col wpam-filter-posts-col">
				<?php $this->render_top_posts_section( $top_posts ); ?>
			</div>
		</div>

		<!-- Recent clicks full width -->
		<?php $this->render_recent_clicks_section( $recent_clicks ); ?>

		<!-- View stat cards — v1.2.0: tarjetas estáticas, sin filtro AJAX -->
		<div class="wpam-stats-grid wpam-stats-grid--views">
			<?php $this->render_stat_card( __( 'Views Today', 'wp-affiliatemanager' ),    (string) $view_stats['today'],   '👁️' ); ?>
			<?php $this->render_stat_card( __( 'Views Last 7 Days', 'wp-affiliatemanager' ),  (string) $view_stats['week'],  '📅' ); ?>
			<?php $this->render_stat_card( __( 'Views Last 30 Days', 'wp-affiliatemanager' ), (string) $view_stats['month'], '🗓️' ); ?>
			<?php $this->render_stat_card( __( 'Total Views', 'wp-affiliatemanager' ),    (string) $view_stats['total'],   '📊' ); ?>
		</div>

		<!-- Top Viewed Posts full width (v1.3.0: filtro AJAX igual que Top Posts) -->
		<div class="wpam-filter-top-viewed-col">
			<?php $this->render_top_viewed_posts_section( $top_viewed_posts ); ?>
		</div>

		<!-- Recent views full width -->
		<?php $this->render_recent_views_section( $recent_views ); ?>

		<!-- Maintenance card -->
		<?php $this->render_maintenance_card(); ?>

		<!-- Broken Link Reports -->
		<?php $this->render_broken_reports_section(); ?>

		</div><!-- .bunny-page-content -->
		<?php
		$this->render_admin_footer();
	}

	// -------------------------------------------------------------------------
	// Affiliates screen — delegada a Affiliates_Screen
	// -------------------------------------------------------------------------

	public function render_affiliates_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'wp-affiliatemanager' ) );
		}

		$this->render_admin_header( __( 'Affiliates', 'wp-affiliatemanager' ) );

		$screen = new Affiliates_Screen();
		$screen->render();

		$this->render_admin_footer();
	}

	public function render_post_affiliates_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'wp-affiliatemanager' ) );
		}

		$this->render_admin_header( __( 'Post Affiliates', 'wp-affiliatemanager' ) );

		$screen = new Post_Affiliates_Screen();
		$screen->render();

		$this->render_admin_footer();
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	public function render_settings_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'wp-affiliatemanager' ) );
		}

		$this->render_admin_header( __( 'Settings', 'wp-affiliatemanager' ) );
		?>
		<div class="bunny-page-content wpam-settings-page">
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wpam_settings_group' );
				do_settings_sections( 'wpam-settings' );
				submit_button( __( 'Save Settings', 'wp-affiliatemanager' ) );
				?>
			</form>
		</div>
		<?php
		$this->render_admin_footer();
	}

	// -------------------------------------------------------------------------
	// Helpers de renderizado
	// -------------------------------------------------------------------------

	private function render_admin_header( string $page_title ): void {
		?>
			<div class="bunny-header">
				<div class="bunny-header-inner">
					<span class="bunny-logo">🐰</span>
					<div class="bunny-title-stack">
						<h1 class="bunny-plugin-name"><?php esc_html_e( 'Bunny Affiliate Manager', 'wp-affiliatemanager' ); ?></h1>
						<span class="bunny-page-subtitle"><?php echo esc_html( $page_title ); ?></span>
					</div>
					<span class="bunny-version-badge">v<?php echo esc_html( WPAM_VERSION ); ?></span>
				</div>
				<nav class="bunny-nav">
					<?php $this->render_admin_nav(); ?>
				</nav>
			</div>
		<?php
	}

	private function render_admin_nav(): void {
		$current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$nav_items = array(
			'wpam-dashboard'        => __( 'Dashboard', 'wp-affiliatemanager' ),
			'wpam-affiliates'       => __( 'Affiliates', 'wp-affiliatemanager' ),
			'wpam-post-affiliates'  => __( 'Post Affiliates', 'wp-affiliatemanager' ),
			'wpam-settings'         => __( 'Settings', 'wp-affiliatemanager' ),
		);

		foreach ( $nav_items as $slug => $label ) {
			$active = ( $current_page === $slug ) ? ' bunny-nav-active' : '';
			printf(
				'<a href="%s" class="bunny-nav-item%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				esc_attr( $active ),
				esc_html( $label )
			);
		}
	}

	private function render_admin_footer(): void {
		echo '</div><!-- .wpam-admin-wrap -->';
	}

	// -------------------------------------------------------------------------
	// v0.2.4 — Maintenance
	// -------------------------------------------------------------------------

	/**
	 * Renderiza la card de mantenimiento en el dashboard.
	 *
	 * @since 0.2.4
	 */
	private function render_maintenance_card(): void {
		// Mostrar notice si venimos de una reconstrucción exitosa.
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['wpam_rebuilt'] ) && '1' === $_GET['wpam_rebuilt'] ) {
			$posts  = absint( $_GET['wpam_posts']  ?? 0 );
			$tokens = absint( $_GET['wpam_tokens'] ?? 0 );
			?>
			<div class="notice notice-success is-dismissible" style="margin:16px 0 0;">
				<p>
					<?php
					printf(
						/* translators: 1: posts processed, 2: tokens generated */
						esc_html__( 'Token map rebuilt successfully. %1$d post(s) processed, %2$d token(s) generated.', 'wp-affiliatemanager' ),
						$posts,
						$tokens
					);
					?>
				</p>
			</div>
			<?php
		}

		if ( isset( $_GET['wpam_cleared'] ) && '1' === $_GET['wpam_cleared'] ) {
			$deleted = absint( $_GET['wpam_deleted'] ?? 0 );
			?>
			<div class="notice notice-success is-dismissible" style="margin:16px 0 0;">
				<p>
					<?php
					printf(
						/* translators: %d: deleted analytics rows */
						esc_html__( 'Analytics cleared successfully. %d record(s) deleted.', 'wp-affiliatemanager' ),
						$deleted
					);
					?>
				</p>
			</div>
			<?php
		}

		if ( isset( $_GET['wpam_pvc_imported'] ) && '1' === $_GET['wpam_pvc_imported'] ) {
			$new     = absint( $_GET['wpam_pvc_new']     ?? 0 );
			$updated = absint( $_GET['wpam_pvc_updated'] ?? 0 );
			$omitted = absint( $_GET['wpam_pvc_omitted'] ?? 0 );
			$elapsed = isset( $_GET['wpam_pvc_elapsed'] ) ? (float) $_GET['wpam_pvc_elapsed'] : 0.0;
			?>
			<div class="notice notice-success is-dismissible" style="margin:16px 0 0;">
				<p>
					<?php
					printf(
						/* translators: 1: imported, 2: updated, 3: omitted, 4: seconds elapsed */
						esc_html__( 'Post Views Counter import complete: %1$d imported, %2$d updated, %3$d omitted (%4$s seconds).', 'wp-affiliatemanager' ),
						$new,
						$updated,
						$omitted,
						esc_html( (string) $elapsed )
					);
					?>
				</p>
			</div>
			<?php
		}
		// phpcs:enable
		?>
		<div class="wpam-analytics-card wpam-analytics-card--full wpam-maintenance-card">
			<h3 class="wpam-analytics-card-title">
				<span>🛠️</span> <?php esc_html_e( 'Maintenance', 'wp-affiliatemanager' ); ?>
			</h3>
			<div class="wpam-maintenance-row">
				<div class="wpam-maintenance-info">
					<strong><?php esc_html_e( 'Rebuild Redirect Token Map', 'wp-affiliatemanager' ); ?></strong>
					<p class="description"><?php esc_html_e( 'Scans all posts with affiliate links and regenerates the complete token map. Use this after migrating, importing posts, or if /go/ links redirect to the homepage.', 'wp-affiliatemanager' ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wpam_rebuild_token_map" />
					<?php wp_nonce_field( 'wpam_rebuild_token_map', 'wpam_nonce' ); ?>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Rebuild Token Map', 'wp-affiliatemanager' ); ?>
					</button>
				</form>
			</div>

			<div class="wpam-maintenance-row">
				<div class="wpam-maintenance-info">
					<strong><?php esc_html_e( 'Clear Analytics', 'wp-affiliatemanager' ); ?></strong>
					<p class="description"><?php esc_html_e( 'Deletes all recorded click analytics. The clicks table remains intact and redirects continue working normally.', 'wp-affiliatemanager' ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Delete all analytics records? This cannot be undone.', 'wp-affiliatemanager' ) ); ?>');">
					<input type="hidden" name="action" value="wpam_clear_analytics" />
					<?php wp_nonce_field( 'wpam_clear_analytics', 'wpam_nonce' ); ?>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Clear Analytics', 'wp-affiliatemanager' ); ?>
					</button>
				</form>
			</div>

			<?php if ( \WP_AffiliateManager\Views\Views_Importer::can_run() ) : ?>
			<div class="wpam-maintenance-row">
				<div class="wpam-maintenance-info">
					<strong><?php esc_html_e( 'Import from Post Views Counter', 'wp-affiliatemanager' ); ?></strong>
					<p class="description"><?php esc_html_e( 'One-time migration: imports daily view counts (type=0) from Post Views Counter into wpam_views. Existing counts are added to, never overwritten. The source table is never modified. This can only be run once.', 'wp-affiliatemanager' ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Import view counts from Post Views Counter? This can only be run once.', 'wp-affiliatemanager' ) ); ?>');">
					<input type="hidden" name="action" value="wpam_import_post_views_counter" />
					<?php wp_nonce_field( 'wpam_import_post_views_counter', 'wpam_nonce' ); ?>
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Import Views', 'wp-affiliatemanager' ); ?>
					</button>
				</form>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handler del action admin-post para reconstruir el mapa de tokens.
	 *
	 * Flujo:
	 *  1. Verificar nonce + capability.
	 *  2. Vaciar completamente wpam_redirect_tokens.
	 *  3. Buscar todos los posts con _wpam_links.
	 *  4. Llamar rebuild_token_map() para cada uno.
	 *  5. Redirigir al dashboard con resultados en query args.
	 *
	 * @since 0.2.4
	 */
	public function handle_rebuild_token_map(): void {
		// Seguridad.
		if (
			! isset( $_POST['wpam_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpam_nonce'] ) ), 'wpam_rebuild_token_map' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'wp-affiliatemanager' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-affiliatemanager' ) );
		}

		// 1. Vaciar el mapa completamente antes de reconstruir.
		update_option( \WP_AffiliateManager\Redirect\Redirect_Manager::TOKEN_MAP_OPTION, array(), false );

		// 2. Buscar todos los posts que tengan _wpam_links.
		global $wpdb;
		$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' AND meta_value != 'a:0:{}'" ,
				\WP_AffiliateManager\Posts\Post_Links::META_KEY
			)
		);

		// 3. Reconstruir token por token reutilizando la lógica existente.
		$redirect_manager = new \WP_AffiliateManager\Redirect\Redirect_Manager();
		$processed        = 0;

		foreach ( $post_ids as $post_id ) {
			$redirect_manager->rebuild_token_map( (int) $post_id );
			$processed++;
		}

		// 4. Contar tokens generados.
		$map        = get_option( \WP_AffiliateManager\Redirect\Redirect_Manager::TOKEN_MAP_OPTION, array() );
		$token_count = is_array( $map ) ? count( $map ) : 0;

		// 5. Redirigir al dashboard con resultados.
		wp_safe_redirect( add_query_arg(
			array(
				'page'          => 'wpam-dashboard',
				'wpam_rebuilt'  => '1',
				'wpam_posts'    => $processed,
				'wpam_tokens'   => $token_count,
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Handler del action admin-post para borrar registros de analytics.
	 *
	 * @since 0.2.5
	 */
	public function handle_clear_analytics(): void {
		if (
			! isset( $_POST['wpam_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpam_nonce'] ) ), 'wpam_clear_analytics' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'wp-affiliatemanager' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-affiliatemanager' ) );
		}

		global $wpdb;
		$table   = \WP_AffiliateManager\Redirect\Clicks_Table::table_name();
		$deleted = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		wp_safe_redirect( add_query_arg(
			array(
				'page'          => 'wpam-dashboard',
				'wpam_cleared'  => '1',
				'wpam_deleted'  => $deleted,
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Handler del action admin-post para importar desde Post Views Counter.
	 *
	 * Migración única: si ya se ejecutó (`Views_Importer::is_completed()`),
	 * no vuelve a correr y redirige con un flag distinto para que el dashboard
	 * no muestre un notice engañoso de "importado" cuando en realidad no hizo nada.
	 *
	 * @since1.2.0
	 */
	public function handle_import_post_views_counter(): void {
		if (
			! isset( $_POST['wpam_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpam_nonce'] ) ), 'wpam_import_post_views_counter' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'wp-affiliatemanager' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-affiliatemanager' ) );
		}

		if ( ! \WP_AffiliateManager\Views\Views_Importer::can_run() ) {
			wp_safe_redirect( add_query_arg(
				array(
					'page'              => 'wpam-dashboard',
					'wpam_pvc_skipped'  => '1',
				),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		$stats = \WP_AffiliateManager\Views\Views_Importer::run();

		wp_safe_redirect( add_query_arg(
			array(
				'page'               => 'wpam-dashboard',
				'wpam_pvc_imported'  => '1',
				'wpam_pvc_new'       => $stats['imported'],
				'wpam_pvc_updated'   => $stats['updated'],
				'wpam_pvc_omitted'   => $stats['omitted'],
				'wpam_pvc_elapsed'   => $stats['elapsed_seconds'],
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	private function render_stat_card( string $label, string $value, string $icon ): void {
		?>
		<div class="wpam-stat-card">
			<span class="wpam-stat-icon"><?php echo esc_html( $icon ); ?></span>
			<span class="wpam-stat-value"><?php echo esc_html( $value ); ?></span>
			<span class="wpam-stat-label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// v0.2.3 — Analytics: SQL helpers
	// -------------------------------------------------------------------------

	/**
	 * Retorna contadores de clicks agrupados por rango de tiempo.
	 *
	 * Todas las comparaciones usan UTC (la DB almacena en UTC via CURRENT_TIMESTAMP).
	 *
	 * @since  0.2.3
	 * @return array{ today: int, week: int, month: int, total: int }
	 */
	private function get_click_stats(): array {
		global $wpdb;
		$table = \WP_AffiliateManager\Redirect\Clicks_Table::table_name();

		$today = gmdate( 'Y-m-d' );
		$week  = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
		$month = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$today_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ts >= %s", $table, $today . ' 00:00:00' ) );
		$week_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ts >= %s", $table, $week  . ' 00:00:00' ) );
		$month_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ts >= %s", $table, $month . ' 00:00:00' ) );
		$total_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table ) );
		// phpcs:enable

		return array(
			'today' => $today_count,
			'week'  => $week_count,
			'month' => $month_count,
			'total' => $total_count,
		);
	}

	/**
	 * Top 10 afiliados por número de clicks.
	 *
	 * @since  0.2.3
	 * @since  0.2.8 Acepta un rango de tiempo opcional.
	 * @param  string $range today|week|month|total
	 * @return array[]
	 */
	private function get_top_affiliates( string $range = 'total' ): array {
		global $wpdb;
		$table = \WP_AffiliateManager\Redirect\Clicks_Table::table_name();

		$where = '';
		if ( 'total' !== $range ) {
			$since = \WP_AffiliateManager\Frontend\Top_Posts_Query::range_to_since( $range );
			$where = $wpdb->prepare( ' WHERE ts >= %s', $since );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT affiliate_id, COUNT(*) AS click_count FROM %i{$where} GROUP BY affiliate_id ORDER BY click_count DESC LIMIT 10",
				$table
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) { return array(); }

		$result = array();
		foreach ( $rows as $row ) {
			$aff_id = (int) $row['affiliate_id'];
			$post   = get_post( $aff_id );
			if ( ! $post instanceof \WP_Post ) { continue; }

			$result[] = array(
				'id'          => $aff_id,
				'title'       => $post->post_title,
				'click_count' => (int) $row['click_count'],
				'logo_url'    => (string) get_post_meta( $aff_id, \WP_AffiliateManager\Affiliates\Meta::KEY_LOGO_URL,    true ),
				'brand_color' => (string) ( get_post_meta( $aff_id, \WP_AffiliateManager\Affiliates\Meta::KEY_BRAND_COLOR, true ) ?: '#6c47ff' ),
			);
		}

		return $result;
	}

	/**
	 * Top posts por número de clicks.
	 *
	 * @since  0.2.3
	 * @since  0.2.8 Acepta un rango de tiempo opcional.
	 * @since  1.0.0 Delega la query a Frontend\Top_Posts_Query (fuente compartida con el shortcode).
	 *              Añade thumb_url y edit_url que sólo necesita el dashboard.
	 * @param  string $range today|week|month|total
	 * @return array[]
	 */
	private function get_top_posts( string $range = 'total' ): array {
		$rows = \WP_AffiliateManager\Frontend\Top_Posts_Query::get( $range, 10 );

		// Añadir campos extra que sólo usa el dashboard (thumb_url, edit_url).
		foreach ( $rows as &$row ) {
			$thumb_id        = get_post_thumbnail_id( $row['id'] );
			$row['thumb_url'] = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';
			$row['edit_url']  = (string) get_edit_post_link( $row['id'], 'raw' );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Últimos 20 clicks.
	 *
	 * @since  0.2.3
	 * @return array[]
	 */
	private function get_recent_clicks(): array {
		global $wpdb;
		$table = \WP_AffiliateManager\Redirect\Clicks_Table::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ts, post_id, affiliate_id, destination_url FROM %i ORDER BY ts DESC LIMIT 20",
				$table
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Top posts por número de vistas.
	 *
	 * Delega la query a Views\Views_Query (fuente única de verdad, mismo rol
	 * que Top_Posts_Query para clicks). Añade thumb_url y edit_url que solo
	 * necesita el dashboard, igual que get_top_posts().
	 *
	 * @since 1.2.0
	 * @param  string $range today|week|month|total
	 * @return array[]
	 */
	private function get_top_viewed_posts( string $range = 'total' ): array {
		$rows = \WP_AffiliateManager\Views\Views_Query::get_cached( $range, 10 );

		foreach ( $rows as &$row ) {
			$thumb_id         = get_post_thumbnail_id( $row['id'] );
			$row['thumb_url'] = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';
			$row['edit_url']  = (string) get_edit_post_link( $row['id'], 'raw' );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Últimas filas de wpam_views (agregado diario, no evento).
	 *
	 * Delega la query cruda a Views\Views_Query::get_recent() y añade el
	 * título/edit_url del post, igual que get_top_viewed_posts().
	 *
	 * @since 1.2.0
	 * @param  int $limit Número máximo de filas. Default 20.
	 * @return array[]
	 */
	private function get_recent_views( int $limit = 20 ): array {
		$rows = \WP_AffiliateManager\Views\Views_Query::get_recent( $limit );

		$result = array();
		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$post    = get_post( $post_id );

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$result[] = array(
				'post_id'   => $post_id,
				'period'    => $row['period'],
				'count'     => (int) $row['count'],
				'title'     => $post->post_title,
				'edit_url'  => (string) get_edit_post_link( $post_id, 'raw' ),
			);
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// v0.2.3 — Analytics: render helpers
	// -------------------------------------------------------------------------

	private function render_top_affiliates_section( array $affiliates, int $total_clicks ): void {
		?>
		<div class="wpam-analytics-card">
			<h3 class="wpam-analytics-card-title">
				<span>🏆</span> <?php esc_html_e( 'Top Affiliates', 'wp-affiliatemanager' ); ?>
			</h3>
			<?php if ( empty( $affiliates ) ) : ?>
				<p class="wpam-analytics-empty"><?php esc_html_e( 'No clicks recorded yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<ul class="wpam-top-list">
				<?php foreach ( $affiliates as $aff ) :
					$pct   = $total_clicks > 0 ? round( ( $aff['click_count'] / $total_clicks ) * 100 ) : 0;
					$color = esc_attr( $aff['brand_color'] ?: '#6c47ff' );
				?>
					<li class="wpam-top-item">
						<div class="wpam-top-item-lead">
							<?php if ( $aff['logo_url'] ) : ?>
								<img class="wpam-top-logo" src="<?php echo esc_url( $aff['logo_url'] ); ?>" alt="" />
							<?php else : ?>
								<span class="wpam-top-initial" style="background:<?php echo $color; ?>"><?php echo esc_html( strtoupper( substr( $aff['title'], 0, 1 ) ) ); ?></span>
							<?php endif; ?>
							<span class="wpam-top-name"><?php echo esc_html( $aff['title'] ); ?></span>
						</div>
						<div class="wpam-top-item-meta">
							<div class="wpam-top-bar-wrap">
								<div class="wpam-top-bar" style="width:<?php echo esc_attr( (string) $pct ); ?>%;background:<?php echo $color; ?>"></div>
							</div>
							<span class="wpam-top-count"><?php echo esc_html( number_format_i18n( $aff['click_count'] ) ); ?></span>
							<span class="wpam-top-pct"><?php echo esc_html( $pct . '%' ); ?></span>
						</div>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_top_posts_section( array $posts ): void {
		?>
		<div class="wpam-analytics-card">
			<h3 class="wpam-analytics-card-title">
				<span>📝</span> <?php esc_html_e( 'Top Posts', 'wp-affiliatemanager' ); ?>
			</h3>
			<?php if ( empty( $posts ) ) : ?>
				<p class="wpam-analytics-empty"><?php esc_html_e( 'No clicks recorded yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<?php $this->render_top_list( $posts, 'click_count' ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Top Viewed Posts — mismo diseño visual que Top Posts.
	 *
	 * @since 1.2.0
	 * @param  array[] $posts Ver get_top_viewed_posts().
	 * @return void
	 */
	private function render_top_viewed_posts_section( array $posts ): void {
		?>
		<div class="wpam-analytics-card wpam-analytics-card--full">
			<h3 class="wpam-analytics-card-title">
				<span>👁️</span> <?php esc_html_e( 'Top Viewed Posts', 'wp-affiliatemanager' ); ?>
			</h3>
			<?php if ( empty( $posts ) ) : ?>
				<p class="wpam-analytics-empty"><?php esc_html_e( 'No views recorded yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<?php $this->render_top_list( $posts, 'view_count' ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Lista `<ul class="wpam-top-list">` compartida entre Top Posts (clicks) y
	 * Top Viewed Posts (views). Extraído de render_top_posts_section() para no
	 * duplicar el markup entre ambos — mismo output exacto que antes para
	 * Top Posts, solo cambia qué campo de conteo se usa.
	 *
	 * @since 1.2.0
	 * @param  array[] $items       Cada elemento debe tener: title, edit_url, thumb_url y $count_field.
	 * @param  string  $count_field Nombre del campo de conteo ('click_count' | 'view_count').
	 * @return void
	 */
	private function render_top_list( array $items, string $count_field ): void {
		?>
		<ul class="wpam-top-list">
		<?php foreach ( $items as $item ) : ?>
			<li class="wpam-top-item">
				<div class="wpam-top-item-lead">
					<?php if ( $item['thumb_url'] ) : ?>
						<img class="wpam-top-thumb" src="<?php echo esc_url( $item['thumb_url'] ); ?>" alt="" />
					<?php else : ?>
						<span class="wpam-top-thumb-placeholder">📄</span>
					<?php endif; ?>
					<a class="wpam-top-name" href="<?php echo esc_url( $item['edit_url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
				</div>
				<span class="wpam-top-count"><?php echo esc_html( number_format_i18n( $item[ $count_field ] ) ); ?></span>
			</li>
		<?php endforeach; ?>
		</ul>
		<?php
	}

	private function render_recent_clicks_section( array $clicks ): void {
		?>
		<div class="wpam-analytics-card wpam-analytics-card--full">
			<h3 class="wpam-analytics-card-title">
				<span>🕐</span> <?php esc_html_e( 'Recent Clicks', 'wp-affiliatemanager' ); ?>
				<span class="wpam-analytics-card-sub"><?php esc_html_e( 'Last 20', 'wp-affiliatemanager' ); ?></span>
			</h3>
			<?php if ( empty( $clicks ) ) : ?>
				<p class="wpam-analytics-empty"><?php esc_html_e( 'No clicks recorded yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<div class="wpam-table-wrap">
					<table class="wpam-table wpam-recent-clicks-table">
						<thead><tr>
							<th><?php esc_html_e( 'Date / Time', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Affiliate', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Post', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Destination', 'wp-affiliatemanager' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $clicks as $click ) :
							$aff_post  = get_post( absint( $click['affiliate_id'] ) );
							$aff_name  = $aff_post instanceof \WP_Post ? $aff_post->post_title : '—';
							$src_post  = get_post( absint( $click['post_id'] ) );
							$src_title = $src_post instanceof \WP_Post ? $src_post->post_title : '—';
							$src_url   = $src_post instanceof \WP_Post ? (string) get_edit_post_link( $src_post->ID, 'raw' ) : '';
							$dest_host = (string) ( wp_parse_url( $click['destination_url'], PHP_URL_HOST ) ?: $click['destination_url'] );
							$ts_local  = get_date_from_gmt( $click['ts'], 'd M Y · H:i' );
						?>
							<tr>
								<td class="wpam-recent-ts"><?php echo esc_html( $ts_local ); ?></td>
								<td><?php echo esc_html( $aff_name ); ?></td>
								<td><?php if ( $src_url ) : ?><a href="<?php echo esc_url( $src_url ); ?>"><?php echo esc_html( $src_title ); ?></a><?php else : ?><?php echo esc_html( $src_title ); ?><?php endif; ?></td>
								<td><span class="wpam-dest-host"><?php echo esc_html( $dest_host ); ?></span></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Recent Views — mismo diseño visual que Recent Clicks, pero con
	 * granularidad diaria: la columna Date muestra el `period` (día), sin
	 * hora, porque wpam_views no registra eventos individuales.
	 *
	 * @since 1.2.0
	 * @param  array[] $views Ver get_recent_views().
	 * @return void
	 */
	private function render_recent_views_section( array $views ): void {
		?>
		<div class="wpam-analytics-card wpam-analytics-card--full">
			<h3 class="wpam-analytics-card-title">
				<span>👁️</span> <?php esc_html_e( 'Recent Views', 'wp-affiliatemanager' ); ?>
				<span class="wpam-analytics-card-sub"><?php esc_html_e( 'Last 20', 'wp-affiliatemanager' ); ?></span>
			</h3>
			<?php if ( empty( $views ) ) : ?>
				<p class="wpam-analytics-empty"><?php esc_html_e( 'No views recorded yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<div class="wpam-table-wrap">
					<table class="wpam-table wpam-recent-views-table">
						<thead><tr>
							<th><?php esc_html_e( 'Date', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Post Title', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Views', 'wp-affiliatemanager' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $views as $view ) :
							$date_display = mysql2date( get_option( 'date_format' ), $view['period'] . '000000' );
						?>
							<tr>
								<td class="wpam-recent-ts"><?php echo esc_html( $date_display ); ?></td>
								<td><?php if ( $view['edit_url'] ) : ?><a href="<?php echo esc_url( $view['edit_url'] ); ?>"><?php echo esc_html( $view['title'] ); ?></a><?php else : ?><?php echo esc_html( $view['title'] ); ?><?php endif; ?></td>
								<td><?php echo esc_html( number_format_i18n( $view['count'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Cuenta los posts publicados que tienen affiliate links asignados via _wpam_links.
	 *
	 * Solo cuenta post_type = 'post' con post_status = 'publish'.
	 * Excluye revisiones, borradores y cualquier meta vacía o inválida.
	 *
	 * @since  2.0.0
	 * @since  0.0.6 Query corregida: usa Post_Links::META_KEY (_wpam_links) y filtra por post real publicado.
	 * @return int
	 */
	private function get_posts_with_affiliates_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.post_id)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s
				  AND pm.meta_value != ''
				  AND pm.meta_value != 'a:0:{}'
				  AND p.post_type = 'post'
				  AND p.post_status = 'publish'",
				\WP_AffiliateManager\Posts\Post_Links::META_KEY
			)
		);

		return absint( $count );
	}

	private function get_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none">'
			. '<circle cx="10" cy="12" r="5" fill="#a0a5aa"/>'
			. '<ellipse cx="7" cy="5" rx="2" ry="5" fill="#a0a5aa"/>'
			. '<ellipse cx="13" cy="5" rx="2" ry="5" fill="#a0a5aa"/>'
			. '<circle cx="8" cy="12" r="1" fill="#32373c"/>'
			. '<circle cx="12" cy="12" r="1" fill="#32373c"/>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	// -------------------------------------------------------------------------
	// v0.2.7 — Broken Link Reports
	// -------------------------------------------------------------------------

	/**
	 * Renderiza la sección de reportes de enlaces rotos en el dashboard.
	 *
	 * @since 0.2.7
	 */
	private function render_broken_reports_section(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['wpam_report_cleared'] ) && '1' === $_GET['wpam_report_cleared'] ) {
			?>
			<div class="notice notice-success is-dismissible" style="margin:16px 0 0;">
				<p><?php esc_html_e( 'Broken link report cleared.', 'wp-affiliatemanager' ); ?></p>
			</div>
			<?php
		}
		if ( isset( $_GET['wpam_reports_cleared_all'] ) && '1' === $_GET['wpam_reports_cleared_all'] ) {
			?>
			<div class="notice notice-success is-dismissible" style="margin:16px 0 0;">
				<p><?php esc_html_e( 'All broken link reports cleared.', 'wp-affiliatemanager' ); ?></p>
			</div>
			<?php
		}
		// phpcs:enable

		$reports = get_option( self::REPORTS_OPTION, array() );
		$reports = is_array( $reports ) ? $reports : array();
		?>
		<div class="wpam-analytics-card wpam-analytics-card--full wpam-maintenance-card">
			<h3 class="wpam-analytics-card-title">
				<span>🔗</span> <?php esc_html_e( 'Broken Link Reports', 'wp-affiliatemanager' ); ?>
				<?php if ( ! empty( $reports ) ) : ?>
					<span class="wpam-analytics-card-sub">
						<?php echo esc_html( sprintf( '%d token(s)', count( $reports ) ) ); ?>
					</span>
				<?php endif; ?>
			</h3>

			<?php if ( empty( $reports ) ) : ?>
				<p class="wpam-analytics-empty"><?php esc_html_e( 'No broken link reports yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<div class="wpam-table-wrap">
					<table class="wpam-table">
						<thead><tr>
							<th><?php esc_html_e( 'Token', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Post', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Reports', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Last Reported', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Action', 'wp-affiliatemanager' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $reports as $token => $data ) :
							$post_id   = absint( $data['post_id'] ?? 0 );
							$src_post  = $post_id ? get_post( $post_id ) : null;
							$post_label = $src_post instanceof \WP_Post
								? sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $post_id, 'raw' ) ), esc_html( $src_post->post_title ) )
								: esc_html( $post_id > 0 ? '#' . $post_id : '—' );
							$last = $data['last_reported'] ?? '—';
							$last = ( '—' !== $last ) ? esc_html( get_date_from_gmt( $last, 'd M Y · H:i' ) ) : '—';
						?>
							<tr>
								<td><code><?php echo esc_html( $token ); ?></code></td>
								<td><?php echo $post_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped above. ?></td>
								<td><?php echo esc_html( (string) absint( $data['count'] ?? 0 ) ); ?></td>
								<td><?php echo $last; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped above. ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<input type="hidden" name="action" value="wpam_clear_broken_report" />
										<input type="hidden" name="wpam_token" value="<?php echo esc_attr( $token ); ?>" />
										<?php wp_nonce_field( 'wpam_clear_broken_report', 'wpam_nonce' ); ?>
										<button type="submit" class="button button-small button-secondary">
											<?php esc_html_e( 'Clear report', 'wp-affiliatemanager' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="wpam-maintenance-row" style="margin-top:16px;">
					<div class="wpam-maintenance-info">
						<strong><?php esc_html_e( 'Clear All Reports', 'wp-affiliatemanager' ); ?></strong>
						<p class="description"><?php esc_html_e( 'Removes all broken link report entries from storage.', 'wp-affiliatemanager' ); ?></p>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Delete all broken link reports? This cannot be undone.', 'wp-affiliatemanager' ) ); ?>')">
						<input type="hidden" name="action" value="wpam_clear_all_broken_reports" />
						<?php wp_nonce_field( 'wpam_clear_all_broken_reports', 'wpam_nonce' ); ?>
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Clear all reports', 'wp-affiliatemanager' ); ?>
						</button>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler (nopriv): registra un reporte de enlace roto.
	 *
	 * @since 0.2.7
	 */
	public function handle_report_broken_link(): void {
		// FIX 2: nonce verification (nonce generated server-side in the renderer).
		check_ajax_referer( 'wpam_report_nonce', 'nonce' );

		$token   = sanitize_text_field( wp_unslash( $_POST['token']   ?? '' ) );
		$post_id = absint( $_POST['post_id'] ?? 0 );

		// Validate token format matches the 8-char hex pattern used by the plugin.
		if ( ! preg_match( '/^[a-f0-9]{8}$/', $token ) ) {
			wp_die( '', '', array( 'response' => 400 ) );
		}

		$reports = get_option( self::REPORTS_OPTION, array() );
		$reports = is_array( $reports ) ? $reports : array();

		if ( isset( $reports[ $token ] ) ) {
			// FIX 3: throttle — skip if already reported within the last 10 minutes.
			$last_ts = strtotime( $reports[ $token ]['last_reported'] ?? '' );
			if ( $last_ts && ( time() - $last_ts ) < 600 ) {
				wp_die( '', '', array( 'response' => 200 ) ); // Silently accept; don't increment.
			}
			$reports[ $token ]['count']         = absint( $reports[ $token ]['count'] ) + 1;
			$reports[ $token ]['last_reported'] = gmdate( 'Y-m-d H:i:s' );
			// FIX 4: backfill post_id if the first report stored 0.
			if ( 0 === absint( $reports[ $token ]['post_id'] ?? 0 ) && $post_id > 0 ) {
				$reports[ $token ]['post_id'] = $post_id;
			}
		} else {
			$reports[ $token ] = array(
				'count'         => 1,
				'post_id'       => $post_id,
				'last_reported' => gmdate( 'Y-m-d H:i:s' ),
			);
		}

		update_option( self::REPORTS_OPTION, $reports, false );
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Admin-post handler: limpia un reporte individual por token.
	 *
	 * @since 0.2.7
	 */
	public function handle_clear_broken_report(): void {
		if (
			! isset( $_POST['wpam_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpam_nonce'] ) ), 'wpam_clear_broken_report' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'wp-affiliatemanager' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-affiliatemanager' ) );
		}

		$token   = sanitize_text_field( wp_unslash( $_POST['wpam_token'] ?? '' ) );
		$reports = get_option( self::REPORTS_OPTION, array() );
		$reports = is_array( $reports ) ? $reports : array();

		unset( $reports[ $token ] );
		update_option( self::REPORTS_OPTION, $reports, false );

		wp_safe_redirect( add_query_arg(
			array(
				'page'                => 'wpam-dashboard',
				'wpam_report_cleared' => '1',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	// -------------------------------------------------------------------------
	// v0.2.8 — Dashboard analytics filter AJAX
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler: returns filtered Top Affiliates + Top Posts HTML.
	 *
	 * Accepted ranges: today | week | month | total.
	 *
	 * @since 0.2.8
	 */
	public function ajax_dashboard_filter(): void {
		check_ajax_referer( 'wpam_dashboard_filter', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$allowed = array( 'today', 'week', 'month', 'total' );
		$range   = sanitize_text_field( wp_unslash( $_POST['range'] ?? 'total' ) );
		if ( ! in_array( $range, $allowed, true ) ) {
			$range = 'total';
		}

		// v1.3.0: grupo de filtro de Views — responde solo posts_html (Top Viewed Posts).
		$source = sanitize_text_field( wp_unslash( $_POST['source'] ?? 'clicks' ) );

		if ( 'views' === $source ) {
			$viewed_posts = $this->get_top_viewed_posts( $range );

			ob_start();
			$this->render_top_viewed_posts_section( $viewed_posts );
			$viewed_posts_html = ob_get_clean();

			wp_send_json_success( array(
				'posts_html' => $viewed_posts_html,
			) );
		}

		$affiliates = $this->get_top_affiliates( $range );
		$posts      = $this->get_top_posts( $range );

		// Total clicks for the same range (for percentage bars).
		global $wpdb;
		$table       = \WP_AffiliateManager\Redirect\Clicks_Table::table_name();
		$range_total = $this->get_range_total( $table, $range );

		ob_start();
		$this->render_top_affiliates_section( $affiliates, $range_total );
		$affiliates_html = ob_get_clean();

		ob_start();
		$this->render_top_posts_section( $posts );
		$posts_html = ob_get_clean();

		wp_send_json_success( array(
			'affiliates_html' => $affiliates_html,
			'posts_html'      => $posts_html,
		) );
	}

	/**
	 * Returns total click count for a given range (used for percentage bars in AJAX response).
	 *
	 * @since  0.2.8
	 * @param  string $table DB table name.
	 * @param  string $range today|week|month|total
	 * @return int
	 */
	private function get_range_total( string $table, string $range ): int {
		global $wpdb;
		if ( 'total' === $range ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		}
		$since = \WP_AffiliateManager\Frontend\Top_Posts_Query::range_to_since( $range );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ts >= %s', $table, $since ) );
	}

	/**
	 * Admin-post handler: limpia todos los reportes.
	 *
	 * @since 0.2.7
	 */
	public function handle_clear_all_broken_reports(): void {
		if (
			! isset( $_POST['wpam_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpam_nonce'] ) ), 'wpam_clear_all_broken_reports' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'wp-affiliatemanager' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-affiliatemanager' ) );
		}

		update_option( self::REPORTS_OPTION, array(), false );

		wp_safe_redirect( add_query_arg(
			array(
				'page'                      => 'wpam-dashboard',
				'wpam_reports_cleared_all'  => '1',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
