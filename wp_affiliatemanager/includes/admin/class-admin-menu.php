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
		<div class="wpam-page-content">
			<div class="wpam-dashboard-welcome">
				<div class="wpam-welcome-icon">🐰</div>
				<h2><?php esc_html_e( 'Bunny Affiliate Manager', 'wp-affiliatemanager' ); ?></h2>
				<p><?php esc_html_e( 'Manage your affiliate programs and generate tracked links for any post.', 'wp-affiliatemanager' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . CPT::POST_TYPE ) ); ?>" class="button button-primary button-hero">
					+ <?php esc_html_e( 'Add New Affiliate', 'wp-affiliatemanager' ); ?>
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
		</div>
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

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	public function render_settings_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'wp-affiliatemanager' ) );
		}

		$this->render_admin_header( __( 'Settings', 'wp-affiliatemanager' ) );
		?>
		<div class="wpam-page-content">
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
		<div class="wrap wpam-admin-wrap">
			<div class="wpam-admin-header">
				<div class="wpam-admin-header-inner">
					<span class="wpam-logo-icon">🐰</span>
					<div class="wpam-admin-title">
						<h1 class="wpam-admin-plugin-name"><?php esc_html_e( 'Bunny Affiliate Manager', 'wp-affiliatemanager' ); ?></h1>
						<span class="wpam-admin-page-name"><?php echo esc_html( $page_title ); ?></span>
					</div>
					<span class="wpam-version-badge">v<?php echo esc_html( WPAM_VERSION ); ?></span>
				</div>
				<nav class="wpam-admin-nav">
					<?php $this->render_admin_nav(); ?>
				</nav>
			</div>
		<?php
	}

	private function render_admin_nav(): void {
		$current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$nav_items = array(
			'wpam-dashboard'  => __( 'Dashboard', 'wp-affiliatemanager' ),
			'wpam-affiliates' => __( 'Affiliates', 'wp-affiliatemanager' ),
			'wpam-settings'   => __( 'Settings', 'wp-affiliatemanager' ),
		);

		foreach ( $nav_items as $slug => $label ) {
			$active = ( $current_page === $slug ) ? ' wpam-nav-active' : '';
			printf(
				'<a href="%s" class="wpam-nav-item%s">%s</a>',
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

	/**
	 * Cuenta los posts que tienen afiliados asignados via post meta.
	 *
	 * @since  2.0.0
	 * @return int
	 */
	private function get_posts_with_affiliates_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != %s",
				\WP_AffiliateManager\Affiliates\Meta::KEY_ACTIVE, // reusamos la key como proxy; FASE 3 tendrá su propia tabla.
				''
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
