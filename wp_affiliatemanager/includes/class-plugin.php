<?php
/**
 * Clase principal del plugin (Orchestrator).
 *
 * @package WP_AffiliateManager
 * @since   1.0.0
 */

namespace WP_AffiliateManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Core ---
require_once WPAM_PLUGIN_PATH . 'includes/admin/class-admin.php';
require_once WPAM_PLUGIN_PATH . 'includes/admin/class-admin-menu.php';
require_once WPAM_PLUGIN_PATH . 'includes/admin/class-admin-assets.php';
require_once WPAM_PLUGIN_PATH . 'includes/frontend/class-frontend.php';
require_once WPAM_PLUGIN_PATH . 'includes/frontend/class-frontend-assets.php';
require_once WPAM_PLUGIN_PATH . 'includes/settings/class-settings.php';
require_once WPAM_PLUGIN_PATH . 'includes/templates/class-templates.php';
// Nota: class-post-affiliates.php (placeholder FASE 1) fue reemplazado por
// class-post-links.php en FASE 3. Ya no se carga. El archivo se conserva
// como referencia histórica hasta que se decida eliminarlo formalmente.
require_once WPAM_PLUGIN_PATH . 'includes/api/class-api.php';

// --- FASE 2: Affiliates system ---
require_once WPAM_PLUGIN_PATH . 'includes/affiliates/class-cpt.php';
require_once WPAM_PLUGIN_PATH . 'includes/affiliates/class-meta.php';
require_once WPAM_PLUGIN_PATH . 'includes/affiliates/class-repository.php';
require_once WPAM_PLUGIN_PATH . 'includes/affiliates/class-affiliates.php';
require_once WPAM_PLUGIN_PATH . 'includes/affiliates/helpers-affiliates.php';
require_once WPAM_PLUGIN_PATH . 'includes/admin/class-affiliates-screen.php';

// --- FASE 3: Post links system ---
require_once WPAM_PLUGIN_PATH . 'includes/posts/class-post-links.php';
require_once WPAM_PLUGIN_PATH . 'includes/posts/helpers-post-links.php';

// --- v0.1.0: Post Affiliates board ---
require_once WPAM_PLUGIN_PATH . 'includes/admin/class-post-affiliates-screen.php';

// --- v0.2.0-alpha1: Redirect system ---
require_once WPAM_PLUGIN_PATH . 'includes/redirect/class-clicks-table.php';
require_once WPAM_PLUGIN_PATH . 'includes/redirect/class-click-tracker.php';
require_once WPAM_PLUGIN_PATH . 'includes/redirect/class-redirect-manager.php';
require_once WPAM_PLUGIN_PATH . 'includes/redirect/class-interstitial-renderer.php';
require_once WPAM_PLUGIN_PATH . 'includes/redirect/helpers-redirect.php';

// --- FASE 4: Render Engine ---
require_once WPAM_PLUGIN_PATH . 'includes/frontend/class-render-engine.php';
require_once WPAM_PLUGIN_PATH . 'includes/frontend/helpers-render.php';

// --- v1.0.0: Top Posts Query (compartida entre dashboard y shortcode) ---
require_once WPAM_PLUGIN_PATH . 'includes/frontend/class-top-posts-query.php';
require_once WPAM_PLUGIN_PATH . 'includes/frontend/class-top-posts-renderer.php';
require_once WPAM_PLUGIN_PATH . 'includes/frontend/class-shortcode-top-posts.php';
require_once WPAM_PLUGIN_PATH . 'includes/frontend/class-widget-top-posts.php';

require_once WPAM_PLUGIN_PATH . 'includes/api/class-wpam-api.php';

// --- v1.2.0: Views system (Fase 1 — infraestructura) ---
require_once WPAM_PLUGIN_PATH . 'includes/views/class-views-table.php';
require_once WPAM_PLUGIN_PATH . 'includes/views/class-view-tracker.php';
require_once WPAM_PLUGIN_PATH . 'includes/views/class-views.php';
require_once WPAM_PLUGIN_PATH . 'includes/views/class-views-query.php';
require_once WPAM_PLUGIN_PATH . 'includes/views/class-views-importer.php';

/**
 * Class Plugin
 *
 * Singleton. Punto de entrada central del plugin.
 *
 * @since 1.0.0
 */
final class Plugin {

	private static ?Plugin $instance = null;
	private Loader $loader;
	private string $version;

	private function __construct() {
		$this->version = WPAM_VERSION;
		$this->loader  = new Loader();

		$this->define_global_hooks();
		$this->define_admin_hooks();
		$this->define_frontend_hooks();
		$this->loader->run();
	}

	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wp-affiliatemanager',
			false,
			dirname( plugin_basename( WPAM_PLUGIN_FILE ) ) . '/languages/'
		);
	}

	/**
	 * Hooks globales (admin + frontend).
	 *
	 * @since 2.0.0
	 */
	private function define_global_hooks(): void {
		// Textdomain en init — requerido desde WP 6.7.
		$this->loader->add_action( 'init', $this, 'load_textdomain' );

		$cpt = new Affiliates\CPT();
		$this->loader->add_action( 'init', $cpt, 'register' );

		// v0.2.0-alpha1: Redirect system.
		$redirect = new Redirect\Redirect_Manager();
		$this->loader->add_action( 'init',             $redirect, 'register_rewrite' );
		$this->loader->add_filter( 'query_vars',       $redirect, 'add_query_var' );
		$this->loader->add_action( 'template_redirect', $redirect, 'handle' );

		// v0.2.7: Broken link AJAX report — fires for logged-in and logged-out users.
		$admin_menu_report = new Admin\Admin_Menu();
		$this->loader->add_action( 'wp_ajax_nopriv_wpam_report_broken_link', $admin_menu_report, 'handle_report_broken_link' );
		$this->loader->add_action( 'wp_ajax_wpam_report_broken_link',        $admin_menu_report, 'handle_report_broken_link' );

		// v0.2.8: Dashboard filter AJAX (admin-only).
		$admin_menu_filter = new Admin\Admin_Menu();
		$this->loader->add_action( 'wp_ajax_wpam_dashboard_filter', $admin_menu_filter, 'ajax_dashboard_filter' );

		// v1.0.0: Widget Top Posts — widgets_init corre en admin y frontend (Customizer, panel de widgets, sidebar).
		// Se registra via closure para evitar instanciar WP_Widget antes del hook init
		// (el constructor llama a __() que dispara el textdomain demasiado temprano en WP 6.7+).
		add_action( 'widgets_init', function() {
			register_widget( Frontend\Widget_Top_Posts::class );
		} );

		// v1.2.0: Views tracking AJAX endpoint — debe ir en hooks globales
		// porque admin-ajax.php corre con is_admin() === true, y define_frontend_hooks()
		// corta con return temprano en ese caso.
		$views = new Views\Views();
		$this->loader->add_action( 'wp_ajax_wpam_track_view',        $views, 'ajax_track' );
		$this->loader->add_action( 'wp_ajax_nopriv_wpam_track_view', $views, 'ajax_track' );
	}

	/**
	 * Hooks exclusivos del área de administración.
	 *
	 * @since 1.0.0
	 */
	private function define_admin_hooks(): void {
		if ( ! is_admin() ) {
			return;
		}

		// Admin core.
		$admin = new Admin\Admin( $this->version );
		$this->loader->add_action( 'admin_init', $admin, 'init' );

		// Menú.
		$admin_menu = new Admin\Admin_Menu();
		$this->loader->add_action( 'admin_menu', $admin_menu, 'register_menus' );

		// Assets (FASE 3: ahora también detecta post screens).
		$admin_assets = new Admin\Admin_Assets( $this->version );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin_assets, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin_assets, 'enqueue_scripts' );

		// Settings.
		$settings = new Settings\Settings();
		$this->loader->add_action( 'admin_init', $settings, 'register_settings' );

		// FASE 2 — Affiliates screen.
		$affiliates_screen = new Admin\Affiliates_Screen();
		$this->loader->add_action( 'admin_init', $affiliates_screen, 'handle_actions' );
		// 0.0.6 — AJAX handlers para inline CRUD.
		$this->loader->add_action( 'wp_ajax_wpam_save_affiliate',    $affiliates_screen, 'ajax_save' );
		$this->loader->add_action( 'wp_ajax_wpam_get_edit_row',      $affiliates_screen, 'ajax_get_edit_row' );

		// v0.1.0 — Post Affiliates board.
		$post_affiliates_screen = new Admin\Post_Affiliates_Screen();
		$this->loader->add_action( 'wp_ajax_wpam_load_posts',       $post_affiliates_screen, 'ajax_load_posts' );
		$this->loader->add_action( 'wp_ajax_wpam_save_post_links',  $post_affiliates_screen, 'ajax_save_post_links' );

		// FASE 2 — Meta boxes del CPT wpam_affiliate.
		$meta = new Affiliates\Meta();
		$this->loader->add_action( 'add_meta_boxes', $meta, 'register_meta_boxes' );
		$this->loader->add_action( 'save_post_' . Affiliates\CPT::POST_TYPE, $meta, 'save' );

		// FASE 3 — Meta box "Affiliate Links" en posts.
		$post_links = new Posts\Post_Links();
		$this->loader->add_action( 'add_meta_boxes', $post_links, 'register_meta_box' );
		$this->loader->add_action( 'save_post',      $post_links, 'save' );

		// v0.2.0-alpha1: Reconstruir mapa de tokens al guardar links.
		$redirect = new Redirect\Redirect_Manager();
		$this->loader->add_action( 'save_post', $redirect, 'rebuild_token_map' );

		// v0.2.4: Maintenance action — rebuild token map completo.
		$admin_menu_maint = new Admin\Admin_Menu();
		$this->loader->add_action( 'admin_post_wpam_rebuild_token_map', $admin_menu_maint, 'handle_rebuild_token_map' );
		$this->loader->add_action( 'admin_post_wpam_clear_analytics',    $admin_menu_maint, 'handle_clear_analytics' );
		$this->loader->add_action( 'admin_post_wpam_import_post_views_counter', $admin_menu_maint, 'handle_import_post_views_counter' );

		// v0.2.7: Broken link report clear actions (admin-only).
		$admin_menu_reports = new Admin\Admin_Menu();
		$this->loader->add_action( 'admin_post_wpam_clear_broken_report',      $admin_menu_reports, 'handle_clear_broken_report' );
		$this->loader->add_action( 'admin_post_wpam_clear_all_broken_reports',  $admin_menu_reports, 'handle_clear_all_broken_reports' );
	}

	/**
	 * Hooks exclusivos del frontend público.
	 *
	 * @since 1.0.0
	 */
	private function define_frontend_hooks(): void {
		if ( is_admin() ) {
			return;
		}

		$frontend = new Frontend\Frontend();
		$this->loader->add_action( 'wp', $frontend, 'init' );

		$frontend_assets = new Frontend\Frontend_Assets( $this->version );
		$this->loader->add_action( 'wp_enqueue_scripts', $frontend_assets, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $frontend_assets, 'enqueue_scripts' );

		// v1.0.0: Shortcode [wpam_top_posts].
		// Los shortcodes se registran via add_shortcode() directamente (no via Loader).
		Frontend\Shortcode_Top_Posts::register();

		// v1.2.0: Views beacon — enqueue condicional, solo en is_singular('post').
		// Módulo independiente de Frontend_Assets: las vistas se cuentan tenga o no
		// el post links afiliados.
		$views = new Views\Views();
		$this->loader->add_action( 'wp_enqueue_scripts', $views, 'maybe_enqueue_beacon' );

		// El CSS del widget (wpam-top-posts-widget) se registra dentro del hook
		// wp_enqueue_scripts en Frontend_Assets::enqueue_styles().
		// El encolado real lo realizan el shortcode/widget cuando se renderizan.
	}

	public function get_version(): string { return $this->version; }
	public function get_loader(): Loader  { return $this->loader; }
	public function __clone()   {}
	public function __wakeup()  {}
}
