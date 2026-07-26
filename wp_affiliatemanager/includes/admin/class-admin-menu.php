<?php
/**
 * Módulo de menú de administración.
 *
 * Desde v1.4.0: solo posee Dashboard, Settings y Broken Reports. Toda la
 * analítica (Score/Clicks/Views con tabs) vive en Analytics_Screen. Los
 * datos siempre se obtienen de las Query classes (Top_Posts_Query,
 * Views_Query, Score_Query) y el markup compartido de renderiza vía
 * Analytics_Renderer — esta clase no vuelve a tener lógica de datos ni de
 * render de analítica propia.
 *
 * @package WP_AffiliateManager\Admin
 * @since   1.0.0 (actualizado en 2.0.0, 1.4.0)
 */

namespace WP_AffiliateManager\Admin;

use WP_AffiliateManager\Affiliates\Repository;
use WP_AffiliateManager\Affiliates\CPT;
use WP_AffiliateManager\Analytics\Score_Query;
use WP_AffiliateManager\Frontend\Top_Posts_Query;
use WP_AffiliateManager\Views\Views_Query;

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
			__( 'Analytics — Bunny Affiliate Manager', 'wp-affiliatemanager' ),
			__( 'Analytics', 'wp-affiliatemanager' ),
			self::CAPABILITY,
			'wpam-analytics',
			array( $this, 'render_analytics_page' )
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
			__( 'Broken Reports — Bunny Affiliate Manager', 'wp-affiliatemanager' ),
			__( 'Broken Reports', 'wp-affiliatemanager' ),
			self::CAPABILITY,
			'wpam-broken-reports',
			array( $this, 'render_broken_reports_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Settings — Bunny Affiliate Manager', 'wp-affiliatemanager' ),
			__( 'Settings', 'wp-affiliatemanager' ),
			self::CAPABILITY,
			'wpam-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Bunny Score — Bunny Affiliate Manager', 'wp-affiliatemanager' ),
			__( 'Bunny Score', 'wp-affiliatemanager' ),
			self::CAPABILITY,
			'wpam-bunny-score',
			array( $this, 'render_bunny_score_page' )
		);

	}

	/**
	 * Render the Bunny Score admin page wrapped with the common header/footer.
	 *
	 * @return void
	 */
	public function render_bunny_score_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'wp-affiliatemanager' ) );
		}

		$this->render_admin_header( __( 'Bunny Score', 'wp-affiliatemanager' ) );

		if ( class_exists( '\WP_AffiliateManager\\Bunny_Score\\Bunny_Score_Screen' ) ) {
			\WP_AffiliateManager\Bunny_Score\Bunny_Score_Screen::render();
		} else {
			echo '<div class="bunny-page-content"><p>Screen class missing.</p></div>';
		}

		$this->render_admin_footer();
	}

	// -------------------------------------------------------------------------
	// Dashboard — resumen ejecutivo (v1.4.0)
	// -------------------------------------------------------------------------

	/**
	 * Dashboard = resumen ejecutivo del plugin.
	 *
	 * Solo: totales, última actividad, Top 10 por Score y accesos rápidos.
	 * Nada de esto ejecuta filtros AJAX ni queries por rango — para eso
	 * está Analytics. Todos los datos vienen exclusivamente de
	 * Top_Posts_Query / Views_Query / Score_Query; el markup se delega a
	 * Analytics_Renderer donde corresponde.
	 *
	 * @since 1.4.0
	 */
	public function render_dashboard_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'wp-affiliatemanager' ) );
		}

		$repo        = new Repository();
		$total       = $repo->count();
		$active      = $repo->count( true );
		$posts_count = $this->get_posts_with_affiliates_count();

		// Resumen — solo totales (rango 'total'), sin AJAX.
		$click_totals = Top_Posts_Query::get_stats_cached();
		$view_totals  = Views_Query::get_stats_cached();
		$score_totals = Score_Query::get_stats_cached();

		// Actividad reciente — mismos datos crudos que usará Analytics.
		$recent_clicks = Top_Posts_Query::get_recent( 20 );
		$recent_views  = Views_Query::get_recent( 20 );

		// Top 10 general — mismo servicio Score_Query que usará el tab Score de Analytics.
		$top_scored = Score_Query::get_cached( 'total', 10 );

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
				<?php Analytics_Renderer::render_stat_card( __( 'Total Affiliates', 'wp-affiliatemanager' ), (string) $total, '📦' ); ?>
				<?php Analytics_Renderer::render_stat_card( __( 'Active Affiliates', 'wp-affiliatemanager' ), (string) $active, '✅' ); ?>
				<?php Analytics_Renderer::render_stat_card( __( 'Posts with Affiliates', 'wp-affiliatemanager' ), (string) $posts_count, '📝' ); ?>
			</div>

			<h2 class="wpam-section-heading"><?php esc_html_e( 'Overview', 'wp-affiliatemanager' ); ?></h2>
			<div class="wpam-stats-grid wpam-stats-grid--summary">
				<?php Analytics_Renderer::render_stat_card( __( 'Total Score', 'wp-affiliatemanager' ), number_format_i18n( $score_totals['total'] ), '⭐' ); ?>
				<?php Analytics_Renderer::render_stat_card( __( 'Total Views', 'wp-affiliatemanager' ), number_format_i18n( $view_totals['total'] ), '👁️' ); ?>
				<?php Analytics_Renderer::render_stat_card( __( 'Total Clicks', 'wp-affiliatemanager' ), number_format_i18n( $click_totals['total'] ), '🖱️' ); ?>
				<?php Analytics_Renderer::render_stat_card( __( 'Total Affiliates', 'wp-affiliatemanager' ), (string) $total, '📦' ); ?>
			</div>

			<h2 class="wpam-section-heading"><?php esc_html_e( 'Recent Activity', 'wp-affiliatemanager' ); ?></h2>
			<?php Analytics_Renderer::render_recent_clicks_section( $recent_clicks ); ?>
			<?php Analytics_Renderer::render_recent_views_section( $recent_views ); ?>

			<h2 class="wpam-section-heading"><?php esc_html_e( 'Top 10 Overall', 'wp-affiliatemanager' ); ?></h2>
			<?php Analytics_Renderer::render_top_scored_posts_section( $top_scored ); ?>

			<h2 class="wpam-section-heading"><?php esc_html_e( 'Quick Access', 'wp-affiliatemanager' ); ?></h2>
			<div class="wpam-quick-access-grid">
				<a class="wpam-quick-access-card" href="<?php echo esc_url( admin_url( 'admin.php?page=wpam-analytics' ) ); ?>">
					<span class="wpam-quick-access-icon">📊</span>
					<span class="wpam-quick-access-label"><?php esc_html_e( 'Analytics', 'wp-affiliatemanager' ); ?></span>
				</a>
				<a class="wpam-quick-access-card" href="<?php echo esc_url( admin_url( 'admin.php?page=wpam-affiliates' ) ); ?>">
					<span class="wpam-quick-access-icon">📦</span>
					<span class="wpam-quick-access-label"><?php esc_html_e( 'Affiliates', 'wp-affiliatemanager' ); ?></span>
				</a>
				<a class="wpam-quick-access-card" href="<?php echo esc_url( admin_url( 'admin.php?page=wpam-settings' ) ); ?>">
					<span class="wpam-quick-access-icon">⚙️</span>
					<span class="wpam-quick-access-label"><?php esc_html_e( 'Settings', 'wp-affiliatemanager' ); ?></span>
				</a>
				<a class="wpam-quick-access-card" href="<?php echo esc_url( admin_url( 'admin.php?page=wpam-broken-reports' ) ); ?>">
					<span class="wpam-quick-access-icon">🔗</span>
					<span class="wpam-quick-access-label"><?php esc_html_e( 'Broken Link Reports', 'wp-affiliatemanager' ); ?></span>
				</a>
			</div>

		</div><!-- .bunny-page-content -->
		<?php
		$this->render_admin_footer();
	}

	// -------------------------------------------------------------------------
	// Analytics screen — delegada a Analytics_Screen
	// -------------------------------------------------------------------------

	public function render_analytics_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'wp-affiliatemanager' ) );
		}

		$this->render_admin_header( __( 'Analytics', 'wp-affiliatemanager' ) );

		$screen = new Analytics_Screen();
		$screen->render();

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

			<?php // v1.4.0: Maintenance se mueve aquí desde el Dashboard — misma UI, mismos handlers. ?>
			<?php $this->render_maintenance_card(); ?>
		</div>
		<?php
		$this->render_admin_footer();
	}

	// -------------------------------------------------------------------------
	// Broken Reports — página propia (v1.4.0)
	// -------------------------------------------------------------------------

	/**
	 * Página propia de Broken Link Reports.
	 *
	 * Se mueve fuera del Dashboard en v1.4.0. Misma tabla, mismos handlers,
	 * sin cambios de comportamiento — solo cambia dónde vive en el menú.
	 *
	 * @since 1.4.0
	 */
	public function render_broken_reports_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'wp-affiliatemanager' ) );
		}

		$this->render_admin_header( __( 'Broken Reports', 'wp-affiliatemanager' ) );
		?>
		<div class="bunny-page-content">
			<?php $this->render_broken_reports_section(); ?>
		</div>
		<?php
		$this->render_admin_footer();
	}

	// -------------------------------------------------------------------------
	// Helpers de renderizado
	// -------------------------------------------------------------------------

	private function render_admin_header( string $page_title ): void {
		?>
			<div class="wpam-admin-wrap bunny-wrap">
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
			'wpam-dashboard'       => __( 'Dashboard', 'wp-affiliatemanager' ),
			'wpam-analytics'       => __( 'Analytics', 'wp-affiliatemanager' ),
			'wpam-affiliates'      => __( 'Affiliates', 'wp-affiliatemanager' ),
			'wpam-post-affiliates' => __( 'Post Affiliates', 'wp-affiliatemanager' ),
			'wpam-broken-reports'  => __( 'Broken Reports', 'wp-affiliatemanager' ),
			'wpam-settings'        => __( 'Settings', 'wp-affiliatemanager' ),
			'wpam-bunny-score'     => __( 'Bunny Score', 'wp-affiliatemanager' ),
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
	// v0.2.4 — Maintenance (movido a la página Settings en v1.4.0)
	// -------------------------------------------------------------------------

	/**
	 * Renderiza la card de mantenimiento.
	 *
	 * Se muestra ahora en la página Settings en vez del Dashboard (v1.4.0).
	 * Sin cambios de funcionalidad ni de handlers.
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
	 * @since 0.2.4
	 * @since 1.4.0 Redirige a wpam-settings en vez de wpam-dashboard (la
	 *              card de Maintenance vive ahora en Settings). Sin cambios
	 *              en nonce, capability ni lógica.
	 */
	public function handle_rebuild_token_map(): void {
		if (
			! isset( $_POST['wpam_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpam_nonce'] ) ), 'wpam_rebuild_token_map' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'wp-affiliatemanager' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-affiliatemanager' ) );
		}

		update_option( \WP_AffiliateManager\Redirect\Redirect_Manager::TOKEN_MAP_OPTION, array(), false );

		global $wpdb;
		$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' AND meta_value != 'a:0:{}'" ,
				\WP_AffiliateManager\Posts\Post_Links::META_KEY
			)
		);

		$redirect_manager = new \WP_AffiliateManager\Redirect\Redirect_Manager();
		$processed        = 0;

		foreach ( $post_ids as $post_id ) {
			$redirect_manager->rebuild_token_map( (int) $post_id );
			$processed++;
		}

		$map        = get_option( \WP_AffiliateManager\Redirect\Redirect_Manager::TOKEN_MAP_OPTION, array() );
		$token_count = is_array( $map ) ? count( $map ) : 0;

		wp_safe_redirect( add_query_arg(
			array(
				'page'          => 'wpam-settings',
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
	 * @since 1.4.0 Redirige a wpam-settings en vez de wpam-dashboard.
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
				'page'          => 'wpam-settings',
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
	 * @since 1.2.0
	 * @since 1.4.0 Redirige a wpam-settings en vez de wpam-dashboard.
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
					'page'              => 'wpam-settings',
					'wpam_pvc_skipped'  => '1',
				),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		$stats = \WP_AffiliateManager\Views\Views_Importer::run();

		wp_safe_redirect( add_query_arg(
			array(
				'page'               => 'wpam-settings',
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

	/**
	 * Cuenta los posts publicados que tienen affiliate links asignados via _wpam_links.
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
	// v0.2.7 — Broken Link Reports (página propia desde v1.4.0)
	// -------------------------------------------------------------------------

	/**
	 * Renderiza la tabla de reportes de enlaces rotos.
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
		check_ajax_referer( 'wpam_report_nonce', 'nonce' );

		$token   = sanitize_text_field( wp_unslash( $_POST['token']   ?? '' ) );
		$post_id = absint( $_POST['post_id'] ?? 0 );

		if ( ! preg_match( '/^[a-f0-9]{8}$/', $token ) ) {
			wp_die( '', '', array( 'response' => 400 ) );
		}

		$reports = get_option( self::REPORTS_OPTION, array() );
		$reports = is_array( $reports ) ? $reports : array();

		if ( isset( $reports[ $token ] ) ) {
			$last_ts = strtotime( $reports[ $token ]['last_reported'] ?? '' );
			if ( $last_ts && ( time() - $last_ts ) < 600 ) {
				wp_die( '', '', array( 'response' => 200 ) );
			}
			$reports[ $token ]['count']         = absint( $reports[ $token ]['count'] ) + 1;
			$reports[ $token ]['last_reported'] = gmdate( 'Y-m-d H:i:s' );
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
	 * @since 1.4.0 Redirige a wpam-broken-reports en vez de wpam-dashboard.
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
				'page'                => 'wpam-broken-reports',
				'wpam_report_cleared' => '1',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Admin-post handler: limpia todos los reportes.
	 *
	 * @since 0.2.7
	 * @since 1.4.0 Redirige a wpam-broken-reports en vez de wpam-dashboard.
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
				'page'                      => 'wpam-broken-reports',
				'wpam_reports_cleared_all'  => '1',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
