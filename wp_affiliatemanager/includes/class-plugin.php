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
require_once WPAM_PLUGIN_PATH . 'includes/posts/class-post-affiliates.php';
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

		$this->load_textdomain();
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

	private function load_textdomain(): void {
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
		$cpt = new Affiliates\CPT();
		$this->loader->add_action( 'init', $cpt, 'register' );
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

		// FASE 2 — Meta boxes del CPT wpam_affiliate.
		$meta = new Affiliates\Meta();
		$this->loader->add_action( 'add_meta_boxes', $meta, 'register_meta_boxes' );
		$this->loader->add_action( 'save_post_' . Affiliates\CPT::POST_TYPE, $meta, 'save' );

		// FASE 3 — Meta box "Affiliate Links" en posts.
		$post_links = new Posts\Post_Links();
		$this->loader->add_action( 'add_meta_boxes', $post_links, 'register_meta_box' );
		$this->loader->add_action( 'save_post',      $post_links, 'save' );
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
	}

	public function get_version(): string { return $this->version; }
	public function get_loader(): Loader  { return $this->loader; }
	public function __clone()   {}
	public function __wakeup()  {}
}
