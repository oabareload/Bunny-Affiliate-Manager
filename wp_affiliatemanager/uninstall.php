<?php
/**
 * Se ejecuta automáticamente cuando el usuario elimina el plugin desde el panel de WordPress.
 *
 * IMPORTANTE: Este archivo SOLO se invoca si el plugin fue desactivado primero
 * y el usuario elige "Borrar" en la pantalla de plugins.
 *
 * @package WP_AffiliateManager
 * @since   1.0.0
 */

// Seguridad: abortar si no fue llamado por WordPress durante el proceso de desinstalación.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Limpieza de opciones de la base de datos
// ---------------------------------------------------------------------------

delete_option( 'wpam_settings' );
delete_option( 'wpam_version' );

// ---------------------------------------------------------------------------
// Limpieza de datos de usuario (user meta)
// ---------------------------------------------------------------------------
// Placeholder — descomentar y ajustar en fases posteriores si se guardan user metas.
// delete_metadata( 'user', 0, 'wpam_user_data', '', true );

// ---------------------------------------------------------------------------
// Limpieza de tablas personalizadas (si existieran)
// ---------------------------------------------------------------------------
// Placeholder — descomentar en fases posteriores cuando se creen tablas custom.
// global $wpdb;
// $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpam_affiliates" );
// $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpam_affiliate_links" );

// ---------------------------------------------------------------------------
// Limpieza de post meta
// ---------------------------------------------------------------------------
// Placeholder — descomentar en fases posteriores.
// delete_post_meta_by_key( '_wpam_affiliate_links' );
