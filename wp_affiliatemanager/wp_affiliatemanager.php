<?php
/**
 * Plugin Name:       Bunny Affiliate Manager
 * Plugin URI:        https://bunnychase.net/bunny-affiliate-manager
 * Description:       Sistema modular y escalable para administrar enlaces de afiliados por entrada/post dentro de WordPress.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            BunnyChase
 * Author URI:        https://bunnychase.net
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-affiliatemanager
 * Domain Path:       /languages
 *
 * @package WP_AffiliateManager
 */

// Prevenir acceso directo al archivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constantes globales del plugin
// ---------------------------------------------------------------------------

/** Versión actual del plugin. */
define( 'WPAM_VERSION', '1.1.0' );

/** Ruta absoluta al archivo principal del plugin. */
define( 'WPAM_PLUGIN_FILE', __FILE__ );

/** Ruta absoluta al directorio raíz del plugin (con trailing slash). */
define( 'WPAM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

/** URL pública al directorio raíz del plugin (con trailing slash). */
define( 'WPAM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/** Nombre de la opción principal en la base de datos. */
define( 'WPAM_OPTION_KEY', 'wpam_settings' );

// ---------------------------------------------------------------------------
// Autoload de clases base
// ---------------------------------------------------------------------------

require_once WPAM_PLUGIN_PATH . 'includes/helpers.php';
require_once WPAM_PLUGIN_PATH . 'includes/class-activator.php';
require_once WPAM_PLUGIN_PATH . 'includes/class-deactivator.php';
require_once WPAM_PLUGIN_PATH . 'includes/class-loader.php';
require_once WPAM_PLUGIN_PATH . 'includes/class-plugin.php';

// ---------------------------------------------------------------------------
// Hooks de activación / desactivación
// ---------------------------------------------------------------------------

register_activation_hook( WPAM_PLUGIN_FILE, array( 'WP_AffiliateManager\\Activator', 'activate' ) );
register_deactivation_hook( WPAM_PLUGIN_FILE, array( 'WP_AffiliateManager\\Deactivator', 'deactivate' ) );

// ---------------------------------------------------------------------------
// Inicialización
// ---------------------------------------------------------------------------

/**
 * Retorna la instancia única del plugin.
 *
 * @since  1.0.0
 * @return WP_AffiliateManager\Plugin
 */
function wpam_init(): WP_AffiliateManager\Plugin {
	return WP_AffiliateManager\Plugin::get_instance();
}

add_action( 'plugins_loaded', 'wpam_init' );
