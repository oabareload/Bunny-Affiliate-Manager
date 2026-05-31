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

	const PARENT_SLUG = 'wpam-dashboard';
	const CAPABILITY  = 'manage_options';

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
		$click_stats    = $this->get_click_stats();
		$top_affiliates = $this->get_top_affiliates();
		$top_posts      = $this->get_top_posts();
		$recent_clicks  = $this->get_recent_clicks();
		?>

		<!-- Click stat cards -->
		<div class="wpam-stats-grid wpam-stats-grid--clicks">
			<?php $this->render_stat_card( __( 'Clicks Today', 'wp-affiliatemanager' ),   (string) $click_stats['today'],   '📈' ); ?>
			<?php $this->render_stat_card( __( 'Last 7 Days', 'wp-affiliatemanager' ),    (string) $click_stats['week'],    '📅' ); ?>
			<?php $this->render_stat_card( __( 'Last 30 Days', 'wp-affiliatemanager' ),   (string) $click_stats['month'],   '🗓️' ); ?>
			<?php $this->render_stat_card( __( 'Total Clicks', 'wp-affiliatemanager' ),   (string) $click_stats['total'],   '🖱️' ); ?>
		</div>

		<!-- Two-column: Top Affiliates + Top Posts -->
		<div class="wpam-analytics-cols">
			<div class="wpam-analytics-col">
				<?php $this->render_top_affiliates_section( $top_affiliates, $click_stats['total'] ); ?>
			</div>
			<div class="wpam-analytics-col">
				<?php $this->render_top_posts_section( $top_posts ); ?>
			</div>
		</div>

		<!-- Recent clicks full width -->
		<?php $this->render_recent_clicks_section( $recent_clicks ); ?>

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
		<div class="wrap bunny-wrap wpam-admin-wrap">
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
	 * @return array[]
	 */
	private function get_top_affiliates(): array {
		global $wpdb;
		$table = \WP_AffiliateManager\Redirect\Clicks_Table::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT affiliate_id, COUNT(*) AS click_count FROM %i GROUP BY affiliate_id ORDER BY click_count DESC LIMIT 10",
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
	 * Top 10 posts por número de clicks.
	 *
	 * @since  0.2.3
	 * @return array[]
	 */
	private function get_top_posts(): array {
		global $wpdb;
		$table = \WP_AffiliateManager\Redirect\Clicks_Table::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, COUNT(*) AS click_count FROM %i GROUP BY post_id ORDER BY click_count DESC LIMIT 10",
				$table
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) { return array(); }

		$result = array();
		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) { continue; }

			$thumb_id  = get_post_thumbnail_id( $post_id );
			$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';

			$result[] = array(
				'id'          => $post_id,
				'title'       => $post->post_title ?: __( '(no title)', 'wp-affiliatemanager' ),
				'click_count' => (int) $row['click_count'],
				'thumb_url'   => $thumb_url ?: '',
				'edit_url'    => (string) get_edit_post_link( $post_id, 'raw' ),
			);
		}

		return $result;
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
				<ul class="wpam-top-list">
				<?php foreach ( $posts as $post ) : ?>
					<li class="wpam-top-item">
						<div class="wpam-top-item-lead">
							<?php if ( $post['thumb_url'] ) : ?>
								<img class="wpam-top-thumb" src="<?php echo esc_url( $post['thumb_url'] ); ?>" alt="" />
							<?php else : ?>
								<span class="wpam-top-thumb-placeholder">📄</span>
							<?php endif; ?>
							<a class="wpam-top-name" href="<?php echo esc_url( $post['edit_url'] ); ?>"><?php echo esc_html( $post['title'] ); ?></a>
						</div>
						<span class="wpam-top-count"><?php echo esc_html( number_format_i18n( $post['click_count'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
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

	// -------------------------------------------------------------------------
	// Helpers de datos existentes
	// -------------------------------------------------------------------------

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
}
